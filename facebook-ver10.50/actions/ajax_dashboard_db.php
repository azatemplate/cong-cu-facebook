<?php
// actions/ajax_dashboard_db.php
// Lightweight DB-only queries — cached in Redis (30s TTL) & indexed range queries (<1ms)
session_start();
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/redis_queue.php';

header('Content-Type: application/json');

// Giải phóng session sớm để không lock session cho ajax_dashboard_metrics.php chạy song song
$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');
session_write_close();

// Check Redis Cache (30 seconds TTL) for instant load <1ms
$cache_key = "dashboard:overview:" . ($is_admin ? "admin" : $account_id);
$rq = RedisQueue::getInstance();
$cached_data = $rq->getCache($cache_key);

if ($cached_data !== false && is_array($cached_data)) {
    echo json_encode($cached_data);
    exit;
}

$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$yesterday_start = date('Y-m-d 00:00:00', strtotime('-1 days'));
$yesterday_end = date('Y-m-d 23:59:59', strtotime('-1 days'));

// 1. Total Users (Connected Facebook Accounts)
if ($is_admin) {
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_users FROM users WHERE account_id = ?");
    $stmt->execute([$account_id]);
}
$total_users = (int)$stmt->fetchColumn();

// 2. Total Pages + Followers (Owned and Shared Pages)
if ($is_admin) {
    $stmt2 = $pdo->query("SELECT COUNT(id) as total_pages, SUM(followers_count) as total_followers FROM pages");
} else {
    $stmt2 = $pdo->prepare("
        SELECT COUNT(DISTINCT combined.id) as total_pages, SUM(combined.followers_count) as total_followers
        FROM (
            SELECT p.id, p.followers_count FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = :aid
            UNION
            SELECT p.id, p.followers_count FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id WHERE ps.shared_with_account_id = :aid2
        ) as combined
    ");
    $stmt2->execute(['aid' => $account_id, 'aid2' => $account_id]);
}
$pages_data = $stmt2->fetch(PDO::FETCH_ASSOC);
$total_pages = (int)($pages_data['total_pages'] ?? 0);
$total_followers = (int)($pages_data['total_followers'] ?? 0);

// 3. Yesterday's snapshots comparisons (Filtered by logged-in account_id)
$snap_account_id = $is_admin ? 0 : $account_id;
$yesterday_date = date('Y-m-d', strtotime('-1 days'));

$yest_followers = 0;
$yest_pages = 0;
$yest_accounts = 0;
$yest_reels = 0;
$yest_posts = 0;

try {
    $stmt_yest = $pdo->prepare("SELECT total_followers, total_pages, total_accounts, total_reels, total_posts FROM dashboard_snapshots WHERE account_id = ? AND snapshot_date = ?");
    $stmt_yest->execute([$snap_account_id, $yesterday_date]);
    $yest_row = $stmt_yest->fetch(PDO::FETCH_ASSOC);
    if ($yest_row) {
        $yest_followers = intval($yest_row['total_followers'] ?? 0);
        $yest_pages = intval($yest_row['total_pages'] ?? 0);
        $yest_accounts = intval($yest_row['total_accounts'] ?? 0);
        $yest_reels = intval($yest_row['total_reels'] ?? 0);
        $yest_posts = intval($yest_row['total_posts'] ?? 0);
    }
} catch (Exception $e) {}

// Fallback dynamic queries with INDEXED range queries (avoid full table scan)
try {
    if ($yest_accounts <= 0) {
        if ($is_admin) {
            $stmt_yest_acc = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at < ?");
            $stmt_yest_acc->execute([$today_start]);
        } else {
            $stmt_yest_acc = $pdo->prepare("SELECT COUNT(*) FROM users WHERE account_id = ? AND created_at < ?");
            $stmt_yest_acc->execute([$account_id, $today_start]);
        }
        $yest_accounts = intval($stmt_yest_acc->fetchColumn() ?: 0);
    }
    
    if ($yest_reels <= 0) {
        if ($is_admin) {
            $stmt_yest_reels = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE post_type = 'Reel' AND scheduled_time >= ? AND scheduled_time <= ?");
            $stmt_yest_reels->execute([$yesterday_start, $yesterday_end]);
        } else {
            $stmt_yest_reels = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE account_id = ? AND post_type = 'Reel' AND scheduled_time >= ? AND scheduled_time <= ?");
            $stmt_yest_reels->execute([$account_id, $yesterday_start, $yesterday_end]);
        }
        $yest_reels = intval($stmt_yest_reels->fetchColumn() ?: 0);
    }
    
    if ($yest_posts <= 0) {
        if ($is_admin) {
            $stmt_yest_posts = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE status = 'published' AND scheduled_time >= ? AND scheduled_time <= ?");
            $stmt_yest_posts->execute([$yesterday_start, $yesterday_end]);
        } else {
            $stmt_yest_posts = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE account_id = ? AND status = 'published' AND scheduled_time >= ? AND scheduled_time <= ?");
            $stmt_yest_posts->execute([$account_id, $yesterday_start, $yesterday_end]);
        }
        $yest_posts = intval($stmt_yest_posts->fetchColumn() ?: 0);
    }
} catch (Exception $e) {}

function get_diff_html($current, $yesterday) {
    if ($yesterday <= 0) {
        return '';
    }
    $diff_pct = round((($current - $yesterday) / $yesterday) * 100, 1);
    return $diff_pct >= 0
        ? '<span style="color: #16a34a; font-size: 14px; margin-left:10px; font-weight: 500;">&uarr; ' . $diff_pct . '%</span>'
        : '<span style="color: #ef4444; font-size: 14px; margin-left:10px; font-weight: 500;">&darr; ' . abs($diff_pct) . '%</span>';
}

$followers_diff_html = get_diff_html($total_followers, $yest_followers);
$users_diff_html = get_diff_html($total_users, $yest_accounts);
$pages_diff_html = get_diff_html($total_pages, $yest_pages);

// 4. Reels today (INDEXED RANGE QUERY — <1ms using B-Tree index)
if ($is_admin) {
    $stmt_reels = $pdo->prepare("SELECT COUNT(id) as total_reels, SUM(IF(status = 'failed', 1, 0)) as failed_reels FROM scheduled_posts WHERE post_type = 'Reel' AND scheduled_time >= ? AND scheduled_time <= ?");
    $stmt_reels->execute([$today_start, $today_end]);
} else {
    $stmt_reels = $pdo->prepare("SELECT COUNT(id) as total_reels, SUM(IF(status = 'failed', 1, 0)) as failed_reels FROM scheduled_posts WHERE account_id = ? AND post_type = 'Reel' AND scheduled_time >= ? AND scheduled_time <= ?");
    $stmt_reels->execute([$account_id, $today_start, $today_end]);
}
$reels_data = $stmt_reels->fetch(PDO::FETCH_ASSOC);
$reels_diff_html = get_diff_html($reels_data['total_reels'] ?: 0, $yest_reels);

// 5. Total Posts Today and Page Limit (INDEXED RANGE QUERY — <1ms using B-Tree index)
if ($is_admin) {
    $stmt_posts = $pdo->prepare("SELECT COUNT(id) as total_posts FROM scheduled_posts WHERE status = 'published' AND scheduled_time >= ? AND scheduled_time <= ?");
    $stmt_posts->execute([$today_start, $today_end]);
    $total_posts_today = (int)($stmt_posts->fetchColumn() ?: 0);
    $page_limit = 0;
} else {
    $stmt_posts = $pdo->prepare("SELECT COUNT(id) as total_posts FROM scheduled_posts WHERE account_id = ? AND status = 'published' AND scheduled_time >= ? AND scheduled_time <= ?");
    $stmt_posts->execute([$account_id, $today_start, $today_end]);
    $total_posts_today = (int)($stmt_posts->fetchColumn() ?: 0);
    
    $stmt_limit = $pdo->prepare("SELECT page_limit FROM system_accounts WHERE id = ?");
    $stmt_limit->execute([$account_id]);
    $page_limit = (int)$stmt_limit->fetchColumn();
}
$posts_diff_html = get_diff_html($total_posts_today, $yest_posts);

$result = [
    'total_users' => $total_users,
    'total_pages' => $total_pages,
    'total_followers' => $total_followers,
    'followers_diff_html' => $followers_diff_html,
    'users_diff_html' => $users_diff_html,
    'pages_diff_html' => $pages_diff_html,
    'total_reels_today' => (int)($reels_data['total_reels'] ?? 0),
    'failed_reels_today' => (int)($reels_data['failed_reels'] ?? 0),
    'reels_diff_html' => $reels_diff_html,
    'total_posts_today' => $total_posts_today,
    'page_limit' => $page_limit,
    'posts_diff_html' => $posts_diff_html
];

// Save to Redis Cache (30s TTL)
$rq->setCache($cache_key, 30, $result);

echo json_encode($result);
