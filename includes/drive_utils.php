<?php
// includes/drive_utils.php

/**
 * Lấy Access Token từ Refresh Token
 */
function get_drive_access_token($pdo, $account_id) {
    $stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret, gg_refresh_token FROM system_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account || empty($account['gg_refresh_token'])) {
        return false;
    }

    if (empty($account['gg_client_id']) || empty($account['gg_client_secret'])) {
        return false;
    }
    $client_id = $account['gg_client_id'];
    $client_secret = $account['gg_client_secret'];

    $token_url = 'https://oauth2.googleapis.com/token';
    $post_fields = [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $account['gg_refresh_token'],
        'grant_type' => 'refresh_token'
    ];

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
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
    $q = "'" . str_replace("'", "\\'", $folder_id) . "' in parents and trashed = false";
    $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q) . "&fields=files(id,name,mimeType,createdTime,size)&orderBy=createdTime&pageSize=1000";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return false;
    }
    
    $data = json_decode($response, true);
    return isset($data['files']) ? $data['files'] : [];
}
?>
