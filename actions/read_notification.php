<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}
session_write_close();

$id = $_POST['id'] ?? null;
$post_id = $_POST['post_id'] ?? null;
$conversation_id = $_POST['conversation_id'] ?? null;

if ($id) {
    if (strpos($id, 'fp_') === 0) {
        $sp_id = substr($id, 3);
        try {
            $stmt = $pdo->prepare("UPDATE scheduled_posts SET is_read = 1 WHERE id = ?");
            $stmt->execute([$sp_id]);
        } catch (Exception $e) {}
        echo json_encode(['status' => 'success']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE page_notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$id]);
    } catch (Exception $e) {}
    echo json_encode(['status' => 'success']);
} elseif ($post_id) {
    try {
        $stmt = $pdo->prepare("UPDATE page_notifications SET is_read = 1 WHERE post_id = ?");
        $stmt->execute([$post_id]);
    } catch (Exception $e) {}
    echo json_encode(['status' => 'success']);
} elseif ($conversation_id) {
    try {
        $stmt = $pdo->prepare("UPDATE page_notifications SET is_read = 1 WHERE conversation_id = ?");
        $stmt->execute([$conversation_id]);
    } catch (Exception $e) {}
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Missing ID']);
}

