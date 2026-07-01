<?php
// debug_yt_worker.php
header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNOSING RUNNING PROCESSES ===\n\n";

$output = [];
@exec('ps aux | grep php', $output);
echo implode("\n", $output) . "\n\n";

echo "=== DIAGNOSING ACTIVE LOCKS ===\n\n";
$locks = glob(__DIR__ . '/locks/*.lock');
foreach ($locks as $lock) {
    $content = file_get_contents($lock);
    $time = is_numeric(trim($content)) ? date('Y-m-d H:i:s', intval(trim($content))) : trim($content);
    echo basename($lock) . " -> Contents: " . trim($content) . " (Time: $time)\n";
}
