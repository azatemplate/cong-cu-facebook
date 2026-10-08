<?php
require_once __DIR__ . '/../includes/db.php';
$pdo->query("DELETE FROM system_settings WHERE setting_key = 'last_snapshot_time'");
echo "Success! last_snapshot_time cleared.";
