<?php
// includes/fb_api.php
require_once __DIR__ . '/config.php';

define('FB_API_VERSION', 'v25.0');
define('FB_API_BASE', 'https://graph.facebook.com/' . FB_API_VERSION . '/');

function fb_curl_setssl($ch) {
    // Force IPv4 resolution to prevent IPv6 connection timeouts on VPS/servers
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 3600);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    // Disable SSL verification only in development environment
    if (defined('APP_ENV') && APP_ENV === 'development') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
}

function apply_proxy_to_curl($ch, $access_token = null) {
    if (empty($access_token)) return;
    
    static $proxy_cache = [];
    $token_hash = md5($access_token);

    if (!array_key_exists($token_hash, $proxy_cache)) {
        $proxy_cache[$token_hash] = null;
        try {
            global $pdo;
            if (isset($pdo)) {
                $enc_token = function_exists('encryptData') ? encryptData($access_token) : $access_token;
                
                // 1. Direct match on users.access_token
                $stmt = $pdo->prepare("
                    SELECT px.* 
                    FROM users u
                    JOIN proxies px ON u.proxy_id = px.id
                    WHERE (u.access_token = ? OR u.access_token = ?) AND px.status != 'dead'
                    LIMIT 1
                ");
                $stmt->execute([$access_token, $enc_token]);
                $px = $stmt->fetch(PDO::FETCH_ASSOC);

                // 2. Match via pages.access_token (Page access token -> user -> proxy)
                if (!$px) {
                    $stmt = $pdo->prepare("
                        SELECT px.* 
                        FROM pages p
                        JOIN users u ON p.user_id = u.id
                        JOIN proxies px ON u.proxy_id = px.id
                        WHERE (p.access_token = ? OR p.access_token = ?) AND px.status != 'dead'
                        LIMIT 1
                    ");
                    $stmt->execute([$access_token, $enc_token]);
                    $px = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                // 3. Match via instagram_accounts.access_token (Instagram access token -> page -> user -> proxy)
                if (!$px) {
                    $stmt = $pdo->prepare("
                        SELECT px.* 
                        FROM instagram_accounts ig
                        JOIN pages p ON ig.fb_page_id = p.page_id
                        JOIN users u ON p.user_id = u.id
                        JOIN proxies px ON u.proxy_id = px.id
                        WHERE (ig.access_token = ? OR ig.access_token = ?) AND px.status != 'dead'
                        LIMIT 1
                    ");
                    $stmt->execute([$access_token, $enc_token]);
                    $px = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                // 4. Match via instagram_accounts.account_id (Instagram access token -> account_id -> users with proxy)
                if (!$px) {
                    $stmt = $pdo->prepare("
                        SELECT px.* 
                        FROM instagram_accounts ig
                        JOIN users u ON ig.account_id = u.account_id
                        JOIN proxies px ON u.proxy_id = px.id
                        WHERE (ig.access_token = ? OR ig.access_token = ?) AND px.status != 'dead'
                        ORDER BY u.id ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$access_token, $enc_token]);
                    $px = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($px) {
                    $proxy_cache[$token_hash] = $px;
                }
            }
        } catch (Exception $e) {}
    }

    $px = $proxy_cache[$token_hash];
    if ($px) {
        $ip_str = (($px['ip_type'] ?? '') === 'IPv6' && strpos($px['ip'], ':') !== false) ? '[' . $px['ip'] . ']' : $px['ip'];
        curl_setopt($ch, CURLOPT_PROXY, $ip_str . ':' . $px['port']);
        if (!empty($px['username']) && !empty($px['password'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $px['username'] . ':' . $px['password']);
        }
        if (strtolower($px['protocol'] ?? '') === 'socks5') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5);
        }
    }
}

function fb_api_request($endpoint, $params = [], $method = 'GET', $post_data = [], $timeout = 20) {
    if (strpos($endpoint, 'videos') !== false || strpos($endpoint, 'video_stories') !== false || strpos($endpoint, 'video_reels') !== false) {
        $url = 'https://graph-video.facebook.com/' . FB_API_VERSION . '/' . $endpoint;
    } else {
        $url = FB_API_BASE . $endpoint;
    }

    if (!empty($params)) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024); // Ngắt nếu tốc độ truyền tải < 1KB/s
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);    // trong 60s liên tục (chống cURL treo vô hạn)
    fb_curl_setssl($ch);

    $token_for_proxy = $params['access_token'] ?? $post_data['access_token'] ?? null;
    if (!empty($token_for_proxy)) {
        apply_proxy_to_curl($ch, $token_for_proxy);
    }

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (empty($post_data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        } else {
            if (is_array($post_data)) {
                $has_file = false;
                foreach ($post_data as $v) {
                    if ($v instanceof CURLFile) { $has_file = true; break; }
                }
                if (!$has_file) {
                    $post_data = http_build_query($post_data);
                }
            } else if (is_string($post_data) && (strpos($post_data, '{') === 0 || strpos($post_data, '[') === 0)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
    } elseif (strtoupper($method) === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['status_code' => 0, 'data' => ['error' => ['message' => $curl_err]]];
    }

    return [
        'status_code' => $http_code,
        'data'        => json_decode($response, true)
    ];
}

/**
 * Gọi API Facebook bằng URL đầy đủ (dùng cho phân trang - pagination `next` URL).
 */
function fb_api_request_url($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    fb_curl_setssl($ch);

    if (preg_match('/[?&]access_token=([^&]+)/', $url, $m)) {
        apply_proxy_to_curl($ch, urldecode($m[1]));
    }

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['status_code' => 0, 'data' => ['error' => ['message' => $curl_err]]];
    }

    return [
        'status_code' => $http_code,
        'data'        => json_decode($response, true)
    ];
}

function get_fb_user_profile($access_token) {
    return fb_api_request('me', [
        'fields'       => 'id,name',
        'access_token' => $access_token
    ]);
}

function get_fb_user_pages($access_token, $after = null) {
    $params = [
        'fields'       => 'id,name,access_token,category,followers_count,picture{url}',
        'limit'        => 500,
        'access_token' => $access_token
    ];
    if ($after) $params['after'] = $after;
    return fb_api_request('me/accounts', $params);
}

function get_fb_page_insights($page_id, $page_access_token, $period = 'day', $since = null, $until = null) {
    $params = [
        'period'       => $period,
        'access_token' => $page_access_token
    ];
    if ($since) $params['since'] = $since;
    if ($until) $params['until'] = $until;
    return fb_api_request($page_id . '/insights/page_media_view', $params);
}

function get_fb_page_insights_multi($pages, $period = 'day', $since = null, $until = null) {
    if (empty($pages)) return [];

    $results = [];
    $chunks = array_chunk($pages, 50);

    foreach ($chunks as $chunk) {
        $multi_curl = curl_multi_init();
        $handles    = [];

        foreach ($chunk as $p) {
            $url = FB_API_BASE . $p['page_id'] . "/insights?metric=page_media_view,page_total_media_view_unique&period=" . urlencode($period) . "&access_token=" . $p['access_token'];
            if ($since) $url .= "&since=" . urlencode($since);
            if ($until) $url .= "&until=" . urlencode($until);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            fb_curl_setssl($ch);
            curl_multi_add_handle($multi_curl, $ch);
            $handles[$p['page_id']] = $ch;
        }

        // Correct multi-curl execution loop
        $active = null;
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multi_curl, 0.5) === -1) {
                usleep(5000);
            }
            do {
                $mrc = curl_multi_exec($multi_curl, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }

        foreach ($handles as $page_id => $ch) {
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi_curl, $ch);
            $results[$page_id] = [
                'status_code' => $http_code,
                'data'        => json_decode($response, true)
            ];
        }
        curl_multi_close($multi_curl);
    }
    return $results;
}

function get_fb_conversations_multi($pages, $limit = 5, $cursors = []) {
    if (empty($pages)) return ['data' => [], 'cursors' => []];

    $all_conversations = [];
    $next_cursors = [];
    $chunks = array_chunk($pages, 50);

    foreach ($chunks as $chunk) {
        $multi_curl = curl_multi_init();
        $handles    = [];
        
        $fields = 'id,updated_time,unread_count,tags{name},participants{id,name,email,custom_labels},messages.limit(' . $limit . '){message,from}';

        foreach ($chunk as $p) {
            $url = FB_API_BASE . $p['page_id'] . "/conversations?fields=" . urlencode($fields) . "&limit=" . $limit . "&access_token=" . $p['access_token'];
            
            if (!empty($cursors[$p['page_id']])) {
                $url .= "&after=" . $cursors[$p['page_id']];
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            fb_curl_setssl($ch);
            curl_multi_add_handle($multi_curl, $ch);
            $handles[$p['page_id']] = $ch;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multi_curl, 0.5) === -1) {
                usleep(5000);
            }
            do {
                $mrc = curl_multi_exec($multi_curl, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }

        foreach ($handles as $page_id => $ch) {
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi_curl, $ch);
            
            if ($http_code === 200) {
                $data = json_decode($response, true);
                if (!empty($data['data'])) {
                    $current_page = null;
                    foreach ($chunk as $c) {
                        if ($c['page_id'] == $page_id) {
                            $current_page = $c;
                            break;
                        }
                    }
                    
                    if ($current_page) {
                        foreach ($data['data'] as $conv) {
                            $conv['_page_id'] = $current_page['page_id'];
                            $conv['_user_id'] = $current_page['user_id'];
                            $conv['_page_name'] = $current_page['name'] ?? 'Page';
                            $all_conversations[] = $conv;
                        }
                    }
                }
                if (isset($data['paging']['cursors']['after']) && count($data['data']) > 0) {
                    $next_cursors[$page_id] = $data['paging']['cursors']['after'];
                }
            }
        }
        curl_multi_close($multi_curl);
    }
    
    usort($all_conversations, function ($a, $b) {
        $timeA = strtotime($a['updated_time']);
        $timeB = strtotime($b['updated_time']);
        return $timeB - $timeA;
    });

    return [
        'data' => array_slice($all_conversations, 0, 40),
        'cursors' => $next_cursors
    ];
}

function get_fb_posts_multi($pages, $limit = 15, $cursors = []) {
    $all_posts = [];
    $next_cursors = [];
    $chunks = array_chunk($pages, 30);

    foreach ($chunks as $chunk) {
        $multi_curl = curl_multi_init();
        $handles = [];

        foreach ($chunk as $p) {
            $page_id = $p['page_id'];
            $token = $p['access_token'];
            $after = $cursors[$page_id] ?? '';
            
            $url = FB_API_BASE . "{$page_id}/feed?fields=id,message,created_time,full_picture,comments.summary(1).limit(1),reactions.summary(1).limit(1)&limit={$limit}&access_token={$token}";
            if ($after) {
                $url .= "&after=" . urlencode($after);
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            fb_curl_setssl($ch);
            curl_multi_add_handle($multi_curl, $ch);
            $handles[$page_id] = $ch;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multi_curl, 0.5) === -1) {
                usleep(5000);
            }
            do {
                $mrc = curl_multi_exec($multi_curl, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }

        foreach ($handles as $page_id => $ch) {
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi_curl, $ch);
            
            if ($http_code === 200) {
                $data = json_decode($response, true);
                if (!empty($data['data'])) {
                    $current_page = null;
                    foreach ($chunk as $c) {
                        if ($c['page_id'] == $page_id) {
                            $current_page = $c;
                            break;
                        }
                    }
                    
                    if ($current_page) {
                        foreach ($data['data'] as $post) {
                            $post['_page_id'] = $current_page['page_id'];
                            $post['_user_id'] = $current_page['user_id'];
                            $post['_page_name'] = $current_page['name'] ?? 'Page';
                            // Transform fields to match single API
                            $post['picture'] = $post['full_picture'] ?? null;
                            $cc = $post['comments']['summary']['total_count'] ?? 0;
                            $rc = $post['reactions']['summary']['total_count'] ?? 0;
                            $post['comment_count'] = $cc;
                            $post['reaction_count'] = $rc;
                            $post['has_comments'] = $cc > 0;
                            $all_posts[] = $post;
                        }
                    }
                }
                if (isset($data['paging']['cursors']['after']) && count($data['data']) > 0) {
                    $next_cursors[$page_id] = $data['paging']['cursors']['after'];
                }
            }
        }
        curl_multi_close($multi_curl);
    }
    
    usort($all_posts, function ($a, $b) {
        $timeA = strtotime($a['created_time']);
        $timeB = strtotime($b['created_time']);
        return $timeB - $timeA;
    });

    return [
        'data' => array_slice($all_posts, 0, $limit),
        'cursors' => $next_cursors
    ];
}

function fb_upload_story($page_id, $page_access_token, $file_path, $file_mime, $is_photo, $original_name) {
    if ($is_photo) {
        $safe_ext = pathinfo($original_name, PATHINFO_EXTENSION);
        $safe_original_name = 'media_upload_' . uniqid() . ($safe_ext ? '.' . $safe_ext : '');
        $post_data = [
            'source'    => new CURLFile($file_path, $file_mime, $safe_original_name),
            'published' => 'false'
        ];
        $res1 = fb_api_request($page_id . '/photos', ['access_token' => $page_access_token], 'POST', $post_data, 60);
        if ($res1['status_code'] !== 200 || empty($res1['data']['id'])) return $res1;
        return fb_api_request($page_id . '/photo_stories', ['access_token' => $page_access_token], 'POST', ['photo_id' => $res1['data']['id']], 60);
    } else {
        // ── Step 1: Start upload session ─────────────────────────────────
        $file_size = filesize($file_path);

        $res1 = fb_api_request($page_id . '/video_stories', [
            'upload_phase' => 'start',
            'access_token' => $page_access_token
        ], 'POST');

        if ($res1['status_code'] !== 200 || empty($res1['data']['video_id']) || empty($res1['data']['upload_url'])) {
            return $res1;
        }

        $video_id   = $res1['data']['video_id'];
        $upload_url = $res1['data']['upload_url'];

        // ── Step 2: Upload file bytes ────────────────────────────────────
        $file_bytes = @file_get_contents($file_path);
        if ($file_bytes === false) {
            return ['status_code' => 0, 'data' => ['error' => ['message' => 'Không thể đọc file video để upload.']]];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $upload_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_bytes);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: OAuth {$page_access_token}",
            "Content-Type: application/octet-stream",
            "offset: 0",
            "file_size: {$file_size}",
            "Expect:"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);
        fb_curl_setssl($ch);
        apply_proxy_to_curl($ch, $page_access_token);

        set_time_limit(600);
        $chunk_resRaw = curl_exec($ch);
        $chunk_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err     = curl_error($ch);
        curl_close($ch);
        unset($file_bytes);

        if ($chunk_code !== 200) {
            $err_msg = 'Upload phase failed';
            if ($curl_err) $err_msg .= " (cURL: $curl_err)";
            $parsed  = @json_decode($chunk_resRaw, true);
            if ($parsed && isset($parsed['error']['message'])) $err_msg = $parsed['error']['message'];
            return ['status_code' => $chunk_code, 'data' => ['error' => ['message' => $err_msg]]];
        }

        // ── Step 3: Finish upload ────────────────────────────────────────
        return fb_api_request($page_id . '/video_stories', [
            'upload_phase' => 'finish',
            'video_id'     => $video_id,
            'access_token' => $page_access_token
        ], 'POST');
    }
}

