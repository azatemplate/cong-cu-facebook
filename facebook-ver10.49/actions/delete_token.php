<?php
session_start();
if (!isset($_SESSION['account_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

// ── Security: Only accept POST requests with CSRF token ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../token_management.php?status=error&msg=' . urlencode('Yêu cầu không hợp lệ.'));
    exit;
}

verify_csrf();

if (isset($_POST['id'])) {
    $user_id = intval($_POST['id']);
    $account_id = $_SESSION['account_id'];
    $is_admin = ($_SESSION['role'] === 'admin');
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND account_id = ?");
$stmt->execute([$user_id, $account_id]);
        
        if ($stmt->fetch()) {
            // ── Cascade delete: xóa toàn bộ dữ liệu liên quan đến user/token này ──

            // 1. Lấy danh sách page_id thuộc user này
            $page_ids = [];
            try {
                $pg_stmt = $pdo->prepare("SELECT page_id FROM pages WHERE user_id = ?");
                $pg_stmt->execute([$user_id]);
                $page_ids = $pg_stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {}

            if (!empty($page_ids)) {
                $in = implode(',', array_fill(0, count($page_ids), '?'));

                // 2. Xóa scheduled_posts của các page này
                try {
                    $pdo->prepare("DELETE FROM scheduled_posts WHERE page_id IN ($in)")->execute($page_ids);
                } catch (PDOException $e) {}

                // 3. Xóa posts_history của các page này
                try {
                    $pdo->prepare("DELETE FROM posts_history WHERE page_id IN ($in)")->execute($page_ids);
                } catch (PDOException $e) {}

                // 4. Xóa page_shares của các page này
                try {
                    $pdo->prepare("DELETE FROM page_shares WHERE page_id IN ($in)")->execute($page_ids);
                } catch (PDOException $e) {}
            }

            // 5. Xóa post_campaigns thuộc account này mà không còn scheduled_posts nào
            try {
                $pdo->prepare("DELETE FROM post_campaigns WHERE account_id = ? AND id NOT IN (SELECT DISTINCT campaign_id FROM scheduled_posts WHERE campaign_id IS NOT NULL)")->execute([$account_id]);
            } catch (PDOException $e) {}

            // 6. Xóa toàn bộ pages của user
            try {
                $pdo->prepare("DELETE FROM pages WHERE user_id = ?")->execute([$user_id]);
            } catch (PDOException $e) {}

            // 7. Cuối cùng xóa user
            $del_stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $del_stmt->execute([$user_id]);

            // Clear Dashboard Cache
            $cache_dir = __DIR__ . '/../uploads/cache';
            foreach (glob($cache_dir . "/dashboard_user_{$account_id}_*.json") as $cf) { @unlink($cf); }
            foreach (glob($cache_dir . "/dashboard_admin_*.json") as $cf) { @unlink($cf); }

            header('Location: ../token_management.php?status=success_delete');
            exit;
        } else {
            header('Location: ../token_management.php?status=error&msg=' . urlencode('Không tìm thấy Token hoặc không có quyền xóa.'));
            exit;
        }
    } catch(PDOException $e) {
        // Don't leak DB error details in production
        $err_msg = (defined('APP_ENV') && APP_ENV === 'development') 
            ? 'Lỗi hệ thống khi xóa: ' . $e->getMessage() 
            : 'Lỗi hệ thống khi xóa. Vui lòng thử lại.';
        header('Location: ../token_management.php?status=error&msg=' . urlencode($err_msg));
        exit;
    }
}

header('Location: ../token_management.php?status=error&msg=' . urlencode('Yêu cầu không hợp lệ.'));
exit;
?>
