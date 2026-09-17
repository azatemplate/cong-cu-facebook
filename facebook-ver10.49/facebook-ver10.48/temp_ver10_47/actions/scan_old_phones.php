<?php
session_start();
// Thiết lập không giới hạn thời gian chạy cho tiến trình quét SĐT
@set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (php_sapi_name() === 'cli') {
    $account_id = isset($argv[1]) ? intval($argv[1]) : 0;
} else if (isset($_GET['account_id'])) {
    $account_id = intval($_GET['account_id']);
} else {
    if (!isset($_SESSION['account_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
        exit;
    }
    $account_id = $_SESSION['account_id'];
}
session_write_close();

$lock_dir = dirname(__DIR__) . '/locks';
if (!is_dir($lock_dir)) {
    @mkdir($lock_dir, 0777, true);
}
$lock_file = $lock_dir . '/scan_' . intval($account_id) . '.lock';

// Clean stale lock > 15 min
if (file_exists($lock_file) && (time() - filemtime($lock_file)) > 900) {
    @unlink($lock_file);
}

$lock_fp = @fopen($lock_file, 'c+');
if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    if ($lock_fp) fclose($lock_fp);
    if (php_sapi_name() !== 'cli') {
        echo json_encode(['status' => 'success', 'scanned' => 0, 'found' => 0, 'msg' => 'Tiến trình quét đang chạy ngầm']);
    }
    exit;
}

register_shutdown_function(function() use ($lock_fp, $lock_file) {
    if ($lock_fp) {
        flock($lock_fp, LOCK_UN);
        fclose($lock_fp);
    }
    if (file_exists($lock_file)) {
        @unlink($lock_file);
    }
});

try {
    // Tự động migration thêm cột phone_scanned_at nếu chưa có
    try {
        $pdo->exec("ALTER TABLE fb_customers ADD COLUMN phone_scanned_at DATETIME DEFAULT NULL");
    } catch (Exception $e) {}

    // Lấy tất cả hội thoại của tài khoản hiện tại mà khách hàng CHƯA có số điện thoại
    // Ưu tiên hội thoại mới nhất và bỏ qua các khách hàng đã quét trong vòng 24 giờ qua bằng cột phone_scanned_at
    $stmt = $pdo->prepare("
        SELECT c.conversation_id, c.page_id, c.sender_id, c.sender_name, p.access_token
        FROM fb_conversations c
        JOIN pages p ON c.page_id = p.page_id
        JOIN users u ON p.user_id = u.id
        LEFT JOIN fb_customers fc ON fc.page_id = c.page_id AND fc.sender_id = c.sender_id
        WHERE u.account_id = :aid
          AND (fc.phone IS NULL OR fc.phone = '')
          AND (fc.phone_scanned_at IS NULL OR fc.phone_scanned_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))
        ORDER BY c.updated_time DESC
    ");
    $stmt->execute([':aid' => $account_id]);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi truy vấn DB: ' . $e->getMessage()]);
    exit;
}

if (empty($targets)) {
    echo json_encode(['status' => 'success', 'scanned' => 0, 'found' => 0, 'msg' => 'Tất cả khách hàng đã có SĐT hoặc chưa có dữ liệu hội thoại.']);
    exit;
}

// Giải mã token lưu trong bộ nhớ
$tokens_cache = [];
$scanned_count = 0;
$found_count = 0;

foreach ($targets as $t) {
    $pid = $t['page_id'];
    $conv_id = $t['conversation_id'];
    $sender_id = $t['sender_id'];
    $sender_name = $t['sender_name'];
    
    if (empty($conv_id)) continue;

    if (!isset($tokens_cache[$pid])) {
        $tokens_cache[$pid] = decryptData($t['access_token']);
    }
    $token = $tokens_cache[$pid];

    // Lấy tối đa 50 tin nhắn cuối cùng trong cuộc hội thoại để quét tìm SĐT
    $params = [
        'fields' => 'message,from',
        'access_token' => $token,
        'limit' => 50
    ];
    
    $response = fb_api_request($conv_id . '/messages', $params, 'GET');
    $scanned_count++;
    
    if ($response['status_code'] !== 200) {
        $err_details = json_encode($response['data']['error'] ?? $response);
        @file_put_contents(__DIR__ . '/../uploads/scan_error.log', date('[Y-m-d H:i:s] ') . "FB API Error (Conv: $conv_id, Page: $pid): $err_details\n", FILE_APPEND);
    }
    
    if ($response['status_code'] === 200 && !empty($response['data']['data'])) {
        $messages = $response['data']['data'];
        
        $phone_found = false;
        // Quét ngược từ tin nhắn mới nhất đến cũ nhất
        foreach ($messages as $msg) {
            $from_id = $msg['from']['id'] ?? '';
            
            // Chỉ quét tin nhắn của KHÁCH HÀNG gửi (không quét tin của Page gửi)
            if (trim($from_id) != trim($pid)) {
                $msg_text = $msg['message'] ?? '';
                $phone = extract_phone_number($msg_text);
                
                if ($phone) {
                    // Cập nhật SĐT vào fb_customers
                    try {
                        $upd_stmt = $pdo->prepare("
                            INSERT INTO fb_customers (page_id, sender_id, name, phone, phone_scanned_at, last_message_at)
                            VALUES (?, ?, ?, ?, NOW(), CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE
                                phone = VALUES(phone),
                                phone_scanned_at = VALUES(phone_scanned_at)
                        ");
                        $upd_stmt->execute([$pid, $sender_id, $sender_name, $phone]);
                        
                        // Đánh dấu nhãn "Đã cho số điện thoại" cục bộ
                        $lbl_stmt = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
                        $lbl_stmt->execute([$conv_id, $pid, $sender_id]);
                    } catch (Exception $ex) {
                        @file_put_contents(__DIR__ . '/../uploads/scan_error.log', date('[Y-m-d H:i:s] ') . "DB Update Error (Sender: $sender_id): " . $ex->getMessage() . "\n", FILE_APPEND);
                    }
                    
                    $found_count++;
                    $phone_found = true;
                    break; // Dừng quét cuộc hội thoại này chuyển sang khách tiếp theo
                }
            }
        }
        
        // Nếu đã quét hết 50 tin nhắn mà không tìm thấy SĐT, cập nhật phone_scanned_at của khách hàng
        // để lượt quét sau bỏ qua không quét lại KH này nữa
        if (!$phone_found) {
            try {
                $upd_no_phone = $pdo->prepare("
                    INSERT INTO fb_customers (page_id, sender_id, name, phone_scanned_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        phone_scanned_at = VALUES(phone_scanned_at)
                ");
                $upd_no_phone->execute([$pid, $sender_id, $sender_name]);
            } catch (Exception $ex) {}
        }
    }
    
    // Dừng lại nếu quét quá 200 cuộc hội thoại trong 1 request để tránh quá tải API Facebook Rate Limits
    if ($scanned_count >= 200) {
        break;
    }
}

// Giải phóng khóa tiến trình quét SĐT
$lock_file = __DIR__ . '/../locks/scan_' . intval($account_id) . '.lock';
@unlink($lock_file);

echo json_encode([
    'status' => 'success',
    'scanned' => $scanned_count,
    'found' => $found_count
]);
exit;

/**
 * Trích xuất số điện thoại Việt Nam từ văn bản
 */
function extract_phone_number($text) {
    if (empty($text)) return null;
    
    // Loại bỏ khoảng trắng, dấu chấm, dấu gạch ngang để chuẩn hóa
    $clean = preg_replace('/[\s.\-_]+/', '', $text);
    
    // Regex nhận diện số điện thoại Việt Nam (hỗ trợ cả định dạng +84, 84, 0)
    if (preg_match('/(?:\+84|84|0)(3|5|7|8|9)\d{8}\b/', $clean, $matches)) {
        $phone = $matches[0];
        
        // Chuẩn hóa về đầu số 0
        if (strpos($phone, '+84') === 0) {
            $phone = '0' . substr($phone, 3);
        } elseif (strpos($phone, '84') === 0 && strlen($phone) === 11) {
            $phone = '0' . substr($phone, 2);
        }
        return $phone;
    }
    return null;
}
?>
