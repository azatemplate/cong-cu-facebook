<?php
session_start();
@set_time_limit(120);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}
$account_id = $_SESSION['account_id'];
session_write_close();

$page_id = $_GET['page_id'] ?? '';
$user_id = (int)($_GET['user_id'] ?? 0);
$after = $_GET['after'] ?? '';

if (!$page_id || !$user_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Missing parameters page_id or user_id']);
    exit;
}

// Fetch page credentials
$stmt = $pdo->prepare("
    SELECT p.page_id, p.name, p.user_id, p.access_token 
    FROM pages p
    JOIN users u ON p.user_id = u.id
    WHERE p.page_id = ? AND p.user_id = ? AND (u.account_id = ? OR EXISTS (
        SELECT 1 FROM page_shares ps 
        WHERE ps.page_id = p.page_id 
          AND ps.shared_with_account_id = ?
    ))
");
$stmt->execute([$page_id, $user_id, $account_id, $account_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    echo json_encode(['status' => 'error', 'msg' => 'Page not found or access denied']);
    exit;
}

if (empty($page['access_token'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Page access token is empty']);
    exit;
}

$page_access_token = decryptData($page['access_token']);
$pid = $page['page_id'];

$sync_count = 0;
$batch_iteration = 0;
$next_cursor = null;

// Lấy tối đa 5 trang x 50 cuộc hội thoại = 250 cuộc hội thoại trên mỗi request để cực kỳ an toàn tránh timeout
while ($batch_iteration < 5) {
    $batch_iteration++;

    $params = [
        'fields' => 'id,updated_time,unread_count,tags{name},participants{id,name,email,custom_labels},messages.limit(1){message,from}',
        'access_token' => $page_access_token,
        'limit' => 50
    ];
    if ($after) {
        $params['after'] = $after;
    }

    $response = fb_api_request($pid . '/conversations', $params, 'GET');

    if ($response['status_code'] !== 200 || empty($response['data']['data'])) {
        if ($response['status_code'] !== 200) {
            $fb_error = $response['data']['error']['message'] ?? 'Lỗi không xác định từ Facebook API';
            echo json_encode([
                'status' => 'error', 
                'msg' => 'Facebook API Error (Code ' . $response['status_code'] . '): ' . $fb_error
            ]);
            exit;
        }
        break;
    }

    $conversations = $response['data']['data'];

    foreach ($conversations as $c) {
        $conv_id = $c['id'];
        $updated_time = date('Y-m-d H:i:s', strtotime($c['updated_time']));
        $unread_count = (int)($c['unread_count'] ?? 0);

        // Find sender
        $sender_id = '';
        $sender_name = 'Khách hàng';
        if (isset($c['participants']['data'])) {
            foreach ($c['participants']['data'] as $p) {
                if ($p['id'] !== $pid) {
                    $sender_id = $p['id'];
                    $sender_name = $p['name'] ?? 'Khách hàng';
                    break;
                }
            }
        }

        if (empty($sender_id)) continue;

        $snippet = '';
        if (!empty($c['messages']['data'])) {
            $snippet = $c['messages']['data'][0]['message'] ?? '';
        }

        // Sync to fb_conversations
        $stmt_ins = $pdo->prepare("
            INSERT INTO fb_conversations (page_id, sender_id, sender_name, snippet, unread_count, updated_time, conversation_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                sender_name = VALUES(sender_name),
                snippet = IF(VALUES(updated_time) >= updated_time, VALUES(snippet), snippet),
                unread_count = VALUES(unread_count),
                updated_time = IF(VALUES(updated_time) >= updated_time, VALUES(updated_time), updated_time),
                conversation_id = VALUES(conversation_id)
        ");
        $stmt_ins->execute([$pid, $sender_id, $sender_name, $snippet, $unread_count, $updated_time, $conv_id]);

        // Sync to fb_customers
        try {
            // Trích xuất số điện thoại trực tiếp từ tin nhắn mới nhất (snippet) - Hoàn toàn không mất thêm API call
            $phone = extract_phone_number($snippet);

            $stmt_cust = $pdo->prepare("
                INSERT INTO fb_customers (page_id, sender_id, name, phone, last_message_at)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    phone = IF(VALUES(phone) IS NOT NULL AND VALUES(phone) != '', VALUES(phone), phone)
            ");
            $stmt_cust->execute([$pid, $sender_id, $sender_name, $phone ?: null, $updated_time]);

            if ($phone) {
                // Đánh dấu nhãn cục bộ
                $lbl_stmt = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
                $lbl_stmt->execute([$conv_id, $pid, $sender_id]);
            }
        } catch (Exception $e) {}

        $sync_count++;
    }

    if (isset($response['data']['paging']['cursors']['after'])) {
        $after = $response['data']['paging']['cursors']['after'];
        $next_cursor = $after;
    } else {
        $next_cursor = null;
        break;
    }
}

echo json_encode([
    'status' => 'success',
    'synced' => $sync_count,
    'next_cursor' => $next_cursor
]);
exit;

/**
 * Trích xuất số điện thoại Việt Nam từ văn bản
 */
function extract_phone_number($text) {
    if (empty($text)) return null;
    $clean = preg_replace('/[\s.\-_]+/', '', $text);
    if (preg_match('/(?:\+84|84|0)(3|5|7|8|9)\d{8}\b/', $clean, $matches)) {
        $phone = $matches[0];
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
