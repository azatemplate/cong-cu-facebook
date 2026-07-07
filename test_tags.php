<?php
// test_tags.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$page_id = '148579325014720';
$sender_id = '27749040704712300';

echo "=== DIAGNOSING CONVERSATION TAGS FOR SENDER $sender_id ===\n\n";

try {
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page || !$page['access_token']) {
        exit("Page or Access Token not found in database.\n");
    }
    
    $token = decryptData($page['access_token']);
    echo "Decrypted Page Token successfully.\n\n";
    
    // Step 1: Query conversation details using user_id parameter
    echo "Querying conversation with user_id=$sender_id...\n";
    $endpoint = "{$page_id}/conversations";
    $params = [
        'fields' => 'id,updated_time,unread_count,tags{name},participants{id,name,email,custom_labels}',
        'user_id' => $sender_id,
        'access_token' => $token
    ];
    
    $response = fb_api_request($endpoint, $params, 'GET');
    echo "API HTTP Code: " . $response['status_code'] . "\n";
    echo "API Raw Response:\n";
    print_r($response['data']);
    
    if (isset($response['data']['data'][0])) {
        $conv = $response['data']['data'][0];
        $conv_id = $conv['id'];
        echo "\nFound Conversation ID: $conv_id\n";
        
        // Step 2: Query labels directly for this conversation
        echo "Querying labels directly for conv $conv_id...\n";
        $lbl_response = fb_api_request($conv_id, [
            'fields' => 'tags{name},custom_labels',
            'access_token' => $token
        ], 'GET');
        echo "Direct query response:\n";
        print_r($lbl_response['data']);
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
