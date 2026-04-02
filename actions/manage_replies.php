<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;

if (!$user_id) {
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Thiếu user_id']);
        exit;
    }
}

// Find account_id
$stmt = $pdo->prepare("SELECT account_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch();
$account_id = $u ? $u['account_id'] : 1; 

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    if ($action === 'list') {
        // Lấy danh sách tin mẫu
        $stmt = $pdo->prepare("SELECT id, title, content FROM saved_replies WHERE account_id = ? ORDER BY id DESC");
        $stmt->execute([$account_id]);
        $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $replies]);
        exit;
    } 
    elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Thêm tin mẫu mới
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $content = isset($_POST['content']) ? trim($_POST['content']) : '';

        if (empty($content)) {
            echo json_encode(['status' => 'error', 'msg' => 'Nội dung không được để trống']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO saved_replies (account_id, title, content) VALUES (?, ?, ?)");
        $stmt->execute([$account_id, $title, $content]);
        $new_id = $pdo->lastInsertId();

        echo json_encode([
            'status' => 'success', 
            'msg' => 'Đã thêm tin mẫu thành công', 
            'data' => ['id' => $new_id, 'title' => $title, 'content' => $content]
        ]);
        exit;
    }
    elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Xóa tin mẫu
        $reply_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        $stmt = $pdo->prepare("DELETE FROM saved_replies WHERE id = ? AND account_id = ?");
        $stmt->execute([$reply_id, $account_id]);

        echo json_encode(['status' => 'success', 'msg' => 'Đã xóa tin mẫu']);
        exit;
    }
    else {
        echo json_encode(['status' => 'error', 'msg' => 'Hành động không hợp lệ']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi server: ' . $e->getMessage()]);
}
?>
