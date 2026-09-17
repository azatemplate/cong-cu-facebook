<?php
// fix_buffer_yt_category.php - Reset failed Buffer YouTube posts to pending
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/html; charset=utf-8');

echo "<h2>🔄 RESET BÀI BUFFER YOUTUBE CẬP NHẬT CATEGORY ID VÀ CHẠY LẠI WORKER</h2>";

$stmt = $pdo->query("
    UPDATE scheduled_posts 
    SET status = 'pending', retry_count = 0, error_msg = NULL 
    WHERE post_type LIKE 'Buffer%' 
      AND (error_msg LIKE '%YoutubePostMetadataInput%' OR error_msg LIKE '%category%')
");

$affected = $stmt->rowCount();
echo "<p>Số bài Buffer YouTube bị lỗi đã được đưa về PENDING: <strong>{$affected}</strong></p>";

// Kích hoạt worker
if (file_exists(__DIR__ . '/cron/start_publish.php')) {
    ob_start();
    include __DIR__ . '/cron/start_publish.php';
    $out = ob_get_clean();
    echo "<pre style='background:#1e293b;color:#38bdf8;padding:15px;border-radius:8px;'>" . htmlspecialchars($out) . "</pre>";
}
?>
