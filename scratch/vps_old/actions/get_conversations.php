<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// Auto migration: ensure folder column exists in fb_conversations
try {
    $col = $pdo->query("SHOW COLUMNS FROM fb_conversations LIKE 'folder'");
    if ($col && $col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE fb_conversations ADD COLUMN folder VARCHAR(20) DEFAULT 'inbox'");
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $merge_all = isset($_GET['merge_all']) ? intval($_GET['merge_all']) : 0;
    
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
    $search_clean = '%' . preg_replace('/[\s\.\-\(\)]/', '', $search) . '%';

    $filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'all';

    // Check if folder column exists
    $has_folder_col = false;
    try {
        $chk_f = $pdo->query("SHOW COLUMNS FROM fb_conversations LIKE 'folder'");
        $has_folder_col = ($chk_f && $chk_f->rowCount() > 0);
    } catch (Exception $e) {}

    $folder_select = $has_folder_col ? "c.folder," : "'inbox' AS folder,";
    $folder_where = "";
    if ($has_folder_col) {
        $folder_where = ($filter === 'spam') ? " AND c.folder = 'spam'" : " AND (c.folder IS NULL OR c.folder != 'spam')";
    } elseif ($filter === 'spam') {
        $folder_where = " AND 1=0";
    }

    try {
        if ($merge_all === 1) {
            if (!isset($_SESSION['account_id'])) {
                echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
                exit;
            }
            $account_id = $_SESSION['account_id'];
            session_write_close();

            $sql = "
                SELECT 
                    c.conversation_id, 
                    c.page_id, 
                    c.sender_id, 
                    COALESCE(NULLIF(cust.name, ''), c.sender_name) AS sender_name, 
                    c.snippet, 
                    c.unread_count, 
                    c.updated_time,
                    {$folder_select}
                    p.name AS page_name,
                    p.user_id,
                    cust.name AS cust_name,
                    cust.is_ads,
                    cust.ad_title,
                    cust.ad_photo_url,
                    cust.consulted
                FROM fb_conversations c
                JOIN pages p ON c.page_id = p.page_id
                JOIN users u ON p.user_id = u.id
                LEFT JOIN fb_customers cust ON c.page_id = cust.page_id AND c.sender_id = cust.sender_id
                WHERE (u.account_id = :aid OR p.page_id IN (SELECT page_id FROM page_shares WHERE shared_with_account_id = :aid2))
                {$folder_where}
            ";
            if ($search !== '') {
                $sql .= " AND (c.sender_name LIKE :search OR cust.name LIKE :search OR c.snippet LIKE :search OR c.sender_id LIKE :search OR cust.phone LIKE :search2 OR REPLACE(REPLACE(REPLACE(cust.phone, ' ', ''), '.', ''), '-', '') LIKE :search_clean)";
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
                $stmt->bindValue(':search_clean', $search_clean, PDO::PARAM_STR);
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

            $sql = "
                SELECT 
                    c.conversation_id, 
                    c.page_id, 
                    c.sender_id, 
                    COALESCE(NULLIF(cust.name, ''), c.sender_name) AS sender_name, 
                    c.snippet, 
                    c.unread_count, 
                    c.updated_time,
                    {$folder_select}
                    p.name AS page_name,
                    p.user_id,
                    cust.name AS cust_name,
                    cust.is_ads,
                    cust.ad_title,
                    cust.ad_photo_url,
                    cust.consulted
                FROM fb_conversations c
                JOIN pages p ON c.page_id = p.page_id
                LEFT JOIN fb_customers cust ON c.page_id = cust.page_id AND c.sender_id = cust.sender_id
                WHERE c.page_id = :page_id AND p.user_id = :user_id
                {$folder_where}
            ";
            if ($search !== '') {
                $sql .= " AND (c.sender_name LIKE :search OR cust.name LIKE :search OR c.snippet LIKE :search OR c.sender_id LIKE :search OR cust.phone LIKE :search2 OR REPLACE(REPLACE(REPLACE(cust.phone, ' ', ''), '.', ''), '-', '') LIKE :search_clean)";
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
                $stmt->bindValue(':search_clean', $search_clean, PDO::PARAM_STR);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi CSDL: ' . $e->getMessage()]);
        exit;
    }

    $formatted = [];
    foreach ($rows as $row) {
        $formatted[] = [
            'id' => $row['conversation_id'],
            'updated_time' => date('Y-m-d\TH:i:sP', strtotime($row['updated_time'])),
            'unread_count' => (int)$row['unread_count'],
            'folder' => $row['folder'] ?? 'inbox',
            'cust_name' => $row['cust_name'] ?? '',
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
            'ad_photo_url' => $row['ad_photo_url'] ?? null,
            'consulted' => (int)($row['consulted'] ?? 0)
        ];
    }

    attach_phone_status_to_conversations($formatted, $pdo, $merge_all === 1 ? '' : ($page_id ?? ''));

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
    
    $sender_ids = array_unique($sender_ids);
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
