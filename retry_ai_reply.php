<?php
// retry_ai_reply.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/ai_rewriter.php';

header('Content-Type: text/plain; charset=utf-8');

$sender_id = isset($_GET['sender_id']) ? trim($_GET['sender_id']) : '7190028664419646';
$page_id = isset($_GET['page_id']) ? trim($_GET['page_id']) : '2216507885096928';
$custom_text = isset($_GET['text']) ? trim($_GET['text']) : 'HCM';

echo "=== RETRYING AI REPLY FOR SENDER: $sender_id ON PAGE: $page_id ===\n\n";

// 1. Fetch customer details
$stmt_cust = $pdo->prepare("SELECT name, phone, province, notes, sales_phone, sales_notes FROM fb_customers WHERE page_id = ? AND sender_id = ?");
$stmt_cust->execute([$page_id, $sender_id]);
$cust_row = $stmt_cust->fetch(PDO::FETCH_ASSOC);

if (!$cust_row) {
    echo "ERROR: Customer not found in fb_customers database!\n";
    exit;
}

$sender_name = $cust_row['name'] ?? 'Khách hàng';
$cust_phone = $cust_row['phone'] ?? '';
$cust_province = $cust_row['province'] ?? '';
$cust_notes = $cust_row['notes'] ?? '';
$cust_sales_phone = $cust_row['sales_phone'] ?? '';
$cust_sales_notes = $cust_row['sales_notes'] ?? '';

echo "Customer Name: $sender_name\n";
echo "Current Phone: " . ($cust_phone ?: "None") . "\n";
echo "Current Province: " . ($cust_province ?: "None") . "\n";
echo "Current Requirements: " . ($cust_notes ?: "None") . "\n\n";

// 2. Fetch page token and account ID
$stmt_page = $pdo->prepare("SELECT p.name, p.access_token, u.account_id FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ?");
$stmt_page->execute([$page_id]);
$page_info = $stmt_page->fetch(PDO::FETCH_ASSOC);

if (!$page_info || empty($page_info['access_token'])) {
    echo "ERROR: Page or Access Token not found!\n";
    exit;
}

$page_name = $page_info['name'];
$page_token = decryptData($page_info['access_token']);
$acc_id = $page_info['account_id'];

// 3. Fetch active AI Reply rule
$st_ai = $pdo->prepare("SELECT * FROM bot_chat_rules WHERE account_id=? AND is_active=1 AND rule_type='ai_reply'");
$st_ai->execute([$acc_id]);
$ai_rules_all = $st_ai->fetchAll(PDO::FETCH_ASSOC);

$ai_rule = null;
foreach ($ai_rules_all as $r) {
    $match_scope = false;
    if ($r['pages_scope'] === 'ALL') {
        $match_scope = true;
    } else {
        $scope_arr = @json_decode($r['pages_scope'], true);
        if (is_array($scope_arr) && in_array($page_id, $scope_arr)) {
            $match_scope = true;
        }
    }
    if ($match_scope) {
        $ai_rule = $r;
        break;
    }
}

if (!$ai_rule) {
    echo "ERROR: No active AI Reply rule found for this page scope!\n";
    exit;
}

echo "AI Rule Found: ID={$ai_rule['id']}\n\n";

// 4. Fetch chat history (N messages)
$history_count = (int)($ai_rule['history_count'] ?? 6);
$history_text = '';
$conversation_id = '';

// Get conversation ID first
$stmt_conv = $pdo->prepare("SELECT conversation_id FROM fb_conversations WHERE page_id = ? AND sender_id = ?");
$stmt_conv->execute([$page_id, $sender_id]);
$conversation_id = $stmt_conv->fetchColumn();

if ($history_count > 0 && !empty($conversation_id)) {
    echo "Fetching recent chat history (Limit $history_count)... \n";
    $msg_res = fb_api_request($conversation_id . '/messages', [
        'fields' => 'message,from',
        'limit' => $history_count,
        'access_token' => $page_token
    ], 'GET');
    
    if ($msg_res['status_code'] === 200 && !empty($msg_res['data']['data'])) {
        $msgs = array_reverse($msg_res['data']['data']);
        foreach ($msgs as $m) {
            if (empty($m['message'])) continue;
            $role = ($m['from']['id'] === $page_id) ? "Bạn (Cửa hàng)" : "Khách hàng";
            $history_text .= "$role: " . $m['message'] . "\n";
        }
        echo "=== Chat History ===\n" . $history_text . "====================\n\n";
    } else {
        echo "Could not retrieve chat history from Facebook API. Status Code: {$msg_res['status_code']}\n\n";
    }
}

require_once __DIR__ . '/includes/bot_prompt_helper.php';
$custom_system_prompt = build_cop_pha_viet_system_prompt($ai_rule['message'] ?? '', $sender_name, $cust_phone, $cust_province, $cust_notes, $cust_sales_phone, $cust_sales_notes, $history_text);

