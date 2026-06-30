<?php
// scratch/inspect_camp_1748.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

echo "=== INSPECTING CAMPAIGN 1748 ===\n";
$stmt = $pdo->prepare("SELECT id, page_id, account_id, post_type, content, media_path, status, error_msg, scheduled_time, retry_count, limit_retries, updated_at FROM scheduled_posts WHERE campaign_id = 1748");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($posts)) {
    echo "No posts found for Campaign 1748.\n";
    exit;
}

foreach ($posts as $post) {
    echo "ID: {$post['id']} | Page ID: {$post['page_id']} | Account ID: {$post['account_id']} | Type: {$post['post_type']} | Status: {$post['status']} | Time: {$post['scheduled_time']} | Media: {$post['media_path']} | Retry: {$post['retry_count']}/{$post['limit_retries']} | Updated At: {$post['updated_at']} | Error: {$post['error_msg']}\n";
}

echo "\n--- checking youtube channels connected ---\n";
foreach (array_unique(array_column($posts, 'page_id')) as $page_id) {
    if (!$page_id) continue;
    $yt_stmt = $pdo->prepare("SELECT id, account_id, channel_id, channel_title, has_token FROM (SELECT yc.*, (refresh_token IS NOT NULL AND refresh_token != '') as has_token FROM youtube_channels yc) t WHERE id = ?");
    $yt_stmt->execute([$page_id]);
    $yt = $yt_stmt->fetch(PDO::FETCH_ASSOC);
    if ($yt) {
        echo "Channel ID in DB: {$yt['id']} | Google Channel ID: {$yt['channel_id']} | Title: {$yt['channel_title']} | Account ID: {$yt['account_id']} | Has Token: " . ($yt['has_token'] ? 'YES' : 'NO') . "\n";
    } else {
        echo "Channel ID in DB: {$page_id} -> NOT FOUND in youtube_channels table!\n";
    }
}
