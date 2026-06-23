<?php
header('Content-Type: text/plain; charset=utf-8');

$videoId = '7487176921743199493';
$url = "https://api22-normal-c-alisg.tiktokv.com/aweme/v1/feed/?aweme_id=" . $videoId . "&iid=7318518857994389254&device_id=7318517321748022790&channel=googleplay&app_name=musical_ly&version_code=300904&device_platform=android&device_type=ASUS_Z01QD&os_version=9";

$app_ua = 'com.zhiliaoapp.musically/2022600030 (Linux; U; Android 7.1.2; ru_RU; Rootkit; Build/NJH47F; Cronet/TTNetVersion:b4d74d15 2020-04-23 QuicVersion:0144d138 2020-03-24)';
$browser_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36';

function test_request($url, $ua, $name) {
    echo "--- TESTING WITH: $name ---\n";
    echo "UA: $ua\n";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: $ua",
        "Referer: https://www.tiktok.com/",
        "Cookie: CykaBlyat=XD"
    ]);
    
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP Status Code: " . $info['http_code'] . "\n";
    if ($err) {
        echo "Curl Error: $err\n";
    }
    if ($resp) {
        $json = json_decode($resp, true);
        if (isset($json['aweme_list'][0])) {
            echo "-> SUCCESS: aweme_list is populated!\n";
            echo "Video Play Link: " . ($json['aweme_list'][0]['video']['play_addr']['url_list'][0] ?? 'N/A') . "\n";
        } else {
            echo "-> FAILED: aweme_list is empty.\n";
            echo "Response snippet: " . substr($resp, 0, 500) . "\n";
        }
    } else {
        echo "-> No response received.\n";
    }
    echo "\n";
}

test_request($url, $app_ua, "TIKTOK APP USER AGENT");
test_request($url, $browser_ua, "BROWSER USER AGENT (from tiktok_api.py)");
