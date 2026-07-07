<?php
// includes/fb_api.php
require_once __DIR__ . '/config.php';

define('FB_API_VERSION', 'v25.0');
define('FB_API_BASE', 'https://graph.facebook.com/' . FB_API_VERSION . '/');

function fb_curl_setssl($ch) {
    // Disable SSL verification only in development environment
    if (defined('APP_ENV') && APP_ENV === 'development') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
}

function fb_api_request($endpoint, $params = [], $method = 'GET', $post_data = []) {
    $url = FB_API_BASE . $endpoint;

    if (!empty($params)) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    fb_curl_setssl($ch);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($post_data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    fb_curl_setssl($ch);

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
    $chunks = array_chunk($pages, 10);

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
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
        $res1 = fb_api_request($page_id . '/photos', ['access_token' => $page_access_token], 'POST', $post_data);
        if ($res1['status_code'] !== 200 || empty($res1['data']['id'])) return $res1;
        return fb_api_request($page_id . '/photo_stories', ['access_token' => $page_access_token], 'POST', ['photo_id' => $res1['data']['id']]);
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
        $fp = fopen($file_path, 'rb');
        if (!$fp) {
            return ['status_code' => 0, 'data' => ['error' => ['message' => 'Không thể mở file video để upload.']]];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $upload_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: OAuth {$page_access_token}",
            "Content-Type: application/octet-stream",
            "offset: 0",
            "file_size: {$file_size}"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file_path));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        fb_curl_setssl($ch);
        fclose($fp);

        set_time_limit(600);
        $chunk_resRaw = curl_exec($ch);
        $chunk_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err     = curl_error($ch);
        curl_close($ch);

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
?>
