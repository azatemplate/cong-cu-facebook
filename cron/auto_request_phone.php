<?php
// cron/auto_request_phone.php
// Script quét và tự động gửi tin nhắn xin thông tin khách hàng (SĐT -> Tỉnh thành -> Nhu cầu)
// Chạy định kỳ 5-15 phút/lần
// Cú pháp chạy: php /path/to/cron/auto_request_phone.php

ignore_user_abort(true);
set_time_limit(300);

// Ngăn chạy trùng lặp tiến trình (Concurrency Lock)
$lock_file = __DIR__ . '/../locks/auto_request_phone.lock';
$lock_fp = fopen($lock_file, 'c');
if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    echo "Another instance of auto_request_phone.php is already running. Exiting.\n";
    return;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/zalo_api.php';

// Helper để thay thế các biến {name} và {phone-sales|fallback}
function replace_message_tags($text, $customer_name, $sales_phone) {
    $text = str_replace('{name}', $customer_name, $text);
    $sales_phone = trim($sales_phone ?? '');
    return preg_replace_callback('/\{phone-sales(?:\|([^}]+))?\}/', function($matches) use ($sales_phone) {
        if (!empty($sales_phone)) {
            return $sales_phone;
        }
        return isset($matches[1]) ? $matches[1] : '';
    }, $text);
}

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
    // Lấy danh sách các trang Facebook thuộc tài khoản có kích hoạt tính năng tự động xin thông tin hoặc CSKH
    $sql_fb_pages = "
        SELECT p.page_id, p.access_token, p.user_id,
               sa.phone_request_enabled, sa.phone_request_hours, sa.phone_request_text, 
               sa.province_request_text, sa.product_request_text,
               sa.followup_request_enabled, sa.followup_request_hours, sa.followup_request_text,
               sa.phone_request_limit
        FROM pages p
        JOIN users u ON p.user_id = u.id
        JOIN system_accounts sa ON u.account_id = sa.id
        WHERE sa.phone_request_enabled = 1 OR sa.followup_request_enabled = 1
    ";
    $pages = $pdo->query($sql_fb_pages)->fetchAll(PDO::FETCH_ASSOC);
    echo "[FB] Tìm thấy " . count($pages) . " Fanpage có kích hoạt.\n";

    foreach ($pages as $page) {
        $page_id = $page['page_id'];
        $phone_request_enabled = (int)($page['phone_request_enabled'] ?? 0);
        $hours = max(1, intval($page['phone_request_hours']));
        $phone_request_text = trim($page['phone_request_text'] ?? '');
        $province_request_text = trim($page['province_request_text'] ?? '');
        $product_request_text = trim($page['product_request_text'] ?? '');
        $phone_request_limit = max(1, intval($page['phone_request_limit'] ?? 3));
        
        $followup_request_enabled = (int)($page['followup_request_enabled'] ?? 0);
        $followup_hours = max(1, intval($page['followup_request_hours']));
        $followup_request_text = trim($page['followup_request_text'] ?? '');
        
        $page_access_token = decryptData($page['access_token']);
        if (empty($page_access_token)) {
            echo "[FB] Fanpage $page_id bị lỗi giải mã Access Token. Bỏ qua.\n";
            continue;
        }



        // Truy vấn khách hàng tương tác và thực sự đủ điều kiện xử lý trong CSDL (để tối ưu hóa hiệu năng)
        $sql_fb_customers = "
            SELECT c.name, c.phone, c.province, c.notes, c.sender_id, c.last_message_at, c.info_requested_at, c.followup_requested_at, c.sales_phone, c.consulted
            FROM fb_customers c
            WHERE c.page_id = :page_id
              AND NOT EXISTS (
                  SELECT 1 FROM bot_chat_locks l
                  WHERE l.page_id = c.page_id AND l.sender_id = c.sender_id AND l.expire_at > NOW()
              )
              AND (
                  -- Case 1: Cần tự động xin thông tin
                  (
                      :phone_request_enabled = 1
                      AND NOT (c.phone IS NOT NULL AND c.phone != '' AND (:has_province_req = 0 OR (c.province IS NOT NULL AND c.province != '')) AND (:has_product_req = 0 OR (c.notes IS NOT NULL AND c.notes != '')))
                      AND c.last_message_at <= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                      AND c.last_message_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) -- Chỉ gửi tin trong vòng 24h từ tương tác cuối
                      AND (c.info_request_count IS NULL OR c.info_request_count < :phone_request_limit)
                      AND (c.info_requested_at IS NULL OR c.last_message_at > c.info_requested_at OR c.info_requested_at <= DATE_SUB(NOW(), INTERVAL :hours HOUR))
                      AND c.consulted != 3
                  )
                  OR
                  -- Case 2: Cần tự động gửi tin CSKH/Follow-up
                  (
                      :followup_request_enabled = 1
                      AND (c.phone IS NOT NULL AND c.phone != '' AND (:has_province_req = 0 OR (c.province IS NOT NULL AND c.province != '')) AND (:has_product_req = 0 OR (c.notes IS NOT NULL AND c.notes != '')))
                      AND c.last_message_at <= DATE_SUB(NOW(), INTERVAL :followup_hours HOUR)
                      AND c.last_message_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) -- Tuân thủ chính sách 24h của Facebook
                      AND c.followup_requested_at IS NULL
                      AND c.consulted = 1
                  )
              )
        ";
        $stmt_cust = $pdo->prepare($sql_fb_customers);
        $stmt_cust->execute([
            ':page_id' => $page_id,
            ':phone_request_enabled' => $phone_request_enabled,
            ':has_province_req' => empty($province_request_text) ? 0 : 1,
            ':has_product_req' => empty($product_request_text) ? 0 : 1,
            ':hours' => $hours,
            ':phone_request_limit' => $phone_request_limit,
            ':followup_request_enabled' => $followup_request_enabled,
            ':followup_hours' => $followup_hours
        ]);
        $customers = $stmt_cust->fetchAll(PDO::FETCH_ASSOC);

        if (count($customers) > 0) {
            echo "[FB] Fanpage $page_id: Phát hiện " . count($customers) . " khách hàng đang chờ xử lý.\n";
            
            foreach ($customers as $c) {
                // Xác định khách hàng đã đầy đủ thông tin hay chưa
                $is_info_complete = !empty($c['phone']) 
                    && (empty($province_request_text) || !empty($c['province'])) 
                    && (empty($product_request_text) || !empty($c['notes']));

                // --- TRƯỜNG HỢP 1: TỰ ĐỘNG XIN THÔNG TIN (Nếu thông tin chưa đầy đủ) ---
                if (!$is_info_complete && $phone_request_enabled && (int)($c['consulted'] ?? 0) !== 3) {
                    $last_msg_ts = strtotime($c['last_message_at']);
                    $diff_hours = (time() - $last_msg_ts) / 3600;

                    if ($diff_hours >= $hours) {
                        if (empty($c['info_requested_at']) || strtotime($c['last_message_at']) > strtotime($c['info_requested_at'])) {
                            
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
                                $message_text = replace_message_tags($msg_to_send, $customer_name, $c['sales_phone']);

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
                                            info_requested_at = CURRENT_TIMESTAMP,
                                            info_request_count = COALESCE(info_request_count, 0) + 1
                                        WHERE page_id = ? AND sender_id = ?
                                    ");
                                    $upd->execute([$page_id, $c['sender_id']]);
                                    $fb_success_count++;
                                    echo "[FB] Gửi thành công cho khách {$c['sender_id']}.\n";
                                } else {
                                    $fb_error_count++;
                                    $err_msg = $res['data']['error']['message'] ?? 'Lỗi không xác định.';
                                    echo "[FB] Gửi thất bại cho khách {$c['sender_id']}: $err_msg\n";
                                    
                                    // Cập nhật info_requested_at để tránh lặp lại gửi liên tục khi lỗi (bị chặn, không hoạt động...)
                                    $upd_fail = $pdo->prepare("
                                        UPDATE fb_customers 
                                        SET info_requested_at = CURRENT_TIMESTAMP,
                                            info_request_count = COALESCE(info_request_count, 0) + 1
                                        WHERE page_id = ? AND sender_id = ?
                                    ");
                                    $upd_fail->execute([$page_id, $c['sender_id']]);
                                }
                            } else {
                                // Thông tin đã đầy đủ hoặc không có cấu hình tin nhắn mẫu, cập nhật info_requested_at để tránh quét lại liên tục
                                $upd_skip = $pdo->prepare("
                                    UPDATE fb_customers 
                                    SET info_requested_at = CURRENT_TIMESTAMP 
                                    WHERE page_id = ? AND sender_id = ?
                                ");
                                $upd_skip->execute([$page_id, $c['sender_id']]);
                            }
                        }
                    }
                }

                // --- TRƯỜNG HỢP 2: TỰ ĐỘNG GỬI TIN CSKH / FOLLOW-UP (Nếu thông tin đã đầy đủ) ---
                if ($is_info_complete && $followup_request_enabled && !empty($followup_request_text) && (int)($c['consulted'] ?? 0) === 1) {
                    $last_msg_ts = strtotime($c['last_message_at']);
                    $diff_hours = (time() - $last_msg_ts) / 3600;

                    if ($diff_hours >= $followup_hours) {
                        if (empty($c['followup_requested_at'])) {
                            
                            $customer_name = trim($c['name'] ?? 'bạn');
                            if (empty($customer_name)) {
                                $customer_name = 'bạn';
                            }
                            $message_text = replace_message_tags($followup_request_text, $customer_name, $c['sales_phone']);

                            echo "[FB] Đang gửi CSKH/Follow-up cho khách {$c['sender_id']} ({$customer_name}): \"$message_text\"\n";
                            
                            // Gửi tin nhắn qua Facebook Graph API
                            $post_data = [
                                'recipient' => json_encode(['id' => $c['sender_id']]),
                                'message' => json_encode(['text' => $message_text]),
                                'messaging_type' => 'RESPONSE'
                            ];

                            $res = fb_api_request('me/messages', ['access_token' => $page_access_token], 'POST', $post_data);
                            
                            if ($res['status_code'] === 200) {
                                // Cập nhật khách hàng (last_sender chuyển sang agent để ngừng spam)
                                $upd = $pdo->prepare("
                                    UPDATE fb_customers 
                                    SET last_sender = 'agent', 
                                        last_message_at = CURRENT_TIMESTAMP, 
                                        followup_requested_at = CURRENT_TIMESTAMP 
                                    WHERE page_id = ? AND sender_id = ?
                                ");
                                $upd->execute([$page_id, $c['sender_id']]);

                                // Khóa chatbot 24 giờ để tránh chatbot tự động trả lời khi khách hàng phản hồi tin CSKH
                                try {
                                    $stmt_lock = $pdo->prepare("
                                        INSERT INTO bot_chat_locks (page_id, sender_id, expire_at)
                                        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                                        ON DUPLICATE KEY UPDATE expire_at = GREATEST(expire_at, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                                    ");
                                    $stmt_lock->execute([$page_id, $c['sender_id']]);
                                } catch (Exception $e) {}

                                $fb_success_count++;
                                echo "[FB] Gửi CSKH thành công cho khách {$c['sender_id']}.\n";
                            } else {
                                $fb_error_count++;
                                $err_msg = $res['data']['error']['message'] ?? 'Lỗi không xác định.';
                                echo "[FB] Gửi CSKH thất bại cho khách {$c['sender_id']}: $err_msg\n";
                                
                                // Vẫn cập nhật để tránh gửi lại liên tục khi lỗi
                                $upd_fail = $pdo->prepare("
                                    UPDATE fb_customers 
                                    SET followup_requested_at = CURRENT_TIMESTAMP 
                                    WHERE page_id = ? AND sender_id = ?
                                ");
                                $upd_fail->execute([$page_id, $c['sender_id']]);
                            }
                        }
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
    // Lấy danh sách Zalo OA thuộc tài khoản có kích hoạt tính năng tự động xin thông tin hoặc CSKH
    $sql_zalo_oas = "
        SELECT zo.oa_id, zo.account_id,
               zs.phone_request_enabled, zs.phone_request_hours, zs.phone_request_text, 
               zs.province_request_text, zs.product_request_text,
               zs.followup_request_enabled, zs.followup_request_hours, zs.followup_request_text,
               zs.phone_request_limit
        FROM zalo_oas zo
        JOIN zalo_settings zs ON zo.account_id = zs.account_id
        WHERE zs.phone_request_enabled = 1 OR zs.followup_request_enabled = 1
    ";
    $oas = $pdo->query($sql_zalo_oas)->fetchAll(PDO::FETCH_ASSOC);
    echo "[ZALO] Tìm thấy " . count($oas) . " Zalo OA có kích hoạt.\n";

    foreach ($oas as $oa) {
        $oa_id = $oa['oa_id'];
        $phone_request_enabled = (int)($oa['phone_request_enabled'] ?? 0);
        $hours = max(1, intval($oa['phone_request_hours']));
        $phone_request_text = trim($oa['phone_request_text'] ?? '');
        $province_request_text = trim($oa['province_request_text'] ?? '');
        $product_request_text = trim($oa['product_request_text'] ?? '');
        $phone_request_limit = max(1, intval($oa['phone_request_limit'] ?? 3));
        
        $followup_request_enabled = (int)($oa['followup_request_enabled'] ?? 0);
        $followup_hours = max(1, intval($oa['followup_request_hours']));
        $followup_request_text = trim($oa['followup_request_text'] ?? '');

        // Lấy Access Token hoạt động (sẽ tự động refresh nếu hết hạn)
        $access_token = zalo_get_active_token($oa_id, $pdo);
        if (!$access_token) {
            echo "[ZALO] OA $oa_id không thể lấy Access Token hoạt động. Bỏ qua.\n";
            continue;
        }

        // Truy vấn khách hàng tương tác và thực sự đủ điều kiện xử lý trong CSDL (để tối ưu hóa hiệu năng)
        $sql_zalo_customers = "
            SELECT name, phone, province, notes, sender_id, last_message_at, info_requested_at, followup_requested_at, sales_phone, consulted
            FROM zalo_customers
            WHERE oa_id = :oa_id
              AND NOT EXISTS (
                  SELECT 1 FROM zalo_chat_locks l
                  WHERE l.oa_id = zalo_customers.oa_id AND l.sender_id = zalo_customers.sender_id AND l.expire_at > NOW()
              )
              AND (
                  -- Case 1: Cần tự động xin thông tin
                  (
                      :phone_request_enabled = 1
                      AND NOT (phone IS NOT NULL AND phone != '' AND (:has_province_req = 0 OR (province IS NOT NULL AND province != '')) AND (:has_product_req = 0 OR (notes IS NOT NULL AND notes != '')))
                      AND last_message_at <= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                      AND (info_request_count IS NULL OR info_request_count < :phone_request_limit)
                      AND (info_requested_at IS NULL OR last_message_at > info_requested_at OR info_requested_at <= DATE_SUB(NOW(), INTERVAL :hours HOUR))
                      AND consulted != 3
                  )
                  OR
                  -- Case 2: Cần tự động gửi tin CSKH/Follow-up
                  (
                      :followup_request_enabled = 1
                      AND (phone IS NOT NULL AND phone != '' AND (:has_province_req = 0 OR (province IS NOT NULL AND province != '')) AND (:has_product_req = 0 OR (notes IS NOT NULL AND notes != '')))
                      AND last_message_at <= DATE_SUB(NOW(), INTERVAL :followup_hours HOUR)
                      AND last_message_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                      AND followup_requested_at IS NULL
                      AND consulted = 1
                  )
              )
        ";
        $stmt_cust = $pdo->prepare($sql_zalo_customers);
        $stmt_cust->execute([
            ':oa_id' => $oa_id,
            ':phone_request_enabled' => $phone_request_enabled,
            ':has_province_req' => empty($province_request_text) ? 0 : 1,
            ':has_product_req' => empty($product_request_text) ? 0 : 1,
            ':hours' => $hours,
            ':phone_request_limit' => $phone_request_limit,
            ':followup_request_enabled' => $followup_request_enabled,
            ':followup_hours' => $followup_hours
        ]);
        $customers = $stmt_cust->fetchAll(PDO::FETCH_ASSOC);

        if (count($customers) > 0) {
            echo "[ZALO] OA $oa_id: Phát hiện " . count($customers) . " khách hàng đang chờ xử lý.\n";

            foreach ($customers as $c) {
                // Xác định khách hàng đã đầy đủ thông tin hay chưa
                $is_info_complete = !empty($c['phone']) 
                    && (empty($province_request_text) || !empty($c['province'])) 
                    && (empty($product_request_text) || !empty($c['notes']));

                // --- TRƯỜNG HỢP 1: TỰ ĐỘNG XIN THÔNG TIN (Nếu thông tin chưa đầy đủ) ---
                if (!$is_info_complete && $phone_request_enabled && (int)($c['consulted'] ?? 0) !== 3) {
                    $last_msg_ts = strtotime($c['last_message_at']);
                    $diff_hours = (time() - $last_msg_ts) / 3600;

                    if ($diff_hours >= $hours) { // Follows custom user-configured hours only
                        if (empty($c['info_requested_at']) || strtotime($c['last_message_at']) > strtotime($c['info_requested_at'])) {
                            
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
                                $message_text = replace_message_tags($msg_to_send, $customer_name, $c['sales_phone']);

                                echo "[ZALO] Đang gửi auto-request cho khách Zalo {$c['sender_id']} ({$customer_name}): \"$message_text\"\n";

                                // Gửi tin nhắn qua Zalo Open API
                                $res = zalo_send_text_message($access_token, $c['sender_id'], $message_text);

                                if (isset($res['status_code']) && $res['status_code'] === 200 && isset($res['data']['error']) && $res['data']['error'] === 0) {
                                    // Cập nhật khách hàng
                                    $upd = $pdo->prepare("
                                        UPDATE zalo_customers 
                                        SET last_sender = 'agent', 
                                            last_message_at = CURRENT_TIMESTAMP, 
                                            info_requested_at = CURRENT_TIMESTAMP,
                                            info_request_count = COALESCE(info_request_count, 0) + 1
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

                                    // Cập nhật info_requested_at để tránh lặp lại gửi liên tục khi lỗi
                                    $upd_fail = $pdo->prepare("
                                        UPDATE zalo_customers 
                                        SET info_requested_at = CURRENT_TIMESTAMP,
                                            info_request_count = COALESCE(info_request_count, 0) + 1
                                        WHERE oa_id = ? AND sender_id = ?
                                    ");
                                    $upd_fail->execute([$oa_id, $c['sender_id']]);
                                }
                            } else {
                                // Thông tin đã đầy đủ hoặc không có cấu hình tin nhắn mẫu, cập nhật info_requested_at để tránh quét lại liên tục
                                $upd_skip = $pdo->prepare("
                                    UPDATE zalo_customers 
                                    SET info_requested_at = CURRENT_TIMESTAMP 
                                    WHERE oa_id = ? AND sender_id = ?
                                ");
                                $upd_skip->execute([$oa_id, $c['sender_id']]);
                            }
                        }
                    }
                }

                // --- TRƯỜNG HỢP 2: TỰ ĐỘNG GỬI TIN CSKH / FOLLOW-UP (Nếu thông tin đã đầy đủ) ---
                if ($is_info_complete && $followup_request_enabled && !empty($followup_request_text) && (int)($c['consulted'] ?? 0) === 1) {
                    $last_msg_ts = strtotime($c['last_message_at']);
                    $diff_hours = (time() - $last_msg_ts) / 3600;

                    if ($diff_hours >= $followup_hours) {
                        if (empty($c['followup_requested_at'])) {
                            
                            $customer_name = trim($c['name'] ?? 'bạn');
                            if (empty($customer_name)) {
                                $customer_name = 'bạn';
                            }
                            $message_text = replace_message_tags($followup_request_text, $customer_name, $c['sales_phone']);

                            echo "[ZALO] Đang gửi CSKH/Follow-up cho khách Zalo {$c['sender_id']} ({$customer_name}): \"$message_text\"\n";

                            // Gửi tin nhắn qua Zalo Open API
                            $res = zalo_send_text_message($access_token, $c['sender_id'], $message_text);

                            if (isset($res['status_code']) && $res['status_code'] === 200 && isset($res['data']['error']) && $res['data']['error'] === 0) {
                                // Cập nhật khách hàng (last_sender chuyển sang agent để ngừng spam)
                                $upd = $pdo->prepare("
                                    UPDATE zalo_customers 
                                    SET last_sender = 'agent', 
                                        last_message_at = CURRENT_TIMESTAMP, 
                                        followup_requested_at = CURRENT_TIMESTAMP 
                                    WHERE oa_id = ? AND sender_id = ?
                                ");
                                $upd->execute([$oa_id, $c['sender_id']]);

                                // Khóa chatbot 24 giờ để tránh chatbot tự động trả lời khi khách hàng phản hồi tin CSKH
                                try {
                                    $stmt_lock = $pdo->prepare("
                                        INSERT INTO zalo_chat_locks (oa_id, sender_id, expire_at)
                                        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                                        ON DUPLICATE KEY UPDATE expire_at = GREATEST(expire_at, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                                    ");
                                    $stmt_lock->execute([$oa_id, $c['sender_id']]);
                                } catch (Exception $e) {}

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
                                echo "[ZALO] Gửi CSKH thành công cho khách {$c['sender_id']}.\n";
                            } else {
                                $zalo_error_count++;
                                $err_msg = $res['data']['message'] ?? 'Lỗi không xác định.';
                                echo "[ZALO] Gửi CSKH thất bại cho khách {$c['sender_id']}: $err_msg\n";

                                // Vẫn cập nhật để tránh gửi lại liên tục khi lỗi
                                $upd_fail = $pdo->prepare("
                                    UPDATE zalo_customers 
                                    SET followup_requested_at = CURRENT_TIMESTAMP 
                                    WHERE oa_id = ? AND sender_id = ?
                                ");
                                $upd_fail->execute([$oa_id, $c['sender_id']]);
                            }
                        }
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

// Giải phóng file lock để tránh rò rỉ FD sang các tiến trình con được spawn sau đó (ví dụ publish_worker.php)
if (isset($lock_fp) && is_resource($lock_fp)) {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
}
?>
