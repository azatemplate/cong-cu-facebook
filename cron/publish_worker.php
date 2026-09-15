<?php
// cron/publish_worker.php

// This file is meant to be run via CLI or triggered by a Windows Task Scheduler/cronjob
// e.g., php d:\pagespeed\hi\facebook\cron\publish_worker.php

ignore_user_abort(true);
set_time_limit(0);

// Hỗ trợ test report
if (isset($_GET['test_report']) && $_GET['test_report'] == '1') {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/telegram.php';
    send_telegram_daily_report($pdo);
    echo "Đã gửi thử báo cáo thủ công qua Telegram và Chuông!";
    exit;
}

if (isset($_GET['reset_report']) && $_GET['reset_report'] == '1') {
    require_once __DIR__ . '/../includes/db.php';
    $pdo->query("DELETE FROM system_settings WHERE setting_key = 'last_daily_report_date'");
    echo "Đã XÓA cờ báo cáo của hôm nay. Hệ thống sẽ tự động gửi lại báo cáo ở chu kỳ phút tiếp theo!";
    exit;
}


// Nhận danh sách page_ids (phẩy cách) từ dispatcher - tất cả thuộc cùng 1 Token User
$raw_page_input = isset($argv[1]) ? trim($argv[1]) : '';
if (empty($raw_page_input) && isset($_GET['page_id'])) {
    $raw_page_input = trim($_GET['page_id']);
}
if (empty($raw_page_input)) {
    echo "Tiến trình gọi thiếu Page ID. Hủy bỏ.\n";
    exit;
}

// Parse danh sách page_ids
$target_page_ids = array_filter(array_map('trim', explode(',', $raw_page_input)));
if (empty($target_page_ids)) {
    echo "Danh sách Page ID rỗng. Hủy bỏ.\n";
    exit;
}

// Nhận user_id từ dispatcher (argv[2] hoặc $_GET['user_id'])
$user_id_lock = isset($argv[2]) ? trim($argv[2]) : '';
if (empty($user_id_lock) && isset($_GET['user_id'])) {
    $user_id_lock = trim($_GET['user_id']);
}

// Dùng biến $target_page_id cho tương thích ngược (single page fallback)
$target_page_id = $target_page_ids[0];

$lock_dir = __DIR__ . '/../locks';
if (!is_dir($lock_dir)) {
    @mkdir($lock_dir, 0777, true);
}

// Lock theo Token User ID (thay vì page_ids) để ngăn 2 worker cùng user chạy đồng thời
$lock_key = !empty($user_id_lock) ? md5('uid_' . $user_id_lock) : md5($raw_page_input);
$lock_file = $lock_dir . "/publish_user_" . $lock_key . ".lock";

// Xoá lock file cũ nếu quá 60 giây (worker cũ crash hoặc ngắt kết nối không release)
$lock_stale_seconds = 60;
if (file_exists($lock_file) && (time() - filemtime($lock_file)) > $lock_stale_seconds) {
    @unlink($lock_file);
}

$lock_fp = @fopen($lock_file, 'c');
if (!$lock_fp) {
    $lock_dir = sys_get_temp_dir();
    $lock_file = $lock_dir . "/publish_user_" . $lock_key . ".lock";
    $lock_fp = @fopen($lock_file, 'c');
}

$lock_got = false;
if ($lock_fp) {
    for ($lock_wait = 0; $lock_wait < 3; $lock_wait++) {
        if (flock($lock_fp, LOCK_EX | LOCK_NB)) {
            $lock_got = true;
            break;
        }
        sleep(1);
    }
    
    // Nếu lock thất bại và file lock tồn tại > 30s => ép giải phóng lock cũ
    if (!$lock_got && file_exists($lock_file) && (time() - filemtime($lock_file)) > 30) {
        @fclose($lock_fp);
        @unlink($lock_file);
        $lock_fp = @fopen($lock_file, 'c');
        if ($lock_fp && flock($lock_fp, LOCK_EX | LOCK_NB)) {
            $lock_got = true;
        }
    }
}

if (!$lock_got) {
    echo "Worker cho Token User này đang bận. Tự dọn lock để ưu tiên luồng mới...\n";
    if ($lock_fp) @fclose($lock_fp);
    @unlink($lock_file);
}

echo "Worker khởi động cho " . count($target_page_ids) . " Pages: " . implode(', ', $target_page_ids) . "\n";

// Khong sleep Thundering Herd (moi worker la 1 process doc lap per-page, khong tranh chap)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/drive_utils.php';
require_once __DIR__ . '/../includes/ai_rewriter.php';
require_once __DIR__ . '/../includes/telegram.php';

/**
 * Resolves a file ID from a folder ID
 * Returns array of [id, name, mimeType]
 */
if (!function_exists('resolve_drive_folder_file')) {
    function resolve_drive_folder_file($pdo, $access_token, $folder_id, $mime_filter = null, $only_anti_duplicate = false) {
        // 1. Get list of files in folder from Google Drive
        $files = list_drive_files_in_folder($access_token, $folder_id);
        if ($files === false) {
            return ['error' => 'Không thể kết nối API Google Drive hoặc thư mục không tồn tại.'];
        }

        // 2. Filter compatible files (non-folder, matching mime_filter)
        $candidates = [];
        foreach ($files as $file) {
            $mime = $file['mimeType'];
            if ($mime === 'application/vnd.google-apps.folder') {
                continue;
            }
            if ($mime_filter !== null) {
                $matched = false;
                foreach ((array)$mime_filter as $filter) {
                    $pattern = '/^' . str_replace(['/', '*'], ['\/', '.+'], $filter) . '$/i';
                    if (preg_match($pattern, $mime)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) continue;
            }
            $candidates[] = $file;
        }

        if (empty($candidates)) {
            return ['error' => 'Không tìm thấy tệp tin phù hợp trong thư mục.'];
        }

        // Nếu KHÔNG bật "Chống trùng và xóa file đã đăng Drive" => Lấy ngẫu nhiên 1 tệp bình thường, không chặn DB
        if (!$only_anti_duplicate) {
            shuffle($candidates);
            return $candidates[0];
        }

        // --- NẾU CÓ BẬT CHỐNG TRÙNG VÀ XÓA FILE DRIVE ($only_anti_duplicate = true) ---
        $posted_files = [];
        try {
            $stmt = $pdo->prepare("SELECT file_id FROM posted_folder_files WHERE folder_id = ?");
            $stmt->execute([$folder_id]);
            $posted_files = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {}

        $unposted_candidates = [];
        foreach ($candidates as $file) {
            if (!in_array($file['id'], $posted_files)) {
                $unposted_candidates[] = $file;
            }
        }

        // Recycle if all files are posted
        if (empty($unposted_candidates) && !empty($posted_files)) {
            try {
                $stmt = $pdo->prepare("DELETE FROM posted_folder_files WHERE folder_id = ?");
                $stmt->execute([$folder_id]);
            } catch (Exception $e) {}
            $unposted_candidates = $candidates;
        }

        if (empty($unposted_candidates)) {
            return ['error' => 'Không tìm thấy tệp tin phù hợp trong thư mục.'];
        }

        shuffle($unposted_candidates);

        foreach ($unposted_candidates as $file) {
            $file_id = $file['id'];
            try {
                $stmt = $pdo->prepare("INSERT INTO posted_folder_files (folder_id, file_id) VALUES (?, ?)");
                $stmt->execute([$folder_id, $file_id]);
                return $file;
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') {
                    continue;
                } else {
                    return $file;
                }
            }
        }

        return ['error' => 'Tất cả các tệp trong thư mục đã được đăng hoặc đang được đăng bởi kênh khác.'];
    }
}

// ── Spin Syntax Helper ──────────────────────────────────────────────────────
// Xử lý cú pháp spin: {nội dung 1|nội dung 2|nội dung 3} → random chọn 1
function spin_text($text) {
    if (empty($text) || strpos($text, '{') === false) return $text;
    // Xử lý từ trong ra ngoài (hỗ trợ nested spin)
    $max_iterations = 10;
    $i = 0;
    while (preg_match('/\{([^{}]+)\}/', $text) && $i < $max_iterations) {
        $text = preg_replace_callback('/\{([^{}]+)\}/', function($matches) {
            $options = explode('|', $matches[1]);
            return trim($options[array_rand($options)]);
        }, $text);
        $i++;
    }
    return $text;
}

if (!function_exists('ensure_https_url')) {
    function ensure_https_url($urlStr) {
        $url = trim((string)$urlStr);
        if (empty($url)) return '';
        if (strpos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }
        return $url;
    }
}

if (!function_exists('get_system_site_url')) {
    function get_system_site_url($pdo) {
        if (!empty($_SERVER['HTTP_HOST'])) {
            $is_ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
            $scheme = $is_ssl ? 'https' : 'http';
            $url = $scheme . '://' . $_SERVER['HTTP_HOST'];
            try {
                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('base_site_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$url, $url]);
            } catch (Exception $e) {}
            return $url;
        }
        try {
            $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'base_site_url'");
            $saved = $stmt ? trim($stmt->fetchColumn() ?: '') : '';
            if (!empty($saved)) {
                if (strpos($saved, 'http://') === 0) {
                    $saved = 'https://' . substr($saved, 7);
                }
                return rtrim($saved, '/');
            }
        } catch (Exception $e) {}
        return 'https://fbweb.hongdolab.com';
    }
}

if (!function_exists('download_remote_file_to_local')) {
    function download_remote_file_to_local($url, $output_path) {
        if (empty($url)) return false;
        $fp = @fopen($output_path, 'w+b');
        if (!$fp) return false;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $success = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($success && $http_code >= 200 && $http_code < 300 && file_exists($output_path) && filesize($output_path) > 1000) {
            return true;
        }
        @unlink($output_path);
        return false;
    }
}

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
                        $url = 'https://data.hongdolab.com/uploads/' . $stored_name;
                        return function_exists('ensure_https_url') ? ensure_https_url($url) : $url;
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
                return function_exists('ensure_https_url') ? ensure_https_url($json['url']) : $json['url'];
            }
        }
        return false;
    }
}

if (!function_exists('is_valid_buffer_media_url')) {
    function is_valid_buffer_media_url($url) {
        $url = trim((string)$url);
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        $path = parse_url($url, PHP_URL_PATH);
        if (empty($path) || $path === '/' || $path === '') return false;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['mp4', 'mov', 'webm', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', 'jpg', 'jpeg', 'png', 'webp', 'gif']);
    }
}

if (!function_exists('call_buffer_worker_graphql')) {
    function call_buffer_worker_graphql($token, $query, $variables = []) {
        $payload = ['query' => $query];
        if (!empty($variables)) $payload['variables'] = $variables;

        $ch = curl_init('https://api.buffer.com/graphql');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . trim($token),
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res ? json_decode($res, true) : null;
    }
}



// Auto-migrate newly required columns
try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN post_delay_seconds INT DEFAULT 15");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN retry_interval_minutes INT DEFAULT 1");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN max_retries INT DEFAULT 3");
} catch (Exception $e) {}

// Auto-migrate updated_at for scheduled_posts (stuck detection)
try {
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
} catch (Exception $e) {}

