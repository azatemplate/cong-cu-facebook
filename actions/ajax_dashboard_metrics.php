<?php
// actions/ajax_dashboard_metrics.php
session_start();
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$period = 'days_28';

// Giải phóng session lock sớm — cho phép ajax_dashboard_db.php chạy song song
session_write_close();

$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-28 days'));

// Fast cache response
$cache_dir = __DIR__ . '/../uploads/cache';
if (!is_dir($cache_dir)) @mkdir($cache_dir, 0777, true);
$cache_file_key = $is_admin ? "admin_{$period}_fbmetrics" : "user_{$account_id}_{$period}_fbmetrics";
$cache_file_path = $cache_dir . "/dashboard_" . $cache_file_key . ".json";

if (file_exists($cache_file_path) && (time() - filemtime($cache_file_path)) < 7200) { // Cache 2 giờ
    if (!isset($_GET['force'])) {
        header('Content-Type: application/json');
        echo file_get_contents($cache_file_path);
        exit;
    }
}

// 1. Fetch Pages
if ($is_admin) {
    $stmt_all_pages = $pdo->query("SELECT page_id, access_token FROM pages");
    $all_user_pages = $stmt_all_pages->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt_all_pages = $pdo->prepare("
        SELECT p.page_id, p.access_token 
        FROM pages p JOIN users u ON p.user_id = u.id 
        WHERE u.account_id = :aid
        UNION
        SELECT p.page_id, p.access_token 
        FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id 
        WHERE ps.shared_with_account_id = :aid2
    ");
    $stmt_all_pages->execute(['aid' => $account_id, 'aid2' => $account_id]);
    $all_user_pages = $stmt_all_pages->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($all_user_pages as &$p_row) {
    $p_row['access_token'] = decryptData($p_row['access_token']);
}
unset($p_row);

$display_total_views = 0;
$display_total_reach = 0;
$checkpointed_pages = 0;
$error_pages = 0;

// Mã lỗi Facebook chỉ ra tài khoản bị checkpoint hoặc token không hợp lệ
$CHECKPOINT_ERROR_CODES = [190, 368, 2500, 467, 10902];
$CHECKPOINT_SUBCODES   = [459, 460, 461, 462, 463, 464, 492, 500];

$api_responses = get_fb_page_insights_multi($all_user_pages, $period, $start_date, $end_date);
foreach ($api_responses as $page_id => $api_response) {
    if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
        // Xử lý dữ liệu hợp lệ bình thường
        foreach ($api_response['data']['data'] as $metric) {
            if ($metric['name'] === 'page_media_view' || $metric['name'] === 'page_impressions_unique') {
                if (isset($metric['values']) && is_array($metric['values']) && count($metric['values']) > 0) {
                    $values_arr = $metric['values'];
                    $latest_value = end($values_arr);

                    $val = isset($latest_value['value']) ? intval($latest_value['value']) : 0;
                    if ($metric['name'] === 'page_media_view') {
                        $display_total_views += $val;
                    } else {
                        $display_total_reach += $val;
                    }
                }
            }
        }
    } elseif ($api_response['status_code'] !== 200) {
        // Bỏ qua page bị lỗi - phân loại theo loại lỗi
        $err     = $api_response['data']['error'] ?? [];
        $errCode = intval($err['code']      ?? 0);
        $errSub  = intval($err['error_subcode'] ?? 0);

        if (in_array($errCode, $CHECKPOINT_ERROR_CODES) || in_array($errSub, $CHECKPOINT_SUBCODES)) {
            // Token bị checkpoint hoặc hết hạn: đếm riêng và bỏ qua
            $checkpointed_pages++;
        } else {
            // Lỗi khác (rate limit, permission, ...): cũng bỏ qua
            $error_pages++;
        }
        // Tiếp tục xử lý các page khác - không dừng lại
        continue;
    }
}

// 2. Fetch Total Followers and Pages for Snapshot Update
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
$total_pages = $pages_data['total_pages'] ?: 0;
$total_followers = $pages_data['total_followers'] ?: 0;

// Update Dashboard Snapshots since we now have the reach & views
$snap_account_id = $is_admin ? 0 : $account_id;
$today_date = date('Y-m-d');
try {
    $pdo->prepare("
        INSERT INTO dashboard_snapshots (account_id, snapshot_date, total_followers, total_reach, total_views, total_pages)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            total_followers = VALUES(total_followers),
            total_reach = VALUES(total_reach),
            total_views = VALUES(total_views),
            total_pages = VALUES(total_pages)
    ")->execute([$snap_account_id, $today_date, $total_followers, $display_total_reach, $display_total_views, $total_pages]);
} catch (Exception $e) { /* ignore */ }

// 3. Fetch yesterday's snapshot to compute percentage diff
$yesterday_date = date('Y-m-d', strtotime('-1 days'));
$yest_reach = 0;
$yest_views = 0;
try {
    $stmt_yest = $pdo->prepare("SELECT total_reach, total_views FROM dashboard_snapshots WHERE account_id = ? AND snapshot_date = ?");
    $stmt_yest->execute([$snap_account_id, $yesterday_date]);
    $yest_data = $stmt_yest->fetch(PDO::FETCH_ASSOC);
    if ($yest_data) {
        $yest_reach = intval($yest_data['total_reach']);
        $yest_views = intval($yest_data['total_views']);
    }
} catch (Exception $e) { /* ignore */ }

$reach_diff_pct = 0;
if ($yest_reach > 0) {
    $reach_diff_pct = round((($display_total_reach - $yest_reach) / $yest_reach) * 100, 1);
} else if ($display_total_reach > 0) {
    $reach_diff_pct = 100;
}

$views_diff_pct = 0;
if ($yest_views > 0) {
    $views_diff_pct = round((($display_total_views - $yest_views) / $yest_views) * 100, 1);
} else if ($display_total_views > 0) {
    $views_diff_pct = 100;
}

$reach_diff_html = $reach_diff_pct >= 0 
    ? '<span style="color: #16a34a; font-size: 14px; margin-left:10px; font-weight: 500;">&uarr; ' . $reach_diff_pct . '%</span>'
    : '<span style="color: #ef4444; font-size: 14px; margin-left:10px; font-weight: 500;">&darr; ' . abs($reach_diff_pct) . '%</span>';

$views_diff_html = $views_diff_pct >= 0 
    ? '<span style="color: #16a34a; font-size: 14px; margin-left:10px; font-weight: 500;">&uarr; ' . $views_diff_pct . '%</span>'
    : '<span style="color: #ef4444; font-size: 14px; margin-left:10px; font-weight: 500;">&darr; ' . abs($views_diff_pct) . '%</span>';

// Return JSON Output
$resp = [
    'reach'               => $display_total_reach,
    'views'               => $display_total_views,
    'reach_formatted'     => number_format($display_total_reach),
    'views_formatted'     => number_format($display_total_views),
    'reach_diff_html'     => $reach_diff_html,
    'views_diff_html'     => $views_diff_html,
    'checkpointed_pages'  => $checkpointed_pages,  // Số page bị checkpoint/token lỗi
    'error_pages'         => $error_pages,          // Số page bị lỗi khác
];
file_put_contents($cache_file_path, json_encode($resp));

header('Content-Type: application/json');
echo json_encode($resp);
