<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (empty($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['account_id'];
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT * FROM bot_chat_rules WHERE account_id = ? ORDER BY id DESC");
    $stmt->execute([$account_id]);
    echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'save') {
    $id = intval($_POST['id'] ?? 0);
    $rule_type = $_POST['rule_type'] ?? '';
    $keywords = $_POST['keywords'] ?? '';
    $message = $_POST['message'] ?? '';
    $pages_scope = $_POST['pages_scope'] ?? 'ALL';
    $is_active = intval($_POST['is_active'] ?? 1);

    if (empty($message)) {
        echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập nội dung phản hồi']);
        exit;
    }
    
    if ($rule_type === 'keyword' && empty(trim($keywords))) {
        echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập từ khóa']);
        exit;
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE bot_chat_rules SET rule_type=?, keywords=?, message=?, pages_scope=?, is_active=? WHERE id=? AND account_id=?");
            $stmt->execute([$rule_type, $keywords, $message, $pages_scope, $is_active, $id, $account_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO bot_chat_rules (account_id, rule_type, keywords, message, pages_scope, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$account_id, $rule_type, $keywords, $message, $pages_scope, $is_active]);
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM bot_chat_rules WHERE id=? AND account_id=?");
        $stmt->execute([$id, $account_id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'msg' => 'Invalid action']);
