<?php
require 'includes/db.php';
try {
    $stmt = $pdo->query("SELECT sp.page_id, sp.post_type, yt1.channel_title as yt1_title, yt2.channel_title as yt2_title, bc.channel_name as bc_name 
        FROM scheduled_posts sp 
        LEFT JOIN youtube_channels yt1 ON sp.page_id = yt1.channel_id 
        LEFT JOIN youtube_channels yt2 ON sp.page_id = yt2.id 
        LEFT JOIN buffer_channels bc ON sp.page_id = bc.channel_id 
        WHERE sp.post_type IN ('YouTube', 'Buffer Facebook Page') 
        ORDER BY sp.id DESC LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "ERROR WITHOUT CAST: " . $e->getMessage() . "\n";
}

try {
    $stmt = $pdo->query("SELECT sp.page_id, sp.post_type, yt1.channel_title as yt1_title, yt2.channel_title as yt2_title, bc.channel_name as bc_name 
        FROM scheduled_posts sp 
        LEFT JOIN youtube_channels yt1 ON sp.page_id = CAST(yt1.channel_id AS CHAR) 
        LEFT JOIN youtube_channels yt2 ON sp.page_id = CAST(yt2.id AS CHAR) 
        LEFT JOIN buffer_channels bc ON sp.page_id = CAST(bc.channel_id AS CHAR) 
        WHERE sp.post_type IN ('YouTube', 'Buffer Facebook Page') 
        ORDER BY sp.id DESC LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "ERROR WITH CAST: " . $e->getMessage() . "\n";
}
