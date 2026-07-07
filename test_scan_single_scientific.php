<?php
// test_scan_single_scientific.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
header('Content-Type: text/plain; charset=utf-8');

$sender_id = '9999863946738529';
echo "Analyzing 'Xây Dựng Thái Khoa' (ID: $sender_id)\n\n";

try {
    // Find conversation in DB
    $stmt = $pdo->prepare("SELECT * FROM fb_conversations WHERE sender_id = ?");
    $stmt->execute([$sender_id]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conv) {
        // Search in fb_customers
        $stmt_cust = $pdo->prepare("SELECT * FROM fb_customers WHERE sender_id = ?");
        $stmt_cust->execute([$sender_id]);
        $cust = $stmt_cust->fetch(PDO::FETCH_ASSOC);
        
        if ($cust) {
            echo "Found in fb_customers:\n";
            print_r($cust);
            $page_id = $cust['page_id'];
            
            // Try to find conversation ID from Graph API
            $stmt_token = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
            $stmt_token->execute([$page_id]);
            $token = decryptData($stmt_token->fetchColumn());
            
            $conv_url = "https://graph.facebook.com/v25.0/me/conversations?user_id=" . urlencode($sender_id) . "&access_token=" . urlencode($token);
            $ch = curl_init($conv_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            fb_curl_setssl($ch);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            if (!empty($res['data'])) {
                $conv_id = $res['data'][0]['id'];
                echo "Found Conversation ID from Facebook API: $conv_id\n\n";
            } else {
                echo "Error: No conversation found on Facebook API. Full response:\n";
                print_r($res);
                exit;
            }
        } else {
            echo "Error: Customer not found anywhere in database.\n";
            exit;
        }
    } else {
        $conv_id = $conv['conversation_id'];
        $page_id = $conv['page_id'];
        echo "Found in fb_conversations:\n";
        print_r($conv);
    }
    
    // Get page access token
    $stmt_token = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt_token->execute([$page_id]);
    $token = decryptData($stmt_token->fetchColumn());
    
    // Fetch 50 messages
    $params = [
        'fields' => 'message,from',
        'access_token' => $token,
        'limit' => 50
    ];
    $response = fb_api_request($conv_id . '/messages', $params, 'GET');
    
    if ($response['status_code'] === 200 && !empty($response['data']['data'])) {
        $messages = $response['data']['data'];
        echo "Total messages fetched: " . count($messages) . "\n\n";
        
        foreach ($messages as $msg) {
            $from_name = $msg['from']['name'] ?? 'Unknown';
            $from_id = $msg['from']['id'] ?? '';
            $msg_text = $msg['message'] ?? '';
            
            if ($from_id !== $page_id) {
                echo "CUSTOMER ($from_name): \"$msg_text\"\n";
                
                // standard cleaning
                $clean = preg_replace('/[\s.\-_]+/', '', $msg_text);
                echo "  Standard Cleaned: $clean\n";
                
                // Advanced cleaning (remove all non-digits except +)
                $digits_only = preg_replace('/[^0-9+]/', '', $msg_text);
                echo "  Digits Only: $digits_only\n";
                
                // Test current regex: /(?:\+84|84|0)(3|5|7|8|9)\d{8}\b/
                $phone = null;
                if (preg_match('/(?:\+84|84|0)(3|5|7|8|9)\d{8}\b/', $clean, $matches)) {
                    $phone = $matches[0];
                }
                echo "  Current Regex Match: " . ($phone ?: "FAILED") . "\n";
                
                // Test broader regex: /(?:\+84|84|0)(3|5|7|8|9)[0-9]{8}/
                $phone_broad = null;
                if (preg_match('/(?:\+84|84|0)(3|5|7|8|9)[0-9]{8}/', $clean, $matches_broad)) {
                    $phone_broad = $matches_broad[0];
                }
                echo "  Broader Regex Match (no word boundary): " . ($phone_broad ?: "FAILED") . "\n";
                
                // Test digits only regex
                $phone_digits = null;
                if (preg_match('/(?:\+84|84|0)(3|5|7|8|9)[0-9]{8}/', $digits_only, $matches_digits)) {
                    $phone_digits = $matches_digits[0];
                }
                echo "  Digits-only Regex Match: " . ($phone_digits ?: "FAILED") . "\n";
                echo "\n";
            } else {
                echo "PAGE: \"$msg_text\"\n\n";
            }
        }
    } else {
        echo "API Call failed or no messages. Response:\n";
        print_r($response);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
