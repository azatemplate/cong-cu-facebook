<?php
// check_exec.php
header('Content-Type: text/plain; charset=utf-8');

echo "=== SERVER EXEC ENVIRONMENT CHECK ===\n\n";

$exec_enabled = function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false;
echo "1. exec() function enabled: " . ($exec_enabled ? "YES" : "NO") . "\n";

$popen_enabled = function_exists('popen') && strpos(ini_get('disable_functions'), 'popen') === false;
echo "2. popen() function enabled: " . ($popen_enabled ? "YES" : "NO") . "\n";

echo "3. disabled_functions: " . ini_get('disable_functions') . "\n";
echo "4. PHP_SAPI: " . PHP_SAPI . "\n";
echo "5. max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "6. memory_limit: " . ini_get('memory_limit') . "\n";
echo "7. open_basedir: " . ini_get('open_basedir') . "\n";

// Test executing a simple CLI command if exec is enabled
if ($exec_enabled) {
    echo "\nTesting exec('php -v'):\n";
    $output = [];
    $retval = -1;
    @exec('php -v', $output, $retval);
    echo "Return value: $retval\n";
    echo "Output:\n" . implode("\n", $output) . "\n";
}
