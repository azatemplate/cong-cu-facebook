<?php
// actions/drive_proxy.php
require_once __DIR__ . '/../includes/db.php';
session_start();
$action = isset($_GET['action']) ? $_GET['action'] : 'list_files';

if (!isset($_SESSION['account_id']) && $action !== 'stream' && $action !== 'download') {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : ($_SESSION['account_id'] ?? 1);
$user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;

// Helper: Exchange Refresh Token for Access Token
function exchange_refresh_token($client_id, $client_secret, $refresh_token) {
    if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
        return null;
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $token_response_raw = curl_exec($ch);
    curl_close($ch);

    $token_data = json_decode($token_response_raw, true);
    return $token_data['access_token'] ?? null;
}

// Get prioritized list of token configurations WITHOUT exchanging them upfront
function get_all_drive_token_configs($pdo, $account_id, $user_id = 0) {
    $tokens = [];
    $seen_refreshes = [];

    // Default Client ID / Secret from system_accounts or system_settings
    $default_client_id = null;
    $default_client_secret = null;

    $stmt_sys_cfg = $pdo->prepare("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = ?");
    $stmt_sys_cfg->execute([$account_id]);
    $sys_cfg = $stmt_sys_cfg->fetch(PDO::FETCH_ASSOC);
    if ($sys_cfg) {
        $default_client_id = $sys_cfg['gg_client_id'] ?? null;
        $default_client_secret = $sys_cfg['gg_client_secret'] ?? null;
    }

    if (empty($default_client_id) || empty($default_client_secret)) {
        try {
            $st_sys = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('google_client_id', 'google_client_secret')");
            while ($r = $st_sys->fetch(PDO::FETCH_ASSOC)) {
                if ($r['setting_key'] === 'google_client_id' && empty($default_client_id)) $default_client_id = $r['setting_value'];
                if ($r['setting_key'] === 'google_client_secret' && empty($default_client_secret)) $default_client_secret = $r['setting_value'];
            }
        } catch (Exception $e) {}
    }

    // 1. Specific user_id token if provided
    if ($user_id > 0) {
        $stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret, gg_refresh_token FROM users WHERE id = ? AND account_id = ?");
        $stmt->execute([$user_id, $account_id]);
        $user_row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row && !empty($user_row['gg_refresh_token'])) {
            $tokens[] = [
                'type' => 'user',
                'user_id' => $user_id,
                'client_id' => !empty($user_row['gg_client_id']) ? $user_row['gg_client_id'] : $default_client_id,
                'client_secret' => !empty($user_row['gg_client_secret']) ? $user_row['gg_client_secret'] : $default_client_secret,
                'refresh_token' => $user_row['gg_refresh_token']
            ];
            $seen_refreshes[$user_row['gg_refresh_token']] = true;
        }
    }

    // 2. Main System Account (Settings) token
    $stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret, gg_refresh_token FROM system_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($account && !empty($account['gg_refresh_token'])) {
        if (!isset($seen_refreshes[$account['gg_refresh_token']])) {
            $tokens[] = [
                'type' => 'system',
                'user_id' => 0,
                'client_id' => !empty($account['gg_client_id']) ? $account['gg_client_id'] : $default_client_id,
                'client_secret' => !empty($account['gg_client_secret']) ? $account['gg_client_secret'] : $default_client_secret,
                'refresh_token' => $account['gg_refresh_token']
            ];
            $seen_refreshes[$account['gg_refresh_token']] = true;
        }
    }

    // 3. Other users with Drive refresh tokens
    $stmt_u = $pdo->prepare("SELECT id, gg_client_id, gg_client_secret, gg_refresh_token FROM users WHERE account_id = ? AND gg_refresh_token IS NOT NULL AND gg_refresh_token != '' ORDER BY id ASC");
    $stmt_u->execute([$account_id]);
    while ($u_drive = $stmt_u->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($u_drive['gg_refresh_token']) && !isset($seen_refreshes[$u_drive['gg_refresh_token']])) {
            $tokens[] = [
                'type' => 'user',
                'user_id' => $u_drive['id'],
                'client_id' => !empty($u_drive['gg_client_id']) ? $u_drive['gg_client_id'] : $default_client_id,
                'client_secret' => !empty($u_drive['gg_client_secret']) ? $u_drive['gg_client_secret'] : $default_client_secret,
                'refresh_token' => $u_drive['gg_refresh_token']
            ];
            $seen_refreshes[$u_drive['gg_refresh_token']] = true;
        }
    }

    return $tokens;
}

