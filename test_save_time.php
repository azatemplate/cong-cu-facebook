<?php
// test_save_time.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== DATABASE UPDATE BENCHMARK ===\n\n";

$account_id = 1; // Default or first account id
try {
    $stmt_find = $pdo->query("SELECT id FROM system_accounts LIMIT 1");
    $account_id = $stmt_find->fetchColumn() ?: 1;
    echo "Using system_account ID: $account_id\n";
} catch (Exception $e) {
    echo "Error finding account: " . $e->getMessage() . "\n";
}

$start = microtime(true);

try {
    // Select first
    $stmt_acc = $pdo->prepare("SELECT * FROM system_accounts WHERE id = ?");
    $stmt_acc->execute([$account_id]);
    $old_acc = $stmt_acc->fetch(PDO::FETCH_ASSOC);
    $time_select = microtime(true) - $start;
    echo sprintf("SELECT time: %.4f seconds\n", $time_select);
    
    // Update
    $start_update = microtime(true);
    $stmt = $pdo->prepare("UPDATE system_accounts SET auto_reply_enabled = auto_reply_enabled WHERE id = ?");
    $stmt->execute([$account_id]);
    $time_update = microtime(true) - $start_update;
    echo sprintf("UPDATE time: %.4f seconds\n", $time_update);
    
    $total = microtime(true) - $start;
    echo sprintf("Total execution time: %.4f seconds\n", $total);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
