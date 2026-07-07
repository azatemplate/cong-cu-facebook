<?php
// test_all_convs.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$page_id = '148579325014720';

echo "=== SCANNING LAST 20 CONVERSATIONS FOR CUSTOM LABELS AND TAGS ===\n\n";

try {
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page || !$page['access_token']) {
        exit("Page or Access Token not found in database.\n");
    }
    
    $token = decryptData($page['access_token']);
    echo "Decrypted Page Token successfully.\n\n";
    
    $endpoint = "{$page_id}/conversations";
    $params = [
        'fields' => 'id,updated_time,unread_count,tags{name},participants{id,name,email,custom_labels{page_label_name,id}}',
        'limit' => 20,
        'access_token' => $token
    ];
    
    $response = fb_api_request($endpoint, $params, 'GET');
    echo "API HTTP Code: " . $response['status_code'] . "\n\n";
    
    if (empty($response['data']['data'])) {
        exit("No conversations found.\n");
    }
    
    $conversations = $response['data']['data'];
    foreach ($conversations as $c) {
        $conv_id = $c['id'];
        $updated_time = $c['updated_time'];
        
        $sender_name = '';
        $sender_id = '';
        $has_labels = false;
        $labels_dump = '';
        
        if (isset($c['participants']['data'])) {
            foreach ($c['participants']['data'] as $p) {
                if ($p['id'] !== $page_id) {
                    $sender_name = $p['name'];
                    $sender_id = $p['id'];
                    if (isset($p['custom_labels'])) {
                        $has_labels = true;
                        $labels_dump = json_encode($p['custom_labels']);
                    }
                    break;
                }
            }
        }
        
        $tags_dump = isset($c['tags']) ? json_encode($c['tags']) : 'None';
        
        echo "Conv ID: $conv_id | Customer: $sender_name ($sender_id) | Updated: $updated_time\n";
        echo "  - tags field: $tags_dump\n";
        echo "  - participants.custom_labels: " . ($has_labels ? $labels_dump : "None") . "\n";
        echo str_repeat("-", 80) . "\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
