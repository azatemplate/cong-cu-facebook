<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$page_id = $_GET['page_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$post_id = $_GET['post_id'] ?? '';
$cursor  = $_GET['before'] ?? '';

if (!$page_id || !$user_id || !$post_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số bắt buộc']);
    exit;
}

// 1. Check permission
$stmt = $pdo->prepare("SELECT u.account_id, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ? AND u.id = ?");
$stmt->execute([$page_id, $user_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Page hoặc không có quyền']);
    exit;
}
if (!$page['access_token']) {
    echo json_encode(['status' => 'error', 'msg' => 'Page chưa có token.']);
    exit;
}

$token = decryptData($page['access_token']);

// 2. Fetch comments from Graph API
// Lấy comments theo thứ tự thời gian đảo ngược (mới nhất trước) để giống Live Chat
$endpoint = "$post_id/comments";
$params = [
    'fields' => 'id,message,created_time,from,attachment,comments.summary(1){id,message,created_time,from,attachment}',
    'order' => 'reverse_chronological',
    'limit' => 20,
    'access_token' => $token
];
if ($cursor) {
    $params['after'] = $cursor; // 'after' ở reverse_chronological là đi ngược về quá khứ
}

$response = fb_api_request($endpoint, $params, 'GET');

if (isset($response['data']['error'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi FB: ' . $response['data']['error']['message']]);
    exit;
}

$next_cursor = $response['data']['paging']['cursors']['after'] ?? null;

echo json_encode([
    'status' => 'success',
    'data' => $response['data']['data'] ?? [],
    'next_cursor' => $next_cursor
]);
