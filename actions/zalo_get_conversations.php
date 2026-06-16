<?php
// actions/zalo_get_conversations.php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}
session_write_close();

$oa_id = isset($_GET['oa_id']) ? trim($_GET['oa_id']) : '';

if (empty($oa_id)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng cung cấp OA ID.']);
    exit;
}

$account_id = $_SESSION['account_id'];

try {
    // Verify that this OA belongs to the current user
    $stmt_oa = $pdo->prepare("SELECT oa_id FROM zalo_oas WHERE oa_id = ? AND account_id = ?");
    $stmt_oa->execute([$oa_id, $account_id]);
    if (!$stmt_oa->fetch()) {
        echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền truy cập kênh OA này.']);
        exit;
    }

    // Select conversations from zalo_messages joined with zalo_customers details
    $stmt = $pdo->prepare("
        SELECT 
            m.sender_id,
            COALESCE(c.name, m.sender_name, 'Khách hàng Zalo') AS sender_name,
            COALESCE(c.avatar, 'https://ui-avatars.com/api/?name=Zalo') AS sender_avatar,
            c.phone,
            c.province,
            m.snippet,
            m.unread_count,
            m.updated_time
        FROM zalo_messages m
        LEFT JOIN zalo_customers c ON m.oa_id = c.oa_id AND m.sender_id = c.sender_id
        WHERE m.oa_id = ?
        ORDER BY m.updated_time DESC
    ");
    $stmt->execute([$oa_id]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $conversations
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
}
?>
