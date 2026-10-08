<?php
// includes/drive_utils.php

/**
 * Lấy Access Token từ Refresh Token
 */
/**
 * Lấy Access Token từ Refresh Token (Ưu tiên theo kênh/page_id -> System Account Settings -> Tài khoản user bất kỳ)
 */
function get_drive_access_token($pdo, $account_id, $page_id = null) {
    $exchange_token = function($client_id, $client_secret, $refresh_token) {
        if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
            return null;
        }

        static $memory_cache = [];
        $cache_key = md5($client_id . '_' . $refresh_token);

        // 1. Kiểm tra cache trong bộ nhớ (Google OAuth2 Client Pattern)
        if (isset($memory_cache[$cache_key])) {
            $cached = $memory_cache[$cache_key];
            if (!empty($cached['access_token']) && ($cached['expires_at'] - 120) > time()) {
                return $cached['access_token'];
            }
        }

        // 2. Kiểm tra cache trên đĩa cứng đệm (tránh gọi cURL nhiều lần liên tục)
        $cache_file = sys_get_temp_dir() . '/gg_access_token_' . $cache_key . '.json';
        if (file_exists($cache_file)) {
            $json = @file_get_contents($cache_file);
            $data = json_decode($json, true);
            if ($data && !empty($data['access_token']) && ($data['expires_at'] - 120) > time()) {
                $memory_cache[$cache_key] = $data;
                return $data['access_token'];
            }
        }

        // 3. Đổi token mới từ endpoint Google OAuth2 theo chuẩn Google API Client
        $token_url = 'https://oauth2.googleapis.com/token';
        $post_fields = [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token'
        ];

        $ch = curl_init($token_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post_fields),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15
        ]);
        $token_response_raw = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $token_data = json_decode($token_response_raw, true);

        if ($http_code === 200 && !empty($token_data['access_token'])) {
            $expires_in = (int)($token_data['expires_in'] ?? 3600);
            $token_info = [
                'access_token' => $token_data['access_token'],
                'expires_at'   => time() + $expires_in
            ];
            $memory_cache[$cache_key] = $token_info;
            @file_put_contents($cache_file, json_encode($token_info));
            return $token_data['access_token'];
        }

        // Ghi log chi tiết nếu Google từ chối cấp token (invalid_grant, client_id sai,...)
        if (isset($token_data['error'])) {
            $err_desc = $token_data['error_description'] ?? $token_data['error'];
            @file_put_contents(
                __DIR__ . '/../webhook_db_errors.txt',
                date('Y-m-d H:i:s') . " GOOGLE_OAUTH2_REFRESH_ERROR (HTTP {$http_code}): {$err_desc}\n",
                FILE_APPEND
            );
        }

        return null;
    };

    // System default client_id / secret
    $sys_client_id = null;
    $sys_client_secret = null;
    $stmt_sys_cfg = $pdo->prepare("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = ?");
    $stmt_sys_cfg->execute([$account_id]);
    $sys_cfg = $stmt_sys_cfg->fetch(PDO::FETCH_ASSOC);
    if ($sys_cfg) {
        $sys_client_id = $sys_cfg['gg_client_id'] ?? null;
        $sys_client_secret = $sys_cfg['gg_client_secret'] ?? null;
    }

    if (empty($sys_client_id) || empty($sys_client_secret)) {
        try {
            $st_sys = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('google_client_id', 'google_client_secret')");
            while ($r = $st_sys->fetch(PDO::FETCH_ASSOC)) {
                if ($r['setting_key'] === 'google_client_id' && empty($sys_client_id)) $sys_client_id = $r['setting_value'];
                if ($r['setting_key'] === 'google_client_secret' && empty($sys_client_secret)) $sys_client_secret = $r['setting_value'];
            }
        } catch (Exception $e) {}
    }

    // Tier 1: Try specific page_id token if provided
    if ($page_id !== null) {
        $stmt_user = $pdo->prepare("
            SELECT u.gg_client_id, u.gg_client_secret, u.gg_refresh_token 
            FROM pages p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.page_id = ? AND u.account_id = ?
        ");
        $stmt_user->execute([$page_id, $account_id]);
        $user_drive = $stmt_user->fetch(PDO::FETCH_ASSOC);

        if (!$user_drive) {
            $stmt_ig = $pdo->prepare("
                SELECT u.gg_client_id, u.gg_client_secret, u.gg_refresh_token
                FROM instagram_accounts ig
                JOIN users u ON ig.account_id = u.account_id
                WHERE (ig.ig_user_id = ? OR ig.id = ?) AND u.account_id = ? AND u.gg_refresh_token IS NOT NULL AND u.gg_refresh_token != ''
                LIMIT 1
            ");
            $stmt_ig->execute([$page_id, $page_id, $account_id]);
            $user_drive = $stmt_ig->fetch(PDO::FETCH_ASSOC);
        }

        if ($user_drive && !empty($user_drive['gg_refresh_token'])) {
            $cid = !empty($user_drive['gg_client_id']) ? $user_drive['gg_client_id'] : $sys_client_id;
            $csec = !empty($user_drive['gg_client_secret']) ? $user_drive['gg_client_secret'] : $sys_client_secret;
            $token = $exchange_token($cid, $csec, $user_drive['gg_refresh_token']);
            if ($token) return $token;
        }
    }

    // Tier 2: Try main system account token (connected in Settings)
    $stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret, gg_refresh_token FROM system_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($account && !empty($account['gg_refresh_token'])) {
        $cid = !empty($account['gg_client_id']) ? $account['gg_client_id'] : $sys_client_id;
        $csec = !empty($account['gg_client_secret']) ? $account['gg_client_secret'] : $sys_client_secret;
        $token = $exchange_token($cid, $csec, $account['gg_refresh_token']);
        if ($token) return $token;
    }

    // Tier 3: Try any user token under account_id
    $stmt_u = $pdo->prepare("SELECT gg_client_id, gg_client_secret, gg_refresh_token FROM users WHERE account_id = ? AND gg_refresh_token IS NOT NULL AND gg_refresh_token != '' ORDER BY id ASC");
    $stmt_u->execute([$account_id]);
    while ($u_drive = $stmt_u->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($u_drive['gg_refresh_token'])) {
            $cid = !empty($u_drive['gg_client_id']) ? $u_drive['gg_client_id'] : $sys_client_id;
            $csec = !empty($u_drive['gg_client_secret']) ? $u_drive['gg_client_secret'] : $sys_client_secret;
            $token = $exchange_token($cid, $csec, $u_drive['gg_refresh_token']);
            if ($token) return $token;
        }
    }

    return false;
}

