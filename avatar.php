<?php
// avatar.php - High Performance Avatar Caching Proxy
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

// 1. Serve cached avatar from disk if valid
if (!$nocache && file_exists($avatar_filepath) && filesize($avatar_filepath) > 0) {
    $mtime = filemtime($avatar_filepath);
    if ((time() - $mtime) < $cache_time) {
        $etag = '"' . md5($avatar_filepath . $mtime) . '"';
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            header("HTTP/1.1 304 Not Modified");
            exit;
        }
        $is_svg = (strpos(@file_get_contents($avatar_filepath, false, null, 0, 10), '<svg') !== false);
        header($is_svg ? 'Content-Type: image/svg+xml' : 'Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);
        readfile($avatar_filepath);
        exit;
    }
}

// Default SVG fallback avatar
$default_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 24 24" fill="#9ca3af"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';

$token_page_id = $page_id ? $page_id : $id;
$stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
$stmt->execute([$token_page_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    if (file_exists($avatar_filepath) && filesize($avatar_filepath) > 0) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        readfile($avatar_filepath);
    } else {
        // Save fallback SVG to prevent repeating DB queries
        @file_put_contents($avatar_filepath, $default_svg);
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=3600');
        echo $default_svg;
    }
    exit;
}

$token = decryptData($page['access_token']);

// Fetch using Graph API with strict 2-second timeout
$img_data = false;
$img_url = null;
$is_customer = ($page_id && $page_id !== $id);

if ($is_customer) {
    // Method 1 for Customer: Try /picture edge
    $fb_api_url = "https://graph.facebook.com/v25.0/{$id}/picture?type=large&redirect=false&access_token={$token}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fb_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2s timeout
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['data']['url'])) {
        $img_url = $data['data']['url'];
    }
    
    // Method 2 for Customer: Try profile_pic
    if (!$img_url) {
        $fb_api_url_fallback = "https://graph.facebook.com/v25.0/{$id}?fields=profile_pic&access_token={$token}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fb_api_url_fallback);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $response_fallback = curl_exec($ch);
        curl_close($ch);
        
        $data_fallback = json_decode($response_fallback, true);
        if (isset($data_fallback['profile_pic'])) {
            $img_url = $data_fallback['profile_pic'];
        }
    }
} else {
    // Method 1 for Page: Try /picture edge
    $fb_api_url = "https://graph.facebook.com/v25.0/{$id}/picture?type=large&redirect=false&access_token={$token}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fb_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['data']['url'])) {
        $img_url = $data['data']['url'];
    }
}

if (isset($img_url)) {
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, $img_url);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 3);
    $img_data = curl_exec($ch2);
    curl_close($ch2);
}

if ($img_data !== false && !empty($img_data)) {
    @file_put_contents($avatar_filepath, $img_data);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    echo $img_data;
} else {
    // Save default SVG placeholder to disk so future requests return in 0.0001s without hitting FB API
    @file_put_contents($avatar_filepath, $default_svg);
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=3600');
    echo $default_svg;
}
