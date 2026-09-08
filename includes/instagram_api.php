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
    $synced_map = [];

    try {
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

        // 1. Scan User Tokens from users table with Nested Fields (Gets ALL connected IG accounts in 1 HTTP call!)
        $u_stmt = $pdo->prepare("SELECT access_token FROM users WHERE account_id = ? AND access_token IS NOT NULL AND access_token != ''");
        $u_stmt->execute([$account_id]);
        $users = $u_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $usr) {
            $u_token = decryptData($usr['access_token']);
            if (empty($u_token)) continue;

            $me_url = FB_API_BASE . "me/accounts?fields=id,name,access_token,instagram_business_account{id,username,name,profile_picture_url,followers_count}&limit=500&access_token=" . urlencode($u_token);
            $ch = curl_init($me_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            apply_proxy_to_curl($ch, $u_token);
            fb_curl_setssl($ch);
            $res = curl_exec($ch);
            curl_close($ch);

            $me_data = json_decode($res, true);
            if (!empty($me_data['data'])) {
                foreach ($me_data['data'] as $p_item) {
                    if (!empty($p_item['instagram_business_account'])) {
                        $ig_info = $p_item['instagram_business_account'];
                        $ig_id = $ig_info['id'] ?? '';
                        if (empty($ig_id)) continue;

                        $page_id = $p_item['id'];
                        $p_token = $p_item['access_token'] ?? $u_token;
                        $username = $ig_info['username'] ?? '';
                        $name = $ig_info['name'] ?? $username;
                        $avatar = $ig_info['profile_picture_url'] ?? '';
                        $followers = (int)($ig_info['followers_count'] ?? 0);

                        if (!empty($username)) {
                            $up_stmt->execute([$account_id, $ig_id, $page_id, $username, $name, $avatar, $followers, $p_token]);
                            $synced_map[$ig_id] = true;
                        }
                    }
                }
            }
        }

        // 2. Parallel scan for pages in `pages` table using curl_multi in batches of 40
        $stmt = $pdo->prepare("
            SELECT p.page_id, p.access_token
            FROM pages p
            JOIN users u ON p.user_id = u.id
            WHERE u.account_id = ?
        ");
        $stmt->execute([$account_id]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $valid_pages = [];
        foreach ($pages as $pg) {
            $t = decryptData($pg['access_token']);
            if (!empty($t)) {
                $valid_pages[] = [
                    'page_id' => $pg['page_id'],
                    'token' => $t
                ];
            }
        }

        if (!empty($valid_pages)) {
            $chunks = array_chunk($valid_pages, 40);
            foreach ($chunks as $chunk) {
                $mh = curl_multi_init();
                $curl_handles = [];

                foreach ($chunk as $idx => $p_item) {
                    $page_id = $p_item['page_id'];
                    $token = $p_item['token'];
                    // Nested query gets IG info in 1 single HTTP request
                    $url = FB_API_BASE . $page_id . "?fields=instagram_business_account{id,username,name,profile_picture_url,followers_count}&access_token=" . urlencode($token);
                    
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    apply_proxy_to_curl($ch, $token);
                    fb_curl_setssl($ch);

                    curl_multi_add_handle($mh, $ch);
                    $curl_handles[$idx] = [
                        'ch' => $ch,
                        'page_id' => $page_id,
                        'token' => $token
                    ];
                }

                $running = null;
                do {
                    curl_multi_exec($mh, $running);
                    curl_multi_select($mh);
                } while ($running > 0);

                foreach ($curl_handles as $item) {
                    $ch = $item['ch'];
                    $res = curl_multi_getcontent($ch);
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);

                    if (empty($res)) continue;
                    $data = json_decode($res, true);
                    if (!empty($data['instagram_business_account'])) {
                        $ig_info = $data['instagram_business_account'];
                        $ig_id = $ig_info['id'] ?? '';
                        if (empty($ig_id)) continue;

                        $username = $ig_info['username'] ?? '';
                        $name = $ig_info['name'] ?? $username;
                        $avatar = $ig_info['profile_picture_url'] ?? '';
                        $followers = (int)($ig_info['followers_count'] ?? 0);

                        if (!empty($username)) {
                            $up_stmt->execute([$account_id, $ig_id, $item['page_id'], $username, $name, $avatar, $followers, $item['token']]);
                            $synced_map[$ig_id] = true;
                        }
                    }
                }
                curl_multi_close($mh);
            }
        }
    } catch (Exception $e) {
        error_log("sync_instagram_accounts error: " . $e->getMessage());
    }
    return count($synced_map);
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
        $url = FB_API_BASE . $container_id . "?fields=status_code,status,status_code_description&access_token=" . urlencode($access_token);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        apply_proxy_to_curl($ch, $access_token);
        fb_curl_setssl($ch);
        $res = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res, true);
        if (!empty($data['error'])) {
            return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Lỗi kiểm tra Container Meta'];
        }

        $status = $data['status_code'] ?? '';
        if ($status === 'FINISHED') {
            return ['status' => 'success'];
        } elseif ($status === 'ERROR' || $status === 'EXPIRED') {
            $msg = $data['status_code_description'] ?? $data['status'] ?? 'Lỗi xử lý Container Media Instagram';
            return ['status' => 'error', 'msg' => $msg];
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

    $container_id = $data['id'];
    $poll = poll_instagram_container_status($container_id, $access_token, 30);
    if ($poll['status'] !== 'success') {
        return $poll;
    }

    return publish_instagram_container($ig_user_id, $container_id, $access_token);
}

/**
 * Post Carousel (Multiple Photos/Videos) to Instagram
 */
function post_instagram_carousel($ig_user_id, $access_token, $media_items, $caption = '') {
    if (empty($media_items) || !is_array($media_items)) {
        return ['status' => 'error', 'msg' => 'Không có danh sách media hợp lệ cho Carousel Instagram.'];
    }

    $media_items = array_values(array_slice($media_items, 0, 10));
    $item_container_ids = [];

    foreach ($media_items as $media_url) {
        $is_video = (strpos(strtolower($media_url), '.mp4') !== false || 
                     strpos(strtolower($media_url), '.mov') !== false || 
                     strpos(strtolower($media_url), '.webm') !== false);

        $url = FB_API_BASE . $ig_user_id . "/media";
        $params = [
            'is_carousel_item' => 'true',
            'access_token' => $access_token
        ];

        if ($is_video) {
            $params['media_type'] = 'VIDEO';
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

        if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL Carousel Item: ' . $err];
        $data = json_decode($res, true);
        if (empty($data['id'])) {
            return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Không tạo được Item Container Carousel'];
        }

        $c_id = $data['id'];
        $poll = poll_instagram_container_status($c_id, $access_token, 60);
        if ($poll['status'] !== 'success') return $poll;

        $item_container_ids[] = $c_id;
    }

    if (empty($item_container_ids)) {
        return ['status' => 'error', 'msg' => 'Không tạo được item nào cho Carousel'];
    }

    $url = FB_API_BASE . $ig_user_id . "/media";
    $params = [
        'media_type' => 'CAROUSEL',
        'caption' => $caption,
        'children' => implode(',', $item_container_ids),
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

    if ($err) return ['status' => 'error', 'msg' => 'Lỗi cURL Carousel Container: ' . $err];
    $data = json_decode($res, true);
    if (empty($data['id'])) {
        return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Không tạo được Carousel Container'];
    }

    $parent_container_id = $data['id'];
    $poll = poll_instagram_container_status($parent_container_id, $access_token, 60);
    if ($poll['status'] !== 'success') return $poll;

    return publish_instagram_container($ig_user_id, $parent_container_id, $access_token);
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
