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

$sys_page_id = 'SYSTEM_ACCOUNT_' . $_SESSION['account_id'];

if ($id) {
    if (strpos($id, 'fp_') === 0) {
        $sp_id = substr($id, 3);
        // Only update if it belongs to user's accounts
        $stmt = $pdo->prepare("
            UPDATE scheduled_posts sp
            JOIN pages p ON sp.page_id = p.page_id
            JOIN users u ON p.user_id = u.id
            SET sp.is_read = 1
            WHERE sp.id = ? AND u.account_id = ?
        ");
        $stmt->execute([$sp_id, $_SESSION['account_id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // Only update if it belongs to user's accounts
    $stmt = $pdo->prepare("
        UPDATE page_notifications n
        LEFT JOIN pages p ON n.page_id COLLATE utf8mb4_0900_ai_ci = p.page_id
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN zalo_oas zo ON n.page_id COLLATE utf8mb4_0900_ai_ci = zo.oa_id
        SET n.is_read = 1
        WHERE n.id = ? AND (
            u.account_id = ?
            OR EXISTS (SELECT 1 FROM page_shares ps WHERE ps.page_id = p.page_id AND ps.shared_with_account_id = ?)
            OR zo.account_id = ?
            OR n.page_id = ?
        )
    ");
    $stmt->execute([$id, $_SESSION['account_id'], $_SESSION['account_id'], $_SESSION['account_id'], $sys_page_id]);
    echo json_encode(['status' => 'success']);
} elseif ($post_id) {
    $stmt = $pdo->prepare("
        UPDATE page_notifications n
        LEFT JOIN pages p ON n.page_id COLLATE utf8mb4_0900_ai_ci = p.page_id
        LEFT JOIN users u ON p.user_id = u.id
        SET n.is_read = 1
        WHERE n.post_id = ? AND (
            u.account_id = ?
            OR EXISTS (SELECT 1 FROM page_shares ps WHERE ps.page_id = p.page_id AND ps.shared_with_account_id = ?)
            OR n.page_id = ?
        )
    ");
    $stmt->execute([$post_id, $_SESSION['account_id'], $_SESSION['account_id'], $sys_page_id]);
    echo json_encode(['status' => 'success']);
} elseif ($conversation_id) {
    $stmt = $pdo->prepare("
        UPDATE page_notifications n
        LEFT JOIN pages p ON n.page_id COLLATE utf8mb4_0900_ai_ci = p.page_id
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN zalo_oas zo ON n.page_id COLLATE utf8mb4_0900_ai_ci = zo.oa_id
        SET n.is_read = 1
        WHERE n.conversation_id = ? AND (
            u.account_id = ?
            OR EXISTS (SELECT 1 FROM page_shares ps WHERE ps.page_id = p.page_id AND ps.shared_with_account_id = ?)
            OR zo.account_id = ?
            OR n.page_id = ?
        )
    ");
    $stmt->execute([$conversation_id, $_SESSION['account_id'], $_SESSION['account_id'], $_SESSION['account_id'], $sys_page_id]);
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Missing ID']);
}
