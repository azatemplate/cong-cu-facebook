<?php
require_once __DIR__ . '/includes/db.php';
$stmt = $pdo->query("SELECT sp.id, sp.page_id, sp.post_type, bc.channel_name FROM scheduled_posts sp LEFT JOIN buffer_channels bc ON sp.page_id = bc.channel_id WHERE sp.post_type LIKE 'Buffer%' LIMIT 5");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
