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
    curl_setopt($ch, CURLOPT_TCP_NODELAY, 1); // Tắt Nagle algorithm để gửi packet ngay lập tức
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 524288); // Tăng buffer cURL lên 512KB để tối đa throughput mạng

    if (defined('CURL_HTTP_VERSION_2_0')) {
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    }

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
    $has_file = false;
    if (is_array($post_data)) {
        foreach ($post_data as $v) {
            if ($v instanceof CURLFile) { $has_file = true; break; }
        }
    }
    if (!$has_file && is_array($params)) {
        foreach ($params as $v) {
            if ($v instanceof CURLFile) { $has_file = true; break; }
        }
    }

    $is_file_url = (is_array($post_data) && isset($post_data['file_url'])) || (is_array($params) && isset($params['file_url']));

    // Binary uploads use graph-video.facebook.com, but file_url requests MUST use standard graph.facebook.com
    if (!$is_file_url && (strpos($endpoint, 'videos') !== false || strpos($endpoint, 'video_stories') !== false || strpos($endpoint, 'video_reels') !== false)) {
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

    // Dùng proxy cho API thường, nhưng BỎ PROXY khi upload file binary (CURLFile) để tận dụng 100% băng thông VPS
    if (!$has_file) {
        $token_for_proxy = (is_array($params) ? ($params['access_token'] ?? null) : null) ?? (is_array($post_data) ? ($post_data['access_token'] ?? null) : null);
        if (!empty($token_for_proxy)) {
            apply_proxy_to_curl($ch, $token_for_proxy);
        }
    } else {
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 1048576); // 1MB buffer cho upload file binary siêu tốc
    }

    $headers = ['Expect:'];

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (empty($post_data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        } else {
            if (is_array($post_data)) {
                if (!$has_file) {
                    $post_data = http_build_query($post_data);
                } else {
                    $last_printed_pct = -10;
                    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function() use (&$last_printed_pct) {
                        $args = func_get_args();
                        if (count($args) >= 5) {
                            $uploaded = $args[4];
                            $total = $args[3];
                        } else {
                            $uploaded = $args[3] ?? 0;
                            $total = $args[2] ?? 0;
                        }
                        if ($total > 0 && $uploaded > 0) {
                            $pct = (int) floor(($uploaded / $total) * 100);
                            if ($pct >= $last_printed_pct + 10 || $pct === 100) {
                                $last_printed_pct = $pct;
                                $up_mb = round($uploaded / 1024 / 1024, 2);
                                $tot_mb = round($total / 1024 / 1024, 2);
                                fb_echo_log("   → Tiến trình upload: {$pct}% ({$up_mb} MB / {$tot_mb} MB)\n");
                                if ($pct === 100) {
                                    fb_echo_log("   ⏳ Đã truyền xong 100% dữ liệu sang Facebook, đang chờ Facebook xác nhận...\n");
                                }
                            }
                        }
                    });
                }
            } else if (is_string($post_data) && (strpos($post_data, '{') === 0 || strpos($post_data, '[') === 0)) {
                $headers[] = 'Content-Type: application/json';
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
    } elseif (strtoupper($method) === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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

if (!function_exists('get_public_file_url')) {
    function get_public_file_url($file_path) {
        if (strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0) {
            return $file_path;
        }
        $root_dir = realpath(__DIR__ . '/../');
        $real_file_path = realpath($file_path);
        if ($real_file_path && $root_dir && strpos(str_replace('\\', '/', $real_file_path), str_replace('\\', '/', $root_dir)) === 0) {
            $rel_path = str_replace('\\', '/', substr(str_replace('\\', '/', $real_file_path), strlen(str_replace('\\', '/', $root_dir))));
            global $pdo;
            $base_url = '';
            if (isset($pdo) && function_exists('get_system_site_url')) {
                $base_url = get_system_site_url($pdo);
            }
            if (empty($base_url)) {
                $base_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'fbweb.hongdolab.com');
            }
            return rtrim($base_url, '/') . '/' . ltrim($rel_path, '/');
        }
        return '';
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
 * Real Meta Resumable Chunked Uploader (Slices file into 20MB chunks, zero RAM overhead via fopen/fread, per-chunk retry)
 */
function fb_upload_video_chunked($page_id, $page_access_token, $file_path, $title = '', $description = '', $is_reel = false, $sp_post_id = 0, $chunk_size_mb = 20) {
    @ob_implicit_flush(1);

    $temp_dl_file = null;
    $is_remote_url = (strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0);
    if ($is_remote_url) {
        fb_echo_log("   📥 Đang tải video từ URL về máy chủ để chuẩn bị upload Facebook...\n");
        if ($sp_post_id > 0 && function_exists('update_post_progress')) {
            global $pdo;
            if (isset($pdo)) {
                update_post_progress($pdo, $sp_post_id, "📥 Đang tải video từ URL về máy chủ...");
            }
        }
        $temp_dl_file = sys_get_temp_dir() . '/fb_vid_' . uniqid() . '.mp4';
        $ch_dl = curl_init($file_path);
        $fp_dl = @fopen($temp_dl_file, 'wb');
        if (!$fp_dl) {
            return ['status_code' => 0, 'data' => ['error' => ['message' => "Không thể tạo file tạm để tải video từ URL: {$file_path}"]]];
        }
        curl_setopt_array($ch_dl, [
            CURLOPT_FILE => $fp_dl,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        ]);
        $dl_exec = curl_exec($ch_dl);
        $dl_code = curl_getinfo($ch_dl, CURLINFO_HTTP_CODE);
        $dl_err  = curl_error($ch_dl);
        curl_close($ch_dl);
        fclose($fp_dl);

        if (!$dl_exec || $dl_code !== 200 || !file_exists($temp_dl_file) || filesize($temp_dl_file) === 0) {
            if (file_exists($temp_dl_file)) @unlink($temp_dl_file);
            return ['status_code' => 0, 'data' => ['error' => ['message' => "Tải video từ URL thất bại (HTTP {$dl_code}): " . ($dl_err ?: 'File rỗng')]]];
        }
        $file_path = $temp_dl_file;
    } else {
        if (!file_exists($file_path) && file_exists(__DIR__ . '/../' . ltrim($file_path, '/'))) {
            $file_path = __DIR__ . '/../' . ltrim($file_path, '/');
        }
        if (!file_exists($file_path)) {
            return ['status_code' => 0, 'data' => ['error' => ['message' => "File video không tồn tại trên máy chủ: {$file_path}"]]];
        }
    }

    $file_size = filesize($file_path);
    $total_mb = round($file_size / 1024 / 1024, 2);
    $chunk_size_bytes = $chunk_size_mb * 1024 * 1024;

    $type_name = $is_reel ? 'REEL' : 'VIDEO';
    fb_echo_log("   ----------------------------------------------------\n");
    fb_echo_log("   🚀 BẮT ĐẦU ĐĂNG {$type_name} CHUNKED ({$total_mb} MB - Chunk Size: {$chunk_size_mb} MB)\n");
    fb_echo_log("   ----------------------------------------------------\n");

    $endpoint = $page_id . '/videos';

    // ── BƯỚC 1: START UPLOAD SESSION ─────────────────────────────────
    fb_echo_log("   → [Bước 1/3] Khởi tạo phiên upload (POST /{$endpoint}?upload_phase=start)...\n");
    $start_params = [
        'upload_phase' => 'start',
        'file_size'    => $file_size,
        'access_token' => $page_access_token
    ];

    $res1 = fb_api_request($endpoint, $start_params, 'POST', [], 60);

    if ($res1['status_code'] !== 200 || empty($res1['data']['video_id']) || empty($res1['data']['upload_session_id'])) {
        fb_echo_log("   ❌ [Bước 1 Thất Bại] HTTP " . ($res1['status_code'] ?? 0) . " - " . json_encode($res1['data'] ?? []) . "\n");
        return $res1;
    }

    $video_id          = $res1['data']['video_id'];
    $upload_session_id = $res1['data']['upload_session_id'];
    $start_offset      = (int)($res1['data']['start_offset'] ?? 0);

    fb_echo_log("   ✅ [Bước 1 Thành Công] Video ID: {$video_id} - Session ID: {$upload_session_id}\n");

    // ── BƯỚC 2: TRANSFER CHUNKS (Đọc file bằng fopen/fseek/fread - Tiết kiệm 100% RAM) ──
    $handle = @fopen($file_path, 'rb');
    if (!$handle) {
        return ['status_code' => 0, 'data' => ['error' => ['message' => 'Không thể mở file video để đọc binary.']]];
    }

    $temp_dir = __DIR__ . '/../uploads/tmp';
    if (!is_dir($temp_dir)) @mkdir($temp_dir, 0777, true);

    $chunk_index = 0;
    $total_chunks = (int)ceil($file_size / $chunk_size_bytes);
    if ($total_chunks < 1) $total_chunks = 1;

    while ($start_offset < $file_size) {
        $chunk_index++;
        fseek($handle, $start_offset);
        $chunk_data = fread($handle, $chunk_size_bytes);
        if ($chunk_data === false || strlen($chunk_data) === 0) break;

        $current_chunk_len = strlen($chunk_data);
        $chunk_file_path = $temp_dir . '/chunk_' . $upload_session_id . '_' . $start_offset . '.tmp';
        file_put_contents($chunk_file_path, $chunk_data);
        unset($chunk_data);

        $chunk_mb = round($current_chunk_len / 1024 / 1024, 2);
        $up_mb    = round($start_offset / 1024 / 1024, 2);
        $pct      = (int)floor(($start_offset / $file_size) * 100);

        fb_echo_log("   → [Bước 2/Chunk {$chunk_index}/{$total_chunks}] Uploading {$chunk_mb} MB ({$pct}% - {$up_mb}/{$total_mb} MB) từ offset {$start_offset}...\n");

        if ($sp_post_id > 0 && function_exists('update_post_progress')) {
            global $pdo;
            if (isset($pdo)) {
                update_post_progress($pdo, $sp_post_id, "📤 Đang upload Chunk {$chunk_index}/{$total_chunks} ({$pct}%)...");
            }
        }

        $safe_ext  = pathinfo($file_path, PATHINFO_EXTENSION);
        $safe_name = 'chunk_' . $chunk_index . ($safe_ext ? '.' . $safe_ext : '.mp4');

        $chunk_params = [
            'upload_phase'      => 'transfer',
            'upload_session_id' => $upload_session_id,
            'start_offset'      => (string)$start_offset,
            'video_file_chunk'  => new CURLFile($chunk_file_path, 'application/octet-stream', $safe_name),
            'access_token'      => $page_access_token
        ];

        $retry_count = 0;
        $chunk_success = false;

        $t_start = microtime(true);
        while ($retry_count < 3 && !$chunk_success) {
            $retry_count++;
            $res2 = fb_api_request($endpoint, [], 'POST', $chunk_params, 300);
            
            if ($res2['status_code'] === 200 || $res2['status_code'] === 206) {
                $chunk_success = true;
                $t_elapsed = round(microtime(true) - $t_start, 2);
                $speed_mbs = ($t_elapsed > 0) ? round($chunk_mb / $t_elapsed, 2) : $chunk_mb;
                fb_echo_log("   ✅ [Chunk {$chunk_index}/{$total_chunks} Thành Công] HTTP {$res2['status_code']} trong {$t_elapsed}s (Tốc độ: {$speed_mbs} MB/s)\n");

                if (isset($res2['data']['start_offset'])) {
                    $next_offset = (int)$res2['data']['start_offset'];
                    if ($next_offset > $start_offset) {
                        $start_offset = $next_offset;
                    } else {
                        $start_offset += $current_chunk_len;
                    }
                } else {
                    $start_offset += $current_chunk_len;
                }
            } else {
                fb_echo_log("   ⚠️ [Chunk {$chunk_index} Lỗi - Thử lại {$retry_count}/3] HTTP " . ($res2['status_code'] ?? 0) . " - " . json_encode($res2['data'] ?? []) . "\n");
                sleep(2);
            }
        }

        @unlink($chunk_file_path);

        if (!$chunk_success) {
            fclose($handle);
            if ($temp_dl_file && file_exists($temp_dl_file)) @unlink($temp_dl_file);
            fb_echo_log("   ❌ [Đăng Thất Bại] Chunk {$chunk_index} bị lỗi sau 3 lần thử lại.\n");
            return $res2;
        }
    }

    fclose($handle);
    fb_echo_log("   ✅ [Bước 2 Thành Công] Đã truyền xong toàn bộ các chunk ({$total_mb} MB) lên Facebook.\n");

    // ── BƯỚC 3: FINISH UPLOAD SESSION ─────────────────────────────────
    fb_echo_log("   → [Bước 3/3] Đang hoàn tất xuất bản ({$type_name})...\n");
    if ($sp_post_id > 0 && function_exists('update_post_progress')) {
        global $pdo;
        if (isset($pdo)) {
            update_post_progress($pdo, $sp_post_id, "⚙️ Đang hoàn tất xuất bản {$type_name}...");
        }
    }

    $finish_params = [
        'upload_phase'      => 'finish',
        'upload_session_id' => $upload_session_id,
        'access_token'      => $page_access_token,
        'title'             => $title,
        'description'       => $description
    ];

    if ($is_reel) {
        $finish_params['post_video_as_reels'] = 'true';
    }

    $res3 = fb_api_request($endpoint, [], 'POST', $finish_params, 120);

    if ($res3['status_code'] === 200 && (!empty($res3['data']['success']) || !empty($res3['data']['id']))) {
        $res3['data']['id']      = $video_id;
        $res3['data']['post_id'] = $video_id;
        fb_echo_log("   🎉 [Bước 3 Thành Công] Đã xuất bản {$type_name} thành công! Facebook Post ID: {$video_id}\n");
    } else {
        fb_echo_log("   ❌ [Bước 3 Thất Bại] HTTP " . ($res3['status_code'] ?? 0) . " - " . json_encode($res3['data'] ?? []) . "\n");
    }

    if ($temp_dl_file && file_exists($temp_dl_file)) @unlink($temp_dl_file);
    return $res3;
}

function fb_upload_page_reel($page_id, $page_access_token, $file_path, $title = '', $description = '', $sp_post_id = 0) {
    return fb_upload_video_chunked($page_id, $page_access_token, $file_path, $title, $description, true, $sp_post_id, 20);
}

function fb_upload_video_resumable($page_id, $page_access_token, $file_path, $title, $description, $is_reel = false, $sp_post_id = 0) {
    return fb_upload_video_chunked($page_id, $page_access_token, $file_path, $title, $description, $is_reel, $sp_post_id, 20);
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
