<?php
// includes/instagram_api.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fb_api.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Sync Instagram Business Accounts linked to connected Facebook Pages
 */
function sync_instagram_accounts($account_id) {
    global $pdo;
    $synced = 0;
    try {
        // Fetch pages for this system account
        $stmt = $pdo->prepare("
            SELECT p.page_id, p.access_token, p.name as page_name
            FROM pages p
            JOIN users u ON p.user_id = u.id
            WHERE u.account_id = ?
        ");
        $stmt->execute([$account_id]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pages as $pg) {
            $page_id = $pg['page_id'];
            $token = $pg['access_token'];

            // Query page for instagram_business_account
            $url = FB_API_BASE . $page_id . "?fields=instagram_business_account&access_token=" . urlencode($token);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            apply_proxy_to_curl($ch, $token);
            fb_curl_setssl($ch);
            $res = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($res, true);
            if (!empty($data['instagram_business_account']['id'])) {
                $ig_id = $data['instagram_business_account']['id'];

                // Query Instagram profile details
                $ig_url = FB_API_BASE . $ig_id . "?fields=id,username,name,profile_picture_url,followers_count&access_token=" . urlencode($token);
                $ch2 = curl_init($ig_url);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                apply_proxy_to_curl($ch2, $token);
                fb_curl_setssl($ch2);
                $res2 = curl_exec($ch2);
                curl_close($ch2);

                $ig_data = json_decode($res2, true);
                if (!empty($ig_data['username'])) {
                    $username = $ig_data['username'];
                    $name = $ig_data['name'] ?? $username;
                    $avatar = $ig_data['profile_picture_url'] ?? '';
                    $followers = (int)($ig_data['followers_count'] ?? 0);

                    // Upsert into instagram_accounts
                    $up_stmt = $pdo->prepare("
                        INSERT INTO instagram_accounts (account_id, ig_user_id, fb_page_id, username, name, avatar, followers_count, access_token)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            username = VALUES(username),
                            name = VALUES(name),
                            avatar = VALUES(avatar),
                            followers_count = VALUES(followers_count),
                            access_token = VALUES(access_token)
                    ");
                    $up_stmt->execute([$account_id, $ig_id, $page_id, $username, $name, $avatar, $followers, $token]);
                    $synced++;
                }
            }
        }
    } catch (Exception $e) {
        error_log("sync_instagram_accounts error: " . $e->getMessage());
    }
    return $synced;
}

/**
 * Get connected Instagram accounts for user
 */
function get_instagram_accounts($account_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM instagram_accounts WHERE account_id = ? ORDER BY created_at DESC");
        $stmt->execute([$account_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Internal Helper: Poll Media Container status until FINISHED or error
 */
function poll_instagram_container_status($container_id, $access_token, $max_wait_seconds = 60) {
    $start = time();
    while (time() - $start < $max_wait_seconds) {
        $url = FB_API_BASE . $container_id . "?fields=status_code,status&access_token=" . urlencode($access_token);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        apply_proxy_to_curl($ch, $access_token);
        fb_curl_setssl($ch);
        $res = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res, true);
        $status = $data['status_code'] ?? '';
        if ($status === 'FINISHED') {
            return ['status' => 'success'];
        } elseif ($status === 'ERROR') {
            return ['status' => 'error', 'msg' => $data['status'] ?? 'Lỗi xử lý Container Media'];
        }
        sleep(2);
    }
    return ['status' => 'error', 'msg' => 'Hết thời gian chờ xử lý Video Instagram Container (Timeout)'];
}

/**
 * Internal Helper: Publish Media Container
 */
function publish_instagram_container($ig_user_id, $container_id, $access_token) {
    $url = FB_API_BASE . $ig_user_id . "/media_publish";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'creation_id' => $container_id,
            'access_token' => $access_token
        ])
    ]);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL: ' . $err];
    $data = json_decode($res, true);
    if (!empty($data['id'])) {
        return ['status' => 'success', 'id' => $data['id']];
    }
    $msg = $data['error']['message'] ?? 'Lỗi không xác định khi xuất bản bài Instagram';
    return ['status' => 'error', 'msg' => $msg];
}

/**
 * Post Single Photo to Instagram Feed
 */
function post_instagram_photo($ig_user_id, $access_token, $image_url, $caption = '') {
    $url = FB_API_BASE . $ig_user_id . "/media";
    $params = [
        'image_url' => $image_url,
        'caption' => $caption,
        'access_token' => $access_token
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params)
    ]);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL: ' . $err];
    $data = json_decode($res, true);
    if (empty($data['id'])) {
        return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Không tạo được Photo Container'];
    }

    return publish_instagram_container($ig_user_id, $data['id'], $access_token);
}

