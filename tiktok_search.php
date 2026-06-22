<?php
// ─── AJAX requests must be handled BEFORE any HTML output ─────────────────────
if (isset($_GET['ajax'])) {
    // Only need DB/session for auth check — we boot a minimal session here
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/includes/db.php';
    if (!isset($_SESSION['account_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Chưa đăng nhập.']);
        exit;
    }

    header('Content-Type: application/json');

    // ─── Shared cURL helper ────────────────────────────────────────────────────
    function tiktok_curl(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        return ['raw' => $raw, 'err' => $err];
    }

    // ─── Filter video fields ───────────────────────────────────────────────────
    function filter_video_fields(array $videos): array {
        $fields = ['video_id','region','duration','title','play_count','digg_count',
                   'comment_count','share_count','download_count','create_time','music_info','author'];
        $result = [];
        foreach ($videos as $video) {
            $v = [];
            foreach ($fields as $f) { $v[$f] = $video[$f] ?? null; }
            $result[] = $v;
        }
        return $result;
    }

    $ajax = $_GET['ajax'];

    // ── Search by keyword ──────────────────────────────────────────────────────
    if ($ajax === 'keyword') {
        $keyword = trim($_GET['keyword'] ?? '');
        $count   = 30; // API trả tối đa ~30/request, JS sẽ loop
        $cursor  = max(0, intval($_GET['cursor'] ?? 0));

        if ($keyword === '') {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập từ khóa tìm kiếm.']); exit;
        }

        $api_url = "https://www.tikwm.com/api/feed/search?" . http_build_query([
            'keywords' => $keyword, 'count' => $count, 'cursor' => $cursor,
        ]);

        ['raw' => $raw, 'err' => $err] = tiktok_curl($api_url);
        if ($err) { echo json_encode(['status' => 'error', 'message' => 'Lỗi cURL: ' . $err]); exit; }

        $data = json_decode($raw, true);
        if (!$data || ($data['msg'] ?? '') !== 'success') {
            echo json_encode(['status' => 'error', 'message' => $data['msg'] ?? 'API không phản hồi.']); exit;
        }

        echo json_encode([
            'status'  => 'success',
            'data'    => filter_video_fields($data['data']['videos'] ?? []),
            'cursor'  => $data['data']['cursor'] ?? 0,
            'hasMore' => !empty($data['data']['hasMore']),
        ]);
        exit;
    }

    // ── Search by username ──────────────────────────────────────────────────────
    if ($ajax === 'username') {
        $username = trim($_GET['username'] ?? '');
        $count    = 33; // API returns up to 33 per request, JS will loop
        $cursor   = trim($_GET['cursor'] ?? '0');

        if ($username === '') {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập username kênh TikTok.']); exit;
        }

        // Get TikTok API URL
        $tiktok_api_url = 'http://127.0.0.1:8000';
        $ss_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'tiktok_api_url'");
        if ($ss_stmt) {
            $db_url = trim($ss_stmt->fetchColumn() ?: '');
            if ($db_url !== '') {
                $tiktok_api_url = $db_url;
            }
        }

        $api_url = rtrim($tiktok_api_url, '/') . '/api/tiktok/user_videos?' . http_build_query([
            'username' => $username,
            'count'    => $count,
            'cursor'   => $cursor,
        ]);

        ['raw' => $raw, 'err' => $err] = tiktok_curl($api_url);
        if ($err) { echo json_encode(['status' => 'error', 'message' => 'Lỗi cURL: ' . $err]); exit; }

        $data = json_decode($raw, true);
        if (!$data || ($data['code'] ?? 0) !== 200) {
            echo json_encode(['status' => 'error', 'message' => $data['detail'] ?? ($data['msg'] ?? 'Không thể tải danh sách video của username này.')]); exit;
        }

        $formatted_videos = [];
        $videos = $data['videos'] ?? [];
        foreach ($videos as $vid) {
            $stats = $vid['statistics'] ?? [];
            $formatted_videos[] = [
                'video_id'      => $vid['video_id'] ?? null,
                'title'         => $vid['desc'] ?? null,
                'play_count'    => $stats['play_count'] ?? 0,
                'digg_count'    => $stats['digg_count'] ?? 0,
                'comment_count' => $stats['comment_count'] ?? 0,
                'share_count'   => $stats['share_count'] ?? 0,
                'create_time'   => $vid['create_time'] ?? 0,
                'author'        => [
                    'unique_id' => $username,
                    'nickname'  => $username
                ],
                'region'        => $vid['region'] ?? 'VN',
                'duration'      => $vid['duration'] ?? 0,
            ];
        }

        echo json_encode([
            'status'  => 'success',
            'data'    => $formatted_videos,
            'cursor'  => $data['cursor'] ?? 0,
            'hasMore' => !empty($data['has_more']),
        ]);
        exit;
    }

    // ── Search hashtags by keyword — mirrors getHashTagBYKeyword() ───────────────
    // path: api/challenge/search | returns challenge_list [{id, cha_name, user_count, view_count}]
    if ($ajax === 'hashtag_search') {
        $keyword = trim($_GET['keyword'] ?? '');
        $count   = max(1, min(20, intval($_GET['count'] ?? 10)));
        $cursor  = max(0, intval($_GET['cursor'] ?? 0));

        if ($keyword === '') {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập từ khóa hashtag.']); exit;
        }

        $api_url = 'https://www.tikwm.com/api/challenge/search?' . http_build_query([
            'keywords' => $keyword, 'count' => $count, 'cursor' => $cursor,
        ]);
        ['raw' => $raw, 'err' => $err] = tiktok_curl($api_url);
        if ($err) { echo json_encode(['status' => 'error', 'message' => 'Lỗi cURL: ' . $err]); exit; }

        $data = json_decode($raw, true);
        if (!$data || ($data['msg'] ?? '') !== 'success') {
            echo json_encode(['status' => 'error', 'message' => $data['msg'] ?? 'API không phản hồi.']); exit;
        }

        // Arr::only($challenge, ['id','cha_name','user_count','view_count'])
        $fields = ['id', 'cha_name', 'user_count', 'view_count'];
        $list   = [];
        foreach (($data['data']['challenge_list'] ?? []) as $ch) {
            $item = [];
            foreach ($fields as $f) { $item[$f] = $ch[$f] ?? null; }
            $list[] = $item;
        }

        echo json_encode(['status' => 'success', 'data' => $list]);
        exit;
    }

    // ── Get hashtag detail by name — mirrors getHashTagDetail() ───────────────────
    // path: api/challenge/info | param: challenge_name | returns: id, cha_name, user_count, view_count
    if ($ajax === 'hashtag_info') {
        $challenge_name = ltrim(trim($_GET['challenge_name'] ?? ''), '#');
        if ($challenge_name === '') {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập tên hashtag.']); exit;
        }

        $api_url = 'https://www.tikwm.com/api/challenge/info?' . http_build_query(['challenge_name' => $challenge_name]);
        ['raw' => $raw, 'err' => $err] = tiktok_curl($api_url);
        if ($err) { echo json_encode(['status' => 'error', 'message' => 'Lỗi cURL: ' . $err]); exit; }

        $data = json_decode($raw, true);
        if (!$data || ($data['msg'] ?? '') !== 'success') {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy hashtag #' . htmlspecialchars($challenge_name)]); exit;
        }

        // Arr::only($data, ['id','cha_name','user_count','view_count'])
        $d = $data['data'] ?? [];
        $fields = ['id', 'cha_name', 'user_count', 'view_count'];
        $info = [];
        foreach ($fields as $f) { $info[$f] = $d[$f] ?? null; }

        if (!$info['id']) {
            echo json_encode(['status' => 'error', 'message' => 'Không lấy được ID của hashtag #' . $challenge_name]); exit;
        }
        $info['id'] = (string) $info['id']; // Keep as string — 64-bit snowflake
        echo json_encode(['status' => 'success', 'data' => $info]);
        exit;
    }

    // ── Search by hashtag — mirrors getVideoByHashTag(method, challenge_id, count, cursor) ──
    // host    = https://www.tikwm.com
    // path    = api/challenge/posts
    // params  = challenge_id, count, cursor
    if ($ajax === 'hashtag') {
        $challenge_id = trim($_GET['challenge_id'] ?? '');
        $count        = 30; // API trả tối đa ~30/request, JS sẽ loop
        $cursor       = max(0, intval($_GET['cursor'] ?? 0));

        if (!is_numeric($challenge_id) || $challenge_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'challenge_id không hợp lệ.']); exit;
        }

        $host    = 'https://www.tikwm.com';
        $path    = 'api/challenge/posts';
        $api_url = rtrim($host, '/') . '/' . ltrim($path, '/') . '?' . http_build_query([
            'challenge_id' => $challenge_id,
            'count'        => $count,
            'cursor'       => $cursor,
        ]);

        ['raw' => $raw, 'err' => $err] = tiktok_curl($api_url);
        if ($err) { echo json_encode(['status' => 'error', 'message' => 'Lỗi cURL: ' . $err]); exit; }

        $data = json_decode($raw, true);
        if (!$data || ($data['msg'] ?? '') !== 'success') {
            echo json_encode(['status' => 'error', 'message' => $data['msg'] ?? 'API không phản hồi.']); exit;
        }

        echo json_encode([
            'status'  => 'success',
            'data'    => filter_video_fields($data['data']['videos'] ?? []),
            'cursor'  => $data['data']['cursor'] ?? 0,
            'hasMore' => !empty($data['data']['hasMore']),
        ]);
        exit;
    }

    // Unknown ajax action
    echo json_encode(['status' => 'error', 'message' => 'Action không hợp lệ.']);
    exit;
}

