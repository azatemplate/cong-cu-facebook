<?php
// check_customer_scan.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$sender_id = '25783021141400194';

echo "=== DIAGNOSING SCANNER FOR SENDER $sender_id ===\n\n";

try {
    // 1. Fetch customer details
    $stmt_cust = $pdo->prepare("SELECT * FROM fb_customers WHERE sender_id = ?");
    $stmt_cust->execute([$sender_id]);
    $customer = $stmt_cust->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        exit("Customer NOT found in fb_customers.\n");
    } else {
        echo "Customer details in fb_customers:\n";
        print_r($customer);
    }
    
    $page_id = $customer['page_id'];
    
    // 2. Fetch conversation_id
    $stmt_conv = $pdo->prepare("SELECT * FROM fb_conversations WHERE page_id = ? AND sender_id = ?");
    $stmt_conv->execute([$page_id, $sender_id]);
    $conv = $stmt_conv->fetch(PDO::FETCH_ASSOC);
    
    if (!$conv) {
        exit("Conversation NOT found in fb_conversations.\n");
    } else {
        echo "\nConversation details:\n";
        print_r($conv);
    }
    
    $conv_id = $conv['conversation_id'];
    
    // 3. Fetch Page token
    $stmt_token = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt_token->execute([$page_id]);
    $page_token_raw = $stmt_token->fetchColumn();
    if (!$page_token_raw) {
        exit("Page token NOT found.\n");
    }
    
    $token = decryptData($page_token_raw);
    echo "\nDecrypted Page Token successfully.\n\n";
    
    // 4. Fetch messages from Facebook Graph API
    $params = [
        'fields' => 'id,message,from,created_time',
        'limit' => 50,
        'access_token' => $token
    ];
    $response = fb_api_request($conv_id . '/messages', $params, 'GET');
    
    if ($response['status_code'] !== 200) {
        exit("Facebook API Error: " . json_encode($response));
    }
    
    $messages = $response['data']['data'] ?? [];
    echo "Fetched " . count($messages) . " messages.\n\n";
    
    foreach ($messages as $msg) {
        $from_id = $msg['from']['id'] ?? '';
        $from_name = $msg['from']['name'] ?? '';
        $msg_text = $msg['message'] ?? '';
        
        $is_page = ($from_id == $page_id);
        $is_customer = ($from_id == $sender_id);
        
        echo "Message ID: " . $msg['id'] . "\n";
        echo "Time: " . $msg['created_time'] . "\n";
        echo "From: $from_name (ID: $from_id)\n";
        echo "  Is Page (== $page_id)? " . ($is_page ? 'YES' : 'NO') . "\n";
        echo "  Is Page (=== $page_id strict)? " . (($from_id === $page_id) ? 'YES' : 'NO') . "\n";
        echo "  Is Customer (== $sender_id)? " . ($is_customer ? 'YES' : 'NO') . "\n";
        echo "Text: " . $msg_text . "\n";
        
        // Test extraction
        $extracted_phone = '';
        if (preg_match('/(03|05|07|08|09)+([0-9]{8})\b/', $msg_text, $matches)) {
            $extracted_phone = $matches[0];
        }
        echo "  Extracted Phone: " . ($extracted_phone ?: 'None') . "\n";
        echo "----------------------------------------\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
