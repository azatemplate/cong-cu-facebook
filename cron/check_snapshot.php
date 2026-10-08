<?php
require_once __DIR__ . '/includes/db.php';
$stmt = $pdo->query("SELECT snapshot_date, total_reach FROM dashboard_snapshots ORDER BY snapshot_date DESC LIMIT 5");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Snapshots in DB:\n";
print_r($res);

$stmt2 = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_snapshot_time'");
echo "\nlast_snapshot_time = " . $stmt2->fetchColumn() . "\n";
