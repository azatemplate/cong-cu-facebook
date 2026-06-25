<?php
// show_customers.php
// Script chẩn đoán chi tiết: đối chiếu cấu hình và dữ liệu khách hàng thực tế

ignore_user_abort(true);
set_time_limit(60);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

header('Content-Type: text/plain; charset=utf-8');

echo "========================================================\n";
echo "  DETAILED AUTO-REQUEST DIAGNOSTICS - " . date('Y-m-d H:i:s') . "\n";
echo "========================================================\n\n";

// --- 1. XEM CẤU HÌNH TÀI KHOẢN ---
echo "--- CẤU HÌNH HỆ THỐNG (SYSTEM ACCOUNTS) ---\n";
try {
    $stmt = $pdo->query("SELECT id, username, phone_request_enabled, phone_request_hours, phone_request_text FROM system_accounts");
    $sys_accs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sys_accs as $sa) {
        echo "Account ID: {$sa['id']} | User: {$sa['username']}\n";
        echo "  Auto-Request Enabled: " . ($sa['phone_request_enabled'] ? "BẬT" : "TẮT") . " | Hours wait: {$sa['phone_request_hours']} giờ\n";
        echo "  Template: '" . ($sa['phone_request_text'] ? "Có cấu hình" : "Trống") . "'\n";
        echo "--------------------------------------------------------\n";
    }
} catch (Exception $e) {
    echo "Lỗi query system_accounts: " . $e->getMessage() . "\n";
}
echo "\n";

// --- 2. XEM CẤU HÌNH ZALO SETTINGS ---
echo "--- CẤU HÌNH ZALO (ZALO SETTINGS) ---\n";
try {
    $stmt = $pdo->query("SELECT account_id, phone_request_enabled, phone_request_hours, phone_request_text FROM zalo_settings");
    $zalo_sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($zalo_sets)) {
        echo "Không có cấu hình zalo_settings nào.\n";
    } else {
        foreach ($zalo_sets as $zs) {
            echo "Account ID: {$zs['account_id']}\n";
            echo "  Zalo Auto-Request Enabled: " . ($zs['phone_request_enabled'] ? "BẬT" : "TẮT") . " | Hours wait: {$zs['phone_request_hours']} giờ\n";
            echo "  Template: '" . ($zs['phone_request_text'] ? "Có cấu hình" : "Trống") . "'\n";
            echo "--------------------------------------------------------\n";
        }
    }
} catch (Exception $e) {
    echo "Lỗi query zalo_settings: " . $e->getMessage() . "\n";
}
echo "\n";

// --- 3. PHÂN TÍCH KHÁCH HÀNG CHƯA CÓ SĐT (FACEBOOK) ---
echo "--- KHÁCH HÀNG FACEBOOK CHƯA CÓ SĐT (BẤT KỲ TIN NHẮN CUỐI NÀO) ---\n";
try {
    $stmt = $pdo->query("
        SELECT c.name, c.sender_id, c.page_id, c.last_message_at, c.info_requested_at, p.user_id, u.account_id
        FROM fb_customers c
        LEFT JOIN pages p ON c.page_id = p.page_id
        LEFT JOIN users u ON p.user_id = u.id
        WHERE (c.phone IS NULL OR c.phone = '')
        ORDER BY c.last_message_at DESC 
        LIMIT 10
    ");
    $fb_custs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($fb_custs)) {
        echo "Không tìm thấy khách hàng Facebook nào thỏa mãn.\n";
    } else {
        foreach ($fb_custs as $idx => $c) {
            $num = $idx + 1;
            $acc_id = $c['account_id'] ?? 'Không xác định';
            
            // Lấy cấu hình của account liên kết
            $stmt_cfg = $pdo->prepare("SELECT phone_request_enabled, phone_request_hours, phone_request_text FROM system_accounts WHERE id = ?");
            $stmt_cfg->execute([$acc_id]);
            $cfg = $stmt_cfg->fetch(PDO::FETCH_ASSOC);
            $enabled = $cfg ? (int)$cfg['phone_request_enabled'] : 0;
            $hours = $cfg ? (int)$cfg['phone_request_hours'] : 0;
            $has_text = $cfg ? !empty($cfg['phone_request_text']) : false;
            
            $last_msg_ts = strtotime($c['last_message_at']);
            $diff_hours = (time() - $last_msg_ts) / 3600;
            
            // Check locks
            $stmt_lock = $pdo->prepare("SELECT expire_at FROM bot_chat_locks WHERE page_id = ? AND sender_id = ? AND expire_at > NOW()");
            $stmt_lock->execute([$c['page_id'], $c['sender_id']]);
            $lock = $stmt_lock->fetchColumn();
            
            echo "[$num] Khách hàng: {$c['name']} (ID: {$c['sender_id']})\n";
            echo "    Thuộc Page ID: {$c['page_id']} | Thuộc Account ID: $acc_id\n";
            echo "    Cấu hình Account: Enabled=" . ($enabled ? "YES" : "NO") . ", Hours=$hours, HasTemplate=" . ($has_text ? "YES" : "NO") . "\n";
            echo "    last_message_at: {$c['last_message_at']} | Time elapsed: " . round($diff_hours, 2) . " giờ\n";
            echo "    info_requested_at: " . ($c['info_requested_at'] ?? 'NULL') . "\n";
            echo "    Chat lock status: " . ($lock ? "ĐANG BỊ KHÓA (đến $lock)" : "Không bị khóa") . "\n";
            
            // Đánh giá lý do bị bỏ qua
            $reasons = [];
            if (!$enabled) $reasons[] = "Chưa bật Auto-Request trên tài khoản này";
            if ($diff_hours < $hours) $reasons[] = "Chưa đủ thời gian chờ (mới trôi qua " . round($diff_hours, 2) . " giờ / yêu cầu $hours giờ)";
            if ($lock) $reasons[] = "Chatbot đang bị khóa cho khách này";
            if (!$has_text) $reasons[] = "Mẫu tin nhắn xin SĐT đang để trống";
            if (!empty($c['info_requested_at']) && strtotime($c['last_message_at']) <= strtotime($c['info_requested_at'])) {
                $reasons[] = "Đã gửi yêu cầu cho tin nhắn này trước đó (info_requested_at mới hơn last_message_at)";
            }
            
            if (empty($reasons)) {
                echo "    => ĐỦ ĐIỀU KIỆN! Sẽ được gửi trong lượt quét tiếp theo.\n";
            } else {
                echo "    => BỊ BỎ QUA vì các lý do:\n";
                foreach ($reasons as $r) {
                    echo "       - $r\n";
                }
            }
            echo "--------------------------------------------------------\n";
        }
    }
} catch (Exception $e) {
    echo "Lỗi chẩn đoán Facebook: " . $e->getMessage() . "\n";
}
echo "\n";