echo "Calling OpenAI API... \n";
$ai_reply_text = generate_chat_reply_with_ai($custom_text, $custom_system_prompt, $acc_id, $page_name, $history_text);
echo "AI Raw Response:\n" . $ai_reply_text . "\n\n";

if (empty($ai_reply_text)) {
    echo "ERROR: AI generated empty response!\n";
    exit;
}

$ai_parsed = parse_ai_json_reply($ai_reply_text);
$reply_to_send = $ai_parsed['reply'];
$parsed_extracted = $ai_parsed['extracted'];

if (!empty($reply_to_send)) {
    $reply_to_send = filter_ai_reply_no_duplicate_asks($reply_to_send, $cust_phone, $cust_province, $cust_notes);
    echo "Parsed Reply message: \"$reply_to_send\"\n";
    
    // Extracted fields
    $ext_phone = $parsed_extracted['phone'] ?? null;
    $ext_prov = $parsed_extracted['province'] ?? null;
    $ext_notes = $parsed_extracted['requirements'] ?? null;
    $ext_stop = $parsed_extracted['stop_consulting'] ?? null;
    echo "Extracted Phone: " . ($ext_phone ?: "None") . "\n";
    echo "Extracted Province: " . ($ext_prov ?: "None") . "\n";
    echo "Extracted Requirements: " . ($ext_notes ?: "None") . "\n";
    echo "Extracted Stop Consulting: " . ($ext_stop ? "True" : "False") . "\n\n";
    
    // Perform database updates if any information was extracted
    $ai_upd_fields = [];
    $ai_upd_params = [];
    if (!empty($ext_phone) && $ext_phone !== $cust_phone) {
        $ai_upd_fields[] = "phone = ?";
        $ai_upd_params[] = $ext_phone;
    }
    if (!empty($ext_prov) && $ext_prov !== $cust_province) {
        $ai_upd_fields[] = "province = ?";
        $ai_upd_params[] = $ext_prov;
    }
    if (!empty($ext_notes) && $ext_notes !== $cust_notes) {
        $ai_upd_fields[] = "notes = ?";
        $ai_upd_params[] = $ext_notes;
    }
    if ($ext_stop === true) {
        $ai_upd_fields[] = "consulted = 3";
    }
    if (!empty($ai_upd_fields)) {
        $ai_upd_params[] = $page_id;
        $ai_upd_params[] = $sender_id;
        $st_upd = $pdo->prepare("UPDATE fb_customers SET " . implode(", ", $ai_upd_fields) . " WHERE page_id = ? AND sender_id = ?");
        $st_upd->execute($ai_upd_params);
        echo "Database updated with new extracted info.\n";
    }
}

if (empty($reply_to_send)) {
    echo "ERROR: Reply message text is empty!\n";
    exit;
}

// 6. Send the message using our new retrying function
echo "Sending message to Facebook Graph API...\n";
$url = "https://graph.facebook.com/v25.0/me/messages?access_token={$page_token}";
$post_data = json_encode([
    'recipient' => ['id' => $sender_id],
    'message' => ['text' => $reply_to_send],
    'messaging_type' => 'RESPONSE'
]);

$max_send_retries = 3;
$success = false;
$output = '';
$http_code = 0;
$err_msg = '';

for ($attempt = 1; $attempt <= $max_send_retries; $attempt++) {
    echo "Send Attempt $attempt... ";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $output = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($output === false) {
        $err_msg = curl_error($ch);
        curl_close($ch);
        echo "FAILED (cURL error: $err_msg)\n";
        sleep(1);
    } else {
        curl_close($ch);
        $res_data = json_decode($output, true);
        if ($http_code === 200 && !isset($res_data['error'])) {
            $success = true;
            echo "SUCCESS!\nResponse: $output\n";
            break;
        } else {
            $err_msg = $output;
            echo "FAILED (FB API error: Code=$http_code Response=$output)\n";
            if ($http_code >= 400 && $http_code < 500) {
                echo "Client error, breaking retry loop.\n";
                break;
            }
            sleep(1);
        }
    }
}

if ($success) {
    try {
        $st_upd = $pdo->prepare("UPDATE fb_customers SET last_sender = 'agent', last_message_at = CURRENT_TIMESTAMP WHERE page_id = ? AND sender_id = ?");
        $st_upd->execute([$page_id, $sender_id]);
    } catch (Exception $e) {}
    
    echo "\n=== RESULT: SUCCESS! Message successfully sent to customer and database updated. ===\n";
} else {
    echo "\n=== RESULT: FAILED! Could not send message to Facebook Graph API after $max_send_retries attempts. ===\n";
}

exit;
?>
