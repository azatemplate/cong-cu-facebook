<?php
// add_db_indexes.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

echo "=== ADDING MISSING DATABASE INDEXES ===\n\n";

// Helper function to safely add index
function safely_add_index($pdo, $index_name, $column_name) {
    try {
        echo "Adding index $index_name on page_notifications($column_name)...\n";
        $pdo->exec("ALTER TABLE page_notifications ADD INDEX $index_name ($column_name)");
        echo "Index $index_name added successfully.\n\n";
    } catch (Exception $e) {
        // If index already exists, ignore it
        if (strpos($e->getMessage(), 'Duplicate key name') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "Index $index_name already exists.\n\n";
        } else {
            echo "Error adding $index_name: " . $e->getMessage() . "\n\n";
        }
    }
}

safely_add_index($pdo, 'idx_comment_id', 'comment_id');
safely_add_index($pdo, 'idx_post_id', 'post_id');
safely_add_index($pdo, 'idx_conversation_id', 'conversation_id');

echo "ALL INDEXES PROCESSED!\n";
exit;
?>
