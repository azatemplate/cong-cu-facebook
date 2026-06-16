<?php
// cron/auto_request_phone.php
// Script quét và tự động gửi tin nhắn xin thông tin khách hàng (SĐT -> Tỉnh thành -> Nhu cầu)
// Chạy định kỳ 5-15 phút/lần
// Cú pháp chạy: php /path/to/cron/auto_request_phone.php

ignore_user_abort(true);
set_time_limit(300);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/zalo_api.php';

echo "\n========================================\n";
echo "  AUTO-REQUEST INFO WORKER — " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

$fb_success_count = 0;
$fb_error_count = 0;
$zalo_success_count = 0;
$zalo_error_count = 0;

// ── PHẦN 1: PHÂN HỆ FACEBOOK ───────────────────────────────────────────
echo "\n[FB] Bắt đầu quét cấu hình Facebook...\n";
try {
    // Lấy danh sách các trang Facebook thuộc tài khoản có kích hoạt tính năng tự động xin thông tin
    $sql_fb_pages = "
        SELECT p.page_id, p.access_token, p.user_id,
               sa.phone_request_hours, sa.phone_request_text, 
               sa.province_request_text, sa.product_request_text
        FROM pages p
        JOIN users u ON p.user_id = u.id
        JOIN system_accounts sa ON u.account_id = sa.id
        WHERE sa.phone_request_enabled = 1
    ";
    $pages = $pdo->query($sql_fb_pages)->fetchAll(PDO::FETCH_ASSOC);
    echo "[FB] Tìm thấy " . count($pages) . " Fanpage có kích hoạt.\n";

    foreach ($pages as $page) {
        $page_id = $page['page_id'];
        $hours = max(1, intval($page['phone_request_hours']));
        $phone_request_text = trim($page['phone_request_text'] ?? '');
        $province_request_text = trim($page['province_request_text'] ?? '');
        $product_request_text = trim($page['product_request_text'] ?? '');
        
        $page_access_token = decryptData($page['access_token']);
        if (empty($page_access_token)) {
            echo "[FB] Fanpage $page_id bị lỗi giải mã Access Token. Bỏ qua.\n";
            continue;
        }

        // Truy vấn khách hàng thỏa mãn điều kiện của Fanpage này và bot không bị khóa
        $sql_fb_customers = "
            SELECT c.name, c.phone, c.province, c.notes, c.sender_id, c.last_message_at, c.info_requested_at
            FROM fb_customers c
            WHERE c.page_id = :page_id
              AND c.last_sender = 'customer'
              AND c.last_message_at <= DATE_SUB(NOW(), INTERVAL $hours HOUR)
              AND c.last_message_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND (c.info_requested_at IS NULL OR c.last_message_at > c.info_requested_at)
              AND NOT EXISTS (
                  SELECT 1 FROM bot_chat_locks l
                  WHERE l.page_id = c.page_id AND l.sender_id = c.sender_id AND l.expire_at > NOW()
              )
        ";
        $stmt_cust = $pdo->prepare($sql_fb_customers);
        $stmt_cust->execute([
            ':page_id' => $page_id
        ]);
        $customers = $stmt_cust->fetchAll(PDO::FETCH_ASSOC);

        if (count($customers) > 0) {
            echo "[FB] Fanpage $page_id: Phát hiện " . count($customers) . " khách hàng đủ điều kiện gửi tin.\n";
            
            foreach ($customers as $c) {
                $msg_to_send = '';
                
                // 1. Kiểm tra Số điện thoại
                if (empty($c['phone'])) {
                    if (!empty($phone_request_text)) {
                        $msg_to_send = $phone_request_text;
                    }
                }
                
                // 2. Kiểm tra Tỉnh thành (Nếu có SĐT rồi hoặc bỏ qua xin SĐT)
                if (empty($msg_to_send) && empty($c['province'])) {
                    if (!empty($province_request_text)) {
                        $msg_to_send = $province_request_text;
                    }
                }
                
                // 3. Kiểm tra Nhu cầu/Sản phẩm (Nếu có SĐT và Tỉnh thành rồi hoặc bỏ qua)
                if (empty($msg_to_send) && empty($c['notes'])) {
                    if (!empty($product_request_text)) {
                        $msg_to_send = $product_request_text;
                    }
                }

                if (!empty($msg_to_send)) {
                    $customer_name = trim($c['name'] ?? 'bạn');
                    if (empty($customer_name)) {
                        $customer_name = 'bạn';
                    }
                    $message_text = str_replace('{name}', $customer_name, $msg_to_send);

                    echo "[FB] Đang gửi auto-request cho khách {$c['sender_id']} ({$customer_name}): \"$message_text\"\n";
                    
                    // Gửi tin nhắn qua Facebook Graph API
                    $post_data = [
                        'recipient' => json_encode(['id' => $c['sender_id']]),
                        'message' => json_encode(['text' => $message_text]),
                        'messaging_type' => 'RESPONSE'
                    ];

                    $res = fb_api_request('me/messages', ['access_token' => $page_access_token], 'POST', $post_data);
                    
                    if ($res['status_code'] === 200) {
                        // Cập nhật trạng thái tin nhắn cuối cùng và thời điểm yêu cầu
                        $upd = $pdo->prepare("
                            UPDATE fb_customers 
                            SET last_sender = 'agent', 
                                last_message_at = CURRENT_TIMESTAMP, 
                                info_requested_at = CURRENT_TIMESTAMP 
                            WHERE page_id = ? AND sender_id = ?
                        ");
                        $upd->execute([$page_id, $c['sender_id']]);
                        $fb_success_count++;
                        echo "[FB] Gửi thành công cho khách {$c['sender_id']}.\n";
                    } else {
                        $fb_error_count++;
                        $err_msg = $res['data']['error']['message'] ?? 'Lỗi không xác định.';
                        echo "[FB] Gửi thất bại cho khách {$c['sender_id']}: $err_msg\n";
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    echo "[FB] Lỗi hệ thống Facebook auto-request: " . $e->getMessage() . "\n";
}

// ── PHẦN 2: PHÂN HỆ ZALO OA ─────────────────────────────────────────────
echo "\n[ZALO] Bắt đầu quét cấu hình Zalo...\n";
try {
    // Lấy danh sách Zalo OA thuộc tài khoản có kích hoạt tính năng tự động xin thông tin
    $sql_zalo_oas = "
        SELECT zo.oa_id, zo.account_id,
               zs.phone_request_hours, zs.phone_request_text, 
               zs.province_request_text, zs.product_request_text
        FROM zalo_oas zo
        JOIN zalo_settings zs ON zo.account_id = zs.account_id
        WHERE zs.phone_request_enabled = 1
    ";
    $oas = $pdo->query($sql_zalo_oas)->fetchAll(PDO::FETCH_ASSOC);
    echo "[ZALO] Tìm thấy " . count($oas) . " Zalo OA có kích hoạt.\n";

    foreach ($oas as $oa) {
        $oa_id = $oa['oa_id'];
        $hours = max(1, intval($oa['phone_request_hours']));
        $phone_request_text = trim($oa['phone_request_text'] ?? '');
        $province_request_text = trim($oa['province_request_text'] ?? '');
        $product_request_text = trim($oa['product_request_text'] ?? '');

        // Lấy Access Token hoạt động (sẽ tự động refresh nếu hết hạn)
        $access_token = zalo_get_active_token($oa_id, $pdo);
        if (!$access_token) {
            echo "[ZALO] OA $oa_id không thể lấy Access Token hoạt động. Bỏ qua.\n";
            continue;
        }

        // Truy vấn khách hàng thỏa mãn điều kiện của OA này
        $sql_zalo_customers = "
            SELECT name, phone, province, notes, sender_id, last_message_at, info_requested_at
            FROM zalo_customers
            WHERE oa_id = :oa_id
              AND last_sender = 'customer'
              AND last_message_at <= DATE_SUB(NOW(), INTERVAL $hours HOUR)
              AND last_message_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND (info_requested_at IS NULL OR last_message_at > info_requested_at)
        ";
        $stmt_cust = $pdo->prepare($sql_zalo_customers);
        $stmt_cust->execute([
            ':oa_id' => $oa_id
        ]);
        $customers = $stmt_cust->fetchAll(PDO::FETCH_ASSOC);

        if (count($customers) > 0) {
            echo "[ZALO] OA $oa_id: Phát hiện " . count($customers) . " khách hàng đủ điều kiện gửi tin.\n";

            foreach ($customers as $c) {
                $msg_to_send = '';

                // 1. Kiểm tra Số điện thoại
                if (empty($c['phone'])) {
                    if (!empty($phone_request_text)) {
                        $msg_to_send = $phone_request_text;
                    }
                }

                // 2. Kiểm tra Tỉnh thành (Nếu có SĐT rồi hoặc bỏ qua)
                if (empty($msg_to_send) && empty($c['province'])) {
                    if (!empty($province_request_text)) {
                        $msg_to_send = $province_request_text;
                    }
                }

                // 3. Kiểm tra Nhu cầu/Sản phẩm (Nếu có SĐT và Tỉnh thành rồi hoặc bỏ qua)
                if (empty($msg_to_send) && empty($c['notes'])) {
                    if (!empty($product_request_text)) {
                        $msg_to_send = $product_request_text;
                    }
                }

                if (!empty($msg_to_send)) {
                    $customer_name = trim($c['name'] ?? 'bạn');
                    if (empty($customer_name)) {
                        $customer_name = 'bạn';
                    }
                    $message_text = str_replace('{name}', $customer_name, $msg_to_send);

                    echo "[ZALO] Đang gửi auto-request cho khách Zalo {$c['sender_id']} ({$customer_name}): \"$message_text\"\n";

                    // Gửi tin nhắn qua Zalo Open API
                    $res = zalo_send_text_message($access_token, $c['sender_id'], $message_text);

                    if (isset($res['status_code']) && $res['status_code'] === 200 && isset($res['data']['error']) && $res['data']['error'] === 0) {
                        // Cập nhật khách hàng
                        $upd = $pdo->prepare("
                            UPDATE zalo_customers 
                            SET last_sender = 'agent', 
                                last_message_at = CURRENT_TIMESTAMP, 
                                info_requested_at = CURRENT_TIMESTAMP 
                            WHERE oa_id = ? AND sender_id = ?
                        ");
                        $upd->execute([$oa_id, $c['sender_id']]);

                        // Cập nhật danh sách hội thoại đệm
                        $upd_msg = $pdo->prepare("
                            INSERT INTO zalo_messages (oa_id, sender_id, snippet, unread_count, updated_time)
                            VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE 
                                snippet = VALUES(snippet),
                                unread_count = 0,
                                updated_time = CURRENT_TIMESTAMP
                        ");
                        $upd_msg->execute([$oa_id, $c['sender_id'], $message_text]);

                        $zalo_success_count++;
                        echo "[ZALO] Gửi thành công cho khách {$c['sender_id']}.\n";
                    } else {
                        $zalo_error_count++;
                        $err_msg = $res['data']['message'] ?? 'Lỗi không xác định.';
                        echo "[ZALO] Gửi thất bại cho khách {$c['sender_id']}: $err_msg\n";
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    echo "[ZALO] Lỗi hệ thống Zalo auto-request: " . $e->getMessage() . "\n";
}

// ── BÁO CÁO TỔNG KẾT ───────────────────────────────────────────────────
echo "\n========================================\n";
echo "  TỔNG KẾT WORKER\n";
echo "  Facebook thành công:   $fb_success_count\n";
echo "  Facebook thất bại:     $fb_error_count\n";
echo "  Zalo thành công:       $zalo_success_count\n";
echo "  Zalo thất bại:         $zalo_error_count\n";
echo "========================================\n";
echo "[DONE] Hoàn tất auto-request worker.\n";
?>
