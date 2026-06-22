<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

$username = trim($_GET['username'] ?? '');
$count    = max(1, min(100, intval($_GET['count'] ?? 33)));
$cursor   = trim($_GET['cursor'] ?? '0');

if ($username === '') {
    http_response_code(400);
    echo json_encode(['code' => -1, 'msg' => 'Thiếu tham số username']);
    exit;
}

// Get TikTok API URL
$tiktok_api_url = 'http://127.0.0.1:8000';
try {
    $ss_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'tiktok_api_url'");
    if ($ss_stmt) {
        $db_url = trim($ss_stmt->fetchColumn() ?: '');
        if ($db_url !== '') {
            $tiktok_api_url = $db_url;
        }
    }
} catch (Exception $e) {}

$api_url = rtrim($tiktok_api_url, '/') . '/api/tiktok/user_videos?' . http_build_query([
    'username' => $username,
    'count'    => $count,
    'cursor'   => $cursor,
]);

$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_FOLLOWLOCATION => true,
]);
$resp = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(500);
    echo json_encode(['code' => -1, 'msg' => 'Lỗi cURL: ' . $err]);
    exit;
}

$data = json_decode($resp, true);
if (!$data || ($data['code'] ?? 0) !== 200) {
    http_response_code(400);
    echo json_encode([
        'code' => -1,
        'msg' => $data['detail'] ?? ($data['msg'] ?? 'Không thể tải danh sách video của username này.')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
