<?php
session_start();
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== SESSION INFO ===\n";
$aid = $_SESSION['account_id'] ?? 0;
echo "account_id: $aid\n\n";

echo "=== account_id của user_id 20, 33, 41 ===\n";
$stmt = $pdo->query("SELECT id, account_id, name FROM users WHERE id IN (20, 33, 41)");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "user_id={$u['id']} | account_id={$u['account_id']} | name={$u['name']}\n";
}

echo "\n=== Pages thuộc account_id=$aid ===\n";
$stmt2 = $pdo->prepare("SELECT p.page_id, p.name FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = ? LIMIT 5");
$stmt2->execute([$aid]);
$rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "Tổng: " . count($rows) . "+ pages\n";
foreach ($rows as $r) {
    echo "• {$r['page_id']} - {$r['name']}\n";
}

echo "\n=== Notifications (10 mới nhất) ===\n";
$stmt3 = $pdo->query("SELECT n.id, n.page_id, n.type, n.snippet, n.is_read FROM page_notifications ORDER BY n.id DESC LIMIT 10");
foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "[{$r['id']}] {$r['type']} | page={$r['page_id']} | read={$r['is_read']} | {$r['snippet']}\n";
}
