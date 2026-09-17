<?php
header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['url']) || empty($_GET['url'])) {
    http_response_code(400);
    echo json_encode(['code' => -1, 'msg' => 'Thiếu tham số url']);
    exit;
}

$tiktok_url = trim($_GET['url']);

// ==================== LẤY COOKIES TỪ WEB ====================
function getTikTokCookies($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true); // Chỉ lấy Header cho nhanh, không tải nguyên trang HTML nữa
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $cookies = [];
    if ($response) {
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
        $cookies = array_map('trim', $matches[1]);
    }

    return implode('; ', $cookies);
}

// ==================== LẤY DỮ LIỆU TỪ API APP (LINK TRỰC TIẾP KHÔNG CẦN COOKIE) ====================
function getApi22Data($videoId) {
    if (empty($videoId)) return null;
    $apiUrl = "https://api22-normal-c-useast1a.tiktokv.com/aweme/v1/feed/?aweme_id=" . $videoId . "&iid=7318518857994389254&device_id=7318517321748022790&channel=googleplay&app_name=musical_ly&version_code=300904&device_platform=android&device_type=ASUS_Z01QD&os_version=9";
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: com.zhiliaoapp.musically/2022600030 (Linux; U; Android 7.1.2; ru_RU; Rootkit; Build/NJH47F; Cronet/TTNetVersion:b4d74d15 2020-04-23 QuicVersion:0144d138 2020-03-24)'
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res) {
        $data = json_decode($res, true);
        if (isset($data['aweme_list'][0])) {
            return $data['aweme_list'][0];
        }
    }
    return null;
}

// ==================== CHẠY CODE ====================
// 1. Tách ID video từ URL
preg_match('/video\/(\d+)/', $tiktok_url, $match);
$videoId = $match[1] ?? '';

if (empty($videoId)) {
    // Nếu URL không có ID (ví dụ link rút gọn v.t.tiktok.com), ta cần lấy ID bằng cách phân giải URL
    $ch = curl_init($tiktok_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    preg_match('/video\/(\d+)/', $finalUrl, $match);
    $videoId = $match[1] ?? '';
    if (empty($videoId)) {
        preg_match('/v=(\d+)/', $finalUrl, $match);
        $videoId = $match[1] ?? '';
    }
}

// 2. Lấy cookies (Dù link trực tiếp không cần cookie, vẫn giữ lại để tương thích form cũ)
$cookies = getTikTokCookies($tiktok_url);

// 3. Lấy dữ liệu video từ App API (Bỏ qua hoàn toàn HTML scraping)
$apiData = getApi22Data($videoId);

if (!$apiData) {
    http_response_code(404);
    echo json_encode(['code' => -1, 'msg' => 'Không tìm thấy dữ liệu video qua API nội bộ', 'videoId' => $videoId], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. Map dữ liệu
$author = $apiData['author'] ?? [];
$stats = $apiData['statistics'] ?? [];
$video = $apiData['video'] ?? [];
$textExtra = $apiData['text_extra'] ?? [];

$hashtags = [];
foreach ($textExtra as $extra) {
    if (!empty($extra['hashtag_name'])) {
        $hashtags[] = $extra['hashtag_name'];
    }
}

// Lấy link trực tiếp không cần cookie
$playAddr = $video['play_addr']['url_list'][0] ?? null;
if (empty($playAddr)) {
    $playAddr = $video['download_addr']['url_list'][0] ?? null;
}

$duration = isset($video['duration']) ? (int)($video['duration'] / 1000) : 0;

$videoFormatted = [
    'ad_authorization' => $apiData['is_ads'] ?? false,
    'anchor_types'     => [],
    'author'           => $author['unique_id'] ?? '',
    'author_followers' => 0,
    'author_id'        => (string)($author['uid'] ?? ''),
    'category_type'    => 113,
    'comment_count'    => (int)($stats['comment_count'] ?? 0),
    'cover'            => $video['cover']['url_list'][0] ?? '',
    'create_time'      => (int)($apiData['create_time'] ?? 0),
    'desc'             => $apiData['desc'] ?? '',
    'digg_count'       => (int)($stats['digg_count'] ?? 0),
    'download_addr'    => $playAddr,
    'duration_s'       => $duration,
    'embed_url'        => 'https://www.tiktok.com/embed/v2/' . $videoId,
    'has_shop'         => !empty($apiData['is_ads']),
    'hashtags'         => $hashtags,
    'id'               => (string)$videoId,
    'play_count'       => (int)($stats['play_count'] ?? 0),
    'share_count'      => (int)($stats['share_count'] ?? 0)
];

// Định dạng json đầu ra mong muốn như ảnh
$output = [
    'ad_count'   => 0,
    'count'      => 1,
    'keyword'    => '',
    'shop_count' => 0,
    'videos'     => [
        $videoFormatted
    ],
    'cookies'    => $cookies
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>