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

$conv_id = trim($_GET['conv_id'] ?? '');
$page_id = trim($_GET['page_id'] ?? '');

if (!$conv_id || !$page_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu conv_id hoặc page_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT label_name, created_at FROM conversation_labels WHERE conv_id = ? AND page_id = ? ORDER BY created_at ASC");
    $stmt->execute([$conv_id, $page_id]);
    $labels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'data' => $labels]);
} catch (PDOException $e) {
    // Don't leak DB error details
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi truy vấn dữ liệu.']);
}
?>