/**
 * Lấy Tên File từ Google Drive siêu tốc (Chỉ Metadata)
 */
function get_drive_file_name($access_token, $file_id) {
    $meta_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?fields=name";
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "Authorization: Bearer $access_token\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $meta_response = @file_get_contents($meta_url, false, $context);
    
    if (!$meta_response) return false;
    
    $meta = json_decode($meta_response, true);
    if (!isset($meta['name'])) return false;
    
    return $meta['name'];
}

/**
 * Tải file từ Google Drive về thư mục tạm (Cache 2h, Tải trực tiếp Google CDN không qua Proxy)
 */
function download_drive_file_temp($access_token, $file_id) {
    $temp_dir = __DIR__ . '/../uploads/tmp';
    if (!is_dir($temp_dir)) {
        @mkdir($temp_dir, 0777, true);
    }

    // ⚡ 1. KIỂM TRA FILE CACHE TRÊN VPS (Nếu đã tải file này trong 2h -> Tái sử dụng ngay lập tức)
    $cached_files = glob($temp_dir . '/gdrive_' . md5($file_id) . '.*');
    if (!empty($cached_files)) {
        foreach ($cached_files as $cf) {
            if (substr($cf, -12) === '.downloading' || substr($cf, -4) === '.tmp') continue;
            if (file_exists($cf) && filesize($cf) > 0 && (time() - filemtime($cf)) < 7200) {
                $c_mb = round(filesize($cf) / 1024 / 1024, 2);
                $c_name = basename($cf);
                echo "   ⚡ Sử dụng file Google Drive đã cache trên VPS ({$c_mb} MB) - Tốc độ 0.001s!\n";
                if (ob_get_level() > 0) @ob_flush();
                @flush();
                return [
                    'path' => $cf,
                    'name' => $c_name,
                    'mime_type' => 'video/mp4'
                ];
            }
        }
    }

    // 2. Lấy thông tin metadata của file (name, mimeType, size) - Kết nối trực tiếp Google CDN
    $meta_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?fields=name,mimeType,size";
    $ch = curl_init($meta_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token", "Expect:"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $meta_response = curl_exec($ch);
    curl_close($ch);

    $meta = json_decode($meta_response, true);
    if (!isset($meta['name'])) {
        return ['error' => 'Không thể lấy thông tin file từ Google Drive. (Vui lòng kiểm tra quyền truy cập hoặc Refresh Token)'];
    }

    if (isset($meta['size']) && (int)$meta['size'] === 0) {
        return ['error' => 'Tệp trên Google Drive là tệp rỗng (0 byte). Vui lòng tải lại file video/ảnh hợp lệ lên Google Drive.'];
    }

    $mime_type = $meta['mimeType'];
    $file_name = $meta['name'];

    echo "   → Đang tải tệp '{$file_name}' trực tiếp từ Google CDN...\n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();

    // Lấy extension từ tên file gốc (nếu có)
    $ext = pathinfo($file_name, PATHINFO_EXTENSION);
    if (!$ext || strtolower($ext) === 'tmp') {
        $map = [
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
            'video/webm' => 'webm',
            'video/3gpp' => '3gp',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        $ext = $map[$mime_type] ?? 'mp4';
        if ($ext !== 'tmp' && strpos($file_name, '.') === false) {
            $file_name = rtrim($file_name, '.') . '.' . $ext;
        }
    }

    // 3. Download nội dung file trực tiếp từ Google CDN (vào file tạm .downloading trước)
    $download_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?alt=media&supportsAllDrives=true&acknowledgeAbuse=true";
    $temp_path_final = $temp_dir . '/gdrive_' . md5($file_id) . '.' . $ext;
    $temp_path_downloading = $temp_path_final . '.downloading';

    $fp = fopen($temp_path_downloading, 'w+');
    if ($fp === false) {
        return ['error' => 'Không thể tạo file tạm trên Server.'];
    }

    // Tách riêng bước lấy URL CDN (302 Redirect) để không bị gửi Header Authorization sang googleusercontent.com
    $ch_loc = curl_init($download_url);
    curl_setopt($ch_loc, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_loc, CURLOPT_HEADER, true);
    curl_setopt($ch_loc, CURLOPT_NOBODY, true);
    curl_setopt($ch_loc, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token", "Expect:"]);
    curl_setopt($ch_loc, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch_loc, CURLOPT_SSL_VERIFYPEER, false);
    $loc_res = curl_exec($ch_loc);
    $loc_code = curl_getinfo($ch_loc, CURLINFO_HTTP_CODE);
    curl_close($ch_loc);

    $cdn_url = '';
    if (($loc_code === 302 || $loc_code === 303 || $loc_code === 307) && preg_match('/^Location:\s*(.+)$/mi', (string)$loc_res, $m)) {
        $cdn_url = trim(end($m));
    }

    $ch2 = curl_init(!empty($cdn_url) ? $cdn_url : $download_url);
    if (empty($cdn_url)) {
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token", "Expect:"]);
    }
    curl_setopt($ch2, CURLOPT_FILE, $fp);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 1800);
    curl_setopt($ch2, CURLOPT_LOW_SPEED_LIMIT, 1024);
    curl_setopt($ch2, CURLOPT_LOW_SPEED_TIME, 60);
    curl_setopt($ch2, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_BUFFERSIZE, 1048576);

    $success = curl_exec($ch2);
    $http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch2);
    curl_close($ch2);
    if (is_resource($fp)) {
        @fclose($fp);
    }
    clearstatcache(true, $temp_path_downloading);

    $file_exists = file_exists($temp_path_downloading);
    $file_size = $file_exists ? filesize($temp_path_downloading) : 0;

    if (!$success || $http_code !== 200 || !$file_exists || $file_size === 0) {
        if ($file_exists && $file_size === 0 && $http_code === 200) {
            $err_msg = 'Tệp tải về từ Google Drive có dung lượng 0 byte (HTTP 200). Vui lòng kiểm tra quyền hoặc file trên Drive.';
        } else {
            $err_msg = 'Lỗi tải file từ Google Drive (HTTP ' . $http_code . ', CURL: ' . ($curl_err ?: 'Bị ngắt kết nối hoặc dung lượng 0 byte') . ').';
        }
        @unlink($temp_path_downloading);
        return ['error' => $err_msg];
    }

    @rename($temp_path_downloading, $temp_path_final);
    @chmod($temp_path_final, 0644);

    $d_mb = round(filesize($temp_path_final) / 1024 / 1024, 2);
    echo "   → Tải xong file '{$file_name}' ({$d_mb} MB) từ Google Drive.\n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();

    return [
        'path' => $temp_path_final,
        'name' => $file_name,
        'mime_type' => $mime_type
    ];
}

/**
 * Xóa file trên Google Drive (xóa vĩnh viễn)
 * Bước 1: Thử DELETE trực tiếp (permanent delete) với supportsAllDrives
 * Bước 2: Nếu thất bại, thử chuyển vào Trash rồi xóa vĩnh viễn từ Trash
 */
function delete_drive_file($access_token, $file_id) {
    $headers = ["Authorization: Bearer $access_token", "Content-Type: application/json"];
    
    // === Bước 1: Thử DELETE trực tiếp (xóa vĩnh viễn, bỏ qua Trash) ===
    $delete_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?supportsAllDrives=true";
    $ch = curl_init($delete_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // HTTP 204 = xóa vĩnh viễn thành công
    if ($http_code === 204) {
        return true;
    }
    
    // === Bước 2: Nếu DELETE thất bại (403/404/500...), thử Trash rồi xóa từ Trash ===
    // Bước 2a: Chuyển file vào Trash bằng PATCH trashed=true
    $trash_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?supportsAllDrives=true";
    $ch2 = curl_init($trash_url);
    curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(['trashed' => true]));
    curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
    $trash_response = curl_exec($ch2);
    $trash_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    
    if ($trash_code !== 200) {
        // Không thể trash file → thất bại hoàn toàn
        return false;
    }
    
    // Bước 2b: Xóa vĩnh viễn file đã nằm trong Trash
    $ch3 = curl_init($delete_url);
    curl_setopt($ch3, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch3, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch3, CURLOPT_TIMEOUT, 30);
    $del2_response = curl_exec($ch3);
    $del2_code = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    curl_close($ch3);
    
    // HTTP 204 = xóa vĩnh viễn thành công, hoặc 200 = chấp nhận
    return ($del2_code === 204 || $del2_code === 200);
}

/**
 * Lấy danh sách file trong 1 thư mục từ Google Drive
 * Sắp xếp theo thời gian tạo tăng dần (cũ nhất trước)
 */
function list_drive_files_in_folder($access_token, $folder_id) {
    echo "   → Đang kết nối Google Drive quét thư mục: {$folder_id}...\n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();

    $files = [];
    $pageToken = null;
    $q = "'" . str_replace("'", "\\'", $folder_id) . "' in parents and trashed = false";
    
    do {
        $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q) . "&fields=nextPageToken,files(id,name,mimeType,createdTime,size)&orderBy=createdTime&pageSize=1000";
        if ($pageToken) {
            $url .= "&pageToken=" . urlencode($pageToken);
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if (function_exists('apply_proxy_to_curl')) {
            apply_proxy_to_curl($ch, $access_token);
        }
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return empty($files) ? false : $files;
        }
        
        $data = json_decode($response, true);
        if (isset($data['files'])) {
            $files = array_merge($files, $data['files']);
        }
        $pageToken = isset($data['nextPageToken']) ? $data['nextPageToken'] : null;
    } while ($pageToken);
    
    return $files;
}

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

        // Xoay vòng qua tất cả các file chưa đăng trong thư mục Drive để tránh lặp file
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

        // Đã đăng hết toàn bộ file trong thư mục -> Tái sử dụng (nếu không bật xóa file) hoặc báo hết bài (nếu có bật xóa file)
        if (empty($unposted_candidates) && !empty($posted_files)) {
            if (!$only_anti_duplicate) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM posted_folder_files WHERE folder_id = ?");
                    $stmt->execute([$folder_id]);
                } catch (Exception $e) {}
                $unposted_candidates = $candidates;
            } else {
                return ['error' => 'Tất cả các tệp trong thư mục đã được đăng.'];
            }
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

