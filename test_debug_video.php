<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
header('Content-Type: application/json; charset=utf-8');

$stmt = $pdo->query("SELECT access_token FROM users WHERE access_token IS NOT NULL AND access_token != '' LIMIT 10");
$token = null;
foreach ($stmt->fetchAll() as $u) {
    $t = decryptData($u['access_token']);
    if ($t) {
        $chk = fb_api_request("me", ['access_token' => $t]);
        if ($chk['status_code'] === 200) { $token = $t; break; }
    }
}

$post_id = "545112162239560_1477967903892963";

// 1. Query post direct
$res_post = fb_api_request($post_id, [
    'access_token' => $token,
    'fields' => 'id,message,permalink_url,attachments{media,media_type,target,type,url,subattachments}'
]);

// 2. Query target ID from attachments if exists
$target_id = $res_post['data']['attachments']['data'][0]['target']['id'] ?? '';
$res_target = null;
if ($target_id) {
    $res_target = fb_api_request($target_id, [
        'access_token' => $token,
        'fields' => 'id,source,permalink_url,embed_html,format'
    ]);
}

// 3. Query post video attachments with source explicitly
$res_att_source = fb_api_request("{$post_id}/attachments", [
    'access_token' => $token,
    'fields' => 'media{source,image},target{id,url}'
]);

echo json_encode([
    'post' => $res_post['data'] ?? null,
    'target_id' => $target_id,
    'target_res' => $res_target['data'] ?? ($res_target ?? null),
    'att_source' => $res_att_source['data'] ?? null
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
