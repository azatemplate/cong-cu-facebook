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
$target_id = $_POST['target_id'] ?? ''; // Có thể là post_id hoặc comment_id
$message = trim($_POST['message'] ?? '');

if (!$page_id || !$user_id || !$target_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số bắt buộc']);
    exit;
}

if ($message === '' && empty($_FILES['filedata']['tmp_name'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập tin nhắn hoặc chọn tệp']);
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
$payload = [];
if ($message) {
    $payload['message'] = $message;
}

// Nếu có đính kèm tệp cho bình luận
if (!empty($_FILES['filedata']['tmp_name'])) {
    $file_path = $_FILES['filedata']['tmp_name'];
    $mime_type = mime_content_type($file_path);
    $cfile = new CURLFile($file_path, $mime_type, $_FILES['filedata']['name']);
    $payload['source'] = $cfile; // Dùng cURL file
}

$endpoint = "$target_id/comments";

// Gọi fb_api_request. Kể cả payload chứa CURLFile, curl nội bộ trong fb_api_request sẽ tự xử lý multipart
$payload['access_token'] = $token;
$response = fb_api_request($endpoint, $payload, 'POST', $payload); // Params and post_data can be merged

if (isset($response['data']['error'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi FB: ' . $response['data']['error']['message']]);
    exit;
}

echo json_encode(['status' => 'success', 'data' => $response['data']]);
