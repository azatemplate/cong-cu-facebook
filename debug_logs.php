<?php
// debug_logs.php
header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/zalo_webhook_debug.log';

if (!file_exists($logFile)) {
    echo "Log file zalo_webhook_debug.log does not exist.\n";
    exit;
}

$lines = file($logFile);
$lastLines = array_slice($lines, -100);

echo "=== LAST 100 LINES OF zalo_webhook_debug.log ===\n";
foreach ($lastLines as $line) {
    echo $line;
}
