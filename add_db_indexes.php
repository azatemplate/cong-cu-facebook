<?php
// add_db_indexes.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

echo "=== ADDING MISSING DATABASE INDEXES ===\n\n";

try {
    // 1. Add index on comment_id
    echo "Adding index idx_comment_id on page_notifications(comment_id)...\n";
    $pdo->exec("ALTER TABLE page_notifications ADD INDEX IF NOT EXISTS idx_comment_id (comment_id)");
    echo "Index idx_comment_id added or already exists.\n\n";

    // 2. Add index on post_id
    echo "Adding index idx_post_id on page_notifications(post_id)...\n";
    $pdo->exec("ALTER TABLE page_notifications ADD INDEX IF NOT EXISTS idx_post_id (post_id)");
    echo "Index idx_post_id added or already exists.\n\n";

    // 3. Add index on conversation_id
    echo "Adding index idx_conversation_id on page_notifications(conversation_id)...\n";
    $pdo->exec("ALTER TABLE page_notifications ADD INDEX IF NOT EXISTS idx_conversation_id (conversation_id)");
    echo "Index idx_conversation_id added or already exists.\n\n";

    echo "ALL INDEXES VERIFIED/ADDED SUCCESSFULLY!\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
