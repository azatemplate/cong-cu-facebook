<?php
// ─── Ensure Database & Tables Exist ──────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

if (!function_exists('ensureScraperTables')) {
    function ensureScraperTables($pdo) {
        static $done = false;
        if ($done) return;
        $flag = sys_get_temp_dir() . '/scraper_tables_v2.done';
        if (file_exists($flag)) {
            $done = true;
            return;
        }
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS scraper_pages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    user_id INT NOT NULL,
                    page_id VARCHAR(255) NOT NULL,
                    page_name VARCHAR(255),
                    followers_count INT DEFAULT 0,
                    post_count INT DEFAULT 0,
                    access_token TEXT,
                    auto_refresh_hours INT DEFAULT 0,
                    last_scraped_at DATETIME DEFAULT NULL,
                    only_with_content TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_scraper_page (account_id, page_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            try {
                $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'auto_refresh_hours'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN auto_refresh_hours INT DEFAULT 0");
                }
                $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'only_with_content'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN only_with_content TINYINT(1) DEFAULT 0");
                }
                $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'post_count'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN post_count INT DEFAULT 0");
                }
                $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'last_scraped_at'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN last_scraped_at DATETIME DEFAULT NULL");
                }
            } catch (Exception $e1) {}

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS scraper_posts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    page_id VARCHAR(255) NOT NULL,
                    fb_post_id VARCHAR(255) NOT NULL,
                    message TEXT,
                    picture TEXT,
                    shares INT DEFAULT 0,
                    comments INT DEFAULT 0,
                    likes INT DEFAULT 0,
                    post_created_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_post (page_id, fb_post_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS scraper_bots (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    user_id INT DEFAULT 0,
                    name VARCHAR(255) NOT NULL,
                    sources JSON DEFAULT NULL,
                    targets JSON DEFAULT NULL,
                    check_interval_seconds INT DEFAULT 300,
                    target_post_gap_seconds INT DEFAULT 0,
                    range_filter VARCHAR(20) DEFAULT 'today',
                    format_filter VARCHAR(20) DEFAULT 'all',
                    distribute_mode VARCHAR(20) DEFAULT 'all',
                    find_words TEXT DEFAULT NULL,
                    replace_words TEXT DEFAULT NULL,
                    remove_hashtag TINYINT(1) DEFAULT 0,
                    remove_link TINYINT(1) DEFAULT 1,
                    seeding JSON DEFAULT NULL,
                    status VARCHAR(20) DEFAULT 'stopped',
                    last_run_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_bot_account (account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            try {
                $col = $pdo->query("SHOW COLUMNS FROM scraper_bots LIKE 'user_id'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_bots ADD COLUMN user_id INT DEFAULT 0");
                }
            } catch (Exception $e2) {}

            try {
                $col = $pdo->query("SHOW COLUMNS FROM scraper_bots LIKE 'only_with_content'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_bots ADD COLUMN only_with_content TINYINT(1) DEFAULT 0");
                }
                $col = $pdo->query("SHOW COLUMNS FROM scraper_bots LIKE 'use_ai_rewrite'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_bots ADD COLUMN use_ai_rewrite TINYINT(1) DEFAULT 0");
                }
            } catch (Exception $e_bot_cols) {}

            try {
                $col = $pdo->query("SHOW COLUMNS FROM scraper_bot_logs LIKE 'content_hash'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_bot_logs ADD COLUMN content_hash VARCHAR(64) DEFAULT NULL, ADD INDEX idx_target_hash (target_page_id, content_hash)");
                }
            } catch (Exception $e3) {}

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS scraper_bot_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    bot_id INT NOT NULL,
                    account_id INT NOT NULL,
                    source_post_id VARCHAR(255) NOT NULL,
                    source_page_id VARCHAR(255) DEFAULT NULL,
                    source_page_name VARCHAR(255) DEFAULT NULL,
                    source_permalink VARCHAR(500) DEFAULT NULL,
                    target_page_id VARCHAR(255) DEFAULT NULL,
                    target_page_name VARCHAR(255) DEFAULT NULL,
                    posted_permalink VARCHAR(500) DEFAULT NULL,
                    post_type VARCHAR(50) DEFAULT 'text',
                    post_content TEXT DEFAULT NULL,
                    status ENUM('success', 'fail') DEFAULT 'success',
                    error_message TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_log_bot (bot_id),
                    INDEX idx_log_source_post (source_post_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            @touch($flag);
            $done = true;
        } catch (Exception $e) {}
    }
}

ensureScraperTables($pdo);

