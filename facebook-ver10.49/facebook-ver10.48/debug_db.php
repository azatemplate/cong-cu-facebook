<?php
require 'includes/db.php';
$data = [];
try {
    $stmt = $pdo->query("SELECT id, page_id, post_type, campaign_id FROM scheduled_posts WHERE post_type IN ('YouTube', 'Buffer Facebook Page') ORDER BY id DESC LIMIT 5");
    $data['scheduled_posts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->query("SELECT id, channel_id, channel_title FROM youtube_channels ORDER BY id DESC LIMIT 5");
    $data['youtube_channels'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $stmt3 = $pdo->query("SELECT id, channel_id, channel_name FROM buffer_channels ORDER BY id DESC LIMIT 5");
    $data['buffer_channels'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    // Test JOIN
    $stmt4 = $pdo->query("
        SELECT sp.id as post_id, sp.page_id, sp.post_type, 
               yt1.channel_title as yt1_title, 
               yt2.channel_title as yt2_title, 
               bc.channel_name as bc_name
        FROM scheduled_posts sp 
        LEFT JOIN youtube_channels yt1 ON CAST(sp.page_id AS BINARY) = CAST(yt1.channel_id AS BINARY)
        LEFT JOIN youtube_channels yt2 ON CAST(sp.page_id AS BINARY) = CAST(yt2.id AS BINARY)
        LEFT JOIN buffer_channels bc ON CAST(sp.page_id AS BINARY) = CAST(bc.channel_id AS BINARY)
        WHERE sp.post_type IN ('YouTube', 'Buffer Facebook Page')
        ORDER BY sp.id DESC LIMIT 5
    ");
    $data['join_result'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);

    // Test simple string match in PHP
    foreach ($data['scheduled_posts'] as &$sp) {
        $sp['php_match'] = null;
        if ($sp['post_type'] === 'YouTube') {
            foreach ($data['youtube_channels'] as $yt) {
                if ($sp['page_id'] == $yt['id'] || $sp['page_id'] === $yt['channel_id']) {
                    $sp['php_match'] = $yt['channel_title'];
                }
            }
        } else {
            foreach ($data['buffer_channels'] as $bc) {
                if ($sp['page_id'] === $bc['channel_id']) {
                    $sp['php_match'] = $bc['channel_name'];
                }
            }
        }
    }

} catch (Exception $e) {
    $data['error'] = $e->getMessage();
}

file_put_contents('debug_db.json', json_encode($data, JSON_PRETTY_PRINT));
echo "Done";
