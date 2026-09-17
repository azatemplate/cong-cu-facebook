<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/security.php';

echo "Updating old notifications...\n";

// Get all notifications where sender_name IS NULL
$stmt = $pdo->query("SELECT id, page_id, sender_id FROM page_notifications WHERE type = 'message' AND (sender_name IS NULL OR sender_name = '') AND sender_id IS NOT NULL");
$notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($notifs)) {
    echo "No notifications to update.\n";
    exit;
}

// Group by page_id to get tokens efficiently
$page_ids = array_unique(array_column($notifs, 'page_id'));
$page_tokens = [];
foreach ($page_ids as $pid) {
    $ts = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $ts->execute([$pid]);
    if ($page = $ts->fetch(PDO::FETCH_ASSOC)) {
        $token = decryptData($page['access_token']);
        if ($token) {
            $page_tokens[$pid] = $token;
        }
    }
}

// Update each
$updated = 0;
foreach ($notifs as $n) {
    if (isset($page_tokens[$n['page_id']])) {
        $token = $page_tokens[$n['page_id']];
        $res = fb_api_request($n['page_id'] . '/conversations', ['fields' => 'participants', 'user_id' => $n['sender_id'], 'access_token' => $token]);
        if ($res['status_code'] === 200 && !empty($res['data']['data'][0]['participants']['data'])) {
            $name = null;
            foreach ($res['data']['data'][0]['participants']['data'] as $p) {
                if ($p['id'] == $n['sender_id'] && !empty($p['name'])) {
                    $name = $p['name'];
                    break;
                }
            }
            if ($name) {
                $pdo->prepare("UPDATE page_notifications SET sender_name = ? WHERE id = ?")->execute([$name, $n['id']]);
                echo "Updated id {$n['id']} with name: $name<br>\n";
                $updated++;
            } else {
                echo "Failed for id {$n['id']} (Name not found in participants)<br>\n";
            }
        } else {
            echo "Failed for id {$n['id']}: " . json_encode($res['data']) . "<br>\n";
        }
    }
}

echo "Done! Updated: $updated\n";
