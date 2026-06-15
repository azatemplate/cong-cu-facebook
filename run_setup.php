<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== RUNNING DB SETUP ===\n\n";

require_once __DIR__ . '/setup_live_chat.php';

echo "\n=== SETUP COMPLETED ===";
?>
