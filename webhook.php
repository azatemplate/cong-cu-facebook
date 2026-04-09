<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

// Cấu hình mã xác minh (Verify Token) cho Webhook
$verify_token = 'HVP_WEBHOOK_VERIFY_TOKEN_2026'; // Bạn có thể đổi mã này và nhập vào form cấu hình Webhook trên Facebook App

// 1. Xử lý yêu cầu xác minh từ Facebook (GET Request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hub_mode      = $_GET['hub_mode'] ?? '';
    $hub_verify    = $_GET['hub_verify_token'] ?? '';
    $hub_challenge = $_GET['hub_challenge'] ?? '';

    if ($hub_mode === 'subscribe' && $hub_verify === $verify_token) {
        // Facebook yêu cầu trả về hub_challenge để xác minh thành công
        echo $hub_challenge;
        http_response_code(200);
        exit;
    } else {
        http_response_code(403);
        echo "Token xác minh không khớp.";
        exit;
    }
}

// 2. Xử lý dữ liệu gửi đến từ Facebook (POST Request)
// Yêu cầu lấy dữ liệu thô
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

// Debug logging
@file_put_contents(__DIR__ . '/webhook_debug.txt', date('Y-m-d H:i:s') . "\n" . $input . "\n\n", FILE_APPEND);

// DB error logging helper
function webhook_log($msg) {
    @file_put_contents(__DIR__ . '/webhook_db_errors.txt', date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
}

if ($data && isset($data['object']) && $data['object'] === 'page') {
    
    // Tự động tạo bảng nếu chưa có (dành cho môi trường mới chưa chạy script tạo bảng)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS page_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_id VARCHAR(50) NOT NULL,
            type VARCHAR(20) NOT NULL, /* 'message' or 'comment' */
            sender_id VARCHAR(50) NULL,
            sender_name VARCHAR(100) NULL,
            snippet TEXT NULL,
            post_id VARCHAR(50) NULL,
            comment_id VARCHAR(50) NULL,
            conversation_id VARCHAR(50) NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page (page_id),
            INDEX idx_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {}

    foreach ($data['entry'] as $entry) {
        $page_id = $entry['id'];

        // Kiểm tra xem có field 'messaging' (Tin nhắn mới) không
        if (isset($entry['messaging'])) {
            foreach ($entry['messaging'] as $messaging_event) {
                if (isset($messaging_event['message']) && !isset($messaging_event['message']['is_echo'])) {
                    $sender_id = $messaging_event['sender']['id'];
                    $text = $messaging_event['message']['text'] ?? 'Đã gửi một tệp đính kèm';
                    
                    // Lấy thông tin người gửi và conversation_id qua Graph API
                    $sender_name = null;
                    $conversation_id = null;
                    try {
                        $ts = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
                        $ts->execute([$page_id]);
                        if ($page = $ts->fetch(PDO::FETCH_ASSOC)) {
                            $page_token = decryptData($page['access_token']);
                            if ($page_token) {
                                $conv_res = fb_api_request($page_id . '/conversations', ['fields' => 'participants', 'user_id' => $sender_id, 'access_token' => $page_token]);
                                webhook_log("API Conv Res for $sender_id: " . json_encode($conv_res));
                                
                                if ($conv_res['status_code'] === 200 && !empty($conv_res['data']['data'][0])) {
                                    $conv_data = $conv_res['data']['data'][0];
                                    $conversation_id = $conv_data['id'] ?? null;
                                    
                                    // Tìm participants để lấy tên
                                    if (!empty($conv_data['participants']['data'])) {
                                        foreach ($conv_data['participants']['data'] as $p) {
                                            if ($p['id'] == $sender_id && !empty($p['name'])) {
                                                $sender_name = $p['name'];
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        webhook_log("Exception in conv fetch: " . $e->getMessage());
                    }

                    // Lưu trực tiếp vào Database
                    try {
                        $stmt = $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_id, sender_name, conversation_id, snippet) VALUES (?, 'message', ?, ?, ?, ?)");
                        $r = $stmt->execute([$page_id, $sender_id, $sender_name, $conversation_id, $text]);
                        webhook_log("MSG INSERT: page=$page_id sender=$sender_id result=" . ($r ? 'OK id='.$pdo->lastInsertId() : 'FAIL'));
                    } catch (Exception $e) { webhook_log('MSG DB ERR: ' . $e->getMessage()); }
                }
            }
        }
        
        // Kiểm tra xem có field 'changes' (Comments, Posts mới thuộc Feed) không
        if (isset($entry['changes'])) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'feed') {
                    $val = $change['value'];
                    // Chỉ lấy bình luận mới (item = comment) và không phải do chính page bình luận (ẩn danh / tự reply)
                    if ($val['item'] === 'comment' && $val['verb'] === 'add') {
                        $sender_id   = $val['from']['id'] ?? '';
                        $sender_name = $val['from']['name'] ?? 'Khách hàng';
                        $comment_id  = $val['comment_id'] ?? '';
                        $post_id     = $val['post_id'] ?? '';
                        $text        = $val['message'] ?? '';
                        
                        // Bỏ qua nếu ng gửi chính là Page
                        if ($sender_id === $page_id) continue;

                        try {
                            $stmt = $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_id, sender_name, snippet, post_id, comment_id) VALUES (?, 'comment', ?, ?, ?, ?, ?)");
                            $r = $stmt->execute([$page_id, $sender_id, $sender_name, $text, $post_id, $comment_id]);
                            webhook_log("CMT INSERT: page=$page_id post=$post_id sender=$sender_name result=" . ($r ? 'OK id='.$pdo->lastInsertId() : 'FAIL'));
                        } catch (Exception $e) { webhook_log('CMT DB ERR: ' . $e->getMessage()); }
                    }
                }
            }
        }
    }
    
    // Trả về 200 OK để Facebook biết đã nhận được
    http_response_code(200);
    echo "EVENT_RECEIVED";
} else {
    // Không phải Object Page
    http_response_code(404);
}
?>
