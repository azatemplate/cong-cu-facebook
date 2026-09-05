<?php
session_start();
if (!isset($_SESSION['account_id'])) {
    session_write_close();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}
$account_id = $_SESSION['account_id'];
session_write_close(); // Giải phóng session lock ngay lập tức để tránh tranh chấp / treo tiến trình

require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("
        (SELECT p.page_id, p.name, p.user_id 
         FROM pages p JOIN users u ON p.user_id = u.id
         WHERE u.account_id = :aid)
        UNION
        (SELECT p.page_id, p.name, p.user_id 
         FROM pages p
         JOIN page_shares ps ON p.page_id = ps.page_id
         JOIN users u ON p.user_id = u.id
         WHERE ps.shared_with_account_id = :aid2)
    ");
    $stmt->execute([':aid' => $account_id, ':aid2' => $account_id]);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'pages' => $pages]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>
