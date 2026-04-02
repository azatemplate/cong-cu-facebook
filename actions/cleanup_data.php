<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Only Admins can run the cleanup
if (!isset($_SESSION['account_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'msg' => 'Truy cập bị từ chối.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed.']);
    exit;
}

try {
    $stats = [];
    $pdo->beginTransaction();

    // 1. Dọn dẹp Level 3: posts_history, page_shares
    // Xóa bài viết thuộc về Fanpage không còn tồn tại
    $stmt_history = $pdo->query("DELETE FROM posts_history WHERE page_id NOT IN (SELECT page_id FROM pages)");
    $stats['posts_history'] = $stmt_history->rowCount();

    // Xóa chia sẻ quyền Fanpage nếu trang hoặc 1 trong 2 người dùng không tồn tại
    $stmt_shares = $pdo->query("
        DELETE FROM page_shares 
        WHERE page_id NOT IN (SELECT page_id FROM pages) 
           OR owner_account_id NOT IN (SELECT id FROM system_accounts) 
           OR shared_with_account_id NOT IN (SELECT id FROM system_accounts)
    ");
    $stats['page_shares'] = $stmt_shares->rowCount();

    // 2. Dọn dẹp Level 2: pages
    // Xóa Fanpage mà owner_user không còn tồn tại trong bảng users
    $stmt_pages = $pdo->query("DELETE FROM pages WHERE user_id NOT IN (SELECT id FROM users)");
    $stats['pages'] = $stmt_pages->rowCount();

    // 3. Dọn dẹp Level 1: users (Profiles)
    // Xóa Users mà account_id đã bị xóa khỏi system_accounts
    $stmt_users = $pdo->query("DELETE FROM users WHERE account_id NOT IN (SELECT id FROM system_accounts)");
    $stats['users'] = $stmt_users->rowCount();

    // 4. Dọn dẹp Level 1 (Liên kết trực tiếp system_accounts): scheduled_posts, saved_replies, ai_configs
    
    // --- Bắt đầu dọn dẹp file media rỗng của scheduled_posts ---
    $stmt_media = $pdo->query("SELECT media_path FROM scheduled_posts WHERE account_id NOT IN (SELECT id FROM system_accounts) AND media_path IS NOT NULL AND media_path != ''");
    $media_files = $stmt_media->fetchAll(PDO::FETCH_COLUMN);
    $deleted_files_count = 0;
    foreach ($media_files as $path) {
        // Tách các đường dẫn có thể bị nối bởi dấu phẩy
        $paths = explode(',', $path);
        foreach ($paths as $p) {
            $p = trim($p);
            $full_path = __DIR__ . '/../' . $p;
            if (file_exists($full_path) && is_file($full_path)) {
                unlink($full_path);
                $deleted_files_count++;
            }
        }
    }
    
    $stmt_sched = $pdo->query("DELETE FROM scheduled_posts WHERE account_id NOT IN (SELECT id FROM system_accounts)");
    $stats['scheduled_posts'] = $stmt_sched->rowCount();
    $stats['media_files'] = $deleted_files_count;

    $stmt_rep = $pdo->query("DELETE FROM saved_replies WHERE account_id NOT IN (SELECT id FROM system_accounts)");
    $stats['saved_replies'] = $stmt_rep->rowCount();

    $stmt_ai = $pdo->query("DELETE FROM ai_configs WHERE account_id NOT IN (SELECT id FROM system_accounts)");
    $stats['ai_configs'] = $stmt_ai->rowCount();

    $pdo->commit();

    $total_cleaned = array_sum($stats);

    echo json_encode([
        'status' => 'success', 
        'msg' => "Dọn dẹp hoàn tất ($total_cleaned dòng mồ côi đã được xóa).",
        'data' => $stats
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
