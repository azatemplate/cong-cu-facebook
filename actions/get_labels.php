<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

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
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>
