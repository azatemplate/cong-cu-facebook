<?php
require 'includes/db.php';
$stmt = $pdo->query("SELECT id, page_id, post_type, status, scheduled_time, retry_count, error_msg FROM scheduled_posts WHERE status = 'pending' ORDER BY id DESC LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
