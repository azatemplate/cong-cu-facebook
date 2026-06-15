<?php
// actions/zalo_send_message.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/zalo_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oa_id = isset($_POST['oa_id']) ? trim($_POST['oa_id']) : '';
    $recipient_id = isset($_POST['recipient_id']) ? trim($_POST['recipient_id']) : '';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $has_file = isset($_FILES['filedata']) && $_FILES['filedata']['error'] === UPLOAD_ERR_OK;

    if (empty($oa_id) || empty($recipient_id) || (empty($message) && !$has_file)) {
        echo json_encode(['status' => 'error', 'msg' => 'Tham số không hợp lệ.']);
        exit;
    }

    try {
        // Verify that this OA belongs to the current user
        $stmt_oa = $pdo->prepare("SELECT oa_id FROM zalo_oas WHERE oa_id = ? AND account_id = ?");
        $stmt_oa->execute([$oa_id, $_SESSION['account_id']]);
        if (!$stmt_oa->fetch()) {
            echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền truy cập kênh OA này.']);
            exit;
        }

        // Get active access token
        $access_token = zalo_get_active_token($oa_id, $pdo);
        if (!$access_token) {
            echo json_encode(['status' => 'error', 'msg' => 'Không thể lấy Access Token của Zalo OA. Vui lòng kết nối lại kênh.']);
            exit;
        }

        // 1. Check 24-hour limit using Zalo conversation history API
        $data_param = json_encode([
            'user_id' => $recipient_id,
            'offset' => 0,
            'count' => 10
        ]);
        $check_url = ZALO_API_BASE . 'v2.0/oa/conversation?data=' . urlencode($data_param);
        $check_headers = ["access_token: {$access_token}"];
        $check_res = zalo_api_request($check_url, 'GET', $check_headers);
        
        $is_over_24h = false;
        if ($check_res['status_code'] === 200 && isset($check_res['data']['error']) && $check_res['data']['error'] === 0) {
            $history = $check_res['data']['data'] ?? [];
            $last_customer_time = 0;
            foreach ($history as $msg) {
                // src = 1 means customer sent it, src = 0 means OA sent it
                if (isset($msg['src']) && $msg['src'] == 1) {
                    $last_customer_time = intval($msg['time'] / 1000); // ms to seconds
                    break;
                }
            }
            
            if ($last_customer_time > 0) {
                $diff_hours = (time() - $last_customer_time) / 3600;
                if ($diff_hours > 24) {
                    $is_over_24h = true;
                }
            } else {
                // Fallback: check conversation's last updated time in DB
                $stmt_msg = $pdo->prepare("SELECT updated_time FROM zalo_messages WHERE oa_id = ? AND sender_id = ?");
                $stmt_msg->execute([$oa_id, $recipient_id]);
                $conv_db = $stmt_msg->fetch(PDO::FETCH_ASSOC);
                if ($conv_db) {
                    $upd_time = strtotime($conv_db['updated_time']);
                    $diff_hours = (time() - $upd_time) / 3600;
                    if ($diff_hours > 24) {
                        $is_over_24h = true;
                    }
                }
            }
        }
        
        if ($is_over_24h) {
            echo json_encode([
                'status' => 'error', 
                'msg' => 'Gửi thất bại: Cuộc trò chuyện đã quá 24 giờ kể từ tương tác cuối cùng của khách hàng.'
            ]);
            exit;
        }

        // 2. Handle File Upload
        $send_res = null;
        $is_image = false;
        if ($has_file) {
            $file_tmp = $_FILES['filedata']['tmp_name'];
            $file_type = $_FILES['filedata']['type'];
            $file_name = $_FILES['filedata']['name'];
            $file_size = $_FILES['filedata']['size'];
            
            // Check file size under 5MB (Zalo file limit)
            if ($file_size > 5 * 1024 * 1024) {
                echo json_encode(['status' => 'error', 'msg' => 'Dung lượng file vượt quá giới hạn 5MB của Zalo OA.']);
                exit;
            }

            $is_image = (strpos($file_type, 'image/') === 0);

            // Temp folder in project structure to avoid open_basedir restrictions
            $tmp_dir = __DIR__ . '/../uploads/tmp';
            if (!is_dir($tmp_dir)) {
                @mkdir($tmp_dir, 0777, true);
            }
            
            $local_temp_file = $tmp_dir . '/' . uniqid('zalo_att_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file_name);
            if (move_uploaded_file($file_tmp, $local_temp_file)) {
                $file_to_send = $local_temp_file;
            } else {
                $file_to_send = $file_tmp;
            }

            if ($is_image) {
                // Upload image to Zalo server
                $attachment_id = zalo_upload_image($access_token, $file_to_send, $file_name, $file_type);
                
                if ($file_to_send !== $file_tmp && file_exists($file_to_send)) {
                    @unlink($file_to_send);
                }

                if (!$attachment_id) {
                    echo json_encode(['status' => 'error', 'msg' => 'Không thể upload ảnh lên Zalo OA server.']);
                    exit;
                }

                // Send image message
                $send_res = zalo_send_image_message($access_token, $recipient_id, $attachment_id);
            } else {
                // Upload other files (PDF, DOC, DOCX, CSV, etc.)
                $file_token = zalo_upload_file($access_token, $file_to_send, $file_name, $file_type);

                if ($file_to_send !== $file_tmp && file_exists($file_to_send)) {
                    @unlink($file_to_send);
                }

                if (!$file_token) {
                    echo json_encode(['status' => 'error', 'msg' => 'Không thể upload tài liệu lên Zalo OA server. Hỗ trợ định dạng PDF, DOC, DOCX, CSV dưới 5MB.']);
                    exit;
                }

                // Send file message
                $send_res = zalo_send_file_message($access_token, $recipient_id, $file_token);
            }
        } else {
            // Send text message
            $send_res = zalo_send_text_message($access_token, $recipient_id, $message);
        }

        // 3. Process send result
        if (isset($send_res['status_code']) && $send_res['status_code'] === 200 && isset($send_res['data']['error']) && $send_res['data']['error'] === 0) {
            $snippet = $message ?: ($has_file ? ($is_image ? '[Hình ảnh]' : '[Tài liệu] ' . $file_name) : '');
            
            // Update conversation thread in DB
            $upd_stmt = $pdo->prepare("
                INSERT INTO zalo_messages (oa_id, sender_id, snippet, unread_count, updated_time)
                VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE 
                    snippet = VALUES(snippet),
                    unread_count = 0,
                    updated_time = CURRENT_TIMESTAMP
            ");
            $upd_stmt->execute([$oa_id, $recipient_id, $snippet]);

            // Lock chatbot for 30 minutes due to manual admin activity
            $lock_stmt = $pdo->prepare("
                INSERT INTO zalo_chat_locks (oa_id, sender_id, expire_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                ON DUPLICATE KEY UPDATE expire_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
            ");
            $lock_stmt->execute([$oa_id, $recipient_id]);

            echo json_encode([
                'status' => 'success',
                'data' => $send_res['data']
            ]);
        } else {
            $error_msg = $send_res['data']['message'] ?? 'Lỗi không xác định từ Zalo API.';
            echo json_encode(['status' => 'error', 'msg' => 'Gửi thất bại: ' . $error_msg]);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
}
?>