if (!function_exists('fb_echo_log')) {
    function fb_echo_log($msg) {
        echo $msg;
        if (ob_get_level() > 0) @ob_flush();
        @flush();
    }
}

/**
 * Upload Facebook Page Reel using Meta's Official 4-Step Video Reels Publishing API
 */
function fb_upload_page_reel($page_id, $page_access_token, $file_path, $title = '', $description = '', $sp_post_id = 0) {
    @ob_implicit_flush(1);

    $is_remote_url = (strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0);

    if (!$is_remote_url) {
        if (!file_exists($file_path) && file_exists(__DIR__ . '/../' . ltrim($file_path, '/'))) {
            $file_path = __DIR__ . '/../' . ltrim($file_path, '/');
        }
        if (!file_exists($file_path)) {
            return ['status_code' => 0, 'data' => ['error' => ['message' => "File video Reel không tồn tại trên máy chủ: {$file_path}"]]];
        }
    }

    $file_size = $is_remote_url ? 0 : filesize($file_path);
    $mb_size = $is_remote_url ? 'URL' : round($file_size / 1024 / 1024, 2);
    fb_echo_log("   ----------------------------------------------------\n");
    fb_echo_log("   🎬 BẮT ĐẦU ĐĂNG REEL META 4 BƯỚC (Dung lượng: $mb_size MB)\n");
    fb_echo_log("   ----------------------------------------------------\n");

    // ── Phase 1: Start upload session ───────────────────────────────────
    fb_echo_log("   → [Bước 1/4] Khởi tạo phiên đăng Reel (POST /{page_id}/video_reels?upload_phase=start)...\n");
    $res1 = fb_api_request($page_id . '/video_reels', [
        'upload_phase' => 'start',
        'access_token' => $page_access_token
    ], 'POST', [], 60);

    if ($res1['status_code'] !== 200 || empty($res1['data']['video_id']) || empty($res1['data']['upload_url'])) {
        fb_echo_log("   ❌ [Bước 1 Thất Bại] HTTP " . ($res1['status_code'] ?? 0) . " - " . json_encode($res1['data'] ?? []) . "\n");
        return $res1;
    }

    $video_id   = $res1['data']['video_id'];
    $upload_url = $res1['data']['upload_url'];

    fb_echo_log("   ✅ [Bước 1 Thành Công] Video ID: {$video_id}\n");
    fb_echo_log("      Upload URL: {$upload_url}\n");

    // ── Phase 2: Transfer video file ────────────────────────────────────
    fb_echo_log("   → [Bước 2/4] Đang truyền dữ liệu video lên rupload.facebook.com...\n");

    if ($is_remote_url) {
        fb_echo_log("   → [Bước 2 Remote] Truyền video qua header file_url: {$file_path}...\n");
        $ch_cdn = curl_init();
        curl_setopt($ch_cdn, CURLOPT_URL, $upload_url);
        curl_setopt($ch_cdn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_cdn, CURLOPT_POST, true);
        curl_setopt($ch_cdn, CURLOPT_HTTPHEADER, [
            "Authorization: OAuth {$page_access_token}",
            "file_url: {$file_path}"
        ]);
        curl_setopt($ch_cdn, CURLOPT_TIMEOUT, 120);
        fb_curl_setssl($ch_cdn);
        apply_proxy_to_curl($ch_cdn, $page_access_token);

        $cdn_resRaw = curl_exec($ch_cdn);
        $cdn_code   = curl_getinfo($ch_cdn, CURLINFO_HTTP_CODE);
        curl_close($ch_cdn);

        if ($cdn_code !== 200) {
            $parsed = @json_decode($cdn_resRaw, true);
            $err_msg = $parsed['error']['message'] ?? substr(strip_tags($cdn_resRaw), 0, 300);
            fb_echo_log("   ❌ [Bước 2 Thất Bại] HTTP $cdn_code - $err_msg\n");
            return ['status_code' => $cdn_code, 'data' => ['error' => ['message' => $err_msg]]];
        }
        fb_echo_log("   ✅ [Bước 2 Thành Công] Đã truyền video qua file_url thành công (HTTP 200).\n");
    } else {
        $real_size = filesize($file_path);
        fb_echo_log("   → [Bước 2 Binary] Upload file video binary trực tiếp (offset: 0, file_size: {$real_size} bytes)...\n");
        $file_bytes = @file_get_contents($file_path);
        if ($file_bytes === false) {
            return ['status_code' => 0, 'data' => ['error' => ['message' => 'Không thể đọc file video trên server.']]];
        }

        $actual_bytes_len = strlen($file_bytes);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $upload_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_bytes);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: OAuth {$page_access_token}",
            "Content-Type: application/octet-stream",
            "Content-Length: " . $actual_bytes_len,
            "offset: 0",
            "file_size: " . $actual_bytes_len,
            "Expect:"
        ]);

        fb_curl_setssl($ch);
        apply_proxy_to_curl($ch, $page_access_token);

        set_time_limit(600);
        $chunk_resRaw = curl_exec($ch);
        $chunk_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err     = curl_error($ch);
        curl_close($ch);
        unset($file_bytes);

        if ($chunk_code !== 200) {
            $err_msg = 'Upload phase 2 (transfer) failed';
            if ($curl_err) $err_msg .= " (cURL: $curl_err)";
            $parsed  = @json_decode($chunk_resRaw, true);
            if ($parsed && isset($parsed['error']['message'])) {
                $err_msg .= " - " . $parsed['error']['message'];
            } elseif (!empty($chunk_resRaw)) {
                $err_msg .= " - Body: " . substr(strip_tags($chunk_resRaw), 0, 300);
            }
            fb_echo_log("   ❌ [Bước 2 Thất Bại] HTTP $chunk_code - $err_msg\n");
            return ['status_code' => $chunk_code, 'data' => ['error' => ['message' => $err_msg]]];
        }

        fb_echo_log("   ✅ [Bước 2 Thành Công] Tải file video lên rupload thành công (HTTP 200).\n");
    }

    // ── Phase 3: Check Video Processing Status (Tối đa 5 lần kiểm tra nhanh) ───
    fb_echo_log("   → [Bước 3/4] Kiểm tra trạng thái video trên Facebook (GET /{$video_id}?fields=status)...\n");
    if ($sp_post_id > 0 && function_exists('update_post_progress')) {
        global $pdo;
        if (isset($pdo)) {
            update_post_progress($pdo, $sp_post_id, "⚙️ Đang kiểm tra trạng thái xử lý video (Bước 3)...");
        }
    }

    $max_status_checks = 5; // Kiểm tra tối đa 5 lần (mỗi lần 1s)
    $video_ready = false;

    for ($check_i = 1; $check_i <= $max_status_checks; $check_i++) {
        $status_res = fb_api_request($video_id, [
            'fields'       => 'status',
            'access_token' => $page_access_token
        ], 'GET', [], 10);

        $video_status    = $status_res['data']['status']['video_status'] ?? '';
        $uploading_state = $status_res['data']['status']['uploading_phase']['status'] ?? '';
        $processing_state= $status_res['data']['status']['processing_phase']['status'] ?? '';

        fb_echo_log("   → [Bước 3 Lần $check_i/$max_status_checks] Status: video={$video_status}, upload={$uploading_state}, processing={$processing_state}\n");

        if (in_array($video_status, ['ready', 'complete', 'upload_complete']) || in_array($processing_state, ['complete', 'success', 'completed']) || in_array($uploading_state, ['complete', 'completed'])) {
            $video_ready = true;
            fb_echo_log("   ✅ [Bước 3 Thành Công] Trạng thái video sẵn sàng xuất bản.\n");
            break;
        }

        if ($video_status === 'error' || $processing_state === 'error' || $uploading_state === 'error') {
            fb_echo_log("   ⚠️ [Bước 3 Cảnh Báo] Video trả về trạng thái error - " . json_encode($status_res['data'] ?? []) . "\n");
            break;
        }

        sleep(1);
    }

    // ── Phase 4: Finish and Publish Reel ────────────────────────────────
    fb_echo_log("   → [Bước 4/4] Đang xuất bản Reel (upload_phase: finish, video_state: PUBLISHED)...\n");
    if ($sp_post_id > 0 && function_exists('update_post_progress')) {
        global $pdo;
        if (isset($pdo)) {
            update_post_progress($pdo, $sp_post_id, "⚙️ Đang hoàn tất xuất bản Reel (Bước 4)...");
        }
    }

    $finish_params = [
        'upload_phase' => 'finish',
        'video_id'     => $video_id,
        'video_state'  => 'PUBLISHED',
        'access_token' => $page_access_token
    ];
    if (!empty($description)) {
        $finish_params['description'] = $description;
    }
    if (!empty($title)) {
        $finish_params['title'] = $title;
    }

    $res3 = fb_api_request($page_id . '/video_reels', [
        'upload_phase' => 'finish',
        'access_token' => $page_access_token
    ], 'POST', $finish_params, 60);

    $pub_reel_id = $res3['data']['id'] ?? $res3['data']['post_id'] ?? $video_id;

    if ($res3['status_code'] === 200) {
        fb_echo_log("   🎉 [Bước 4 Thành Công] Đã xuất bản Reel thành công! Facebook Post ID: {$pub_reel_id}\n");
        if (empty($res3['data']['id']) && empty($res3['data']['post_id'])) {
            $res3['data']['id'] = $video_id;
        }
    } else {
        fb_echo_log("   ❌ [Bước 4 Thất Bại] HTTP " . ($res3['status_code'] ?? 0) . " - " . json_encode($res3['data'] ?? []) . "\n");
    }

    return $res3;
}

