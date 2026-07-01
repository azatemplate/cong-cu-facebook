<?php
// kill_zombies.php
header('Content-Type: text/plain; charset=utf-8');

echo "=== KILLING ZOMBIE PROCESSES ON VPS ===\n\n";

$zombie_pids = [
    1201475, 1406734, 1407183, 1917331, 1918499, 
    2178028, 2184787, 2186555, 2453140, 2703922, 
    2705372, 2707016
];

foreach ($zombie_pids as $pid) {
    echo "Killing PID $pid... ";
    $output = [];
    $retval = -1;
    @exec("kill -9 $pid 2>&1", $output, $retval);
    if ($retval === 0) {
        echo "SUCCESS\n";
    } else {
        echo "FAILED (Code: $retval, Output: " . implode(" ", $output) . ")\n";
    }
}

echo "\nDone. Please run debug_yt_worker.php again to verify they are gone.\n";
