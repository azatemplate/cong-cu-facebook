<?php
// test_scrape_copphaviet.php - Quét Fanpage Facebook & Tải Video lên CDN data.hongdolab.com
@ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

header('Content-Type: application/json; charset=utf-8');

$page_id = trim($_REQUEST['page_id'] ?? 'TatDiepBeautySalonQ3');
if (empty($page_id)) {
    $page_id = 'TatDiepBeautySalonQ3';
}

$limit = intval($_REQUEST['limit'] ?? 10);
if ($limit <= 0) $limit = 10;

$custom_token = trim($_REQUEST['token'] ?? '');
$do_upload_cdn = isset($_REQUEST['upload_cdn']) ? (bool)$_REQUEST['upload_cdn'] : true;
$is_debug = !empty($_REQUEST['debug']);

$token = null;
$token_owner = '';

// 1. Kiểm tra Token từ Parameter URL (nếu có)
if (!empty($custom_token)) {
    $test = fb_api_request("me", ['access_token' => $custom_token]);
    if ($test['status_code'] === 200 && !empty($test['data']['id'])) {
        $token = $custom_token;
        $token_owner = "Custom Token từ Parameter: " . ($test['data']['name'] ?? 'User');
    }
}

// 2. Lấy Access Token sống từ DB người dùng (Users table)
if (!$token) {
    $stmt = $pdo->query("SELECT access_token, name, id FROM users WHERE access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 25");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        $tok = decryptData($u['access_token']);
        if ($tok) {
            $test = fb_api_request("me", ['access_token' => $tok]);
            if ($test['status_code'] === 200 && !empty($test['data']['id'])) {
                $token = $tok;
                $token_owner = "User DB: " . $u['name'] . " (ID: " . $u['id'] . ")";
                break;
            }
        }
    }
}

if (!$token) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Không tìm thấy Access Token Facebook hợp lệ nào trong hệ thống.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Lấy bản đồ Video MP4 từ endpoint /{page_id}/videos & /{page_id}/published_posts
$page_videos_map = [];

// Thử lấy video list của Page qua numeric page ID hoặc username
$numeric_page_id = '';
$p_info = fb_api_request("{$page_id}", ['access_token' => $token, 'fields' => 'id,name']);
if (!empty($p_info['data']['id'])) {
    $numeric_page_id = $p_info['data']['id'];
}

$target_page_id_for_vids = $numeric_page_id ?: $page_id;

$res_p_vids = fb_api_request("{$target_page_id_for_vids}/videos", [
    'access_token' => $token,
    'fields' => 'id,source,description,format,created_time',
    'limit' => 100
]);

if (!empty($res_p_vids['data']['data'])) {
    foreach ($res_p_vids['data']['data'] as $vid_obj) {
        $v_src = $vid_obj['source'] ?? '';
        if ($v_src) {
            if (!empty($vid_obj['id'])) {
                $page_videos_map[$vid_obj['id']] = $v_src;
            }
            if (!empty($vid_obj['created_time'])) {
                $v_ts = strtotime($vid_obj['created_time']);
                $page_videos_map['ts_' . $v_ts] = $v_src;
            }
        }
    }
}

