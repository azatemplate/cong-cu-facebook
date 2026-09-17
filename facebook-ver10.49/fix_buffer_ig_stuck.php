<?php
// fix_buffer_ig_stuck.php - Reset failed Instagram Buffer posts to pending
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/html; charset=utf-8');

echo "<h2>🔄 RESET BÀI BUFFER INSTAGRAM THẤT BẠI VÀ CHẠY LẠI WORKER</h2>";

$stmt = $pdo->query("
    UPDATE scheduled_posts 
    SET status = 'pending', retry_count = 0, error_msg = NULL 
    WHERE post_type LIKE 'Buffer%' 
      AND (error_msg LIKE '%notification scheduling%' OR error_msg LIKE '%personal profile%')
");

$affected = $stmt->rowCount();
echo "<p>Số bài Buffer Instagram bị lỗi đã được đưa về PENDING: <strong>{$affected}</strong></p>";

// Kích hoạt worker
if (file_exists(__DIR__ . '/cron/start_publish.php')) {
    ob_start();
    include __DIR__ . '/cron/start_publish.php';
    $out = ob_get_clean();
    echo "<pre style='background:#1e293b;color:#38bdf8;padding:15px;border-radius:8px;'>" . htmlspecialchars($out) . "</pre>";
}
?>