/**
 * Post Video / Reels to Instagram
 */
function post_instagram_reels($ig_user_id, $access_token, $video_url, $caption = '', $cover_url = '') {
    $url = FB_API_BASE . $ig_user_id . "/media";
    $params = [
        'media_type' => 'REELS',
        'video_url' => $video_url,
        'caption' => $caption,
        'share_to_feed' => 'true',
        'access_token' => $access_token
    ];
    if (!empty($cover_url)) {
        $params['cover_url'] = $cover_url;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params)
    ]);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL: ' . $err];
    $data = json_decode($res, true);
    if (empty($data['id'])) {
        return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Không tạo được Reels Container'];
    }

    $container_id = $data['id'];
    $poll = poll_instagram_container_status($container_id, $access_token, 90);
    if ($poll['status'] !== 'success') {
        return $poll;
    }

    return publish_instagram_container($ig_user_id, $container_id, $access_token);
}

/**
 * Post Story to Instagram (Image or Video)
 */
function post_instagram_story($ig_user_id, $access_token, $media_url, $is_video = false) {
    $url = FB_API_BASE . $ig_user_id . "/media";
    $params = [
        'media_type' => 'STORIES',
        'access_token' => $access_token
    ];
    if ($is_video) {
        $params['video_url'] = $media_url;
    } else {
        $params['image_url'] = $media_url;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params)
    ]);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL: ' . $err];
    $data = json_decode($res, true);
    if (empty($data['id'])) {
        return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Không tạo được Story Container'];
    }

    $container_id = $data['id'];
    if ($is_video) {
        $poll = poll_instagram_container_status($container_id, $access_token, 60);
        if ($poll['status'] !== 'success') return $poll;
    }

    return publish_instagram_container($ig_user_id, $container_id, $access_token);
}

/**
 * Fetch Instagram Published Media List
 */
function get_instagram_media_list($ig_user_id, $access_token, $limit = 25) {
    $url = FB_API_BASE . $ig_user_id . "/media?fields=id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count&limit={$limit}&access_token=" . urlencode($access_token);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    return $data['data'] ?? [];
}

/**
 * Get Media Insights (Impressions, Reach, Engagement, Saved, Shares, Plays)
 */
function get_instagram_media_insights($ig_media_id, $access_token) {
    $metrics = "engagement,impressions,reach,saved";
    $url = FB_API_BASE . $ig_media_id . "/insights?metric={$metrics}&access_token=" . urlencode($access_token);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    $result = [];
    if (!empty($data['data'])) {
        foreach ($data['data'] as $item) {
            $result[$item['name']] = $item['values'][0]['value'] ?? 0;
        }
    }
    return $result;
}

/**
 * Delete Instagram Post / Media
 */
function delete_instagram_media($ig_media_id, $access_token) {
    $url = FB_API_BASE . $ig_media_id . "?access_token=" . urlencode($access_token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE'
    ]);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL: ' . $err];
    $data = json_decode($res, true);
    if (!empty($data['success'])) {
        return ['status' => 'success'];
    }
    return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Không xóa được bài viết Instagram'];
}

/**
 * Get Instagram Media Comments
 */
function get_instagram_comments($ig_media_id, $access_token) {
    $url = FB_API_BASE . $ig_media_id . "/comments?fields=id,text,username,timestamp,like_count&access_token=" . urlencode($access_token);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    return $data['data'] ?? [];
}

/**
 * Reply to an Instagram Comment
 */
function reply_instagram_comment($ig_comment_id, $access_token, $message) {
    $url = FB_API_BASE . $ig_comment_id . "/replies";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'message' => $message,
            'access_token' => $access_token
        ])
    ]);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL: ' . $err];
    $data = json_decode($res, true);
    if (!empty($data['id'])) {
        return ['status' => 'success', 'id' => $data['id']];
    }
    return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Lỗi không trả lời được bình luận'];
}

/**
 * Delete Instagram Comment
 */
function delete_instagram_comment($ig_comment_id, $access_token) {
    $url = FB_API_BASE . $ig_comment_id . "?access_token=" . urlencode($access_token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE'
    ]);
    apply_proxy_to_curl($ch, $access_token);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL: ' . $err];
    $data = json_decode($res, true);
    if (!empty($data['success'])) {
        return ['status' => 'success'];
    }
    return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Không xóa được bình luận'];
}
