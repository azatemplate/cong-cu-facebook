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

if (isset($_POST['read_all']) && $_POST['read_all'] == 1) {
    $account_id = $_SESSION['account_id'];
    try {
        $pdo->prepare("UPDATE scheduled_posts SET is_read = 1 WHERE account_id = ? AND status = 'failed'")->execute([$account_id]);
    } catch (Exception $e) {}

    $my_page_ids = $_SESSION['notif_page_ids'] ?? [];
    if (empty($my_page_ids)) {
        $my_page_ids = ['SYSTEM_ACCOUNT_' . $account_id];
        try {
            $st = $pdo->prepare("SELECT p.page_id FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = ?");
            $st->execute([$account_id]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $my_page_ids[] = $r['page_id']; }
        } catch (Exception $e) {}
        try {
            $st = $pdo->prepare("SELECT page_id FROM page_shares WHERE shared_with_account_id = ?");
            $st->execute([$account_id]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $my_page_ids[] = $r['page_id']; }
        } catch (Exception $e) {}
        try {
            $st = $pdo->prepare("SELECT oa_id FROM zalo_oas WHERE account_id = ?");
            $st->execute([$account_id]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $my_page_ids[] = $r['oa_id']; }
        } catch (Exception $e) {}
        try {
            $st = $pdo->prepare("SELECT ig_user_id FROM instagram_accounts WHERE account_id = ?");
            $st->execute([$account_id]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $my_page_ids[] = $r['ig_user_id']; }
        } catch (Exception $e) {}
        try {
            $st = $pdo->prepare("SELECT open_id FROM tiktok_accounts WHERE account_id = ?");
            $st->execute([$account_id]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $my_page_ids[] = $r['open_id']; }
        } catch (Exception $e) {}
        $my_page_ids = array_values(array_unique(array_filter($my_page_ids)));
    }

    if (!empty($my_page_ids)) {
        try {
            $in_clause = implode(',', array_fill(0, count($my_page_ids), '?'));
            $stmt = $pdo->prepare("UPDATE page_notifications SET is_read = 1 WHERE page_id IN ($in_clause) AND (is_read = 0 OR is_read IS NULL)");
            $stmt->execute(array_values($my_page_ids));
        } catch (Exception $e) {}
    }
    echo json_encode(['status' => 'success']);
    exit;
}

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

