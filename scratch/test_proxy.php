<?php
header('Content-Type: text/plain; charset=utf-8');

$master_key = "AyrLPrQDMOIujeiSAfixaG";
$keys_url = "https://proxy.vn/proxyxoay/apigetkeyxoay.php?key=" . $master_key;

echo "=== KIỂM TRA DANH SÁCH KEYS XOAY ===\n";
echo "Fetching keys from: $keys_url\n\n";

$ch = curl_init($keys_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
curl_close($ch);

if (!$resp) {
    echo "Error: Failed to fetch keys list.\n";
    exit;
}

echo "Raw response from apigetkeyxoay.php:\n$resp\n\n";

preg_match_all('/\{[^{}]+\}/', $resp, $matches);
$keys = [];
foreach ($matches[0] as $m) {
    $data = json_decode($m, true);
    if ($data && isset($data['keyxoay'])) {
        $keys[] = $data;
    }
}

if (empty($keys)) {
    echo "No keys extracted from response.\n";
    exit;
}

echo "Extracted " . count($keys) . " keys:\n";
foreach ($keys as $k) {
    echo "- Key: " . $k['keyxoay'] . " (Expired: " . ($k['expired'] ?? 'N/A') . ")\n";
}
echo "\n";

echo "=== KIỂM TRA TỪNG KEY XOAY QUA PROXYXOAY.SHOP ===\n";
foreach ($keys as $k) {
    $key = $k['keyxoay'];
    $get_url = "https://proxyxoay.shop/api/get.php?key=" . $key . "&&nhamang=random&&tinhthanh=0&whitelist=";
    echo "Testing key '$key'...\n";
    echo "URL: $get_url\n";
    
    $ch = curl_init($get_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    echo "HTTP Code: " . $info['http_code'] . "\n";
    echo "Raw Response: $res\n\n";
}
