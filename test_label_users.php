<?php
// test_label_users.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$page_id = '148579325014720';
// Let's test a few label IDs from the output:
// 887959310771495 -> ad_id.120240456226050441
// 1425258776289883 -> Đã cho số điện thoại
$label_ids = [
    '887959310771495', // ad_id.120240456226050441
    '1425258776289883'  // Đã cho số điện thoại
];

echo "=== DIAGNOSING USERS ASSIGNED TO CUSTOM LABELS ===\n\n";

try {
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page || !$page['access_token']) {
        exit("Page or Access Token not found in database.\n");
    }
    
    $token = decryptData($page['access_token']);
    echo "Decrypted Page Token successfully.\n\n";
    
    foreach ($label_ids as $lbl_id) {
        echo "Querying users for Label ID: $lbl_id...\n";
        // Endpoint: /{label-id}/label
        $endpoint = "{$lbl_id}/label";
        $params = [
            'access_token' => $token
        ];
        
        $response = fb_api_request($endpoint, $params, 'GET');
        echo "API HTTP Code: " . $response['status_code'] . "\n";
        echo "API Raw Response:\n";
        print_r($response['data']);
        echo str_repeat("=", 50) . "\n\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
