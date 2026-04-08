<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$stmt = $pdo->query("SELECT id, page_id, fb_post_id, comment_threshold_views, comment_threshold_likes, comment_threshold_comments, comment_status FROM scheduled_posts WHERE comment_mode = 'insights' AND comment_status = 'waiting_insights' ORDER BY id DESC LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    echo "====================\n";
    echo "Testing DB fb_post_id: " . $row['fb_post_id'] . "\n";
    
    $p_stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $p_stmt->execute([$row['page_id']]);
    $page = $p_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page) { echo "No token.\n"; continue; }
    $token = decryptData($page['access_token']);
    
    // Test 1: Video ID
    echo "Test 1 [VIDEO ID]: " . $row['fb_post_id'] . "\n";
    $r1 = fb_api_request($row['fb_post_id'], ['fields' => 'likes.summary(true),comments.summary(true)', 'access_token' => $token]);
    echo "Likes: " . ($r1['data']['likes']['summary']['total_count'] ?? 'error') . " | Comments: " . ($r1['data']['comments']['summary']['total_count'] ?? 'error') . "\n";

    // Test 2: PageID_VideoID (Post ID format)
    $post_id_format = $row['page_id'] . "_" . $row['fb_post_id'];
    echo "Test 2 [POST ID]: " . $post_id_format . "\n";
    $r2 = fb_api_request($post_id_format, ['fields' => 'likes.summary(true),reactions.summary(true),comments.summary(true)', 'access_token' => $token]);
    
    if (isset($r2['data']['reactions'])) {
        echo "Reactions: " . $r2['data']['reactions']['summary']['total_count'] . "\n";
    } elseif (isset($r2['data']['likes'])) {
        echo "Likes: " . $r2['data']['likes']['summary']['total_count'] . "\n";
    } else {
        echo "Post format error: " . ($r2['data']['error']['message'] ?? 'Unknown') . "\n";
    }
}
