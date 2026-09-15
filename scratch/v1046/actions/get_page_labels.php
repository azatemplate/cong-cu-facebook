<?php
// actions/get_page_labels.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

$page_id      = trim($_GET['page_id'] ?? '');
$recipient_id = trim($_GET['recipient_id'] ?? ($_GET['sender_id'] ?? ''));

if (!$page_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu page_id']);
    exit;
}

$all_labels = [];
$assigned_labels = [];

function addUniqueLabel(&$list, $name) {
    $name = trim($name);
    if ($name === '') return;
    if (strpos($name, 'ad_id.') === 0) return; // Lọc bỏ các nhãn hệ thống chạy Quảng cáo (ad_id...)
    foreach ($list as $existing) {
        if (mb_strtolower($existing) === mb_strtolower($name)) {
            return;
        }
    }
    $list[] = $name;
}

// 1. Fetch live labels from Meta Custom Labels API for this page (Nguồn chuẩn duy nhất từ Facebook)
$stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
$stmt->execute([$page_id]);
$raw_token = $stmt->fetchColumn();

if ($raw_token) {
    $access_token = decryptData($raw_token);
    if ($access_token) {
        $meta_res = fb_api_request("{$page_id}/custom_labels", [
            'fields' => 'id,name,page_label_name',
            'limit' => 100,
            'access_token' => $access_token
        ], 'GET');

        if (!empty($meta_res['data']['data']) && is_array($meta_res['data']['data'])) {
            foreach ($meta_res['data']['data'] as $lbl) {
                $name = $lbl['page_label_name'] ?? ($lbl['name'] ?? '');
                addUniqueLabel($all_labels, $name);
            }
        }
    }
}

// 2. Fallback: Nếu không thể lấy từ Meta API thì mới lấy từ database local
if (empty($all_labels)) {
    try {
        $stmt_db_all = $pdo->prepare("SELECT DISTINCT label_name FROM conversation_labels WHERE page_id = ? AND label_name IS NOT NULL AND label_name != ''");
        $stmt_db_all->execute([$page_id]);
        $db_labels = $stmt_db_all->fetchAll(PDO::FETCH_COLUMN);
        foreach ($db_labels as $dl) {
            addUniqueLabel($all_labels, $dl);
        }
    } catch (Exception $e) {}
}

// 3. Fetch currently assigned labels for recipient_id
if (!empty($recipient_id)) {
    try {
        $stmt_ass = $pdo->prepare("SELECT DISTINCT label_name FROM conversation_labels WHERE page_id = ? AND recipient_id = ?");
        $stmt_ass->execute([$page_id, $recipient_id]);
        $assigned_labels = $stmt_ass->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

echo json_encode([
    'status' => 'success',
    'all_labels' => array_values($all_labels),
    'assigned_labels' => array_values($assigned_labels)
]);
