<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['account_id'];
$is_admin   = ($_SESSION['role'] ?? '') === 'admin';
$offset     = max(0, intval($_GET['offset'] ?? 0));
$limit      = 20; // số thông báo mỗi lần tải
session_write_close(); // Giải phóng session lock sớm

// 1. Fetch Post Errors (offset = 0) - Chỉ lấy bài lỗi của tài khoản hiện tại (kể cả admin)
$failed_posts = [];
if ($offset === 0) {
    try {
        $stmt1 = $pdo->prepare("
            SELECT sp.id, sp.error_msg, sp.scheduled_time, 
                   COALESCE(p.name, 'Trang / Kênh') as page_name, 
                   sp.page_id, sp.campaign_id
            FROM scheduled_posts sp
            LEFT JOIN pages p ON sp.page_id = p.page_id
            WHERE sp.status = 'failed' AND (sp.is_read = 0 OR sp.is_read IS NULL) AND sp.account_id = ?
            ORDER BY sp.scheduled_time DESC
            LIMIT 10
        ");
        $stmt1->execute([$account_id]);
        $failed_posts = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// 2. Thu thập danh sách page_id + tên trang thuộc sở hữu/quyền hạn của tài khoản hiện tại
$page_names_map = [];
$my_page_ids = ['SYSTEM_ACCOUNT_' . $account_id];

// Pages Facebook
try {
    $st = $pdo->prepare("SELECT p.page_id, p.name FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = ?");
    $st->execute([$account_id]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $my_page_ids[] = $r['page_id'];
        $page_names_map[$r['page_id']] = $r['name'];
    }
} catch (Exception $e) {}

// Page Shares
try {
    $st = $pdo->prepare("SELECT page_id FROM page_shares WHERE shared_with_account_id = ?");
    $st->execute([$account_id]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $my_page_ids[] = $r['page_id'];
    }
} catch (Exception $e) {}

// Zalo OAs
try {
    $st = $pdo->prepare("SELECT oa_id, name FROM zalo_oas WHERE account_id = ?");
    $st->execute([$account_id]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $my_page_ids[] = $r['oa_id'];
        $page_names_map[$r['oa_id']] = $r['name'];
    }
} catch (Exception $e) {}

// Instagram
try {
    $st = $pdo->prepare("SELECT ig_user_id, username FROM instagram_accounts WHERE account_id = ?");
    $st->execute([$account_id]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $my_page_ids[] = $r['ig_user_id'];
        $page_names_map[$r['ig_user_id']] = $r['username'];
    }
} catch (Exception $e) {}

// TikTok
try {
    $st = $pdo->prepare("SELECT open_id, display_name FROM tiktok_accounts WHERE account_id = ?");
    $st->execute([$account_id]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $my_page_ids[] = $r['open_id'];
        $page_names_map[$r['open_id']] = $r['display_name'];
    }
} catch (Exception $e) {}

// Scraper pages
try {
    $st = $pdo->prepare("SELECT page_id, page_name FROM scraper_pages WHERE account_id = ?");
    $st->execute([$account_id]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $my_page_ids[] = $r['page_id'];
        $page_names_map[$r['page_id']] = $r['page_name'];
    }
} catch (Exception $e) {}

// Lấy thêm tên cho các shared pages nếu chưa có
if (!empty($my_page_ids)) {
    try {
        $in_p = implode(',', array_fill(0, count($my_page_ids), '?'));
        $st_names = $pdo->prepare("SELECT page_id, name FROM pages WHERE page_id IN ($in_p)");
        $st_names->execute(array_values($my_page_ids));
        while ($r = $st_names->fetch(PDO::FETCH_ASSOC)) {
            if (empty($page_names_map[$r['page_id']])) {
                $page_names_map[$r['page_id']] = $r['name'];
            }
        }
    } catch (Exception $e) {}
}

$my_page_ids = array_values(array_unique(array_filter($my_page_ids)));

// 3. Fetch Live Notifications - Chỉ lấy thông báo thuộc các page / hệ thống của tài khoản hiện tại
$live_notifs = [];
$has_more    = false;
try {
    $tab = $_GET['tab'] ?? 'all';
    $read_condition = "";
    if ($tab === 'unread') {
        $read_condition = "AND (is_read = 0 OR is_read IS NULL)";
    } elseif ($tab === 'read') {
        $read_condition = "AND is_read = 1";
    }

    $fetch_limit = $limit + 1;

    $in_clause = implode(',', array_fill(0, count($my_page_ids), '?'));
    $sql = "SELECT * FROM page_notifications 
            WHERE page_id IN ($in_clause) {$read_condition} AND type != 'auto_replied'
            ORDER BY created_at DESC 
            LIMIT {$fetch_limit} OFFSET {$offset}";
    $stmt2 = $pdo->prepare($sql);
    $params = array_values($my_page_ids);
    $stmt2->execute($params);

    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > $limit) {
        $has_more = true;
        array_pop($rows);
    }

    foreach ($rows as &$row) {
        if (!empty($page_names_map[$row['page_id']])) {
            $row['page_name'] = $page_names_map[$row['page_id']];
        } else {
            $row['page_name'] = 'Thông báo Hệ thống';
        }
        $row['platform'] = (strpos($row['page_id'], 'SYSTEM_') === 0) ? 'system' : 'facebook';
    }
    unset($row);

    $live_notifs = $rows;
} catch (Exception $e) {
    @file_put_contents(__DIR__ . '/../notif_error.txt', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
}

echo json_encode([
    'status'       => 'success',
    'failed_posts' => $failed_posts,
    'live_notifs'  => $live_notifs,
    'has_more'     => $has_more,
    'offset'       => $offset,
]);