/**
 * Upload Video/Reel using Resumable API (Chunked Upload)
 * Giúp tránh lỗi timeout 120s khi tải video nặng qua proxy bằng file_url.
 */
function fb_upload_video_resumable($page_id, $page_access_token, $file_path, $title, $description, $is_reel = false, $sp_post_id = 0) {
    if ($is_reel) {
        return fb_upload_page_reel($page_id, $page_access_token, $file_path, $title, $description, $sp_post_id);
    }

    $is_remote_url = (strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0);
    if (!$is_remote_url) {
        if (!file_exists($file_path) && file_exists(__DIR__ . '/../' . ltrim($file_path, '/'))) {
            $file_path = __DIR__ . '/../' . ltrim($file_path, '/');
        }
        if (!file_exists($file_path)) {
            return ['status_code' => 0, 'data' => ['error' => ['message' => "File video không tồn tại trên máy chủ: {$file_path}"]]];
        }
    }

    $file_size = filesize($file_path);
    $endpoint = $page_id . '/videos';

    $safe_ext = pathinfo($file_path, PATHINFO_EXTENSION);
    $safe_name = 'video_' . uniqid() . ($safe_ext ? '.' . $safe_ext : '.mp4');

    $mb_size = round($file_size / 1024 / 1024, 2);
    echo "   → Dang tai video len Facebook ($mb_size MB)...\n";

    $direct_params = [
        'title' => $title,
        'description' => $description,
        'source' => new CURLFile($file_path, 'application/octet-stream', $safe_name),
        'access_token' => $page_access_token
    ];

    $res = fb_api_request($endpoint, [], 'POST', $direct_params, 600);
    echo "   → Ket qua dang video: Status " . ($res['status_code'] ?? '0') . " - Data: " . json_encode($res['data'] ?? []) . "\n";
    return $res;
}

