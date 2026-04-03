<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// ── Auth Guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
    exit;
}

$conv_id    = trim($_POST['conv_id'] ?? '');
$page_id    = trim($_POST['page_id'] ?? '');
$label_name = trim($_POST['label_name'] ?? '');

if (!$conv_id || !$page_id || !$label_name) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu thông tin']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM conversation_labels WHERE conv_id = ? AND page_id = ? AND label_name = ?");
    $stmt->execute([$conv_id, $page_id, $label_name]);
    echo json_encode(['status' => 'success']);
} catch (PDOException $e) {
    // Don't leak DB error details
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi xử lý dữ liệu.']);
}
?>