// 4. Hàm cURL Upload CDN data.hongdolab.com/uploads
if (!function_exists('upload_file_to_hongdolab_cdn')) {
    function upload_file_to_hongdolab_cdn($file_path) {
        if (!file_exists($file_path) || filesize($file_path) < 10) return false;
        @set_time_limit(0);
        
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $upload_tmp_dir = dirname($file_path) . '/';
        
        if ($is_image) {
            $ch = curl_init('https://data.hongdolab.com/api/upload_video.php?action=image');
            $mime = function_exists('mime_content_type') ? mime_content_type($file_path) : 'image/jpeg';
            $cfile = new CURLFile($file_path, $mime, basename($file_path));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['image' => $cfile],
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            if ($res) {
                $json = json_decode($res, true);
                if (!empty($json['url'])) {
                    return $json['url'];
                }
            }
        } else {
            $filename = basename($file_path);
            $filesize = filesize($file_path);
            $mime = function_exists('mime_content_type') ? mime_content_type($file_path) : 'video/mp4';
            
            $ch = curl_init('https://data.hongdolab.com/api/upload_video.php?action=init');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['filename' => $filename, 'filesize' => $filesize, 'mime' => $mime]),
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $init_json = $res ? json_decode($res, true) : null;
            
            if (!empty($init_json['upload_id'])) {
                $upload_id = $init_json['upload_id'];
                $chunk_size = !empty($init_json['chunk_size']) ? (int)$init_json['chunk_size'] : (4 * 1024 * 1024);
                
                $fp = @fopen($file_path, 'rb');
                if ($fp) {
                    $index = 0;
                    $ok = true;
                    while (!feof($fp)) {
                        $chunk_data = fread($fp, $chunk_size);
                        if ($chunk_data === false || strlen($chunk_data) === 0) break;
                        
                        $tmp_chunk = tempnam($upload_tmp_dir, 'chk_' . getmypid() . '_');
                        file_put_contents($tmp_chunk, $chunk_data);
                        
                        $chunk_success = false;
                        for ($retry = 0; $retry < 3 && !$chunk_success; $retry++) {
                            $cfile = new CURLFile($tmp_chunk, 'application/octet-stream', $filename . '.part' . $index);
                            $ch = curl_init('https://data.hongdolab.com/api/upload_video.php?action=chunk');
                            curl_setopt_array($ch, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => [
                                    'upload_id' => $upload_id,
                                    'index' => (string)$index,
                                    'chunk' => $cfile
                                ],
                                CURLOPT_TIMEOUT => 180,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_SSL_VERIFYHOST => 0
                            ]);
                            $c_res = curl_exec($ch);
                            curl_close($ch);
                            $c_json = $c_res ? json_decode($c_res, true) : null;
                            if ($c_res && isset($c_json['ok']) && $c_json['ok']) {
                                $chunk_success = true;
                            } else {
                                usleep(300000);
                            }
                        }
                        @unlink($tmp_chunk);
                        
                        if (!$chunk_success) {
                            $ok = false;
                            break;
                        }
                        $index++;
                    }
                    fclose($fp);
                    
                    if ($ok) {
                        $ch = curl_init('https://data.hongdolab.com/api/upload_video.php?action=complete');
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => ['upload_id' => $upload_id],
                            CURLOPT_TIMEOUT => 300,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => 0
                        ]);
                        $comp_res = curl_exec($ch);
                        curl_close($ch);
                        $comp_json = $comp_res ? json_decode($comp_res, true) : null;
                        if (!empty($comp_json['url'])) {
                            return $comp_json['url'];
                        }
                    }
                }
            }
        }
        return false;
    }
}

// 5. Scrape Video HTML Fallback từ FB Web/Mobile
function scrape_fb_video_mp4_url($url) {
    if (empty($url)) return '';
    
    $test_urls = [
        $url,
        str_replace(['www.facebook.com', 'web.facebook.com', 'm.facebook.com'], 'mbasic.facebook.com', $url),
        str_replace(['www.facebook.com', 'web.facebook.com', 'mbasic.facebook.com'], 'm.facebook.com', $url)
    ];

    foreach ($test_urls as $t_url) {
        $ch = curl_init($t_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (!$html) continue;

        if (preg_match('/href=["\'](\/video\/redirect\/\?src=[^"\']+)["\']/', $html, $m)) {
            $raw_url = urldecode(str_replace('/video/redirect/?src=', '', html_entity_decode($m[1])));
            if (filter_var($raw_url, FILTER_VALIDATE_URL)) {
                return $raw_url;
            }
        }

        $patterns = [
            '/["\']browser_native_hd_url["\']\s*:\s*["\']([^"\']+)["\']/',
            '/["\']browser_native_sd_url["\']\s*:\s*["\']([^"\']+)["\']/',
            '/["\']playable_url_quality_hd["\']\s*:\s*["\']([^"\']+)["\']/',
            '/["\']playable_url["\']\s*:\s*["\']([^"\']+)["\']/',
            '/hd_src\s*:\s*["\']([^"\']+)["\']/',
            '/sd_src\s*:\s*["\']([^"\']+)["\']/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $raw_url = stripslashes(str_replace('\/', '/', $m[1]));
                if (filter_var($raw_url, FILTER_VALIDATE_URL)) {
                    return $raw_url;
                }
            }
        }
    }
    return '';
}

