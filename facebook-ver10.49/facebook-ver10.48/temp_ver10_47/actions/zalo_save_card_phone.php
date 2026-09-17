<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$oa_id = trim($_POST['oa_id'] ?? '');
$sender_id = trim($_POST['sender_id'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$msg_id = trim($_POST['msg_id'] ?? '');

if (empty($oa_id) || empty($sender_id) || empty($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

try {
    // 1. Update zalo_customers phone and auto-update consulted status based on all 4 fields
    $stmt = $pdo->prepare("
        UPDATE zalo_customers 
        SET phone = ?, 
            consulted = IF(
                name IS NOT NULL AND TRIM(name) != '' AND name != 'Khách hàng Zalo' AND 
                province IS NOT NULL AND TRIM(province) != '' AND 
                notes IS NOT NULL AND TRIM(notes) != '',
                IF(consulted = 0, 4, consulted),
                IF(consulted = 4, 0, consulted)
            ) 
        WHERE oa_id = ? AND sender_id = ?
    ");
    $stmt->execute([$phone, $oa_id, $sender_id]);
    
    // 2. Update zalo_file_messages if msg_id provided
    if (!empty($msg_id)) {
        $stmt_file = $pdo->prepare("SELECT file_name FROM zalo_file_messages WHERE message_id = ?");
        $stmt_file->execute([$msg_id]);
        $row = $stmt_file->fetch(PDO::FETCH_ASSOC);
        
        $card_data = ['name' => 'Danh thiếp Zalo', 'phone' => $phone];
        if ($row && !empty($row['file_name']) && strpos($row['file_name'], '{') === 0) {
            $dec = json_decode($row['file_name'], true);
            if (is_array($dec)) {
                $card_data = array_merge($dec, ['phone' => $phone]);
            }
        }
        
        $stmt_upd = $pdo->prepare("UPDATE zalo_file_messages SET file_name = ? WHERE message_id = ?");
        $stmt_upd->execute([json_encode($card_data, JSON_UNESCAPED_UNICODE), $msg_id]);
    }
    
    echo json_encode(['status' => 'success', 'phone' => $phone]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
