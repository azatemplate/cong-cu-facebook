<?php
// get_fb_chat_history.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
header('Content-Type: text/plain; charset=utf-8');

$sender_id = '27402995709356991';
$page_id = '148579325014720';

echo "Querying Facebook Graph API for conversation history with PSID: $sender_id\n\n";

try {
    // Get page token from DB
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $token_enc = $stmt->fetchColumn();
    if (!$token_enc) {
        echo "Error: Page token not found in database for page_id $page_id\n";
        exit;
    }
    $token = decryptData($token_enc);
    
    // Find conversation ID first
    $conv_url = "https://graph.facebook.com/v25.0/me/conversations?user_id=" . urlencode($sender_id) . "&access_token=" . urlencode($token);
    $ch = curl_init($conv_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    fb_curl_setssl($ch);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (empty($res['data'])) {
        echo "No conversation found with user_id $sender_id. Full API Response:\n";
        print_r($res);
        exit;
    }
    
    $conv_id = $res['data'][0]['id'];
    echo "Found Conversation ID: $conv_id\n\n";
    
    // Fetch messages in this conversation
    $msg_url = "https://graph.facebook.com/v25.0/$conv_id/messages?fields=message,created_time,from&limit=10&access_token=" . urlencode($token);
    $ch2 = curl_init($msg_url);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    fb_curl_setssl($ch2);
    $res2 = json_decode(curl_exec($ch2), true);
    curl_close($ch2);
    
    echo "=== RECENT MESSAGES FROM FACEBOOK GRAPH API ===\n";
    if (!empty($res2['data'])) {
        foreach ($res2['data'] as $msg) {
            $sender = $msg['from']['name'] ?? ($msg['from']['id'] ?? 'Unknown');
            $is_page = ($msg['from']['id'] == $page_id) ? "PAGE/AGENT" : "CUSTOMER";
            echo "- Time: {$msg['created_time']} | Sender: $sender ($is_page) | Message: " . ($msg['message'] ?? '[No text]') . "\n";
        }
    } else {
        echo "No messages returned. Full API Response:\n";
        print_r($res2);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