function fb_exchange_token($short_token, $app_id, $app_secret) {
    if (empty($app_id) || empty($app_secret)) return null;
    $res = fb_api_request('oauth/access_token', [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $app_id,
        'client_secret'     => $app_secret,
        'fb_exchange_token' => $short_token
    ]);
    if ($res['status_code'] === 200 && !empty($res['data']['access_token'])) {
        return $res['data']['access_token'];
    }
    return null;
}

/**
 * Process Auto Comment Reply & Auto Private Reply for a Facebook Comment
 */
function process_auto_comment_reply($pdo, $page_id, $comment_id, $sender_id = '', $sender_name = '', $message = '') {
    if (empty($comment_id) || empty($page_id)) return false;

    try {
        // Tự động tạo bảng theo dõi riêng biệt (TUYỆT ĐỐI KHÔNG ghi vào page_notifications để tránh làm bẩn chuông thông báo)
        $pdo->exec("CREATE TABLE IF NOT EXISTS auto_replied_comments (
            comment_id VARCHAR(100) PRIMARY KEY,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 1. Kiểm tra tránh gửi lặp lại cho cùng 1 comment_id
        $chk_stmt = $pdo->prepare("SELECT comment_id FROM auto_replied_comments WHERE comment_id = ? LIMIT 1");
        $chk_stmt->execute([$comment_id]);
        if ($chk_stmt->fetch()) {
            return false; // Đã từng auto-reply rồi
        }

        // Đánh dấu đã xử lý ngay để tránh race condition khi có nhiều request đồng thời
        $ins_track = $pdo->prepare("INSERT IGNORE INTO auto_replied_comments (comment_id) VALUES (?)");
        $ins_track->execute([$comment_id]);

        // 2. Lấy Page access token và account_id sở hữu
        $page_token = '';
        $acc_id = 0;

        $stmt_page = $pdo->prepare("SELECT p.access_token, u.account_id FROM pages p LEFT JOIN users u ON p.user_id = u.id WHERE p.page_id = ? LIMIT 1");
        $stmt_page->execute([$page_id]);
        $page_owner = $stmt_page->fetch(PDO::FETCH_ASSOC);

        if ($page_owner && !empty($page_owner['access_token'])) {
            $page_token = decryptData($page_owner['access_token']);
            $acc_id = (int)($page_owner['account_id'] ?? 0);
        }

        if (empty($page_token)) {
            $stmt_tok = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ? LIMIT 1");
            $stmt_tok->execute([$page_id]);
            $row_tok = $stmt_tok->fetch(PDO::FETCH_ASSOC);
            if ($row_tok && !empty($row_tok['access_token'])) {
                $page_token = decryptData($row_tok['access_token']);
            }
        }

        if (empty($page_token)) {
            @file_put_contents(__DIR__ . '/../webhook_db_errors.txt', date('Y-m-d H:i:s') . " AUTO_REPLY_SKIP: No token for page $page_id\n", FILE_APPEND);
            return false;
        }

        // 3. Lấy cấu hình tự động trả lời của tài khoản sở hữu
        $auto_cfg = null;
        if ($acc_id > 0) {
            $stmt_auto = $pdo->prepare("SELECT auto_reply_enabled, auto_reply_text, auto_inbox_enabled, auto_inbox_text, auto_pages_scope, auto_hide_phone_enabled, auto_hide_keywords_enabled, auto_hide_keywords_text FROM system_accounts WHERE id = ?");
            $stmt_auto->execute([$acc_id]);
            $auto_cfg = $stmt_auto->fetch(PDO::FETCH_ASSOC);
        }

        if (!$auto_cfg) {
            $stmt_def = $pdo->query("SELECT auto_reply_enabled, auto_reply_text, auto_inbox_enabled, auto_inbox_text, auto_pages_scope, auto_hide_phone_enabled, auto_hide_keywords_enabled, auto_hide_keywords_text FROM system_accounts WHERE id = 1");
            $auto_cfg = $stmt_def->fetch(PDO::FETCH_ASSOC);
        }

        if (!$auto_cfg) return false;

        $reply_enabled = (int)($auto_cfg['auto_reply_enabled'] ?? 0) === 1;
        $inbox_enabled = (int)($auto_cfg['auto_inbox_enabled'] ?? 0) === 1;
        $hide_phone_enabled = (int)($auto_cfg['auto_hide_phone_enabled'] ?? 0) === 1;
        $hide_kw_enabled = (int)($auto_cfg['auto_hide_keywords_enabled'] ?? 0) === 1;

        // NẾU TẤT CẢ CÁC TÍNH NĂNG BOT TỰ ĐỘNG ĐỀU TẮT -> NGHỈ NGAY LẬP TỨC KHÔNG CHẠY
        if (!$reply_enabled && !$inbox_enabled && !$hide_phone_enabled && !$hide_kw_enabled) {
            return false;
        }

        // Kiểm tra xem Fanpage có nằm trong phạm vi áp dụng hay không
        $match_scope = false;
        $scope = $auto_cfg['auto_pages_scope'] ?? 'ALL';
        if ($scope === 'ALL' || empty($scope)) {
            $match_scope = true;
        } else {
            $scope_arr = @json_decode($scope, true);
            if (is_array($scope_arr)) {
                $str_pages = array_map('strval', $scope_arr);
                if (in_array((string)$page_id, $str_pages, true)) {
                    $match_scope = true;
                }
            }
        }

        if (!$match_scope) return false;

        if (empty($sender_name)) $sender_name = 'Khách hàng';

        // A. Tự động Ẩn Bình Luận (Hide Comment) theo SĐT hoặc Từ khóa
        $should_hide = false;
        if (!empty($message)) {
            // 1. Kiểm tra số điện thoại (nếu bật)
            if ($hide_phone_enabled) {
                $digits = preg_replace('/[^\d]/', '', $message);
                if (preg_match('/(03|05|07|08|09)\d{8}/', $digits) || preg_match('/84(3|5|7|8|9)\d{8}/', $digits)) {
                    $should_hide = true;
                }
            }

            // 2. Kiểm tra từ khóa (nếu bật)
            if (!$should_hide && $hide_kw_enabled && !empty($auto_cfg['auto_hide_keywords_text'])) {
                $kw_raw = str_replace(["\r", "\n"], ',', $auto_cfg['auto_hide_keywords_text']);
                $keywords = array_filter(array_map('trim', explode(',', $kw_raw)));
                foreach ($keywords as $kw) {
                    if ($kw !== '' && mb_stripos($message, $kw) !== false) {
                        $should_hide = true;
                        break;
                    }
                }
            }
        }

        if ($should_hide) {
            $res_hide = fb_api_request("{$comment_id}", ['access_token' => $page_token], 'POST', ['is_hidden' => 'true']);
            @file_put_contents(__DIR__ . '/../webhook_db_errors.txt', date('Y-m-d H:i:s') . " AUTO HIDE CMT page=$page_id cmt=$comment_id res=" . json_encode($res_hide) . "\n", FILE_APPEND);
        }

        // B. Tự động Trả lời công khai trên Facebook (Public Reply)
        if ($reply_enabled && !empty($auto_cfg['auto_reply_text'])) {
            $reply_lines = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $auto_cfg['auto_reply_text']))));
            if (!empty($reply_lines)) {
                $chosen_reply = $reply_lines[array_rand($reply_lines)];
                $final_cmt_msg = str_replace('{name}', $sender_name, $chosen_reply);

                $res_cmt = fb_api_request("{$comment_id}/comments", ['access_token' => $page_token], 'POST', ['message' => $final_cmt_msg]);
                @file_put_contents(__DIR__ . '/../webhook_db_errors.txt', date('Y-m-d H:i:s') . " PUBLIC CMT REPLY page=$page_id cmt=$comment_id res=" . json_encode($res_cmt) . "\n", FILE_APPEND);
            }
        }

        // C. Tự động Nhắn tin riêng cho người bình luận (Private Reply / Inbox qua Send API)
        if ($inbox_enabled && !empty($auto_cfg['auto_inbox_text'])) {
            $inbox_lines = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $auto_cfg['auto_inbox_text']))));
            if (!empty($inbox_lines)) {
                $chosen_inbox = $inbox_lines[array_rand($inbox_lines)];
                $final_inbox_msg = str_replace('{name}', $sender_name, $chosen_inbox);

                $endpoint_priv = "{$page_id}/messages";
                $params_priv = ['access_token' => $page_token];
                $payload_priv = json_encode([
                    'recipient' => ['comment_id' => $comment_id],
                    'message'   => ['text' => $final_inbox_msg]
                ], JSON_UNESCAPED_UNICODE);

                $res_priv = fb_api_request($endpoint_priv, $params_priv, 'POST', $payload_priv);
                @file_put_contents(__DIR__ . '/../webhook_db_errors.txt', date('Y-m-d H:i:s') . " PRIVATE INBOX REPLY page=$page_id cmt=$comment_id res=" . json_encode($res_priv) . "\n", FILE_APPEND);
            }
        }

        return true;
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/../webhook_db_errors.txt', date('Y-m-d H:i:s') . " AUTO REPLY ERR: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}
?>
