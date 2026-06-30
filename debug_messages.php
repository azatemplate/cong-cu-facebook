<?php
// debug_messages.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$sender_id = '2658920088469387223';

echo "=== ZALO MESSAGES CONVERSATION ===\n";
try {
    $stmt = $pdo->prepare("SELECT * FROM zalo_messages WHERE sender_id = ?");
    $stmt->execute([$sender_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        print_r($row);
    } else {
        echo "No conversation row in zalo_messages.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
