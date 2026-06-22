<?php
// scratch/debug_tiktok.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

$tiktok_url = 'https://www.tiktok.com/@tomvuive_official/video/7487176921743199493';
echo "Debugging TikTok retrieval for URL: $tiktok_url\n\n";

// Fetch settings
$sys_tiktok_api_url = '';
try {
    $ss_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'tiktok_api_url'");
    if ($ss_stmt) {
        $sys_tiktok_api_url = trim($ss_stmt->fetchColumn() ?: '');
    }
} catch (Exception $e) {}

echo "System TikTok API URL: '$sys_tiktok_api_url'\n\n";

// Replicating fetch_tiktok_info step-by-step with output
$tikwm_fallback_data = null;

// ==================== Logic 0: Custom API ====================
if (!empty($sys_tiktok_api_url)) {
    $api_target = $sys_tiktok_api_url;
    if (strpos($api_target, '?') === false) {
        $api_target = rtrim($api_target, '/') . '/api/hybrid/video_data?url=' . urlencode($tiktok_url);
    } else {
        if (strpos($api_target, 'url=') === false) {
            $api_target .= (strpos($api_target, '&') === false ? '' : '&') . 'url=' . urlencode($tiktok_url);
        }
    }
    
    echo "--- LOGIC 0: Custom API ---\n";
    echo "Target URL: $api_target\n";
    
    $ch = curl_init($api_target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    echo "HTTP Status Code: " . $info['http_code'] . "\n";
    if ($err) {
        echo "Curl Error: $err\n";
    }
    
    if ($resp) {
        echo "Response length: " . strlen($resp) . "\n";
        $json = json_decode($resp, true);
        if ($json && isset($json['data'])) {
            $download_url = null;
            $hdAddr = $json['data']['video_data']['nwm_video_url_hd'] ?? null;
            $fhdAddr = $json['data']['video_data']['nwm_video_url_fhd'] ?? null;

            if (!empty($fhdAddr)) {
                $download_url = $fhdAddr;
            } elseif (!empty($hdAddr)) {
                $download_url = $hdAddr;
            } elseif (!empty($json['data']['play'])) {
                $download_url = $json['data']['play'];
            } elseif (!empty($json['data']['video_data']['nwm_video_url_HQ'])) {
                $download_url = $json['data']['video_data']['nwm_video_url_HQ'];
            } elseif (!empty($json['data']['video_data']['nwm_video_url'])) {
                $download_url = $json['data']['video_data']['nwm_video_url'];
            }
            
            echo "Extracted download_url: '$download_url'\n";
            
            if (!empty($download_url)) {
                $res_data = [
                    'download_url' => $download_url,
                    'title'        => $json['data']['desc'] ?? 'tiktok_video',
                    'video_id'     => $json['data']['id'] ?? null,
                ];
                
                $source = $json['data']['source'] ?? 'custom_api';
                echo "Source from Custom API: $source\n";
                if ($source !== 'tikwm') {
                    echo "-> SUCCESS IN LOGIC 0 (Official API)\n";
                } else {
                    echo "-> Custom API returned TIKWM source. Storing as fallback.\n";
                    $tikwm_fallback_data = $res_data;
                }
            } else {
                echo "-> Failed to parse download_url from response.\n";
            }
        } else {
            echo "-> JSON decode failed or 'data' key not found in response: " . substr($resp, 0, 500) . "\n";
        }
    } else {
        echo "-> No response from Custom API.\n";
    }
} else {
    echo "--- LOGIC 0: Custom API URL is empty, skipping ---\n";
}

echo "\n";

// ==================== Logic 1: API App nội bộ ====================
echo "--- LOGIC 1: Direct PHP API ---\n";
preg_match('/video\/(\d+)/', $tiktok_url, $match);
$videoId = $match[1] ?? '';
echo "Video ID: $videoId\n";

if (!empty($videoId)) {
    // getApi22Data logic
    $domains = [
        "api22-normal-c-useast1a.tiktokv.com",
        "api22-normal-c-alisg.tiktokv.com",
        "api16-normal-c-useast1a.tiktokv.com"
    ];
    
    $apiData = null;
    foreach ($domains as $domain) {
        $apiUrl = "https://" . $domain . "/aweme/v1/feed/?aweme_id=" . $videoId . "&iid=7318518857994389254&device_id=7318517321748022790&channel=googleplay&app_name=musical_ly&version_code=300904&device_platform=android&device_type=ASUS_Z01QD&os_version=9";
        echo "Trying domain: $domain\n";
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: com.zhiliaoapp.musically/2022600030 (Linux; U; Android 7.1.2; ru_RU; Rootkit; Build/NJH47F; Cronet/TTNetVersion:b4d74d15 2020-04-23 QuicVersion:0144d138 2020-03-24)'
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($res) {
            $data = json_decode($res, true);
            if (isset($data['aweme_list'][0])) {
                echo "-> Success on $domain\n";
                $apiData = $data['aweme_list'][0];
                break;
            } else {
                echo "-> aweme_list is empty on $domain\n";
            }
        } else {
            echo "-> No response or error on $domain: $err\n";
        }
    }
    
    if ($apiData) {
        $video = $apiData['video'] ?? [];
        $playAddr = $video['play_addr']['url_list'][0] ?? null;
        echo "Direct playAddr: $playAddr\n";
    }
}

echo "\n";

// ==================== Logic 2: TikWM API GET ====================
echo "--- LOGIC 2: TikWM direct cURL ---\n";
$tikwm_url = 'https://www.tikwm.com/api/?url=' . urlencode($tiktok_url);
$ch = curl_init($tikwm_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'
]);
$resp = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($resp) {
    $data = json_decode($resp, true);
    if ($data && isset($data['code']) && $data['code'] === 0 && isset($data['data'])) {
        echo "TikWM response success. Play URL: " . $data['data']['play'] . "\n";
    } else {
        echo "TikWM response error or code != 0: " . substr($resp, 0, 500) . "\n";
    }
} else {
    echo "No response from TikWM direct cURL. Error: $err\n";
}

echo "\n";
echo "--- FINAL STATUS ---\n";
if ($tikwm_fallback_data) {
    echo "Fallback data is set! URL: " . $tikwm_fallback_data['download_url'] . "\n";
} else {
    echo "Fallback data is NULL\n";
}
