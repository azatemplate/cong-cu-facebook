<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['account_id'])) {
    list($status, $msg) = ['error', 'Unauthorized'];
    echo json_encode(compact('status', 'msg'));
    exit;
}

$id = $_POST['id'] ?? null;
$post_id = $_POST['post_id'] ?? null;
$conversation_id = $_POST['conversation_id'] ?? null;

if ($id) {
    // Only update if it belongs to user's accounts
    $stmt = $pdo->prepare("
        UPDATE page_notifications n
        JOIN pages p ON n.page_id = p.page_id
        JOIN users u ON p.user_id = u.id
        SET n.is_read = 1
        WHERE n.id = ? AND u.account_id = ?
    ");
    $stmt->execute([$id, $_SESSION['account_id']]);
    echo json_encode(['status' => 'success']);
} elseif ($post_id) {
    $stmt = $pdo->prepare("
        UPDATE page_notifications n
        JOIN pages p ON n.page_id = p.page_id
        JOIN users u ON p.user_id = u.id
        SET n.is_read = 1
        WHERE n.post_id = ? AND u.account_id = ?
    ");
    $stmt->execute([$post_id, $_SESSION['account_id']]);
    echo json_encode(['status' => 'success']);
} elseif ($conversation_id) {
    $stmt = $pdo->prepare("
        UPDATE page_notifications n
        JOIN pages p ON n.page_id = p.page_id
        JOIN users u ON p.user_id = u.id
        SET n.is_read = 1
        WHERE n.conversation_id = ? AND u.account_id = ?
    ");
    $stmt->execute([$conversation_id, $_SESSION['account_id']]);
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Missing ID']);
}
