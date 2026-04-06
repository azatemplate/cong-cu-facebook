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

$page_id = $_POST['page_id'] ?? '';
$user_id = $_POST['user_id'] ?? '';
$target_id = $_POST['target_id'] ?? ''; // target_id must be a comment_id
$message = trim($_POST['message'] ?? '');

if (!$page_id || !$user_id || !$target_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số bắt buộc']);
    exit;
}

if ($message === '') {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập tin nhắn cần gửi riêng']);
    exit;
}

// 1. Check permission
$stmt = $pdo->prepare("SELECT p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ? AND u.id = ? AND u.account_id = ?");
$stmt->execute([$page_id, $user_id, $_SESSION['account_id']]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page || !$page['access_token']) {
    echo json_encode(['status' => 'error', 'msg' => 'Không có quyền truy cập hoặc Page chưa cấu hình token']);
    exit;
}

$token = decryptData($page['access_token']);

// 2. Prepare Payload
$payload = [
    'message' => $message,
    'access_token' => $token
];

// Graph API for private reply takes POST /{comment_id}/private_replies
$endpoint = "$target_id/private_replies";

$response = fb_api_request($endpoint, $payload, 'POST', $payload);

if (isset($response['data']['error'])) {
    // There is a 7 day limit. If error code implies "can't reply", let user know securely
    $err = $response['data']['error']['message'];
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi FB: ' . $err]);
    exit;
}

echo json_encode(['status' => 'success', 'data' => $response['data']]);
