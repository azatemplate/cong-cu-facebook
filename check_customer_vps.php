<?php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$sender_id = '26510657095243601';

echo "=== DIAGNOSTICS FOR SENDER ID: $sender_id ===\n\n";

// 1. fb_customers
echo "--- fb_customers ---\n";
$stmt = $pdo->prepare("SELECT * FROM fb_customers WHERE sender_id = ?");
$stmt->execute([$sender_id]);
$cust = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($cust);

// 2. bot_chat_locks
echo "\n--- bot_chat_locks ---\n";
$stmt = $pdo->prepare("SELECT * FROM bot_chat_locks WHERE sender_id = ?");
$stmt->execute([$sender_id]);
$locks = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($locks);

// 3. Page Info & Bot Rules
if (!empty($cust)) {
    $page_id = $cust[0]['page_id'];
    $stmt = $pdo->prepare("SELECT p.name, u.account_id FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ?");
    $stmt->execute([$page_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\n--- Page Info ---\n";
    print_r($page);
    
    if ($page) {
        $acc_id = $page['account_id'];
        echo "\n--- bot_chat_rules for account $acc_id ---\n";
        $stmt = $pdo->prepare("SELECT id, rule_type, is_active, keywords, delay_seconds, history_count, pages_scope, message FROM bot_chat_rules WHERE account_id = ?");
        $stmt->execute([$acc_id]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        print_r($rules);
    }
}

// 4. page_notifications
echo "\n--- page_notifications (last 20 messages) ---\n";
$stmt = $pdo->prepare("SELECT id, type, sender_name, snippet, created_at FROM page_notifications WHERE sender_id = ? ORDER BY id DESC LIMIT 20");
$stmt->execute([$sender_id]);
$msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($msgs);

// 5. webhook logs
echo "\n--- webhook_db_errors.txt logs matching sender ---\n";
$log_file = __DIR__ . '/webhook_db_errors.txt';
if (file_exists($log_file)) {
    $lines = file($log_file);
    $matched = 0;
    foreach (array_reverse($lines) as $line) {
        if (strpos($line, $sender_id) !== false) {
            echo $line;
            $matched++;
            if ($matched >= 50) break;
        }
    }
    if ($matched === 0) {
        echo "No log entries found for this sender ID.\n";
    }
} else {
    echo "Log file webhook_db_errors.txt does not exist.\n";
}

// 6. AI debug logs (error_log)
echo "\n--- error_log (AI logs) ---\n";
$ai_log_file = __DIR__ . '/error_log';
if (file_exists($ai_log_file)) {
    $lines = file($ai_log_file);
    $matched = 0;
    foreach (array_reverse($lines) as $line) {
        echo $line;
        $matched++;
        if ($matched >= 50) break;
    }
    if ($matched === 0) {
        echo "No AI log entries found.\n";
    }
} else {
    echo "AI log file error_log does not exist.\n";
}

// 7. ai_usage_logs
if (isset($acc_id)) {
    echo "\n--- ai_usage_logs (last 10 logs) ---\n";
    $stmt = $pdo->prepare("SELECT id, provider, feature, prompt_length, response_length, status, error_message, created_at FROM ai_usage_logs WHERE account_id = ? ORDER BY id DESC LIMIT 10");
    $stmt->execute([$acc_id]);
    $ai_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($ai_logs);
}