if (!function_exists('getUserToken')) {
    function getUserToken($pdo, $user_id, $account_id, $is_admin) {
        if ($is_admin) {
            $stmt = $pdo->prepare("SELECT access_token FROM users WHERE id = :uid LIMIT 1");
            $stmt->execute(['uid' => $user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT access_token FROM users WHERE id = :uid AND account_id = :aid LIMIT 1");
            $stmt->execute(['uid' => $user_id, 'aid' => $account_id]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? decryptData($user['access_token']) : null;
    }
}
if (!function_exists('executeScraperBot')) {
    if (!function_exists('upload_file_to_hongdolab_cdn')) {
    function upload_file_to_hongdolab_cdn($file_path) {
        if (!file_exists($file_path) || filesize($file_path) < 10) return false;
        @set_time_limit(0);
        
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) ?: 'mp4';
        $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $filename = basename($file_path);
        $filesize = filesize($file_path);

        // 1. SIÊU TỐC THỜI GIAN THỰC (0.001s): Nếu thư mục uploads của data.hongdolab.com có sẵn trên máy chủ
        $possible_dirs = [
            '/www/wwwroot/data.hongdolab.com/uploads/',
            __DIR__ . '/../data.hongdolab.com/uploads/',
            dirname(__DIR__) . '/uploads/'
        ];

        $doc_root = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
        if ($doc_root) {
            $possible_dirs[] = dirname($doc_root) . '/data.hongdolab.com/uploads/';
        }

        foreach ($possible_dirs as $target_cdn_dir) {
            if (is_dir($target_cdn_dir) && is_writable($target_cdn_dir)) {
                $prefix = $is_image ? 'img_' : 'vid_';
                $stored_name = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $final_target = $target_cdn_dir . $stored_name;
                if (@copy($file_path, $final_target) || @move_uploaded_file($file_path, $final_target)) {
                    @chmod($final_target, 0777);
                    clearstatcache(true, $final_target);
                    if (file_exists($final_target) && filesize($final_target) == $filesize) {
                        return 'https://data.hongdolab.com/uploads/' . $stored_name;
                    }
                }
            }
        }

        // 2. NẾU QUA HTTP API: Dùng Single-Request POST (action=video / action=image) - Không phân mảnh chunk gây lỗi
        $action_type = $is_image ? 'image' : 'video';
        $field_name  = $is_image ? 'image' : 'video';
        $mime        = function_exists('mime_content_type') ? mime_content_type($file_path) : ($is_image ? ('image/' . $ext) : ('video/' . $ext));

        $ch = curl_init('https://data.hongdolab.com/api/upload_video.php?action=' . $action_type);
        $cfile = new CURLFile($file_path, $mime, $filename);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [$field_name => $cfile],
            CURLOPT_TIMEOUT => 600,
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
        return false;
    }
}

if (!function_exists('is_post_within_range')) {
    function is_post_within_range($created_time, $range_filter) {
        if (empty($range_filter) || $range_filter === 'all') {
            return true;
        }
        if (empty($created_time)) {
            return true;
        }

        $post_ts = is_numeric($created_time) ? intval($created_time) : strtotime($created_time);
        if (!$post_ts) return true;
        $now = time();

        switch ($range_filter) {
            case '1h':
                return ($now - $post_ts) <= 3600;
            case '2h':
                return ($now - $post_ts) <= 7200;
            case '3h':
                return ($now - $post_ts) <= 10800;
            case '6h':
                return ($now - $post_ts) <= 21600;
            case '12h':
                return ($now - $post_ts) <= 43200;
            case '24h':
            case '1d':
                return ($now - $post_ts) <= 86400;
            case '3d':
                return ($now - $post_ts) <= (3 * 86400);
            case '7d':
                return ($now - $post_ts) <= (7 * 86400);
            case '30d':
                return ($now - $post_ts) <= (30 * 86400);
            case 'today':
                return $post_ts >= strtotime('today midnight');
            default:
                if (preg_match('/^(\d+)h$/i', $range_filter, $m)) {
                    return ($now - $post_ts) <= (intval($m[1]) * 3600);
                }
                if (preg_match('/^(\d+)d$/i', $range_filter, $m)) {
                    return ($now - $post_ts) <= (intval($m[1]) * 86400);
                }
                return true;
        }
    }
}

if (!function_exists('scrape_fb_video_mp4_url')) {
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
}

if (!function_exists('get_video_source_from_post')) {
    function get_video_source_from_post($token, $post, $page_videos_map = []) {
        $fbid = $post['id'] ?? '';

        // 1. Direct attachment media source
        $attachments = $post['attachments']['data'][0] ?? null;
        if (!empty($attachments['media']['source'])) {
            return $attachments['media']['source'];
        }

        // Subattachments media source
        $subList = $attachments['subattachments']['data'] ?? [];
        foreach ($subList as $sub) {
            if (!empty($sub['media']['source'])) {
                return $sub['media']['source'];
            }
        }

        // 2. Direct object_id Graph API lookup (Highest accuracy for FB Video Posts & Reels)
        $object_id = $post['object_id'] ?? '';
        if (!empty($object_id)) {
            if (!empty($page_videos_map[$object_id])) {
                return $page_videos_map[$object_id];
            }
            $resObj = fb_api_request("{$object_id}", ['access_token' => $token, 'fields' => 'source,playable_url,playable_url_quality_hd']);
            if (!empty($resObj['data']['source'])) return $resObj['data']['source'];
            if (!empty($resObj['data']['playable_url_quality_hd'])) return $resObj['data']['playable_url_quality_hd'];
            if (!empty($resObj['data']['playable_url'])) return $resObj['data']['playable_url'];
        }

        // 3. Target ID Graph API lookup
        $target_id = $attachments['target']['id'] ?? '';
        if (!empty($target_id) && $target_id !== $object_id) {
            if (!empty($page_videos_map[$target_id])) {
                return $page_videos_map[$target_id];
            }
            $res = fb_api_request("{$target_id}", ['access_token' => $token, 'fields' => 'source,playable_url,playable_url_quality_hd']);
            if (!empty($res['data']['source'])) return $res['data']['source'];
            if (!empty($res['data']['playable_url_quality_hd'])) return $res['data']['playable_url_quality_hd'];
            if (!empty($res['data']['playable_url'])) return $res['data']['playable_url'];
        }

        // 4. Query Post ID directly with object_id lookup
        if ($fbid) {
            $resPost = fb_api_request("{$fbid}", ['access_token' => $token, 'fields' => 'source,object_id']);
            if (!empty($resPost['data']['source'])) return $resPost['data']['source'];
            if (!empty($resPost['data']['object_id'])) {
                $objId = $resPost['data']['object_id'];
                $resObj2 = fb_api_request("{$objId}", ['access_token' => $token, 'fields' => 'source,playable_url,playable_url_quality_hd']);
                if (!empty($resObj2['data']['source'])) return $resObj2['data']['source'];
                if (!empty($resObj2['data']['playable_url_quality_hd'])) return $resObj2['data']['playable_url_quality_hd'];
                if (!empty($resObj2['data']['playable_url'])) return $resObj2['data']['playable_url'];
            }
        }

        // 5. Extract Video/Reel ID candidates from picture URL
        $pic = $post['full_picture'] ?? ($post['picture'] ?? '');
        if ($pic) {
            if (preg_match_all('/(?:[\/_]|^)(\d{13,16})(?:[\/_]|\.|$)/', $pic, $m_all)) {
                $vid_candidates = array_unique($m_all[1]);
                foreach ($vid_candidates as $vid_id) {
                    if (!empty($page_videos_map[$vid_id])) {
                        return $page_videos_map[$vid_id];
                    }
                    $res = fb_api_request("{$vid_id}", ['access_token' => $token, 'fields' => 'source,playable_url,playable_url_quality_hd']);
                    if (!empty($res['data']['source'])) return $res['data']['source'];
                    if (!empty($res['data']['playable_url_quality_hd'])) return $res['data']['playable_url_quality_hd'];
                    if (!empty($res['data']['playable_url'])) return $res['data']['playable_url'];
                }
            }
        }

        // 6. Attachment URL HTML Scrape fallback
        $url = $attachments['url'] ?? ($attachments['target']['url'] ?? ($post['permalink_url'] ?? ''));
        if ($url) {
            $h_src = scrape_fb_video_mp4_url($url);
            if ($h_src) return $h_src;
        }

        if ($target_id) {
            $watch_url = "https://www.facebook.com/watch/?v=" . $target_id;
            $h_src = scrape_fb_video_mp4_url($watch_url);
            if ($h_src) return $h_src;
        }

        return '';
    }
}


if (!function_exists('extract_post_media_urls')) {
    function extract_post_media_urls($post, $token = '') {
        $images = [];
        $videos = [];
        $is_video_post = false;

        $fbid = $post['id'] ?? '';
        $status_type = strtolower($post['status_type'] ?? '');
        if (strpos($status_type, 'video') !== false) {
            $is_video_post = true;
        }

        // Fetch attachments for single post node ID if not present in feed response (prevents Graph API #12 error)
        if (empty($post['attachments']) && !empty($fbid) && !empty($token)) {
            $resAtt = fb_api_request("{$fbid}", ['access_token' => $token, 'fields' => 'attachments']);
            if (!empty($resAtt['data']['attachments'])) {
                $post['attachments'] = $resAtt['data']['attachments'];
            }
        }

        $attachList = $post['attachments']['data'] ?? [];
        foreach ($attachList as $att) {
            $mtype = strtolower($att['media_type'] ?? '');
            $type = strtolower($att['type'] ?? '');
            $ttype = strtolower($att['target']['type'] ?? '');
            $url = strtolower($att['url'] ?? ($att['target']['url'] ?? ''));

            $is_vid = ($mtype === 'video' || $mtype === 'reel' || 
                       strpos($type, 'video') !== false || strpos($type, 'reel') !== false || 
                       strpos($ttype, 'video') !== false || strpos($ttype, 'reel') !== false ||
                       strpos($url, '/reel/') !== false || strpos($url, '/watch') !== false || strpos($url, '/videos/') !== false);

            if ($is_vid || !empty($att['media']['source'])) {
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
                $smtype = strtolower($sub['media_type'] ?? '');
                $stype = strtolower($sub['type'] ?? '');
                $sttype = strtolower($sub['target']['type'] ?? '');
                $surl = strtolower($sub['url'] ?? ($sub['target']['url'] ?? ''));

                $sub_is_vid = ($smtype === 'video' || $smtype === 'reel' || 
                               strpos($stype, 'video') !== false || strpos($stype, 'reel') !== false || 
                               strpos($sttype, 'video') !== false || strpos($sttype, 'reel') !== false ||
                               strpos($surl, '/reel/') !== false || strpos($surl, '/watch') !== false || strpos($surl, '/videos/') !== false);

                if ($sub_is_vid || !empty($sub['media']['source'])) {
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

        if (empty($videos)) {
            $v_source = get_video_source_from_post($token, $post);
            if ($v_source) {
                $videos[] = $v_source;
                $is_video_post = true;
            }
        }

        if (!$is_video_post && empty($images) && !empty($post['full_picture'])) {
            $images[] = $post['full_picture'];
        }

        $post_type = $is_video_post ? 'video' : (!empty($images) ? 'photo' : 'text');

        return [
            'is_video' => $is_video_post,
            'post_type' => $post_type,
            'images' => $is_video_post ? [] : array_values(array_unique(array_filter($images))),
            'videos' => array_values(array_unique(array_filter($videos)))
        ];
    }
}

if (!function_exists('publish_post_with_media_to_fb_page')) {
    function publish_post_with_media_to_fb_page($ptoken, $target_page_id, $msg, $post, $user_token = '') {
        $token_to_use = $user_token ?: $ptoken;
        $media = extract_post_media_urls($post, $token_to_use);
        $images = $media['images'];
        $videos = $media['videos'];
        $is_video = $media['is_video'];

        // 1. VIDEO POSTING
        if ($is_video || !empty($videos)) {
            if (empty($videos)) {
                $v_source = get_video_source_from_post($token_to_use, $post);
                if ($v_source) {
                    $videos[] = $v_source;
                }
            }

            if (!empty($videos)) {
                $video_url = $videos[0];
                $tmp_vid = sys_get_temp_dir() . '/scraped_vid_' . uniqid() . '.mp4';
                $ch = curl_init($video_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0
                ]);
                $vdata = curl_exec($ch);
                curl_close($ch);

                if ($vdata && strlen($vdata) > 1000) {
                    file_put_contents($tmp_vid, $vdata);
                    $cdn_url = upload_file_to_hongdolab_cdn($tmp_vid);
                    @unlink($tmp_vid);

                    if ($cdn_url) {
                        return fb_api_request("{$target_page_id}/videos", ['access_token' => $ptoken], 'POST', [
                            'file_url' => $cdn_url,
                            'description' => $msg
                        ]);
                    }
                }

                return fb_api_request("{$target_page_id}/videos", ['access_token' => $ptoken], 'POST', [
                    'file_url' => $video_url,
                    'description' => $msg
                ]);
            }
        }

        // 2. PHOTO POSTING (Single or Multi-photo)
        if (!empty($images)) {
            $attached_media = [];

            foreach ($images as $img_url) {
                $tmp_img = sys_get_temp_dir() . '/scraped_img_' . uniqid() . '.jpg';
                $ch = curl_init($img_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0
                ]);
                $idata = curl_exec($ch);
                curl_close($ch);

                if ($idata && strlen($idata) > 500) {
                    file_put_contents($tmp_img, $idata);

                    $upload_res = fb_api_request("{$target_page_id}/photos", ['access_token' => $ptoken], 'POST', [
                        'published' => 'false',
                        'source' => new CURLFile($tmp_img, 'image/jpeg', 'photo.jpg')
                    ]);
                    @unlink($tmp_img);

                    if (!empty($upload_res['data']['id'])) {
                        $attached_media[] = ['media_fbid' => $upload_res['data']['id']];
                    }
                }
            }

            if (!empty($attached_media)) {
                $payload = [
                    'message' => $msg,
                    'attached_media' => json_encode($attached_media)
                ];
                return fb_api_request("{$target_page_id}/feed", ['access_token' => $ptoken], 'POST', $payload);
            }

            return fb_api_request("{$target_page_id}/photos", ['access_token' => $ptoken], 'POST', [
                'url' => $images[0],
                'caption' => $msg
            ]);
        }

        // 3. TEXT ONLY POSTING
        return fb_api_request("{$target_page_id}/feed", ['access_token' => $ptoken], 'POST', [
            'message' => $msg
        ]);
    }
}


function executeScraperBot($pdo, $bot_id, $account_id) {
        $stmt = $pdo->prepare("SELECT * FROM scraper_bots WHERE id = :id AND account_id = :aid LIMIT 1");
        $stmt->execute(['id' => $bot_id, 'aid' => $account_id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$b) {
            return ['status' => 'error', 'message' => 'Không tìm thấy cấu hình BOT.'];
        }

        // Cập nhật last_run_at ngay lập tức để tránh tình trạng chạy trùng lặp đồng thời (race condition khi click Chạy + Cron)
        $pdo->prepare("UPDATE scraper_bots SET last_run_at = :now WHERE id = :id")->execute(['now' => date('Y-m-d H:i:s'), 'id' => $bot_id]);

        $bot_user_id = intval($b['user_id'] ?? 0);
        if ($bot_user_id <= 0) {
            return ['status' => 'error', 'message' => 'BOT chưa chọn User Quản Lý Token. Vui lòng Bấm "Sửa" và chọn User.'];
        }

        $sources = json_decode($b['sources'] ?? '[]', true) ?: [];
        $raw_targets = json_decode($b['targets'] ?? '[]', true) ?: [];
        $find_words = explode(',', $b['find_words'] ?? '');
        $replace_words = explode(',', $b['replace_words'] ?? '');

        // Lọc trùng danh sách Page đích theo page_id
        $targets = [];
        $seen_t_ids = [];
        foreach ($raw_targets as $tItem) {
            $tid = trim($tItem['page_id'] ?? '');
            if ($tid !== '' && !isset($seen_t_ids[$tid])) {
                $seen_t_ids[$tid] = true;
                $targets[] = $tItem;
            }
        }

        if (empty($sources)) {
            return ['status' => 'error', 'message' => 'BOT chưa chọn Page nguồn nào.'];
        }

        if (empty($targets)) {
            return ['status' => 'error', 'message' => 'BOT chưa chọn Page đích nào.'];
        }

        $is_admin = false;
        try {
            $stmtRole = $pdo->prepare("SELECT role FROM system_accounts WHERE id = :aid LIMIT 1");
            $stmtRole->execute(['aid' => $account_id]);
            if ($stmtRole->fetchColumn() === 'admin') {
                $is_admin = true;
            }
        } catch (Exception $e) {}

        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $is_admin = true;
        }

        $user_token = getUserToken($pdo, $bot_user_id, $account_id, $is_admin);

        if (!$user_token) {
            $stmtUName = $pdo->prepare("SELECT name FROM users WHERE id = :uid LIMIT 1");
            $stmtUName->execute(['uid' => $bot_user_id]);
            $uName = $stmtUName->fetchColumn() ?: "ID {$bot_user_id}";
            return ['status' => 'error', 'message' => "User {$uName} chưa có Access Token hoặc Token đã hết hạn."];
        }

        // Fetch target pages access tokens from DB (owned + shared)
        $stmtP = $pdo->prepare("
            (SELECT p.page_id, p.name, p.access_token 
             FROM pages p JOIN users u ON p.user_id = u.id 
             WHERE u.account_id = :aid)
            UNION
            (SELECT p.page_id, p.name, p.access_token 
             FROM pages p 
             JOIN page_shares ps ON p.page_id = ps.page_id 
             WHERE ps.shared_with_account_id = :aid2)
        ");
        $stmtP->bindValue(':aid', $account_id, PDO::PARAM_INT);
        $stmtP->bindValue(':aid2', $account_id, PDO::PARAM_INT);
        $stmtP->execute();
        $user_pages = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $targetTokens = [];
        foreach ($user_pages as $up) {
            $targetTokens[$up['page_id']] = [
                'name' => $up['name'],
                'token' => decryptData($up['access_token'])
            ];
        }

        $logs = [];
        $sourcesChecked = 0;
        $postsFound = 0;
        $postsPublished = 0;
        $postsFailed = 0;
        $totalPublishedActions = 0;
        $gap = max(0, intval($b['target_post_gap_seconds'] ?? 0));

        foreach ($sources as $src) {
            $source_id = trim($src['id'] ?? '');
            if (!$source_id) continue;
            $sourcesChecked++;

            $fields = 'id,object_id,message,created_time,full_picture,status_type,shares,comments.summary(total_count),reactions.summary(total_count)';
            $res = fb_api_request("{$source_id}/posts", [
                'access_token' => $user_token,
                'fields' => $fields,
                'limit' => 15
            ]);

            if ($res['status_code'] !== 200) {
                $logs[] = [
                    'type' => 'fail',
                    'message' => 'Không quét được bài từ Page nguồn ' . ($src['name'] ?: $source_id) . ': ' . ($res['data']['error']['message'] ?? 'Lỗi API FB')
                ];
                continue;
            }

            $postsData = $res['data']['data'] ?? [];
            foreach ($postsData as $post) {
                $fbid = $post['id'] ?? '';
                if (!$fbid) continue;

                $postsFound++;
                $msg = $post['message'] ?? '';
                $picture = $post['full_picture'] ?? '';
                $created_time = $post['created_time'] ?? '';

                // Extract media info
                $media = extract_post_media_urls($post, $user_token);

                // CHỈ LẤY BÀI VIẾT DẠNG PHOTO (CÓ HÌNH ẢNH IMAGES), BỎ QUA HOÀN TOÀN BÀI VIẾT DẠNG VIDEO
                if (!empty($media['is_video']) || $media['post_type'] === 'video') {
                    continue;
                }

                if ($media['post_type'] !== 'photo' || empty($media['images'])) {
                    continue;
                }

                // Date range filter (Hỗ trợ 1h, 2h, 3h, 6h, 12h, 24h, today, 3d, 7d, 30d)
                if (!is_post_within_range($created_time, $b['range_filter'] ?? 'today')) {
                    continue;
                }

                // Cấu hình 1: Chỉ lấy bài có nội dung chữ (Bỏ qua bài chỉ có ảnh không có chữ)
                if (!empty($b['only_with_content']) && trim($msg) === '') {
                    continue;
                }

                // Apply text rules
                if (!empty($b['remove_hashtag'])) {
                    $msg = preg_replace('/#[^\s#]+/u', '', $msg);
                }
                if (!empty($b['remove_link'])) {
                    $msg = preg_replace('/https?:\/\/[^\s]+/i', '', $msg);
                }
                if (!empty($find_words)) {
                    foreach ($find_words as $idx => $fw) {
                        $fw = trim($fw);
                        if ($fw !== '') {
                            $rw = trim($replace_words[$idx] ?? '');
                            $msg = str_ireplace($fw, $rw, $msg);
                        }
                    }
                }
                $msg = trim($msg);

                // Cấu hình 2: Sử dụng AI viết lại bài trước khi đăng
                if (!empty($b['use_ai_rewrite']) && $msg !== '') {
                    require_once __DIR__ . '/includes/ai_rewriter.php';
                    if (function_exists('rewrite_content_with_ai')) {
                        $ai_msg = rewrite_content_with_ai($msg, $account_id, false, $src['name'] ?? '');
                        if (!empty($ai_msg)) {
                            $msg = trim($ai_msg);
                        }
                    }
                }

                // Determine target pages to post to (Check per target page dedup)
                $selected_targets = [];
                if ($b['distribute_mode'] === 'random' && !empty($targets)) {
                    $unposted_targets = [];
                    foreach ($targets as $t) {
                        $t_id = trim($t['page_id'] ?? '');
                        if ($t_id === '' || $t_id === $source_id) continue;
                        $content_hash = md5($fbid . '_' . trim($msg));
                        $stmtCheck = $pdo->prepare("SELECT id FROM scraper_bot_logs WHERE target_page_id = :tpid AND (source_post_id = :spid OR (content_hash IS NOT NULL AND content_hash = :chash)) AND status = 'success' LIMIT 1");
                        $stmtCheck->execute(['spid' => $fbid, 'tpid' => $t_id, 'chash' => $content_hash]);
                        if (!$stmtCheck->fetch()) {
                            $unposted_targets[] = $t;
                        }
                    }
                    if (empty($unposted_targets)) {
                        continue;
                    }
                    $selected_targets = [$unposted_targets[array_rand($unposted_targets)]];
                } else {
                    $selected_targets = $targets;
                }

                foreach ($selected_targets as $tItem) {
                    $target_page_id = trim($tItem['page_id'] ?? '');
                    if (!$target_page_id) continue;

                    // Không bao giờ đăng ngược lại chính Page Nguồn
                    if ($target_page_id === $source_id) {
                        continue;
                    }

                    // Deduplication check: đã đăng bài này lên Page đích này chưa (kiểm tra trên toàn bộ hệ thống log cho page đích)
                    $content_hash = md5($fbid . '_' . trim($msg));
                    $stmtCheck = $pdo->prepare("SELECT id FROM scraper_bot_logs WHERE target_page_id = :tpid AND (source_post_id = :spid OR (content_hash IS NOT NULL AND content_hash = :chash)) AND status = 'success' LIMIT 1");
                    $stmtCheck->execute(['spid' => $fbid, 'tpid' => $target_page_id, 'chash' => $content_hash]);
                    if ($stmtCheck->fetch()) {
                        continue;
                    }

                    // Tạm dừng $gap giây giữa MỖI LẦN đăng bài thực tế
                    if ($totalPublishedActions > 0 && $gap > 0) {
                        sleep($gap);
                    }
                    $target_page_name = $tItem['page_name'] ?? $target_page_id;
                    $ptokenData = $targetTokens[$target_page_id] ?? null;

                    if (!$ptokenData || empty($ptokenData['token'])) {
                        $logs[] = [
                            'type' => 'fail',
                            'message' => "Thiếu Page Access Token cho Page đích {$target_page_name} ({$target_page_id})"
                        ];
                        $postsFailed++;
                        
                        $stmtLog = $pdo->prepare("
                            INSERT INTO scraper_bot_logs (bot_id, account_id, source_post_id, source_page_id, source_page_name, source_permalink, target_page_id, target_page_name, post_type, post_content, content_hash, status, error_message)
                            VALUES (:bid, :aid, :spid, :spage_id, :spage_name, :slink, :tpage_id, :tpage_name, :ptype, :content, :chash, 'fail', :err)
                        ");
                        $stmtLog->execute([
                            'bid' => $bot_id, 'aid' => $account_id, 'spid' => $fbid, 'spage_id' => $source_id,
                            'spage_name' => $src['name'] ?: $source_id, 'slink' => "https://facebook.com/{$fbid}",
                            'tpage_id' => $target_page_id, 'tpage_name' => $target_page_name,
                            'ptype' => $picture ? 'photo' : 'text', 'content' => mb_substr($msg, 0, 500),
                            'chash' => $content_hash, 'err' => "Thiếu Page Access Token"
                        ]);
                        continue;
                    }

                    $ptoken = $ptokenData['token'];
                    // Đăng bài với đầy đủ Media (Tự động tải ảnh/video về server & upload đính kèm bài viết Facebook)
                    $postResult = publish_post_with_media_to_fb_page($ptoken, $target_page_id, $msg, $post, $user_token);

                    if ($postResult['status_code'] === 200 && !empty($postResult['data']['id'])) {
                        $published_id = $postResult['data']['id'];
                        $posted_permalink = "https://facebook.com/{$published_id}";
                        $postsPublished++; $totalPublishedActions++;

                        $logs[] = [
                            'type' => 'success',
                            'message' => "Đã tự động đăng bài lên {$target_page_name} thành công!",
                            'posted_link' => $posted_permalink,
                            'content' => $msg
                        ];

                        $stmtLog = $pdo->prepare("
                            INSERT INTO scraper_bot_logs (bot_id, account_id, source_post_id, source_page_id, source_page_name, source_permalink, target_page_id, target_page_name, posted_permalink, post_type, post_content, content_hash, status, error_message)
                            VALUES (:bid, :aid, :spid, :spage_id, :spage_name, :slink, :tpage_id, :tpage_name, :plink, :ptype, :content, :chash, 'success', '')
                        ");
                        $stmtLog->execute([
                            'bid' => $bot_id, 'aid' => $account_id, 'spid' => $fbid, 'spage_id' => $source_id,
                            'spage_name' => $src['name'] ?: $source_id, 'slink' => "https://facebook.com/{$fbid}",
                            'tpage_id' => $target_page_id, 'tpage_name' => $target_page_name,
                            'plink' => $posted_permalink, 'ptype' => $picture ? 'photo' : 'text',
                            'content' => mb_substr($msg, 0, 500),
                            'chash' => $content_hash
                        ]);
                    } else {
                        $errMsg = $postResult['data']['error']['message'] ?? ($postResult['data']['error']['error_user_msg'] ?? ('Lỗi API Facebook (HTTP ' . ($postResult['status_code'] ?? 0) . ')'));
                        $postsFailed++;
                        $logs[] = [
                            'type' => 'fail',
                            'message' => "Lỗi đăng bài lên {$target_page_name}: {$errMsg}"
                        ];

                        $stmtLog = $pdo->prepare("
                            INSERT INTO scraper_bot_logs (bot_id, account_id, source_post_id, source_page_id, source_page_name, source_permalink, target_page_id, target_page_name, post_type, post_content, content_hash, status, error_message)
                            VALUES (:bid, :aid, :spid, :spage_id, :spage_name, :slink, :tpage_id, :tpage_name, :ptype, :content, :chash, 'fail', :err)
                        ");
                        $stmtLog->execute([
                            'bid' => $bot_id, 'aid' => $account_id, 'spid' => $fbid, 'spage_id' => $source_id,
                            'spage_name' => $src['name'] ?: $source_id, 'slink' => "https://facebook.com/{$fbid}",
                            'tpage_id' => $target_page_id, 'tpage_name' => $target_page_name,
                            'ptype' => $picture ? 'photo' : 'text', 'content' => mb_substr($msg, 0, 500),
                            'chash' => $content_hash, 'err' => $errMsg
                        ]);
                    }
                }
            }
        }

        // Cập nhật lại bot last_run_at sau khi hoàn thành
        $pdo->prepare("UPDATE scraper_bots SET last_run_at = :now WHERE id = :id")->execute(['now' => date('Y-m-d H:i:s'), 'id' => $bot_id]);

        return [
            'status' => 'success',
            'summary' => [
                'sources_checked' => $sourcesChecked,
                'posts_found' => $postsFound,
                'posts_published' => $postsPublished,
                'posts_failed' => $postsFailed
            ],
            'logs' => $logs
        ];
    }
}

// ─── Handle AJAX Requests ──────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    if (!isset($_SESSION['account_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Chưa đăng nhập.']);
        exit;
    }

    $account_id = $_SESSION['account_id'];
    $ajax = $_GET['ajax'];

    // Get target pages list
    if ($ajax === 'get_target_pages') {
        try {
            $stmtP = $pdo->prepare("
                (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id 
                 FROM pages p JOIN users u ON p.user_id = u.id 
                 WHERE u.account_id = :aid)
                UNION
                (SELECT p.id, p.page_id, p.name, p.avatar, u.id as user_id
                 FROM pages p 
                 JOIN page_shares ps ON p.page_id = ps.page_id 
                 JOIN users u ON p.user_id = u.id
                 WHERE ps.shared_with_account_id = :aid2)
                ORDER BY name ASC
            ");
            $stmtP->bindValue(':aid', $account_id, PDO::PARAM_INT);
            $stmtP->bindValue(':aid2', $account_id, PDO::PARAM_INT);
            $stmtP->execute();
            $pages = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $stmtP = $pdo->prepare("
                SELECT p.id, p.page_id, p.name, p.avatar, p.user_id 
                FROM pages p
                JOIN users u ON p.user_id = u.id
                WHERE u.account_id = :aid
                ORDER BY p.name ASC
            ");
            $stmtP->execute(['aid' => $account_id]);
            $pages = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        echo json_encode(['status' => 'success', 'data' => $pages]);
        exit;
    }

    // Get page info from FB
    if ($ajax === 'get_page') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $page_id = trim($_POST['page_id'] ?? '');

        if ($user_id <= 0 || $page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập đủ User và Page ID.']);
            exit;
        }

        $is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
        $token = getUserToken($pdo, $user_id, $account_id, $is_admin);

        if (!$token) {
            echo json_encode(['status' => 'error', 'message' => 'User chưa có Access Token hoặc bạn không có quyền.']);
            exit;
        }

        $res = fb_api_request("{$page_id}", [
            'access_token' => $token,
            'fields' => 'id,name,followers_count'
        ]);

        if ($res['status_code'] !== 200) {
            $errMsg = $res['data']['error']['message'] ?? 'Không truy cập được Page bằng User Token này.';
            echo json_encode(['status' => 'error', 'message' => 'Lỗi API: ' . $errMsg]);
            exit;
        }

        $page_name = $res['data']['name'] ?? 'Không rõ';
        $followers = $res['data']['followers_count'] ?? 0;
        $encrypted_page_token = '';

        $stmtU = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmtU->execute([$user_id]);
        $user_name = $stmtU->fetchColumn() ?: 'Không rõ';

        $auto_refresh_hours = (isset($_POST['auto_refresh']) && $_POST['auto_refresh'] == '1') ? intval($_POST['refresh_hours'] ?? 10) : 0;
        $only_with_content = (isset($_POST['only_with_content']) && $_POST['only_with_content'] == '1') ? 1 : 0;

        $stmtPage = $pdo->prepare("
            INSERT INTO scraper_pages (account_id, user_id, page_id, page_name, followers_count, access_token, auto_refresh_hours, only_with_content) 
            VALUES (:aid, :uid, :pid, :pname, :followers, :ptoken, :arh, :owc)
            ON DUPLICATE KEY UPDATE user_id = :uid, page_name = :pname, followers_count = :followers, access_token = :ptoken, auto_refresh_hours = :arh, only_with_content = :owc
        ");
        $stmtPage->execute([
            'aid' => $account_id,
            'uid' => $user_id,
            'pid' => $res['data']['id'] ?? $page_id,
            'pname' => $page_name,
            'followers' => $followers,
            'ptoken' => $encrypted_page_token,
            'arh' => $auto_refresh_hours,
            'owc' => $only_with_content
        ]);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'user_id' => $user_id,
                'user_name' => $user_name,
                'page_id' => $res['data']['id'] ?? $page_id,
                'page_name' => $page_name,
                'followers_count' => $followers
            ]
        ]);
        exit;
    }

    // Get saved posts
    if ($ajax === 'get_saved_posts') {
        $page_id = trim($_POST['page_id'] ?? '');
        if ($page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Page ID bị thiếu.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT fb_post_id as id, message, post_created_at as created_time, picture, shares, comments, likes 
            FROM scraper_posts 
            WHERE page_id = :pid 
            ORDER BY post_created_at DESC 
            LIMIT 100
        ");
        $stmt->execute(['pid' => $page_id]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $posts]);
        exit;
    }

    // Delete page
    if ($ajax === 'delete_page') {
        $page_id = trim($_POST['page_id'] ?? '');
        if ($page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Page ID bị thiếu.']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM scraper_pages WHERE page_id = :pid AND account_id = :aid");
        $stmt->execute(['pid' => $page_id, 'aid' => $account_id]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    // Toggle auto refresh
    if ($ajax === 'toggle_auto') {
        $page_id = trim($_POST['page_id'] ?? '');
        $status = intval($_POST['status'] ?? 0);
        $hours = $status > 0 ? max(1, intval($_POST['hours'] ?? 10)) : 0;
        $only_with_content = intval($_POST['only_with_content'] ?? 0);

        if ($page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Page ID thiếu.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE scraper_pages SET auto_refresh_hours = :hours, only_with_content = :owc WHERE page_id = :pid AND account_id = :aid");
        $stmt->execute(['hours' => $hours, 'owc' => $only_with_content, 'pid' => $page_id, 'aid' => $account_id]);

        echo json_encode(['status' => 'success', 'hours' => $hours, 'only_with_content' => $only_with_content]);
        exit;
    }

    // Scrape posts manual
    if ($ajax === 'scrape') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $page_id = trim($_POST['page_id'] ?? '');
        $limit = max(1, min(100, intval($_POST['limit'] ?? 10)));
        $only_with_content = intval($_POST['only_with_content'] ?? 0);

        if ($user_id <= 0 || $page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'User ID hoặc Page ID bị thiếu.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT user_id, only_with_content FROM scraper_pages WHERE page_id = :pid AND account_id = :aid LIMIT 1");
        $stmt->execute(['pid' => $page_id, 'aid' => $account_id]);
        $scraper_page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$scraper_page) {
            echo json_encode(['status' => 'error', 'message' => 'Lỗi: Không tìm thấy Page được lưu. Bạn hãy Xóa page này bên dưới và Nhập lại.']);
            exit;
        }

        $is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
        $token = getUserToken($pdo, $scraper_page['user_id'], $account_id, $is_admin);
        
        if (!$token) {
            echo json_encode(['status' => 'error', 'message' => 'Lỗi: Không tìm thấy User Token hoặc token đã hết hạn.']);
            exit;
        }

        if (!isset($_POST['only_with_content'])) {
            $only_with_content = intval($scraper_page['only_with_content'] ?? 0);
        }

        try {
            $pdo->exec("ALTER TABLE scraper_posts MODIFY COLUMN picture TEXT");
        } catch (Exception $e) {}
            try {
                $col = $pdo->query("SHOW COLUMNS FROM scraper_posts LIKE 'video_url'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_posts ADD COLUMN video_url TEXT");
                }
                $col = $pdo->query("SHOW COLUMNS FROM scraper_posts LIKE 'post_type'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_posts ADD COLUMN post_type VARCHAR(20) DEFAULT 'photo'");
                }
            } catch (Exception $e_posts_cols) {}

        $fields = 'id,object_id,message,created_time,full_picture,status_type,shares,comments.summary(total_count),reactions.summary(total_count)';
        $result = [];
        $stmtPost = $pdo->prepare("
            INSERT INTO scraper_posts (page_id, fb_post_id, message, picture, shares, comments, likes, post_created_at)
            VALUES (:pid, :fbid, :msg, :pic, :sha, :com, :lik, :c_at)
            ON DUPLICATE KEY UPDATE message=:msg, picture=:pic, shares=:sha, comments=:com, likes=:lik
        ");

        $batchSize = 10;
        $nextUrl = null;
        $maxPages = 10;
        $pageNum = 0;

        while (count($result) < $limit && $pageNum < $maxPages) {
            $pageNum++;
            if ($nextUrl) {
                $res = fb_api_request_url($nextUrl);
            } else {
                $res = fb_api_request("{$page_id}/posts", [
                    'access_token' => $token,
                    'fields' => $fields,
                    'limit' => $batchSize
                ]);
            }

            if ($res['status_code'] !== 200) {
                if ($pageNum === 1) {
                    $errMsg = $res['data']['error']['message'] ?? 'Lỗi không xác định từ Facebook API.';
                    echo json_encode(['status' => 'error', 'message' => 'Lỗi API: ' . $errMsg]);
                    exit;
                }
                break;
            }

            $postsData = $res['data']['data'] ?? [];
            if (empty($postsData)) break;

            foreach ($postsData as $post) {
                $fbid = $post['id'] ?? '';
                if (!$fbid) continue;

                $media = extract_post_media_urls($post, $token);

                // Video & photo posts are both processed now!

                $msg = $post['message'] ?? '';
                $c_at = $post['created_time'] ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : null;
                $pic = $post['full_picture'] ?? '';
                $sha = $post['shares']['count'] ?? 0;
                $com = $post['comments']['summary']['total_count'] ?? 0;
                $lik = $post['reactions']['summary']['total_count'] ?? ($post['likes']['summary']['total_count'] ?? 0);

                if ($only_with_content && trim($msg) === '') {
                    continue;
                }

                $stmtPost->execute([
                    'pid' => $page_id,
                    'fbid' => $fbid,
                    'msg' => $msg,
                    'pic' => $pic,
                    'sha' => $sha,
                    'com' => $com,
                    'lik' => $lik,
                    'c_at' => $c_at
                ]);

                $images = $media['images'];
                $videos = $media['videos'];
                $v_url = $videos[0] ?? '';
                if ($media['is_video'] && !$v_url) {
                    $v_url = get_video_source_from_post($token, $post);
                    if ($v_url) $videos[] = $v_url;
                }

                if (!$media['is_video'] && empty($images) && !empty($pic)) {
                    $images = [$pic];
                }

                $result[] = [
                    'stt' => count($result) + 1,
                    'id' => $fbid,
                    'created_time' => $post['created_time'] ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : '',
                    'post_type' => $media['post_type'],
                    'message' => $msg,
                    'thumbnail' => $pic ?: ($images[0] ?? ''),
                    'images' => $images,
                    'videos' => $videos,
                    'video_url' => $v_url,
                    'cdn_video_url' => $v_url,
                    'raw_video_url' => $v_url,
                    'likes' => $lik,
                    'comments' => $com,
                    'shares' => $sha
                ];

                if (count($result) >= $limit) break;
            }

            if (count($result) >= $limit) break;
            $nextUrl = $res['data']['paging']['next'] ?? null;
            if (!$nextUrl) break;
        }

        $count = count($result);
        $stmtUpdatePostCount = $pdo->prepare("UPDATE scraper_pages SET post_count = :count, last_scraped_at = NOW() WHERE page_id = :pid AND account_id = :aid");
        $stmtUpdatePostCount->execute(['count' => $count, 'pid' => $page_id, 'aid' => $account_id]);

        echo json_encode(['status' => 'success', 'data' => $result, 'post_count' => $count]);
        exit;
    }

    // List Bots
    if ($ajax === 'list_bots') {
        $stmt = $pdo->prepare("
            SELECT b.*, u.name as user_name 
            FROM scraper_bots b 
            LEFT JOIN users u ON b.user_id = u.id 
            WHERE b.account_id = :aid 
            ORDER BY b.created_at DESC
        ");
        $stmt->execute(['aid' => $account_id]);
        $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($bots as $b) {
            $sources = json_decode($b['sources'] ?? '[]', true) ?: [];
            $targets = json_decode($b['targets'] ?? '[]', true) ?: [];
            $seeding = json_decode($b['seeding'] ?? '{}', true) ?: [];

            $result[] = [
                'id' => intval($b['id']),
                'user_id' => intval($b['user_id'] ?? 0),
                'user_name' => $b['user_name'] ?: 'Chưa chọn',
                'name' => $b['name'],
                'status' => $b['status'],
                'last_run_at' => $b['last_run_at'],
                'created_at' => $b['created_at'],
                'summary' => [
                    'source_count' => count($sources),
                    'target_count' => count($targets),
                    'check_interval_seconds' => intval($b['check_interval_seconds']),
                    'distribute_mode' => $b['distribute_mode'],
                    'seeding_enabled' => !empty($seeding['enabled'])
                ],
                'config' => [
                    'user_id' => intval($b['user_id'] ?? 0),
                    'sources' => $sources,
                    'targets' => $targets,
                    'check_interval_seconds' => intval($b['check_interval_seconds']),
                    'target_post_gap_seconds' => intval($b['target_post_gap_seconds']),
                    'range' => $b['range_filter'],
                    'format' => $b['format_filter'],
                    'distribute_mode' => $b['distribute_mode'],
                    'find_words' => $b['find_words'],
                    'replace_words' => $b['replace_words'],
                    'remove_hashtag' => intval($b['remove_hashtag']) === 1,
                    'remove_link' => intval($b['remove_link']) === 1,
                    'only_with_content' => intval($b['only_with_content'] ?? 0) === 1,
                    'use_ai_rewrite' => intval($b['use_ai_rewrite'] ?? 0) === 1,
                    'seeding' => $seeding
                ]
            ];
        }

        echo json_encode(['status' => 'success', 'bots' => $result]);
        exit;
    }

    // Save Bot
    if ($ajax === 'save_bot') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $user_id = intval($_POST['user_id'] ?? 0);
        $config = $_POST['config'] ?? [];

        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }

        if ($user_id <= 0 && !empty($config['user_id'])) {
            $user_id = intval($config['user_id']);
        }

        if ($user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng chọn User Quản Lý Token.']);
            exit;
        }

        if ($name === '') {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập tên BOT.']);
            exit;
        }

        $sources = json_encode($config['sources'] ?? []);
        $targets = json_encode($config['targets'] ?? []);
        $interval = max(30, intval($config['check_interval_seconds'] ?? 300));
        $gap = max(0, intval($config['target_post_gap_seconds'] ?? 0));
        $range = $config['range'] ?? 'today';
        $format = $config['format'] ?? 'all';
        $distribute = $config['distribute_mode'] ?? 'all';
        $find = $config['find_words'] ?? '';
        $replace = $config['replace_words'] ?? '';
        $remove_hashtag = !empty($config['remove_hashtag']) ? 1 : 0;
        $remove_link = isset($config['remove_link']) ? (!empty($config['remove_link']) ? 1 : 0) : 1;
        $only_with_content = !empty($config['only_with_content']) ? 1 : 0;
        $use_ai_rewrite = !empty($config['use_ai_rewrite']) ? 1 : 0;
        $seeding = json_encode($config['seeding'] ?? []);

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE scraper_bots 
                SET user_id = :uid, name = :name, sources = :sources, targets = :targets, check_interval_seconds = :interval,
                    target_post_gap_seconds = :gap, range_filter = :range, format_filter = :format,
                    distribute_mode = :distribute, find_words = :find, replace_words = :replace,
                    remove_hashtag = :rem_hash, remove_link = :rem_link, only_with_content = :owc, use_ai_rewrite = :uai, seeding = :seeding
                WHERE id = :id AND account_id = :aid
            ");
            $stmt->execute([
                'uid' => $user_id, 'name' => $name, 'sources' => $sources, 'targets' => $targets, 'interval' => $interval,
                'gap' => $gap, 'range' => $range, 'format' => $format, 'distribute' => $distribute,
                'find' => $find, 'replace' => $replace, 'rem_hash' => $remove_hashtag, 'rem_link' => $remove_link,
                'owc' => $only_with_content, 'uai' => $use_ai_rewrite, 'seeding' => $seeding, 'id' => $id, 'aid' => $account_id
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO scraper_bots 
                (account_id, user_id, name, sources, targets, check_interval_seconds, target_post_gap_seconds, range_filter, format_filter, distribute_mode, find_words, replace_words, remove_hashtag, remove_link, only_with_content, use_ai_rewrite, seeding)
                VALUES 
                (:aid, :uid, :name, :sources, :targets, :interval, :gap, :range, :format, :distribute, :find, :replace, :rem_hash, :rem_link, :owc, :uai, :seeding)
            ");
            $stmt->execute([
                'aid' => $account_id, 'uid' => $user_id, 'name' => $name, 'sources' => $sources, 'targets' => $targets, 'interval' => $interval,
                'gap' => $gap, 'range' => $range, 'format' => $format, 'distribute' => $distribute,
                'find' => $find, 'replace' => $replace, 'rem_hash' => $remove_hashtag, 'rem_link' => $remove_link,
                'owc' => $only_with_content, 'uai' => $use_ai_rewrite, 'seeding' => $seeding
            ]);
            $id = $pdo->lastInsertId();
        }

        echo json_encode(['status' => 'success', 'id' => $id]);
        exit;
    }

    // Delete Bot
    if ($ajax === 'delete_bot') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID BOT không hợp lệ.']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM scraper_bots WHERE id = :id AND account_id = :aid");
        $stmt->execute(['id' => $id, 'aid' => $account_id]);
        $pdo->prepare("DELETE FROM scraper_bot_logs WHERE bot_id = :id AND account_id = :aid")->execute(['id' => $id, 'aid' => $account_id]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    // Get Single Bot
    if ($ajax === 'get_bot') {
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT b.*, u.name as user_name 
            FROM scraper_bots b 
            LEFT JOIN users u ON b.user_id = u.id 
            WHERE b.id = :id AND b.account_id = :aid 
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'aid' => $account_id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$b) {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy BOT.']);
            exit;
        }

        $botData = [
            'id' => intval($b['id']),
            'user_id' => intval($b['user_id'] ?? 0),
            'user_name' => $b['user_name'] ?: '',
            'name' => $b['name'],
            'status' => $b['status'],
            'config' => [
                'user_id' => intval($b['user_id'] ?? 0),
                'sources' => json_decode($b['sources'] ?? '[]', true) ?: [],
                'targets' => json_decode($b['targets'] ?? '[]', true) ?: [],
                'check_interval_seconds' => intval($b['check_interval_seconds']),
                'target_post_gap_seconds' => intval($b['target_post_gap_seconds']),
                'range' => $b['range_filter'],
                'format' => $b['format_filter'],
                'distribute_mode' => $b['distribute_mode'],
                'find_words' => $b['find_words'],
                'replace_words' => $b['replace_words'],
                'remove_hashtag' => intval($b['remove_hashtag']) === 1,
                'remove_link' => intval($b['remove_link']) === 1,
                'only_with_content' => intval($b['only_with_content'] ?? 0) === 1,
                'use_ai_rewrite' => intval($b['use_ai_rewrite'] ?? 0) === 1,
                'seeding' => json_decode($b['seeding'] ?? '{}', true) ?: []
            ]
        ];

        echo json_encode(['status' => 'success', 'bot' => $botData]);
        exit;
    }

    // Run Bot & Auto-Post
    if ($ajax === 'run_bot') {
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');

        try {
            $id = intval($_POST['id'] ?? 0);
            $res = executeScraperBot($pdo, $id, $account_id);
            echo json_encode($res);
            exit;
        } catch (Throwable $t) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Lỗi hệ thống PHP: ' . $t->getMessage() . ' (' . basename($t->getFile()) . ':' . $t->getLine() . ')'
            ]);
            exit;
        }
    }

    // Daily Report
    if ($ajax === 'bot_report') {
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        $days = intval($_GET['days'] ?? 30);

        $stmt = $pdo->prepare("
            SELECT DATE(created_at) as stat_date,
                   COUNT(DISTINCT source_page_id) as sources_checked,
                   COUNT(DISTINCT source_post_id) as posts_found,
                   SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as posts_published,
                   SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) as posts_failed
            FROM scraper_bot_logs
            WHERE bot_id = :bid AND account_id = :aid
              AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY DATE(created_at)
            ORDER BY stat_date DESC
        ");
        $stmt->execute(['bid' => $id, 'aid' => $account_id, 'days' => $days]);
        $report = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'report' => $report]);
        exit;
    }

    // Posted History
    if ($ajax === 'bot_posts') {
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        $limit = max(1, min(500, intval($_GET['limit'] ?? 200)));

        $stmt = $pdo->prepare("
            SELECT * FROM scraper_bot_logs
            WHERE bot_id = :bid AND account_id = :aid
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':bid', $id, PDO::PARAM_INT);
        $stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'posts' => $posts]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Action không hợp lệ.']);
    exit;
}

// ─── Return if included by Cron / CLI script ──────────────────────────────────
if (defined('CRON_RUNNING') || php_sapi_name() === 'cli' || (isset($_SERVER['SCRIPT_FILENAME']) && basename($_SERVER['SCRIPT_FILENAME']) !== 'facebook_scraper.php')) {
    return;
}

// ─── Normal Page Load ─────────────────────────────────────────────────────────
$current_page = 'facebook_scraper';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'] ?? 1;

$users = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name 
        FROM users u 
        LEFT JOIN pages p ON u.id = p.user_id 
        LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
        WHERE u.account_id = :aid OR ps.shared_with_account_id = :aid2
        ORDER BY u.name ASC
    ");
    $stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
    $stmt->bindValue(':aid2', $account_id, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE account_id = :aid ORDER BY name ASC");
        $stmt->execute(['aid' => $account_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e2) {}
}

$savedScraperPages = [];
try {
    $stmtPages = $pdo->prepare("
        SELECT sp.user_id, sp.page_id, sp.page_name, sp.followers_count, sp.post_count, sp.auto_refresh_hours, sp.only_with_content, u.name as user_name 
        FROM scraper_pages sp 
        LEFT JOIN users u ON sp.user_id = u.id 
        WHERE sp.account_id = :aid AND sp.page_name IS NOT NULL
        ORDER BY sp.created_at DESC
    ");
    $stmtPages->execute(['aid' => $account_id]);
    $savedScraperPages = $stmtPages->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
$savedScraperPagesJson = json_encode($savedScraperPages);

$targetPagesList = [];
try {
    $stmtTargetPages = $pdo->prepare("
        (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id 
         FROM pages p JOIN users u ON p.user_id = u.id 
         WHERE u.account_id = :aid)
        UNION
        (SELECT p.id, p.page_id, p.name, p.avatar, u.id as user_id
         FROM pages p 
         JOIN page_shares ps ON p.page_id = ps.page_id 
         JOIN users u ON p.user_id = u.id
         WHERE ps.shared_with_account_id = :aid2)
        ORDER BY name ASC
    ");
    $stmtTargetPages->bindValue(':aid', $account_id, PDO::PARAM_INT);
    $stmtTargetPages->bindValue(':aid2', $account_id, PDO::PARAM_INT);
    $stmtTargetPages->execute();
    $targetPagesList = $stmtTargetPages->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    try {
        $stmtTargetPages = $pdo->prepare("
            SELECT p.id, p.page_id, p.name, p.avatar, p.user_id 
            FROM pages p
            JOIN users u ON p.user_id = u.id
            WHERE u.account_id = :aid
            ORDER BY p.name ASC
        ");
        $stmtTargetPages->execute(['aid' => $account_id]);
        $targetPagesList = $stmtTargetPages->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e2) {}
}
$targetPagesListJson = json_encode($targetPagesList);
?>

<style>
    /* ─── Facebook Scraper Hero ────────────────────────────────────────────────────── */
    .fb-hero {
        background: linear-gradient(135deg, #1877F2 0%, #0c4391 100%);
        border-radius: 16px;
        padding: 28px 32px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 20px;
        position: relative;
        overflow: hidden;
    }

    .fb-hero::before {
        content: '';
        position: absolute;
        top: -30px;
        right: -30px;
        width: 180px;
        height: 180px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
        pointer-events: none;
    }

    .fb-logo-wrap {
        width: 56px;
        height: 56px;
        background: #fff;
        color: #1877F2;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        flex-shrink: 0;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    }

    .fb-hero-text h1 {
        font-size: 22px;
        font-weight: 700;
        color: #fff;
        margin: 0 0 4px;
    }

    .fb-hero-text p {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.85);
        margin: 0;
    }

    /* ─── Navigation Tabs ─── */
    .nav-tabs-custom {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 2px;
    }

    .nav-tab-btn {
        padding: 10px 20px;
        font-size: 14px;
        font-weight: 600;
        color: var(--text-muted);
        background: transparent;
        border: none;
        border-bottom: 3px solid transparent;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        border-radius: 6px 6px 0 0;
    }

    .nav-tab-btn.active {
        color: #1877F2;
        border-bottom-color: #1877F2;
        background: rgba(24, 119, 242, 0.05);
    }

    .tab-content-panel {
        display: none;
    }

    .tab-content-panel.active {
        display: block;
    }

    /* ─── Form Cards ─── */
    .search-form-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 24px;
        margin-bottom: 20px;
    }

    .search-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .search-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .search-field label {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .search-field input[type="text"],
    .search-field input[type="number"],
    .search-field select {
        padding: 10px 14px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-color);
        color: var(--text-main);
        font-size: 14px;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .search-field input:focus,
    .search-field select:focus {
        border-color: #1877F2;
        box-shadow: 0 0 0 3px rgba(24, 119, 242, 0.12);
    }

    .search-field.grow {
        flex: 1;
        min-width: 220px;
    }

    .btn-primary-action {
        padding: 10px 24px;
        background: linear-gradient(135deg, #1877F2, #165ab5);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: transform .15s, box-shadow .15s;
        box-shadow: 0 4px 14px rgba(24, 119, 242, .3);
    }

    .btn-primary-action:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(24, 119, 242, .4);
    }

    .btn-primary-action:disabled {
        opacity: .6;
        cursor: not-allowed;
        transform: none;
    }

    /* ─── Table ─── */
    .table-wrap {
        overflow-x: auto;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: var(--card-bg);
        margin-bottom: 24px;
    }

    .custom-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 700px;
        font-size: 13px;
    }

    .custom-table thead th {
        padding: 12px 14px;
        background: rgba(0, 0, 0, 0.02);
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        border-bottom: 1px solid var(--border-color);
        text-align: left;
    }

    .dark-mode .custom-table thead th {
        background: rgba(255, 255, 255, 0.02);
    }

    .custom-table tbody tr {
        border-bottom: 1px solid var(--border-color);
        transition: background .1s;
    }

    .custom-table tbody tr:hover {
        background: rgba(24, 119, 242, .03);
    }

    .custom-table tbody tr:last-child {
        border-bottom: none;
    }

    .custom-table td {
        padding: 11px 14px;
        color: var(--text-main);
        vertical-align: middle;
    }

    /* ─── Buttons inside table ─── */
    .btn-sm {
        padding: 5px 12px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-danger {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    .btn-danger:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    .btn-info {
        background: rgba(14, 165, 233, 0.1);
        color: #0ea5e9;
    }

    .btn-info:hover {
        background: rgba(14, 165, 233, 0.2);
    }

    /* ─── Scrape Modal View ─── */
    #scrape-section {
        display: none;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .scrape-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 16px;
        margin-bottom: 16px;
    }

    .scrape-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--primary-color);
        margin: 0;
    }

    .scrape-controls {
        display: flex;
        gap: 12px;
        align-items: flex-end;
    }

    /* ─── Bot Grid Cards ─── */
    .bots-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }

    .bot-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .bot-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.06);
    }

    .bot-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }

    .bot-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--text-main);
        margin: 0 0 4px 0;
    }

    .bot-meta {
        font-size: 12px;
        color: var(--text-muted);
    }

    .bot-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .badge-pill {
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        background: rgba(24, 119, 242, 0.1);
        color: #1877F2;
    }

    .badge-pill.green {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
    }

    .badge-pill.purple {
        background: rgba(139, 92, 246, 0.1);
        color: #8b5cf6;
    }

    .badge-pill.orange {
        background: rgba(245, 158, 11, 0.1);
        color: #d97706;
    }

    .bot-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: auto;
        padding-top: 10px;
        border-top: 1px solid var(--border-color);
    }

    /* ─── Modal Styles ─── */
    .custom-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(4px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .custom-modal-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        width: 100%;
        max-width: 720px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        display: flex;
        flex-direction: column;
    }

    .modal-head {
        padding: 18px 24px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-head h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        color: var(--text-main);
    }

    .modal-body {
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .modal-foot {
        padding: 16px 24px;
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }

    .target-checklist {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 10px;
        max-height: 200px;
        overflow-y: auto;
        padding: 10px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-color);
    }

    .target-checklist label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        cursor: pointer;
        padding: 4px;
        border-radius: 4px;
    }

    .target-checklist label:hover {
        background: rgba(24,119,242,0.05);
    }

    /* ─── Spinners ─── */
    .spin-ring {
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin .8s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        100% {
            transform: rotate(360deg);
        }
    }