// 6. Trích xuất Video Source MP4 đầy đủ
function get_video_source_from_post_full($token, &$post, $page_videos_map = [], &$debug_log = []) {
    $fbid = $post['id'] ?? '';
    
    // Nếu post chưa có attachments, truy vấn riêng bài viết này để lấy full attachments
    if (empty($post['attachments']['data'])) {
        $q_post = fb_api_request("{$fbid}", [
            'access_token' => $token,
            'fields' => 'id,attachments{media{source},media_type,target,type,url,subattachments},source,permalink_url'
        ]);
        $debug_log['step0_q_post'] = $q_post['data'] ?? $q_post;
        if (!empty($q_post['data']['attachments']['data'])) {
            $post['attachments'] = $q_post['data']['attachments'];
        }
        if (!empty($q_post['data']['source'])) {
            return $q_post['data']['source'];
        }
    }

    $attachments = $post['attachments']['data'][0] ?? null;
    $target_id = $attachments['target']['id'] ?? '';
    $debug_log['target_id'] = $target_id;
    
    // 1) Kiểm tra map videos của Page theo Target ID / Video ID
    if (!empty($target_id) && !empty($page_videos_map[$target_id])) {
        $debug_log['step1'] = 'found_in_page_videos_map_target_id';
        return $page_videos_map[$target_id];
    }
    
    // 2) Kiểm tra map theo thời gian đăng bài
    if (!empty($post['created_time'])) {
        $post_ts = strtotime($post['created_time']);
        if (!empty($page_videos_map['ts_' . $post_ts])) {
            $debug_log['step2'] = 'found_in_page_videos_map_ts';
            return $page_videos_map['ts_' . $post_ts];
        }
    }

    // 3) Direct attachment media source
    if (!empty($attachments['media']['source'])) {
        $debug_log['step3'] = 'found_in_attachments_media_source';
        return $attachments['media']['source'];
    }

    // 4) Subattachments media source
    $subList = $attachments['subattachments']['data'] ?? [];
    foreach ($subList as $sub) {
        if (!empty($sub['media']['source'])) {
            $debug_log['step4'] = 'found_in_subattachments_media_source';
            return $sub['media']['source'];
        }
    }

    // 5) Query Target ID / Video ID qua Graph API
    if ($target_id) {
        $res = fb_api_request("{$target_id}", ['access_token' => $token, 'fields' => 'source,playable_url,playable_url_quality_hd']);
        $debug_log['step5_target_res'] = $res['data'] ?? $res;
        if (!empty($res['data']['source'])) {
            return $res['data']['source'];
        }
        if (!empty($res['data']['playable_url'])) {
            return $res['data']['playable_url'];
        }
        if (!empty($res['data']['playable_url_quality_hd'])) {
            return $res['data']['playable_url_quality_hd'];
        }
    }

    // 6) Tự động trích xuất Video ID từ Thumbnail URL qua Regex (ví dụ: 619308253_870340512553020_... -> 870340512553020)
    $pic = $post['full_picture'] ?? ($post['picture'] ?? '');
    $debug_log['pic_url'] = $pic;
    
    if ($pic) {
        $vid_candidates = [];
        if (preg_match_all('/(?:[\/_]|^)(\d{13,16})(?:[\/_]|\.|$)/', $pic, $m_all)) {
            $vid_candidates = array_unique($m_all[1]);
        }
        $debug_log['extracted_vid_candidates'] = $vid_candidates;

        foreach ($vid_candidates as $vid_id) {
            if (!empty($page_videos_map[$vid_id])) {
                $debug_log['step6_map'] = "found_candidate_{$vid_id}_in_map";
                return $page_videos_map[$vid_id];
            }
            $res = fb_api_request("{$vid_id}", ['access_token' => $token, 'fields' => 'source,playable_url,playable_url_quality_hd']);
            $debug_log["step6_api_res_{$vid_id}"] = $res['data'] ?? $res;
            if (!empty($res['data']['source'])) {
                return $res['data']['source'];
            }
            if (!empty($res['data']['playable_url'])) {
                return $res['data']['playable_url'];
            }
            if (!empty($res['data']['playable_url_quality_hd'])) {
                return $res['data']['playable_url_quality_hd'];
            }

            // Thử scrape trang watch / reel theo candidate video ID
            $test_urls = [
                "https://www.facebook.com/reel/{$vid_id}",
                "https://www.facebook.com/watch/?v={$vid_id}",
                "https://mbasic.facebook.com/video/mbasic.php?module=video_publisher&v={$vid_id}"
            ];
            foreach ($test_urls as $t_u) {
                $h_src = scrape_fb_video_mp4_url($t_u);
                if ($h_src) {
                    $debug_log["step6_scrape_candidate_{$vid_id}"] = $h_src;
                    return $h_src;
                }
            }
        }
    }

    // 7) Mobile Scrape fallback từ URL attachment hoặc permalink_url
    $url = $attachments['url'] ?? ($attachments['target']['url'] ?? ($post['permalink_url'] ?? ''));
    $debug_log['scrape_url'] = $url;
    if ($url) {
        $html_src = scrape_fb_video_mp4_url($url);
        if ($html_src) {
            $debug_log['step7_scrape_html'] = 'found';
            return $html_src;
        }
    }

    // 8) Scrape trực tiếp URL bài viết nếu có target_id
    if ($target_id) {
        $watch_url = "https://www.facebook.com/watch/?v=" . $target_id;
        $html_src = scrape_fb_video_mp4_url($watch_url);
        if ($html_src) {
            $debug_log['step8_scrape_watch'] = 'found';
            return $html_src;
        }
    }

    return '';
}

