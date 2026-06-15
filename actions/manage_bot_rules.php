<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (empty($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['account_id'];
$action = $_REQUEST['action'] ?? '';

// Auto migrate table to support active hours
try {
    $col = $pdo->query("SHOW COLUMNS FROM bot_chat_rules LIKE 'active_time_type'");
    if ($col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE bot_chat_rules ADD COLUMN active_time_type VARCHAR(20) DEFAULT 'ALL_DAY' AFTER is_active");
    }
    $col = $pdo->query("SHOW COLUMNS FROM bot_chat_rules LIKE 'active_start_time'");
    if ($col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE bot_chat_rules ADD COLUMN active_start_time TIME DEFAULT '00:00:00' AFTER active_time_type");
    }
    $col = $pdo->query("SHOW COLUMNS FROM bot_chat_rules LIKE 'active_end_time'");
    if ($col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE bot_chat_rules ADD COLUMN active_end_time TIME DEFAULT '23:59:59' AFTER active_start_time");
    }
} catch (Exception $e) {}

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
    $delay_seconds = intval($_POST['delay_seconds'] ?? 0);
    $history_count = intval($_POST['history_count'] ?? 6);
    $active_time_type = $_POST['active_time_type'] ?? 'ALL_DAY';
    $active_start_time = $_POST['active_start_time'] ?? '00:00:00';
    $active_end_time = $_POST['active_end_time'] ?? '23:59:59';

    // Format TIME values properly (e.g. 17:00 to 17:00:00)
    if (strlen($active_start_time) === 5) $active_start_time .= ':00';
    if (strlen($active_end_time) === 5) $active_end_time .= ':00';

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
            $stmt = $pdo->prepare("UPDATE bot_chat_rules SET rule_type=?, keywords=?, message=?, delay_seconds=?, history_count=?, pages_scope=?, is_active=?, active_time_type=?, active_start_time=?, active_end_time=? WHERE id=? AND account_id=?");
            $stmt->execute([$rule_type, $keywords, $message, $delay_seconds, $history_count, $pages_scope, $is_active, $active_time_type, $active_start_time, $active_end_time, $id, $account_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO bot_chat_rules (account_id, rule_type, keywords, message, delay_seconds, history_count, pages_scope, is_active, active_time_type, active_start_time, active_end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$account_id, $rule_type, $keywords, $message, $delay_seconds, $history_count, $pages_scope, $is_active, $active_time_type, $active_start_time, $active_end_time]);
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