</style>

<div class="fb-hero">
    <div class="fb-logo-wrap">🤖</div>
    <div class="fb-hero-text">
        <h1>Facebook Scraper & Auto-Bot Monitoring</h1>
        <p>Tự động theo dõi Fanpage nguồn, cào bài viết mới nhất và tự động đăng bài sang Fanpage đích của bạn.</p>
    </div>
</div>

<!-- Navigation Tabs -->
<div class="nav-tabs-custom">
    <button class="nav-tab-btn active" onclick="switchNavTab('bot-tab', this)">
        <span>🤖</span> Auto-Bot Đăng Bài Tuần Hoàn
    </button>
    <button class="nav-tab-btn" onclick="switchNavTab('scraper-tab', this)">
        <span>🕸️</span> Thêm & Quét Page Nguồn (Manual)
    </button>
</div>

<!-- ==================== TAB 1: AUTO-BOT MONITORING ==================== -->
<div id="bot-tab" class="tab-content-panel active">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
        <h2 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main);">Danh sách Auto Bot đang hoạt động</h2>
        <button class="btn-primary-action" onclick="openBotModal()">
            <span>➕</span> Tạo BOT Tự Động Đăng Bài
        </button>
    </div>

    <div class="bots-grid" id="bots-grid-container">
        <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-muted);">
            <span class="spin-ring" style="border-top-color: #1877F2;"></span> Đang tải danh sách BOT...
        </div>
    </div>