// 7. Gọi Graph API lấy danh sách bài viết từ Page
$fields = 'id,message,created_time,full_picture,attachments{media,media_type,subattachments,target,type,url},shares,comments.summary(total_count),reactions.summary(total_count)';
$endpoints = ['posts', 'feed', 'published_posts'];

$posts_scraped = [];
$api_endpoint_used = '';
$raw_api_response = null;

foreach ($endpoints as $ep) {
    $res = fb_api_request("{$page_id}/{$ep}", [
        'access_token' => $token,
        'fields' => $fields,
        'limit' => $limit
    ]);

    if ($res['status_code'] === 200 && !empty($res['data']['data'])) {
        $api_endpoint_used = $ep;
        $raw_api_response = $res['data'];
        break;
    }
}

if (!$raw_api_response || empty($raw_api_response['data'])) {
    echo json_encode([
        'status' => 'error',
        'message' => "Không quét được bài viết nào từ Page ID '{$page_id}'.",
        'last_api_response' => $res ?? null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$posts_data = $raw_api_response['data'];

foreach ($posts_data as $post) {
    $fbid = $post['id'] ?? '';
    $msg = $post['message'] ?? '';
    $pic = $post['full_picture'] ?? '';
    $c_at = $post['created_time'] ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : null;
    
    $sha = $post['shares']['count'] ?? 0;
    $com = $post['comments']['summary']['total_count'] ?? 0;
    $lik = $post['reactions']['summary']['total_count'] ?? ($post['likes']['summary']['total_count'] ?? 0);

    $images = [];
    $videos = [];
    $is_video_post = false;

    // Phát hiện video từ thumbnail url (t15 domain / path)
    if (strpos($pic, '/t15.') !== false || strpos($pic, '_5256-10/') !== false) {
        $is_video_post = true;
    }

    $attachList = $post['attachments']['data'] ?? [];
    foreach ($attachList as $att) {
        $mtype = $att['media_type'] ?? '';
        $type = $att['type'] ?? '';
        $is_vid = ($mtype === 'video' || strpos(strtolower($type), 'video') !== false);

        if ($is_vid) {
            $is_video_post = true;
            if (!empty($att['media']['source'])) {
                $videos[] = $att['media']['source'];
            }
        } else {
            if (!empty($att['media']['image']['src'])) {
                $images[] = $att['media']['image']['src'];
            }
        }

        $subList = $att['subattachments']['data'] ?? [];
        foreach ($subList as $sub) {
            $smtype = $sub['media_type'] ?? '';
            $stype = $sub['type'] ?? '';
            $sub_is_vid = ($smtype === 'video' || strpos(strtolower($stype), 'video') !== false);

            if ($sub_is_vid) {
                $is_video_post = true;
                if (!empty($sub['media']['source'])) {
                    $videos[] = $sub['media']['source'];
                }
            } else {
                if (!empty($sub['media']['image']['src'])) {
                    $images[] = $sub['media']['image']['src'];
                }
            }
        }
    }

    $item_debug_log = [];
    if ($is_video_post && empty($videos)) {
        $vurl = get_video_source_from_post_full($token, $post, $page_videos_map, $item_debug_log);
        if ($vurl) {
            $videos[] = $vurl;
        }
    }

    if (!$is_video_post && empty($images) && !empty($pic)) {
        $images[] = $pic;
    }

    $final_images = array_values(array_unique(array_filter($images)));
    $final_videos = array_values(array_unique(array_filter($videos)));

    $post_type = 'text';
    if ($is_video_post || !empty($final_videos)) {
        $post_type = 'video';
    } elseif (!empty($final_images)) {
        $post_type = 'photo';
    }

    $cdn_video_url = '';
    $raw_video_url = $final_videos[0] ?? '';

    // Upload video lên CDN data.hongdolab.com/uploads nếu là video
    if ($post_type === 'video' && !empty($raw_video_url) && $do_upload_cdn) {
        $tmp_file = sys_get_temp_dir() . '/test_scrape_vid_' . uniqid() . '.mp4';
        $ch = curl_init($raw_video_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $vbytes = curl_exec($ch);
        curl_close($ch);

        if ($vbytes && strlen($vbytes) > 1000) {
            file_put_contents($tmp_file, $vbytes);
            $uploaded_cdn = upload_file_to_hongdolab_cdn($tmp_file);
            @unlink($tmp_file);
            if ($uploaded_cdn) {
                $cdn_video_url = $uploaded_cdn;
            }
        }
    }

    $item_result = [
        'stt' => count($posts_scraped) + 1,
        'id' => $fbid,
        'created_time' => $c_at,
        'post_type' => $post_type,
        'message' => $msg,
        'thumbnail' => $pic,
        'images' => $final_images,
        'videos' => $final_videos,
        'video_url' => $cdn_video_url ?: $raw_video_url,
        'cdn_video_url' => $cdn_video_url,
        'raw_video_url' => $raw_video_url,
        'likes' => $lik,
        'comments' => $com,
        'shares' => $sha
    ];

    if ($is_debug) {
        $item_result['debug_log'] = $item_debug_log;
    }

    $posts_scraped[] = $item_result;

    if (count($posts_scraped) >= $limit) break;
}

// Lưu file kết quả JSON local
$json_filename = $page_id . '_scraped_results.json';
file_put_contents(__DIR__ . '/' . $json_filename, json_encode($posts_scraped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$response_out = [
    'status' => 'success',
    'scraped_page_id' => $page_id,
    'total_scraped' => count($posts_scraped),
    'endpoint_used' => $api_endpoint_used,
    'token_used' => $token_owner,
    'saved_file' => $json_filename,
    'data' => $posts_scraped
];

echo json_encode($response_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
