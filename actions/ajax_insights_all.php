<?php
// actions/ajax_insights_all.php
// Chiến lược tối ưu:
// 1. Trả JSON insights về browser NGAY LẬP TỨC (không chờ followers)
// 2. Sau khi đóng HTTP connection, cập nhật followers bằng multi-curl song song
// → Với 1000 fanpage: UI load ngay, followers update chạy ngầm ~5-10s

session_start();
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

$account_id = $_SESSION['account_id'];
$period     = 'days_28';
$end_date   = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-30 days'));
$nocache    = isset($_GET['nocache']);

$cache_key_all = "insights_all_growth_v3_{$account_id}_{$period}";

// ── Trả cache ngay nếu còn hạn ───────────────────────────────────────────────
if (!$nocache && isset($_SESSION[$cache_key_all]) && $_SESSION[$cache_key_all]['expires'] > time()) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'data' => $_SESSION[$cache_key_all]['data']]);
    exit;
}

// ── Đảm bảo cột followers_diff tồn tại ───────────────────────────────────────
try { $pdo->exec("ALTER TABLE pages ADD COLUMN followers_diff INT DEFAULT NULL"); } catch (Exception $e) {}

// ── Lấy danh sách fanpage từ DB ───────────────────────────────────────────────
$stmt_all = $pdo->prepare("
    SELECT p.page_id, p.access_token, p.name, p.followers_count
    FROM pages p JOIN users u ON p.user_id = u.id
    WHERE u.account_id = :aid
    UNION
    SELECT p.page_id, p.access_token, p.name, p.followers_count
    FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id
    WHERE ps.shared_with_account_id = :aid2
    ORDER BY followers_count DESC
");
$stmt_all->execute(['aid' => $account_id, 'aid2' => $account_id]);
$all_user_pages = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

$all_page_insights = [];
$fetch_pages = [];   // Dùng cho insights multi-curl
$follower_pages = []; // Dùng cho followers multi-curl

foreach ($all_user_pages as $p_row) {
    $decrypted_token = decryptData($p_row['access_token']);
    $pid = $p_row['page_id'];

    $fetch_pages[]   = ['page_id' => $pid, 'access_token' => $decrypted_token];
    $follower_pages[] = [
        'page_id'       => $pid,
        'access_token'  => $decrypted_token,
        'old_followers' => (int) $p_row['followers_count'],
    ];
    $all_page_insights[$pid] = [
        'name'      => $p_row['name'],
        'followers' => (int) $p_row['followers_count'],  // Dùng giá trị DB, nhanh
        'reach'     => 0,
        'views'     => 0,
    ];
}

// ── Insights: multi-curl song song (đã tối ưu sẵn) ───────────────────────────
$api_responses = get_fb_page_insights_multi($fetch_pages, $period, $start_date, $end_date);

foreach ($api_responses as $pid => $api_response) {
    if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
        foreach ($api_response['data']['data'] as $metric) {
            if (!in_array($metric['name'], ['page_media_view', 'page_impressions_unique'])) continue;
            if (!isset($metric['values']) || !is_array($metric['values']) || empty($metric['values'])) continue;

            $latest = end($metric['values']);
            $val = isset($latest['value']) ? intval($latest['value']) : 0;

            if ($metric['name'] === 'page_media_view') {
                $all_page_insights[$pid]['views'] += $val;
            } else {
                $all_page_insights[$pid]['reach'] += $val;
            }
        }
    }
}

// Sắp xếp theo Reach giảm dần
uasort($all_page_insights, function($a, $b) {
    return $b['reach'] <=> $a['reach'];
});

// Cache 30 phút
$_SESSION[$cache_key_all] = ['data' => $all_page_insights, 'expires' => time() + 1800];

// ── GỬI RESPONSE VỀ BROWSER NGAY ─────────────────────────────────────────────
$json_out = json_encode(['status' => 'success', 'data' => $all_page_insights]);
header('Content-Type: application/json');

if (function_exists('fastcgi_finish_request')) {
    // PHP-FPM: đóng connection, giữ process chạy tiếp
    echo $json_out;
    fastcgi_finish_request();
} else {
    // Non-FPM: Connection: close trick
    ignore_user_abort(true);
    header('Connection: close');
    header('Content-Length: ' . strlen($json_out));
    ob_start();
    echo $json_out;
    $size = ob_get_length();
    ob_end_flush();
    flush();
}

// ═══════════════════════════════════════════════════════════════════════════════
// PHẦN NÀY CHẠY SAU KHI BROWSER ĐÃ NHẬN ĐƯỢC RESPONSE (background)
// Browser không cần đợi — UI đã hiển thị rồi
// ═══════════════════════════════════════════════════════════════════════════════

// Chỉ update followers 1 lần/ngày per account (tránh gọi API lặp)
$followers_flag = "followers_synced_" . date('Y-m-d') . "_acc{$account_id}";
if (isset($_SESSION[$followers_flag]) && !$nocache) {
    exit;
}

// ── Followers: multi-curl song song (50 page/batch) ───────────────────────────
$chunks = array_chunk($follower_pages, 50);
$db_updates = []; // Gom lại để update DB một lần

foreach ($chunks as $chunk) {
    $multi   = curl_multi_init();
    $handles = [];

    foreach ($chunk as $fp) {
        if (empty($fp['access_token'])) continue;

        $url = FB_API_BASE . $fp['page_id'] . '?fields=followers_count&access_token=' . urlencode($fp['access_token']);
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        fb_curl_setssl($ch);
        curl_multi_add_handle($multi, $ch);
        $handles[$fp['page_id']] = ['ch' => $ch, 'old' => $fp['old_followers']];
    }

    // Chờ tất cả requests trong batch hoàn thành
    $active = null;
    do {
        $mrc = curl_multi_exec($multi, $active);
        if ($active) curl_multi_select($multi, 0.5);
    } while ($active && $mrc == CURLM_OK);

    foreach ($handles as $pid => $info) {
        $response  = curl_multi_getcontent($info['ch']);
        $http_code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $info['ch']);

        if ($http_code === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['followers_count'])) {
                $new_count = (int) $data['followers_count'];
                $old_count = $info['old'];
                // Tính diff: so với số cũ đang có trong DB
                $diff = ($old_count > 0) ? ($new_count - $old_count) : null;
                $db_updates[] = [$new_count, $diff, $pid];
            }
        }
    }
    curl_multi_close($multi);

    // Nhỏ delay giữa các batch (tránh rate-limit, không ảnh hưởng user)
    usleep(100000); // 100ms/batch × 20 batch = 2s cho 1000 page
}

// ── Ghi vào DB theo batch ─────────────────────────────────────────────────────
if (!empty($db_updates)) {
    $upd_stmt = $pdo->prepare("UPDATE pages SET followers_count = ?, followers_diff = ? WHERE page_id = ?");
    foreach ($db_updates as $row) {
        $upd_stmt->execute($row);
    }
}

// Đánh dấu đã sync hôm nay
$_SESSION[$followers_flag] = date('H:i:s');