if (!function_exists('resolve_drive_folder_files_multi')) {
    function resolve_drive_folder_files_multi($pdo, $access_token, $folder_id, $mime_filter = null, $only_anti_duplicate = false, $count = 1) {
        $files = list_drive_files_in_folder($access_token, $folder_id);
        if ($files === false) {
            return ['error' => 'Không thể kết nối API Google Drive hoặc thư mục không tồn tại.'];
        }

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

        if (!$only_anti_duplicate) {
            shuffle($candidates);
            return array_slice($candidates, 0, min($count, count($candidates)));
        }

        // With anti-duplicate
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
        $result = [];
        foreach ($unposted_candidates as $file) {
            if (count($result) >= $count) break;
            $file_id = $file['id'];
            try {
                $stmt = $pdo->prepare("INSERT INTO posted_folder_files (folder_id, file_id) VALUES (?, ?)");
                $stmt->execute([$folder_id, $file_id]);
                $result[] = $file;
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') {
                    continue;
                } else {
                    $result[] = $file;
                }
            }
        }

        if (empty($result)) {
            return ['error' => 'Tất cả các tệp trong thư mục đã được đăng hoặc đang được đăng bởi kênh khác.'];
        }

        return $result;
    }
}

function make_drive_file_public($access_token, $file_id) {
    if (empty($file_id) || empty($access_token)) return;
    $url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "/permissions";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['role' => 'reader', 'type' => 'anyone']),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

