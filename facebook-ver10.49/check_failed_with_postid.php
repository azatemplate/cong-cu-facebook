<?php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/html; charset=utf-8');

$stmt = $pdo->query("
    SELECT id, post_type, status, fb_post_id, error_msg, scheduled_time
    FROM scheduled_posts
    WHERE status = 'failed' AND fb_post_id IS NOT NULL AND fb_post_id != ''
    ORDER BY id DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Các bài có fb_post_id nhưng status = failed: " . count($rows) . "</h2>";
echo "<pre>";
print_r($rows);
echo "</pre>";
?>
