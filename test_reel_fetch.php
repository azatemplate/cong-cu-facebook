<?php
/**
 * Test fetching Reel video direct MP4 URL via Facebook Graph API
 * URL: https://fbweb.hongdolab.com/test_reel_fetch.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

@set_time_limit(30);
@ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/facebook_scraper.php';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Kiem tra Reel Video API</title>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#f8fafc;padding:20px;line-height:1.6;} a{color:#38bdf8;} pre{background:#1e293b;padding:15px;border-radius:8px;overflow-x:auto;} .success{color:#4ade80;} .error{color:#f87171;}</style></head><body>";

echo "<h2>🎬 Kiểm tra lấy link Reel Video qua Facebook Graph API</h2>";

$reel_id = "2020765378782801";
$post_id = "545112162239560_1477967903892963";

// Get user token
$stmt = $pdo->query("SELECT id, name, access_token FROM users WHERE access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 5");
$raw_users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$token = '';
$user_name = '';
foreach ($raw_users as $u) {
    $dec = decryptData($u['access_token']);
    if (!empty($dec) && strlen($dec) > 10) {
        $token = $dec;
        $user_name = $u['name'] ?: ("User #" . $u['id']);
        break;
    }
}

if (!$token) {
    echo "<p class='error'>❌ Không tìm thấy User Access Token khả dụng trong cơ sở dữ liệu.</p></body></html>";
    exit;
}

echo "<p>🔑 Đang dùng Access Token của User: <b>" . htmlspecialchars($user_name) . "</b></p>";
echo "<hr style='border-color:#334155;'>";

// Test 1: Fetch directly by Reel ID
echo "<h3>1. Thử lấy dữ liệu từ Reel ID gốc: <code>{$reel_id}</code></h3>";
$res1 = fb_api_request($reel_id, [
    'access_token' => $token,
    'fields' => 'id,source,description,created_time,format'
]);

if (!empty($res1['data']['source'])) {
    $mp4_url = $res1['data']['source'];
    echo "<p class='success'>✅ <b>LẤY THÀNH CÔNG LINK VIDEO MP4!</b></p>";
    echo "<p>🔗 <b>Link MP4:</b> <a href='" . htmlspecialchars($mp4_url) . "' target='_blank'>" . htmlspecialchars($mp4_url) . "</a></p>";
    echo "<video controls width='400' style='border-radius:8px;'><source src='" . htmlspecialchars($mp4_url) . "' type='video/mp4'></video>";
} else {
    echo "<p class='error'>⚠️ Graph API không trả về trường 'source' cho Reel ID này trực tiếp.</p>";
    echo "<pre>" . htmlspecialchars(json_encode($res1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
}

echo "<hr style='border-color:#334155;'>";

// Test 2: Fetch by Post ID attachments
echo "<h3>2. Thử lấy dữ liệu từ Post ID: <code>{$post_id}</code> (Bài viết Reels)</h3>";
$res2 = fb_api_request($post_id, [
    'access_token' => $token,
    'fields' => 'id,message,created_time,full_picture,attachments{media_type,media{source,image},target,type,url,subattachments{media_type,media{source,image},target,type,url}}'
]);

if (isset($res2['data'])) {
    $extracted = extract_post_media_urls($res2['data'], $token);
    echo "<p><b>Dữ liệu Media bóc tách:</b></p>";
    echo "<pre>" . htmlspecialchars(json_encode($extracted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
    if (!empty($extracted['videos'])) {
        echo "<p class='success'>✅ <b>TÌM THẤY VIDEO SOURCE:</b></p>";
        foreach ($extracted['videos'] as $v) {
            echo "<p>🔗 <a href='" . htmlspecialchars($v) . "' target='_blank'>" . htmlspecialchars($v) . "</a></p>";
        }
    }
} else {
    echo "<pre class='error'>" . htmlspecialchars(json_encode($res2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
}

echo "</body></html>";
