<?php
// avatar.php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/db.php';

$id = isset($_GET['id']) ? preg_replace('/[^0-9]/', '', $_GET['id']) : '';
$page_id = isset($_GET['page_id']) ? preg_replace('/[^0-9]/', '', $_GET['page_id']) : '';

if (!$id) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

$avatar_dir = __DIR__ . '/uploads/avatars';
if (!is_dir($avatar_dir)) {
    @mkdir($avatar_dir, 0777, true);
}

// Scoped filename for customer avatars to prevent collisions across different pages
$avatar_filepath = $avatar_dir . '/' . $id . ($page_id ? '_' . $page_id : '') . '.jpg';
$cache_time = 7 * 86400; // Cache for 7 days

$nocache = isset($_GET['nocache']) || isset($_GET['refresh']);
if (!$nocache && file_exists($avatar_filepath) && filesize($avatar_filepath) > 0 && (time() - filemtime($avatar_filepath) < $cache_time)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: max-age=86400, public');
    readfile($avatar_filepath);
    exit;
}

$token_page_id = $page_id ? $page_id : $id;
$stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
$stmt->execute([$token_page_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    if (file_exists($avatar_filepath)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: max-age=86400, public');
        readfile($avatar_filepath);
    } else {
        header("HTTP/1.0 404 Not Found");
    }
    exit;
}

$token = decryptData($page['access_token']);

// Fetch using Graph API
$img_data = false;
$response = '';
$img_url = null;

$is_customer = ($page_id && $page_id !== $id);

if ($is_customer) {
    // Method 1 for Customer: Try profile_pic (Messenger Profile API, requires Business Asset User Profile Access)
    $fb_api_url = "https://graph.facebook.com/v25.0/{$id}?fields=profile_pic&access_token={$token}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fb_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['profile_pic'])) {
        $img_url = $data['profile_pic'];
    }
    
    // Method 2 for Customer: Fallback to /picture edge
    if (!$img_url) {
        $fb_api_url_fallback = "https://graph.facebook.com/v25.0/{$id}/picture?type=large&redirect=false&access_token={$token}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fb_api_url_fallback);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response_fallback = curl_exec($ch);
        curl_close($ch);
        
        $data_fallback = json_decode($response_fallback, true);
        if (isset($data_fallback['data']['url'])) {
            $img_url = $data_fallback['data']['url'];
        } else {
            // Log failure to error_log.txt
            $log_message = date('[Y-m-d H:i:s] ') . "Failed to fetch avatar for Customer ID: $id (Page ID: $page_id).\n" . 
                           "Method 1 URL: $fb_api_url\nResponse 1: $response\n" .
                           "Method 2 URL: $fb_api_url_fallback\nResponse 2: $response_fallback\n\n";
            @file_put_contents(__DIR__ . '/uploads/avatars/error_log.txt', $log_message, FILE_APPEND);
        }
    }
} else {
    // Method 1 for Page: Try /picture edge
    $fb_api_url = "https://graph.facebook.com/v25.0/{$id}/picture?type=large&redirect=false&access_token={$token}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fb_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['data']['url'])) {
        $img_url = $data['data']['url'];
    }
    
    // Method 2 for Page: Fallback to profile_pic field
    if (!$img_url) {
        $fb_api_url_fallback = "https://graph.facebook.com/v25.0/{$id}?fields=profile_pic&access_token={$token}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fb_api_url_fallback);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response_fallback = curl_exec($ch);
        curl_close($ch);
        
        $data_fallback = json_decode($response_fallback, true);
        if (isset($data_fallback['profile_pic'])) {
            $img_url = $data_fallback['profile_pic'];
        } else {
            // Log failure to error_log.txt
            $log_message = date('[Y-m-d H:i:s] ') . "Failed to fetch avatar for Page ID: $id.\n" . 
                           "Method 1 URL: $fb_api_url\nResponse 1: $response\n" .
                           "Method 2 URL: $fb_api_url_fallback\nResponse 2: $response_fallback\n\n";
            @file_put_contents(__DIR__ . '/uploads/avatars/error_log.txt', $log_message, FILE_APPEND);
        }
    }
}

if (isset($img_url)) {
    // Use curl instead of file_get_contents
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, $img_url);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
    $img_data = curl_exec($ch2);
    curl_close($ch2);

    if ($img_data !== false && !empty($img_data)) {
        file_put_contents($avatar_filepath, $img_data);
    }
}

if ($img_data !== false && !empty($img_data)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: max-age=86400, public');
    echo $img_data;
} else {
    // Fallback to old cache
    if (file_exists($avatar_filepath)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: max-age=86400, public');
        readfile($avatar_filepath);
        exit;
    } else {
        if (isset($_GET['debug'])) {
            header("Content-Type: text/plain");
            echo "Failed to fetch image. Graph API Response:\n$response\n\nImage URL: " . (isset($img_url) ? $img_url : 'None');
            exit;
        }
        $fallback_name = isset($_GET['name']) && !empty($_GET['name']) ? $_GET['name'] : 'User';
        // Clean up the name a bit
        $fallback_name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $fallback_name);
        $fallback_url = "https://ui-avatars.com/api/?name=" . urlencode($fallback_name) . "&background=random&color=fff&size=128";
        header("Location: " . $fallback_url);
        exit;
    }
}
