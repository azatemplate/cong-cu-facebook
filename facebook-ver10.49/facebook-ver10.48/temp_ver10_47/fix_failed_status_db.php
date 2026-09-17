<?php
// fix_failed_status_db.php - Fix database status for posts with valid fb_post_id
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/html; charset=utf-8');

$stmt = $pdo->query("
    UPDATE scheduled_posts 
    SET status = 'published', error_msg = NULL 
    WHERE status = 'failed' 
      AND fb_post_id IS NOT NULL 
      AND fb_post_id != ''
");

$affected = $stmt->rowCount();
echo "<h2>✅ ĐÃ ĐỒNG BỘ TRẠNG THÁI BÀI ĐÃ ĐĂNG THÀNH CÔNG!</h2>";
echo "<p>Số bài có mã Post ID nhưng bị ghi nhầm 'failed' đã được sửa thành 'published': <strong>{$affected}</strong></p>";
?>
