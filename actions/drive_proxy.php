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
    $stmt_admin = $pdo->query("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = 1");
    $admin_account = $stmt_admin->fetch(PDO::FETCH_ASSOC);
    if (!$admin_account || empty($admin_account['gg_client_id']) || empty($admin_account['gg_client_secret'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Thiếu cấu hình Client ID và Secret từ Admin.']);
        exit;
    }
    $client_id = $admin_account['gg_client_id'];
    $client_secret = $admin_account['gg_client_secret'];
} else {
    $client_id = $account['gg_client_id'];
    $client_secret = $account['gg_client_secret'];
}

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
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid action']);
}
?>
