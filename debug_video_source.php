<?php
// debug_video_source.php
@ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

header('Content-Type: application/json; charset=utf-8');

// Get User Token
$stmt = $pdo->query("SELECT access_token, name, id FROM users WHERE access_token IS NOT NULL AND access_token != '' LIMIT 10");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$token = null;
foreach ($users as $u) {
    $tok = decryptData($u['access_token']);
    if ($tok) {
        $test = fb_api_request("me", ['access_token' => $tok]);
        if ($test['status_code'] === 200 && !empty($test['data']['id'])) {
            $token = $tok;
            break;
        }
    }
}

$post_id = "148579325014720_122282710484143067";

// 1. Fetch post object
$res_post = fb_api_request($post_id, [
    'access_token' => $token,
    'fields' => 'id,message,attachments{media_type,media{source,image},target,type,url,subattachments}'
]);

// 2. Extract target_id
$attachments = $res_post['data']['attachments']['data'][0] ?? null;
$target_id = $attachments['target']['id'] ?? '';
$target_url = $attachments['target']['url'] ?? '';

// 3. Try fetching target_id with source
$res_target = null;
if ($target_id) {
    $res_target = fb_api_request($target_id, [
        'access_token' => $token,
        'fields' => 'id,source,permalink_url'
    ]);
}

// 4. Try fetching via video endpoint if target_id is video
$res_video = null;
if ($target_id) {
    $res_video = fb_api_request("{$target_id}", [
        'access_token' => $token,
        'fields' => 'source'
    ]);
}

echo json_encode([
    'post_id' => $post_id,
    'post_api_response' => $res_post,
    'target_id' => $target_id,
    'target_url' => $target_url,
    'target_api_response' => $res_target,
    'video_api_response' => $res_video
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
