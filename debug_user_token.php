<?php
// debug_user_token.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

header('Content-Type: text/plain; charset=utf-8');

$user_db_id = 26; // DB ID of Nam Nhi

echo "=== DIAGNOSING USER ACCESS TOKEN FOR USER DB ID $user_db_id ===\n\n";

try {
    $stmt = $pdo->prepare("SELECT name, fb_id, access_token FROM users WHERE id = ?");
    $stmt->execute([$user_db_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        echo "ERROR: User not found in DB!\n";
        exit;
    }

    $token = decryptData($u['access_token']);
    echo "User Name in DB: {$u['name']} (FB ID: {$u['fb_id']})\n";
    
    if (empty($token)) {
        echo "ERROR: User Access Token is empty!\n";
        exit;
    }

    echo "User Token Snippet: " . substr($token, 0, 15) . "..." . substr($token, -10) . "\n\n";

    // 1. Check /me with User Token
    echo "--- 1. Checking /me (User Profile) ---\n";
    $url = "https://graph.facebook.com/v20.0/me?fields=id,name,email&access_token=" . urlencode($token);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Code: $http_code\n";
    echo "Response: $res\n\n";

    // 2. Check /me/accounts with User Token to see what Pages are returned and if they have tokens
    echo "--- 2. Checking /me/accounts (User Pages) ---\n";
    $url = "https://graph.facebook.com/v20.0/me/accounts?limit=100&access_token=" . urlencode($token);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Code: $http_code\n";
    if ($http_code === 200) {
        $data = json_decode($res, true);
        $pages = $data['data'] ?? [];
        echo "Found " . count($pages) . " pages returned by FB:\n";
        foreach ($pages as $idx => $p) {
            $p_name = $p['name'];
            $p_id = $p['id'];
            $has_token = isset($p['access_token']) ? "YES" : "NO";
            $p_token_snippet = isset($p['access_token']) ? (substr($p['access_token'], 0, 10) . "...") : 'NONE';
            $perms = isset($p['tasks']) ? implode(', ', $p['tasks']) : (isset($p['perms']) ? implode(', ', $p['perms']) : 'NONE');
            
            // Check specifically for the requested Page IDs
            $highlight = ($p_id === '154948301041364' || $p_id === '856231941405644') ? "★★ TARGET ★★ " : "";
            
            echo sprintf("%s[%d] %s (ID: %s) | Has Token: %s (%s) | Perms: %s\n", $highlight, $idx + 1, $p_name, $p_id, $has_token, $p_token_snippet, $perms);
        }
    } else {
        echo "Response: $res\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
