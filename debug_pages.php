<?php
// debug_pages.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

header('Content-Type: text/plain; charset=utf-8');

$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : 2;

echo "=== DIAGNOSING PAGES AND TOKENS FOR ACCOUNT ID: $account_id ===\n\n";

try {
    $stmt = $pdo->prepare("
        SELECT p.page_id, p.name as page_name, p.access_token as encrypted_token, u.id as user_db_id, u.name as user_name, u.fb_id as user_fb_id 
        FROM pages p 
        LEFT JOIN users u ON p.user_id = u.id
        WHERE u.account_id = ?
    ");
    $stmt->execute([$account_id]);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($pages) . " pages in DB for Account ID $account_id.\n";
    echo str_repeat("-", 80) . "\n";

    foreach ($pages as $p) {
        $page_id = $p['page_id'];
        $page_name = $p['page_name'];
        $user_name = $p['user_name'];
        $user_db_id = $p['user_db_id'];
        
        $token = decryptData($p['encrypted_token']);
        
        echo "Page Name: $page_name (ID: $page_id)\n";
        echo "Token owner in DB: $user_name (User DB ID: $user_db_id, FB ID: {$p['user_fb_id']})\n";
        
        if (empty($token)) {
            echo "→ ERROR: Token is EMPTY in database!\n";
            echo str_repeat("-", 80) . "\n";
            continue;
        }

        // Test token validity with Facebook Graph API
        $url = "https://graph.facebook.com/v20.0/me?fields=id,name&access_token=" . urlencode($token);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "→ FB API Response Code: $http_code\n";
        if ($http_code === 200) {
            $data = json_decode($res, true);
            echo "→ Token is VALID. Logged in as Page: {$data['name']} (ID: {$data['id']})\n";
        } else {
            echo "→ Token is INVALID! Response: $res\n";
        }
        echo str_repeat("-", 80) . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
