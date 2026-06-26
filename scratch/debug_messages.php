<?php
// Temporary script to dump raw Zalo messages for debugging file attachments
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/zalo_api.php';

// Get first OA
$oas = $pdo->query("SELECT oa_id FROM zalo_oas LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
if (empty($oas)) {
    echo "No OAs found\n";
    exit;
}

$oa_id = $oas[0]['oa_id'];
echo "OA ID: $oa_id\n\n";

$access_token = zalo_get_active_token($oa_id, $pdo);
if (!$access_token) {
    echo "Cannot get access token\n";
    exit;
}

// Get recent conversations
$conversations = $pdo->query("SELECT sender_id, sender_name FROM zalo_messages WHERE oa_id = '$oa_id' ORDER BY updated_time DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

foreach ($conversations as $conv) {
    echo "=== Sender: {$conv['sender_name']} ({$conv['sender_id']}) ===\n";
    
    $data_param = json_encode([
        'user_id' => $conv['sender_id'],
        'offset' => 0,
        'count' => 10
    ]);
    
    $url = ZALO_API_BASE . 'v2.0/oa/conversation?data=' . urlencode($data_param);
    $headers = [
        "access_token: {$access_token}"
    ];
    
    $res = zalo_api_request($url, 'GET', $headers);
    
    if ($res['status_code'] === 200 && isset($res['data']['data'])) {
        foreach ($res['data']['data'] as $msg) {
            $type = $msg['type'] ?? 'no-type';
            $message = $msg['message'] ?? '';
            $src = $msg['src'] ?? '?';
            
            // Check if it's not just text
            if ($type !== 'text' || !empty($msg['url']) || !empty($msg['thumb']) || isset($msg['attachment']) || isset($msg['attachments'])) {
                echo "\n--- Non-text message (type=$type, src=$src) ---\n";
                echo json_encode($msg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    } else {
        echo "API error: " . json_encode($res, JSON_PRETTY_PRINT) . "\n";
    }
    echo "\n";
}
