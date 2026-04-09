<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$page_id = $_GET['page_id'] ?? '';
if (!$page_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu page_id']);
    exit;
}

// Lấy token page
$t_stmt = $pdo->prepare("
    SELECT p.access_token FROM pages p
    JOIN users u ON p.user_id = u.id
    WHERE p.page_id = ?
    LIMIT 1
");
$t_stmt->execute([$page_id]);
$t_row = $t_stmt->fetch(PDO::FETCH_ASSOC);

if (!$t_row || empty($t_row['access_token'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Token Fanpage']);
    exit;
}

$page_token = decryptData($t_row['access_token']);

// 1. Fetch latest videos (Up to 30)
$videos_res = fb_api_request("{$page_id}/videos", [
    'fields' => 'id,created_time',
    'limit' => 30,
    'access_token' => $page_token
], 'GET');

$video_ids = [];
if (isset($videos_res['data']['data'])) {
    foreach ($videos_res['data']['data'] as $v) {
        $video_ids[] = $v['id'];
    }
}

// 2. Build Batch Request for Video Insights
// Metrics: total_video_ad_break_earnings
// (This metric might return an object with "value" = double)
if (empty($video_ids)) {
    // Không có video nào
    return_empty_success();
}

$batch = [];
foreach ($video_ids as $vid) {
    $batch[] = [
        'method' => 'GET',
        'relative_url' => "{$vid}/video_insights/total_video_ad_break_earnings"
    ];
}

$batch_res = fb_api_request('', [
    'access_token' => $page_token,
    'batch' => json_encode($batch)
], 'POST');

$total_earnings = 0.0;
$daily_earnings = [];

// Initialize 28 days of data
for ($i=27; $i>=0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $daily_earnings[$day] = 0.0;
}

if (isset($batch_res['data']) && is_array($batch_res['data'])) {
    foreach ($batch_res['data'] as $index => $node) {
        if ($node['code'] === 200) {
            $body = json_decode($node['body'], true);
            if (isset($body['data'][0]['values'][0]['value'])) {
                // value is usually string/float
                $val = floatval($body['data'][0]['values'][0]['value']);
                $total_earnings += $val;
                
                // Thu nhập được tính dồn vào ngày tạo video do Facebook trả về tổng.
                // Nếu muốn daily chính xác thì API của bên FB không hỗ trợ breakdown theo từng ngày cho video cụ thể,
                // Do đó fallback (tạm tính dồn vào ngày tạo video)
                if (isset($videos_res['data']['data'][$index]['created_time'])) {
                    $day_created = date('Y-m-d', strtotime($videos_res['data']['data'][$index]['created_time']));
                    if (isset($daily_earnings[$day_created])) {
                        $daily_earnings[$day_created] += $val;
                    }
                }
            }
        }
    }
}

// Calculate Stats
$total_28 = array_sum($daily_earnings);

// Calculate total 7
$total_7 = 0;
$past_7 = 0; // days 8-14 to calculate growth
for ($i=0; $i<7; $i++) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $total_7 += ($daily_earnings[$day] ?? 0);
    
    $prev_day = date('Y-m-d', strtotime("-" . ($i+7) . " days"));
    $past_7 += ($daily_earnings[$prev_day] ?? 0);
}

$avg_day = $total_28 / 28;

$growth = 0;
if ($past_7 > 0) {
    $growth = (($total_7 - $past_7) / $past_7) * 100;
} elseif ($total_7 > 0) {
    $growth = 100;
}

$labels = [];
$values = [];
foreach ($daily_earnings as $day => $val) {
    // Định dạng mm-dd
    $labels[] = date('m-d', strtotime($day));
    $values[] = round($val, 2);
}

echo json_encode([
    'status' => 'success',
    'stats' => [
        'total_28' => round($total_28, 2),
        'total_7' => round($total_7, 2),
        'avg_day' => round($avg_day, 2),
        'growth' => round($growth, 2)
    ],
    'chart' => [
        'labels' => $labels,
        'values' => $values
    ]
]);
exit;

function return_empty_success() {
    $daily = [];
    $labels = [];
    $values = [];
    for ($i=27; $i>=0; $i--) {
        $day = date('m-d', strtotime("-$i days"));
        $labels[] = $day;
        $values[] = 0.0;
    }
    echo json_encode([
        'status' => 'success',
        'stats' => ['total_28'=>0.0, 'total_7'=>0.0, 'avg_day'=>0.0, 'growth'=>0.0],
        'chart' => ['labels' => $labels, 'values' => $values]
    ]);
    exit;
}
