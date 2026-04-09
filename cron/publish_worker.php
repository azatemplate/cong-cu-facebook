<?php
// cron/publish_worker.php

// This file is meant to be run via CLI or triggered by a Windows Task Scheduler/cronjob
// e.g., php d:\pagespeed\hi\facebook\cron\publish_worker.php

ignore_user_abort(true);
set_time_limit(0);

$target_page_id = isset($argv[1]) ? trim($argv[1]) : '';
if (empty($target_page_id) && isset($_GET['page_id'])) {
    $target_page_id = trim($_GET['page_id']);
}
if (empty($target_page_id)) {
    echo "Tiến trình gọi thiếu Page ID. Hủy bỏ.\n";
    exit;
}

$lock_file = sys_get_temp_dir() . "/facebook_publish_worker_page_" . md5($target_page_id) . ".lock";

// Xoá lock file cũ nếu quá 15 phút (worker cũ crash không release)
$lock_stale_seconds = 15 * 60;
if (file_exists($lock_file) && (time() - filemtime($lock_file)) > $lock_stale_seconds) {
    @unlink($lock_file);
    echo "  ⚠ Lock file cũ hơn 15 phút đã được dọn sạch cho Page ID: $target_page_id\n";
}

$lock_fp = fopen($lock_file, 'c');
if (!$lock_fp) {
    echo " Không mở được lock file. Bỏ qua.\n";
    exit;
}

// Thử lock trong 5 giây (blocking) thay vì exit ngay
$lock_wait = 0;
$lock_got = false;
while ($lock_wait < 5) {
    if (flock($lock_fp, LOCK_EX | LOCK_NB)) {
        $lock_got = true;
        break;
    }
    sleep(1);
    $lock_wait++;
}

if (!$lock_got) {
    echo "Tiến trình Page ID #$target_page_id đang chạy (lock không giải phóng sau 5s), vùi lòng đợi...\n";
    fclose($lock_fp);
    exit;
}

echo "Tien trinh xu ly thoi gian thuc doc lap cho Page ID: #$target_page_id\n";

// Khong sleep Thundering Herd (moi worker la 1 process doc lap per-page, khong tranh chap)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/drive_utils.php';
require_once __DIR__ . '/../includes/ai_rewriter.php';

$start_time = microtime(true);
echo "-------------------------------------------\n";
echo "Bat dau quet bai viet len lich luc: " . date('Y-m-d H:i:s') . "\n";

