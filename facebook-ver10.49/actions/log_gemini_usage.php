<?php
// actions/log_gemini_usage.php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// We accept both POST form-data and application/json
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$key = $input['key'] ?? '';
$feature = $input['feature'] ?? 'External API Client';
$prompt_len = intval($input['prompt_length'] ?? 0);
$resp_len = intval($input['response_length'] ?? 0);
$status = $input['status'] ?? 'success';
$err_msg = $input['error_message'] ?? null;
$ip_address = $input['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1 (Hệ thống)';
$user_agent = $input['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'System Cron / CLI';

try {
    $account_id = null;
    $username = null;

    if (!empty($key) && strpos($key, 'sk-hvp-') === 0) {
        // Find matching key in gemini_keys
        $stmt = $pdo->prepare("SELECT description FROM gemini_keys WHERE api_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $username = 'Key: ' . ($row['description'] ?: substr($key, 0, 12));
        }
    } else if (!empty($key)) {
        // Search in ai_configs to see whose cookie matches
        $stmt = $pdo->query("SELECT account_id, api_keys FROM ai_configs WHERE provider = 'HongHub AI'");
        $cfgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cfgs as $cfg) {
            $decrypted = decryptData($cfg['api_keys']);
            if (strpos($decrypted, $key) !== false || strpos($key, $decrypted) !== false) {
                $account_id = $cfg['account_id'];
                break;
            }
        }
    }

    if ($account_id) {
        $u_stmt = $pdo->prepare("SELECT username FROM system_accounts WHERE id = ? LIMIT 1");
        $u_stmt->execute([$account_id]);
        $username = $u_stmt->fetchColumn();
    }

    if (empty($username)) {
        $username = !empty($key) ? 'External (' . substr($key, 0, 10) . '...)' : 'Unknown';
    }

    $log_stmt = $pdo->prepare("
        INSERT INTO ai_usage_logs (account_id, username, provider, feature, prompt_length, response_length, status, error_message, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $log_stmt->execute([
        $account_id,
        $username,
        'HongHub AI',
        $feature,
        $prompt_len,
        $resp_len,
        $status,
        $err_msg,
        $ip_address,
        $user_agent
    ]);

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
