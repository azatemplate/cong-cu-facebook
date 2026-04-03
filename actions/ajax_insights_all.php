<?php
// actions/ajax_insights_all.php
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

$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-30 days'));

$cache_key_all = "insights_all_growth_v2_{$account_id}_{$period}";

if (isset($_SESSION[$cache_key_all]) && $_SESSION[$cache_key_all]['expires'] > time() && !isset($_GET['nocache'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'data' => $_SESSION[$cache_key_all]['data']]);
    exit;
}

$stmt_all = $pdo->prepare("
    SELECT p.page_id, p.access_token, p.name, p.followers_count 
    FROM pages p JOIN users u ON p.user_id = u.id 
    WHERE u.account_id = :aid
    UNION
    SELECT p.page_id, p.access_token, p.name, p.followers_count 
    FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id 
    WHERE ps.shared_with_account_id = :aid2
    ORDER BY followers_count DESC
");
$stmt_all->execute(['aid' => $account_id, 'aid2' => $account_id]);
$all_user_pages = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

$all_page_insights = [];
$fetch_pages = [];
foreach ($all_user_pages as $p_row) {
    $fetch_pages[] = [
        'page_id' => $p_row['page_id'],
        'access_token' => decryptData($p_row['access_token'])
    ];
    $all_page_insights[$p_row['page_id']] = [
        'name' => $p_row['name'],
        'followers' => $p_row['followers_count'],
        'reach' => 0,
        'views' => 0
    ];
}

$api_responses = get_fb_page_insights_multi($fetch_pages, $period, $start_date, $end_date);

foreach ($api_responses as $pid => $api_response) {
    if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
        foreach ($api_response['data']['data'] as $metric) {
            if ($metric['name'] === 'page_media_view' || $metric['name'] === 'page_impressions_unique') {
                if (isset($metric['values']) && is_array($metric['values']) && count($metric['values']) > 0) {
                    $values_arr = $metric['values'];
                    $latest_value = end($values_arr);
                    $val = isset($latest_value['value']) ? intval($latest_value['value']) : 0;
                    
                    if ($metric['name'] === 'page_media_view') {
                        $all_page_insights[$pid]['views'] += $val;
                    } else {
                        $all_page_insights[$pid]['reach'] += $val;
                    }
                }
            }
        }
    }
}

// Sắp xếp lại theo Reach giảm dần để dễ nhìn sự tăng trưởng
uasort($all_page_insights, function($a, $b) {
    return $b['reach'] <=> $a['reach'];
});

$_SESSION[$cache_key_all] = [
    'data' => $all_page_insights,
    'expires' => time() + 1800
];

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'data' => $all_page_insights]);
