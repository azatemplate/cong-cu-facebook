<?php
// check_db_labels.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

echo "=== CHECKING CONVERSATION LABELS IN DATABASE ===\n\n";

try {
    // 1. Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'conversation_labels'");
    if (!$stmt->fetch()) {
        exit("Table 'conversation_labels' does not exist.\n");
    }
    
    // 2. Count total rows
    $total = $pdo->query("SELECT COUNT(*) FROM conversation_labels")->fetchColumn();
    echo "Total rows in conversation_labels: $total\n\n";
    
    // 3. Group by label_name
    $stmt_group = $pdo->query("SELECT label_name, COUNT(*) as qty FROM conversation_labels GROUP BY label_name ORDER BY qty DESC");
    $groups = $stmt_group->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Labels summary:\n";
    foreach ($groups as $g) {
        echo " - Label: {$g['label_name']} | Count: {$g['qty']}\n";
    }
    
    // 4. Show some sample rows
    echo "\nSample rows:\n";
    $samples = $pdo->query("SELECT * FROM conversation_labels LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    print_r($samples);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