if (!function_exists('get_or_create_folder_id')) {
    function get_or_create_folder_id($access_token, $folder_name) {
        $q = "mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace("'", "\\'", $folder_name) . "' and trashed = false";
        $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q) . "&fields=files(id)";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token"
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (!empty($data['files'])) {
            return $data['files'][0]['id'];
        }
        
        $create_url = "https://www.googleapis.com/drive/v3/files";
        $post_data = [
            'name' => $folder_name,
            'mimeType' => 'application/vnd.google-apps.folder'
        ];
        
        $ch = curl_init($create_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        
        $data = json_decode($response, true);
        return $data['id'] ?? false;
    }
}

if (!function_exists('detect_mime_type')) {
    function detect_mime_type($tmp_name, $original_name, $client_mime_type = 'application/octet-stream') {
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'avi'  => 'video/x-msvideo',
            'mkv'  => 'video/x-matroska',
            'webm' => 'video/webm'
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if (!empty($client_mime_type) && $client_mime_type !== 'application/octet-stream') {
            return $client_mime_type;
        }
        if (extension_loaded('fileinfo') && function_exists('mime_content_type')) {
            $mime = @mime_content_type($tmp_name);
            if ($mime) return $mime;
        }
        return 'application/octet-stream';
    }
}

if (!function_exists('upload_file_to_drive_resumable')) {
    function upload_file_to_drive_resumable($access_token, $file_path, $file_name, $mime_type, $parent_folder_id) {
        $file_size = filesize($file_path);
        
        $init_url = "https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable";
        $metadata = [
            'name' => $file_name,
            'parents' => [$parent_folder_id]
        ];
        $metadata_json = json_encode($metadata);
        
        $ch = curl_init($init_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata_json);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json; charset=UTF-8",
            "X-Upload-Content-Type: $mime_type",
            "X-Upload-Content-Length: $file_size"
        ]);
        
        $response = curl_exec($ch);
        if ($response === false) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            return ['error' => true, 'msg' => 'cURL Error (Initiation): ' . $error_msg];
        }
        
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        curl_close($ch);
        
        $location = null;
        foreach (explode("\r\n", $headers) as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
                break;
            }
        }
        
        if (!$location) {
            return ['error' => true, 'msg' => 'Could not retrieve upload Location header from Drive API.'];
        }
        
        $ch = curl_init($location);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file_path));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Content-Type: $mime_type",
            "Content-Length: $file_size"
        ]);
        
        $upload_response = curl_exec($ch);
        $upload_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($upload_response, true);
        if (($upload_http_code === 200 || $upload_http_code === 201) && isset($data['id'])) {
            return $data;
        }
        
        return [
            'error' => true,
            'msg' => isset($data['error']['message']) ? $data['error']['message'] : 'Upload failed (HTTP ' . $upload_http_code . ')'
        ];
    }
}