// ─── Normal page load — include header AFTER AJAX block ───────────────────────
$current_page = 'tiktok_search';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ─── TikTok Search Page ─────────────────────────────────────────────────────── */
.tiktok-hero {
    background: linear-gradient(135deg, #010101 0%, #1a0533 40%, #2d0b55 100%);
    border-radius: 16px; padding: 28px 32px; margin-bottom: 24px;
    display: flex; align-items: center; gap: 20px;
    position: relative; overflow: hidden;
}
.tiktok-hero::before {
    content: ''; position: absolute; top: -30px; right: -30px;
    width: 180px; height: 180px;
    background: radial-gradient(circle, rgba(105,0,210,0.4) 0%, transparent 70%);
    pointer-events: none;
}
.tiktok-hero::after {
    content: ''; position: absolute; bottom: -20px; left: 40%;
    width: 120px; height: 120px;
    background: radial-gradient(circle, rgba(254,44,85,0.3) 0%, transparent 70%);
    pointer-events: none;
}
.tiktok-logo-wrap {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #fe2c55, #25f4ee);
    border-radius: 14px; display: flex; align-items: center; justify-content: center;
    font-size: 28px; flex-shrink: 0; box-shadow: 0 4px 16px rgba(254,44,85,0.5);
}
.tiktok-hero-text h1 { font-size: 22px; font-weight: 700; color: #fff; margin: 0 0 4px; }
.tiktok-hero-text p  { font-size: 13px; color: rgba(255,255,255,0.65); margin: 0; }

/* ─── Mode Tabs ─── */
.mode-tabs {
    display: flex; gap: 0; margin-bottom: 20px;
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 12px; padding: 5px; width: fit-content;
}
.mode-tab {
    padding: 10px 24px; border-radius: 8px;
    border: none; background: transparent; color: var(--text-muted);
    font-size: 14px; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; gap: 7px;
    transition: all 0.2s;
}
.mode-tab.active {
    background: linear-gradient(135deg, #fe2c55, #c9134c);
    color: #fff; box-shadow: 0 4px 12px rgba(254,44,85,0.35);
}
.mode-tab.active-ht {
    background: linear-gradient(135deg, #6d28d9, #4c1d95);
    color: #fff; box-shadow: 0 4px 12px rgba(109,40,217,0.35);
}
.mode-tab:not(.active):not(.active-ht):hover { color: var(--text-main); }

/* ─── Search Card ─── */
.search-form-card {
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 14px; padding: 24px; margin-bottom: 20px;
}
.search-row  { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.search-field { display: flex; flex-direction: column; gap: 6px; }
.search-field label {
    font-size: 12px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.search-field input[type="text"],
.search-field input[type="number"] {
    padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 8px;
    background: var(--bg-color); color: var(--text-main);
    font-size: 14px; outline: none; transition: border-color 0.2s, box-shadow 0.2s;
}
.search-field input:focus { border-color: #fe2c55; box-shadow: 0 0 0 3px rgba(254,44,85,0.12); }
.search-field.ht-focus input:focus { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,0.12); }
.search-field.grow { flex: 1; min-width: 220px; }

.btn-search {
    padding: 10px 26px; background: linear-gradient(135deg,#fe2c55,#c9134c);
    color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:600;
    cursor:pointer; white-space:nowrap; display:flex; align-items:center; gap:8px;
    transition: transform .15s, box-shadow .15s; box-shadow:0 4px 14px rgba(254,44,85,.4);
}
.btn-search:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(254,44,85,.5); }
.btn-search:disabled { opacity:.6; cursor:not-allowed; transform:none; }
.btn-search.ht { background:linear-gradient(135deg,#7c3aed,#5b21b6); box-shadow:0 4px 14px rgba(124,58,237,.4); }
.btn-search.ht:hover { box-shadow:0 6px 18px rgba(124,58,237,.55); }

/* ─── Hashtag suggestion list ─── */
.ht-suggest-wrap {
    margin-top: 16px;
    display: none;
}
.ht-suggest-label {
    font-size: 12px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px;
}
.ht-suggest-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px;
}
.ht-suggest-card {
    background: var(--bg-color);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    padding: 12px 14px;
    cursor: pointer;
    transition: all .18s;
    position: relative;
    overflow: hidden;
}
.ht-suggest-card::before {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(124,58,237,0.06), transparent);
    opacity: 0; transition: opacity .18s;
}
.ht-suggest-card:hover { border-color: #8b5cf6; transform: translateY(-2px); box-shadow: 0 4px 14px rgba(124,58,237,.2); }
.ht-suggest-card:hover::before { opacity: 1; }
.ht-suggest-card.selected { border-color: #7c3aed; background: linear-gradient(135deg, rgba(124,58,237,.1), rgba(109,40,217,.06)); }
.ht-card-name { font-size: 14px; font-weight: 700; color: #8b5cf6; margin-bottom: 5px; }
.ht-card-stats { display: flex; gap: 12px; font-size: 11px; color: var(--text-muted); }
.ht-card-stats span strong { color: var(--text-main); font-size: 12px; }
.ht-card-btn {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    font-size: 11px; padding: 4px 10px;
    background: #7c3aed; color: #fff;
    border-radius: 6px; opacity: 0; transition: opacity .18s;
    font-weight: 600;
}
.ht-suggest-card:hover .ht-card-btn { opacity: 1; }

/* Hashtag active selected info bar */
.hashtag-info-bar {
    background: linear-gradient(135deg, rgba(109,40,217,0.12), rgba(76,29,149,0.08));
    border: 1px solid rgba(139,92,246,0.4);
    border-radius: 10px; padding: 12px 16px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.hashtag-info-bar .ht-tag  { font-size: 16px; font-weight: 800; color: #8b5cf6; }
.hashtag-info-bar .ht-stat { font-size: 12px; color: var(--text-muted); }
.hashtag-info-bar .ht-stat strong { color: var(--text-main); font-size: 13px; }

/* ─── Column Filter ─── */
.col-filter-wrap {
    margin-bottom: 16px; background: var(--card-bg);
    border: 1px solid var(--border-color); border-radius: 10px; padding: 14px 18px;
}
.col-filter-label { font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; }
.col-filter-list  { display:flex; flex-wrap:wrap; gap:8px; }
.col-chip {
    display:flex; align-items:center; gap:5px; padding:5px 12px;
    border:1px solid var(--border-color); border-radius:20px;
    font-size:12px; cursor:pointer; background:var(--bg-color); color:var(--text-main);
    transition:all .15s; user-select:none;
}
.col-chip.active { background:linear-gradient(135deg,#1a0533,#2d0b55); border-color:#8b5cf6; color:#fff; }
.col-chip input[type="checkbox"] { display:none; }

/* ─── Results toolbar ─── */
.results-toolbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
.results-info { font-size:13px; color:var(--text-muted); }
.results-info strong { color:var(--text-main); }
.btn-copy-urls {
    padding:8px 18px; background:linear-gradient(135deg,#25f4ee,#0dcfca); color:#010101;
    border:none; border-radius:8px; font-size:13px; font-weight:700;
    cursor:pointer; display:flex; align-items:center; gap:7px; transition:all .15s;
    box-shadow:0 3px 10px rgba(37,244,238,.35);
}
.btn-copy-urls:hover { transform:translateY(-1px); box-shadow:0 5px 14px rgba(37,244,238,.5); }
.btn-copy-urls:disabled { opacity:.5; cursor:not-allowed; transform:none; }

/* ─── Table ─── */
.tiktok-table-wrap { overflow-x:auto; border:1px solid var(--border-color); border-radius:12px; background:var(--card-bg); }
.tiktok-table { width:100%; border-collapse:collapse; min-width:700px; font-size:13px; }
.tiktok-table thead th {
    padding:12px 14px; background:linear-gradient(135deg,#0f0f0f,#1e0340);
    color:#ccc; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;
    border-bottom:1px solid var(--border-color); white-space:nowrap; cursor:pointer; user-select:none;
}
.tiktok-table thead th:hover { color:#fe2c55; }
.tiktok-table thead th.sort-asc::after  { content:' ▲'; color:#fe2c55; font-size:10px; }
.tiktok-table thead th.sort-desc::after { content:' ▼'; color:#fe2c55; font-size:10px; }
.tiktok-table tbody tr { border-bottom:1px solid var(--border-color); transition:background .1s; }
.tiktok-table tbody tr:hover { background:rgba(254,44,85,.04); }
.tiktok-table tbody tr:last-child { border-bottom:none; }
.tiktok-table td { padding:11px 14px; color:var(--text-main); vertical-align:middle; }
.tiktok-table td.col-checkbox { width:38px; text-align:center; }
.tiktok-table td.col-title { max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tiktok-table td.col-title a { color:var(--primary-color); text-decoration:none; font-weight:500; }
.tiktok-table td.col-title a:hover { text-decoration:underline; }
.tiktok-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-views    { background:rgba(139,92,246,.15); color:#8b5cf6; }
.badge-likes    { background:rgba(254,44,85,.13);  color:#fe2c55; }
.badge-comments { background:rgba(59,130,246,.13); color:#3b82f6; }
.badge-shares   { background:rgba(16,185,129,.13); color:#10b981; }
.check-all-wrap { display:flex; align-items:center; gap:6px; cursor:pointer; }

/* ─── Spinner ─── */
#search-spinner {
    display:none; text-align:center; padding:40px; color:var(--text-muted); font-size:14px;
    gap:10px; align-items:center; justify-content:center;
}
.spin-ring {
    width:20px; height:20px; border:2px solid var(--border-color); border-top-color:#fe2c55;
    border-radius:50%; animation:spin .8s linear infinite; display:inline-block;
}
@keyframes spin { 100% { transform:rotate(360deg); } }

.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state .empty-icon { font-size:48px; margin-bottom:12px; }
.empty-state p { font-size:14px; }

#copy-toast {
    position:fixed; bottom:28px; right:20px; background:#10b981; color:#fff;
    padding:12px 22px; border-radius:10px; font-size:14px; font-weight:600;
    box-shadow:0 4px 18px rgba(16,185,129,.45); display:none; z-index:9999;
}
@keyframes slideUp { from { transform:translateY(20px); opacity:0; } to { transform:translateY(0); opacity:1; } }
</style>

<!-- Hero -->
<div class="tiktok-hero">
    <div class="tiktok-logo-wrap">🎵</div>
    <div class="tiktok-hero-text">
        <h1>TikTok Video Search</h1>
        <p>Tìm theo từ khóa hoặc hashtag · Lọc & sắp xếp theo chỉ số · Copy URL hàng loạt</p>
    </div>
</div>

<!-- Mode Tabs -->
<div class="mode-tabs">
    <button class="mode-tab active" id="tab-keyword" onclick="switchMode('keyword')">🔍 Tìm theo Từ khóa</button>
    <button class="mode-tab" id="tab-hashtag" onclick="switchMode('hashtag')">🏷️ Tìm theo Hashtag</button>
    <button class="mode-tab" id="tab-username" onclick="switchMode('username')">👤 Tìm theo Username</button>
</div>

<!-- Search Card: KEYWORD -->
<div class="search-form-card" id="form-keyword">
    <form onsubmit="return false;">
        <div class="search-row">
            <div class="search-field grow">
                <label for="kw-input">Từ khóa tìm kiếm</label>
                <input type="text" id="kw-input" placeholder="Nhập từ khóa..." autocomplete="off">
            </div>
            <div class="search-field">
                <label for="kw-region">Vùng (Quốc gia)</label>
                <input type="text" id="kw-region" placeholder="Ví dụ: VN, US..." style="width:140px;">
            </div>
            <div class="search-field">
                <label for="kw-count">Số video <span style="color:var(--text-muted);font-weight:400;text-transform:none;">(tối đa 5000)</span></label>
                <input type="number" id="kw-count" value="10" min="1" max="5000" style="width:110px;">
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button class="btn-search" id="btn-search-kw" onclick="doSearchKeyword()">
                    <span>🔍</span> Tìm kiếm
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Search Card: HASHTAG -->
<div class="search-form-card" id="form-hashtag" style="display:none;">
    <form onsubmit="return false;">
        <div class="search-row">
            <div class="search-field grow ht-focus">
                <label for="ht-input">🔍 Tìm hashtag theo từ khóa
                    <span style="color:var(--text-muted);font-weight:400;text-transform:none;">(không cần #)</span>
                </label>
                <input type="text" id="ht-input" placeholder="Ví dụ: viral, xuhuong, dancechallenge..." autocomplete="off">
            </div>
            <div class="search-field">
                <label for="ht-region">Vùng (Quốc gia)</label>
                <input type="text" id="ht-region" placeholder="Ví dụ: VN, US..." style="width:140px;">
            </div>
            <div class="search-field">
                <label for="ht-count">Số video / hashtag <span style="color:var(--text-muted);font-weight:400;text-transform:none;">(tối đa 5000)</span></label>
                <input type="number" id="ht-count" value="10" min="1" max="5000" style="width:120px;">
            </div>
            <div style="display:flex;align-items:flex-end;gap:8px;">
                <button class="btn-search ht" id="btn-search-ht" onclick="doSearchHashtagKeyword()">
                    <span>🔍</span> Tìm Hashtag
                </button>
            </div>
        </div>
    </form>

    <!-- Step 1: Hashtag suggestion cards -->
    <div class="ht-suggest-wrap" id="ht-suggest-wrap">
        <div class="ht-suggest-label">📋 Chọn hashtag để xem video</div>
        <div class="ht-suggest-grid" id="ht-suggest-grid"></div>
    </div>

    <!-- Step 2: Selected hashtag info bar -->
    <div id="ht-info-bar" style="display:none; margin-top:14px;"></div>
</div>

<!-- Search Card: USERNAME -->
<div class="search-form-card" id="form-username" style="display:none;">
    <form onsubmit="return false;">
        <div class="search-row">
            <div class="search-field grow">
                <label for="us-input">Username kênh TikTok (ví dụ: copphavietcom)</label>
                <input type="text" id="us-input" placeholder="Nhập username..." autocomplete="off">
            </div>
            <div class="search-field">
                <label for="us-count">Số video <span style="color:var(--text-muted);font-weight:400;text-transform:none;">(tối đa 5000)</span></label>
                <input type="number" id="us-count" value="10" min="1" max="5000" style="width:110px;">
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button class="btn-search" id="btn-search-us" onclick="doSearchUsername()">
                    <span>🔍</span> Tìm kiếm
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Column Filter Chips -->
<div class="col-filter-wrap" id="col-filter-wrap" style="display:none;">
    <div class="col-filter-label">🎛 Hiển thị cột</div>
    <div class="col-filter-list" id="col-chips"></div>
</div>

<!-- Results Toolbar & Table -->
<div id="results-section" style="display:none;">
    <div class="results-toolbar">
        <div class="results-info" id="result-count">Đang tải...</div>
        <button class="btn-copy-urls" id="btn-copy" onclick="copySelectedUrls()" disabled>
            📋 Copy URL đã chọn (<span id="selected-count">0</span>)
        </button>
    </div>
    <div class="tiktok-table-wrap">
        <table class="tiktok-table" id="tiktok-table">
            <thead id="table-head"></thead>
            <tbody id="table-body"></tbody>
        </table>
    </div>
</div>

<!-- Spinner -->
<div id="search-spinner" style="display:none; text-align:center; padding:40px; color:var(--text-muted); font-size:14px; gap:10px; align-items:center; justify-content:center;">
    <span class="spin-ring"></span>&nbsp; Đang tìm kiếm video TikTok...
</div>

<!-- Error -->
<div id="search-error" style="display:none;"></div>

<!-- Empty -->
<div id="empty-state" class="empty-state" style="display:none;">
    <div class="empty-icon">🎵</div>
    <p>Không tìm thấy video nào phù hợp. Hãy thử từ khóa hoặc hashtag khác.</p>
</div>

<!-- Toast -->
<div id="copy-toast">✅ Đã copy URL vào clipboard!</div>

<script>
// ─── Column definitions ────────────────────────────────────────────────────────
const COLUMNS = [
    { key: 'checkbox',      label: '☑',            sortable: false, visible: true,  special: 'checkbox' },
    { key: 'title',         label: 'Tiêu đề',      sortable: true,  visible: true  },
    { key: 'author',        label: 'Tác giả',      sortable: true,  visible: true  },
    { key: 'play_count',    label: '▶ Views',       sortable: true,  visible: true  },
    { key: 'digg_count',    label: '❤ Thích',      sortable: true,  visible: true  },
    { key: 'comment_count', label: '💬 Bình luận',  sortable: true,  visible: true  },
    { key: 'share_count',   label: '🔗 Chia sẻ',   sortable: true,  visible: true  },
    { key: 'download_count',label: '⬇ Tải về',     sortable: true,  visible: false },
    { key: 'duration',      label: '⏱ Thời lượng', sortable: true,  visible: false },
    { key: 'region',        label: '🌍 Vùng',      sortable: false, visible: false },
    { key: 'create_time',   label: '📅 Ngày',      sortable: true,  visible: true  },
];

// ─── State ────────────────────────────────────────────────────────────────────
let allVideos  = [];
let sortKey    = null;
let sortDir    = 'desc';
let colVisible = {};
let currentMode = 'keyword'; // 'keyword' | 'hashtag' | 'username'

COLUMNS.forEach(c => { colVisible[c.key] = c.visible; });

// ─── Mode Switcher ─────────────────────────────────────────────────────────────
function switchMode(mode) {
    currentMode = mode;

    document.getElementById('form-keyword').style.display = mode === 'keyword' ? 'block' : 'none';
    document.getElementById('form-hashtag').style.display = mode === 'hashtag' ? 'block' : 'none';
    document.getElementById('form-username').style.display = mode === 'username' ? 'block' : 'none';

    const tabKw = document.getElementById('tab-keyword');
    const tabHt = document.getElementById('tab-hashtag');
    const tabUs = document.getElementById('tab-username');
    tabKw.className = 'mode-tab' + (mode === 'keyword' ? ' active' : '');
    tabHt.className = 'mode-tab' + (mode === 'hashtag' ? ' active-ht' : '');
    tabUs.className = 'mode-tab' + (mode === 'username' ? ' active' : '');

    // Reset results
    resetResults();
}

function resetResults() {
    allVideos = [];
    document.getElementById('results-section').style.display = 'none';
    document.getElementById('col-filter-wrap').style.display = 'none';
    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('search-error').style.display = 'none';
}

// ─── Progressive Fetch State ──────────────────────────────────────────────────
let pgState = null; // { mode, keyword, challengeId, challengeName, cursor, needed, seenIds }

// ─── Keyword Search (progressive) ────────────────────────────────────────────
function doSearchKeyword() {
    const keyword = document.getElementById('kw-input').value.trim();
    if (!keyword) { alert('Vui lòng nhập từ khóa tìm kiếm!'); return; }
    const needed = parseInt(document.getElementById('kw-count').value) || 10;
    const region = document.getElementById('kw-region').value.trim().toUpperCase();

    allVideos = [];
    sortKey = null; sortDir = 'desc';
    resetResults();
    pgState = { mode: 'keyword', keyword, challengeId: '', challengeName: '', cursor: 0, needed, region: region, fetchCount: 0, seenIds: new Set() };
    setLoading(true, '🔍 Đang tải video...');
    fetchNextPage();
}

// ─── Hashtag: Step 1 — Search hashtags by keyword ────────────────────────────
function doSearchHashtagKeyword() {
    const kw = document.getElementById('ht-input').value.trim().replace(/^#+/, '');
    if (!kw) { alert('Vui lòng nhập từ khóa tìm hashtag!'); return; }

    setLoading(true, '🔍 Đang tìm hashtag "' + kw + '"...');
    resetResults();
    document.getElementById('ht-suggest-wrap').style.display = 'none';
    document.getElementById('ht-info-bar').style.display = 'none';

    fetch(`tiktok_search.php?ajax=hashtag_search&keyword=${encodeURIComponent(kw)}&count=12`)
        .then(r => r.json())
        .then(res => {
            setLoading(false);
            if (res.status !== 'success') { showError('❌ ' + res.message); return; }
            renderHashtagSuggestions(res.data || []);
        })
        .catch(e => { setLoading(false); showError('❌ Lỗi kết nối: ' + e.message); });
}

// ─── Render hashtag suggestion cards ──────────────────────────────────────────
function renderHashtagSuggestions(list) {
    const grid = document.getElementById('ht-suggest-grid');
    const wrap = document.getElementById('ht-suggest-wrap');

    if (!list.length) {
        grid.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:10px 0;">Không tìm thấy hashtag nào.</div>';
        wrap.style.display = 'block';
        return;
    }

    grid.innerHTML = list.map(ch => {
        const name  = ch.cha_name || '—';
        const views = ch.view_count ? fmtNum(ch.view_count) : '—';
        const users = ch.user_count ? fmtNum(ch.user_count) : '—';
        const id    = String(ch.id || '');
        return `
        <div class="ht-suggest-card" onclick="selectHashtag('${escHtml(id)}', '${escHtml(name)}')" title="Click để xem video">
            <div class="ht-card-name">#${escHtml(name)}</div>
            <div class="ht-card-stats">
                <span>👁 <strong>${views}</strong></span>
                <span>👤 <strong>${users}</strong></span>
            </div>
            <span class="ht-card-btn">Xem video →</span>
        </div>`;
    }).join('');
    wrap.style.display = 'block';
}

// ─── Hashtag: Step 2 — Select hashtag and start progressive fetch ─────────────
function selectHashtag(challenge_id, cha_name) {
    if (!challenge_id) return;
    const needed = parseInt(document.getElementById('ht-count').value) || 10;
    const region = document.getElementById('ht-region').value.trim().toUpperCase();

    // Highlight selected card
    document.querySelectorAll('.ht-suggest-card').forEach(c => c.classList.remove('selected'));
    event.currentTarget.classList.add('selected');

    // Show info bar (loading state)
    const bar = document.getElementById('ht-info-bar');
    bar.innerHTML = `
        <div class="hashtag-info-bar">
            <span class="ht-tag">#${escHtml(cha_name)}</span>
            <div class="ht-stat">🆔 ID<br><strong style="font-size:11px;">${escHtml(challenge_id)}</strong></div>
            <div class="ht-stat" style="margin-left:auto;">
                <span style="font-size:12px;color:var(--text-muted);">Đang tải video...</span>
            </div>
        </div>`;
    bar.style.display = 'block';

    allVideos = [];
    sortKey = null; sortDir = 'desc';
    resetResults();
    pgState = { mode: 'hashtag', keyword: '', challengeId: challenge_id, challengeName: cha_name, cursor: 0, needed, region: region, fetchCount: 0, seenIds: new Set() };
    setLoading(true, `🎬 Đang tải video của #${escHtml(cha_name)}...`);
    fetchNextPage();
}

// ─── Unified Progressive Fetch Engine ────────────────────────────────────────
function fetchNextPage() {
    if (!pgState) return;
    const s = pgState;
    const thisState = s; // capture reference to detect stale calls
    
    s.fetchCount = (s.fetchCount || 0) + 1;

    let url;
    if (s.mode === 'keyword') {
        url = `tiktok_search.php?ajax=keyword&keyword=${encodeURIComponent(s.keyword)}&cursor=${s.cursor}`;
    } else if (s.mode === 'username') {
        url = `tiktok_search.php?ajax=username&username=${encodeURIComponent(s.username)}&cursor=${s.cursor}`;
    } else {
        url = `tiktok_search.php?ajax=hashtag&challenge_id=${encodeURIComponent(s.challengeId)}&cursor=${s.cursor}`;
    }

    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (pgState !== thisState) return; // Search was reset — discard stale result

            if (res.status !== 'success') {
                setLoading(false);
                if (allVideos.length === 0) showError('❌ ' + res.message);
                else finalizeResults(); // Show what we have
                return;
            }

            // ── Dedup by video_id ──────────────────────────────────────────────
            const newVideos = (res.data || []).filter(v => {
                const id = String(v.video_id || '');
                if (!id || s.seenIds.has(id)) return false;
                
                // Region Filter
                if (s.region) {
                    const rList = s.region.split(',').map(x => x.trim().toUpperCase()).filter(x => x);
                    if (rList.length > 0) {
                        const vidRegion = String(v.region || '').trim().toUpperCase();
                        if (!rList.includes(vidRegion)) return false;
                    }
                }
                
                s.seenIds.add(id);
                return true;
            });

            if (newVideos.length > 0) {
                const isFirst = allVideos.length === 0;
                allVideos.push(...newVideos);

                if (isFirst) {
                    // First batch: show table + chips
                    document.getElementById('col-filter-wrap').style.display = 'block';
                    document.getElementById('results-section').style.display = 'block';
                    buildChips();
                }
                renderTable();
            }

            s.cursor = res.cursor || 0;
            const hasMore = res.hasMore && s.cursor > 0;
            const gotEnough = allVideos.length >= s.needed;

            if (!gotEnough && hasMore && s.fetchCount < 50) {
                // Update progress and fetch next page
                const infoEl = document.getElementById('result-count');
                infoEl.innerHTML = `⏳ Đang tải... <strong>${allVideos.length}</strong> / ${s.needed} video`;
                setLoading(true, `⏳ Đang quét vùng... tìm được ${allVideos.length}/${s.needed} video`);
                setTimeout(fetchNextPage, 150); // slight delay to prevent API throttle
            } else {
                finalizeResults();
            }
        })
        .catch(e => {
            if (pgState !== thisState) return;
            setLoading(false);
            if (allVideos.length === 0) showError('❌ Lỗi kết nối: ' + e.message);
            else finalizeResults();
        });
}

// ─── Finalize after all pages loaded ─────────────────────────────────────────
function finalizeResults() {
    setLoading(false);
    if (!pgState) return;
    const s = pgState;

    if (allVideos.length === 0) {
        document.getElementById('empty-state').style.display = 'block';
        return;
    }

    const infoEl = document.getElementById('result-count');
    if (s.mode === 'keyword') {
        infoEl.innerHTML = `Tìm thấy <strong>${allVideos.length}</strong> video cho từ khóa "<strong>${escHtml(s.keyword)}</strong>"`;
    } else if (s.mode === 'username') {
        infoEl.innerHTML = `Tìm thấy <strong>${allVideos.length}</strong> video từ kênh "<strong>${escHtml(s.username)}</strong>"`;
    } else {
        infoEl.innerHTML = `<strong>${allVideos.length}</strong> video trong hashtag "<strong>#${escHtml(s.challengeName)}</strong>"`;
        // Update ht-info-bar
        const bar = document.getElementById('ht-info-bar');
        if (bar) bar.innerHTML = `
            <div class="hashtag-info-bar">
                <span class="ht-tag">#${escHtml(s.challengeName)}</span>
                <div class="ht-stat">🎬 Kết quả<br><strong>${allVideos.length} video</strong></div>
                <div class="ht-stat">🆔 ID: <strong style="font-size:11px;">${escHtml(s.challengeId)}</strong></div>
            </div>`;
    }
    renderTable(); // Final render (may re-apply sort)
}

function doSearchUsername() {
    const username = document.getElementById('us-input').value.trim();
    if (!username) { alert('Vui lòng nhập username!'); return; }
    const needed = parseInt(document.getElementById('us-count').value) || 10;

    allVideos = [];
    sortKey = null; sortDir = 'desc';
    resetResults();
    pgState = { mode: 'username', username, cursor: 0, needed, fetchCount: 0, seenIds: new Set() };
    setLoading(true, '🔍 Đang tải video từ kênh ' + username + '...');
    fetchNextPage();
}

// ─── Display Results (legacy — kept for backward compat) ──────────────────────
function displayResults(videos, infoText) {
    allVideos = videos || [];
    sortKey   = null; sortDir = 'desc';

    if (allVideos.length === 0) { document.getElementById('empty-state').style.display = 'block'; return; }

    document.getElementById('col-filter-wrap').style.display = 'block';
    document.getElementById('results-section').style.display = 'block';
    document.getElementById('result-count').innerHTML = infoText;
    buildChips();
    renderTable();
}


// ─── Build column chips ────────────────────────────────────────────────────────
function buildChips() {
    const wrap = document.getElementById('col-chips');
    wrap.innerHTML = '';
    COLUMNS.forEach(col => {
        if (col.special === 'checkbox') return;

        const chip = document.createElement('label');
        chip.className = 'col-chip' + (colVisible[col.key] ? ' active' : '');

        const cb = document.createElement('input');
        cb.type    = 'checkbox';
        cb.checked = !!colVisible[col.key];
        cb.style.display = 'none';

        // Use 'change' on checkbox (not 'click' on label) — label wrapping a checkbox
        // causes click to fire TWICE (label click + bubbled checkbox click), toggling back.
        cb.addEventListener('change', () => {
            colVisible[col.key] = cb.checked;
            chip.classList.toggle('active', cb.checked);
            renderTable();
        });

        chip.appendChild(cb);
        chip.appendChild(document.createTextNode(col.label));
        wrap.appendChild(chip);
    });
}


// ─── Build header ────────────────────────────────────────────────────────────
function buildHeader() {
    const thead = document.getElementById('table-head');
    let html = '<tr>';
    COLUMNS.forEach(col => {
        if (!colVisible[col.key]) return;
        if (col.special === 'checkbox') {
            html += `<th class="col-checkbox">
                <label class="check-all-wrap" title="Chọn tất cả">
                    <input type="checkbox" id="check-all" onchange="toggleAll(this.checked)">
                </label></th>`;
        } else {
            const cls = col.sortable ? (sortKey === col.key ? (sortDir === 'asc' ? 'sort-asc' : 'sort-desc') : '') : '';
            html += `<th class="${cls}" onclick="${col.sortable ? `doSort('${col.key}')` : ''}">${col.label}</th>`;
        }
    });
    html += '</tr>';
    thead.innerHTML = html;
}

// ─── Render table ─────────────────────────────────────────────────────────────
function renderTable() {
    buildHeader();
    let videos = [...allVideos];

    if (sortKey) {
        videos.sort((a, b) => {
            let va = a[sortKey], vb = b[sortKey];
            if (sortKey === 'author') { va = a.author?.nickname || a.author?.unique_id || ''; vb = b.author?.nickname || b.author?.unique_id || ''; }
            if (typeof va === 'string') return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
            return sortDir === 'asc' ? va - vb : vb - va;
        });
    }

    const tbody = document.getElementById('table-body');
    if (videos.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${getVisibleCount()}" style="text-align:center;padding:30px;color:var(--text-muted);">Không có kết quả.</td></tr>`;
        return;
    }

    let html = '';
    videos.forEach((v, idx) => {
        const videoId    = v.video_id || '';
        const authorId   = v.author?.unique_id || 'user';
        const tiktokUrl  = videoId ? `https://www.tiktok.com/@${authorId}/video/${videoId}` : '';
        const title      = (v.title || '').trim() || '(Không có tiêu đề)';
        const authorName = v.author?.nickname || v.author?.unique_id || '—';
        const dateStr    = v.create_time ? new Date(v.create_time * 1000).toLocaleDateString('vi-VN') : '—';

        html += `<tr data-idx="${idx}">`;
        COLUMNS.forEach(col => {
            if (!colVisible[col.key]) return;
            if (col.special === 'checkbox') {
                html += `<td class="col-checkbox"><input type="checkbox" class="row-check" data-url="${escHtml(tiktokUrl)}" onchange="updateSelectedCount()"></td>`;
            } else if (col.key === 'title') {
                html += `<td class="col-title">${tiktokUrl ? `<a href="${escHtml(tiktokUrl)}" target="_blank" title="${escHtml(title)}">${escHtml(title)}</a>` : escHtml(title)}</td>`;
            } else if (col.key === 'author') {
                html += `<td>${escHtml(authorName)}</td>`;
            } else if (col.key === 'play_count') {
                html += `<td><span class="tiktok-badge badge-views">${fmtNum(v.play_count)}</span></td>`;
            } else if (col.key === 'digg_count') {
                html += `<td><span class="tiktok-badge badge-likes">${fmtNum(v.digg_count)}</span></td>`;
            } else if (col.key === 'comment_count') {
                html += `<td><span class="tiktok-badge badge-comments">${fmtNum(v.comment_count)}</span></td>`;
            } else if (col.key === 'share_count') {
                html += `<td><span class="tiktok-badge badge-shares">${fmtNum(v.share_count)}</span></td>`;
            } else if (col.key === 'download_count') {
                html += `<td>${fmtNum(v.download_count)}</td>`;
            } else if (col.key === 'duration') {
                const dur = parseInt(v.duration) || 0;
                html += `<td>${Math.floor(dur/60)}:${String(dur%60).padStart(2,'0')}</td>`;
            } else if (col.key === 'region') {
                html += `<td>${escHtml(v.region || '—')}</td>`;
            } else if (col.key === 'create_time') {
                html += `<td style="white-space:nowrap;font-size:12px;color:var(--text-muted);">${dateStr}</td>`;
            } else {
                html += `<td>—</td>`;
            }
        });
        html += '</tr>';
    });

    tbody.innerHTML = html;
    updateSelectedCount();
}

function getVisibleCount() { return COLUMNS.filter(c => colVisible[c.key]).length; }

// ─── Sort ─────────────────────────────────────────────────────────────────────
function doSort(key) {
    if (sortKey === key) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
    else { sortKey = key; sortDir = 'desc'; }
    renderTable();
}

// ─── Checkbox ─────────────────────────────────────────────────────────────────
function toggleAll(checked) {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = checked);
    updateSelectedCount();
}
function updateSelectedCount() {
    const n = document.querySelectorAll('.row-check:checked').length;
    document.getElementById('selected-count').textContent = n;
    document.getElementById('btn-copy').disabled = n === 0;
}

// ─── Copy URLs ────────────────────────────────────────────────────────────────
function copySelectedUrls() {
    const urls = [];
    document.querySelectorAll('.row-check:checked').forEach(cb => { if (cb.dataset.url) urls.push(cb.dataset.url); });
    if (!urls.length) return;
    const text = urls.join('\n');
    navigator.clipboard.writeText(text).then(() => {
        showToast(`✅ Đã copy ${urls.length} URL vào clipboard!`);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        showToast(`✅ Đã copy ${urls.length} URL vào clipboard!`);
    });
}

// ─── UI Helpers ───────────────────────────────────────────────────────────────
function setLoading(on, msg) {
    const spinner = document.getElementById('search-spinner');
    spinner.style.display = on ? 'flex' : 'none';
    if (on && msg) spinner.innerHTML = `<span class="spin-ring"></span>&nbsp; ${msg}`;
    else if (on)   spinner.innerHTML = `<span class="spin-ring"></span>&nbsp; Đang tìm kiếm video TikTok...`;
    document.getElementById('btn-search-kw').disabled = on;
    document.getElementById('btn-search-ht').disabled = on;
    const btnUs = document.getElementById('btn-search-us');
    if (btnUs) btnUs.disabled = on;
}
function showError(msg) {
    const el = document.getElementById('search-error');
    el.innerHTML = `<div class="alert alert-danger" style="margin-bottom:20px;">${msg}</div>`;
    el.style.display = 'block';
}
function showToast(msg) {
    const t = document.getElementById('copy-toast');
    t.textContent = msg; t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3000);
}
function fmtNum(n) {
    if (n == null) return '—';
    n = parseInt(n) || 0;
    if (n >= 1000000) return (n/1000000).toFixed(1) + 'M';
    if (n >= 1000)    return (n/1000).toFixed(1) + 'K';
    return n.toLocaleString();
}
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─── Enter key ────────────────────────────────────────────────────────────────
document.getElementById('kw-input').addEventListener('keydown', e => { if (e.key === 'Enter') doSearchKeyword(); });
document.getElementById('ht-input').addEventListener('keydown', e => { if (e.key === 'Enter') doSearchHashtagKeyword(); });
document.getElementById('us-input').addEventListener('keydown', e => { if (e.key === 'Enter') doSearchUsername(); });
</script>

<?php include 'includes/footer.php'; ?>
