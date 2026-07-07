<?php
// test_tags.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$page_id = '148579325014720';
$sender_id = '27749040704712300';

echo "=== DIAGNOSING CUSTOM LABELS FOR SENDER $sender_id ===\n\n";

try {
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page || !$page['access_token']) {
        exit("Page or Access Token not found in database.\n");
    }
    
    $token = decryptData($page['access_token']);
    echo "Decrypted Page Token successfully.\n\n";
    
    // Step 1: Query custom_labels for the PSID directly
    echo "Querying custom_labels for PSID: $sender_id...\n";
    $endpoint = "{$sender_id}/custom_labels";
    $params = [
        'access_token' => $token
    ];
    
    $response = fb_api_request($endpoint, $params, 'GET');
    echo "API HTTP Code: " . $response['status_code'] . "\n";
    echo "API Raw Response:\n";
    print_r($response['data']);
    
    // Step 2: Also query all custom labels created for the Page to verify what labels exist
    echo "\nQuerying all custom labels for Page: $page_id...\n";
    $page_lbl_response = fb_api_request("{$page_id}/custom_labels", [
        'access_token' => $token
    ], 'GET');
    echo "Page custom labels response:\n";
    print_r($page_lbl_response['data']);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
