<?php
header('Content-Type: text/plain; charset=utf-8');
$logFile = __DIR__ . '/zalo_webhook_debug.log';
if (file_exists($logFile)) {
    echo file_get_contents($logFile);
} else {
    echo "Log file not found.";
}