// Auto-migrate status column from ENUM to VARCHAR to support 'checkpoint'
try {
    $pdo->exec("ALTER TABLE scheduled_posts MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
} catch (Exception $e) {}

$start_time = microtime(true);
echo "-------------------------------------------\n";
echo "Bat dau quet bai viet len lich luc: " . date('Y-m-d H:i:s') . "\n";

// ── Reset stuck 'processing' posts back to 'pending' ─────────────────────
// If a previous worker run crashed, posts stay at 'processing' forever.
// We reset them so they can be retried.
try {
    $stuck = $pdo->exec("UPDATE scheduled_posts SET status='pending' WHERE status='processing' AND updated_at <= DATE_SUB(NOW(), INTERVAL 20 MINUTE)");
    if ($stuck > 0)
        echo "  [RESET] Reset $stuck bài bị kẹt ở trạng thái 'processing' về 'pending'.\n";
} catch (Exception $e) {
}

// ── Detect available columns ─────────────────────────────────────────────
$has_fb_post_id = true;
$has_error_msg = true;
$has_retry_count = true;
$has_comment_lines = true;
$has_comment_at = true;
$has_comment_status = true;
$has_comment_mode = true;

// Fetch system settings for retry logic
$sys_retry_interval = 1;
$sys_max_retries = 3;

// Chuỗi Fallback Chain 4 tầng bóc tách TikTok theo đúng tài liệu kỹ thuật huong-dan.txt (ongchummo.com)
function fetch_tiktok_info(string $tiktok_url): ?array
{
    $tiktok_url = trim($tiktok_url);
    if (empty($tiktok_url)) return null;

    $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';

    // ==================== CÁCH 1: TIKWM API (tikwm.com) ====================
    $ch1 = curl_init('https://tikwm.com/api/');
    curl_setopt_array($ch1, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['url' => $tiktok_url, 'hd' => 1]),
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $user_agent,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json, text/plain, */*'
        ]
    ]);
    $resp1 = curl_exec($ch1);
    curl_close($ch1);

    if ($resp1 && strpos($resp1, 'Just a moment...') === false) {
        $data1 = json_decode($resp1, true);

        // Tự động thử lại TikWM 1 lần nếu bị rate limit
        if ($data1 && isset($data1['code']) && $data1['code'] === -1 && strpos(strtolower($data1['msg'] ?? ''), 'limit') !== false) {
            usleep(1500000); // Ngủ 1.5s
            $ch1_retry = curl_init('https://tikwm.com/api/');
            curl_setopt_array($ch1_retry, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['url' => $tiktok_url, 'hd' => 1]),
                CURLOPT_TIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . $user_agent,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json, text/plain, */*'
                ]
            ]);
            $resp1 = curl_exec($ch1_retry);
            curl_close($ch1_retry);
            $data1 = json_decode($resp1, true);
        }

        if ($data1 && isset($data1['code']) && $data1['code'] === 0 && isset($data1['data'])) {
            $d = $data1['data'];
            $dl_url = !empty($d['hdplay']) ? $d['hdplay'] : (!empty($d['play']) ? $d['play'] : null);
            if ($dl_url) {
                return [
                    'download_url' => $dl_url,
                    'title'        => $d['title'] ?? 'tiktok_video',
                    'video_id'     => $d['id'] ?? null,
                    'images'       => $d['images'] ?? [],
                    'provider'     => 'tikwm'
                ];
            }
        }
    }

    // ==================== CÁCH 2: SNAPCDN / TIKDOWNLOADER / TIKVID / SAVETIK API (AJAXSEARCH + DECODE JWT) ====================
    $snap_hosts = ['tikdownloader.io', 'tikvid.io', 'savetik.co'];
    foreach ($snap_hosts as $shost) {
        $ch2 = curl_init("https://{$shost}/api/ajaxSearch");
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['q' => $tiktok_url, 'lang' => 'en']),
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . $user_agent,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json, text/plain, */*'
            ]
        ]);
        $resp2 = curl_exec($ch2);
        curl_close($ch2);

        if ($resp2) {
            $data2 = json_decode($resp2, true);
            $s_html = $data2['data'] ?? '';
            if (!empty($s_html)) {
                // Thuật toán giải mã JWT Token trong dl.snapcdn.app/get?token=...
                if (preg_match('/(?:token=|dl\.snapcdn\.app\/get\?token=)([A-Za-z0-9_\.-]+)/i', $s_html, $m_jwt)) {
                    $jwt = $m_jwt[1];
                    $jwt_parts = explode('.', $jwt);
                    if (count($jwt_parts) >= 2) {
                        $payload_b64 = $jwt_parts[1];
                        $payload_b64 = str_replace(['-', '_'], ['+', '/'], $payload_b64);
                        $mod = strlen($payload_b64) % 4;
                        if ($mod !== 0) $payload_b64 .= str_repeat('=', 4 - $mod);
                        $decoded_json = @base64_decode($payload_b64);
                        if ($decoded_json) {
                            $payload = json_decode($decoded_json, true);
                            if ($payload && !empty($payload['url'])) {
                                return [
                                    'download_url' => $payload['url'],
                                    'title'        => $payload['filename'] ?? 'tiktok_video',
                                    'video_id'     => null,
                                    'provider'     => 'snapcdn_' . $shost
                                ];
                            }
                        }
                    }
                }

                // Fallback trích xuất trực tiếp thẻ link CDN
                if (preg_match('/href="(https:\/\/[^"]*(?:tiktokcdn|snapcdn|muscdn|tikcdn)[^"]*)"/i', $s_html, $m_href)) {
                    return [
                        'download_url' => $m_href[1],
                        'title'        => 'tiktok_video',
                        'video_id'     => null,
                        'provider'     => 'snapcdn_href_' . $shost
                    ];
                }
            }
        }
    }

    // ==================== CÁCH 3: TIKMATE API (TIKMATE.APP) ====================
    $ch3 = curl_init('https://api.tikmate.app/api/lookup');
    curl_setopt_array($ch3, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['url' => $tiktok_url]),
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $user_agent,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json, text/plain, */*'
        ]
    ]);
    $resp3 = curl_exec($ch3);
    curl_close($ch3);

    if ($resp3) {
        $data3 = json_decode($resp3, true);
        if ($data3 && !empty($data3['success']) && !empty($data3['token']) && !empty($data3['id'])) {
            $dl_url3 = "https://tikmate.app/download/{$data3['token']}/{$data3['id']}.mp4";
            return [
                'download_url' => $dl_url3,
                'title'        => $data3['desc'] ?? 'tiktok_video',
                'video_id'     => $data3['id'],
                'provider'     => 'tikmate'
            ];
        }
    }

    // ==================== CÁCH 4: SSSTIK API (SSSTIK.IO) ====================
    $ch_init = curl_init('https://ssstik.io/en');
    curl_setopt_array($ch_init, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . $user_agent]
    ]);
    $page_html = curl_exec($ch_init);
    curl_close($ch_init);

    $tt_token = '0';
    if ($page_html && preg_match('/s_tt\s*=\s*[\'"]([^\'"]+)[\'"]/', $page_html, $m)) {
        $tt_token = $m[1];
    }

    $ch_sss = curl_init('https://ssstik.io/abc?url=dl');
    curl_setopt_array($ch_sss, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'id' => $tiktok_url,
            'locale' => 'en',
            'tt' => $tt_token
        ]),
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $user_agent,
            'Referer: https://ssstik.io/en',
            'Origin: https://ssstik.io',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8'
        ]
    ]);
    $sss_html = curl_exec($ch_sss);
    curl_close($ch_sss);

    if ($sss_html) {
        $dl_sss = null;
        if (preg_match('/href="([^"]+)"[^>]*class="[^"]*without_watermark[^"]*"/i', $sss_html, $m)) {
            $dl_sss = $m[1];
        } elseif (preg_match('/href="(https:\/\/tikcdn\.io\/[^"]+)"/i', $sss_html, $m)) {
            $dl_sss = $m[1];
        }

        if ($dl_sss) {
            return [
                'download_url' => $dl_sss,
                'title'        => 'tiktok_video',
                'video_id'     => null,
                'provider'     => 'ssstik'
            ];
        }
    }

    return null;
}
// Lớp hỗ trợ lock tài nguyên Token (đảm bảo 1 token chỉ đăng 1 post 1 lúc, và có delay)
class TokenLocker {
    private $fp = null;
    private $lock_file;
    public function __construct($uid, $delay_sec) {
        if (!$uid) return;
        $lock_dir = __DIR__ . '/../locks';
        if (!is_dir($lock_dir)) {
            @mkdir($lock_dir, 0777, true);
        }
        $this->lock_file = $lock_dir . "/publish_token_" . md5($uid) . ".lock";
        
        // Dọn lock file tồn tại > 60s để tránh kẹt luồng vô tận
        if (file_exists($this->lock_file) && (time() - filemtime($this->lock_file) > 60)) {
            @unlink($this->lock_file);
        }

        $this->fp = @fopen($this->lock_file, 'c');
        if ($this->fp) {
            $wait_time = 0;
            // Đợi tối đa 30s nhường cho worker trước upload xong
            while ($wait_time < 30) {
                if (flock($this->fp, LOCK_EX | LOCK_NB)) {
                    rewind($this->fp);
                    $last = (int)stream_get_contents($this->fp);
                    $elapsed = time() - $last;
                    if ($elapsed >= 0 && $elapsed < $delay_sec) {
                        $s = $delay_sec - $elapsed;
                        echo "   → Chờ $s giây trước khi đăng tiếp (Delay cấu hình của Token)... \n";
                        sleep($s);
                    }
                    break;
                }
                sleep(1);
                $wait_time++;
            }
        }
    }
    public function __destruct() {
        if ($this->fp) {
            ftruncate($this->fp, 0);
            rewind($this->fp);
            fwrite($this->fp, time());
            fflush($this->fp);
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
        }
    }
}

// 1. Fetch pending posts for ALL page_ids of this Token User
$retry_clause = $has_retry_count
    ? "OR (sp.status = 'failed' AND (sp.retry_count IS NULL OR sp.retry_count < COALESCE(sa.max_retries, 3)))"
    : '';

// Build placeholders cho IN clause
$placeholders = implode(',', array_fill(0, count($target_page_ids), '?'));
$params = $target_page_ids;
$post_type_filter = "";
$account_filter = "";

if (!empty($user_id_lock)) {
    if (strpos($user_id_lock, 'yt_chan_') === 0) {
        // Luồng YouTube theo từng Kênh YouTube (Mỗi Kênh YouTube = 1 Slot độc lập)
        $post_type_filter = "AND sp.post_type = 'YouTube' ";
        $yt_chan_id = substr($user_id_lock, 8);
        if (!empty($yt_chan_id)) {
            $account_filter = "AND sp.page_id = ? ";
            array_unshift($params, $yt_chan_id);
        }
    } elseif (strpos($user_id_lock, 'yt_') === 0) {
        // Luồng YouTube tương thích ngược (theo account_id)
        $post_type_filter = "AND sp.post_type = 'YouTube' ";
        $actual_account_id = substr($user_id_lock, 3);
        if (is_numeric($actual_account_id)) {
            $account_filter = "AND sp.account_id = ? ";
            array_unshift($params, (int)$actual_account_id);
        }
    } elseif (strpos($user_id_lock, 'buf_acc_') === 0) {
        // Luồng Buffer theo Buffer Token Account ID cụ thể (Mỗi Token Buffer = 1 Slot độc lập)
        $post_type_filter = "AND sp.post_type LIKE 'Buffer%' ";
        $buf_acc_id = substr($user_id_lock, 8);
        if (is_numeric($buf_acc_id)) {
            $account_filter = "AND bc.buffer_account_id = ? ";
            array_unshift($params, (int)$buf_acc_id);
        }
    } elseif (strpos($user_id_lock, 'buf_') === 0) {
        // Luồng Buffer tương thích ngược
        $post_type_filter = "AND sp.post_type LIKE 'Buffer%' ";
        $actual_account_id = substr($user_id_lock, 4);
        if (is_numeric($actual_account_id)) {
            $account_filter = "AND sp.account_id = ? ";
            array_unshift($params, (int)$actual_account_id);
        }
    } elseif (strpos($user_id_lock, 'tt_') === 0) {
        // Luồng TikTok: Chỉ lấy post_type = TikTok và account_id cụ thể
        $post_type_filter = "AND sp.post_type = 'TikTok' ";
        $actual_account_id = substr($user_id_lock, 3);
        if (is_numeric($actual_account_id)) {
            $account_filter = "AND sp.account_id = ? ";
            array_unshift($params, (int)$actual_account_id);
        }
    } elseif (strpos($user_id_lock, 'ig_') === 0) {
        // Luồng Instagram: Chỉ lấy post_type LIKE 'Instagram%' và page_id cụ thể
        $post_type_filter = "AND sp.post_type LIKE 'Instagram%' ";
        $actual_ig_user_id = substr($user_id_lock, 3);
        if (!empty($actual_ig_user_id)) {
            $account_filter = "AND sp.page_id = ? ";
            array_unshift($params, $actual_ig_user_id);
        }
    } else {
        // Luồng Facebook: Chỉ lấy post_type KHÔNG PHẢI YouTube, Buffer, TikTok, Instagram
        $post_type_filter = "AND sp.post_type NOT LIKE 'Buffer%' AND sp.post_type != 'YouTube' AND sp.post_type != 'TikTok' AND sp.post_type NOT LIKE 'Instagram%' ";
    }
}

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
$stmt = $pdo->prepare($sql);
if (!$stmt) {
    file_put_contents(__DIR__ . '/worker_error.log', date('Y-m-d H:i:s') . " - Prepare Error: " . print_r($pdo->errorInfo(), true) . "\n", FILE_APPEND);
    if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); }
    @unlink($lock_file);
    exit;
}
if (!$stmt->execute($params)) {
    file_put_contents(__DIR__ . '/worker_error.log', date('Y-m-d H:i:s') . " - Execute Error: " . print_r($stmt->errorInfo(), true) . "\n", FILE_APPEND);
    if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); }
    @unlink($lock_file);
    exit;
}
$pending_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($pending_posts)) {
    if (isset($pending_posts[0]['sa_max_retries']) && $pending_posts[0]['sa_max_retries'] !== null) {
        $sys_max_retries = (int)$pending_posts[0]['sa_max_retries'];
    }
    if (isset($pending_posts[0]['sa_retry_interval']) && $pending_posts[0]['sa_retry_interval'] !== null) {
        $sys_retry_interval = (int)$pending_posts[0]['sa_retry_interval'];
    }
}