// ── Reset stuck 'processing' posts back to 'pending' ─────────────────────
// If a previous worker run crashed, posts stay at 'processing' forever.
// We reset them so they can be retried.
try {
    $stuck = $pdo->exec("UPDATE scheduled_posts SET status='pending' WHERE status='processing' AND scheduled_time <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    if ($stuck > 0)
        echo "⚠ Reset $stuck bài bị kẹt ở trạng thái 'processing' về 'pending'.\n";
} catch (Exception $e) {
}

// ── Detect available columns ─────────────────────────────────────────────
$has_fb_post_id = false;
$has_error_msg = false;
$has_retry_count = false;
$has_comment_lines = false;
$has_comment_at = false;
$has_comment_status = false;
$has_comment_mode = false;
try {
    $col_q = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME IN ('fb_post_id','error_msg','retry_count','comment_lines','comment_at','comment_status','comment_mode')");
    $existing_cols = $col_q ? $col_q->fetchAll(PDO::FETCH_COLUMN) : [];
    $has_fb_post_id = in_array('fb_post_id', $existing_cols);
    $has_error_msg = in_array('error_msg', $existing_cols);
    $has_retry_count = in_array('retry_count', $existing_cols);
    $has_comment_lines = in_array('comment_lines', $existing_cols);
    $has_comment_at = in_array('comment_at', $existing_cols);
    $has_comment_status = in_array('comment_status', $existing_cols);
    $has_comment_mode = in_array('comment_mode', $existing_cols);
} catch (Exception $e) {
}

// Fetch system settings for retry logic
$sys_retry_interval = 1;
$sys_max_retries = 3;
$sys_tiktok_api_url = '';
try {
    $ss_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('retry_interval_minutes', 'max_retries', 'tiktok_api_url')");
    $settings = [];
    while ($row = $ss_stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $sys_retry_interval = isset($settings['retry_interval_minutes']) ? (int) $settings['retry_interval_minutes'] : 1;
    $sys_max_retries = isset($settings['max_retries']) ? (int) $settings['max_retries'] : 3;
    $sys_tiktok_api_url = trim($settings['tiktok_api_url'] ?? '');
} catch (Exception $e) {
}

// Helper: Tải thông tin video TikTok (logic giống api-dow-tik.php)
function fetch_tiktok_info(string $tiktok_url, string $custom_api_url = ''): ?array
{
    // Nếu admin cấu hình API riêng (tiktok_api_url), dùng trực tiếp
    if (!empty($custom_api_url)) {
        $resp = @file_get_contents(rtrim($custom_api_url, '?&') . '?url=' . urlencode($tiktok_url));
        $data = json_decode($resp, true);
        if ($data && isset($data['download_url']))
            return $data;
    }

    // Bước 1: Lấy video ID qua TikTok oEmbed (không cần API key, giống logic gốc)
    $oembed_url = (strpos($tiktok_url, 'tiktok.com/oembed') === false)
        ? 'https://www.tiktok.com/oembed?url=' . urlencode($tiktok_url)
        : $tiktok_url;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $oembed_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($resp, true);
    if (!$json || empty($json['embed_product_id']))
        return null;

    $video_id = $json['embed_product_id'];
    $title = preg_replace('/[\/\\\\:\*\?"<>\|]/u', '', $json['title'] ?? 'tiktok_video');

    // Bước 2: Build link CDN tikwm.com (HD → SD → fallback)
    $hd_url = "https://www.tikwm.com/video/media/hdplay/{$video_id}.mp4";
    $sd_url = "https://www.tikwm.com/video/media/play/{$video_id}.mp4";

    $download_url = _tiktok_resolve_cdn($hd_url)
        ?: _tiktok_resolve_cdn($sd_url)
        ?: $hd_url; // fallback giữ nguyên link HD

    return [
        'download_url' => $download_url,
        'title' => $title,
        'video_id' => $video_id,
    ];
}

// Helper: Lấy URL cuối cùng sau redirect (kiểm tra tiktokcdn)
function _tiktok_resolve_cdn(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept: video/mp4,video/*',
        ],
    ]);
    curl_exec($ch);
    $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 400 && $final && strpos($final, 'tiktokcdn') !== false) {
        return $final;
    }
    return null;
}

// 1. Fetch pending posts where scheduled_time <= NOW() AND page_id matches
$retry_clause = $has_retry_count
    ? "OR (status = 'failed' AND (retry_count IS NULL OR retry_count < $sys_max_retries))"
    : '';
$stmt = $pdo->prepare("SELECT * FROM scheduled_posts WHERE (status = 'pending' $retry_clause) AND scheduled_time <= NOW() AND page_id = ?");
$stmt->execute([$target_page_id]);
$pending_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pending_posts)) {
    echo "Không có bài viết nào cần đăng cho Page ID: $target_page_id.\n";
    echo "-------------------------------------------\n";
    exit;
}

// Xáo trộn ngẫu nhiên tất cả bài đăng chờ trong nội bộ Account để công bằng giữa các Page.
shuffle($pending_posts);

echo "Tìm thấy " . count($pending_posts) . " bài viết cần đăng.\n";

$account_published_today = [];
$account_limits = [];

foreach ($pending_posts as $post) {
    echo "Đang xử lý bài đăng ID: {$post['id']} - Loại: {$post['post_type']}\n";

    // 1.5. Check Daily Output Limit per Account
    $aid = $post['account_id'] ?? 0;
    if ($aid > 0) {
        if (!isset($account_limits[$aid])) {
            $l_stmt = $pdo->prepare("SELECT page_limit, role FROM system_accounts WHERE id = ?");
            $l_stmt->execute([$aid]);
            $acc_data = $l_stmt->fetch(PDO::FETCH_ASSOC);
            $account_limits[$aid] = ($acc_data && $acc_data['role'] !== 'admin') ? (int) $acc_data['page_limit'] : -1;
        }

        if (!isset($account_published_today[$aid])) {
            $c_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE account_id = ? AND status = 'published' AND DATE(scheduled_time) = CURDATE()");
            $c_stmt->execute([$aid]);
            $account_published_today[$aid] = (int) $c_stmt->fetchColumn();
        }

        if ($account_limits[$aid] !== -1 && $account_published_today[$aid] >= $account_limits[$aid]) {
            echo "   → Tài khoản ID $aid đã đạt giới hạn {$account_limits[$aid]} bài/ngày. Chuyển bài ID {$post['id']} sang ngày mai.\n";
            $pdo->prepare("UPDATE scheduled_posts SET scheduled_time = CONCAT(DATE_ADD(DATE(scheduled_time), INTERVAL 1 DAY), ' 00:01:00'), error_msg = 'Đã đạt giới hạn bài trong ngày, dời sang ngày tiếp theo' WHERE id = ?")->execute([$post['id']]);
            continue;
        }
    }

    // 2. Mark as processing to prevent duplicate cron runs from picking it up
    // We only update if status is still pending or failed. If 0 rows affected, another worker took it.
    $update_processing = $pdo->prepare("UPDATE scheduled_posts SET status = 'processing' WHERE id = ? AND status IN ('pending', 'failed')");
    $update_processing->execute([$post['id']]);
    if ($update_processing->rowCount() === 0) {
        echo "   → Bài ID {$post['id']} đã được tiến trình khác xử lý. Bỏ qua.\n";
        continue;
    }

    // ── XỬ LÝ RIÊNG DÀNH CHO YOUTUBE ──────────────────────────────────────────
    if ($post['post_type'] === 'YouTube') {
        // Lấy thông tin kênh từ bảng youtube_channels (page_id = channel_id trong bảng)
        // Lưu ý: ở bước tạo post, $post['page_id'] lưu youtube_channels.id chứ không phải youtube channel id
        $yt_stmt = $pdo->prepare("SELECT yc.*, sa.gg_client_id, sa.gg_client_secret FROM youtube_channels yc JOIN system_accounts sa ON yc.account_id = sa.id WHERE yc.id = ?");
        $yt_stmt->execute([$post['page_id']]);
        $yt_channel = $yt_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$yt_channel || empty($yt_channel['refresh_token'])) {
            marKAsFailed($pdo, $post['id'], "Không tìm thấy refresh_token cho Kênh YouTube.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $client_id = $yt_channel['gg_client_id'];
        $client_secret = $yt_channel['gg_client_secret'];

        if (empty($client_id) || empty($client_secret)) {
            $stmt_admin = $pdo->query("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = 1");
            $admin_account = $stmt_admin->fetch(PDO::FETCH_ASSOC);
            if ($admin_account && !empty($admin_account['gg_client_id']) && !empty($admin_account['gg_client_secret'])) {
                $client_id = $admin_account['gg_client_id'];
                $client_secret = $admin_account['gg_client_secret'];
            }
        }

        // Lấy Access Token từ Refresh Token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $yt_channel['refresh_token'],
            'grant_type' => 'refresh_token'
        ]));
        $token_res = curl_exec($ch);
        curl_close($ch);
        $token_data = json_decode($token_res, true);

        if (empty($token_data['access_token'])) {
            marKAsFailed($pdo, $post['id'], "Lỗi cấp mới Access Token YouTube: " . ($token_data['error'] ?? 'Unknown'), $sys_max_retries, $sys_retry_interval);
            continue;
        }
        $access_token = $token_data['access_token'];

        // Download Media (nếu là Tiktok hoặc Drive)
        $raw_media = $post['media_path'];
        $is_drive = strpos($raw_media, 'drive:') === 0;
        $is_tiktok = strpos($raw_media, 'tiktok:') === 0;
        $abs_media_path = '';
        $temp_drive_file = null;
        $t_title_override = '';

        if ($is_drive) {
            $drive_file_id = substr($raw_media, 6);
            $drive_token = get_drive_access_token($pdo, $post['account_id']);
            if (!$drive_token) {
                marKAsFailed($pdo, $post['id'], "Lỗi tải Google Drive: Thiếu Token", $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $file_info = download_drive_file_temp($drive_token, $drive_file_id);
            if (isset($file_info['error'])) {
                marKAsFailed($pdo, $post['id'], "Lỗi tải Video Drive: " . $file_info['error'], $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $abs_media_path = $file_info['path'];
            $temp_drive_file = $abs_media_path;
            $t_title_override = pathinfo($file_info['name'], PATHINFO_FILENAME);
        } elseif ($is_tiktok) {
            $tiktok_url = substr($raw_media, 7);
            $tik_data = fetch_tiktok_info($tiktok_url, $sys_tiktok_api_url);
            if (!$tik_data || !isset($tik_data['download_url'])) {
                marKAsFailed($pdo, $post['id'], "Không thể kết nối API tải video TikTok.", $sys_max_retries, $sys_retry_interval);
                continue;
            }

            $tik_title = isset($tik_data['title']) ? $tik_data['title'] : '';
            $t_title_override = $tik_title;

            // Download the video locally to upload to youtube
            $file_content = @file_get_contents($tik_data['download_url']);
            if (!$file_content) {
                marKAsFailed($pdo, $post['id'], "Không thể tải trực tiếp file video TikTok.", $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $abs_media_path = sys_get_temp_dir() . '/' . uniqid('yt_tik_') . '.mp4';
            file_put_contents($abs_media_path, $file_content);
            $temp_drive_file = $abs_media_path; // Đánh dấu là file rác cần xoá dọn
        } else {
            $abs_media_path = __DIR__ . '/../' . $raw_media;
            if (!file_exists($abs_media_path)) {
                marKAsFailed($pdo, $post['id'], "Lỗi tải Video Local: Không thấy file", $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $t_title_override = pathinfo(basename($abs_media_path), PATHINFO_FILENAME);
        }

        // Tích hợp Content
        $content_data = json_decode($post['content'], true);
        if (!$content_data)
            $content_data = [];

        // Thêm AI chuẩn SEO (Bao gồm Local, Drive, TikTok)
        if (isset($content_data['use_ai']) && $content_data['use_ai']) {
            $base_text = trim(($content_data['title'] ?? '') . " " . ($content_data['description'] ?? ''));
            if (empty($base_text))
                $base_text = $t_title_override;
            if (empty($base_text))
                $base_text = "Video giải trí và tin tức";

            $channel_title = isset($yt_channel['channel_title']) ? $yt_channel['channel_title'] : '';
            $ai_json = rewrite_youtube_with_ai($base_text, $post['account_id'], $channel_title);
            if ($ai_json && is_array($ai_json)) {
                if (!empty($ai_json['title']))
                    $content_data['title'] = $ai_json['title'];
                if (!empty($ai_json['description']))
                    $content_data['description'] = $ai_json['description'];
                if (!empty($ai_json['tags']))
                    $content_data['tags'] = $ai_json['tags'];
            }
        } elseif (isset($content_data['auto_title']) && $content_data['auto_title'] && empty($content_data['title'])) {
            $content_data['title'] = $t_title_override;
            if (empty($content_data['description']))
                $content_data['description'] = $t_title_override;
        }

        // Đảm bảo có fallback nếu tất cả các luồng trên đều không ra title
        if (empty($content_data['title']) && isset($content_data['auto_title']) && $content_data['auto_title']) {
            $content_data['title'] = $t_title_override;
            if (empty($content_data['description']))
                $content_data['description'] = $t_title_override;
        }

        $yt_title = !empty($content_data['title']) ? mb_substr($content_data['title'], 0, 100, 'UTF-8') : (!empty($t_title_override) ? mb_substr($t_title_override, 0, 100, 'UTF-8') : 'YouTube Video');
        $yt_desc = $content_data['description'] ?? '';
        $yt_tags_str = $content_data['tags'] ?? '';
        $yt_tags = array_filter(array_map('trim', explode(',', $yt_tags_str)));

        $metadata = [
            "snippet" => [
                "title" => $yt_title,
                "description" => $yt_desc,
                "categoryId" => "26",
                "defaultLanguage" => "vi",
                "defaultAudioLanguage" => "vi"
            ],
            "status" => [
                "privacyStatus" => "public",
                "license" => "youtube",
                "embeddable" => true,
                "publicStatsViewable" => true,
                "madeForKids" => false
            ]
        ];
        if (!empty($yt_tags)) {
            $metadata['snippet']['tags'] = $yt_tags;
        }

        // --- RESUMABLE UPLOAD PROCESS ---
        $file_size = filesize($abs_media_path);

        // 1. Khởi tạo Upload
        $ch_init = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
        curl_setopt($ch_init, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_init, CURLOPT_POST, true);
        curl_setopt($ch_init, CURLOPT_POSTFIELDS, json_encode($metadata));
        curl_setopt($ch_init, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json; charset=UTF-8",
            "X-Upload-Content-Length: $file_size"
        ]);
        curl_setopt($ch_init, CURLOPT_HEADER, true);
        $init_response = curl_exec($ch_init);
        $init_code = curl_getinfo($ch_init, CURLINFO_HTTP_CODE);
        $init_header_size = curl_getinfo($ch_init, CURLINFO_HEADER_SIZE);
        $init_headers = substr($init_response, 0, $init_header_size);
        $init_body = substr($init_response, $init_header_size);
        curl_close($ch_init);

        if ($init_code !== 200) {
            marKAsFailed($pdo, $post['id'], "Lỗi khởi tạo upload YouTube: HTTP $init_code - $init_body", $sys_max_retries, $sys_retry_interval);
            if ($temp_drive_file && file_exists($temp_drive_file))
                @unlink($temp_drive_file);
            continue;
        }

        // Tìm Location url
        $upload_url = '';
        foreach (explode("\n", $init_headers) as $header_line) {
            if (stripos(trim($header_line), 'Location:') === 0) {
                $upload_url = trim(substr(trim($header_line), 9));
                break;
            }
        }

        if (empty($upload_url)) {
            marKAsFailed($pdo, $post['id'], "Lỗi lấy Location URL để upload lên YouTube.", $sys_max_retries, $sys_retry_interval);
            if ($temp_drive_file && file_exists($temp_drive_file))
                @unlink($temp_drive_file);
            continue;
        }

        // 2. Tải File Lên
        set_time_limit(3600); // 1 giờ cho upload file to
        $file_handle = fopen($abs_media_path, 'r');

        $ch_upload = curl_init($upload_url);
        curl_setopt($ch_upload, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_upload, CURLOPT_PUT, true);
        curl_setopt($ch_upload, CURLOPT_INFILE, $file_handle);
        curl_setopt($ch_upload, CURLOPT_INFILESIZE, $file_size);
        curl_setopt($ch_upload, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Content-Type: video/*"
        ]);
        $upload_response = curl_exec($ch_upload);
        $upload_code = curl_getinfo($ch_upload, CURLINFO_HTTP_CODE);
        curl_close($ch_upload);
        fclose($file_handle);

        if (in_array($upload_code, [200, 201])) {
            $youtube_res = json_decode($upload_response, true);
            $video_id = $youtube_res['id'] ?? '';

            // Xoá file rác local
            if ($temp_drive_file && file_exists($temp_drive_file))
                @unlink($temp_drive_file);
            if (!$is_drive && !$is_tiktok && file_exists($abs_media_path) && strpos($abs_media_path, 'uploads/') !== false) {
                @unlink($abs_media_path);
            }

            // Đánh dấu thành công
            if ($has_fb_post_id) {
                $pdo->prepare("UPDATE scheduled_posts SET status = 'published', fb_post_id = ? WHERE id = ?")
                    ->execute([$video_id, $post['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET status = 'published' WHERE id = ?")
                    ->execute([$post['id']]);
            }

            // Hẹn giờ Comment
            if ($has_comment_lines && $has_comment_at && !empty($post['comment_lines'])) {
                $post_comment_mode = ($has_comment_mode && !empty($post['comment_mode'])) ? $post['comment_mode'] : 'timer';
                if ($post_comment_mode === 'insights') {
                    // Insights mode: don't set comment_at, let cron/comment_insights_worker.php handle it
                    if ($has_comment_status) {
                        $pdo->prepare("UPDATE scheduled_posts SET comment_status = 'waiting_insights' WHERE id = ?")
                            ->execute([$post['id']]);
                    }
                    echo "   → Comment mode: insights (chờ cron kiểm tra metrics)\n";
                } else {
                    // Timer mode: comment after 120s
                    $comment_at = date('Y-m-d H:i:s', time() + 120);
                    if ($has_comment_status) {
                        $pdo->prepare("UPDATE scheduled_posts SET comment_at = ?, comment_status = 'pending' WHERE id = ?")
                            ->execute([$comment_at, $post['id']]);
                    } else {
                        $pdo->prepare("UPDATE scheduled_posts SET comment_at = ? WHERE id = ?")
                            ->execute([$comment_at, $post['id']]);
                    }
                }
            }

            // Lịch sử
            try {
                $display_content = is_string($post['content']) ? $post['content'] : json_encode($content_data);
                $h_stmt = $pdo->prepare("INSERT INTO posts_history (page_id, post_type, content, fb_post_id) VALUES (?, 'YouTube', ?, ?)");
                $h_stmt->execute([$post['page_id'], 'YouTube', $display_content, $video_id]);
            } catch (Exception $e) {
            }

            echo " -> Đăng Video YouTube thành công! Video ID: $video_id\n";
        } else {
            $err_data = json_decode($upload_response, true);
            $err_msg = $err_data['error']['message'] ?? $upload_response;
            marKAsFailed($pdo, $post['id'], "Lỗi lúc tải file lên YouTube: HTTP $upload_code - $err_msg", $sys_max_retries, $sys_retry_interval);
            if ($temp_drive_file && file_exists($temp_drive_file))
                @unlink($temp_drive_file);
        }

        // Xong luồng YouTube, bỏ qua phần Facebook bên dưới
        continue;
    }
    // ──────────────────────────────────────────────────────────────────────────

    // 3. Fetch Page Access Token
    $page_stmt = $pdo->prepare("SELECT access_token, name FROM pages WHERE page_id = ?");
    $page_stmt->execute([$post['page_id']]);
    $page = $page_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page || empty($page['access_token'])) {
        marKAsFailed($pdo, $post['id'], "Không tìm thấy token của page_id: {$post['page_id']}", $sys_max_retries, $sys_retry_interval);
        continue;
    }

    $page_access_token = decryptData($page['access_token']);
    $fanpage_name = isset($page['name']) ? $page['name'] : '';

    // 4. Prepare payload
    $endpoint = '';
    $post_data = [];
    $params = ['access_token' => $page_access_token];

    // ── Detect multi-image JSON array in media_path ──────────────────────
    $multi_image_paths = null;
    $raw_media = $post['media_path'];
    if (!empty($raw_media) && $raw_media[0] === '[') {
        $decoded = @json_decode($raw_media, true);
        if (is_array($decoded) && count($decoded) > 1) {
            $multi_image_paths = $decoded;
        } elseif (is_array($decoded) && count($decoded) === 1) {
            $raw_media = $decoded[0]; // treat single-element array as single path
            $post['media_path'] = $raw_media;
        }
    }

    $is_drive = strpos($raw_media, 'drive:') === 0;
    $is_tiktok = strpos($raw_media, 'tiktok:') === 0;
    $has_media = false;
    $abs_media_path = '';
    $file_mime = '';
    $file_name = '';
    $temp_drive_file = null;
    $t_title_override = null;

    // ── Multi-image post: upload each photo as unpublished, then create feed post ──
    if ($multi_image_paths !== null && ($post['post_type'] === 'Image' || $post['post_type'] === 'Status')) {
        // Parse content / AI rewrite
        $parsed_content = @json_decode($post['content'], true);
        $p_desc = '';
        if (is_array($parsed_content) && isset($parsed_content['description'])) {
            $p_desc = $parsed_content['description'];
            $use_ai = isset($parsed_content['use_ai']) && $parsed_content['use_ai'];
            if ($use_ai && !empty($p_desc)) {
                $p_desc = rewrite_content_with_ai($p_desc, $post['account_id'], false, $fanpage_name);
            }
        } else {
            $p_desc = $post['content'];
        }
        $post['content'] = $p_desc;

        echo "   → Multi-image post: " . count($multi_image_paths) . " ảnh (đã xáo thứ tự)\n";

        $photo_ids = [];
        $temp_files_to_clean = [];

        foreach ($multi_image_paths as $mi_idx => $mi_path) {
            $mi_abs = '';
            $mi_mime = '';
            $mi_name = '';

            if (strpos($mi_path, 'drive:') === 0) {
                // Drive file
                $drive_file_id = substr($mi_path, 6);
                $drive_token = get_drive_access_token($pdo, $post['account_id']);
                if (!$drive_token) {
                    echo "   → Bỏ qua ảnh Drive (không có token): $mi_path\n";
                    continue;
                }
                $file_info = download_drive_file_temp($drive_token, $drive_file_id);
                if (isset($file_info['error'])) {
                    echo "   → Bỏ qua ảnh Drive (lỗi tải): " . $file_info['error'] . "\n";
                    continue;
                }
                $mi_abs = $file_info['path'];
                $mi_mime = $file_info['mime'];
                $mi_name = $file_info['name'];
                $temp_files_to_clean[] = $mi_abs;
            } else {
                // Local file
                $mi_abs = __DIR__ . '/../' . $mi_path;
                if (!file_exists($mi_abs)) {
                    echo "   → Bỏ qua ảnh (không tồn tại): $mi_path\n";
                    continue;
                }
                $mi_mime = mime_content_type($mi_abs) ?: 'image/jpeg';
                $mi_name = basename($mi_abs);
                if (strpos($mi_abs, 'uploads/') !== false) {
                    $temp_files_to_clean[] = $mi_abs;
                }
            }

            // Upload as unpublished photo
            $upload_data = [
                'source' => new CURLFile($mi_abs, $mi_mime, $mi_name),
                'published' => 'false'
            ];
            $upload_res = fb_api_request($post['page_id'] . '/photos', ['access_token' => $page_access_token], 'POST', $upload_data);

            if ($upload_res['status_code'] === 200 && !empty($upload_res['data']['id'])) {
                $photo_ids[] = $upload_res['data']['id'];
                echo "   → Upload ảnh " . ($mi_idx + 1) . "/" . count($multi_image_paths) . " OK (ID: {$upload_res['data']['id']})\n";
            } else {
                $err = isset($upload_res['data']['error']['message']) ? $upload_res['data']['error']['message'] : 'Unknown';
                echo "   → Upload ảnh " . ($mi_idx + 1) . " thất bại: $err\n";
            }
        }

        // Cleanup temp drive files
        foreach ($temp_files_to_clean as $tf) {
            if (file_exists($tf))
                @unlink($tf);
        }

        if (empty($photo_ids)) {
            marKAsFailed($pdo, $post['id'], "Không upload được ảnh nào trong multi-image post.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        // Create multi-photo feed post with attached_media
        $feed_data = [];
        if (!empty($p_desc))
            $feed_data['message'] = $p_desc;
        foreach ($photo_ids as $pi => $pid) {
            $feed_data["attached_media[$pi]"] = json_encode(['media_fbid' => $pid]);
        }

        $response = fb_api_request($post['page_id'] . '/feed', ['access_token' => $page_access_token], 'POST', $feed_data);
        $endpoint = 'multi_photo_feed';

        // Skip the normal single-file flow below
        goto handle_response;
    }

    if ($is_drive) {
        $drive_file_id = substr($post['media_path'], 6);
        $drive_token = get_drive_access_token($pdo, $post['account_id']);
        if (!$drive_token) {
            marKAsFailed($pdo, $post['id'], "Không thể lấy Google Access Token. Có thể Admin chưa liên kết.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $file_info = download_drive_file_temp($drive_token, $drive_file_id);
        if (isset($file_info['error'])) {
            marKAsFailed($pdo, $post['id'], "Lỗi tải Google Drive: " . $file_info['error'], $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $has_media = true;
        $abs_media_path = $file_info['path'];
        $file_mime = $file_info['mime'];
        $file_name = $file_info['name'];
        $t_title_override = pathinfo($file_name, PATHINFO_FILENAME);
        $temp_drive_file = $abs_media_path;

    } elseif ($is_tiktok) {
        $tiktok_url = substr($post['media_path'], 7);
        $tik_data = fetch_tiktok_info($tiktok_url, $sys_tiktok_api_url);

        if (!$tik_data || !isset($tik_data['download_url'])) {
            marKAsFailed($pdo, $post['id'], "Không thể kết nối API tải video TikTok.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $tik_title = isset($tik_data['title']) ? $tik_data['title'] : '';
        $t_title_override = $tik_title;

        $post_data['file_url'] = $tik_data['download_url'];
    } else {
        $has_media = !empty($post['media_path']) && file_exists(__DIR__ . '/../' . $post['media_path']);
        if ($has_media) {
            $abs_media_path = __DIR__ . '/../' . $post['media_path'];
            $file_mime = mime_content_type($abs_media_path);
            if (!$file_mime)
                $file_mime = 'application/octet-stream';
            $file_name = basename($abs_media_path);
            $t_title_override = pathinfo($file_name, PATHINFO_FILENAME);
        }
    }

    if ($has_media && file_exists($abs_media_path)) {
        $post_data['source'] = new CURLFile($abs_media_path, $file_mime, $file_name);
    }

    $post_type = $post['post_type'];

    if ($post_type === 'Status' || $post_type === 'Image') {
        $endpoint = ($post_type === 'Status') ? $post['page_id'] . '/feed' : $post['page_id'] . '/photos';

        $parsed_content = @json_decode($post['content'], true);
        if (is_array($parsed_content) && isset($parsed_content['description'])) {
            $p_desc = $parsed_content['description'];
            $use_ai = isset($parsed_content['use_ai']) && $parsed_content['use_ai'];

            if ($use_ai && !empty($p_desc)) {
                $p_desc = rewrite_content_with_ai($p_desc, $post['account_id'], false, $fanpage_name);
            }
            $post_data['message'] = $p_desc;
            $post['content'] = $p_desc;
        } else {
            $post_data['message'] = $post['content'];
        }
    } elseif ($post_type === 'Video' || $post_type === 'Reel') {
        $endpoint = $post['page_id'] . '/videos';
        // Content might be JSON encoded with title and description for Videos
        if ($post['content']) {
            $content_data = json_decode($post['content'], true);
            if (is_array($content_data)) {
                $p_desc = isset($content_data['description']) ? $content_data['description'] : '';
                $p_title = isset($content_data['title']) ? $content_data['title'] : '';
                $is_auto = isset($content_data['auto_title']) && $content_data['auto_title'];
                $use_ai = isset($content_data['use_ai']) && $content_data['use_ai'];

                if ($is_auto) {
                    if (empty($p_desc) && !empty($t_title_override))
                        $p_desc = $t_title_override;
                    // Không tự động gán text dài vào $p_title để tránh bị Facebook Graph API cắt bớt hiển thị "..."
                    if (empty($p_title) && !empty($t_title_override))
                        $p_title = '';
                }

                if ($use_ai) {
                    if (!empty($p_desc))
                        $p_desc = rewrite_content_with_ai($p_desc, $post['account_id'], false, $fanpage_name);
                    if (!empty($p_title) && $post_type !== 'Reel')
                        $p_title = rewrite_content_with_ai($p_title, $post['account_id'], true, $fanpage_name);
                }

                if (!empty($p_desc))
                    $post_data['description'] = $p_desc;
                if (!empty($p_title) && $post_type !== 'Reel')
                    $post_data['title'] = $p_title;

                // Keep content intact for history reference
                $post['content'] = $p_desc;
            } else {
                $p_desc = !empty($post['content']) ? $post['content'] : $t_title_override;
                if (!empty($p_desc))
                    $post_data['description'] = $p_desc;
                $post['content'] = $p_desc;
            }
        } else {
            if (!empty($t_title_override)) {
                $post_data['description'] = $t_title_override;
                // Không gán vào array $post_data['title'] vì FB giới hạn độ dài title Rất Ngắn và sẽ tự cắt kèm "..."
                $post['content'] = $t_title_override;
            }
        }
    } elseif (strpos($post_type, 'Story') !== false) {
        // Nếu là Drive, post_type chỉ ghi là 'Story'. Cần parse lại dựa vào mime
        if ($post_type === 'Story' && $has_media) {
            $is_photo = strpos($file_mime, 'image') !== false;
            $post_type = 'Story (' . ($is_photo ? 'Image' : 'Video') . ')';
        }

        $is_photo_story = strpos($post_type, 'Image') !== false;

        $response = fb_upload_story($post['page_id'], $page_access_token, $abs_media_path, $file_mime, $is_photo_story, $file_name);

        // Cần giả lập biến $endpoint để không lỗi đoạn dưới (hoặc skip)
        $endpoint = "story_uploaded";
    } else {
        marKAsFailed($pdo, $post['id'], "Loại bài đăng không hỗ trợ: $post_type", $sys_max_retries, $sys_retry_interval);
        if ($temp_drive_file && file_exists($temp_drive_file))
            @unlink($temp_drive_file);
        continue;
    }

    // 5. Call API
    set_time_limit(300); // Allow long upload for videos
    if (strpos($post_type, 'Story') === false) {
        $response = fb_api_request($endpoint, $params, 'POST', $post_data);
    }

    handle_response:
    $post_id = $response['data']['id'] ?? $response['data']['post_id'] ?? null;

    if ($response['status_code'] === 200 && $post_id) {
        // Mark as published + save fb_post_id (if column exists)
        if ($has_fb_post_id) {
            $pdo->prepare("UPDATE scheduled_posts SET status = 'published', fb_post_id = ? WHERE id = ?")
                ->execute([$post_id, $post['id']]);
        } else {
            $pdo->prepare("UPDATE scheduled_posts SET status = 'published' WHERE id = ?")
                ->execute([$post['id']]);
        }

        // Schedule comment if needed (120s after now)
        if ($has_comment_lines && $has_comment_at && !empty($post['comment_lines'])) {
            $post_comment_mode = ($has_comment_mode && !empty($post['comment_mode'])) ? $post['comment_mode'] : 'timer';
            if ($post_comment_mode === 'insights') {
                // Insights mode: don't set comment_at, let cron/comment_insights_worker.php handle it
                if ($has_comment_status) {
                    $pdo->prepare("UPDATE scheduled_posts SET comment_status = 'waiting_insights' WHERE id = ?")
                        ->execute([$post['id']]);
                }
                echo "   → Comment mode: insights (chờ cron kiểm tra metrics)\n";
            } else {
                // Timer mode: comment after 120s
                $comment_at = date('Y-m-d H:i:s', time() + 120);
                if ($has_comment_status) {
                    $pdo->prepare("UPDATE scheduled_posts SET comment_at = ?, comment_status = 'pending' WHERE id = ?")
                        ->execute([$comment_at, $post['id']]);
                } else {
                    $pdo->prepare("UPDATE scheduled_posts SET comment_at = ? WHERE id = ?")
                        ->execute([$comment_at, $post['id']]);
                }
            }
        }

        // Insert history (fault-tolerant)
        try {
            $display_content = $post['content'];
            $h_stmt = $pdo->prepare("INSERT INTO posts_history (page_id, post_type, content, fb_post_id) VALUES (?, ?, ?, ?)");
            $h_stmt->execute([$post['page_id'], $post_type, $display_content, $post_id]);
        } catch (Exception $e) {
            // posts_history table may not exist
        }

        // Xóa local file nếu là Local Scheduler File (uploads/...) - Không xóa file hệ thống
        if (!$is_drive && $has_media && file_exists($abs_media_path) && strpos($abs_media_path, 'uploads/') !== false) {
            // SAFE UNLINK: Tránh xoá nhầm ảnh nếu hình ảnh được chia sẻ cho nhiều Campaign / Page cùng lúc
            $check_usages = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE status IN ('pending', 'processing', 'failed') AND media_path = ? AND id != ?");
            $check_usages->execute([$post['media_path'], $post['id']]);
            if ($check_usages->fetchColumn() == 0) {
                @unlink($abs_media_path);
            }
        }

        if ($aid > 0 && isset($account_published_today[$aid])) {
            $account_published_today[$aid]++;
        }

        echo " -> Thành công! Post ID: $post_id\n";
    } else {
        $error_msg = isset($response['data']['error']['message']) ? $response['data']['error']['message'] : json_encode($response['data']);
        marKAsFailed($pdo, $post['id'], "Lỗi API: $error_msg", $sys_max_retries, $sys_retry_interval, $has_error_msg, $has_retry_count);
    }

    // Always cleanup temp drive file for this iteration
    if ($temp_drive_file && file_exists($temp_drive_file)) {
        @unlink($temp_drive_file);
    }
} // Ket thuc vong lap

$elapsed = round(microtime(true) - $start_time, 2);
echo "Hoan thanh phien quet cho Page ID #$target_page_id ({$elapsed}s).\n";
echo "-------------------------------------------\n";

// Giai phong va xoa lock file (tranh tich luy file rac)
if ($lock_fp) {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
}
@unlink($lock_file);
// Cleanup DB chay rieng qua cron/cleanup.php (59 23 * * *).

function marKAsFailed($pdo, $id, $msg, $max_retries = 3, $retry_interval = 1, $has_error_msg = true, $has_retry_count = true)
{
    $current_retry = 0;
    if ($has_retry_count) {
        try {
            $sel = $pdo->prepare("SELECT retry_count FROM scheduled_posts WHERE id = ?");
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            $current_retry = $row ? (int) $row['retry_count'] : 0;
        } catch (Exception $e) {
            $has_retry_count = false;
        }
    }
    $new_retry = $current_retry + 1;

    echo " -> Lỗi: $msg (Lần thử: $new_retry/$max_retries)\n";

    // Build dynamic UPDATE based on available columns
    if ($has_error_msg && $has_retry_count) {
        if ($new_retry <= $max_retries) {
            $pdo->prepare("UPDATE scheduled_posts SET status='failed', error_msg=?, retry_count=?, scheduled_time=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?")
                ->execute([$msg, $new_retry, $retry_interval, $id]);
        } else {
            $pdo->prepare("UPDATE scheduled_posts SET status='failed', error_msg=?, retry_count=? WHERE id=?")
                ->execute([$msg, $new_retry, $id]);
        }
    } elseif ($has_error_msg) {
        $pdo->prepare("UPDATE scheduled_posts SET status='failed', error_msg=? WHERE id=?")
            ->execute([$msg, $id]);
    } else {
        $pdo->prepare("UPDATE scheduled_posts SET status='failed' WHERE id=?")
            ->execute([$id]);
    }
}
?>