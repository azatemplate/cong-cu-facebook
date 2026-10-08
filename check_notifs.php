<?php
require_once __DIR__ . '/includes/db.php';
$stmt = $pdo->query("SELECT type, COUNT(*) as cnt FROM page_notifications GROUP BY type");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== THỐNG KÊ THÔNG BÁO ===\n";
foreach ($res as $r) {
    echo $r['type'] . ": " . $r['cnt'] . "\n";
}
