<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['url']) || empty($_GET['url'])) {
    http_response_code(400);
    echo json_encode(['code' => -1, 'msg' => 'Thiếu tham số url']);
    exit;
}

$tiktok_url = trim($_GET['url']);

// ==================== Logic 0: Custom API (Evil0ctal / TikHub) - Nhanh nhất ====================
$custom_api_url = '';
try {
    $ss_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'tiktok_api_url'");
    if ($ss_stmt) {
        $custom_api_url = trim($ss_stmt->fetchColumn() ?: '');
    }
} catch (Exception $e) {}

$tikwm_fallback_data = null;

if (!empty($custom_api_url)) {
    $api_target = $custom_api_url;
    if (strpos($api_target, '?') === false) {
        $api_target = rtrim($api_target, '/') . '/api/hybrid/video_data?url=' . urlencode($tiktok_url);
    } else {
        if (strpos($api_target, 'url=') === false) {
            $api_target .= (strpos($api_target, '&') === false ? '' : '&') . 'url=' . urlencode($tiktok_url);
        }
    }

    $ch = curl_init($api_target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $resp = curl_exec($ch);
    curl_close($ch);

    if ($resp) {
        $json = json_decode($resp, true);
        if ($json && isset($json['data'])) {
            $download_url = null;
            $title = null;
            $vid = null;
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
            } elseif (!empty($json['data']['video']['play_addr']['url_list'][0])) {
                $download_url = $json['data']['video']['play_addr']['url_list'][0];
            } elseif (!empty($json['data']['url'])) {
                $download_url = $json['data']['url'];
            } elseif (!empty($json['video_data']['nwm_video_url'])) {
                $download_url = $json['video_data']['nwm_video_url'];
            } elseif (!empty($json['url'])) {
                $download_url = $json['url'];
            }

            if (!empty($json['data']['desc'])) {
                $title = $json['data']['desc'];
            } elseif (!empty($json['data']['title'])) {
                $title = $json['data']['title'];
            } elseif (!empty($json['video_data']['video_title'])) {
                $title = $json['video_data']['video_title'];
            } elseif (!empty($json['desc'])) {
                $title = $json['desc'];
            } elseif (!empty($json['title'])) {
                $title = $json['title'];
            }

            if (!empty($json['data']['id'])) {
                $vid = $json['data']['id'];
            } elseif (!empty($json['data']['aweme_id'])) {
                $vid = $json['data']['aweme_id'];
            } elseif (!empty($json['video_data']['id'])) {
                $vid = $json['video_data']['id'];
            } elseif (!empty($json['id'])) {
                $vid = $json['id'];
            }

            if (!empty($download_url)) {
                $videoFormatted = [
                    'ad_authorization'  => false,
                    'anchor_types'      => [],
                    'author'            => $json['data']['author']['unique_id'] ?? $json['data']['author']['nickname'] ?? 'tiktok_user',
                    'author_followers'  => 0,
                    'author_id'         => (string)($json['data']['author']['id'] ?? ''),
                    'category_type'     => 113,
                    'comment_count'     => (int)($json['data']['statistics']['comment_count'] ?? $json['data']['comment_count'] ?? 0),
                    'cover'             => $json['data']['cover'] ?? '',
                    'create_time'       => (int)($json['data']['create_time'] ?? time()),
                    'desc'              => $title,
                    'digg_count'        => (int)($json['data']['statistics']['digg_count'] ?? $json['data']['digg_count'] ?? 0),
                    'download_addr'     => $download_url,
                    'download_addr_hd'  => $hdAddr,
                    'download_addr_fhd' => $fhdAddr,
                    'duration_s'        => (int)($json['data']['duration'] ?? 0),
                    'embed_url'         => 'https://www.tiktok.com/embed/v2/' . $vid,
                    'has_shop'          => false,
                    'hashtags'          => [],
                    'id'                => (string)$vid,
                    'play_count'        => (int)($json['data']['statistics']['play_count'] ?? $json['data']['play_count'] ?? 0),
                    'share_count'       => (int)($json['data']['statistics']['share_count'] ?? $json['data']['share_count'] ?? 0)
                ];

                $extractor_source = $json['data']['source'] ?? 'custom_api';

                $output = [
                    'ad_count'   => 0,
                    'count'      => 1,
                    'keyword'    => '',
                    'shop_count' => 0,
                    'extractor_source' => $extractor_source,
                    'videos'     => [
                        $videoFormatted
                    ],
                    'cookies'    => ''
                ];

                // Trả về kết quả ngay lập tức (kể cả tikwm) để tránh gọi lại API chính thức gây treo hoặc rate limit chéo
                echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
}

// ==================== LẤY COOKIES TỪ WEB ====================
function getTikTokCookies($url) {
    return ''; // Bỏ qua cURL lấy cookie để tăng tốc độ tối đa (API chính thức & TikWM không dùng cookie)
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true); // Chỉ lấy Header cho nhanh, không tải nguyên trang HTML nữa
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
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
    
    $domains = [
        "api22-normal-c-alisg.tiktokv.com",
        "api22-normal-c-useast1a.tiktokv.com",
        "api16-normal-c-useast1a.tiktokv.com"
    ];
    
    foreach ($domains as $domain) {
        $apiUrl = "https://" . $domain . "/aweme/v1/feed/?aweme_id=" . $videoId . "&iid=7318518857994389254&device_id=7318517321748022790&channel=googleplay&app_name=musical_ly&version_code=300904&device_platform=android&device_type=ASUS_Z01QD&os_version=9";
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36',
            'Referer: https://www.tiktok.com/',
            'Cookie: CykaBlyat=XD'
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $data = json_decode($res, true);
            if (isset($data['aweme_list'][0])) {
                return $data['aweme_list'][0];
            }
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
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
    global $tikwm_fallback_data;
    if (!empty($tikwm_fallback_data)) {
        echo json_encode($tikwm_fallback_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ==================== Logic 2: TikWM API GET Mặc định (Nếu App API lỗi) ====================
    $tikwm_url = 'https://www.tikwm.com/api/?url=' . urlencode($tiktok_url) . '&hd=1';
    $ch = curl_init($tikwm_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 7);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
        'Referer: https://tikwm.com/',
        'Origin: https://tikwm.com',
        'Accept: application/json, text/plain, */*'
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    
    // Tự động thử lại TikWM nếu bị rate limit 1 request/second
    if ($data && isset($data['code']) && $data['code'] === -1 && strpos(strtolower($data['msg'] ?? ''), 'limit') !== false) {
        usleep(1500000); // Ngủ 1.5 giây
        $ch = curl_init($tikwm_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
            'Referer: https://tikwm.com/',
            'Origin: https://tikwm.com',
            'Accept: application/json, text/plain, */*'
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
    }

    if ($data && isset($data['code']) && $data['code'] === 0 && isset($data['data'])) {
        $d = $data['data'];
        if (!empty($d['play'])) {
            $videoFormatted = [
                'ad_authorization'  => false,
                'anchor_types'      => [],
                'author'            => $d['author']['unique_id'] ?? $d['author']['nickname'] ?? 'tiktok_user',
                'author_followers'  => 0,
                'author_id'         => (string)($d['author']['id'] ?? ''),
                'category_type'     => 113,
                'comment_count'     => (int)($d['comment_count'] ?? 0),
                'cover'             => $d['cover'] ?? '',
                'create_time'       => (int)($d['create_time'] ?? time()),
                'desc'              => $d['title'] ?? '',
                'digg_count'        => (int)($d['digg_count'] ?? 0),
                'download_addr'     => $d['hdplay'] ?? $d['play'],
                'download_addr_hd'  => $d['hdplay'] ?? null,
                'download_addr_fhd' => null,
                'duration_s'        => (int)($d['duration'] ?? 0),
                'embed_url'         => 'https://www.tiktok.com/embed/v2/' . ($d['id'] ?? $videoId),
                'has_shop'          => false,
                'hashtags'          => [],
                'id'                => (string)($d['id'] ?? $videoId),
                'play_count'        => (int)($d['play_count'] ?? 0),
                'share_count'       => (int)($d['share_count'] ?? 0)
            ];

            $output = [
                'ad_count'   => 0,
                'count'      => 1,
                'keyword'    => '',
                'shop_count' => 0,
                'extractor_source' => 'tikwm',
                'videos'     => [
                    $videoFormatted
                ],
                'cookies'    => ''
            ];

            echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    http_response_code(200);
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

// Lấy HD và Full HD URLs
$hdAddr = null;
$fhdAddr = null;
if (!empty($video['bit_rate']) && is_array($video['bit_rate'])) {
    foreach ($video['bit_rate'] as $br) {
        if (!empty($br['gear_name']) && !empty($br['play_addr']['url_list'][0])) {
            $gear = strtolower($br['gear_name']);
            $url = $br['play_addr']['url_list'][0];
            if (strpos($gear, '1080') !== false) {
                $fhdAddr = $url;
            } elseif (strpos($gear, '720') !== false) {
                $hdAddr = $url;
            }
        }
    }
}

// Lấy link trực tiếp không cần cookie, ưu tiên FHD > HD > play_addr
$playAddr = $fhdAddr;
if (empty($playAddr)) {
    $playAddr = $hdAddr;
}
if (empty($playAddr)) {
    $playAddr = $video['play_addr']['url_list'][0] ?? null;
}
if (empty($playAddr)) {
    $playAddr = $video['download_addr']['url_list'][0] ?? null;
}

$duration = isset($video['duration']) ? (int)($video['duration'] / 1000) : 0;

$videoFormatted = [
    'ad_authorization'  => $apiData['is_ads'] ?? false,
    'anchor_types'      => [],
    'author'            => $author['unique_id'] ?? '',
    'author_followers'  => 0,
    'author_id'         => (string)($author['uid'] ?? ''),
    'category_type'     => 113,
    'comment_count'     => (int)($stats['comment_count'] ?? 0),
    'cover'             => $video['cover']['url_list'][0] ?? '',
    'create_time'       => (int)($apiData['create_time'] ?? 0),
    'desc'              => $apiData['desc'] ?? '',
    'digg_count'        => (int)($stats['digg_count'] ?? 0),
    'download_addr'     => $playAddr,
    'download_addr_hd'  => $hdAddr,
    'download_addr_fhd' => $fhdAddr,
    'duration_s'        => $duration,
    'embed_url'         => 'https://www.tiktok.com/embed/v2/' . $videoId,
    'has_shop'          => !empty($apiData['is_ads']),
    'hashtags'          => $hashtags,
    'id'                => (string)$videoId,
    'play_count'        => (int)($stats['play_count'] ?? 0),
    'share_count'       => (int)($stats['share_count'] ?? 0)
];

// Định dạng json đầu ra mong muốn như ảnh
$output = [
    'ad_count'   => 0,
    'count'      => 1,
    'keyword'    => '',
    'shop_count' => 0,
    'extractor_source' => 'main_api',
    'videos'     => [
        $videoFormatted
    ],
    'cookies'    => $cookies
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
