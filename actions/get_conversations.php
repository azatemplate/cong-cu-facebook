<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $merge_all = isset($_GET['merge_all']) ? intval($_GET['merge_all']) : 0;
    
    // Determine the offset
    // Translate 'after' parameter to offset integer if it's numeric
    $offset = 0;
    if (isset($_GET['after']) && is_numeric($_GET['after'])) {
        $offset = intval($_GET['after']);
    } elseif (isset($_GET['offset']) && is_numeric($_GET['offset'])) {
        $offset = intval($_GET['offset']);
    }

    $limit = 20;
    $rows = [];

    $search = trim($_GET['search'] ?? '');
    $search_param = '%' . $search . '%';

    if ($merge_all === 1) {
        if (!isset($_SESSION['account_id'])) {
            echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
            exit;
        }
        $account_id = $_SESSION['account_id'];
        session_write_close();

        // Query conversations for all pages belonging to/shared with this account
        $sql = "
            SELECT 
                c.conversation_id, 
                c.page_id, 
                c.sender_id, 
                c.sender_name, 
                c.snippet, 
                c.unread_count, 
                c.updated_time,
                p.name AS page_name,
                p.user_id,
                cust.is_ads,
                cust.ad_title,
                cust.ad_photo_url
            FROM fb_conversations c
            JOIN pages p ON c.page_id = p.page_id
            JOIN users u ON p.user_id = u.id
            LEFT JOIN fb_customers cust ON c.page_id = cust.page_id AND c.sender_id = cust.sender_id
            WHERE (u.account_id = :aid OR p.page_id IN (SELECT page_id FROM page_shares WHERE shared_with_account_id = :aid2))
        ";
        if ($search !== '') {
            $sql .= " AND (c.sender_name LIKE :search OR c.snippet LIKE :search OR c.sender_id LIKE :search OR cust.phone LIKE :search2)";
        }
        $sql .= " ORDER BY c.updated_time DESC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
        $stmt->bindValue(':aid2', $account_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($search !== '') {
            $stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
            $stmt->bindValue(':search2', $search_param, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $page_id = isset($_GET['page_id']) ? $_GET['page_id'] : '';
        
        if (!$user_id || empty($page_id)) {
            echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn đầy đủ User và Fanpage.']);
            exit;
        }
        session_write_close();

        // Query conversations for this specific page
        $sql = "
            SELECT 
                c.conversation_id, 
                c.page_id, 
                c.sender_id, 
                c.sender_name, 
                c.snippet, 
                c.unread_count, 
                c.updated_time,
                p.name AS page_name,
                p.user_id,
                cust.is_ads,
                cust.ad_title,
                cust.ad_photo_url
            FROM fb_conversations c
            JOIN pages p ON c.page_id = p.page_id
            LEFT JOIN fb_customers cust ON c.page_id = cust.page_id AND c.sender_id = cust.sender_id
            WHERE c.page_id = :page_id AND p.user_id = :user_id
        ";
        if ($search !== '') {
            $sql .= " AND (c.sender_name LIKE :search OR c.snippet LIKE :search OR c.sender_id LIKE :search OR cust.phone LIKE :search2)";
        }
        $sql .= " ORDER BY c.updated_time DESC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':page_id', $page_id, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($search !== '') {
            $stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
            $stmt->bindValue(':search2', $search_param, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Format rows to look exactly like Facebook Graph API response object
    $formatted = [];
    foreach ($rows as $row) {
        $formatted[] = [
            'id' => $row['conversation_id'],
            'updated_time' => date('Y-m-d\TH:i:sP', strtotime($row['updated_time'])),
            'unread_count' => (int)$row['unread_count'],
            'participants' => [
                'data' => [
                    [
                        'id' => $row['sender_id'],
                        'name' => $row['sender_name']
                    ],
                    [
                        'id' => $row['page_id'],
                        'name' => $row['page_name']
                    ]
                ]
            ],
            'messages' => [
                'data' => [
                    [
                        'message' => $row['snippet']
                    ]
                ]
            ],
            '_page_id' => $row['page_id'],
            '_user_id' => (int)$row['user_id'],
            '_page_name' => $row['page_name'],
            'is_ads' => (int)($row['is_ads'] ?? 0),
            'ad_title' => $row['ad_title'] ?? null,
            'ad_photo_url' => $row['ad_photo_url'] ?? null
        ];
    }

    // Attach phone number info from fb_customers table
    attach_phone_status_to_conversations($formatted, $pdo, $merge_all === 1 ? '' : $page_id);

    // Calculate the next cursor (offset + count of fetched rows)
    $next_cursor = '';
    if (count($rows) === $limit) {
        $next_cursor = (string)($offset + $limit);
    }

    echo json_encode([
        'status' => 'success',
        'data' => $formatted,
        'next_cursor' => $next_cursor
    ]);
    exit;
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
}

/**
 * Đính kèm thông tin và trạng thái số điện thoại từ DB cho danh sách cuộc hội thoại
 */
function attach_phone_status_to_conversations(&$conversations, $pdo, $currentPageId = '') {
    if (empty($conversations)) return;
    
    $sender_ids = [];
    foreach ($conversations as $c) {
        $page_id = isset($c['_page_id']) ? $c['_page_id'] : $currentPageId;
        if (isset($c['participants']['data'])) {
            foreach ($c['participants']['data'] as $p) {
                if ($p['id'] !== $page_id) {
                    $sender_ids[] = $p['id'];
                }
            }
        }
    }
    
    if (empty($sender_ids)) return;
    
    // Loại bỏ các ID trùng lặp
    $sender_ids = array_unique($sender_ids);
    
    // Tạo placeholders cho câu SQL IN
    $placeholders = implode(',', array_fill(0, count($sender_ids), '?'));
    $sql = "SELECT page_id, sender_id, phone FROM fb_customers WHERE phone IS NOT NULL AND phone != '' AND sender_id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($sender_ids));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $has_phone_map = [];
    foreach ($rows as $row) {
        $key = $row['page_id'] . '_' . $row['sender_id'];
        $has_phone_map[$key] = $row['phone'];
    }
    
    // Gán lại cho conversations
    foreach ($conversations as &$c) {
        $page_id = isset($c['_page_id']) ? $c['_page_id'] : $currentPageId;
        $c['has_phone'] = false;
        $c['phone_number'] = '';
        if (isset($c['participants']['data'])) {
            foreach ($c['participants']['data'] as $p) {
                if ($p['id'] !== $page_id) {
                    $key = $page_id . '_' . $p['id'];
                    if (isset($has_phone_map[$key])) {
                        $c['has_phone'] = true;
                        $c['phone_number'] = $has_phone_map[$key];
                    }
                }
            }
        }
    }
}
?>
