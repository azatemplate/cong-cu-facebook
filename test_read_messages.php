<?php
// test_read_messages.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$page_id = '148579325014720';
$conv_id = 't_1535764451524552';

echo "=== DIAGNOSING MESSAGES FOR CONVERSATION $conv_id ===\n\n";

try {
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page || !$page['access_token']) {
        exit("Page or Access Token not found in database.\n");
    }
    
    $token = decryptData($page['access_token']);
    echo "Decrypted Page Token successfully.\n\n";
    
    // Query messages in the conversation
    $endpoint = "{$conv_id}/messages";
    $params = [
        'fields' => 'id,message,created_time,from,referral,tags',
        'limit' => 20,
        'access_token' => $token
    ];
    
    $response = fb_api_request($endpoint, $params, 'GET');
    echo "API HTTP Code: " . $response['status_code'] . "\n";
    echo "API Raw Response:\n";
    print_r($response['data']);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
