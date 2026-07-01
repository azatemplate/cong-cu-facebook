<?php
// show_processlist.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== SHOW PROCESSLIST ===\n\n";

try {
    $stmt = $pdo->query("SHOW PROCESSLIST");
    $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($processes as $proc) {
        echo sprintf(
            "Id: %d | User: %s | Host: %s | DB: %s | Command: %s | Time: %d | State: %s | Info: %s\n",
            $proc['Id'],
            $proc['User'],
            $proc['Host'],
            $proc['db'],
            $proc['Command'],
            $proc['Time'],
            $proc['State'],
            $proc['Info']
        );
        echo str_repeat("-", 100) . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
