<?php
$raw_page_input = "1080582245129467";
$user_id_lock = "123";

$is_campaign_run = false;
$campaign_id = null;
$target_page_ids = [];

if (strpos($raw_page_input, 'camp_') === 0) {
    $is_campaign_run = true;
    $campaign_id = substr($raw_page_input, 5);
} else {
    $target_page_ids = array_filter(array_map('trim', explode(',', $raw_page_input)));
}

$has_retry_count = true;
$retry_clause = $has_retry_count
    ? "OR (sp.status = 'failed' AND (sp.retry_count IS NULL OR sp.retry_count < COALESCE(sa.max_retries, 3)))"
    : '';

$placeholders = implode(',', array_fill(0, count($target_page_ids), '?'));
$params = $target_page_ids;
$post_type_filter = "";
$account_filter = "";

if (!empty($user_id_lock)) {
    if (strpos($user_id_lock, 'yt_chan_') === 0) {
    } elseif (strpos($user_id_lock, 'yt_') === 0) {
    } elseif (strpos($user_id_lock, 'buf_acc_') === 0) {
    } elseif (strpos($user_id_lock, 'buf_') === 0) {
    } elseif (strpos($user_id_lock, 'tt_') === 0) {
    } elseif (strpos($user_id_lock, 'ig_') === 0) {
    } else {
        $post_type_filter = "AND sp.post_type NOT LIKE 'Buffer%' AND sp.post_type != 'YouTube' AND sp.post_type != 'TikTok' AND sp.post_type NOT LIKE 'Instagram%' ";
    }
}

if ($is_campaign_run) {
    $sql = "
        SELECT sp.*, sa.max_retries AS sa_max_retries, sa.retry_interval_minutes AS sa_retry_interval, sa.post_delay_seconds AS sa_delay
        FROM scheduled_posts sp 
        LEFT JOIN system_accounts sa ON sp.account_id = sa.id 
        WHERE (sp.status = 'pending' $retry_clause) 
          AND sp.scheduled_time <= NOW() 
          AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
          AND sp.campaign_id = ?
        ORDER BY sp.scheduled_time ASC
        LIMIT 5
    ";
    $params = [$campaign_id]; // Ghi đè params
} else {
    $sql = "
        SELECT sp.*, sa.max_retries AS sa_max_retries, sa.retry_interval_minutes AS sa_retry_interval, sa.post_delay_seconds AS sa_delay
        FROM scheduled_posts sp 
        LEFT JOIN system_accounts sa ON sp.account_id = sa.id 
        LEFT JOIN buffer_channels bc ON sp.page_id = bc.channel_id
        WHERE (sp.status = 'pending' $retry_clause) 
          AND sp.scheduled_time <= NOW() 
          AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
          $post_type_filter
          $account_filter
          AND sp.page_id IN ($placeholders)
        ORDER BY sp.scheduled_time ASC
        LIMIT 5
    ";
}

echo $sql;
print_r($params);
