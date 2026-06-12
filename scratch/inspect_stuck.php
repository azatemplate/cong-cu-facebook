<?php
require_once __DIR__ . '/../includes/db.php';

echo "--- SYSTEM SETTINGS --- \n";
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['setting_key']}: {$row['setting_value']}\n";
}

echo "\n--- SYSTEM ACCOUNTS --- \n";
$stmt = $pdo->query("SELECT id, username, role, max_retries, retry_interval_minutes FROM system_accounts");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']} | Username: {$row['username']} | Role: {$row['role']} | Max Retries: " . ($row['max_retries'] ?? 'NULL') . " | Retry Interval: " . ($row['retry_interval_minutes'] ?? 'NULL') . "\n";
}

echo "\n--- STUCK POSTS --- \n";
$stmt = $pdo->query("SELECT sp.id, sp.account_id, sp.page_id, sp.status, sp.retry_count, sp.scheduled_time, sp.error_msg FROM scheduled_posts sp WHERE sp.status = 'failed'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']} | Account ID: {$row['account_id']} | Page ID: {$row['page_id']} | Status: {$row['status']} | Retry: {$row['retry_count']} | Scheduled Time: {$row['scheduled_time']} | Error: {$row['error_msg']}\n";
}
