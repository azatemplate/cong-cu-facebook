<?php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== CHECKING PENDING BUFFER POSTS ===\n";
$stmt = $pdo->query("
    SELECT sp.id, sp.account_id, sp.page_id, sp.post_type, sp.status, sp.scheduled_time, sp.created_at, bc.buffer_account_id, bc.channel_name, ba.id AS ba_id
    FROM scheduled_posts sp
    LEFT JOIN buffer_channels bc ON sp.page_id = bc.channel_id
    LEFT JOIN buffer_accounts ba ON bc.buffer_account_id = ba.id
    WHERE sp.post_type LIKE 'Buffer%'
    ORDER BY sp.id DESC
    LIMIT 20
");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

print_r($posts);

echo "\n=== CHECKING BUFFER CHANNELS ===\n";
$stmt2 = $pdo->query("SELECT id, account_id, buffer_account_id, channel_id, channel_name, service FROM buffer_channels ORDER BY id DESC LIMIT 20");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
