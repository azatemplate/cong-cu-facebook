<?php
// debug_scan_output.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
header('Content-Type: text/plain; charset=utf-8');

$sender_id = '9999863946738529';
$page_id = '148579325014720';
$conv_id = 't_4059222304322454';

echo "=== TRACING SCAN_OLD_PHONES LOGIC FOR XÂY DỰNG THÁI KHOA ===\n\n";

try {
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $token = decryptData($stmt->fetchColumn());
    
    $params = [
        'fields' => 'message,from',
        'access_token' => $token,
        'limit' => 50
    ];
    
    $response = fb_api_request($conv_id . '/messages', $params, 'GET');
    
    if ($response['status_code'] === 200 && !empty($response['data']['data'])) {
        $messages = $response['data']['data'];
        echo "Successfully fetched " . count($messages) . " messages.\n\n";
        
        $phone_found = false;
        foreach ($messages as $idx => $msg) {
            $from_id = $msg['from']['id'] ?? '';
            $from_name = $msg['from']['name'] ?? 'Unknown';
            $msg_text = $msg['message'] ?? '';
            
            echo "[Message #$idx]\n";
            echo "  Sender Name: $from_name\n";
            echo "  Sender ID: '$from_id' (Type: " . gettype($from_id) . ")\n";
            echo "  Page ID (pid): '$page_id' (Type: " . gettype($page_id) . ")\n";
            
            // Check condition: if ($from_id !== $pid)
            $is_not_page = ($from_id !== $page_id);
            echo "  Condition (\$from_id !== \$pid) is: " . ($is_not_page ? "TRUE" : "FALSE") . "\n";
            
            if ($is_not_page) {
                echo "  Message Text: \"$msg_text\"\n";
                
                // Call extract_phone_number logic
                $clean = preg_replace('/[\s.\-_]+/', '', $msg_text);
                echo "    Cleaned text: \"$clean\"\n";
                
                $phone = null;
                if (preg_match('/(?:\+84|84|0)(3|5|7|8|9)\d{8}\b/', $clean, $matches)) {
                    $phone = $matches[0];
                    if (strpos($phone, '+84') === 0) {
                        $phone = '0' . substr($phone, 3);
                    } elseif (strpos($phone, '84') === 0 && strlen($phone) === 11) {
                        $phone = '0' . substr($phone, 2);
                    }
                }
                
                echo "    Extracted Phone: " . ($phone ?: "NONE") . "\n";
                
                if ($phone) {
                    echo "    >>> WOULD UPDATE DB WITH PHONE: $phone <<<\n";
                    $phone_found = true;
                    break;
                }
            } else {
                echo "  Skipped because sender is the Page.\n";
            }
            echo "\n";
        }
        
        if (!$phone_found) {
            echo "=== RESULT: NO PHONE FOUND, WOULD MARK AS SCANNED ===\n";
        } else {
            echo "=== RESULT: PHONE FOUND! ===\n";
        }
        
    } else {
        echo "Error calling API. Response:\n";
        print_r($response);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
