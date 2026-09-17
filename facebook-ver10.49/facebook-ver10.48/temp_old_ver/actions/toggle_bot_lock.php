<?php
// actions/toggle_bot_lock.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
    exit;
}

$type = isset($_POST['type']) ? trim($_POST['type']) : 'facebook'; // 'facebook' or 'zalo'
$sender_id = isset($_POST['sender_id']) ? trim($_POST['sender_id']) : '';
$channel_id = isset($_POST['channel_id']) ? trim($_POST['channel_id']) : ''; // page_id or oa_id
$action = isset($_POST['action']) ? trim($_POST['action']) : 'toggle'; // 'lock', 'unlock' or 'toggle'

if (empty($sender_id) || empty($channel_id)) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số bắt buộc.']);
    exit;
}

try {
    if ($type === 'zalo') {
        $table = 'zalo_chat_locks';
        $channel_col = 'oa_id';
    } else {
        $table = 'bot_chat_locks';
        $channel_col = 'page_id';
    }

    // Check if lock currently exists and is active
    $stmt = $pdo->prepare("SELECT expire_at FROM {$table} WHERE {$channel_col} = ? AND sender_id = ? AND expire_at > NOW()");
    $stmt->execute([$channel_id, $sender_id]);
    $is_locked = $stmt->fetch() ? true : false;

    // Determine target action (if toggle, reverse current status)
    $target_lock = ($action === 'lock') || ($action === 'toggle' && !$is_locked);

    if ($target_lock) {
        // Lock bot (set expiration far in the future to block it indefinitely)
        $expire_at = '2038-01-01 00:00:00';
        $stmt_save = $pdo->prepare("
            INSERT INTO {$table} ({$channel_col}, sender_id, expire_at)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE expire_at = ?
        ");
        $stmt_save->execute([$channel_id, $sender_id, $expire_at, $expire_at]);
        
        echo json_encode(['status' => 'success', 'is_locked' => 1, 'msg' => 'Đã tạm dừng bot cho khách hàng này.']);
    } else {
        // Unlock bot (delete lock row)
        $stmt_del = $pdo->prepare("DELETE FROM {$table} WHERE {$channel_col} = ? AND sender_id = ?");
        $stmt_del->execute([$channel_id, $sender_id]);
        
        echo json_encode(['status' => 'success', 'is_locked' => 0, 'msg' => 'Đã kích hoạt lại bot cho khách hàng này.']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi CSDL: ' . $e->getMessage()]);
}
?>
