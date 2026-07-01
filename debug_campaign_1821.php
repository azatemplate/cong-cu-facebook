<?php
// debug_campaign_1821.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$campaign_id = 1821;

echo "=== DIAGNOSING CAMPAIGN ID: $campaign_id ===\n\n";

try {
    // 1. Fetch scheduled posts for this campaign
    $stmt = $pdo->prepare("
        SELECT sp.id, sp.page_id, sp.post_type, sp.status, sp.scheduled_time, sp.updated_at, sp.error_msg, sp.retry_count, sp.media_path
        FROM scheduled_posts sp
        WHERE sp.campaign_id = ?
        ORDER BY sp.id ASC
    ");
    $stmt->execute([$campaign_id]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($posts) . " posts for Campaign $campaign_id:\n";
    echo str_repeat("-", 100) . "\n";

    foreach ($posts as $post) {
        // Fetch page/channel name
        $name = 'Unknown';
        if ($post['post_type'] === 'YouTube') {
            $p_stmt = $pdo->prepare("SELECT name FROM youtube_channels WHERE id = ?");
            $p_stmt->execute([$post['page_id']]);
            $name = $p_stmt->fetchColumn() ?: 'Unknown';
        } else {
            $p_stmt = $pdo->prepare("SELECT name FROM pages WHERE page_id = ?");
            $p_stmt->execute([$post['page_id']]);
            $name = $p_stmt->fetchColumn() ?: 'Unknown';
        }

        echo sprintf(
            "ID: %d | Target Channel: %s (Page/Channel ID in DB: %s)\nType: %s | Status: %s | Scheduled Time: %s | Updated At: %s\nRetry Count: %d | Error Msg: %s\nMedia Path: %s\n",
            $post['id'],
            $name,
            $post['page_id'],
            $post['post_type'],
            $post['status'],
            $post['scheduled_time'],
            $post['updated_at'],
            $post['retry_count'],
            $post['error_msg'] ?: 'None',
            $post['media_path']
        );
        echo str_repeat("-", 100) . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
