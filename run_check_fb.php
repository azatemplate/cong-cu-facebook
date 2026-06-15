<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== RUNNING FB DIAGNOSTIC ===\n\n";

require_once __DIR__ . '/check_fb_data.php';

echo "\n=== DIAGNOSTIC COMPLETED ===";
?>
