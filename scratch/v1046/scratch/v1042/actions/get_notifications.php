<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['account_id'];
$offset      = max(0, intval($_GET['offset'] ?? 0));
$limit       = 20; // số thông báo mỗi lần tải
session_write_close(); // Giải phóng session lock sớm để các request khác không bị block

// 2. Fetch Post Errors — chỉ lần đầu (offset = 0)
$failed_posts = [];
if ($offset === 0) {
    try {
        $stmt1 = $pdo->prepare("
            SELECT sp.id, sp.error_msg, sp.scheduled_time, p.name as page_name, p.page_id, sp.campaign_id
            FROM scheduled_posts sp
            JOIN pages p ON sp.page_id = p.page_id
            JOIN users u ON p.user_id = u.id
            WHERE sp.status = 'failed' AND sp.is_read = 0 AND u.account_id = ?
            ORDER BY sp.scheduled_time DESC
            LIMIT 10
        ");
        $stmt1->execute([$account_id]);
        $failed_posts = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// 3. Fetch Live Notifications với phân trang
$live_notifs = [];
$has_more    = false;
try {
    // Determine filter
    $tab = $_GET['tab'] ?? 'unread'; // default unread for badge consistency, but frontend will control this
    $read_condition = "";
    if ($tab === 'unread') {
        $read_condition = "AND n.is_read = 0";
    } elseif ($tab === 'read') {
        $read_condition = "AND n.is_read = 1";
    }

    // Fetch limit+1 để biết còn thêm hay không
    $fetch_limit = $limit + 1;
    $sys_page_id = 'SYSTEM_ACCOUNT_' . $account_id;
    $stmt2 = $pdo->prepare("
        SELECT n.*, 
               COALESCE(p.name, zo.name, 'Thông báo Hệ thống') as page_name,
               CASE WHEN zo.oa_id IS NOT NULL THEN 'zalo' ELSE 'facebook' END as platform
        FROM page_notifications n
        LEFT JOIN pages p ON n.page_id = p.page_id
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN zalo_oas zo ON n.page_id = zo.oa_id
        WHERE (
            u.account_id = ?
            OR EXISTS (
                SELECT 1 FROM page_shares ps
                WHERE ps.page_id = p.page_id AND ps.shared_with_account_id = ?
            )
            OR zo.account_id = ?
            OR n.page_id = ?
        ) {$read_condition} AND n.type != 'auto_replied'
        ORDER BY n.created_at DESC
        LIMIT {$fetch_limit} OFFSET {$offset}
    ");
    $stmt2->execute([$account_id, $account_id, $account_id, $sys_page_id]);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > $limit) {
        $has_more = true;
        array_pop($rows); // bỏ record thứ 21 — chỉ dùng để kiểm tra has_more
    }
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
