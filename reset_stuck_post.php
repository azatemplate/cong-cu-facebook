<?php
// reset_stuck_post.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$post_id = 981794;

echo "=== RESETTING STUCK POST ID: $post_id ===\n\n";

try {
    $stmt = $pdo->prepare("UPDATE scheduled_posts SET status = 'pending', error_msg = NULL WHERE id = ? AND status = 'processing'");
    $stmt->execute([$post_id]);
    $affected = $stmt->rowCount();
    
    if ($affected > 0) {
        echo "Successfully reset post ID $post_id from 'processing' to 'pending'.\n";
        echo "The cron worker will pick it up on the next run (within 1 minute).\n";
    } else {
        echo "No changes made. The post was either not in 'processing' status, or already reset.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
