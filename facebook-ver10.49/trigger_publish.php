<?php
// trigger_publish.php
header('Content-Type: text/plain; charset=utf-8');

echo "=== RUNNING start_publish.php SYNCHRONOUSLY ===\n";

// Disable output buffering if any
if (ob_get_level()) ob_end_clean();

require_once __DIR__ . '/cron/start_publish.php';

echo "\n=== FINISHED ===\n";
exit;
?>
