<?php
// scratch/retry_all_failed.php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Chỉ cho phép Admin chạy script này để bảo mật
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die("Từ chối truy cập. Chỉ tài khoản Admin mới có quyền thực thi script này.");
}

require_once __DIR__ . '/../includes/db.php';

try {
    // 1. Đếm số lượng bài viết bị lỗi trước khi reset
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE status = 'failed'");
    $total_failed = (int)$count_stmt->fetchColumn();

    if ($total_failed === 0) {
        echo "<h3>Không có bài viết nào ở trạng thái lỗi (failed) cần khôi phục.</h3>";
        exit;
    }

    // 2. Thực hiện cập nhật trạng thái về pending, xóa lỗi và reset số lần thử lại
    $stmt = $pdo->prepare("UPDATE scheduled_posts SET status = 'pending', error_msg = NULL, retry_count = 0 WHERE status = 'failed'");
    $stmt->execute();
    $affected_rows = $stmt->rowCount();

    echo "<h3>Khôi phục hàng loạt thành công!</h3>";
    echo "<p>- Tổng số bài viết lỗi phát hiện trước đó: <strong>$total_failed</strong></p>";
    echo "<p>- Số bài viết đã được kích hoạt lại thành công: <strong>$affected_rows</strong></p>";
    echo "<p>- Các bài viết này đã được đưa lại vào hàng đợi (pending) và sẽ được worker quét đăng ở chu kỳ cron tiếp theo.</p>";

} catch (Exception $e) {
    echo "<h3>Đã xảy ra lỗi trong quá trình thực thi:</h3>";
    echo "<p style='color:red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}
