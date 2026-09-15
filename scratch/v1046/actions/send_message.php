<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// ── Auth Guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

// Clear conversation cache
foreach (array_keys($_SESSION) as $key) {
    if (strpos($key, 'fb_convs_') === 0) {
        unset($_SESSION[$key]);
    }
}
session_write_close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $recipient_id = isset($_POST['recipient_id']) ? trim($_POST['recipient_id']) : '';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $page_id = isset($_POST['page_id']) ? $_POST['page_id'] : '';
    $has_file = isset($_FILES['filedata']) && $_FILES['filedata']['error'] === UPLOAD_ERR_OK;

    if ((!$message && !$has_file) || !$recipient_id || !$user_id || empty($page_id)) {
        echo json_encode(['status' => 'error', 'msg' => 'Tham số không hợp lệ.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ? AND user_id = ?");
    $stmt->execute([$page_id, $user_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page || empty($page['access_token'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Token Fanpage.']);
        exit;
    }

    $page_access_token = decryptData($page['access_token']);
    $endpoint = 'me/messages';

    // Send File Attachment if exists
    $file_success = true;
    $file_response_data = null;

    if ($has_file) {
        $file_tmp = $_FILES['filedata']['tmp_name'];
        $file_type = $_FILES['filedata']['type'];
        $file_name = $_FILES['filedata']['name'];
        
        $attachment_type = 'file';
        if (strpos($file_type, 'image/') === 0) {
            $attachment_type = 'image';
        } elseif (strpos($file_type, 'video/') === 0) {
            $attachment_type = 'video';
        } elseif (strpos($file_type, 'audio/') === 0) {
            $attachment_type = 'audio';
        }

        // Tạo thư mục tạm trong dự án để tránh lỗi open_basedir restrictions của VPS
        $tmp_dir = __DIR__ . '/../uploads/tmp';
        if (!is_dir($tmp_dir)) {
            @mkdir($tmp_dir, 0777, true);
        }
        
        // Di chuyển file tạm vào thư mục dự án
        $local_temp_file = $tmp_dir . '/' . uniqid('fb_att_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file_name);
        if (move_uploaded_file($file_tmp, $local_temp_file)) {
            $file_to_send = $local_temp_file;
        } else {
            // Fallback nếu không di chuyển được file
            $file_to_send = $file_tmp;
        }

        $post_data_file = [
            'recipient' => json_encode(['id' => $recipient_id]),
            'message' => json_encode([
                'attachment' => [
                    'type' => $attachment_type,
                    'payload' => ['is_reusable' => false]
                ]
            ]),
            'messaging_type' => 'RESPONSE', // Dùng RESPONSE thay vì MESSAGE_TAG cho tệp đính kèm để tránh lỗi Invalid parameter
            'filedata' => new CURLFile($file_to_send, $file_type, $file_name)
        ];

        $params = ['access_token' => $page_access_token];
        $file_response = fb_api_request($endpoint, $params, 'POST', $post_data_file);
        
        // Xóa file tạm sau khi gửi
        if ($file_to_send !== $file_tmp && file_exists($file_to_send)) {
            @unlink($file_to_send);
        }
        
        if ($file_response['status_code'] !== 200) {
            $file_success = false;
            $error_data = $file_response['data']['error'] ?? [];
            $error_msg = translate_fb_error($error_data);
            echo json_encode(['status' => 'error', 'msg' => $error_msg]);
            exit;
        } else {
            $file_response_data = $file_response['data'];
        }
    }

    // Send Text Message if exists
    $text_success = true;
    $text_response_data = null;

    if ($message !== '') {
        $post_data_text = [
            'recipient' => json_encode(['id' => $recipient_id]),
            'message' => json_encode(['text' => $message]),
            'messaging_type' => 'RESPONSE'
        ];

        $params = ['access_token' => $page_access_token];
        $text_response = fb_api_request($endpoint, $params, 'POST', $post_data_text);

        if ($text_response['status_code'] !== 200) {
            $text_success = false;
            // Only output error if we haven't already succeeded with a file
            if (!$has_file) {
                $error_data = $text_response['data']['error'] ?? [];
                $error_msg = translate_fb_error($error_data);
                echo json_encode(['status' => 'error', 'msg' => $error_msg]);
                exit;
            }
        } else {
            $text_response_data = $text_response['data'];
        }
    }

    if ($file_success || $text_success) {
        try {
            $st_upd = $pdo->prepare("UPDATE fb_customers SET last_sender = 'agent', last_message_at = CURRENT_TIMESTAMP WHERE page_id = ? AND sender_id = ?");
            $st_upd->execute([$page_id, $recipient_id]);
        } catch (Exception $e) {}

        // Lock chatbot for 30 minutes due to manual admin activity (preserve existing permanent locks)
        try {
            $lock_stmt = $pdo->prepare("
                INSERT INTO bot_chat_locks (page_id, sender_id, expire_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                ON DUPLICATE KEY UPDATE expire_at = GREATEST(expire_at, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
            ");
            $lock_stmt->execute([$page_id, $recipient_id]);
        } catch (Exception $e) {}

        echo json_encode([
            'status' => 'success', 
            'data' => $text_response_data ?: $file_response_data
        ]);
    }


} else {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
}

/**
 * Dịch lỗi Facebook API thành thông báo tiếng Việt thân thiện
 */
function translate_fb_error($error_data) {
    if (empty($error_data) || !is_array($error_data)) {
        return 'Lỗi không xác định từ Facebook API.';
    }
    
    $code = $error_data['code'] ?? 0;
    $subcode = $error_data['error_subcode'] ?? 0;
    $msg = $error_data['message'] ?? '';
    
    // Kiểm tra lỗi chính sách nhắn tin ngoài khung 24h
    if ($code === 10 || 
        strpos(strtolower($msg), 'outside the allowed window') !== false || 
        strpos($msg, 'ngoài khoảng thời gian cho phép') !== false ||
        $subcode === 2018028
    ) {
        return 'Cuộc trò chuyện đã quá 24 giờ kể từ tương tác cuối cùng của khách hàng. Facebook không cho phép gửi tin nhắn thường lúc này.';
    }
    
    return $msg ?: 'Lỗi gửi tin nhắn.';
}
?>
