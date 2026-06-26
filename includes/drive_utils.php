<?php
// includes/drive_utils.php

/**
 * Lấy Access Token từ Refresh Token
 */
function get_drive_access_token($pdo, $account_id, $page_id = null) {
    $client_id = null;
    $client_secret = null;
    $refresh_token = null;

    if ($page_id !== null) {
        // Find user_id and check if they have gg_refresh_token
        $stmt_user = $pdo->prepare("
            SELECT u.gg_client_id, u.gg_client_secret, u.gg_refresh_token 
            FROM pages p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.page_id = ? AND u.account_id = ?
        ");
        $stmt_user->execute([$page_id, $account_id]);
        $user_drive = $stmt_user->fetch(PDO::FETCH_ASSOC);

        if ($user_drive && !empty($user_drive['gg_refresh_token'])) {
            $refresh_token = $user_drive['gg_refresh_token'];
            $client_id = $user_drive['gg_client_id'];
            $client_secret = $user_drive['gg_client_secret'];
            
            // If Client ID / Client Secret are empty for the user, fallback to the system account settings
            if (empty($client_id) || empty($client_secret)) {
                $stmt_sys = $pdo->prepare("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = ?");
                $stmt_sys->execute([$account_id]);
                $sys = $stmt_sys->fetch(PDO::FETCH_ASSOC);
                if ($sys) {
                    if (empty($client_id)) $client_id = $sys['gg_client_id'];
                    if (empty($client_secret)) $client_secret = $sys['gg_client_secret'];
                }
            }
        }
    }

    // Fallback to system account if no user drive token was retrieved
    if (empty($refresh_token)) {
        $stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret, gg_refresh_token FROM system_accounts WHERE id = ?");
        $stmt->execute([$account_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account || empty($account['gg_refresh_token'])) {
            return false;
        }

        if (empty($account['gg_client_id']) || empty($account['gg_client_secret'])) {
            return false;
        }
        
        $refresh_token = $account['gg_refresh_token'];
        $client_id = $account['gg_client_id'];
        $client_secret = $account['gg_client_secret'];
    }

    if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
        return false;
    }

    $token_url = 'https://oauth2.googleapis.com/token';
    $post_fields = [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
        'grant_type' => 'refresh_token'
    ];

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $token_response_raw = curl_exec($ch);
    curl_close($ch);

    $token_data = json_decode($token_response_raw, true);

    if (!isset($token_data['access_token'])) {
        return false;
    }

    return $token_data['access_token'];
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
 * Tải file từ Google Drive về thư mục tạm (Temp Temp)
 * Trả về mảng chứa đường dẫn file tạm và mimeType
 */
function download_drive_file_temp($access_token, $file_id) {
    // 1. Lấy thông tin metadata của file (name, mimeType)
    $meta_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?fields=name,mimeType";
    $ch = curl_init($meta_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $meta_response = curl_exec($ch);
    curl_close($ch);

    $meta = json_decode($meta_response, true);
    if (!isset($meta['name'])) {
        return ['error' => 'Không thể lấy thông tin file từ Google Drive.'];
    }

    $mime_type = $meta['mimeType'];
    $file_name = $meta['name'];

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
        if (isset($map[$mime_type])) {
            $ext = $map[$mime_type];
        } else {
            $ext = 'tmp';
        }
        
        // Append extension to file name if it doesn't have it
        if ($ext !== 'tmp') {
            $file_name = rtrim($file_name, '.') . '.' . $ext;
        }
    }

    // 2. Download nội dung file
    $download_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?alt=media";
    
    // Tạo file tạm trên OS (Thường nằm ở /tmp trên Linux hoặc C:\Windows\Temp trên Windows)
    $temp_dir = sys_get_temp_dir();
    $temp_path = tempnam($temp_dir, 'gdrive_');
    
    // Thêm đuôi file để CURLFile của Facebook nhận diện đúng định dạng
    $temp_path_with_ext = $temp_path . '.' . $ext;
    rename($temp_path, $temp_path_with_ext);

    $fp = fopen($temp_path_with_ext, 'w+');
    if ($fp === false) {
        return ['error' => 'Không thể tạo file tạm trên Server.'];
    }

    $ch2 = curl_init($download_url);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
    curl_setopt($ch2, CURLOPT_FILE, $fp);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 600);
    $success = curl_exec($ch2);
    $http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    fclose($fp);

    if (!$success || $http_code !== 200) {
        @unlink($temp_path_with_ext);
        return ['error' => 'Lỗi tải file từ Google Drive (HTTP ' . $http_code . ').'];
    }

    return [
        'path' => $temp_path_with_ext,
        'mime' => $mime_type,
        'name' => $file_name
    ];
}

/**
 * Xóa file trên Google Drive
 */
function delete_drive_file($access_token, $file_id) {
    $delete_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id);
    $ch = curl_init($delete_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http_code === 204);
}

/**
 * Lấy danh sách file trong 1 thư mục từ Google Drive
 * Sắp xếp theo thời gian tạo tăng dần (cũ nhất trước)
 */
function list_drive_files_in_folder($access_token, $folder_id) {
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
?>
