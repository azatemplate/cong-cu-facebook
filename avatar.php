<?php
// avatar.php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/db.php';

$page_id = isset($_GET['id']) ? preg_replace('/[^0-9]/', '', $_GET['id']) : '';
if (!$page_id) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

$avatar_dir = __DIR__ . '/uploads/avatars';
if (!is_dir($avatar_dir)) {
    @mkdir($avatar_dir, 0777, true);
}

$avatar_filepath = $avatar_dir . '/' . $page_id . '.jpg';
$cache_time = 7 * 86400; // Cache for 7 days

if (file_exists($avatar_filepath) && (time() - filemtime($avatar_filepath) < $cache_time)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: max-age=86400, public');
    readfile($avatar_filepath);
    exit;
}

$stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
$stmt->execute([$page_id]);
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
$fb_api_url = "https://graph.facebook.com/v20.0/{$page_id}/picture?type=normal&redirect=0&access_token={$token}";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $fb_api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$img_data = false;

if (isset($data['data']['url'])) {
    $img_url = $data['data']['url'];
    
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