// --- 4. PHÂN TÍCH KHÁCH HÀNG CHƯA CÓ SĐT (ZALO) ---
echo "--- KHÁCH HÀNG ZALO CHƯA CÓ SĐT (BẤT KỲ TIN NHẮN CUỐI NÀO) ---\n";
try {
    $stmt = $pdo->query("
        SELECT c.name, c.sender_id, c.oa_id, c.last_message_at, c.info_requested_at, o.account_id
        FROM zalo_customers c
        LEFT JOIN zalo_oas o ON c.oa_id = o.oa_id
        WHERE (c.phone IS NULL OR c.phone = '')
        ORDER BY c.last_message_at DESC 
        LIMIT 10
    ");
    $zalo_custs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($zalo_custs)) {
        echo "Không tìm thấy khách hàng Zalo nào thỏa mãn.\n";
    } else {
        foreach ($zalo_custs as $idx => $c) {
            $num = $idx + 1;
            $acc_id = $c['account_id'] ?? 'Không xác định';
            
            // Lấy cấu hình của zalo_settings liên kết
            $stmt_cfg = $pdo->prepare("SELECT phone_request_enabled, phone_request_hours, phone_request_text FROM zalo_settings WHERE account_id = ?");
            $stmt_cfg->execute([$acc_id]);
            $cfg = $stmt_cfg->fetch(PDO::FETCH_ASSOC);
            $enabled = $cfg ? (int)$cfg['phone_request_enabled'] : 0;
            $hours = $cfg ? (int)$cfg['phone_request_hours'] : 0;
            $has_text = $cfg ? !empty($cfg['phone_request_text']) : false;
            
            $last_msg_ts = strtotime($c['last_message_at']);
            $diff_hours = (time() - $last_msg_ts) / 3600;
            
            // Check locks
            $stmt_lock = $pdo->prepare("SELECT expire_at FROM zalo_chat_locks WHERE oa_id = ? AND sender_id = ? AND expire_at > NOW()");
            $stmt_lock->execute([$c['oa_id'], $c['sender_id']]);
            $lock = $stmt_lock->fetchColumn();
            
            echo "[$num] Khách hàng: {$c['name']} (ID: {$c['sender_id']})\n";
            echo "    Thuộc OA ID: {$c['oa_id']} | Thuộc Account ID: $acc_id\n";
            echo "    Cấu hình Zalo: Enabled=" . ($enabled ? "YES" : "NO") . ", Hours=$hours, HasTemplate=" . ($has_text ? "YES" : "NO") . "\n";
            echo "    last_message_at: {$c['last_message_at']} | Time elapsed: " . round($diff_hours, 2) . " giờ\n";
            echo "    info_requested_at: " . ($c['info_requested_at'] ?? 'NULL') . "\n";
            echo "    Chat lock status: " . ($lock ? "ĐANG BỊ KHÓA (đến $lock)" : "Không bị khóa") . "\n";
            
            // Đánh giá lý do bị bỏ qua
            $reasons = [];
            if (!$enabled) $reasons[] = "Chưa bật Zalo Auto-Request trên tài khoản này";
            if ($diff_hours < $hours) $reasons[] = "Chưa đủ thời gian chờ (mới trôi qua " . round($diff_hours, 2) . " giờ / yêu cầu $hours giờ)";
            if ($lock) $reasons[] = "Chatbot đang bị khóa cho khách này";
            if (!$has_text) $reasons[] = "Mẫu tin nhắn xin SĐT đang để trống";
            if (!empty($c['info_requested_at']) && strtotime($c['last_message_at']) <= strtotime($c['info_requested_at'])) {
                $reasons[] = "Đã gửi yêu cầu cho tin nhắn này trước đó (info_requested_at mới hơn last_message_at)";
            }
            
            if (empty($reasons)) {
                echo "    => ĐỦ ĐIỀU KIỆN! Sẽ được gửi trong lượt quét tiếp theo.\n";
            } else {
                echo "    => BỊ BỎ QUA vì các lý do:\n";
                foreach ($reasons as $r) {
                    echo "       - $r\n";
                }
            }
            echo "--------------------------------------------------------\n";
        }
    }
} catch (Exception $e) {
    echo "Lỗi chẩn đoán Zalo: " . $e->getMessage() . "\n";
}

echo "\n========================================================\n";
?>
