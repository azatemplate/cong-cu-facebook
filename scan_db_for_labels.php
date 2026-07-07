<?php
// scan_db_for_labels.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$page_id = '148579325014720';

echo "=== SCANNING DATABASE CUSTOMERS FOR FACEBOOK LABELS ===\n\n";

try {
    // 1. Get access token
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page || !$page['access_token']) {
        exit("Page or Access Token not found in database.\n");
    }
    $token = decryptData($page['access_token']);
    
    // 2. Fetch last 50 customers for this page from the database
    $stmt_cust = $pdo->prepare("SELECT sender_id, name FROM fb_customers WHERE page_id = ? ORDER BY updated_at DESC LIMIT 50");
    $stmt_cust->execute([$page_id]);
    $customers = $stmt_cust->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($customers) . " customers to scan.\n\n";
    
    $found_any = false;
    foreach ($customers as $idx => $c) {
        $psid = $c['sender_id'];
        $name = $c['name'];
        
        echo "[$idx] Checking $name ($psid)... ";
        
        $endpoint = "{$psid}/custom_labels";
        $response = fb_api_request($endpoint, [
            'fields' => 'page_label_name',
            'access_token' => $token
        ], 'GET');
        
        if ($response['status_code'] === 200 && !empty($response['data']['data'])) {
            echo "SUCCESS!\n";
            echo "  Labels: " . json_encode($response['data']['data']) . "\n";
            $found_any = true;
            
            // Try updating the DB as a test
            foreach ($response['data']['data'] as $lbl) {
                $tag_name = $lbl['page_label_name'];
                echo "  -> Found tag: $tag_name\n";
            }
        } else {
            if ($response['status_code'] === 200) {
                echo "Empty labels (200 OK but no data)\n";
            } else {
                $msg = $response['data']['error']['message'] ?? 'Error';
                $code = $response['data']['error']['code'] ?? 0;
                $subcode = $response['data']['error']['error_subcode'] ?? 0;
                echo "Fail (Code $code, Sub $subcode: $msg)\n";
            }
        }
    }
    
    if (!$found_any) {
        echo "\nNo customers in the last 50 had any custom labels returned.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
