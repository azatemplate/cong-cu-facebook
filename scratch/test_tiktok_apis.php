<?php
// scratch/test_tiktok_apis.php

$videoId = '7647866772745489665';
$tiktokUrl = 'https://www.tiktok.com/@copphavietofficial/video/7647866772745489665';

echo "=== TESTING TIKTOK DOWNLOAD APIS ===\n\n";

// 1. Test Internal App API (Logic 1)
echo "1. Testing Logic 1: Internal App API (api22-normal...)\n";
$apiUrl = "https://api22-normal-c-useast1a.tiktokv.com/aweme/v1/feed/?aweme_id=" . $videoId . "&iid=7318518857994389254&device_id=7318517321748022790&channel=googleplay&app_name=musical_ly&version_code=300904&device_platform=android&device_type=ASUS_Z01QD&os_version=9";
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: com.zhiliaoapp.musically/2022600030 (Linux; U; Android 7.1.2; ru_RU; Rootkit; Build/NJH47F; Cronet/TTNetVersion:b4d74d15 2020-04-23 QuicVersion:0144d138 2020-03-24)'
]);
$res1 = curl_exec($ch);
$info1 = curl_getinfo($ch);
curl_close($ch);

echo "HTTP Code: " . $info1['http_code'] . "\n";
if ($res1) {
    $data1 = json_decode($res1, true);
    if (isset($data1['aweme_list'][0])) {
        $video = $data1['aweme_list'][0]['video'] ?? [];
        $playAddr = $video['play_addr']['url_list'][0] ?? $video['download_addr']['url_list'][0] ?? null;
        echo "SUCCESS! Direct video URL: " . substr($playAddr, 0, 80) . "...\n";
    } else {
        echo "FAILED: aweme_list[0] is empty or not found in response.\n";
        echo "Response snippet: " . substr($res1, 0, 300) . "\n";
    }
} else {
    echo "FAILED: No response received.\n";
}

echo "\n--------------------------------------------------\n\n";

// 2. Test TikWM API (Logic 2)
echo "2. Testing Logic 2: TikWM API\n";
$tikwm_url = 'https://www.tikwm.com/api/?url=' . urlencode($tiktokUrl);
$ch = curl_init($tikwm_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
    'Referer: https://tikwm.com/',
    'Origin: https://tikwm.com',
    'Accept: application/json, text/plain, */*'
]);
$res2 = curl_exec($ch);
$info2 = curl_getinfo($ch);
curl_close($ch);

echo "HTTP Code: " . $info2['http_code'] . "\n";
if ($res2) {
    $data2 = json_decode($res2, true);
    if ($data2 && isset($data2['code']) && $data2['code'] === 0 && isset($data2['data']['play'])) {
        echo "SUCCESS! Direct video URL: " . substr($data2['data']['play'], 0, 80) . "...\n";
    } else {
        echo "FAILED: API returned error code or invalid structure.\n";
        echo "Response snippet: " . substr($res2, 0, 300) . "\n";
    }
} else {
    echo "FAILED: No response received.\n";
}

echo "\n=== END OF TESTING ===\n";
