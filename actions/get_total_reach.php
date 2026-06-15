<?php
session_start();
if (!isset($_SESSION['account_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

$stmt = $pdo->prepare("SELECT p.page_id, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = ? LIMIT 50");
$stmt->execute([$account_id]);

$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pages)) {
    echo json_encode(['success' => true, 'total_reach' => 0]);
    exit;
}

// Build multi-curl request Array
$mh = curl_multi_init();
$curl_handlers = [];

foreach ($pages as $p) {
    if (empty($p['access_token'])) continue;
    $ptoken = decryptData($p['access_token']);
    $url = "https://graph.facebook.com/v25.0/" . $p['page_id'] . "/insights?metric=page_impressions,page_post_engagements&period=day&access_token=" . urlencode($ptoken);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 seconds max per request
    
    curl_multi_add_handle($mh, $ch);
    $curl_handlers[] = $ch;
}

$active = null;
do {
    $status = curl_multi_exec($mh, $active);
    if ($active) {
        curl_multi_select($mh);
    }
} while ($active && $status == CURLM_OK);

$total_reach = 0;

$debug_responses = [];

foreach ($curl_handlers as $ch) {
    // ... inside loop
    $response = curl_multi_getcontent($ch);
    $debug_responses[] = $response; // Save for debugging
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            foreach ($data['data'] as $metric) {
                if ($metric['name'] === 'page_impressions' || $metric['name'] === 'page_impressions_unique') {
                    if (isset($metric['values']) && is_array($metric['values'])) {
                        foreach ($metric['values'] as $v) {
                            $total_reach += isset($v['value']) ? intval($v['value']) : 0;
                        }
                    }
                }
            }
        }
    }
}

curl_multi_close($mh);

// TRÌNH BẮT LỖI TẠM THỜI: Ghi đè kết quả debug ra 1 file text để tôi debug
file_put_contents(__DIR__ . '/debug_fb.txt', print_r($debug_responses, true));

echo json_encode(['success' => true, 'total_reach' => $total_reach, 'debug' => array_slice($debug_responses, 0, 2)]);
?>
