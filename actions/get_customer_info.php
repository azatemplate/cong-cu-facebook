<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Auth guard
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

$sender_id = trim($_GET['sender_id'] ?? '');
$page_id = trim($_GET['page_id'] ?? '');

if (!$sender_id || !$page_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu sender_id hoặc page_id']);
    exit;
}

try {
    // Check if customer exists in the local database
    $stmt = $pdo->prepare("SELECT name, phone, province, notes, consulted, sales_phone, sales_notes, is_ads, ad_id, ad_title, ad_photo_url FROM fb_customers WHERE page_id = ? AND sender_id = ?");
    $stmt->execute([$page_id, $sender_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        // Fallback: try to find the customer name from page notifications
        $stmt_notif = $pdo->prepare("SELECT sender_name FROM page_notifications WHERE page_id = ? AND sender_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_notif->execute([$page_id, $sender_id]);
        $name = $stmt_notif->fetchColumn() ?: 'Khách hàng';
        
        // Insert a new record so it exists
        $ins = $pdo->prepare("INSERT INTO fb_customers (page_id, sender_id, name) VALUES (?, ?, ?)");
        $ins->execute([$page_id, $sender_id, $name]);
        
        $customer = [
            'name' => $name,
            'phone' => '',
            'province' => '',
            'notes' => '',
            'consulted' => 0,
            'sales_phone' => '',
            'sales_notes' => '',
            'is_ads' => 0,
            'ad_id' => '',
            'ad_title' => '',
            'ad_photo_url' => ''
        ];
    }
    
    $stmt_lock = $pdo->prepare("SELECT expire_at FROM bot_chat_locks WHERE page_id = ? AND sender_id = ? AND expire_at > NOW()");
    $stmt_lock->execute([$page_id, $sender_id]);
    $customer['is_locked'] = $stmt_lock->fetch() ? 1 : 0;
    
    echo json_encode(['status' => 'success', 'data' => $customer]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
