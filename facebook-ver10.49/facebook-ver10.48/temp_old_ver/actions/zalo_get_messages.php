<?php
// actions/zalo_get_messages.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/zalo_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}
session_write_close();

$oa_id = isset($_GET['oa_id']) ? trim($_GET['oa_id']) : '';
$sender_id = isset($_GET['sender_id']) ? trim($_GET['sender_id']) : '';
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$count = isset($_GET['count']) ? intval($_GET['count']) : 10;
if ($count > 10) {
    $count = 10;
}

if (empty($oa_id) || empty($sender_id)) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số OA ID hoặc Sender ID.']);
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

    // Get access token
    $access_token = zalo_get_active_token($oa_id, $pdo);
    if (!$access_token) {
        echo json_encode(['status' => 'error', 'msg' => 'Không thể lấy Access Token của Zalo OA. Vui lòng kết nối lại kênh.']);
        exit;
    }

    // Call Zalo API to get conversation messages
    $data_param = json_encode([
        'user_id' => $sender_id,
        'offset' => $offset,
        'count' => $count
    ]);
    
    $url = ZALO_API_BASE . 'v2.0/oa/conversation?data=' . urlencode($data_param);
    $headers = [
        "access_token: {$access_token}"
    ];
    
    $res = zalo_api_request($url, 'GET', $headers);
    
    if ($res['status_code'] === 200 && isset($res['data']['error']) && $res['data']['error'] === 0) {
        $messages = $res['data']['data'] ?? [];
        file_put_contents(__DIR__ . '/../zalo_messages_debug.json', json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Reset unread_count to 0 for this conversation in DB
        try {
            $stmt_reset = $pdo->prepare("UPDATE zalo_messages SET unread_count = 0 WHERE oa_id = ? AND sender_id = ?");
            $stmt_reset->execute([$oa_id, $sender_id]);
        } catch (Exception $e) {
            // Ignore/Log error if any
        }
        
        // Auto-extract name and avatar from conversation history if missing in DB
        $extracted_name = '';
        $extracted_avatar = '';
        foreach ($messages as $msg) {
            if (isset($msg['src'])) {
                if ($msg['src'] == 1) { // From user
                    $n = $msg['from_display_name'] ?? '';
                    $a = $msg['from_avatar'] ?? '';
                } else { // From OA
                    $n = $msg['to_display_name'] ?? '';
                    $a = $msg['to_avatar'] ?? '';
                }
                
                if (!empty($n) && $n !== 'Khách hàng Zalo' && $n !== 'Khách hàng' && empty($extracted_name)) {
                    $extracted_name = $n;
                }
                if (!empty($a) && strpos($a, 'ui-avatars.com') === false && empty($extracted_avatar)) {
                    $extracted_avatar = $a;
                }
            }
            if (!empty($extracted_name) && !empty($extracted_avatar)) {
                break;
            }
        }
        
        if (!empty($extracted_name)) {
            $stmt_chk = $pdo->prepare("SELECT name, avatar FROM zalo_customers WHERE oa_id = ? AND sender_id = ?");
            $stmt_chk->execute([$oa_id, $sender_id]);
            $cust = $stmt_chk->fetch(PDO::FETCH_ASSOC);
            
            $need_update = false;
            if (!$cust) {
                $need_update = true;
            } else {
                if (empty($cust['name']) || $cust['name'] === 'Khách hàng Zalo' || empty($cust['avatar']) || strpos($cust['avatar'], 'ui-avatars.com') !== false) {
                    $need_update = true;
                }
            }
            
            if ($need_update) {
                $final_avatar = $extracted_avatar ?: ($cust['avatar'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($extracted_name));
                $stmt_ins = $pdo->prepare("
                    INSERT INTO zalo_customers (oa_id, sender_id, name, avatar)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name),
                        avatar = VALUES(avatar)
                ");
                $stmt_ins->execute([$oa_id, $sender_id, $extracted_name, $final_avatar]);
                
                // Also update zalo_messages
                $stmt_upd_msg = $pdo->prepare("
                    UPDATE zalo_messages 
                    SET sender_name = ? 
                    WHERE oa_id = ? AND sender_id = ?
                ");
                $stmt_upd_msg->execute([$extracted_name, $oa_id, $sender_id]);
            }
        }
        
        // Enrich messages with file metadata from DB (for file/image/audio attachments)
        $msg_ids = [];
        foreach ($messages as $msg) {
            if (!empty($msg['message_id'])) {
                $msg_ids[] = $msg['message_id'];
            }
        }
        
        $file_meta_map = [];
        if (!empty($msg_ids)) {
            $placeholders = implode(',', array_fill(0, count($msg_ids), '?'));
            $stmt_files = $pdo->prepare("SELECT message_id, file_name, file_url, file_size, file_type FROM zalo_file_messages WHERE message_id IN ($placeholders)");
            $stmt_files->execute($msg_ids);
            while ($row = $stmt_files->fetch(PDO::FETCH_ASSOC)) {
                $file_meta_map[$row['message_id']] = $row;
            }
        }
        
        // Inject file metadata into messages
        foreach ($messages as &$msg) {
            $mid = $msg['message_id'] ?? '';
            if (!empty($mid) && isset($file_meta_map[$mid])) {
                $meta = $file_meta_map[$mid];
                $msg['_file_meta'] = [
                    'name' => $meta['file_name'],
                    'url' => $meta['file_url'],
                    'size' => (int)$meta['file_size'],
                    'type' => $meta['file_type']
                ];
                // Set message type if missing
                if (empty($msg['type'])) {
                    $msg['type'] = $meta['file_type'];
                }
            }
        }
        unset($msg);
        
        echo json_encode([
            'status' => 'success',
            'data' => $messages,
            'next_offset' => $offset + count($messages)
        ]);
    } else {
        $error_msg = $res['data']['message'] ?? 'Lỗi không xác định khi gọi Zalo API.';
        echo json_encode(['status' => 'error', 'msg' => $error_msg]);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
}
