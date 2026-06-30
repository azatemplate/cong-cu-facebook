<?php
// test_specific_pages.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

header('Content-Type: text/plain; charset=utf-8');

$page_ids = ['154948301041364', '856231941405644'];

echo "=== TESTING SPECIFIC PAGE TOKENS ===\n\n";

foreach ($page_ids as $page_id) {
    echo "Testing Page ID: $page_id\n";
    
    // 1. Fetch from DB
    $stmt = $pdo->prepare("SELECT p.name as page_name, p.access_token as encrypted_token, u.name as user_name FROM pages p LEFT JOIN users u ON p.user_id = u.id WHERE p.page_id = ?");
    $stmt->execute([$page_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$p) {
        echo "→ ERROR: Page not found in database!\n";
        echo str_repeat("-", 80) . "\n";
        continue;
    }
    
    $token = decryptData($p['encrypted_token']);
    echo "→ Page Name in DB: {$p['page_name']}\n";
    echo "→ Token Owner in DB: {$p['user_name']}\n";
    
    if (empty($token)) {
        echo "→ ERROR: Token is empty!\n";
        echo str_repeat("-", 80) . "\n";
        continue;
    }
    
    $token_snippet = substr($token, 0, 15) . "..." . substr($token, -10);
    echo "→ Token Snippet: $token_snippet\n";
    
    // 2. Query /me to check token info
    echo "→ Checking /me endpoint...\n";
    $url = "https://graph.facebook.com/v20.0/me?fields=id,name,accounts&access_token=" . urlencode($token);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "  → HTTP Code: $http_code\n";
    echo "  → Response: $res\n";
    
    // 3. Query /{page_id}/feed to test posting permission check
    echo "→ Checking Page Feed read permissions...\n";
    $url = "https://graph.facebook.com/v20.0/$page_id/feed?limit=1&access_token=" . urlencode($token);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "  → HTTP Code: $http_code\n";
    echo "  → Response: $res\n";
    
    echo str_repeat("-", 80) . "\n";
}
