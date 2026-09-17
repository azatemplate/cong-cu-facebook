<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Auth guard
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

$sender_id = trim($_GET['sender_id'] ?? '');
$page_id = trim($_GET['page_id'] ?? '');

if (!$sender_id || !$page_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu sender_id hoặc page_id']);
    exit;
}

try {
    // Check if customer exists in the local database
    $stmt = $pdo->prepare("SELECT name, phone, province, notes, consulted, sales_phone, sales_notes, is_ads, ad_id, ad_title, ad_photo_url, capi_pushed FROM fb_customers WHERE page_id = ? AND sender_id = ?");
    $stmt->execute([$page_id, $sender_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        // Fallback: try to find the customer name from page notifications
        $stmt_notif = $pdo->prepare("SELECT sender_name FROM page_notifications WHERE page_id = ? AND sender_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_notif->execute([$page_id, $sender_id]);
        $name = $stmt_notif->fetchColumn() ?: 'Khách hàng';
        
        // Insert a new record so it exists
        $ins = $pdo->prepare("INSERT INTO fb_customers (page_id, sender_id, name) VALUES (?, ?, ?)");
        $ins->execute([$page_id, $sender_id, $name]);
        
        $customer = [
            'name' => $name,
            'phone' => '',
            'province' => '',
            'notes' => '',
            'consulted' => 0,
            'sales_phone' => '',
            'sales_notes' => '',
            'is_ads' => 0,
            'ad_id' => '',
            'ad_title' => '',
            'ad_photo_url' => '',
            'capi_pushed' => 0
        ];
    }
    
    $stmt_lock = $pdo->prepare("SELECT expire_at FROM bot_chat_locks WHERE page_id = ? AND sender_id = ? AND expire_at > NOW()");
    $stmt_lock->execute([$page_id, $sender_id]);
    $customer['is_locked'] = $stmt_lock->fetch() ? 1 : 0;
    
    // 1. Fetch recent comments from local page_notifications
    $comments = [];
    $stmt_cmts = $pdo->prepare("
        SELECT snippet, post_id, comment_id, created_at 
        FROM page_notifications 
        WHERE page_id = ? 
          AND (sender_id = ? OR (sender_name = ? AND sender_name IS NOT NULL AND sender_name != '')) 
          AND type = 'comment' 
        ORDER BY id DESC LIMIT 5
    ");
    $stmt_cmts->execute([$page_id, $sender_id, $customer['name'] ?? '']);
    $db_comments = $stmt_cmts->fetchAll(PDO::FETCH_ASSOC);
    foreach ($db_comments as $dc) {
        $comments[$dc['comment_id']] = [
            'snippet' => $dc['snippet'],
            'post_id' => $dc['post_id'],
            'comment_id' => $dc['comment_id'],
            'created_at' => $dc['created_at']
        ];
    }
    
    // 2. Fetch conversation_id and folder from fb_conversations
    $stmt_conv = $pdo->prepare("SELECT conversation_id, folder FROM fb_conversations WHERE page_id = ? AND sender_id = ?");
    $stmt_conv->execute([$page_id, $sender_id]);
    $conv_row = $stmt_conv->fetch(PDO::FETCH_ASSOC);
    $conv_id = $conv_row['conversation_id'] ?? '';
    $customer['folder'] = $conv_row['folder'] ?? 'inbox';
    
    if ($conv_id) {
        $stmt_token = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
        $stmt_token->execute([$page_id]);
        $page_token_raw = $stmt_token->fetchColumn();
        if ($page_token_raw) {
            require_once __DIR__ . '/../includes/fb_api.php';
            $page_token = decryptData($page_token_raw);
            if ($page_token) {
                // Fetch the oldest messages of the thread to find the system reply link
                $msg_res = fb_api_request("{$conv_id}/messages", [
                    'fields' => 'message,created_time',
                    'limit' => 20,
                    'access_token' => $page_token
                ], 'GET');
                
                if ($msg_res['status_code'] === 200 && !empty($msg_res['data']['data'])) {
                    foreach ($msg_res['data']['data'] as $msg) {
                        $msg_text = $msg['message'] ?? '';
                        if (strpos($msg_text, 'You are responding to a user comment') !== false) {
                            $post_id = null;
                            $comment_id = null;
                            if (preg_match('/post_id=([0-9a-zA-Z_]+)/', $msg_text, $match_post)) {
                                $post_id = $match_post[1];
                            }
                            if (preg_match('/comment_id=([0-9a-zA-Z_]+)/', $msg_text, $match_cmt)) {
                                $comment_id = $match_cmt[1];
                            }
                            if ($post_id && $comment_id) {
                                $comments[$comment_id] = [
                                    'snippet' => '[Rep từ bình luận bài viết]',
                                    'post_id' => $post_id,
                                    'comment_id' => $comment_id,
                                    'created_at' => date('Y-m-d H:i:s', strtotime($msg['created_time']))
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Sort and limit final merged array
    $final_comments = array_values($comments);
    usort($final_comments, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $customer['recent_comments'] = array_slice($final_comments, 0, 5);

    // Nếu khách chưa có SĐT trong hồ sơ chat, tự động bóc tách từ các bình luận cũ của họ
    if (empty($customer['phone'])) {
        foreach ($final_comments as $cmt) {
            $cmt_text = $cmt['snippet'] ?? '';
            if ($cmt_text && $cmt_text !== '[Rep từ bình luận bài viết]') {
                if (preg_match('/(03|05|07|08|09)+([0-9]{8})\b/', $cmt_text, $match_ph)) {
                    $found_phone = $match_ph[0];
                    $stmt_upd_ph = $pdo->prepare("UPDATE fb_customers SET phone = ? WHERE page_id = ? AND sender_id = ?");
                    $stmt_upd_ph->execute([$found_phone, $page_id, $sender_id]);
                    $customer['phone'] = $found_phone;
                    
                    // Tự động gắn nhãn cục bộ 'Đã cho số điện thoại'
                    $stmt_lbl = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
                    $stmt_lbl->execute([$conv_id ?: 'c_' . $sender_id, $page_id, $sender_id, 'Đã cho số điện thoại']);
                    break;
                }
            }
        }
    }
    
    echo json_encode(['status' => 'success', 'data' => $customer]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
