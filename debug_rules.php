<?php
// debug_rules.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== BOT CHAT RULES ===\n";
$stmt = $pdo->query("SELECT * FROM bot_chat_rules");
$rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rules as $r) {
    echo "ID: {$r['id']} | Type: {$r['rule_type']} | Active: {$r['is_active']} | Keywords: {$r['keywords']}\n";
    echo "Message:\n{$r['message']}\n";
    echo "--------------------------------------------------\n";
}