</div>

<!-- ==================== TAB 2: MANUAL SCRAPER ==================== -->
<div id="scraper-tab" class="tab-content-panel">
    <!-- Add Page Card -->
    <div class="search-form-card">
        <form onsubmit="addPage(event);">
            <div class="search-row">
                <div class="search-field grow" style="flex: 0.5;">
                    <label for="user-select">Chọn User Quản Lý Token</label>
                    <select id="user-select" required>
                        <option value="">-- Chọn User --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['id']); ?>">
                                <?php echo htmlspecialchars($user['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-field grow">
                    <label for="page-id-input">Nhập Page ID quản lý</label>
                    <input type="text" id="page-id-input" placeholder="Ví dụ: 929785023558487" autocomplete="off" required>
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn-primary-action" id="btn-add-page">
                        <span>➕</span> Nhập Page
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- List of Pages -->
    <div class="table-wrap">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 50px;">STT</th>
                    <th>Page Name</th>
                    <th>Page ID</th>
                    <th>User Quản lý</th>
                    <th>Post Count</th>
                    <th>Followers</th>
                    <th style="text-align: right;">Thao tác</th>
                </tr>
            </thead>
            <tbody id="pages-tbody">
                <tr id="empty-row">
                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        Chưa có Fanpage nào được thêm. Hãy nhập ID và bấm "Nhập Page".
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Scrape Details Section -->
    <div id="scrape-section">
        <div class="scrape-header">
            <h2 id="scrape-title">Chi tiết Page</h2>
            <div class="scrape-controls">
                <div class="search-field" style="width: 150px;">
                    <label for="scrape-limit">Số bài muốn quét</label>
                    <input type="number" id="scrape-limit" value="10" min="1" max="100">
                </div>
                <div class="search-field">
                    <label style="visibility:hidden;">‎</label>
                    <label style="display:inline-flex; align-items:center; font-size:12px; gap:5px; cursor:pointer; white-space:nowrap; height:38px; background:rgba(24,119,242,0.06); border:1px solid rgba(24,119,242,0.15); border-radius:8px; padding:0 12px; margin:0;" title="Chỉ lấy các bài viết có nội dung chữ">
                        <input type="checkbox" id="scrape-only-content" style="margin:0;"> 📝 Chỉ bài có nội dung
                    </label>
                </div>
                <button class="btn-primary-action" id="btn-do-scrape" onclick="doScrape()">
                    <span id="scrape-btn-icon">⚡</span> Quét bài viết
                </button>
                <button class="btn-sm btn-danger" onclick="closeScrape()" style="height: 40px; padding: 0 16px;">Đóng</button>
            </div>
        </div>

        <div class="table-wrap" style="margin-bottom: 0;">
            <table class="custom-table" id="scrape-results-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">STT</th>
                        <th style="width: 45%;">Nội dung & Media</th>
                        <th>👍 Likes</th>
                        <th>💬 Comments</th>
                        <th>🔗 Shares</th>
                    </tr>
                </thead>
                <tbody id="scrape-tbody">
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            Bấm "Quét bài viết" để bắt đầu lấy dữ liệu.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== MODAL: CREATE / EDIT BOT ==================== -->
<div id="bot-config-modal" class="custom-modal-overlay" style="display: none;">
    <div class="custom-modal-card">
        <div class="modal-head">
            <h3 id="bot-modal-title">Tạo Auto-Bot Đăng Bài</h3>
            <button onclick="closeBotModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="bot-edit-id" value="0">

            <!-- Step 1: Select User Account -->
            <div class="search-field">
                <label for="bot-user-id">1. Chọn User Quản Lý Token (Dùng để quét & đăng bài)</label>
                <select id="bot-user-id" onchange="onBotUserChange(this.value)" required style="border: 2px solid #1877F2;">
                    <option value="">-- Chọn User Quản Lý Token --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo htmlspecialchars($user['id']); ?>">
                            <?php echo htmlspecialchars($user['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: var(--text-muted); margin-top: 2px;">System sẽ dùng Access Token của User này để quét bài viết từ Page nguồn và lọc danh sách Fanpage đích bên dưới.</small>
            </div>

            <div class="search-field">
                <label for="bot-name">2. Tên BOT</label>
                <input type="text" id="bot-name" placeholder="Ví dụ: Auto Bot Tin Tức Hot" required>
            </div>

            <!-- Source Pages Input -->
            <div class="search-field">
                <label>3. Page Nguồn Theo Dõi (Page ID hoặc Link Facebook)</label>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="bot-source-input" placeholder="Dán link Facebook Fanpage nguồn hoặc Page ID" style="flex:1;">
                    <button type="button" class="btn-primary-action" onclick="addBotSourceItem()" style="padding: 0 16px;">+ Thêm nguồn</button>
                </div>
                <div id="bot-sources-list" style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;">
                    <!-- Rendered items -->
                </div>
            </div>

            <!-- Target Pages Checklist -->
            <div class="search-field">
                <label>4. Chọn Fanpage Đích Đăng Bài</label>
                <div class="target-checklist" id="bot-target-checklist">
                    <!-- Rendered target pages -->
                </div>
            </div>

            <!-- Settings Grid -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="search-field">
                    <label for="bot-interval">Tần suất kiểm tra (giây)</label>
                    <input type="number" id="bot-interval" value="300" min="30">
                </div>
                <div class="search-field">
                    <label for="bot-range">Khoảng thời gian bài viết</label>
                    <select id="bot-range">
                        <option value="1h">1 giờ gần nhất</option>
                        <option value="2h">2 giờ gần nhất</option>
                        <option value="3h">3 giờ gần nhất</option>
                        <option value="6h">6 giờ gần nhất</option>
                        <option value="12h">12 giờ gần nhất</option>
                        <option value="24h">24 giờ gần nhất</option>
                        <option value="today">Hôm nay (từ 00:00)</option>
                        <option value="3d">3 ngày gần nhất</option>
                        <option value="7d">7 ngày gần nhất</option>
                        <option value="30d">30 ngày gần nhất</option>
                        <option value="all">Tất cả bài viết</option>
                    </select>
                </div>
                <div class="search-field">
                    <label for="bot-distribute">Chế độ phân phối Page đích</label>
                    <select id="bot-distribute">
                        <option value="all">Đăng lên TẤT CẢ Page đích đã chọn</option>
                        <option value="random">Chọn NGẪU NHIÊN 1 Page đích</option>
                    </select>
                </div>
                <div class="search-field">
                    <label for="bot-gap">Khoảng cách bài đăng (giây)</label>
                    <input type="number" id="bot-gap" value="0" min="0">
                </div>
            </div>

            <!-- Text Transformation Rules -->
            <div style="border: 1px dashed var(--border-color); border-radius: 10px; padding: 14px; background: rgba(0,0,0,0.01);">
                <div style="font-size: 13px; font-weight:700; color:var(--text-main); margin-bottom:8px;">Thay thế từ khóa & Lọc nội dung</div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                    <div class="search-field">
                        <label>Từ khóa cần thay (phân cách dấu phẩy)</label>
                        <input type="text" id="bot-find-words" placeholder="ví dụ: Shop A, 0909123456">
                    </div>
                    <div class="search-field">
                        <label>Thay bằng (phân cách dấu phẩy)</label>
                        <input type="text" id="bot-replace-words" placeholder="ví dụ: HongDoLab, 0988888888">
                    </div>
                </div>
                <div style="display:flex; gap:20px;">
                    <label style="font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" id="bot-remove-hashtag"> ✂️ Xóa tất cả Hashtag (#)
                    </label>
                    <label style="font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" id="bot-remove-link" checked> ✂️ Xóa tất cả Link (http/https)
                    </label>
                </div>
                <div style="display:flex; flex-direction:column; gap:8px; margin-top:10px; padding-top:10px; border-top:1px dashed var(--border-color);">
                    <label style="font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; color:var(--text-main);">
                        <input type="checkbox" id="bot-only-content"> 📝 1. Chỉ lấy bài có nội dung chữ (Bỏ qua bài chỉ có ảnh không có chữ)
                    </label>
                    <label style="font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; color:#8b5cf6;">
                        <input type="checkbox" id="bot-use-ai"> 🤖 2. Sử dụng AI viết lại bài trước khi đăng (Dùng cấu hình tại Cấu Hình AI)
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn-sm btn-danger" onclick="closeBotModal()">Hủy</button>
            <button class="btn-primary-action" id="btn-save-bot" onclick="saveBotConfig()">Lưu BOT</button>
        </div>
    </div>
</div>


<!-- ==================== MODAL: BOT HISTORY LOGS ==================== -->
<div id="bot-history-modal" class="custom-modal-overlay" style="display: none;">
    <div class="custom-modal-card" style="max-width: 900px;">
        <div class="modal-head">
            <h3 id="history-modal-title">📜 Lịch sử Đăng bài của Bot</h3>
            <button onclick="closeBotHistoryModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>
        <div class="modal-body" style="padding: 16px;">
            <div class="table-wrap" style="margin-bottom: 0; max-height: 450px; overflow-y: auto;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Thời gian</th>
                            <th>Page Nguồn ➔ Page Đích</th>
                            <th>Nội dung trích đoạn</th>
                            <th style="width: 110px;">Trạng thái</th>
                            <th style="width: 120px;">Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody id="bot-history-tbody">
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                <span class="spin-ring" style="border-top-color: #1877F2;"></span> Đang tải lịch sử...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn-primary-action" onclick="closeBotHistoryModal()">Đóng</button>
        </div>
    </div>
</div>

<!-- ==================== MODAL: RUN LOGS & PROGRESS ==================== -->
<div id="bot-run-modal" class="custom-modal-overlay" style="display: none;">
    <div class="custom-modal-card" style="max-width: 800px;">
        <div class="modal-head">
            <h3 id="run-modal-title">Tiến trình Chạy Auto Bot</h3>
            <button onclick="closeRunModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>
        <div class="modal-body" style="background: #0f172a; color: #f8fafc; border-radius: 8px; font-family: monospace; font-size: 13px; max-height: 400px; overflow-y: auto;" id="run-log-console">
            <!-- Console log stream -->
        </div>
        <div class="modal-foot">
            <button class="btn-primary-action" onclick="closeRunModal()">Đóng Console</button>
        </div>
    </div>
</div>

<script>
    let addedPages = <?php echo $savedScraperPagesJson; ?>;
    let targetPagesData = <?php echo $targetPagesListJson; ?>;
    let botSourcesList = [];
    let pendingScrapePageId = null;
    let pendingScrapeUserId = null;

    document.addEventListener('DOMContentLoaded', () => {
        renderPagesTable();
        loadBotsGrid();
    });

    function switchNavTab(tabId, btnEl) {
        document.querySelectorAll('.nav-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content-panel').forEach(p => p.classList.remove('active'));
        btnEl.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }

    // ─── BOT MANAGEMENT FUNCTIONS ───
    function loadBotsGrid() {
        const container = document.getElementById('bots-grid-container');
        fetch('facebook_scraper.php?ajax=list_bots')
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    renderBotsGrid(res.bots);
                } else {
                    container.innerHTML = `<div style="grid-column: 1 / -1; text-align: center; color: #ef4444;">⚠ ${escapeHtml(res.message)}</div>`;
                }
            })
            .catch(err => {
                container.innerHTML = `<div style="grid-column: 1 / -1; text-align: center; color: #ef4444;">⚠ Lỗi kết nối: ${escapeHtml(err.message)}</div>`;
            });
    }

    function renderBotsGrid(bots) {
        const container = document.getElementById('bots-grid-container');
        if (!bots || bots.length === 0) {
            container.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; background: var(--card-bg); border: 1px dashed var(--border-color); border-radius: 14px;">
                    <div style="font-size: 32px; margin-bottom: 10px;">🤖</div>
                    <div style="font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">Chưa có Auto-Bot nào</div>
                    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">Tạo Bot để tự động cào bài viết mới từ Page nguồn và tự động đăng lên Page của bạn.</div>
                    <button class="btn-primary-action" onclick="openBotModal()" style="margin: 0 auto;">+ Tạo Auto-Bot Mới</button>
                </div>
            `;
            return;
        }

        container.innerHTML = bots.map(b => {
            const summary = b.summary || {};
            const lastRun = b.last_run_at ? new Date(b.last_run_at).toLocaleString('vi-VN') : 'Chưa chạy';

            return `
                <div class="bot-card">
                    <div class="bot-card-header">
                        <div>
                            <h3 class="bot-title">${escapeHtml(b.name)}</h3>
                            <div class="bot-meta">⏱ Tần suất: ${summary.check_interval_seconds || 300}s | 📅 ${lastRun}</div>
                        </div>
                        <div style="font-size: 24px;">🤖</div>
                    </div>
                    <div class="bot-badges">
                        <span class="badge-pill orange">👤 User: ${escapeHtml(b.user_name || 'Không rõ')}</span>
                        <span class="badge-pill">📥 ${summary.source_count || 0} Page Nguồn</span>
                        <span class="badge-pill green">📤 ${summary.target_count || 0} Page Đích</span>
                        <span class="badge-pill purple">${summary.distribute_mode === 'random' ? '🎲 Ngẫu nhiên 1 Page' : '🌐 Tất cả Page'}</span>
                    </div>
                    <div class="bot-actions">
                        <button class="btn-primary-action" style="padding: 6px 14px; font-size: 12px;" onclick="runBotNow(${b.id}, '${escapeHtml(b.name)}')">▶ Chạy Bot</button>
                        <button class="btn-sm btn-info" onclick="viewBotHistory(${b.id}, '${escapeHtml(b.name)}')">📜 Lịch sử</button>
                        <button class="btn-sm btn-info" onclick="editBot(${b.id})">Sửa</button>
                        <button class="btn-sm btn-danger" onclick="deleteBot(${b.id}, '${escapeHtml(b.name)}')">Xóa</button>
                    </div>
                </div>
            `;
        }).join('');
    }

    function onBotUserChange(userId) {
        const currentlyChecked = [];
        document.querySelectorAll('.bot-target-cb:checked').forEach(cb => {
            currentlyChecked.push({ page_id: cb.value, page_name: cb.dataset.name });
        });
        renderBotTargetChecklist(currentlyChecked, userId);
    }

    function openBotModal(botData = null) {
        document.getElementById('bot-edit-id').value = botData ? botData.id : 0;
        document.getElementById('bot-modal-title').innerText = botData ? 'Sửa Auto-Bot' : 'Tạo Auto-Bot Mới';
        
        const userId = botData ? botData.user_id : '';
        document.getElementById('bot-user-id').value = userId;
        document.getElementById('bot-name').value = botData ? botData.name : '';
        document.getElementById('bot-interval').value = botData ? (botData.config.check_interval_seconds || 300) : 300;
        document.getElementById('bot-gap').value = botData ? (botData.config.target_post_gap_seconds || 0) : 0;
        document.getElementById('bot-range').value = botData ? (botData.config.range || 'today') : 'today';
        document.getElementById('bot-distribute').value = botData ? (botData.config.distribute_mode || 'all') : 'all';
        document.getElementById('bot-find-words').value = botData ? (botData.config.find_words || '') : '';
        document.getElementById('bot-replace-words').value = botData ? (botData.config.replace_words || '') : '';
        document.getElementById('bot-remove-hashtag').checked = botData ? !!botData.config.remove_hashtag : false;
        document.getElementById('bot-remove-link').checked = botData ? botData.config.remove_link !== false : true;
        document.getElementById('bot-only-content').checked = botData ? !!botData.config.only_with_content : false;
        document.getElementById('bot-use-ai').checked = botData ? !!botData.config.use_ai_rewrite : false;

        botSourcesList = botData && botData.config.sources ? [...botData.config.sources] : [];
        renderBotSources();

        // Refresh target pages list from server if empty
        if (!targetPagesData || targetPagesData.length === 0) {
            fetch('facebook_scraper.php?ajax=get_target_pages')
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success' && res.data) {
                        targetPagesData = res.data;
                    }
                    renderBotTargetChecklist(botData ? (botData.config.targets || []) : [], userId);
                })
                .catch(() => {
                    renderBotTargetChecklist(botData ? (botData.config.targets || []) : [], userId);
                });
        } else {
            renderBotTargetChecklist(botData ? (botData.config.targets || []) : [], userId);
        }

        document.getElementById('bot-config-modal').style.display = 'flex';
    }

    function closeBotModal() {
        document.getElementById('bot-config-modal').style.display = 'none';
    }

    function addBotSourceItem() {
        const input = document.getElementById('bot-source-input');
        const val = input.value.trim();
        if (!val) return;

        let pageId = val;
        if (val.includes('facebook.com')) {
            const parts = val.replace(/\/$/, '').split('/');
            pageId = parts[parts.length - 1] || val;
        }

        if (botSourcesList.some(s => s.id === pageId)) {
            alert('Page nguồn này đã có trong danh sách.');
            return;
        }

        botSourcesList.push({ id: pageId, name: pageId });
        input.value = '';
        renderBotSources();
    }

    function removeBotSourceItem(idx) {
        botSourcesList.splice(idx, 1);
        renderBotSources();
    }

    function renderBotSources() {
        const container = document.getElementById('bot-sources-list');
        if (botSourcesList.length === 0) {
            container.innerHTML = `<span style="font-size:12px; color:var(--text-muted);">Chưa thêm Page nguồn nào.</span>`;
            return;
        }
        container.innerHTML = botSourcesList.map((s, idx) => `
            <span style="display:inline-flex; align-items:center; gap:6px; background:rgba(24,119,242,0.1); color:#1877F2; padding:4px 10px; border-radius:14px; font-size:12px; font-weight:600;">
                📘 ${escapeHtml(s.name || s.id)}
                <button type="button" onclick="removeBotSourceItem(${idx})" style="background:none; border:none; color:#ef4444; font-weight:bold; cursor:pointer; padding:0 2px;">&times;</button>
            </span>
        `).join('');
    }

    function renderBotTargetChecklist(selectedTargets = [], filterUserId = '') {
        const container = document.getElementById('bot-target-checklist');
        const selectedIds = new Set(selectedTargets.map(t => String(t.page_id)));

        if (!filterUserId || filterUserId === '' || filterUserId === '0') {
            container.innerHTML = `<div style="grid-column: 1 / -1; text-align: center; padding: 16px; color: var(--text-muted); font-size: 13px;">👈 Vui lòng chọn <b>User Quản Lý Token</b> ở bước 1 để hiển thị danh sách Fanpage đích tương ứng.</div>`;
            return;
        }

        if (!targetPagesData || targetPagesData.length === 0) {
            container.innerHTML = `<div style="grid-column: 1 / -1; text-align: center; padding: 16px; color: var(--text-muted); font-size: 13px;">Hệ thống chưa có Fanpage nào.</div>`;
            return;
        }

        let filteredPages = targetPagesData.filter(p => p.user_id && String(p.user_id) === String(filterUserId));

        if (filteredPages.length === 0) {
            container.innerHTML = `<div style="grid-column: 1 / -1; text-align: center; padding: 16px; color: var(--text-muted); font-size: 13px;">⚠ User này hiện chưa quản lý hoặc chưa được phân quyền Fanpage nào.</div>`;
            return;
        }

        container.innerHTML = filteredPages.map(p => {
            const pid = String(p.page_id);
            const pname = p.name || p.page_name || pid;
            const isChecked = selectedIds.has(pid) ? 'checked' : '';

            return `
                <label style="display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:6px; background:var(--bg-color); cursor:pointer; border:1px solid var(--border-color);">
                    <input type="checkbox" class="bot-target-cb" value="${escapeHtml(pid)}" data-name="${escapeHtml(pname)}" ${isChecked}>
                    ${p.avatar ? `<img src="${escapeHtml(p.avatar)}" style="width:24px; height:24px; border-radius:50%; object-fit:cover;" onerror="this.style.display='none'">` : ''}
                    <span style="font-size:13px; font-weight:600;">${escapeHtml(pname)}</span>
                </label>
            `;
        }).join('');
    }

    function saveBotConfig() {
        const id = document.getElementById('bot-edit-id').value;
        const userId = document.getElementById('bot-user-id').value;
        const name = document.getElementById('bot-name').value.trim();

        if (!userId) {
            alert('Vui lòng chọn User Quản Lý Token ở bước 1.');
            document.getElementById('bot-user-id').focus();
            return;
        }
        if (!name) {
            alert('Vui lòng nhập tên BOT.');
            document.getElementById('bot-name').focus();
            return;
        }
        if (botSourcesList.length === 0) {
            alert('Vui lòng thêm ít nhất 1 Page nguồn.');
            return;
        }

        const selectedTargets = [];
        document.querySelectorAll('.bot-target-cb:checked').forEach(cb => {
            selectedTargets.push({
                page_id: cb.value,
                page_name: cb.dataset.name
            });
        });

        if (selectedTargets.length === 0) {
            alert('Vui lòng chọn ít nhất 1 Fanpage đích.');
            return;
        }

        const config = {
            user_id: parseInt(userId),
            sources: botSourcesList,
            targets: selectedTargets,
            check_interval_seconds: parseInt(document.getElementById('bot-interval').value) || 300,
            target_post_gap_seconds: parseInt(document.getElementById('bot-gap').value) || 0,
            range: document.getElementById('bot-range').value,
            distribute_mode: document.getElementById('bot-distribute').value,
            find_words: document.getElementById('bot-find-words').value,
            replace_words: document.getElementById('bot-replace-words').value,
            remove_hashtag: document.getElementById('bot-remove-hashtag').checked,
            remove_link: document.getElementById('bot-remove-link').checked,
            only_with_content: document.getElementById('bot-only-content').checked,
            use_ai_rewrite: document.getElementById('bot-use-ai').checked
        };

        const fd = new FormData();
        fd.append('id', id);
        fd.append('user_id', userId);
        fd.append('name', name);
        fd.append('config', JSON.stringify(config));

        const btn = document.getElementById('btn-save-bot');
        btn.disabled = true;
        btn.innerHTML = '<span class="spin-ring"></span> Đang lưu...';

        fetch('facebook_scraper.php?ajax=save_bot', {
            method: 'POST',
            body: fd
        })
        .then(async r => {
            const txt = await r.text();
            try { return JSON.parse(txt); }
            catch(e) { throw new Error(txt); }
        })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = 'Lưu BOT';
            if (res.status === 'success') {
                closeBotModal();
                loadBotsGrid();
            } else {
                alert('Lỗi: ' + res.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = 'Lưu BOT';
            alert('Lỗi kết nối: ' + err.message);
        });
    }

    function editBot(id) {
        fetch(`facebook_scraper.php?ajax=get_bot&id=${id}`)
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    openBotModal(res.bot);
                } else {
                    alert('Lỗi: ' + res.message);
                }
            });
    }

    function deleteBot(id, name) {
        if (!confirm(`Bạn có chắc chắn muốn xóa BOT "${name}"?`)) return;
        const fd = new FormData();
        fd.append('id', id);
        fetch('facebook_scraper.php?ajax=delete_bot', {
            method: 'POST', body: fd
        }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                loadBotsGrid();
            } else {
                alert('Lỗi xóa BOT: ' + res.message);
            }
        });
    }

    function runBotNow(id, botName) {
        const consoleEl = document.getElementById('run-log-console');
        document.getElementById('run-modal-title').innerText = `Tiến trình BOT: ${botName}`;
        consoleEl.innerHTML = `<div style="color: #38bdf8;">[${new Date().toLocaleTimeString()}] ▶ Đang khởi chạy BOT "${escapeHtml(botName)}"...</div>`;
        document.getElementById('bot-run-modal').style.display = 'flex';

        const fd = new FormData();
        fd.append('id', id);

        fetch('facebook_scraper.php?ajax=run_bot', {
            method: 'POST', body: fd
        })
        .then(async r => {
            const txt = await r.text();
            try {
                return JSON.parse(txt);
            } catch(e) {
                throw new Error(txt || 'Lỗi Response từ Server rỗng hoặc không đúng định dạng JSON');
            }
        })
        .then(res => {
            if (res.status === 'success') {
                const sum = res.summary || {};
                consoleEl.innerHTML += `<div style="color: #4ade80; font-weight: bold; margin-top: 10px;">✓ Hoàn tất lượt chạy BOT!</div>`;
                consoleEl.innerHTML += `<div style="color: #94a3b8;">----------------------------------------</div>`;
                consoleEl.innerHTML += `<div style="color: #f8fafc;">🔍 Nguồn đã quét: ${sum.sources_checked || 0}</div>`;
                consoleEl.innerHTML += `<div style="color: #f8fafc;">📰 Bài tìm thấy: ${sum.posts_found || 0}</div>`;
                consoleEl.innerHTML += `<div style="color: #4ade80;">🚀 Đã đăng thành công: ${sum.posts_published || 0}</div>`;
                consoleEl.innerHTML += `<div style="color: #f87171;">⚠️ Lỗi: ${sum.posts_failed || 0}</div>`;

                if (res.logs && res.logs.length > 0) {
                    consoleEl.innerHTML += `<div style="color: #94a3b8; margin-top: 10px;">Chi tiết nhật ký:</div>`;
                    res.logs.forEach(l => {
                        const color = l.type === 'success' ? '#4ade80' : '#f87171';
                        const linkHtml = l.posted_link ? ` <a href="${escapeHtml(l.posted_link)}" target="_blank" style="color:#38bdf8;">[Xem bài]</a>` : '';
                        consoleEl.innerHTML += `<div style="color: ${color}; margin-left: 10px;">• ${escapeHtml(l.message)}${linkHtml}</div>`;
                    });
                }
                loadBotsGrid();
            } else {
                consoleEl.innerHTML += `<div style="color: #f87171; font-weight: bold; margin-top: 10px;">✕ Lỗi chạy BOT: ${escapeHtml(res.message)}</div>`;
            }
        })
        .catch(err => {
            consoleEl.innerHTML += `<div style="color: #f87171; font-weight: bold; margin-top: 10px;">✕ Lỗi kết nối / Server Error:</div><pre style="white-space:pre-wrap; background:#1e293b; color:#f87171; padding:10px; border-radius:6px; margin-top:6px; font-size:12px;">${escapeHtml(err.message)}</pre>`;
        });
    }

    
    function viewBotHistory(botId, botName) {
        document.getElementById('history-modal-title').innerText = `📜 Lịch sử Hoạt Động BOT: ${botName}`;
        const tbody = document.getElementById('bot-history-tbody');
        tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;"><span class="spin-ring" style="border-top-color: #1877F2;"></span> Đang tải nhật ký lịch sử...</td></tr>`;
        document.getElementById('bot-history-modal').style.display = 'flex';

        fetch(`facebook_scraper.php?ajax=bot_posts&id=${botId}&limit=100`)
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    renderBotHistoryTable(res.posts);
                } else {
                    tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #ef4444; padding: 20px;">⚠ ${escapeHtml(res.message)}</td></tr>`;
                }
            })
            .catch(err => {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #ef4444; padding: 20px;">⚠ Lỗi kết nối: ${escapeHtml(err.message)}</td></tr>`;
            });
    }

    function renderBotHistoryTable(posts) {
        const tbody = document.getElementById('bot-history-tbody');
        if (!posts || posts.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">BOT chưa thực hiện đăng bài nào.</td></tr>`;
            return;
        }

        tbody.innerHTML = posts.map(p => {
            const timeStr = new Date(p.created_at).toLocaleString('vi-VN');
            const isSuccess = p.status === 'success';
            const statusBadge = isSuccess 
                ? `<span class="badge-pill green">✓ Thành công</span>`
                : `<span class="badge-pill" style="background:rgba(239,68,68,0.1); color:#ef4444;">✕ Thất bại</span>`;

            let detailHtml = '';
            if (isSuccess && p.posted_permalink) {
                detailHtml = `<a href="${escapeHtml(p.posted_permalink)}" target="_blank" style="color:#1877F2; font-weight:600; text-decoration:none;">🔗 Xem trên FB</a>`;
            } else if (p.error_message) {
                detailHtml = `<span style="color:#ef4444; font-size:12px;" title="${escapeHtml(p.error_message)}">${escapeHtml(p.error_message)}</span>`;
            }

            return `
                <tr>
                    <td style="font-size:12px; color:var(--text-muted);">${timeStr}</td>
                    <td>
                        <div style="font-weight:600; font-size:13px; color:var(--text-main);">${escapeHtml(p.source_page_name || p.source_page_id)}</div>
                        <div style="font-size:12px; color:#1877F2;">➔ ${escapeHtml(p.target_page_name || p.target_page_id)}</div>
                    </td>
                    <td style="font-size:12px; max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(p.post_content)}">
                        ${p.post_type === 'photo' ? '🖼️ ' : '📝 '} ${escapeHtml(p.post_content || 'Không có chữ')}
                    </td>
                    <td>${statusBadge}</td>
                    <td>${detailHtml}</td>
                </tr>
            `;
        }).join('');
    }

    function closeBotHistoryModal() {
        document.getElementById('bot-history-modal').style.display = 'none';
    }

    function closeRunModal() {
        document.getElementById('bot-run-modal').style.display = 'none';
    }

    // ─── MANUAL SCRAPER FUNCTIONS ───
    function renderPagesTable() {
        const tbody = document.getElementById('pages-tbody');
        if (!addedPages || addedPages.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có Fanpage nào được thêm. Hãy nhập ID và bấm "Nhập Page".</td></tr>`;
            return;
        }

        tbody.innerHTML = addedPages.map((page, index) => {
            const followers = parseInt(page.followers_count || 0).toLocaleString('vi-VN');
            const isAuto = parseInt(page.auto_refresh_hours || 0) > 0;
            const autoChecked = isAuto ? 'checked' : '';
            const autoHours = parseInt(page.auto_refresh_hours || 10) || 10;
            const isOnlyContent = parseInt(page.only_with_content || 0) > 0;
            const onlyContentChecked = isOnlyContent ? 'checked' : '';
            const pid = escapeHtml(page.page_id);
            return `
        <tr>
            <td>${index + 1}</td>
            <td style="font-weight: 600; color: var(--primary-color);">${escapeHtml(page.page_name || 'Không rõ')}</td>
            <td style="color: var(--text-muted);">${pid}</td>
            <td><span style="color:#0ea5e9; font-weight: 600;">${escapeHtml(page.user_name || 'Không rõ')}</span></td>
            <td style="font-weight: 700; color:#10b981; font-size:15px;" id="post-count-cell-${pid}">${parseInt(page.post_count || 0).toLocaleString('vi-VN')}</td>
            <td>${followers}</td>
            <td style="text-align: right;">
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <label style="display:inline-flex; align-items:center; font-size:12px; cursor:pointer;" title="Tự động quét định kỳ">
                            <input type="checkbox" ${autoChecked} onchange="toggleAuto('${pid}', this.checked)" style="margin-right:4px;"> Auto
                        </label>
                        <button class="btn-sm btn-info" onclick="openScrape('${pid}', '${escapeHtml(page.page_name)}')">Chi tiết</button>
                        <button class="btn-sm btn-danger" onclick="removePage('${pid}', this)">Xóa</button>
                    </div>
                    <div id="auto-settings-${pid}" style="display:${isAuto ? 'flex' : 'none'}; align-items:center; gap:8px; flex-wrap:wrap; background:rgba(24,119,242,0.05); border:1px solid rgba(24,119,242,0.15); border-radius:8px; padding:6px 10px;">
                        <label style="display:inline-flex; align-items:center; font-size:11px; gap:4px; color:var(--text-muted); white-space:nowrap;">
                            ⏱ Mỗi
                            <input type="number" value="${autoHours}" min="1" max="168" style="width:50px; padding:3px 6px; border:1px solid var(--border-color); border-radius:5px; font-size:12px; text-align:center; background:var(--bg-color); color:var(--text-main);" onchange="updateAutoSettings('${pid}')" id="auto-hours-${pid}">
                            giờ
                        </label>
                        <label style="display:inline-flex; align-items:center; font-size:11px; gap:4px; cursor:pointer; color:var(--text-muted); white-space:nowrap;">
                            <input type="checkbox" ${onlyContentChecked} id="only-content-${pid}" onchange="updateAutoSettings('${pid}')" style="margin:0;">
                            📝 Chỉ bài có nội dung
                        </label>
                    </div>
                </div>
            </td>
        </tr>`;
        }).join('');
    }

    function addPage(e) {
        e.preventDefault();
        const userSelect = document.getElementById('user-select');
        const inputEl = document.getElementById('page-id-input');
        const btn = document.getElementById('btn-add-page');
        const userId = userSelect.value.trim();
        const pageId = inputEl.value.trim();
        if (!userId || !pageId) return;

        if (addedPages.find(p => p.page_id === pageId)) {
            alert('Page này đã được thêm vào danh sách.');
            return;
        }

        const originalText = btn.innerHTML;
        btn.innerHTML = `<span class="spin-ring"></span> Đang tải...`;
        btn.disabled = true;

        const fd = new FormData();
        fd.append('user_id', userId);
        fd.append('page_id', pageId);
        fd.append('auto_refresh', '0');
        fd.append('refresh_hours', '10');

        fetch('facebook_scraper.php?ajax=get_page', {
            method: 'POST',
            body: fd
        })
            .then(r => r.json())
            .then(res => {
                btn.innerHTML = originalText;
                btn.disabled = false;

                if (res.status === 'success') {
                    addedPages.push(res.data);
                    inputEl.value = '';
                    renderPagesTable();
                } else {
                    alert('Lỗi: ' + res.message);
                }
            })
            .catch(err => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                alert('Lỗi kết nối mạng: ' + err.message);
            });
    }

    function toggleAuto(pageId, isChecked) {
        const settingsEl = document.getElementById('auto-settings-' + pageId);
        if (settingsEl) {
            settingsEl.style.display = isChecked ? 'flex' : 'none';
        }

        const hoursEl = document.getElementById('auto-hours-' + pageId);
        const onlyContentEl = document.getElementById('only-content-' + pageId);
        const hours = hoursEl ? parseInt(hoursEl.value) || 10 : 10;
        const onlyContent = onlyContentEl ? (onlyContentEl.checked ? 1 : 0) : 0;

        const fd = new FormData();
        fd.append('page_id', pageId);
        fd.append('status', isChecked ? '1' : '0');
        fd.append('hours', hours);
        fd.append('only_with_content', onlyContent);
        fetch('facebook_scraper.php?ajax=toggle_auto', {
            method: 'POST', body: fd
        }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                const p = addedPages.find(x => x.page_id === pageId);
                if (p) {
                    p.auto_refresh_hours = res.hours;
                    p.only_with_content = res.only_with_content;
                }
            } else {
                alert('Có lỗi khi lưu trạng thái auto: ' + res.message);
            }
        });
    }

    function updateAutoSettings(pageId) {
        const hoursEl = document.getElementById('auto-hours-' + pageId);
        const onlyContentEl = document.getElementById('only-content-' + pageId);
        const hours = hoursEl ? Math.max(1, parseInt(hoursEl.value) || 10) : 10;
        const onlyContent = onlyContentEl ? (onlyContentEl.checked ? 1 : 0) : 0;

        const fd = new FormData();
        fd.append('page_id', pageId);
        fd.append('status', '1');
        fd.append('hours', hours);
        fd.append('only_with_content', onlyContent);
        fetch('facebook_scraper.php?ajax=toggle_auto', {
            method: 'POST', body: fd
        }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                const p = addedPages.find(x => x.page_id === pageId);
                if (p) {
                    p.auto_refresh_hours = res.hours;
                    p.only_with_content = res.only_with_content;
                }
            }
        });
    }

    function removePage(pageId, btnElement) {
        if (btnElement && !btnElement.classList.contains('confirm-delete')) {
            const originalText = btnElement.innerHTML;
            btnElement.innerHTML = 'Chắc chắn muốn xóa?';
            btnElement.style.background = '#ef4444';
            btnElement.style.color = '#fff';
            btnElement.classList.add('confirm-delete');
            
            setTimeout(() => {
                if (document.body.contains(btnElement)) {
                    btnElement.innerHTML = originalText;
                    btnElement.style.background = '';
                    btnElement.style.color = '';
                    btnElement.classList.remove('confirm-delete');
                }
            }, 3000);
            return;
        }

        addedPages = addedPages.filter(p => p.page_id !== pageId);
        renderPagesTable();
        if (pendingScrapePageId === pageId) {
            closeScrape();
        }

        const fd = new FormData();
        fd.append('page_id', pageId);
        fetch('facebook_scraper.php?ajax=delete_page', {
            method: 'POST',
            body: fd
        });
    }

    function openScrape(pageId, pageName) {
        const pageObj = addedPages.find(p => p.page_id === pageId);
        if (!pageObj) return;

        pendingScrapePageId = pageId;
        pendingScrapeUserId = pageObj.user_id;
        document.getElementById('scrape-section').style.display = 'block';
        document.getElementById('scrape-title').innerText = 'Chi tiết Page: ' + pageName;

        const scrapeOnlyContent = document.getElementById('scrape-only-content');
        if (scrapeOnlyContent) {
            scrapeOnlyContent.checked = parseInt(pageObj.only_with_content || 0) > 0;
        }

        document.getElementById('scrape-section').scrollIntoView({ behavior: 'smooth', block: 'start' });

        const tbody = document.getElementById('scrape-tbody');
        tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;"><div style="display:flex;justify-content:center;align-items:center;gap:10px;"><span class="spin-ring" style="border-top-color:var(--text-muted);"></span> Đang tải dữ liệu cũ...</div></td></tr>`;

        const fd = new FormData();
        fd.append('page_id', pageId);
        fetch('facebook_scraper.php?ajax=get_saved_posts', {
            method: 'POST', body: fd
        }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                renderPostsData(res.data);
            } else {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Lỗi tải dữ liệu: ${escapeHtml(res.message)}</td></tr>`;
            }
        }).catch(err => {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Bấm "Quét bài viết" để bắt đầu lấy dữ liệu.</td></tr>`;
        });
    }

    function renderPostsData(posts) {
        const tbody = document.getElementById('scrape-tbody');
        if (!posts || posts.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có dữ liệu bài viết cũ. Bấm "Quét bài viết" để cào dữ liệu mới nhất.</td></tr>`;
            return;
        }

        tbody.innerHTML = posts.map((post, index) => {
            let mediaHtml = '';
            const isVid = (post.post_type === 'video' || (post.videos && post.videos.length > 0) || post.video_url);
            if (post.picture || post.thumbnail) {
                const src = post.picture || post.thumbnail;
                mediaHtml = `<div style="position:relative; width:80px; height:80px; flex-shrink:0;">
                    <img src="${escapeHtml(src)}" alt="Media" referrerpolicy="no-referrer" style="width: 80px !important; height: 80px !important; max-width: 80px !important; max-height: 80px !important; object-fit: cover !important; border-radius: 8px !important; border: 1px solid var(--border-color) !important;">
                    ${isVid ? `<span style="position:absolute; bottom:4px; right:4px; background:rgba(0,0,0,0.75); color:#fff; font-size:10px; font-weight:700; padding:2px 5px; border-radius:4px; display:flex; align-items:center; gap:2px;">🎥 VIDEO</span>` : ''}
                </div>`;
            } else if (isVid) {
                mediaHtml = `<div class="no-img" style="background:#1877F2; color:#fff; border-radius:8px; display:flex; align-items:center; justify-content:center; width:80px; height:80px; font-weight:bold;">🎥 VIDEO</div>`;
            } else {
                mediaHtml = `<div class="no-img">📄</div>`;
            }

            const timeStr = new Date(post.created_time).toLocaleString('vi-VN');

            return `
                <tr>
                    <td style="text-align:center; font-weight:600; color:var(--text-muted);">${index + 1}</td>
                    <td>
                        <div class="post-visual" style="display: flex !important; flex-direction: row !important; align-items: flex-start !important; gap: 14px !important;">
                            ${mediaHtml}
                            <div class="post-text" style="flex: 1 !important; min-width: 0 !important; display: flex !important; flex-direction: column !important; gap: 6px !important;">
                                <div class="post-desc" style="font-size: 13px !important; line-height: 1.5 !important; color: var(--text-main) !important; max-height: 4.5em !important; overflow: hidden !important; text-overflow: ellipsis !important; display: -webkit-box !important; -webkit-line-clamp: 3 !important; -webkit-box-orient: vertical !important;" title="${escapeHtml(post.message)}">${escapeHtml(post.message) || '<span style="color:#9ca3af;font-style:italic;">Không có nội dung chữ</span>'}</div>
                                <div class="post-time" style="font-size: 12px !important; color: var(--text-muted) !important;">📅 ${timeStr} | ID: <a href="https://facebook.com/${post.id}" target="_blank" style="color:#0ea5e9; text-decoration:none; font-weight:600;">${(post.id + "").split('_')[1] || post.id}</a></div>
                            </div>
                        </div>
                    </td>
                    <td style="font-weight:600; color:#1877F2; white-space:nowrap;">👍 ${parseInt(post.likes || 0).toLocaleString()}</td>
                    <td style="font-weight:600; color:#0ea5e9; white-space:nowrap;">💬 ${parseInt(post.comments || 0).toLocaleString()}</td>
                    <td style="font-weight:600; color:#10b981; white-space:nowrap;">🔗 ${parseInt(post.shares || 0).toLocaleString()}</td>
                </tr>
            `;
        }).join('');
    }

    function closeScrape() {
        pendingScrapePageId = null;
        pendingScrapeUserId = null;
        document.getElementById('scrape-section').style.display = 'none';
    }

    function doScrape() {
        if (!pendingScrapePageId || !pendingScrapeUserId) return;

        const limitInput = document.getElementById('scrape-limit').value;
        const btn = document.getElementById('btn-do-scrape');

        const originalText = btn.innerHTML;
        btn.innerHTML = `<span class="spin-ring"></span> Đang xử lý...`;
        btn.disabled = true;

        const tbody = document.getElementById('scrape-tbody');
        tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;"><div style="display:flex;justify-content:center;align-items:center;gap:10px;"><span class="spin-ring" style="border-top-color:var(--text-muted);"></span> Đang cào dữ liệu từ Facebook (ID: ${pendingScrapePageId})...</div></td></tr>`;

        const fd = new FormData();
        fd.append('user_id', pendingScrapeUserId);
        fd.append('page_id', pendingScrapePageId);
        fd.append('limit', limitInput);
        const onlyContentEl = document.getElementById('scrape-only-content');
        fd.append('only_with_content', onlyContentEl && onlyContentEl.checked ? '1' : '0');

        fetch('facebook_scraper.php?ajax=scrape', {
            method: 'POST',
            body: fd
        })
            .then(async r => {
                const txt = await r.text();
                try {
                    return JSON.parse(txt);
                } catch(e) {
                    throw new Error(txt);
                }
            })
            .then(res => {
                btn.innerHTML = originalText;
                btn.disabled = false;

                if (res.status === 'success') {
                    renderPostsData(res.data);

                    const pObj = addedPages.find(p => p.page_id === pendingScrapePageId);
                    if (pObj && res.post_count !== undefined) {
                        pObj.post_count = res.post_count;
                        const cell = document.getElementById('post-count-cell-' + pendingScrapePageId);
                        if (cell) cell.innerText = parseInt(res.post_count).toLocaleString('vi-VN');
                    }
                } else {
                    alert('Lỗi: ' + res.message);
                    tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #ef4444; padding: 30px;">⚠ Lỗi khi quét bài viết: ${escapeHtml(res.message)}</td></tr>`;
                }
            })
            .catch(err => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;"><span style="color:#ef4444;">⚠️ Lỗi máy chủ (Vui lòng chụp màn hình gửi lại):</span><br><br><div style="text-align:left; background:#111; color:#0f0; border-radius:6px; padding:12px; font-family:monospace; font-size:13px; overflow-x:auto; white-space:pre-wrap;">${escapeHtml(err.message)}</div></td></tr>`;
            });
    }

    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return (unsafe + '').replace(/[&<"'>]/g, function (match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#039;';
                default: return match;
            }
        });
    }
</script>

<?php include 'includes/footer.php'; ?>