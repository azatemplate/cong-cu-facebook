<?php
// actions/drive_proxy.php
require_once __DIR__ . '/../includes/db.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['account_id'];

// Get Google Credentials and Refresh Token
$stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret, gg_refresh_token FROM system_accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || empty($account['gg_refresh_token'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng liên kết tài khoản Google Drive trong mục Cài đặt trước.']);
    exit;
}

if (empty($account['gg_client_id']) || empty($account['gg_client_secret'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu cấu hình Cài Đặt Google Client ID và Secret cá nhân.']);
    exit;
}
$client_id = $account['gg_client_id'];
$client_secret = $account['gg_client_secret'];

$refresh_token = $account['gg_refresh_token'];

// Step 1: Exchange Refresh Token for a fresh Access Token
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
$token_response_raw = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($token_response_raw, true);

if (!isset($token_data['access_token'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Không thể cấp mới Google Access Token (Refresh Token có thể đã bị thu hồi). Vui lòng kết nối lại.']);
    exit;
}

$access_token = $token_data['access_token'];

// Action mapping: list_files, get_file
$action = isset($_GET['action']) ? $_GET['action'] : 'list_files';

if ($action === 'list_files') {
    // Parent folder ID (default is 'root')
    $parent_id = isset($_GET['parent_id']) ? $_GET['parent_id'] : 'root';
    
    // Query condition
    // Fetch only folders or image/video files to keep it relevant
    $q = "'" . $parent_id . "' in parents and trashed = false and (mimeType contains 'image/' or mimeType contains 'video/' or mimeType = 'application/vnd.google-apps.folder')";
    
    // Phân trang: lặp qua tất cả các trang để lấy toàn bộ file
    $all_files = [];
    $page_token = null;
    
    do {
        $drive_url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q) 
            . "&fields=nextPageToken,files(id,name,mimeType,thumbnailLink,size)"
            . "&orderBy=folder,name"
            . "&pageSize=1000";
        
        if ($page_token) {
            $drive_url .= "&pageToken=" . urlencode($page_token);
        }
        
        $ch_drive = curl_init($drive_url);
        curl_setopt($ch_drive, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_drive, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token"
        ]);
        
        $drive_response = curl_exec($ch_drive);
        curl_close($ch_drive);
        
        $files_data = json_decode($drive_response, true);
        
        if (isset($files_data['error'])) {
            echo json_encode(['status' => 'error', 'msg' => 'Lỗi từ Google Drive API: ' . $files_data['error']['message']]);
            exit;
        }
        
        if (isset($files_data['files'])) {
            $all_files = array_merge($all_files, $files_data['files']);
        }
        
        $page_token = isset($files_data['nextPageToken']) ? $files_data['nextPageToken'] : null;
        
    } while ($page_token);
    
    echo json_encode(['status' => 'success', 'files' => $all_files, 'total' => count($all_files)]);
} else if ($action === 'get_file_info') {
    // Lấy tên và thumbnail của 1 file cụ thể khi user đã chọn
    $file_id = isset($_GET['file_id']) ? $_GET['file_id'] : '';
    if (!$file_id) {
         echo json_encode(['status' => 'error', 'msg' => 'Missing file_id']);
         exit;
    }

    $drive_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?fields=id,name,mimeType,thumbnailLink,size";
    
    $ch_drive = curl_init($drive_url);
    curl_setopt($ch_drive, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_drive, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $access_token"
    ]);
    
    $drive_response = curl_exec($ch_drive);
    curl_close($ch_drive);
    
    $file_data = json_decode($drive_response, true);
    
    if (isset($file_data['error'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi từ Google Drive API: ' . $file_data['error']['message']]);
        exit;
    }
    
    echo json_encode(['status' => 'success', 'file' => $file_data]);
} else if ($action === 'upload') {
    // Tải tệp từ client lên Google Drive vào thư mục Reels Media Access
    $folder_id = get_or_create_folder_id($access_token, 'Reels Media Access');
    if (!$folder_id) {
        echo json_encode(['status' => 'error', 'msg' => 'Không thể tạo hoặc tìm thư mục Reels Media Access trên Google Drive.']);
        exit;
    }
    
    if (empty($_FILES)) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy tệp để tải lên.']);
        exit;
    }
    
    $file_key = key($_FILES);
    $files = $_FILES[$file_key];
    $uploaded_files = [];
    
    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $files['tmp_name'][$i];
                $name = $files['name'][$i];
                $type = detect_mime_type($tmp_name, $name, $files['type'][$i]);
                
                $result = upload_file_to_drive_resumable($access_token, $tmp_name, $name, $type, $folder_id);
                if (isset($result['error'])) {
                    echo json_encode(['status' => 'error', 'msg' => 'Lỗi tải lên Google Drive: ' . $result['msg']]);
                    exit;
                }
                
                $uploaded_files[] = [
                    'id' => $result['id'],
                    'name' => $name
                ];
            }
        }
    } else {
        if ($files['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $files['tmp_name'];
            $name = $files['name'];
            $type = detect_mime_type($tmp_name, $name, $files['type']);
            
            $result = upload_file_to_drive_resumable($access_token, $tmp_name, $name, $type, $folder_id);
            if (isset($result['error'])) {
                echo json_encode(['status' => 'error', 'msg' => 'Lỗi tải lên Google Drive: ' . $result['msg']]);
                exit;
            }
            
            $uploaded_files[] = [
                'id' => $result['id'],
                'name' => $name
            ];
        }
    }
    
    if (empty($uploaded_files)) {
         echo json_encode(['status' => 'error', 'msg' => 'Tải lên không thành công hoặc tệp bị lỗi.']);
         exit;
    }
    
    echo json_encode([
        'status' => 'success',
        'files' => $uploaded_files
    ]);
    exit;
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid action']);
}

// ── Google Drive Helper Functions ──────────────────────────────────────────

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
        $error_msg = curl_error($ch);
        curl_close($ch);
        error_log("Google Drive API Search Folder cURL Error: " . $error_msg);
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
        $error_msg = curl_error($ch);
        curl_close($ch);
        error_log("Google Drive API Create Folder cURL Error: " . $error_msg);
        return false;
    }
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['id'])) {
        return $data['id'];
    }
    
    if (isset($data['error']['message'])) {
        error_log("Google Drive API Create Folder Error Response: " . $data['error']['message']);
    }
    return false;
}

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
        return [
            'error' => true,
            'msg' => 'cURL Error (Initiation): ' . $error_msg
        ];
    }
    
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        $body = substr($response, $header_size);
        $data = json_decode($body, true);
        return [
            'error' => true,
            'msg' => isset($data['error']['message']) ? $data['error']['message'] : 'Initiation failed (HTTP ' . $http_code . ')'
        ];
    }
    
    $headers_str = substr($response, 0, $header_size);
    $location = '';
    if (preg_match('/^Location:\s*(.*)$/im', $headers_str, $matches)) {
        $location = trim($matches[1]);
    }
    
    if (!$location) {
        return [
            'error' => true,
            'msg' => 'Could not find Location header for resumable upload.'
        ];
    }
    
    $fp = fopen($file_path, 'r');
    if (!$fp) {
        return [
            'error' => true,
            'msg' => 'Could not open file for uploading.'
        ];
    }
    
    $ch2 = curl_init($location);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_PUT, true);
    curl_setopt($ch2, CURLOPT_INFILE, $fp);
    curl_setopt($ch2, CURLOPT_INFILESIZE, $file_size);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        "Content-Length: $file_size"
    ]);
    
    $upload_response = curl_exec($ch2);
    if ($upload_response === false) {
        $error_msg = curl_error($ch2);
        curl_close($ch2);
        fclose($fp);
        return [
            'error' => true,
            'msg' => 'cURL Error (Upload): ' . $error_msg
        ];
    }
    
    $upload_http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    fclose($fp);
    
    $data = json_decode($upload_response, true);
    if (($upload_http_code === 200 || $upload_http_code === 201) && isset($data['id'])) {
        return $data;
    }
    
    return [
        'error' => true,
        'msg' => isset($data['error']['message']) ? $data['error']['message'] : 'Upload failed (HTTP ' . $upload_http_code . ')'
    ];
}

function detect_mime_type($tmp_name, $original_name, $client_mime_type) {
    // 1. Try to detect by original filename extension first
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

    // 2. Try the browser's client mime type if it is set and not generic
    if (!empty($client_mime_type) && $client_mime_type !== 'application/octet-stream') {
        return $client_mime_type;
    }

    // 3. Try native mime_content_type only if the real fileinfo extension is loaded
    if (extension_loaded('fileinfo') && function_exists('mime_content_type')) {
        $mime = @mime_content_type($tmp_name);
        if ($mime) {
            return $mime;
        }
    }
    
    // 4. Test image using getimagesize
    $img_info = @getimagesize($tmp_name);
    if ($img_info && isset($img_info['mime'])) {
        return $img_info['mime'];
    }
    
    return 'application/octet-stream';
}
