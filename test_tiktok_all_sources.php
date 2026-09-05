<?php
// test_tiktok_all_sources.php - Test 4-tier TikTok fallback chain (Clean output without PHP 8.5 Deprecated warnings)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

header('Content-Type: text/html; charset=utf-8');

$tiktok_url = isset($_GET['url']) && !empty($_GET['url']) 
    ? trim($_GET['url']) 
    : 'https://www.tiktok.com/@copphavietcom/video/7518744554284125447';

$user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';

echo "<!DOCTYPE html>
<html>
<head>
    <title>🧪 Test 4-Tier TikTok Fallback Chain (Clean Output)</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; }
        .card { background: #1e293b; border-radius: 10px; padding: 20px; margin-bottom: 20px; border: 1px solid #334155; }
        .winner-card { background: #064e3b; border: 2px solid #10b981; }
        h2, h3 { color: #38bdf8; margin-top: 0; }
        .status-ok { color: #4ade80; font-weight: bold; }
        .status-fail { color: #f87171; font-weight: bold; }
        pre { background: #090d16; padding: 12px; border-radius: 6px; overflow-x: auto; color: #e2e8f0; font-size: 13px; }
        a { color: #38bdf8; word-break: break-all; }
        input[type='text'] { width: 70%; padding: 10px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: white; }
        button { padding: 10px 20px; background: #0284c7; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>";

echo "<div class='card'>";
echo "<h2>🧪 BÓC TÁCH LINK TIKTOK 4 TẦNG FALLBACK CHAIN</h2>";
echo "<form method='GET' style='margin-bottom:10px;'>
        <input type='text' name='url' value='" . htmlspecialchars($tiktok_url) . "' placeholder='Dán link TikTok tại đây...'>
        <button type='submit'>🚀 Kiểm Tra Ngay</button>
      </form>";
echo "</div>";

// ── KHỞI CHẠY WATERFALL FALLBACK CHAIN (Giống publish_worker.php) ─────────────
$final_result = null;

// ── 1. TẦNG 1: TIKWM API ──────────────────────────────────────────────────────
echo "<div class='card'>";
echo "<h3>1. Tầng 1: TikWM API (tikwm.com)</h3>";
$ch1 = curl_init('https://tikwm.com/api/');
curl_setopt_array($ch1, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['url' => $tiktok_url, 'hd' => 1]),
    CURLOPT_TIMEOUT => 12,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER => [
        'User-Agent: ' . $user_agent,
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json, text/plain, */*'
    ]
]);
$res1 = curl_exec($ch1);

if ($res1 && strpos($res1, 'Just a moment...') === false) {
    $data1 = json_decode($res1, true);
    if ($data1 && isset($data1['code']) && $data1['code'] === 0 && isset($data1['data'])) {
        $d = $data1['data'];
        $link1 = !empty($d['hdplay']) ? $d['hdplay'] : (!empty($d['play']) ? $d['play'] : '');
        if ($link1) {
            $final_result = [
                'download_url' => $link1,
                'title'        => $d['title'] ?? 'tiktok_video',
                'video_id'     => $d['id'] ?? null,
                'provider'     => 'Tầng 1: TikWM API'
            ];
            echo "<p class='status-ok'>✅ THÀNH CÔNG RỰC RỠ!</p>";
            echo "<p><strong>Tiêu đề:</strong> " . htmlspecialchars($d['title'] ?? '') . "</p>";
            echo "<p><strong>Link MP4 gốc (CDN):</strong> <a href='" . htmlspecialchars($link1) . "' target='_blank'>" . htmlspecialchars($link1) . "</a></p>";
        }
    }
}
if (!$final_result && isset($data1['msg'])) {
    echo "<p class='status-fail'>❌ THẤT BẠI: " . htmlspecialchars($data1['msg']) . "</p>";
}
echo "</div>";

// ── 2. TẦNG 2: SNAPCDN HOSTS ─────────────────────────────────────────────────
echo "<div class='card'>";
echo "<h3>2. Tầng 2: SnapCDN / TikDownloader / TikVid (Require Cookie Session)</h3>";
$snap_hosts = ['tikdownloader.io', 'tikvid.io', 'savetik.co'];
$t2_success = false;

foreach ($snap_hosts as $shost) {
    $ch2 = curl_init("https://{$shost}/api/ajaxSearch");
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['q' => $tiktok_url, 'lang' => 'en']),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $user_agent,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json, text/plain, */*'
        ]
    ]);
    $res2 = curl_exec($ch2);

    if ($res2) {
        $data2 = json_decode($res2, true);
        $s_html = $data2['data'] ?? '';
        if (!empty($s_html)) {
            if (preg_match('/(?:token=|dl\.snapcdn\.app\/get\?token=)([A-Za-z0-9_\.-]+)/i', $s_html, $m_jwt)) {
                $jwt_parts = explode('.', $m_jwt[1]);
                if (count($jwt_parts) >= 2) {
                    $payload_b64 = str_replace(['-', '_'], ['+', '/'], $jwt_parts[1]);
                    $mod = strlen($payload_b64) % 4;
                    if ($mod !== 0) $payload_b64 .= str_repeat('=', 4 - $mod);
                    $decoded_json = @base64_decode($payload_b64);
                    if ($decoded_json) {
                        $payload = json_decode($decoded_json, true);
                        if (!empty($payload['url'])) {
                            $t2_success = true;
                            if (!$final_result) {
                                $final_result = [
                                    'download_url' => $payload['url'],
                                    'title'        => $payload['filename'] ?? 'tiktok_video',
                                    'video_id'     => null,
                                    'provider'     => 'Tầng 2: SnapCDN (' . $shost . ')'
                                ];
                            }
                            echo "<p class='status-ok'>✅ THÀNH CÔNG via {$shost}!</p>";
                            break;
                        }
                    }
                }
            }
        }
    }
}
if (!$t2_success) {
    echo "<p class='status-fail'>⚠️ Tầng 2 bị bỏ qua (Do server Cloudflare lọc Session Cookie cURL). Tự động chuyển sang Tầng 3...</p>";
}
echo "</div>";

// ── 3. TẦNG 3: TIKMATE API ───────────────────────────────────────────────────
echo "<div class='card'>";
echo "<h3>3. Tầng 3: TikMate API (api.tikmate.app)</h3>";
$ch3 = curl_init('https://api.tikmate.app/api/lookup');
curl_setopt_array($ch3, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['url' => $tiktok_url]),
    CURLOPT_TIMEOUT => 12,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'User-Agent: ' . $user_agent,
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json, text/plain, */*'
    ]
]);
$res3 = curl_exec($ch3);

if ($res3) {
    $data3 = json_decode($res3, true);
    if ($data3 && !empty($data3['success']) && !empty($data3['token']) && !empty($data3['id'])) {
        $link3 = "https://tikmate.app/download/{$data3['token']}/{$data3['id']}.mp4";
        if (!$final_result) {
            $final_result = [
                'download_url' => $link3,
                'title'        => $data3['desc'] ?? 'tiktok_video',
                'video_id'     => $data3['id'],
                'provider'     => 'Tầng 3: TikMate API'
            ];
        }
        echo "<p class='status-ok'>✅ THÀNH CÔNG!</p>";
        echo "<p><strong>Tiêu đề:</strong> " . htmlspecialchars($data3['desc'] ?? '') . "</p>";
        echo "<p><strong>Link MP4:</strong> <a href='" . htmlspecialchars($link3) . "' target='_blank'>" . htmlspecialchars($link3) . "</a></p>";
    } else {
        echo "<p class='status-fail'>❌ THẤT BẠI</p>";
    }
} else {
    echo "<p class='status-fail'>❌ LỖI KẾT NỐI MẠNG</p>";
}
echo "</div>";

// ── 4. TẦNG 4: SSSTIK API ────────────────────────────────────────────────────
echo "<div class='card'>";
echo "<h3>4. Tầng 4: SSSTik API (ssstik.io)</h3>";
$ch_init = curl_init('https://ssstik.io/en');
curl_setopt_array($ch_init, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => ['User-Agent: ' . $user_agent]
]);
$page_html = curl_exec($ch_init);

$tt_token = '0';
if ($page_html && preg_match('/s_tt\s*=\s*[\'"]([^\'"]+)[\'"]/', $page_html, $m)) {
    $tt_token = $m[1];
}

$ch_sss = curl_init('https://ssstik.io/abc?url=dl');
curl_setopt_array($ch_sss, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'id' => $tiktok_url,
        'locale' => 'en',
        'tt' => $tt_token
    ]),
    CURLOPT_HTTPHEADER => [
        'User-Agent: ' . $user_agent,
        'Referer: https://ssstik.io/en',
        'Origin: https://ssstik.io',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8'
    ]
]);
$sss_html = curl_exec($ch_sss);

if ($sss_html) {
    $dl_sss = null;
    if (preg_match('/href="([^"]+)"[^>]*class="[^"]*without_watermark[^"]*"/i', $sss_html, $m)) {
        $dl_sss = $m[1];
    } elseif (preg_match('/href="(https:\/\/tikcdn\.io\/[^"]+)"/i', $sss_html, $m)) {
        $dl_sss = $m[1];
    }

    if ($dl_sss) {
        if (!$final_result) {
            $final_result = [
                'download_url' => $dl_sss,
                'title'        => 'tiktok_video',
                'video_id'     => null,
                'provider'     => 'Tầng 4: SSSTik API'
            ];
        }
        echo "<p class='status-ok'>✅ THÀNH CÔNG!</p>";
        echo "<p><strong>Link MP4:</strong> <a href='" . htmlspecialchars($dl_sss) . "' target='_blank'>" . htmlspecialchars($dl_sss) . "</a></p>";
    } else {
        echo "<p class='status-fail'>❌ Không trích xuất được link từ SSSTik.</p>";
    }
} else {
    echo "<p class='status-fail'>❌ LỖI KẾT NỐI MẠNG</p>";
}
echo "</div>";

// ── KẾT QUẢ CUỐI CÙNG MÀ WORKER SẼ DÙNG ─────────────────────────────────────
echo "<div class='card winner-card'>";
echo "<h2 style='color:#34d399;'>🏆 KẾT QUẢ ĐƯỢC CHỌN CHO BÀI ĐĂNG WORKER</h2>";
if ($final_result) {
    echo "<p><strong>Kênh thành công đầu tiên:</strong> <span style='color:#a7f3d0; font-weight:bold; font-size:16px;'>" . htmlspecialchars($final_result['provider']) . "</span></p>";
    echo "<p><strong>Tiêu đề trích xuất:</strong> " . htmlspecialchars($final_result['title']) . "</p>";
    echo "<p><strong>URL Download Video MP4:</strong> <a href='" . htmlspecialchars($final_result['download_url']) . "' target='_blank'>" . htmlspecialchars($final_result['download_url']) . "</a></p>";
} else {
    echo "<p class='status-fail'>❌ Rất tiếc, tất cả các tầng đều không trích xuất được link.</p>";
}
echo "</div>";

echo "</body></html>";
?>
