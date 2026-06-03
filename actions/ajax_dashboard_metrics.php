<?php
// actions/ajax_dashboard_metrics.php
session_start();
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Giải phóng session lock sớm — cho phép ajax_dashboard_db.php chạy song song
session_write_close();

$snap_account_id = $is_admin ? 0 : $account_id;

$display_total_reach = 0;
$display_total_views = 0;
$yest_reach = 0;
$yest_views = 0;

try {
    // Lấy dữ liệu snapshot của ngày mới nhất và ngày trước đó
    $stmt = $pdo->prepare("SELECT snapshot_date, total_reach, total_views FROM dashboard_snapshots WHERE account_id = ? ORDER BY snapshot_date DESC LIMIT 2");
    $stmt->execute([$snap_account_id]);
    $snaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($snaps) > 0) {
        $display_total_reach = intval($snaps[0]['total_reach']);
        $display_total_views = intval($snaps[0]['total_views']);
    }
    if (count($snaps) > 1) {
        $yest_reach = intval($snaps[1]['total_reach']);
        $yest_views = intval($snaps[1]['total_views']);
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
    'checkpointed_pages'  => 0,  // Đã bỏ quét live nên không check lỗi token ở đây nữa
    'error_pages'         => 0,
];

header('Content-Type: application/json');
echo json_encode($resp);
