<?php
// debug_columns.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== SHOW CREATE TABLE zalo_customers ===\n";
try {
    $stmt = $pdo->query("SHOW CREATE TABLE zalo_customers");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Table'] . "\n\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "=== SHOW CREATE TABLE fb_customers ===\n";
try {
    $stmt = $pdo->query("SHOW CREATE TABLE fb_customers");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Table'] . "\n\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
