<?php
// debug_ai.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== AI USAGE LOGS ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM ai_usage_logs ORDER BY id DESC LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "ID: {$r['id']} | Provider: {$r['provider']} | Feature: {$r['feature']} | Status: {$r['status']} | Created: {$r['created_at']}\n";
        echo "Error: {$r['error_message']}\n";
        echo "--------------------------------------------------\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
