<?php
// actions/tiktok_chat_api.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/tiktok_api.php';
require_once __DIR__ . '/../setup_tiktok_chat.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
    exit;
}

$account_id = $_SESSION['account_id'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// 1. Get TikTok Conversations
if ($action === 'get_conversations') {
    $open_id = trim($_GET['open_id'] ?? '');
    
    if (empty($open_id)) {
        echo json_encode(['status' => 'success', 'conversations' => []]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT tc.*, 
                   tc.last_message_at AS updated_at
            FROM tiktok_customers tc
            WHERE tc.open_id = ?
            ORDER BY tc.last_message_at DESC
        ");
        $stmt->execute([$open_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'conversations' => $customers]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// 2. Get Messages for a specific customer
if ($action === 'get_messages') {
    $open_id = trim($_GET['open_id'] ?? '');
    $sender_id = trim($_GET['sender_id'] ?? '');

    if (empty($open_id) || empty($sender_id)) {
        echo json_encode(['status' => 'success', 'messages' => []]);
        exit;
    }

    try {
        // Mark as read
        $upd = $pdo->prepare("UPDATE tiktok_customers SET unread_count = 0 WHERE open_id = ? AND sender_id = ?");
        $upd->execute([$open_id, $sender_id]);

        $stmt = $pdo->prepare("
            SELECT * FROM tiktok_messages 
            WHERE open_id = ? AND sender_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$open_id, $sender_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'messages' => $messages]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// 3. Send Reply Message to Customer
if ($action === 'send_message') {
    $open_id = trim($_POST['open_id'] ?? '');
    $sender_id = trim($_POST['sender_id'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($open_id) || empty($sender_id) || empty($message)) {
        echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập nội dung tin nhắn.']);
        exit;
    }

    try {
        // Save agent message to DB
        $stmt = $pdo->prepare("
            INSERT INTO tiktok_messages (open_id, sender_id, sender_name, sender_type, message, is_read, created_at)
            VALUES (?, ?, 'Tư vấn viên', 'agent', ?, 1, NOW())
        ");
        $stmt->execute([$open_id, $sender_id, $message]);

        // Update customer last_message
        $stmt_c = $pdo->prepare("
            UPDATE tiktok_customers 
            SET last_message = ?, last_sender = 'agent', last_message_at = NOW() 
            WHERE open_id = ? AND sender_id = ?
        ");
        $stmt_c->execute([$message, $open_id, $sender_id]);

        echo json_encode(['status' => 'success', 'msg' => 'Đã gửi tin nhắn thành công.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi lưu tin nhắn: ' . $e->getMessage()]);
    }
    exit;
}

// 4. Save Customer Profile
if ($action === 'save_customer_profile') {
    $open_id = trim($_POST['open_id'] ?? '');
    $sender_id = trim($_POST['sender_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $consulted = intval($_POST['consulted'] ?? 0);
    $sales_phone = trim($_POST['sales_phone'] ?? '');
    $sales_notes = trim($_POST['sales_notes'] ?? '');

    if (empty($open_id) || empty($sender_id)) {
        echo json_encode(['status' => 'error', 'msg' => 'Thiếu thông tin nhận diện khách hàng.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO tiktok_customers (open_id, sender_id, name, phone, province, notes, consulted, sales_phone, sales_notes, last_message_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                phone = VALUES(phone),
                province = VALUES(province),
                notes = VALUES(notes),
                consulted = VALUES(consulted),
                sales_phone = VALUES(sales_phone),
                sales_notes = VALUES(sales_notes)
        ");
        $stmt->execute([$open_id, $sender_id, $name, $phone, $province, $notes, $consulted, $sales_phone, $sales_notes]);

        echo json_encode(['status' => 'success', 'msg' => 'Đã lưu thông tin hồ sơ khách hàng TikTok!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'msg' => 'Action không hợp lệ']);
?>
