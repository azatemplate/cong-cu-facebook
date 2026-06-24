<?php
// test_diagnose.php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/drive_utils.php';

echo "=== DIAGNOSTIC SYSTEM ===\n\n";

// Check PHP extensions
echo "PHP Version: " . PHP_VERSION . "\n";
echo "finfo_open available: " . (function_exists('finfo_open') ? 'YES' : 'NO') . "\n";
echo "mime_content_type available: " . (function_exists('mime_content_type') ? 'YES' : 'NO') . "\n";
echo "curl available: " . (function_exists('curl_init') ? 'YES' : 'NO') . "\n\n";

$target_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!isset($_GET['run'])) {
    echo "To run a live upload test for a specific post, visit: test_diagnose.php?run=1&id=<post_id>\n\n";
    echo "=== LAST 10 POSTS IN DATABASE ===\n";
    $stmt = $pdo->query("SELECT id, page_id, post_type, media_path, status, error_msg, scheduled_time, created_at FROM scheduled_posts ORDER BY id DESC LIMIT 10");
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($posts as $post) {
        echo "ID: {$post['id']} | Page: {$post['page_id']} | Type: {$post['post_type']} | Path: {$post['media_path']} | Status: {$post['status']} | Error: {$post['error_msg']}\n";
    }
    exit;
}

// Find target post
if ($target_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM scheduled_posts WHERE id = ?");
    $stmt->execute([$target_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->query("SELECT * FROM scheduled_posts ORDER BY id DESC LIMIT 1");
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$post) {
    echo "Post not found in database.\n";
    exit;
}

echo "TESTING UPLOAD FOR POST ID: " . $post['id'] . "\n";
echo "Page ID: " . $post['page_id'] . "\n";
echo "Post Type: " . $post['post_type'] . "\n";
echo "Media Path: " . $post['media_path'] . "\n";
echo "Scheduled Time: " . $post['scheduled_time'] . "\n\n";

$raw_media = $post['media_path'];
$is_folder = strpos($raw_media, 'folder:') === 0;
$is_drive = strpos($raw_media, 'drive:') === 0;

$drive_file_id = null;
if ($is_folder) {
    echo "This is a folder post. Fetching Drive Access Token...\n";
    $folder_id = substr($raw_media, 7);
    $drive_token = get_drive_access_token($pdo, $post['account_id']);
    echo "Access Token: " . ($drive_token ? "Obtained (length: " . strlen($drive_token) . ")" : "FAILED to obtain") . "\n";
    if (!$drive_token) {
        echo "ERROR: Could not obtain Google Drive access token. Please verify system settings.\n";
        exit;
    }
    
    echo "Listing files in folder $folder_id via Google API...\n";
    $q = "'" . str_replace("'", "\\'", $folder_id) . "' in parents and trashed = false";
    $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q) . "&fields=files(id,name,mimeType,createdTime,size)&orderBy=createdTime&pageSize=1000";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $drive_token"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    echo "Google API HTTP Status Code: $http_code\n";
    if ($curl_error) {
        echo "cURL Error: $curl_error\n";
    }
    
    if ($http_code !== 200) {
        echo "ERROR Response from Google API: " . $response . "\n";
        exit;
    }
    
    $data = json_decode($response, true);
    $files = isset($data['files']) ? $data['files'] : [];
    echo "Found " . count($files) . " files in Drive folder.\n\n";
    
    // Inline resolve logic
    $posted_files = [];
    try {
        $stmt_posted = $pdo->prepare("SELECT file_id FROM posted_folder_files WHERE folder_id = ?");
        $stmt_posted->execute([$folder_id]);
        $posted_files = $stmt_posted->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        echo "DB WARNING: " . $e->getMessage() . "\n";
    }
    
    echo "Already posted files count in DB: " . count($posted_files) . "\n";
    
    $target_file = null;
    $mime_filter = ($post['post_type'] === 'Video' || $post['post_type'] === 'Reel') ? 'video/*' : 'image/*';
    
    foreach ($files as $file) {
        $file_id = $file['id'];
        $mime = $file['mimeType'];
        
        if ($mime === 'application/vnd.google-apps.folder') continue;
        
        // Match mime filter
        $pattern = '/^' . str_replace(['/', '*'], ['\/', '.+'], $mime_filter) . '$/i';
        if (!preg_match($pattern, $mime)) {
            echo "Skipping file {$file['name']} due to mismatch mimeType: $mime\n";
            continue;
        }
        
        if (in_array($file_id, $posted_files)) {
            echo "Skipping file {$file['name']} (ID: $file_id) as it is already marked posted in DB.\n";
            continue;
        }
        
        $target_file = $file;
        break;
    }
    
    if (!$target_file) {
        echo "ERROR: No new unposted files matching '$mime_filter' found in this folder.\n";
        exit;
    }
    
    $drive_file_id = $target_file['id'];
    echo "Target file selected for upload:\n";
    echo "- Name: " . $target_file['name'] . "\n";
    echo "- ID: " . $drive_file_id . "\n";
    echo "- Drive MIME Type: " . $target_file['mimeType'] . "\n";
    echo "- Drive Size: " . ($target_file['size'] ?? 'unknown') . " bytes\n\n";
    
} elseif ($is_drive) {
    $drive_file_id = substr($raw_media, 6);
    echo "This is a single Drive post. File ID: " . $drive_file_id . "\n";
} else {
    echo "This is a local or TikTok post, diagnostic script only tests Google Drive files.\n";
    exit;
}

$drive_token = get_drive_access_token($pdo, $post['account_id']);
if (!$drive_token) {
    echo "ERROR: Could not obtain Google Drive access token.\n";
    exit;
}

// Download
echo "Downloading Drive file...\n";
$file_info = download_drive_file_temp($drive_token, $drive_file_id);
if (isset($file_info['error'])) {
    echo "DOWNLOAD ERROR: " . $file_info['error'] . "\n";
    exit;
}

echo "DOWNLOADED FILE METADATA:\n";
echo "Temp Path: " . $file_info['path'] . "\n";
echo "MIME Type: " . $file_info['mime'] . "\n";
echo "Name: " . $file_info['name'] . "\n";
echo "File size on disk: " . filesize($file_info['path']) . " bytes\n\n";

// Get page token
$page_stmt = $pdo->prepare("SELECT access_token, name FROM pages WHERE page_id = ?");
$page_stmt->execute([$post['page_id']]);
$page = $page_stmt->fetch(PDO::FETCH_ASSOC);

if (!$page || empty($page['access_token'])) {
    echo "ERROR: Page access token not found.\n";
    @unlink($file_info['path']);
    exit;
}

$page_access_token = decryptData($page['access_token']);
echo "Target Page: " . $page['name'] . "\n";

// Try uploading
echo "Sending upload request to Facebook Page...\n";
$endpoint = $post['page_id'] . '/videos';
$post_data = [
    'source' => new CURLFile($file_info['path'], $file_info['mime'], $file_info['name']),
    'description' => 'Diagnostic test upload'
];

$response = fb_api_request($endpoint, ['access_token' => $page_access_token], 'POST', $post_data);

echo "\nAPI UPLOAD RESPONSE:\n";
echo "HTTP Status Code: " . $response['status_code'] . "\n";
echo "Response data:\n";
print_r($response['data']);

// Clean up
@unlink($file_info['path']);
echo "\nCleaned up temp file.\n";