if (empty($pending_posts)) {
    echo "Không có bài viết nào cần đăng cho " . count($target_page_ids) . " Pages.\n";
    echo "-------------------------------------------\n";
    if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); }
    @unlink($lock_file);
    exit;
}

// Lấy delay cấu hình từ DB (đã có sẵn từ query)
$user_delay_sec = 15;
if (isset($pending_posts[0]['sa_delay']) && $pending_posts[0]['sa_delay'] !== null) {
    $user_delay_sec = (int)$pending_posts[0]['sa_delay'];
}

// Xáo trộn ngẫu nhiên để công bằng giữa các Page trong cùng Token User
shuffle($pending_posts);

echo "Tìm thấy " . count($pending_posts) . " bài viết cần đăng (Delay: {$user_delay_sec}s giữa mỗi post).\n";

$account_published_today = [];
$account_limits = [];
$post_index = 0; // Đếm số post đã xử lý để áp dụng delay

foreach ($pending_posts as $post) {
    // ── Delay giữa mỗi post (bỏ qua post đầu tiên) ──────────────────────
    if ($post_index > 0 && $user_delay_sec > 0) {
        echo "   → Chờ {$user_delay_sec}s trước khi đăng post tiếp theo (Token User delay)...\n";
        sleep($user_delay_sec);
    }
    $post_index++;
    echo "Đang xử lý bài đăng ID: {$post['id']} - Loại: {$post['post_type']}\n";

    // Lưu account_id cho hàm marKAsFailed có thể gửi Telegram
    $GLOBALS['_current_account_id'] = $post['account_id'] ?? 0;

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
        $yt_stmt = $pdo->prepare("SELECT yc.*, COALESCE(yc.gg_client_id, sa.gg_client_id) AS gg_client_id, COALESCE(yc.gg_client_secret, sa.gg_client_secret) AS gg_client_secret FROM youtube_channels yc JOIN system_accounts sa ON yc.account_id = sa.id WHERE yc.id = ?");
        $yt_stmt->execute([$post['page_id']]);
        $yt_channel = $yt_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$yt_channel || empty($yt_channel['refresh_token'])) {
            marKAsFailed($pdo, $post['id'], "Không tìm thấy refresh_token cho Kênh YouTube.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $client_id = $yt_channel['gg_client_id'];
        $client_secret = $yt_channel['gg_client_secret'];

        if (empty($client_id) || empty($client_secret)) {
            marKAsFailed($pdo, $post['id'], "Thiếu cấu hình Google Client ID và Secret của bạn (Vui lòng vào Cài đặt để bổ sung).", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        // Lấy Access Token từ Refresh Token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $yt_channel['refresh_token'],
            'grant_type' => 'refresh_token'
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $token_res = curl_exec($ch);
        curl_close($ch);
        $token_data = json_decode($token_res, true);

        if (empty($token_data['access_token'])) {
            marKAsFailed($pdo, $post['id'], "Lỗi cấp mới Access Token YouTube: " . ($token_data['error'] ?? 'Unknown'), $sys_max_retries, $sys_retry_interval);
            continue;
        }
        $access_token = $token_data['access_token'];

        // Khởi tạo Lock cho YouTube API dựa trên ID Kênh (Channel ID)
        $yt_delay_sec = 15;
        if ($post['account_id'] > 0) {
            try {
                $stmt_d = $pdo->prepare("SELECT post_delay_seconds FROM system_accounts WHERE id = ?");
                $stmt_d->execute([$post['account_id']]);
                $d = $stmt_d->fetchColumn();
                if ($d !== false) $yt_delay_sec = (int)$d;
            } catch (Exception $ed) {}
        }
        $yt_lock_id = "yt_channel_" . $post['page_id'];
        $yt_locker = new TokenLocker($yt_lock_id, $yt_delay_sec);

        // Download Media (nếu là Tiktok hoặc Drive)
        $raw_media = $post['media_path'];
        $is_folder = strpos($raw_media, 'folder:') === 0;
        $is_drive = strpos($raw_media, 'drive:') === 0;
        $is_tiktok = strpos($raw_media, 'tiktok:') === 0;
        $abs_media_path = '';
        $temp_drive_file = null;
        $t_title_override = '';

        $resolved_file_id = null;
        $folder_id_to_log = null;

        if ($is_folder) {
            $folder_id = substr($raw_media, 7);
            $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
            if (!$drive_token) {
                marKAsFailed($pdo, $post['id'], "Lỗi tải Google Drive: Thiếu Token", $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $c_data = json_decode($post['content'] ?? '', true);
            $is_anti_dup = !empty($c_data['delete_drive_file']);
            $resolved_file_info = resolve_drive_folder_file($pdo, $drive_token, $folder_id, 'video/*', $is_anti_dup);
            if (isset($resolved_file_info['error'])) {
                marKAsFailed($pdo, $post['id'], "Lỗi quét thư mục Drive: " . $resolved_file_info['error'], $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $drive_file_id = $resolved_file_info['id'];
            $resolved_file_id = $drive_file_id;
            $folder_id_to_log = $folder_id;
            
            // Persist the resolved drive file path to the database
            $new_media_path = 'drive:' . $drive_file_id;
            $post['media_path'] = $new_media_path;
            $raw_media = $new_media_path;
            $is_folder = false;
            $is_drive = true;
            
            $pdo->prepare("UPDATE scheduled_posts SET media_path = ? WHERE id = ?")
                ->execute([$new_media_path, $post['id']]);
            
            $file_info = download_drive_file_temp($drive_token, $drive_file_id);
            if (isset($file_info['error'])) {
                marKAsFailed($pdo, $post['id'], "Lỗi tải Video Drive từ thư mục: " . $file_info['error'], $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $abs_media_path = $file_info['path'];
            $temp_drive_file = $abs_media_path;
            $t_title_override = pathinfo($file_info['name'], PATHINFO_FILENAME);
        } elseif ($is_drive) {
            $drive_file_id = substr($raw_media, 6);
            $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
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
            $tik_data = fetch_tiktok_info($tiktok_url);
            if (!$tik_data || !isset($tik_data['download_url'])) {
                marKAsFailed($pdo, $post['id'], "Không thể kết nối API tải video TikTok.", $sys_max_retries, $sys_retry_interval);
                continue;
            }

            $tik_title = isset($tik_data['title']) ? $tik_data['title'] : '';
            $t_title_override = $tik_title;

            // Download the video locally via cURL stream
            $abs_media_path = sys_get_temp_dir() . '/' . uniqid('yt_tik_') . '.mp4';
            $downloaded = download_remote_file_to_local($tik_data['download_url'], $abs_media_path);
            if (!$downloaded) {
                marKAsFailed($pdo, $post['id'], "Không thể tải trực tiếp file video TikTok.", $sys_max_retries, $sys_retry_interval);
                continue;
            }
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
        // Apply spin syntax trước khi qua AI
        if (isset($content_data['description'])) $content_data['description'] = spin_text($content_data['description']);
        if (isset($content_data['title'])) $content_data['title'] = spin_text($content_data['title']);

        if (isset($content_data['use_ai']) && $content_data['use_ai']) {
            $is_auto = isset($content_data['auto_title']) && $content_data['auto_title'];
            if ($is_auto && !empty($t_title_override)) {
                // Checkbox auto_title ON: {prompt} = Tên file/Title TikTok + mô tả user nhập (nếu có)
                $user_desc = trim($content_data['description'] ?? '');
                $base_text = !empty($user_desc) ? ($t_title_override . "\n\n" . $user_desc) : $t_title_override;
            } else {
                // Checkbox auto_title OFF: {prompt} = chỉ nội dung user nhập
                $base_text = trim(($content_data['title'] ?? '') . " " . ($content_data['description'] ?? ''));
            }
            if (empty($base_text))
                $base_text = $t_title_override;
            if (empty($base_text))
                $base_text = "Video giải trí và tin tức";

            $channel_title = isset($yt_channel['channel_title']) ? $yt_channel['channel_title'] : '';
            $ai_json = rewrite_youtube_with_ai($base_text, $post['account_id'], $channel_title);
            // Nếu AI thất bại (null), thử lại 1 lần sau 3 giây
            if (!$ai_json || !is_array($ai_json)) {
                echo "   → AI YouTube lần 1 thất bại, thử lại sau 3s...\n";
                sleep(3);
                $ai_json = rewrite_youtube_with_ai($base_text, $post['account_id'], $channel_title);
            }
            // Nếu vẫn thất bại → đánh dấu failed để tránh đăng nội dung trùng lặp (filename)
            if (!$ai_json || !is_array($ai_json)) {
                echo "   → AI YouTube vẫn thất bại sau 2 lần thử. Bỏ qua bài này.\n";
                marKAsFailed($pdo, $post['id'], "AI không thể viết nội dung YouTube (API lỗi/quá tải). Sẽ thử lại lượt cron tiếp theo.", $sys_max_retries, $sys_retry_interval);
                if ($temp_drive_file && file_exists($temp_drive_file)) @unlink($temp_drive_file);
                continue;
            }
            if (!empty($ai_json['title']))
                $content_data['title'] = $ai_json['title'];
            if (!empty($ai_json['description']))
                $content_data['description'] = $ai_json['description'];
            if (!empty($ai_json['tags']))
                $content_data['tags'] = $ai_json['tags'];
        } elseif (isset($content_data['auto_title']) && $content_data['auto_title'] && empty($content_data['title'])) {
            $content_data['title'] = $t_title_override;
            if (empty($content_data['description'])) {
                $content_data['description'] = $t_title_override;
            } elseif (!empty($t_title_override) && strpos($content_data['description'], $t_title_override) !== 0) {
                $content_data['description'] = $t_title_override . "\n\n" . $content_data['description'];
            }
        }

        // Đảm bảo có fallback nếu tất cả các luồng trên đều không ra title
        if (empty($content_data['title']) && isset($content_data['auto_title']) && $content_data['auto_title']) {
            $content_data['title'] = $t_title_override;
            if (empty($content_data['description'])) {
                $content_data['description'] = $t_title_override;
            } elseif (!empty($t_title_override) && strpos($content_data['description'], $t_title_override) !== 0) {
                $content_data['description'] = $t_title_override . "\n\n" . $content_data['description'];
            }
        }

        $yt_title = !empty($content_data['title']) ? mb_substr(clean_markdown($content_data['title']), 0, 100, 'UTF-8') : (!empty($t_title_override) ? mb_substr($t_title_override, 0, 100, 'UTF-8') : 'YouTube Video');
        $yt_desc = !empty($content_data['description']) ? clean_markdown($content_data['description']) : (!empty($yt_title) ? $yt_title : 'YouTube Video');
        $yt_tags_str = $content_data['tags'] ?? '';
        $yt_tags = sanitize_youtube_tags($yt_tags_str);

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

        $ch_init = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
        curl_setopt($ch_init, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_init, CURLOPT_POST, true);
        curl_setopt($ch_init, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch_init, CURLOPT_POSTFIELDS, json_encode($metadata));
        curl_setopt($ch_init, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json; charset=UTF-8",
            "X-Upload-Content-Length: $file_size"
        ]);
        curl_setopt($ch_init, CURLOPT_HEADER, true);
        curl_setopt($ch_init, CURLOPT_TIMEOUT, 30);
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
        curl_setopt($ch_upload, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch_upload, CURLOPT_TIMEOUT, 600);
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
                $pdo->prepare("UPDATE scheduled_posts SET status = 'published', fb_post_id = ?, error_msg = NULL WHERE id = ?")
                    ->execute([$video_id, $post['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET status = 'published', error_msg = NULL WHERE id = ?")
                    ->execute([$post['id']]);
            }

            // Ghi nhận file đã đăng từ thư mục để chống trùng
            if (!empty($folder_id_to_log) && !empty($resolved_file_id)) {
                try {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO posted_folder_files (folder_id, file_id) VALUES (?, ?)");
                    $stmt->execute([$folder_id_to_log, $resolved_file_id]);
                } catch (Exception $e) {}
            }

            if (!empty($content_data['delete_drive_file']) && $is_drive) {
                // SAFE DELETE: check usages
                $check_usages = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE status IN ('pending', 'processing', 'failed') AND media_path = ? AND id != ?");
                $check_usages->execute([$post['media_path'], $post['id']]);
                $remaining_usages = $check_usages->fetchColumn();

                if ($remaining_usages == 0) {
                    $drive_file_id = substr($raw_media, 6);
                    $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
                    if ($drive_token) {
                        $del_res = delete_drive_file($drive_token, $drive_file_id);
                        if ($del_res) {
                            echo "   → [SAFE DELETE] Đã xóa file trên Google Drive thành công: $drive_file_id\n";
                        } else {
                            echo "   → [LỖI] Không thể xóa file trên Google Drive: $drive_file_id (Có thể do thiếu quyền/scope hoặc Token hết hạn)\n";
                        }
                    }
                } else {
                    echo "   → File Drive {$post['media_path']} vẫn còn {$remaining_usages} kênh khác đang chờ hoặc lỗi cần dùng, chưa xóa.\n";
                }
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

    // ── XỬ LÝ RIÊNG DÀNH CHO BUFFER API ──────────────────────────────────────
    if (strpos($post['post_type'], 'Buffer') !== false) {
        $stmt_b = $pdo->prepare("
            SELECT bc.channel_id, bc.channel_name, bc.service, ba.access_token 
            FROM buffer_channels bc 
            JOIN buffer_accounts ba ON bc.buffer_account_id = ba.id 
            WHERE bc.channel_id = ? AND bc.account_id = ?
        ");
        $stmt_b->execute([$post['page_id'], $post['account_id']]);
        $buf_chan = $stmt_b->fetch(PDO::FETCH_ASSOC);

        if (!$buf_chan || empty($buf_chan['access_token'])) {
            marKAsFailed($pdo, $post['id'], "Không tìm thấy Access Token của Buffer cho kênh này.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $token      = $buf_chan['access_token'];
        $channel_id = $buf_chan['channel_id'];
        $service    = strtolower($buf_chan['service']);

        $raw_media        = $post['media_path'];
        $is_folder        = (strpos($raw_media, 'folder:') === 0);
        $is_drive         = (strpos($raw_media, 'drive:') === 0);
        $is_tiktok        = (strpos($raw_media, 'tiktok:') === 0);
        $public_media_url = null;
        $t_title_override = '';

        if (!function_exists('ensure_https_url')) {
            function ensure_https_url($urlStr) {
                $url = trim((string)$urlStr);
                if (empty($url)) return '';
                if (strpos($url, 'http://') === 0) {
                    return 'https://' . substr($url, 7);
                }
                return $url;
            }
        }

        if (!function_exists('upload_file_to_hongdolab_cdn')) {
            function upload_file_to_hongdolab_cdn($file_path) {
                if (!file_exists($file_path) || filesize($file_path) < 10) return false;
                @set_time_limit(0);
                
                $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                $upload_tmp_dir = dirname($file_path) . '/';
                if (!is_dir($upload_tmp_dir)) $upload_tmp_dir = __DIR__ . '/../uploads/';
                
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
                            return ensure_https_url($json['url']);
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
                                
                                $tmp_chunk = tempnam($upload_tmp_dir, 'buf_chk_' . getmypid() . '_');
                                file_put_contents($tmp_chunk, $chunk_data);
                                
                                $chunk_success = false;
                                for ($retry = 0; $retry < 5 && !$chunk_success; $retry++) {
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
                                        CURLOPT_TIMEOUT => 300,
                                        CURLOPT_SSL_VERIFYPEER => false,
                                        CURLOPT_SSL_VERIFYHOST => 0
                                    ]);
                                    $c_res = curl_exec($ch);
                                    curl_close($ch);
                                    $c_json = $c_res ? json_decode($c_res, true) : null;
                                    if ($c_res && isset($c_json['ok']) && $c_json['ok']) {
                                        $chunk_success = true;
                                    } else {
                                        sleep(1 + $retry);
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
                                    return ensure_https_url($comp_json['url']);
                                }
                            }
                        }
                    }
                }
                return false;
            }
        }

        if (!function_exists('get_system_site_url')) {
            function get_system_site_url($pdo) {
                if (!empty($_SERVER['HTTP_HOST'])) {
                    $is_ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                        || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
                    $scheme = $is_ssl ? 'https' : 'http';
                    $url = $scheme . '://' . $_SERVER['HTTP_HOST'];
                    try {
                        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('base_site_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                            ->execute([$url, $url]);
                    } catch (Exception $e) {}
                    return $url;
                }
                try {
                    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'base_site_url'");
                    $saved = $stmt ? trim($stmt->fetchColumn() ?: '') : '';
                    if (!empty($saved)) {
                        if (strpos($saved, 'http://') === 0) {
                            $saved = 'https://' . substr($saved, 7);
                        }
                        return rtrim($saved, '/');
                    }
                } catch (Exception $e) {}
                return 'https://fbweb.hongdolab.com';
            }
        }

        $upload_dir = __DIR__ . '/../uploads/';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);

        static $buf_media_cache = [];

        if ($is_folder) {
            $folder_id   = substr($raw_media, 7);
            $drive_token = get_drive_access_token($pdo, $post['account_id'], null);
            if (!$drive_token) {
                marKAsFailed($pdo, $post['id'], "Lỗi tải Google Drive: Thiếu Token tài khoản Google Drive (chưa liên kết/hết hạn).", $sys_max_retries, $sys_retry_interval);
                continue;
            }

            $c_data = json_decode($post['content'] ?? '', true);
            $is_anti_dup = !empty($c_data['delete_drive_file']);
            $resolved_file_info = resolve_drive_folder_file($pdo, $drive_token, $folder_id, null, $is_anti_dup);
            if (isset($resolved_file_info['error'])) {
                marKAsFailed($pdo, $post['id'], "Lỗi quét thư mục Drive: " . $resolved_file_info['error'], $sys_max_retries, $sys_retry_interval);
                continue;
            }

            $drive_file_id = $resolved_file_info['id'];
            if (function_exists('make_drive_file_public')) {
                make_drive_file_public($drive_token, $drive_file_id);
            }
            $temp_res = download_drive_file_temp($drive_token, $drive_file_id);
            if (!isset($temp_res['path']) || !file_exists($temp_res['path'])) {
                $err_msg = $temp_res['error'] ?? "Không thể tải tệp từ thư mục Google Drive về máy chủ.";
                marKAsFailed($pdo, $post['id'], "Lỗi tải tệp Drive: " . $err_msg, $sys_max_retries, $sys_retry_interval);
                continue;
            }

            $ext = pathinfo($temp_res['name'] ?? 'media.mp4', PATHINFO_EXTENSION) ?: 'mp4';
            $unique_id = $post['id'] . '_' . bin2hex(random_bytes(4));
            $dest_filename = 'buf_drive_' . $unique_id . '.' . $ext;
            $dest_path = $upload_dir . $dest_filename;
            @copy($temp_res['path'], $dest_path);
            @unlink($temp_res['path']);

            $zp_cdn = false;
            for ($cdn_retry = 0; $cdn_retry < 5 && !$zp_cdn; $cdn_retry++) {
                $zp_cdn = upload_file_to_hongdolab_cdn($dest_path);
                if (!$zp_cdn) sleep(2 * ($cdn_retry + 1));
            }

            if ($zp_cdn) {
                $public_media_url = $zp_cdn;
            } else {
                @unlink($dest_path);
                marKAsFailed($pdo, $post['id'], "Không thể upload tệp video từ Google Drive lên CDN data.hongdolab.com.", $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $t_title_override = pathinfo($temp_res['name'], PATHINFO_FILENAME);
        } elseif ($is_drive) {
            if (!empty($buf_media_cache[$raw_media])) {
                $public_media_url = $buf_media_cache[$raw_media]['url'];
                $t_title_override = $buf_media_cache[$raw_media]['title'];
            } else {
                $drive_file_id = substr($raw_media, 6);
                $drive_token = get_drive_access_token($pdo, $post['account_id'], null);
                if (!$drive_token) {
                    marKAsFailed($pdo, $post['id'], "Lỗi tải Google Drive: Thiếu Token tài khoản Google Drive (chưa liên kết/hết hạn).", $sys_max_retries, $sys_retry_interval);
                    continue;
                }

                if (function_exists('make_drive_file_public')) {
                    make_drive_file_public($drive_token, $drive_file_id);
                }
                $temp_res = download_drive_file_temp($drive_token, $drive_file_id);
                if (!isset($temp_res['path']) || !file_exists($temp_res['path'])) {
                    $err_msg = $temp_res['error'] ?? "Không thể tải tệp Google Drive về máy chủ.";
                    marKAsFailed($pdo, $post['id'], "Lỗi tải tệp Drive: " . $err_msg, $sys_max_retries, $sys_retry_interval);
                    continue;
                }

                $ext = pathinfo($temp_res['name'] ?? 'media.mp4', PATHINFO_EXTENSION) ?: 'mp4';
                $unique_id = $post['id'] . '_' . bin2hex(random_bytes(4));
                $dest_filename = 'buf_drive_' . $unique_id . '.' . $ext;
                $dest_path = $upload_dir . $dest_filename;
                @copy($temp_res['path'], $dest_path);
                @unlink($temp_res['path']);

                $zp_cdn = false;
                for ($cdn_retry = 0; $cdn_retry < 5 && !$zp_cdn; $cdn_retry++) {
                    $zp_cdn = upload_file_to_hongdolab_cdn($dest_path);
                    if (!$zp_cdn) sleep(2 * ($cdn_retry + 1));
                }

                if ($zp_cdn) {
                    $public_media_url = $zp_cdn;
                } else {
                    @unlink($dest_path);
                    marKAsFailed($pdo, $post['id'], "Không thể upload tệp video Google Drive lên CDN data.hongdolab.com.", $sys_max_retries, $sys_retry_interval);
                    continue;
                }
                $t_title_override = pathinfo($temp_res['name'], PATHINFO_FILENAME);

                if ($public_media_url) {
                    $buf_media_cache[$raw_media] = [
                        'url' => $public_media_url,
                        'title' => $t_title_override
                    ];
                }
            }
        } elseif ($is_tiktok) {
            $tiktok_url = substr($raw_media, 7);
            $tik_data = fetch_tiktok_info($tiktok_url);
            if ($tik_data && !empty($tik_data['download_url'])) {
                $t_title_override = $tik_data['title'] ?? '';
                $dest_filename = 'buf_tiktok_' . ($tik_data['video_id'] ?: uniqid()) . '.mp4';
                $dest_path = $upload_dir . $dest_filename;

                $downloaded = download_remote_file_to_local($tik_data['download_url'], $dest_path);
                if ($downloaded) {
                    if (file_exists($dest_path) && filesize($dest_path) > 1000) {
                        $zp_cdn = false;
                        for ($cdn_retry = 0; $cdn_retry < 2 && !$zp_cdn; $cdn_retry++) {
                            $zp_cdn = upload_file_to_hongdolab_cdn($dest_path);
                            if (!$zp_cdn) sleep(1);
                        }

                        if ($zp_cdn) {
                            $public_media_url = $zp_cdn;
                        } else {
                            @unlink($dest_path);
                            marKAsFailed($pdo, $post['id'], "Không thể upload tệp video TikTok lên CDN data.hongdolab.com.", $sys_max_retries, $sys_retry_interval);
                            continue;
                        }
                    }
                }
            }
        } else {
            if (!empty($raw_media)) {
                $local_file_to_upload = null;

                if (strpos($raw_media, 'http') === 0) {
                    // Nếu là URL http(s), kiểm tra xem có thuộc server local/fbweb không
                    $domain = get_system_site_url($pdo);
                    $domain_host = parse_url($domain, PHP_URL_HOST);
                    $media_host = parse_url($raw_media, PHP_URL_HOST);

                    if (empty($media_host) || $media_host === $domain_host || strpos($raw_media, '/uploads/') !== false) {
                        // Tệp nằm trên server local => Tìm file local tương ứng để upload lên CDN data.hongdolab.com
                        $parsed_path = parse_url($raw_media, PHP_URL_PATH);
                        $relative_path = ltrim($parsed_path, '/');
                        $candidate = __DIR__ . '/../' . $relative_path;
                        if (file_exists($candidate)) {
                            $local_file_to_upload = $candidate;
                        }
                    } else {
                        // Là URL ngoài internet => giữ nguyên
                        $public_media_url = ensure_https_url($raw_media);
                    }
                } else {
                    // Là đường dẫn tương đối (ví dụ: uploads/buf_xxx.jpg)
                    $clean_rel = ltrim($raw_media, '/');
                    $candidate1 = __DIR__ . '/../' . $clean_rel;
                    $candidate2 = $upload_dir . basename($clean_rel);
                    if (file_exists($candidate1)) {
                        $local_file_to_upload = $candidate1;
                    } elseif (file_exists($candidate2)) {
                        $local_file_to_upload = $candidate2;
                    }
                }

                if ($local_file_to_upload && file_exists($local_file_to_upload)) {
                    $zp_cdn = upload_file_to_hongdolab_cdn($local_file_to_upload);
                    if ($zp_cdn) {
                        $public_media_url = $zp_cdn;
                    }
                }

                if (empty($public_media_url)) {
                    if (strpos($raw_media, 'http') === 0) {
                        $public_media_url = ensure_https_url($raw_media);
                    } elseif (!empty(trim($raw_media))) {
                        $domain = get_system_site_url($pdo);
                        $public_media_url = ensure_https_url($domain . '/' . ltrim($raw_media, '/'));
                    }
                }
            }
        }

        if (!function_exists('is_valid_buffer_media_url')) {
            function is_valid_buffer_media_url($url) {
                $url = trim((string)$url);
                if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return false;
                $path = parse_url($url, PHP_URL_PATH);
                if (empty($path) || $path === '/' || $path === '') return false;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                return in_array($ext, ['mp4', 'mov', 'webm', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', 'jpg', 'jpeg', 'png', 'webp', 'gif']);
            }
        }

        // Xử lý Content & AI Rewrite
        $content_data = json_decode($post['content'], true);
        if (!$content_data && is_string($post['content'])) {
            $post_text = $post['content'];
        } else {
            $post_text = $content_data['text'] ?? ($content_data['description'] ?? '');
        }

        $is_auto_title = isset($content_data['auto_title']) ? (bool)$content_data['auto_title'] : false;

        if ($is_auto_title && !empty($t_title_override)) {
            // Checkbox ON: Nếu có mô tả => Tên file + \n\n + Mô tả, nếu mô tả rỗng => Tên file
            $post_text = !empty(trim($post_text)) ? ($t_title_override . "\n\n" . trim($post_text)) : $t_title_override;
        } else {
            // Checkbox OFF: Chỉ lấy mô tả
            $post_text = trim($post_text);
        }

        if (isset($content_data['use_ai']) && $content_data['use_ai'] && function_exists('ai_rewrite_content') && !empty($post_text)) {
            $rewritten = ai_rewrite_content($pdo, $post['account_id'], $post_text);
            if (!empty($rewritten)) $post_text = $rewritten;
        }

        // Gọi Buffer GraphQL API
        if (!function_exists('call_buffer_worker_graphql')) {
            function call_buffer_worker_graphql($token, $query, $variables = []) {
                $payload = ['query' => $query];
                if (!empty($variables)) $payload['variables'] = $variables;

                $ch = curl_init('https://api.buffer.com/graphql');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . trim($token),
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                    ],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                $res = curl_exec($ch);
                curl_close($ch);
                return $res ? json_decode($res, true) : null;
            }
        }

        $mutation = '
            mutation CreatePost($input: CreatePostInput!) {
                createPost(input: $input) {
                    ... on PostActionSuccess {
                        post {
                            id
                            text
                            status
                        }
                    }
                    ... on NotFoundError { message }
                    ... on UnauthorizedError { message }
                    ... on UnexpectedError { message }
                    ... on RestProxyError { code message }
                    ... on LimitReachedError { message }
                    ... on InvalidInputError { message }
                }
            }
        ';

        $is_scheduled_post = !empty($post['scheduled_time']) && (strtotime($post['scheduled_time']) > time() + 60);

        if ($is_scheduled_post) {
            $input = [
                'channelId' => $channel_id,
                'text' => $post_text,
                'mode' => 'customScheduled',
                'dueAt' => date('c', strtotime($post['scheduled_time'])),
                'schedulingType' => 'automatic'
            ];
        } elseif ($service === 'pinterest') {
            $input = [
                'channelId' => $channel_id,
                'text' => $post_text,
                'mode' => 'customScheduled',
                'dueAt' => date('c', time() + 30),
                'schedulingType' => 'automatic'
            ];
        } else {
            $input = [
                'channelId' => $channel_id,
                'text' => $post_text,
                'mode' => 'shareNow',
                'schedulingType' => 'automatic'
            ];
        }

        // Dựng danh sách media assets giống modal-fanpage-post-buffer.js
        $image_urls = [];
        if (!empty($public_media_url) && is_valid_buffer_media_url($public_media_url)) {
            $public_media_url = ensure_https_url($public_media_url);
            $image_urls[] = $public_media_url;
        } else {
            $public_media_url = null;
        }

        if (!empty($content_data['image_urls']) && is_array($content_data['image_urls'])) {
            foreach ($content_data['image_urls'] as $img_u) {
                $clean_u = ensure_https_url($img_u);
                if (empty($clean_u) || !is_valid_buffer_media_url($clean_u)) continue;

                // Nếu là tệp nằm trên server local, tải lên CDN data.hongdolab.com
                $domain = get_system_site_url($pdo);
                $domain_host = parse_url($domain, PHP_URL_HOST);
                $img_host = parse_url($clean_u, PHP_URL_HOST);

                if (empty($img_host) || $img_host === $domain_host || strpos($clean_u, '/uploads/') !== false) {
                    $parsed_path = parse_url($clean_u, PHP_URL_PATH);
                    $rel_path = ltrim($parsed_path, '/');
                    $candidate = __DIR__ . '/../' . $rel_path;
                    if (file_exists($candidate)) {
                        $cdn_img = upload_file_to_hongdolab_cdn($candidate);
                        if ($cdn_img) $clean_u = $cdn_img;
                    }
                }

                if (!empty($clean_u) && is_valid_buffer_media_url($clean_u) && !in_array($clean_u, $image_urls)) {
                    $image_urls[] = $clean_u;
                }
            }
        }

        $post_mode_hint = strtolower($content_data['post_mode'] ?? '');
        $url_lower = strtolower($public_media_url ?? '');

        $is_video_ext = (bool)preg_match('/\.(mp4|mov|webm|avi|mkv|flv|wmv|m4v|3gp)($|\?)/i', $url_lower);
        $is_vid_prefix = (strpos($url_lower, '/uploads/vid_') !== false || strpos($url_lower, 'vid_') !== false);
        $is_video_mime = isset($temp_res['mime']) && (strpos(strtolower($temp_res['mime']), 'video') !== false);

        $is_video_svc = in_array($service, ['tiktok', 'youtube', 'reels'])
            || $is_video_ext
            || $is_vid_prefix
            || $is_video_mime
            || ($post_mode_hint === 'video');

            if ($is_video_svc && $public_media_url && is_valid_buffer_media_url($public_media_url)) {
                $video_asset = ['url' => $public_media_url];

                $thumbnail_url = !empty($content_data['thumbnail_url']) ? ensure_https_url($content_data['thumbnail_url']) : '';

                if (!empty($thumbnail_url) && is_valid_buffer_media_url($thumbnail_url)) {
                    $video_asset['thumbnailUrl'] = $thumbnail_url;
                }

                $input['assets'] = [['video' => $video_asset]];
            } else {
                $clean_images = [];
                foreach (array_slice($image_urls, 0, 20) as $img_u) {
                    if (is_valid_buffer_media_url($img_u)) {
                        $clean_images[] = $img_u;
                    }
                }
                $input['assets'] = [];
                foreach ($clean_images as $img_u) {
                    $input['assets'][] = ['image' => ['url' => $img_u]];
                }
            }

        if ($service === 'pinterest' && !empty($input['assets']) && count($input['assets']) > 1) {
            $input['assets'] = array_slice($input['assets'], 0, 1);
        }

        if (in_array($service, ['pinterest', 'instagram', 'tiktok', 'youtube']) && empty($input['assets'])) {
            marKAsFailed($pdo, $post['id'], "Kênh " . ucfirst($service) . " yêu cầu đính kèm tệp hình ảnh hoặc video công khai hợp lệ.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        if (strpos($service, 'instagram') !== false) {
            if (empty($input['assets'])) {
                marKAsFailed($pdo, $post['id'], "Kênh Instagram yêu cầu phải đính kèm ít nhất 1 hình ảnh hoặc video.", $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $is_vid = isset($input['assets'][0]['video']);
            $input['metadata'] = [
                'instagram' => [
                    'type' => $is_vid ? 'reel' : 'post',
                    'shouldShareToFeed' => true
                ]
            ];
        }

        if (strpos($service, 'youtube') !== false) {
            $yt_title = '';
            if (!empty($content_data['title'])) {
                $yt_title = trim($content_data['title']);
            } elseif (!empty($t_title_override)) {
                $yt_title = trim($t_title_override);
            } else {
                $yt_title = mb_substr(trim(strip_tags($post_text)), 0, 95);
            }
            if (empty($yt_title)) {
                $yt_title = "Video mới";
            }
            $yt_title = mb_substr($yt_title, 0, 98);

            $yt_category = !empty($content_data['category']) ? (string)$content_data['category'] : '22';

            $input['metadata'] = [
                'youtube' => [
                    'title' => $yt_title,
                    'categoryId' => $yt_category
                ]
            ];
        }

        if ($service === 'pinterest') {
            if (empty(trim($input['text'] ?? ''))) {
                $fallback_text = !empty($t_title_override) ? $t_title_override : "Cốp Pha Việt";
                $input['text'] = $fallback_text;
            }

            $board_svc_id = '';
            $board_query1 = '
                query GetPinterestBoard($id: ChannelId!) {
                    channel(input: { id: $id }) {
                        metadata {
                            ... on PinterestMetadata {
                                boards {
                                    id
                                    name
                                    serviceId
                                }
                            }
                        }
                    }
                }
            ';
            $board_res = call_buffer_worker_graphql($token, $board_query1, ['id' => $channel_id]);
            $boards = $board_res['data']['channel']['metadata']['boards'] ?? [];

            if (!empty($boards)) {
                $board_svc_id = !empty($boards[0]['serviceId']) ? $boards[0]['serviceId'] : ($boards[0]['id'] ?? '');
            }

            if (empty($board_svc_id)) {
                // Fallback nếu không lấy được danh sách bảng Pinterest từ API
                $board_svc_id = $channel_id;
            }

            $pin_title = mb_substr(trim(strip_tags($input['text'])), 0, 90);
            if (empty($pin_title)) {
                $pin_title = "Cốp Pha Việt";
            }
            $input['metadata'] = [
                'pinterest' => [
                    'boardServiceId' => (string)$board_svc_id,
                    'title' => $pin_title
                ]
            ];
            if (!empty($content_data['link'])) {
                $input['metadata']['pinterest']['link'] = ensure_https_url($content_data['link']);
            }
        }

        $max_buf_retries = 3;
        $create_res = null;
        $res = null;

        for ($buf_retry = 1; $buf_retry <= $max_buf_retries; $buf_retry++) {
            $res = call_buffer_worker_graphql($token, $mutation, ['input' => $input]);
            $create_res = $res['data']['createPost'] ?? null;

            if (!isset($create_res['post']['id'])) {
                $err_msg = $create_res['message'] ?? ($res['errors'][0]['message'] ?? '');
                if (stripos($err_msg, 'notification scheduling') !== false || stripos($err_msg, 'personal profile') !== false) {
                    $input['schedulingType'] = 'notification';
                    $res = call_buffer_worker_graphql($token, $mutation, ['input' => $input]);
                    $create_res = $res['data']['createPost'] ?? null;
                }
            }

            if (isset($create_res['post']['id'])) {
                break; // Thành công!
            }

            $err_msg = $create_res['message'] ?? ($res['errors'][0]['message'] ?? '');
            if ($buf_retry < $max_buf_retries && (stripos($err_msg, 'could not be read') !== false || stripos($err_msg, 'Invalid post') !== false || stripos($err_msg, 'publicly accessible') !== false)) {
                echo "   ⚠️ Buffer báo lỗi tạm thời từ URL video: {$err_msg}. Chờ 5 giây để tự động thử lại (Lần {$buf_retry}/{$max_buf_retries})...\n";
                sleep(5);
            } else {
                break;
            }
        }

        if (isset($create_res['post']['id'])) {
            $buffer_post_id = $create_res['post']['id'];
            $pdo->prepare("UPDATE scheduled_posts SET status = 'published', fb_post_id = ?, error_msg = NULL WHERE id = ?")
                ->execute([$buffer_post_id, $post['id']]);
            echo "   ✅ Đăng bài thành công qua Buffer API! Post ID: {$buffer_post_id}\n";
        } else {
            $err_msg = $create_res['message'] ?? ($res['errors'][0]['message'] ?? 'Lỗi không xác định từ Buffer API');
            marKAsFailed($pdo, $post['id'], "Buffer API error: " . $err_msg, $sys_max_retries, $sys_retry_interval);
        }

        if (isset($dest_path) && file_exists($dest_path)) {
            @unlink($dest_path);
        }

        // Xong luồng Buffer, bỏ qua phần Facebook bên dưới
        continue;
    }
    // ── XỬ LÝ RIÊNG DÀNH CHO INSTAGRAM POST / REELS / STORY ──────────────────
    // ── XỬ LÝ RIÊNG DÀNH CHO INSTAGRAM POST / REELS / STORY / CAROUSEL ──────
    if (strpos($post['post_type'], 'Instagram') !== false) {
        require_once __DIR__ . '/../includes/instagram_api.php';

        $ig_stmt = $pdo->prepare("SELECT * FROM instagram_accounts WHERE (ig_user_id = ? OR id = ?) AND account_id = ?");
        $ig_stmt->execute([$post['page_id'], $post['page_id'], $post['account_id']]);
        $ig_acc = $ig_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ig_acc || empty($ig_acc['access_token'])) {
            marKAsFailed($pdo, $post['id'], "Không tìm thấy tài khoản Instagram ủy quyền hợp lệ.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $raw_media = $post['media_path'] ?? '';
        $content_data = @json_decode($post['content'], true);
        $caption = is_array($content_data) ? ($content_data['description'] ?? $content_data['text'] ?? '') : $post['content'];
        $caption = spin_text($caption);

        if (empty($raw_media)) {
            marKAsFailed($pdo, $post['id'], "Không có URL media để đăng Instagram.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        // Parse media paths (Single item vs JSON array for Carousel)
        $media_items = [];
        $decoded_media = @json_decode($raw_media, true);
        if (is_array($decoded_media)) {
            $media_items = array_values(array_filter($decoded_media));
        } else {
            $media_items = [$raw_media];
        }

        if (empty($media_items)) {
            marKAsFailed($pdo, $post['id'], "Danh sách media Instagram rỗng.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $base_domain = get_system_site_url($pdo);
        $public_media_urls = [];
        $temp_local_files = [];
        $created_upload_files = [];
        $resolved_drive_file_ids = [];
        $resolved_title_override = '';

        foreach ($media_items as $item_media) {
            $is_folder = strpos($item_media, 'folder:') === 0;
            $is_drive  = strpos($item_media, 'drive:') === 0;
            $is_tiktok = strpos($item_media, 'tiktok:') === 0;

            if ($is_folder) {
                $folder_id = substr($item_media, 7);
                $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
                if (!$drive_token) {
                    marKAsFailed($pdo, $post['id'], "Lỗi tải Google Drive: Thiếu Token", $sys_max_retries, $sys_retry_interval);
                    continue 2;
                }
                $is_anti_dup = !empty($content_data['delete_drive_file']);
                $mime_filter = ($post['post_type'] === 'Instagram_Reels') ? 'video/*' : null;
                $resolved_file_info = resolve_drive_folder_file($pdo, $drive_token, $folder_id, $mime_filter, $is_anti_dup);
                if (isset($resolved_file_info['error'])) {
                    marKAsFailed($pdo, $post['id'], "Lỗi quét thư mục Drive: " . $resolved_file_info['error'], $sys_max_retries, $sys_retry_interval);
                    continue 2;
                }
                $drive_file_id = $resolved_file_info['id'];
                $resolved_drive_file_ids[] = $drive_file_id;
                if (!empty($resolved_file_info['name'])) {
                    $resolved_title_override = pathinfo($resolved_file_info['name'], PATHINFO_FILENAME);
                }

                $file_info = download_drive_file_temp($drive_token, $drive_file_id);
                if (isset($file_info['error'])) {
                    marKAsFailed($pdo, $post['id'], "Lỗi tải tệp từ Drive: " . $file_info['error'], $sys_max_retries, $sys_retry_interval);
                    continue 2;
                }
                $temp_local_files[] = $file_info['path'];
                $ext = pathinfo($file_info['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $dest_name = 'uploads/ig_' . uniqid() . '.' . $ext;
                $dest_full = __DIR__ . '/../' . $dest_name;
                copy($file_info['path'], $dest_full);
                $created_upload_files[] = $dest_full;
                $public_media_urls[] = $base_domain . '/' . $dest_name;
            } elseif ($is_drive) {
                $drive_file_id = substr($item_media, 6);
                $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
                if (!$drive_token) {
                    marKAsFailed($pdo, $post['id'], "Lỗi tải Google Drive: Thiếu Token", $sys_max_retries, $sys_retry_interval);
                    continue 2;
                }
                $resolved_drive_file_ids[] = $drive_file_id;
                $file_info = download_drive_file_temp($drive_token, $drive_file_id);
                if (isset($file_info['error'])) {
                    marKAsFailed($pdo, $post['id'], "Lỗi tải tệp từ Drive: " . $file_info['error'], $sys_max_retries, $sys_retry_interval);
                    continue 2;
                }
                if (!empty($file_info['name'])) {
                    $resolved_title_override = pathinfo($file_info['name'], PATHINFO_FILENAME);
                }
                $temp_local_files[] = $file_info['path'];
                $ext = pathinfo($file_info['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $dest_name = 'uploads/ig_' . uniqid() . '.' . $ext;
                $dest_full = __DIR__ . '/../' . $dest_name;
                copy($file_info['path'], $dest_full);
                $created_upload_files[] = $dest_full;
                $public_media_urls[] = $base_domain . '/' . $dest_name;
            } elseif ($is_tiktok) {
                $tt_url = substr($item_media, 7);
                if (!function_exists('download_tiktok_video')) {
                    require_once __DIR__ . '/../includes/tiktok_downloader.php';
                }
                $res_tt = download_tiktok_video($tt_url);
                if (empty($res_tt['file_path']) || !file_exists($res_tt['file_path'])) {
                    marKAsFailed($pdo, $post['id'], "Lỗi tải video TikTok: " . ($res_tt['msg'] ?? 'Không tải được file'), $sys_max_retries, $sys_retry_interval);
                    continue 2;
                }
                if (!empty($res_tt['title'])) {
                    $resolved_title_override = $res_tt['title'];
                }
                $temp_local_files[] = $res_tt['file_path'];
                $dest_name = 'uploads/ig_' . uniqid() . '.mp4';
                $dest_full = __DIR__ . '/../' . $dest_name;
                copy($res_tt['file_path'], $dest_full);
                $created_upload_files[] = $dest_full;
                $public_media_urls[] = $base_domain . '/' . $dest_name;
            } else {
                $local_rel = ltrim($item_media, '/');
                if (strpos($item_media, 'http://') === 0 || strpos($item_media, 'https://') === 0) {
                    $public_media_urls[] = $item_media;
                } else {
                    $resolved_title_override = pathinfo(basename($local_rel), PATHINFO_FILENAME);
                    $local_full = __DIR__ . '/../' . $local_rel;
                    $created_upload_files[] = $local_full;
                    $public_media_urls[] = $base_domain . '/' . $local_rel;
                }
            }
        }

        if (empty($public_media_urls)) {
            marKAsFailed($pdo, $post['id'], "Không thể tạo URL công khai cho tệp phương tiện Instagram.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        // Tự động gán Tên file / Tiêu đề TikTok làm Caption nếu có cấu hình auto_title hoặc caption rỗng
        $is_auto_title = !empty($content_data['auto_title']);
        if (($is_auto_title || empty(trim($caption))) && !empty($resolved_title_override)) {
            $user_cap = trim($caption);
            if (!empty($user_cap) && $is_auto_title) {
                $caption = $resolved_title_override . "\n\n" . $user_cap;
            } else {
                $caption = $resolved_title_override;
            }
        }
        $caption = spin_text($caption);

        // 2. Post to Instagram Graph API (Single vs Carousel)
        if (count($public_media_urls) > 1) {
            $res = post_instagram_carousel($ig_acc['ig_user_id'], $ig_acc['access_token'], $public_media_urls, $caption);
        } elseif ($post['post_type'] === 'Instagram_Reels') {
            $res = post_instagram_reels($ig_acc['ig_user_id'], $ig_acc['access_token'], $public_media_urls[0], $caption);
        } elseif ($post['post_type'] === 'Instagram_Story') {
            $is_vid = (strpos(strtolower($public_media_urls[0]), '.mp4') !== false || strpos(strtolower($public_media_urls[0]), '.mov') !== false || strpos(strtolower($public_media_urls[0]), '.webm') !== false);
            $res = post_instagram_story($ig_acc['ig_user_id'], $ig_acc['access_token'], $public_media_urls[0], $is_vid);
        } else {
            $res = post_instagram_photo($ig_acc['ig_user_id'], $ig_acc['access_token'], $public_media_urls[0], $caption);
        }

        if ($res['status'] === 'success') {
            $pub_id = $res['id'] ?? '';
            $permalink = get_instagram_media_permalink($pub_id, $ig_acc['access_token']);
            $save_fb_post_id = !empty($permalink) ? ($permalink . '#' . $pub_id) : $pub_id;

            $pdo->prepare("UPDATE scheduled_posts SET status = 'published', fb_post_id = ?, error_msg = NULL WHERE id = ?")
                ->execute([$save_fb_post_id, $post['id']]);
            echo " -> Đăng bài Instagram thành công! Link: " . (!empty($permalink) ? $permalink : $pub_id) . "\n";

            // Hẹn giờ Comment tự động nếu có cấu hình
            if ($has_comment_lines && $has_comment_at && !empty($post['comment_lines'])) {
                $post_comment_mode = ($has_comment_mode && !empty($post['comment_mode'])) ? $post['comment_mode'] : 'timer';
                if ($post_comment_mode === 'insights') {
                    if ($has_comment_status) {
                        $pdo->prepare("UPDATE scheduled_posts SET comment_status = 'waiting_insights' WHERE id = ?")
                            ->execute([$post['id']]);
                    }
                    echo "   → Comment mode: insights (chờ cron kiểm tra metrics)\n";
                } else {
                    $comment_at = date('Y-m-d H:i:s', time() + 120);
                    if ($has_comment_status) {
                        $pdo->prepare("UPDATE scheduled_posts SET comment_at = ?, comment_status = 'pending' WHERE id = ?")
                            ->execute([$comment_at, $post['id']]);
                    } else {
                        $pdo->prepare("UPDATE scheduled_posts SET comment_at = ? WHERE id = ?")
                            ->execute([$comment_at, $post['id']]);
                    }
                    echo "   → Đã hẹn giờ bình luận Instagram sau 2 phút.\n";
                }
            }

            // Dọn dẹp tệp phương tiện uploads/ sau khi đăng thành công (giống reels.php & posts.php)
            foreach ($created_upload_files as $uf) {
                if (!empty($uf) && file_exists($uf) && strpos(str_replace('\\', '/', $uf), '/uploads/') !== false) {
                    $bname = basename($uf);
                    try {
                        $usage_chk = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE status IN ('pending', 'processing') AND media_path LIKE ? AND id != ?");
                        $usage_chk->execute(['%' . $bname . '%', $post['id']]);
                        if ($usage_chk->fetchColumn() == 0) {
                            @unlink($uf);
                            echo " -> Đã dọn tệp uploads: $bname\n";
                        }
                    } catch (Exception $e) {}
                }
            }

            if (!empty($content_data['delete_drive_file']) && !empty($resolved_drive_file_ids)) {
                $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
                if ($drive_token) {
                    foreach ($resolved_drive_file_ids as $fid) {
                        delete_drive_file($drive_token, $fid);
                        echo " -> Đã xóa file Google Drive: $fid\n";
                    }
                }
            }
        } else {
            marKAsFailed($pdo, $post['id'], "Lỗi đăng Instagram: " . ($res['msg'] ?? 'Lỗi không xác định'), $sys_max_retries, $sys_retry_interval);
        }

        foreach ($temp_local_files as $tf) {
            if (!empty($tf) && file_exists($tf)) @unlink($tf);
        }

        continue;
    }

    // ── XỬ LÝ RIÊNG DÀNH CHO TIKTOK DIRECT POST ─────────────────────────────
    if ($post['post_type'] === 'TikTok') {
        require_once __DIR__ . '/../includes/tiktok_api.php';
        
        // TokenLocker đăng lần lượt từng kênh TikTok (Delay 15s giữa các lần xuất bản)
        $tt_lock_id = "tiktok_acc_" . $post['page_id'];
        $tt_locker = new TokenLocker($tt_lock_id, 15);

        $tt_stmt = $pdo->prepare("SELECT * FROM tiktok_accounts WHERE id = ? AND account_id = ? AND is_active = 1");
        $tt_stmt->execute([$post['page_id'], $post['account_id']]);
        $tt_acc = $tt_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tt_acc || empty($tt_acc['access_token'])) {
            marKAsFailed($pdo, $post['id'], "Không tìm thấy tài khoản TikTok ủy quyền hợp lệ.", $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $raw_media = $post['media_path'];
        $video_url = '';
        $host = $_SERVER['HTTP_HOST'] ?? 'fbweb.hongdolab.com';
        $base_url = "https://" . $host . "/";

        if (strpos($raw_media, 'drive:') === 0) {
            $drive_id = substr($raw_media, 6);
            $video_url = $base_url . "actions/drive_proxy.php?action=stream&file_id=" . urlencode($drive_id) . "&account_id=" . $post['account_id'] . "&ext=video.mp4";
        } elseif (strpos($raw_media, 'folder:') === 0) {
            $folder_id = substr($raw_media, 7);
            $drive_token = get_drive_access_token($pdo, $post['account_id']);
            if (!$drive_token) {
                marKAsFailed($pdo, $post['id'], "Không lấy được Google Access Token để quét thư mục Drive.", $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $c_data = json_decode($post['content'] ?? '', true);
            $is_anti_dup = !empty($c_data['delete_drive_file']);
            $resolved = resolve_drive_folder_file($pdo, $drive_token, $folder_id, 'video/*', $is_anti_dup);
            if (isset($resolved['error'])) {
                marKAsFailed($pdo, $post['id'], "Lỗi quét thư mục Drive: " . $resolved['error'], $sys_max_retries, $sys_retry_interval);
                continue;
            }
            $drive_id = $resolved['id'];
            $video_url = $base_url . "actions/drive_proxy.php?action=stream&file_id=" . urlencode($drive_id) . "&account_id=" . $post['account_id'] . "&ext=video.mp4";
        } elseif (strpos($raw_media, 'tiktok:') === 0) {
            $tt_url = substr($raw_media, 7);
            $tik_data = fetch_tiktok_info($tt_url);
            if ($tik_data && !empty($tik_data['download_url'])) {
                $video_url = $tik_data['download_url'];
            }
        } elseif (strpos($raw_media, 'uploads/') === 0) {
            $video_url = $base_url . $raw_media;
        } else {
            $video_url = (strpos($raw_media, 'http') === 0) ? $raw_media : ($base_url . $raw_media);
        }

        if (empty($video_url)) {
            marKAsFailed($pdo, $post['id'], "Không thể khởi tạo URL Video TikTok từ media_path: " . $raw_media, $sys_max_retries, $sys_retry_interval);
            continue;
        }

        $title = $post['content'] ?? '';

        $res = post_tiktok_video_direct($tt_acc['access_token'], $video_url, $title, [
            'privacy_level' => 'PUBLIC_TO_EVERYONE'
        ]);

        if ($res['status'] === 'success') {
            $pub_id = $res['publish_id'] ?? '';
            $pdo->prepare("UPDATE scheduled_posts SET status = 'published', fb_post_id = ?, error_msg = NULL WHERE id = ?")
                ->execute([$pub_id, $post['id']]);
            echo " -> Đăng Video TikTok thành công! Publish ID: $pub_id\n";
        } else {
            marKAsFailed($pdo, $post['id'], "Lỗi đăng TikTok: " . $res['msg'], $sys_max_retries, $sys_retry_interval);
        }
        continue;
    }

    // 3. Fetch Page Access Token
    $page_stmt = $pdo->prepare("SELECT access_token, name, user_id FROM pages WHERE page_id = ?");
    $page_stmt->execute([$post['page_id']]);
    $page = $page_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page || empty($page['access_token'])) {
        marKAsFailed($pdo, $post['id'], "Không tìm thấy token của page_id: {$post['page_id']}", $sys_max_retries, $sys_retry_interval);
        continue;
    }

    $page_access_token = decryptData($page['access_token']);
    $fanpage_name = isset($page['name']) ? $page['name'] : '';

    $content_data = json_decode($post['content'], true);
    if (!is_array($content_data)) {
        $content_data = [];
    }

    // Delay đã được xử lý ở đầu vòng lặp (sleep $user_delay_sec giữa mỗi post)
    // Không cần TokenLocker nữa vì 1 Token User = 1 Worker duy nhất

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

    $is_folder = strpos($raw_media, 'folder:') === 0;
    $is_drive = strpos($raw_media, 'drive:') === 0;
    $is_tiktok = strpos($raw_media, 'tiktok:') === 0;
    $resolved_file_id = null;
    $folder_id_to_log = null;
    $has_media = false;
    $abs_media_path = '';
    $file_mime = '';
    $file_name = '';
    $temp_drive_file = null;
    $t_title_override = null;

    // ── Multi-image post: upload each photo as unpublished, then create feed post ──
    if ($multi_image_paths !== null && ($post['post_type'] === 'Image' || $post['post_type'] === 'Status')) {
        $parsed_content = @json_decode($post['content'], true);
        $p_desc = '';
        if (is_array($parsed_content) && isset($parsed_content['description'])) {
            $p_desc = spin_text($parsed_content['description']);
            $use_ai = isset($parsed_content['use_ai']) && $parsed_content['use_ai'];
            if ($use_ai && !empty($p_desc)) {
                $ai_original = $p_desc;
                $p_desc = rewrite_content_with_ai($p_desc, $post['account_id'], false, $fanpage_name);
                if ($p_desc === $ai_original) {
                    echo "   → AI lần 1 thất bại (multi-image), thử lại sau 3s...\n";
                    sleep(3);
                    $p_desc = rewrite_content_with_ai($ai_original, $post['account_id'], false, $fanpage_name);
                }
                if ($p_desc === $ai_original) {
                    marKAsFailed($pdo, $post['id'], "AI không thể viết nội dung (API lỗi). Sẽ thử lại lượt cron tiếp theo.", $sys_max_retries, $sys_retry_interval);
                    continue;
                }
            }
        } else {
            $p_desc = spin_text($post['content']);
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
                $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
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

            // Sanitize file name for CURLFile to prevent Facebook API upload issues due to diacritics/special characters
            $safe_mi_ext = pathinfo($mi_name, PATHINFO_EXTENSION);
            $safe_mi_name = 'media_upload_' . uniqid() . ($safe_mi_ext ? '.' . $safe_mi_ext : '');
            
            // Upload as unpublished photo
            $upload_data = [
                'source' => new CURLFile($mi_abs, $mi_mime, $safe_mi_name),
                'published' => 'false'
            ];
            $upload_res = fb_api_request($post['page_id'] . '/photos', ['access_token' => $page_access_token], 'POST', $upload_data, 60);

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

    if ($is_folder) {
        $folder_id = substr($post['media_path'], 7);
        $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
        if (!$drive_token) {
            marKAsFailed($pdo, $post['id'], "Không thể lấy Google Access Token. Có thể Admin chưa liên kết.", $sys_max_retries, $sys_retry_interval);
            continue;
        }
        
        $mime_filter = null;
        if ($post['post_type'] === 'Video' || $post['post_type'] === 'Reel') {
            $mime_filter = 'video/*';
        } elseif ($post['post_type'] === 'Status' || $post['post_type'] === 'Image') {
            $mime_filter = 'image/*';
        } elseif (strpos($post['post_type'], 'Story') !== false) {
            $mime_filter = ['image/*', 'video/*'];
        }
        
        $c_data = json_decode($post['content'] ?? '', true);
        $is_anti_dup = !empty($c_data['delete_drive_file']);
        $resolved_file_info = resolve_drive_folder_file($pdo, $drive_token, $folder_id, $mime_filter, $is_anti_dup);
        if (isset($resolved_file_info['error'])) {
            marKAsFailed($pdo, $post['id'], "Lỗi quét thư mục Drive: " . $resolved_file_info['error'], $sys_max_retries, $sys_retry_interval);
            continue;
        }
        
        $drive_file_id = $resolved_file_info['id'];
        $resolved_file_id = $drive_file_id;
        $folder_id_to_log = $folder_id;
        
        // UPDATE OTHER POSTS IN THE SAME SLOT TO USE THIS RESOLVED FILE ID
        $new_media_path = 'drive:' . $drive_file_id;
        
        // Update the current post's media_path in memory and database
        $post['media_path'] = $new_media_path;
        $raw_media = $new_media_path;
        $is_folder = false;
        $is_drive = true;
        
        $pdo->prepare("UPDATE scheduled_posts SET media_path = ? WHERE id = ?")
            ->execute([$new_media_path, $post['id']]);
        
        $file_info = download_drive_file_temp($drive_token, $drive_file_id);
        if (isset($file_info['error'])) {
            marKAsFailed($pdo, $post['id'], "Lỗi tải file từ thư mục Google Drive: " . $file_info['error'], $sys_max_retries, $sys_retry_interval);
            continue;
        }
        
        $has_media = true;
        $abs_media_path = $file_info['path'];
        $file_mime = $file_info['mime'];
        $file_name = $file_info['name'];
        $t_title_override = pathinfo($file_name, PATHINFO_FILENAME);
        $temp_drive_file = $abs_media_path;
        
    } elseif ($is_drive) {
        $drive_file_id = substr($post['media_path'], 6);
        $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
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
        $tik_data = fetch_tiktok_info($tiktok_url);

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
        // Sanitize file name for CURLFile to prevent Facebook API upload issues due to diacritics/special characters
        $safe_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $safe_file_name = 'media_upload_' . uniqid() . ($safe_ext ? '.' . $safe_ext : '');
        $post_data['source'] = new CURLFile($abs_media_path, $file_mime, $safe_file_name);
    }

    $post_type = $post['post_type'];

    if ($post_type === 'Status' || $post_type === 'Image') {
        $endpoint = ($post_type === 'Status') ? $post['page_id'] . '/feed' : $post['page_id'] . '/photos';

        $parsed_content = @json_decode($post['content'], true);
        if (is_array($parsed_content) && isset($parsed_content['description'])) {
            $p_desc = spin_text($parsed_content['description']);
            $use_ai = isset($parsed_content['use_ai']) && $parsed_content['use_ai'];

            if ($use_ai && !empty($p_desc)) {
                $ai_original = $p_desc;
                $p_desc = rewrite_content_with_ai($p_desc, $post['account_id'], false, $fanpage_name);
                if ($p_desc === $ai_original) {
                    echo "   → AI lần 1 thất bại (Status/Image), thử lại sau 3s...\n";
                    sleep(3);
                    $p_desc = rewrite_content_with_ai($ai_original, $post['account_id'], false, $fanpage_name);
                }
                if ($p_desc === $ai_original) {
                    marKAsFailed($pdo, $post['id'], "AI không thể viết nội dung (API lỗi). Sẽ thử lại lượt cron tiếp theo.", $sys_max_retries, $sys_retry_interval);
                    if ($temp_drive_file && file_exists($temp_drive_file)) @unlink($temp_drive_file);
                    continue;
                }
            }
            $post_data['message'] = $p_desc;
            $post['content'] = $p_desc;
        } else {
            $post_data['message'] = spin_text($post['content']);
        }
    } elseif ($post_type === 'Video' || $post_type === 'Reel') {
        $endpoint = $post['page_id'] . '/videos';
        // Content might be JSON encoded with title and description for Videos
        if ($post['content']) {
            $content_data = json_decode($post['content'], true);
            if (is_array($content_data)) {
                $p_desc = isset($content_data['description']) ? spin_text($content_data['description']) : '';
                $p_title = isset($content_data['title']) ? spin_text($content_data['title']) : '';
                $is_auto = isset($content_data['auto_title']) && $content_data['auto_title'];
                $use_ai = isset($content_data['use_ai']) && $content_data['use_ai'];

                if ($is_auto) {
                    if (!empty($t_title_override)) {
                        if (empty($p_desc)) {
                            $p_desc = $t_title_override;
                        } elseif (strpos($p_desc, $t_title_override) !== 0) {
                            $p_desc = $t_title_override . "\n\n" . $p_desc;
                        }
                    }
                    // Không tự động gán text dài vào $p_title để tránh bị Facebook Graph API cắt bớt hiển thị "..."
                    if (empty($p_title) && !empty($t_title_override))
                        $p_title = '';
                }

                if ($use_ai) {
                    // Xây dựng {prompt} cho AI dựa trên auto_title
                    if ($is_auto && !empty($t_title_override)) {
                        // Checkbox auto_title ON: {prompt} = Tên file/Title TikTok + mô tả user (nếu có)
                        $ai_input = !empty($p_desc) ? ($t_title_override . "\n\n" . $p_desc) : $t_title_override;
                    } else {
                        // Checkbox auto_title OFF: {prompt} = chỉ nội dung user nhập
                        $ai_input = $p_desc;
                    }
                    if (!empty($ai_input)) {
                        $ai_original = $ai_input;
                        $p_desc = rewrite_content_with_ai($ai_input, $post['account_id'], false, $fanpage_name);
                        // Nếu AI trả về nguyên bản (nghĩa là lỗi API), thử lại 1 lần sau 3 giây
                        if ($p_desc === $ai_original) {
                            echo "   → AI lần 1 thất bại, thử lại sau 3s...\n";
                            sleep(3);
                            $p_desc = rewrite_content_with_ai($ai_input, $post['account_id'], false, $fanpage_name);
                        }
                        // Nếu vẫn thất bại → đánh dấu failed để tránh đăng nội dung trùng lặp (filename)
                        if ($p_desc === $ai_original) {
                            echo "   → AI vẫn thất bại sau 2 lần thử. Bỏ qua bài này.\n";
                            marKAsFailed($pdo, $post['id'], "AI không thể viết nội dung (API lỗi/quá tải). Sẽ thử lại lượt cron tiếp theo.", $sys_max_retries, $sys_retry_interval);
                            if ($temp_drive_file && file_exists($temp_drive_file)) @unlink($temp_drive_file);
                            continue;
                        }
                    }
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
                $p_desc = !empty($post['content']) ? spin_text($post['content']) : $t_title_override;
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
    set_time_limit(600); // Allow long upload for videos
    if (strpos($post_type, 'Story') === false) {
        $timeout = ($post_type === 'Video' || $post_type === 'Reel') ? 600 : 30;
        $response = fb_api_request($endpoint, $params, 'POST', $post_data, $timeout);
    }

    handle_response:
    $post_id = $response['data']['id'] ?? $response['data']['post_id'] ?? null;

    if ($response['status_code'] === 200 && $post_id) {
        // Mark as published + save fb_post_id (if column exists)
        if ($has_fb_post_id) {
            $pdo->prepare("UPDATE scheduled_posts SET status = 'published', fb_post_id = ?, error_msg = NULL WHERE id = ?")
                ->execute([$post_id, $post['id']]);
        } else {
            $pdo->prepare("UPDATE scheduled_posts SET status = 'published', error_msg = NULL WHERE id = ?")
                ->execute([$post['id']]);
        }

        // Ghi nhận file đã đăng từ thư mục để chống trùng
        if (!empty($folder_id_to_log) && !empty($resolved_file_id)) {
            try {
                $stmt = $pdo->prepare("INSERT IGNORE INTO posted_folder_files (folder_id, file_id) VALUES (?, ?)");
                $stmt->execute([$folder_id_to_log, $resolved_file_id]);
            } catch (Exception $e) {}
        }

        if (!empty($content_data['delete_drive_file'])) {
            $check_usages = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE status IN ('pending', 'processing', 'failed') AND media_path = ? AND id != ?");
            $check_usages->execute([$post['media_path'], $post['id']]);
            $remaining_usages = $check_usages->fetchColumn();

            if ($remaining_usages == 0) {
                $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
                if ($drive_token) {
                    $raw_media = $post['media_path'];
                    if (strpos($raw_media, 'drive:') === 0) {
                        $drive_file_id = substr($raw_media, 6);
                        $del_res = delete_drive_file($drive_token, $drive_file_id);
                        if ($del_res) {
                            echo "   → [SAFE DELETE] Đã xóa file trên Google Drive thành công: $drive_file_id\n";
                        } else {
                            echo "   → [LỖI] Không thể xóa file trên Google Drive: $drive_file_id (Có thể do thiếu quyền/scope hoặc Token hết hạn)\n";
                        }
                    } elseif (strpos($raw_media, '[') === 0) {
                        $media_paths = @json_decode($raw_media, true);
                        if (is_array($media_paths)) {
                            foreach ($media_paths as $path) {
                                if (strpos($path, 'drive:') === 0) {
                                    $drive_file_id = substr($path, 6);
                                    
                                    // Check if this specific drive file is still needed by other posts
                                    $check_sub = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE status IN ('pending', 'processing', 'failed') AND media_path LIKE ? AND id != ?");
                                    $check_sub->execute(['%' . $drive_file_id . '%', $post['id']]);
                                    if ($check_sub->fetchColumn() == 0) {
                                        $del_res = delete_drive_file($drive_token, $drive_file_id);
                                        if ($del_res) {
                                            echo "   → [SAFE DELETE] Đã xóa file trên Google Drive thành công: $drive_file_id\n";
                                        } else {
                                            echo "   → [LỖI] Không thể xóa file trên Google Drive: $drive_file_id (Có thể do thiếu quyền/scope hoặc Token hết hạn)\n";
                                        }
                                    } else {
                                        echo "   → File Drive $drive_file_id vẫn còn trang khác cần dùng, chưa xóa.\n";
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                echo "   → File Drive {$post['media_path']} vẫn còn {$remaining_usages} trang khác đang chờ hoặc lỗi cần dùng, chưa xóa.\n";
            }
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

        // Gửi thông báo Telegram (Đã tắt theo yêu cầu để tránh spam)
        $tg_page_name = $fanpage_name ?: $post['page_id'];
        // send_telegram_notification($pdo, $post['account_id'], "<b>Đăng bài thành công!</b>\n📄 Page: {$tg_page_name}\n📝 Loại: {$post['post_type']}\n🆔 Post ID: {$post_id}", 'publish');
    } else {
        $status_code = isset($response['status_code']) ? $response['status_code'] : 'Unknown';
        if (isset($response['data']['error']['message'])) {
            $error_msg = $response['data']['error']['message'];
        } else {
            $error_msg = json_encode($response['data'] ?? $response);
        }
        
        if ((int)$status_code === 413 || stripos($error_msg, '413') !== false) {
            $friendly_err = "Lỗi tải video từ Google Drive";
            marKAsFailed($pdo, $post['id'], $friendly_err, $sys_max_retries, $sys_retry_interval, $has_error_msg, $has_retry_count);
        } else {
            $full_msg = "HTTP $status_code - $error_msg";
            marKAsFailed($pdo, $post['id'], "Lỗi API: $full_msg", $sys_max_retries, $sys_retry_interval, $has_error_msg, $has_retry_count);
        }
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
    // Cắt và chuẩn hóa thông báo lỗi Quota YouTube API 429
    if (stripos($msg, 'Quota exceeded') !== false || stripos($msg, 'rateLimitExceeded') !== false || stripos($msg, 'RESOURCE_EXHAUSTED') !== false || stripos($msg, 'defaultVideoInsertPerDayPerProject') !== false) {
        $msg = "Lỗi YouTube API: Đã đạt giới hạn Quota/ngày";
    }

    // Cắt lỗi Checkpoint ngay từ đầu
    $is_checkpoint = (stripos($msg, 'You cannot access the app till you log in') !== false) || 
                     (stripos($msg, 'Error validating access token') !== false) ||
                     (stripos($msg, 'changed their password') !== false) ||
                     (stripos($msg, 'session has been invalidated') !== false) ||
                     (stripos($msg, 'not a confirmed user') !== false) ||
                     (stripos($msg, 'subcode":464') !== false) ||
                     (stripos($msg, 'error_subcode":464') !== false);

    if ($is_checkpoint) {
        $checkpoint_msg = "TK Bị Checkpoint hoặc cần kết nối lại";
        file_put_contents(__DIR__ . '/worker_error.log', date('Y-m-d H:i:s') . " - [CHECKPOINT] Intercepted message: " . $msg . "\n", FILE_APPEND);
        try {
            $p_stmt = $pdo->prepare("SELECT campaign_id FROM scheduled_posts WHERE id = ?");
            $p_stmt->execute([$id]);
            $p = $p_stmt->fetch(PDO::FETCH_ASSOC);
            if ($p && !empty($p['campaign_id'])) {
                $cid = $p['campaign_id'];
                // Dừng tất cả pending, processing, failed sang checkpoint trong cùng Campaign
                $pdo->prepare("UPDATE scheduled_posts SET status='checkpoint', error_msg=? WHERE campaign_id=? AND status IN ('pending', 'processing', 'failed')")
                    ->execute([$checkpoint_msg, $cid]);
                // Cập nhật riêng cho ID hiện tại
                $pdo->prepare("UPDATE scheduled_posts SET status='checkpoint', error_msg=? WHERE id=?")
                    ->execute([$checkpoint_msg, $id]);
                
                // Cảnh báo Telegram
                if (function_exists('send_telegram_notification')) {
                    send_telegram_notification($pdo, $GLOBALS['_current_account_id'] ?? 0, "<b>🚨 CẢNH BÁO CHECKPOINT!</b>\n🎯 Campaign ID: {$cid}\n💬 Lỗi: {$checkpoint_msg}\n⚠️ Đã tự động <b>DỪNG</b> Campaign để bảo vệ tài khoản.", 'error');
                }
                echo " -> [CHECKPOINT] Đã dừng toàn bộ Campaign #$cid do Checkpoint API!\n";
                return;
            } else {
                // Không có campaign thì set thẳng node này
                $pdo->prepare("UPDATE scheduled_posts SET status='checkpoint', error_msg=? WHERE id=?")
                    ->execute([$checkpoint_msg, $id]);
                if (function_exists('send_telegram_notification')) {
                    send_telegram_notification($pdo, $GLOBALS['_current_account_id'] ?? 0, "<b>🚨 CẢNH BÁO CHECKPOINT!</b>\n🆔 Bài ID: {$id}\n💬 Lỗi: {$checkpoint_msg}", 'error');
                }
                echo " -> [CHECKPOINT] Lỗi Checkpoint API cho bài ID #$id!\n";
                return;
            }
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/worker_error.log', date('Y-m-d H:i:s') . " - EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

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
            
            // --- TÍNH NĂNG TỰ ĐỘNG ĐỔI NỘI DUNG KHI LỖI TẢI TỆP ---
            try {
                $p_stmt = $pdo->prepare("SELECT campaign_id, media_path FROM scheduled_posts WHERE id = ?");
                $p_stmt->execute([$id]);
                $p = $p_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($p && !empty($p['campaign_id'])) {
                    if (stripos($msg, 'Unable to fetch video') !== false || stripos($msg, 'API tải video TikTok') !== false || stripos($msg, 'tải trực tiếp file') !== false || stripos($msg, 'Google Drive') !== false || stripos($msg, 'file_url') !== false) {
                        // Tìm 1 media ngẫu nhiên khác trong cùng chiến dịch
                        $swap_stmt = $pdo->prepare("SELECT media_path FROM scheduled_posts WHERE campaign_id = ? AND media_path != ? ORDER BY RAND() LIMIT 1");
                        $swap_stmt->execute([$p['campaign_id'], $p['media_path']]);
                        $new_media = $swap_stmt->fetchColumn();
                        if ($new_media) {
                            $pdo->prepare("UPDATE scheduled_posts SET media_path = ? WHERE id = ?")->execute([$new_media, $id]);
                            echo "   → [AUTO-SWAP] Đã tự động lấy một URL/Tệp khác trong cùng Campaign để thay thế!\n";
                        }
                    }
                }
            } catch (Exception $e) {}
            // -----------------------------------------------------
            
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

    // Gửi Telegram khi hết số lần thử
    if ($new_retry > $max_retries) {
        $short_msg = mb_strimwidth($msg, 0, 150, '…');
        send_telegram_notification($pdo, $GLOBALS['_current_account_id'] ?? 0, "<b>Đăng bài thất bại!</b>\n🆔 Bài ID: {$id}\n💬 Lỗi: {$short_msg}\n🔄 Đã thử: {$new_retry}/{$max_retries} lần", 'error');
    }

    if (isset($yt_locker)) unset($yt_locker);
}
?>