if (!function_exists('upload_local_video_to_drive')) {
    function upload_local_video_to_drive($pdo, $account_id, $local_file_path, $original_name = null) {
        if (!file_exists($local_file_path) || filesize($local_file_path) < 10) {
            return false;
        }

        $drive_token = get_drive_access_token($pdo, $account_id, null);
        if (!$drive_token) return false;

        $folder_id = get_or_create_folder_id($drive_token, 'HONGDOLABS');
        if (!$folder_id) return false;

        $file_name = $original_name ? $original_name : basename($local_file_path);
        $mime = detect_mime_type($local_file_path, $file_name, 'video/mp4');

        $res = upload_file_to_drive_resumable($drive_token, $local_file_path, $file_name, $mime, $folder_id);

        if (isset($res['id'])) {
            make_drive_file_public($drive_token, $res['id']);
            return $res['id'];
        }
        return false;
    }
}

if (!function_exists('upload_local_file_to_drive_public')) {
    function upload_local_file_to_drive_public($pdo, $account_id, $local_file_path) {
        if (!file_exists($local_file_path) || filesize($local_file_path) < 10) {
            return false;
        }

        $drive_token = get_drive_access_token($pdo, $account_id, null);
        if (!$drive_token) return false;

        $folder_id = get_or_create_folder_id($drive_token, 'HONGDOLABS');
        if (!$folder_id) return false;

        $file_name = basename($local_file_path);
        $mime = detect_mime_type($local_file_path, $file_name, 'image/jpeg');

        $res = upload_file_to_drive_resumable($drive_token, $local_file_path, $file_name, $mime, $folder_id);

        if (isset($res['id'])) {
            make_drive_file_public($drive_token, $res['id']);
            $is_img = strpos($mime, 'image') !== false;
            if ($is_img) {
                return "https://lh3.googleusercontent.com/d/" . $res['id'] . "=w2000";
            }
            return $res['id'];
        }
        return false;
    }
}
?>