function fetch_drive_files_list($access_token, $parent_id) {
    $execute_query = function($q_str) use ($access_token) {
        $all_files = [];
        $page_token = null;
        $pages = 0;
        
        do {
            // Remove orderBy to make Google Drive API response lightning fast!
            $drive_url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q_str) 
                . "&fields=nextPageToken,files(id,name,mimeType,thumbnailLink,size)"
                . "&pageSize=500"
                . "&supportsAllDrives=true"
                . "&includeItemsFromAllDrives=true";
            
            if ($page_token) {
                $drive_url .= "&pageToken=" . urlencode($page_token);
            }
            
            $ch_drive = curl_init($drive_url);
            curl_setopt($ch_drive, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_drive, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch_drive, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch_drive, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $access_token"
            ]);
            
            $drive_response = curl_exec($ch_drive);
            curl_close($ch_drive);
            
            $files_data = json_decode($drive_response, true);
            
            if (isset($files_data['error'])) {
                return ['error' => 'Lỗi từ Google Drive API: ' . $files_data['error']['message']];
            }
            
            if (isset($files_data['files'])) {
                $all_files = array_merge($all_files, $files_data['files']);
            }
            
            $page_token = isset($files_data['nextPageToken']) ? $files_data['nextPageToken'] : null;
            $pages++;
            if ($pages >= 3) break; // Limit to max 3 pages (1500 items max) to prevent long waiting times
            
        } while ($page_token);
        
        return ['files' => $all_files];
    };

    if ($parent_id === 'root') {
        $q = "('root' in parents or sharedWithMe = true) and trashed = false";
    } else {
        $q = "'" . str_replace("'", "\\'", $parent_id) . "' in parents and trashed = false";
    }

    $res = $execute_query($q);
    
    // If querying root returned 0 files and no error, fallback to searching all non-trashed items
    if ($parent_id === 'root' && !isset($res['error']) && empty($res['files'])) {
        $fallback_res = $execute_query("trashed = false");
        if (!isset($fallback_res['error']) && !empty($fallback_res['files'])) {
            $res = $fallback_res;
        }
    }

    // Sort folders first, then files alphabetically in PHP (instantaneous < 1ms)
    if (!empty($res['files'])) {
        usort($res['files'], function($a, $b) {
            $is_a_folder = ($a['mimeType'] === 'application/vnd.google-apps.folder') ? 1 : 0;
            $is_b_folder = ($b['mimeType'] === 'application/vnd.google-apps.folder') ? 1 : 0;
            if ($is_a_folder !== $is_b_folder) {
                return $is_b_folder - $is_a_folder; // Folders first
            }
            return strnatcasecmp($a['name'], $b['name']);
        });
    }

    return $res;
}

$token_configs = get_all_drive_token_configs($pdo, $account_id, $user_id);
if (empty($token_configs)) {
    if ($action === 'stream' || $action === 'download') {
        header('HTTP/1.1 404 Not Found');
        echo 'No active Google Drive token found.';
        exit;
    }
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng liên kết tài khoản Google Drive trong mục Cài đặt trước.']);
    exit;
}

