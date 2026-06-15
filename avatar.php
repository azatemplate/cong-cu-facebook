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

if (file_exists($avatar_filepath) && (time() - filemtime($avatar_filepath) < $cache_time)) {
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

if ($page_id && $page_id !== $id) {
    // Customer Avatar (PSID) -> Query User Profile node fields
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
} else {
    // Page Avatar -> Query /picture edge
    $fb_api_url = "https://graph.facebook.com/v25.0/{$id}/picture?type=normal&redirect=0&access_token={$token}";
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
    } else {
        if (isset($_GET['debug'])) {
            header("Content-Type: text/plain");
            echo "Failed to fetch image. Graph API Response:\n$response\n\nImage URL: " . (isset($img_url) ? $img_url : 'None');
            exit;
        }
        header("HTTP/1.0 404 Not Found");
    }
}
