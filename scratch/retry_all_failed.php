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
    // 1. Đếm số lượng bài viết bị lỗi trong ngày hôm nay (scheduled_time hoặc updated_at là hôm nay)
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE status = 'failed' AND (DATE(scheduled_time) = CURDATE() OR DATE(updated_at) = CURDATE())");
    $total_failed_today = (int)$count_stmt->fetchColumn();

    if ($total_failed_today === 0) {
        echo "<h3>Không có bài viết nào bị lỗi trong ngày hôm nay (" . date('d/m/Y') . ") cần khôi phục.</h3>";
        exit;
    }

    // 2. Thực hiện cập nhật trạng thái về pending cho các bài lỗi hôm nay
    $stmt = $pdo->prepare("UPDATE scheduled_posts SET status = 'pending', error_msg = NULL, retry_count = 0 WHERE status = 'failed' AND (DATE(scheduled_time) = CURDATE() OR DATE(updated_at) = CURDATE())");
    $stmt->execute();
    $affected_rows = $stmt->rowCount();

    echo "<h3>Khôi phục hàng loạt bài lỗi hôm nay thành công!</h3>";
    echo "<p>- Ngày thực hiện: <strong>" . date('d/m/Y') . "</strong></p>";
    echo "<p>- Số bài viết lỗi hôm nay phát hiện: <strong>$total_failed_today</strong></p>";
    echo "<p>- Số bài viết đã được kích hoạt lại thành công: <strong>$affected_rows</strong></p>";
    echo "<p>- Các bài viết này đã được đưa lại vào hàng đợi (pending) và sẽ được worker quét đăng ở chu kỳ cron tiếp theo.</p>";

} catch (Exception $e) {
    echo "<h3>Đã xảy ra lỗi trong quá trình thực thi:</h3>";
    echo "<p style='color:red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}