if ($action === 'list_files') {
    $parent_id = isset($_GET['parent_id']) ? $_GET['parent_id'] : 'root';
    
    $last_error = null;
    $all_files = null;

    foreach ($token_configs as $cfg) {
        $tok = exchange_refresh_token($cfg['client_id'], $cfg['client_secret'], $cfg['refresh_token']);
        if (!$tok) {
            $last_error = "Không thể cấp mới Google Access Token.";
            continue;
        }
        
        $res = fetch_drive_files_list($tok, $parent_id);
        if (isset($res['error'])) {
            $last_error = $res['error'];
            continue;
        }
        
        if (!empty($res['files'])) {
            $all_files = $res['files'];
            break; // Found non-empty list of files! Return immediately!
        }

        if ($all_files === null) {
            $all_files = [];
        }
    }

    if ($all_files === null && $last_error) {
        echo json_encode(['status' => 'error', 'msg' => $last_error]);
        exit;
    }
    
    $final_files = $all_files ?? [];
    echo json_encode(['status' => 'success', 'files' => $final_files, 'total' => count($final_files)]);
    exit;

} else if ($action === 'get_file_info') {
    $file_id = isset($_GET['file_id']) ? $_GET['file_id'] : '';
    if (!$file_id) {
         echo json_encode(['status' => 'error', 'msg' => 'Missing file_id']);
         exit;
    }

    $file_data = null;
    $last_error = null;

    foreach ($token_configs as $cfg) {
        $tok = exchange_refresh_token($cfg['client_id'], $cfg['client_secret'], $cfg['refresh_token']);
        if (!$tok) continue;

        $drive_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?fields=id,name,mimeType,thumbnailLink,size&supportsAllDrives=true";
        
        $ch_drive = curl_init($drive_url);
        curl_setopt($ch_drive, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_drive, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch_drive, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch_drive, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tok"]);
        
        $drive_response = curl_exec($ch_drive);
        curl_close($ch_drive);
        
        $res = json_decode($drive_response, true);
        if (isset($res['id'])) {
            $file_data = $res;
            break;
        } else if (isset($res['error'])) {
            $last_error = $res['error']['message'];
        }
    }

    if ($file_data) {
        echo json_encode(['status' => 'success', 'file' => $file_data]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi từ Google Drive API: ' . ($last_error ?: 'File not found')]);
    }
    exit;

} else if ($action === 'stream' || $action === 'download') {
    $file_id = isset($_GET['file_id']) ? $_GET['file_id'] : '';
    if (!$file_id) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Missing file_id';
        exit;
    }

    $stream_success = false;

    foreach ($token_configs as $cfg) {
        $tok = exchange_refresh_token($cfg['client_id'], $cfg['client_secret'], $cfg['refresh_token']);
        if (!$tok) continue;

        $drive_url = "https://www.googleapis.com/drive/v3/files/" . urlencode($file_id) . "?alt=media&supportsAllDrives=true";
        
        $ch_drive = curl_init($drive_url);
        curl_setopt_array($ch_drive, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $tok"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 300
        ]);
        
        $file_content = curl_exec($ch_drive);
        $http_code = curl_getinfo($ch_drive, CURLINFO_HTTP_CODE);
        curl_close($ch_drive);
        
        if ($http_code === 200 && !empty($file_content)) {
            header("HTTP/1.1 200 OK");
            header("Content-Type: video/mp4");
            header("Accept-Ranges: bytes");
            header("Content-Length: " . strlen($file_content));
            header("Content-Disposition: inline; filename=\"video.mp4\"");
            echo $file_content;
            $stream_success = true;
            break;
        }
    }

    if (!$stream_success) {
        header('HTTP/1.1 404 Not Found');
        echo 'File stream failed';
    }
    exit;

} else if ($action === 'upload') {
    if (empty($_FILES)) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy tệp để tải lên.']);
        exit;
    }

    $uploaded_files = [];
    $file_key = key($_FILES);
    $files = $_FILES[$file_key];

    // Find first working active token for upload
    $tok = null;
    foreach ($token_configs as $cfg) {
        $t = exchange_refresh_token($cfg['client_id'], $cfg['client_secret'], $cfg['refresh_token']);
        if ($t) {
            $tok = $t;
            break;
        }
    }

    if (!$tok) {
        echo json_encode(['status' => 'error', 'msg' => 'Không thể kết nối Google Drive API.']);
        exit;
    }

    $folder_id = get_or_create_folder_id($tok, 'HONGDOLABS');

    if (!$folder_id) {
        echo json_encode(['status' => 'error', 'msg' => 'Không thể tạo hoặc tìm thư mục HONGDOLABS trên Google Drive.']);
        exit;
    }

    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $files['tmp_name'][$i];
                $name = $files['name'][$i];
                $type = detect_mime_type($tmp_name, $name, $files['type'][$i]);
                
                $result = upload_file_to_drive_resumable($tok, $tmp_name, $name, $type, $folder_id);
                if (isset($result['error'])) {
                    echo json_encode(['status' => 'error', 'msg' => 'Lỗi tải lên Google Drive: ' . $result['msg']]);
                    exit;
                }
                
                if (!empty($result['id'])) {
                    make_drive_file_public($tok, $result['id']);
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
            
            $result = upload_file_to_drive_resumable($tok, $tmp_name, $name, $type, $folder_id);
            if (isset($result['error'])) {
                echo json_encode(['status' => 'error', 'msg' => 'Lỗi tải lên Google Drive: ' . $result['msg']]);
                exit;
            }
            
            if (!empty($result['id'])) {
                make_drive_file_public($tok, $result['id']);
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
    exit;
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
