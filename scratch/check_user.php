<?php
require_once __DIR__ . '/../includes/db.php';

$sender_id = '5681176173809790182';

echo "=== CUSTOMER DATA ===\n";
$stmt = $pdo->prepare("SELECT * FROM zalo_customers WHERE sender_id = ?");
$stmt->execute([$sender_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($customer);

echo "\n=== PAGE NOTIFICATIONS (SMS LOGS) ===\n";
$stmt2 = $pdo->prepare("SELECT * FROM page_notifications WHERE sender_id = ? ORDER BY id DESC LIMIT 20");
$stmt2->execute([$sender_id]);
$notifs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
print_r($notifs);

echo "\n=== ZALO MESSAGES ===\n";
$stmt3 = $pdo->prepare("SELECT * FROM zalo_messages WHERE sender_id = ? ORDER BY updated_time DESC");
$stmt3->execute([$sender_id]);
$msgs = $stmt3->fetchAll(PDO::FETCH_ASSOC);
print_r($msgs);
?>
