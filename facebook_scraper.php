<?php
// ─── AJAX requests must be handled BEFORE any HTML output ─────────────────────
if (isset($_GET['ajax'])) {
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/fb_api.php';

    if (!isset($_SESSION['account_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Chưa đăng nhập.']);
        exit;
    }

    header('Content-Type: application/json');
    $account_id = $_SESSION['account_id'];
    $ajax = $_GET['ajax'];

    // ── Helper to Get User Token ───────────────────────────────────────────────
    function getUserToken($pdo, $user_id, $account_id, $is_admin)
    {
        if ($is_admin) {
            $stmt = $pdo->prepare("SELECT access_token FROM users WHERE id = :uid LIMIT 1");
            $stmt->execute(['uid' => $user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT access_token FROM users WHERE id = :uid AND account_id = :aid LIMIT 1");
            $stmt->execute(['uid' => $user_id, 'aid' => $account_id]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? decryptData($user['access_token']) : null;
    }

    // ── Get Page Info ──────────────────────────────────────────────────────────
    if ($ajax === 'get_page') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $page_id = trim($_POST['page_id'] ?? '');

        if ($user_id <= 0 || $page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập đủ User và Page ID.']);
            exit;
        }

        $is_admin = ($_SESSION['role'] === 'admin');
        $token = getUserToken($pdo, $user_id, $account_id, $is_admin);

        if (!$token) {
            echo json_encode(['status' => 'error', 'message' => 'User chưa có Access Token hoặc bạn không có quyền.']);
            exit;
        }

        // Dùng token user gọi API lấy thông tin trang
        $res = fb_api_request("{$page_id}", [
            'access_token' => $token,
            'fields' => 'id,name,followers_count,access_token'
        ]);

        if ($res['status_code'] !== 200) {
            $errMsg = $res['data']['error']['message'] ?? 'Không truy cập được Page bằng User Token này.';
            echo json_encode(['status' => 'error', 'message' => 'Lỗi API: ' . $errMsg]);
            exit;
        }

        $page_name = $res['data']['name'] ?? 'Không rõ';
        $followers = $res['data']['followers_count'] ?? 0;
        $page_token = $res['data']['access_token'] ?? '';

        $encrypted_page_token = encryptData($page_token);

        $stmtU = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmtU->execute([$user_id]);
        $user_name = $stmtU->fetchColumn() ?: 'Không rõ';

        $auto_refresh_hours = (isset($_POST['auto_refresh']) && $_POST['auto_refresh'] == '1') ? intval($_POST['refresh_hours'] ?? 10) : 0;

        // Lưu vào DB bảng scraper_pages
        $stmtPage = $pdo->prepare("
            INSERT INTO scraper_pages (account_id, user_id, page_id, page_name, followers_count, access_token, auto_refresh_hours) 
            VALUES (:aid, :uid, :pid, :pname, :followers, :ptoken, :arh)
            ON DUPLICATE KEY UPDATE user_id = :uid, page_name = :pname, followers_count = :followers, access_token = :ptoken, auto_refresh_hours = :arh
        ");
        $stmtPage->execute([
            'aid' => $account_id,
            'uid' => $user_id,
            'pid' => $res['data']['id'] ?? $page_id,
            'pname' => $page_name,
            'followers' => $followers,
            'ptoken' => $encrypted_page_token,
            'arh' => $auto_refresh_hours
        ]);

        echo json_encode([
            'status' => 'success',
            'data'   => [
                'user_id' => $user_id,
                'user_name' => $user_name,
                'page_id' => $res['data']['id'] ?? $page_id,
                'page_name' => $page_name,
                'followers_count' => $followers
            ]
        ]);
        exit;
    }

    // ── Get Saved Posts ───────────────────────────────────────────────────────────
    if ($ajax === 'get_saved_posts') {
        $page_id = trim($_POST['page_id'] ?? '');
        if ($page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Page ID bị thiếu.']); exit;
        }

        $stmt = $pdo->prepare("
            SELECT fb_post_id as id, message, post_created_at as created_time, picture, shares, comments, likes 
            FROM scraper_posts 
            WHERE page_id = :pid 
            ORDER BY post_created_at DESC 
            LIMIT 100
        ");
        $stmt->execute(['pid' => $page_id]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $posts]);
        exit;
    }

    // ── Delete Page ────────────────────────────────────────────────────────────
    if ($ajax === 'delete_page') {
        $page_id = trim($_POST['page_id'] ?? '');
        if ($page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Page ID bị thiếu.']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM scraper_pages WHERE page_id = :pid AND account_id = :aid");
        $stmt->execute(['pid' => $page_id, 'aid' => $account_id]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    // ── Toggle Auto Refresh ──────────────────────────────────────────────────
    if ($ajax === 'toggle_auto') {
        $page_id = trim($_POST['page_id'] ?? '');
        $status = intval($_POST['status'] ?? 0);
        $hours = $status > 0 ? 10 : 0; // Default 10 hours if checked

        if ($page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Page ID thiếu.']); exit;
        }

        $stmt = $pdo->prepare("UPDATE scraper_pages SET auto_refresh_hours = :hours WHERE page_id = :pid AND account_id = :aid");
        $stmt->execute(['hours' => $hours, 'pid' => $page_id, 'aid' => $account_id]);

        echo json_encode(['status' => 'success', 'hours' => $hours]);
        exit;
    }

    // ── Scrape Posts ───────────────────────────────────────────────────────────
    if ($ajax === 'scrape') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $page_id = trim($_POST['page_id'] ?? '');
        $limit = max(1, min(100, intval($_POST['limit'] ?? 10))); // Max 100 per request

        if ($user_id <= 0 || $page_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'User ID hoặc Page ID bị thiếu.']);
            exit;
        }

        // Lấy Page Token từ scraper_pages thay vì dùng User Token
        $stmt = $pdo->prepare("SELECT access_token FROM scraper_pages WHERE page_id = :pid AND account_id = :aid LIMIT 1");
        $stmt->execute(['pid' => $page_id, 'aid' => $account_id]);
        $scraper_page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$scraper_page || empty($scraper_page['access_token'])) {
            echo json_encode(['status' => 'error', 'message' => 'Lỗi: Không tìm thấy Page Token. Bạn hãy Xóa page này bên dưới và Nhập lại để lấy Token mới.']);
            exit;
        }

        $token = decryptData($scraper_page['access_token']);

        // Build API request using /posts (yêu cầu Page Token)
        $fields = 'id,message,created_time,full_picture,shares,comments.summary(total_count),reactions.summary(total_count)';
        $res = fb_api_request("{$page_id}/posts", [
            'access_token' => $token,
            'fields' => $fields,
            'limit' => $limit
        ]);

        if ($res['status_code'] !== 200) {
            $errMsg = $res['data']['error']['message'] ?? 'Lỗi không xác định từ Facebook API.';
            echo json_encode(['status' => 'error', 'message' => 'Lỗi API: ' . $errMsg]);
            exit;
        }

        $postsData = $res['data']['data'] ?? [];
        $result = [];

        $stmtPost = $pdo->prepare("
            INSERT INTO scraper_posts (page_id, fb_post_id, message, picture, shares, comments, likes, post_created_at)
            VALUES (:pid, :fbid, :msg, :pic, :sha, :com, :lik, :c_at)
            ON DUPLICATE KEY UPDATE message=:msg, picture=:pic, shares=:sha, comments=:com, likes=:lik
        ");

        foreach ($postsData as $post) {
            $fbid = $post['id'] ?? '';
            $msg = $post['message'] ?? '';
            $c_at = $post['created_time'] ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : null;
            $pic = $post['full_picture'] ?? '';
            $sha = $post['shares']['count'] ?? 0;
            $com = $post['comments']['summary']['total_count'] ?? 0;
            $lik = $post['reactions']['summary']['total_count'] ?? ($post['likes']['summary']['total_count'] ?? 0);

            if ($fbid) {
                $stmtPost->execute([
                    'pid' => $page_id,
                    'fbid' => $fbid,
                    'msg' => $msg,
                    'pic' => $pic,
                    'sha' => $sha,
                    'com' => $com,
                    'lik' => $lik,
                    'c_at' => $c_at
                ]);
            }

            $result[] = [
                'id' => $fbid,
                'message' => $msg,
                'created_time' => $post['created_time'] ?? '',
                'picture' => $pic,
                'shares' => $sha,
                'comments' => $com,
                'likes' => $lik,
            ];
        }

        $count = count($result);
        $stmtUpdatePostCount = $pdo->prepare("UPDATE scraper_pages SET post_count = :count, last_scraped_at = NOW() WHERE page_id = :pid AND account_id = :aid");
        $stmtUpdatePostCount->execute(['count' => $count, 'pid' => $page_id, 'aid' => $account_id]);

        echo json_encode(['status' => 'success', 'data' => $result, 'post_count' => $count]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Action không hợp lệ.']);
    exit;
}

// ─── Normal page load ─────────────────────────────────────────────────────────
$current_page = 'facebook_scraper';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.name 
    FROM users u 
    LEFT JOIN pages p ON u.id = p.user_id 
    LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
    WHERE u.account_id = :aid OR ps.shared_with_account_id = :aid2
    ORDER BY u.name ASC
");
$stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch saved scraper pages
$stmtPages = $pdo->prepare("
    SELECT sp.user_id, sp.page_id, sp.page_name, sp.followers_count, sp.post_count, sp.auto_refresh_hours, u.name as user_name 
    FROM scraper_pages sp 
    LEFT JOIN users u ON sp.user_id = u.id 
    WHERE sp.account_id = :aid AND sp.page_name IS NOT NULL
    ORDER BY sp.created_at DESC
");
$stmtPages->execute(['aid' => $account_id]);
$savedScraperPages = $stmtPages->fetchAll(PDO::FETCH_ASSOC);
$savedScraperPagesJson = json_encode($savedScraperPages);
?>

<style>
    /* ─── Facebook Scraper Hero ────────────────────────────────────────────────────── */
    .fb-hero {
        background: linear-gradient(135deg, #1877F2 0%, #0c4391 100%);
        border-radius: 16px;
        padding: 28px 32px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 20px;
        position: relative;
        overflow: hidden;
    }

    .fb-hero::before {
        content: '';
        position: absolute;
        top: -30px;
        right: -30px;
        width: 180px;
        height: 180px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
        pointer-events: none;
    }

    .fb-logo-wrap {
        width: 56px;
        height: 56px;
        background: #fff;
        color: #1877F2;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        flex-shrink: 0;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    }

    .fb-hero-text h1 {
        font-size: 22px;
        font-weight: 700;
        color: #fff;
        margin: 0 0 4px;
    }

    .fb-hero-text p {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.8);
        margin: 0;
    }

    /* ─── Form Card ─── */
    .search-form-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 24px;
        margin-bottom: 20px;
    }

    .search-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .search-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .search-field label {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .search-field input[type="text"],
    .search-field input[type="number"],
    .search-field select {
        padding: 10px 14px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-color);
        color: var(--text-main);
        font-size: 14px;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .search-field input:focus,
    .search-field select:focus {
        border-color: #1877F2;
        box-shadow: 0 0 0 3px rgba(24, 119, 242, 0.12);
    }

    .search-field.grow {
        flex: 1;
        min-width: 220px;
    }

    .btn-primary-action {
        padding: 10px 26px;
        background: linear-gradient(135deg, #1877F2, #165ab5);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: transform .15s, box-shadow .15s;
        box-shadow: 0 4px 14px rgba(24, 119, 242, .3);
    }

    .btn-primary-action:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(24, 119, 242, .4);
    }

    .btn-primary-action:disabled {
        opacity: .6;
        cursor: not-allowed;
        transform: none;
    }

    /* ─── Table ─── */
    .table-wrap {
        overflow-x: auto;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: var(--card-bg);
        margin-bottom: 24px;
    }

    .custom-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 700px;
        font-size: 13px;
    }

    .custom-table thead th {
        padding: 12px 14px;
        background: rgba(0, 0, 0, 0.02);
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        border-bottom: 1px solid var(--border-color);
        text-align: left;
    }

    .dark-mode .custom-table thead th {
        background: rgba(255, 255, 255, 0.02);
    }

    .custom-table tbody tr {
        border-bottom: 1px solid var(--border-color);
        transition: background .1s;
    }

    .custom-table tbody tr:hover {
        background: rgba(24, 119, 242, .03);
    }

    .custom-table tbody tr:last-child {
        border-bottom: none;
    }

    .custom-table td {
        padding: 11px 14px;
        color: var(--text-main);
        vertical-align: middle;
    }

    /* ─── Buttons inside table ─── */
    .btn-sm {
        padding: 5px 12px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-danger {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    .btn-danger:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    .btn-info {
        background: rgba(14, 165, 233, 0.1);
        color: #0ea5e9;
    }

    .btn-info:hover {
        background: rgba(14, 165, 233, 0.2);
    }

    /* ─── Scrape Modal View ─── */
    #scrape-section {
        display: none;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .scrape-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 16px;
        margin-bottom: 16px;
    }

    .scrape-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--primary-color);
        margin: 0;
    }

    .scrape-controls {
        display: flex;
        gap: 12px;
        align-items: flex-end;
    }

    /* ─── Scrape Results Component ─── */
    .post-visual {
        display: flex;
        gap: 12px;
        align-items: flex-start;
    }

    .post-visual img {
        width: 80px;
        height: 80px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid var(--border-color);
    }

    .post-visual .no-img {
        width: 80px;
        height: 80px;
        background: rgba(0, 0, 0, 0.05);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        font-size: 24px;
    }

    .post-text {
        flex: 1;
        min-width: 0;
    }

    .post-desc {
        font-size: 13px;
        color: var(--text-main);
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.4;
        margin-bottom: 4px;
    }

    .post-time {
        font-size: 11px;
        color: var(--text-muted);
    }

    /* ─── Spinners ─── */
    .spin-ring {
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin .8s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        100% {
            transform: rotate(360deg);
        }
    }
</style>

<div class="fb-hero">
    <div class="fb-logo-wrap">f</div>
    <div class="fb-hero-text">
        <h1>Content Studio</h1>
        <p>Quét các bài viết, nội dung, media và số liệu tương tác để phân tích.</p>
    </div>
</div>

<!-- Add Page Card -->
<div class="search-form-card">
    <form onsubmit="addPage(event);">
        <div class="search-row">
            <div class="search-field grow" style="flex: 0.5;">
                <label for="user-select">Chọn User Quản Lý Token</label>
                <select id="user-select" required>
                    <option value="">-- Chọn User --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo htmlspecialchars($user['id']); ?>">
                        
                                <?php echo htmlspecialchars($user['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="search-field grow">
                <label for="page-id-input">Nhập Page ID quản lý</label>
                <input type="text" id="page-id-input" placeholder="Ví dụ: 929785023558487" autocomplete="off" required>
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button type="submit" class="btn-primary-action" id="btn-add-page">
                    <span>➕</span> Nhập Page
                </button>
            </div>
        </div>
    </form>
</div>

<!-- List of Pages -->
<div class="table-wrap">
    <table class="custom-table">
        <thead>
            <tr>
                <th style="width: 50px;">STT</th>
                <th>Page Name</th>
                <th>Page ID</th>
                <th>User Quản lý</th>
                <th>Post Count</th>
                <th>Followers</th>
                <th style="text-align: right;">Thao tác</th>
            </tr>
        </thead>
        <tbody id="pages-tbody">
            <tr id="empty-row">
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                    Chưa có Fanpage nào được thêm. Hãy nhập ID và bấm "Nhập Page".
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- Scrape Section -->
<div id="scrape-section">
    <div class="scrape-header">
        <h2 id="scrape-title">Chi tiết Page</h2>
        <div class="scrape-controls">
            <div class="search-field" style="width: 150px;">
                <label for="scrape-limit">Số bài viết muốn quét</label>
                <input type="number" id="scrape-limit" value="10" min="1" max="100">
            </div>
            <button class="btn-primary-action" id="btn-do-scrape" onclick="doScrape()">
                <span id="scrape-btn-icon">⚡</span> Quét bài viết
            </button>
            <button class="btn-sm btn-danger" onclick="closeScrape()"
                style="height: 40px; padding: 0 16px;">Đóng</button>
        </div>
    </div>

    <div class="table-wrap" style="margin-bottom: 0;">
        <table class="custom-table" id="scrape-results-table">
            <thead>
                <tr>
                    <th style="width: 50px;">STT</th>
                    <th style="width: 45%;">Nội dung & Media</th>
                    <th>👍 Likes</th>
                    <th>💬 Comments</th>
                    <th>🔗 Shares</th>
                </tr>
            </thead>
            <tbody id="scrape-tbody">
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        Bấm "Quét bài viết" để bắt đầu lấy dữ liệu.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    let addedPages = <?php echo $savedScraperPagesJson; ?>;
    let pendingScrapePageId = null;
    let pendingScrapeUserId = null;

    document.addEventListener('DOMContentLoaded', () => {
        renderPagesTable();
    });

    function renderPagesTable() {
        const tbody = document.getElementById('pages-tbody');
        if (addedPages.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có Fanpage nào được thêm. Hãy nhập ID và bấm "Nhập Page".</td></tr>`;
            return;
        }

        tbody.innerHTML = addedPages.map((page, index) => {
            const followers = parseInt(page.followers_count || 0).toLocaleString('vi-VN');
            const isAuto = parseInt(page.auto_refresh_hours || 0) > 0;
            const autoChecked = isAuto ? 'checked' : '';
            return `
        <tr>
            <td>${index + 1}</td>
            <td style="font-weight: 600; color: var(--primary-color);">${escapeHtml(page.page_name || 'Không rõ')}</td>
            <td style="color: var(--text-muted);">${escapeHtml(page.page_id)}</td>
            <td><span style="color:#0ea5e9; font-weight: 600;">${escapeHtml(page.user_name || 'Không rõ')}</span></td>
            <td style="font-weight: 700; color:#10b981; font-size:15px;" id="post-count-cell-${escapeHtml(page.page_id)}">${parseInt(page.post_count || 0).toLocaleString('vi-VN')}</td>
            <td>${followers}</td>
            <td style="text-align: right; gap: 8px;">
                <label style="display:inline-flex; align-items:center; margin-right:8px; font-size:12px; cursor:pointer;" title="Tự quét 10h một lần">
                    <input type="checkbox" ${autoChecked} onchange="toggleAuto('${escapeHtml(page.page_id)}', this.checked)" style="margin-right:4px;"> Auto
                </label>
                <button class="btn-sm btn-info" onclick="openScrape('${escapeHtml(page.page_id)}', '${escapeHtml(page.page_name)}')">Chi tiết</button>
                <button class="btn-sm btn-danger" onclick="removePage('${escapeHtml(page.page_id)}')">Xóa</button>
            </td>
        </tr>`;
        }).join('');
    }

    function addPage(e) {
        e.preventDefault();
        const userSelect = document.getElementById('user-select');
        const inputEl = document.getElementById('page-id-input');
        const btn = document.getElementById('btn-add-page');
        const userId = userSelect.value.trim();
        const pageId = inputEl.value.trim();
        if (!userId || !pageId) return;

        // Check if already exist in frontend state
        if (addedPages.find(p => p.page_id === pageId)) {
            alert('Page này đã được thêm vào danh sách.');
            return;
        }

        const originalText = btn.innerHTML;
        btn.innerHTML = `<span class="spin-ring"></span> Đang tải...`;
        btn.disabled = true;

        const fd = new FormData();
        fd.append('user_id', userId);
        fd.append('page_id', pageId);
        fd.append('auto_refresh', '0'); // Mặc định tắt khi mới thêm, bật sau ở bảng
        fd.append('refresh_hours', '10');

        fetch('facebook_scraper.php?ajax=get_page', {
            method: 'POST',
            body: fd
        })
            .then(r => r.json())
            .then(res => {
                btn.innerHTML = originalText;
                btn.disabled = false;

                if (res.status === 'success') {
                    addedPages.push(res.data);
                    inputEl.value = '';
                    renderPagesTable();
                } else {
                    alert('Lỗi: ' + res.message);
                }
            })
            .catch(err => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                alert('Lỗi kết nối mạng: ' + err.message);
            });
    }

    function toggleAuto(pageId, isChecked) {
        const fd = new FormData();
        fd.append('page_id', pageId);
        fd.append('status', isChecked ? '1' : '0');
        fetch('facebook_scraper.php?ajax=toggle_auto', {
            method: 'POST', body: fd
        }).then(r => r.json()).then(res => {
            if(res.status === 'success') {
                const p = addedPages.find(x => x.page_id === pageId);
                if(p) p.auto_refresh_hours = res.hours;
            } else {
                alert('Có lỗi khi lưu trạng thái auto: ' + res.message);
            }
        });
    }

    function removePage(pageId) {
        if (!confirm("Bạn có chắc muốn xóa page này khỏi danh sách?")) return;

        addedPages = addedPages.filter(p => p.page_id !== pageId);
        renderPagesTable();
        if (pendingScrapePageId === pageId) {
            closeScrape(); // close details if deleting the currently viewed page
        }

        const fd = new FormData();
        fd.append('page_id', pageId);
        fetch('facebook_scraper.php?ajax=delete_page', {
            method: 'POST',
            body: fd
        });
    }

    function openScrape(pageId, pageName) {
        const pageObj = addedPages.find(p => p.page_id === pageId);
        if (!pageObj) return;

        pendingScrapePageId = pageId;
        pendingScrapeUserId = pageObj.user_id; // Lưu lại ID
        document.getElementById('scrape-section').style.display = 'block';
        document.getElementById('scrape-title').innerText = 'Chi tiết Page: ' + pageName;

        // Smooth scroll to scrape section
        document.getElementById('scrape-section').scrollIntoView({ behavior: 'smooth', block: 'start' });

        const tbody = document.getElementById('scrape-tbody');
        tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;"><div style="display:flex;justify-content:center;align-items:center;gap:10px;"><span class="spin-ring" style="border-top-color:var(--text-muted);"></span> Đang tải dữ liệu cũ...</div></td></tr>`;

        const fd = new FormData();
        fd.append('page_id', pageId);
        fetch('facebook_scraper.php?ajax=get_saved_posts', {
            method: 'POST', body: fd
        }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                renderPostsData(res.data);
            } else {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Lỗi tải dữ liệu: ${escapeHtml(res.message)}</td></tr>`;
            }
        }).catch(err => {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Bấm "Quét bài viết" để bắt đầu lấy dữ liệu.</td></tr>`;
        });
    }

    function renderPostsData(posts) {
        const tbody = document.getElementById('scrape-tbody');
        if (posts.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có dữ liệu bài viết cũ. Bấm "Quét bài viết" để cào dữ liệu mới nhất.</td></tr>`;
            return;
        }

        tbody.innerHTML = posts.map((post, index) => {
            let mediaHtml = '';
            if (post.picture) {
                mediaHtml = `<img src="${escapeHtml(post.picture)}" alt="Media" referrerpolicy="no-referrer">`;
            } else {
                mediaHtml = `<div class="no-img">📄</div>`;
            }

            const timeStr = new Date(post.created_time).toLocaleString('vi-VN');

            return `
                <tr>
                    <td style="text-align:center;">${index + 1}</td>
                    <td>
                        <div class="post-visual">
                            ${mediaHtml}
                            <div class="post-text">
                                <div class="post-desc" title="${escapeHtml(post.message)}">${escapeHtml(post.message) || '<span style="color:#9ca3af;font-style:italic;">Không có nội dung chữ</span>'}</div>
                                <div class="post-time">📅 ${timeStr} | ID: <a href="https://facebook.com/${post.id}" target="_blank" style="color:#0ea5e9;text-decoration:none;">${(post.id+"").split('_')[1] || post.id}</a></div>
                            </div>
                        </div>
                    </td>
                    <td style="font-weight:600; color:#1877F2;">👍 ${parseInt(post.likes || 0).toLocaleString()}</td>
                    <td style="font-weight:600; color:#0ea5e9;">💬 ${parseInt(post.comments || 0).toLocaleString()}</td>
                    <td style="font-weight:600; color:#10b981;">🔗 ${parseInt(post.shares || 0).toLocaleString()}</td>
                </tr>
            `;
        }).join('');
    }

    function closeScrape() {
        pendingScrapePageId = null;
        pendingScrapeUserId = null;
        document.getElementById('scrape-section').style.display = 'none';
    }

    function doScrape() {
        if (!pendingScrapePageId || !pendingScrapeUserId) return;

        const limitInput = document.getElementById('scrape-limit').value;
        const btn = document.getElementById('btn-do-scrape');

        const originalText = btn.innerHTML;
        btn.innerHTML = `<span class="spin-ring"></span> Đang xử lý...`;
        btn.disabled = true;

        const tbody = document.getElementById('scrape-tbody');
        tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;"><div style="display:flex;justify-content:center;align-items:center;gap:10px;"><span class="spin-ring" style="border-top-color:var(--text-muted);"></span> Đang cào dữ liệu từ Facebook (ID: ${pendingScrapePageId})...</div></td></tr>`;

        const fd = new FormData();
        fd.append('user_id', pendingScrapeUserId);
        fd.append('page_id', pendingScrapePageId);
        fd.append('limit', limitInput);

        fetch('facebook_scraper.php?ajax=scrape', {
            method: 'POST',
            body: fd
        })
            .then(r => r.json())
            .then(res => {
                btn.innerHTML = originalText;
                btn.disabled = false;

                if (res.status === 'success') {
                    renderPostsData(res.data);
                    
                    // Update post count dynamically
                    const pObj = addedPages.find(p => p.page_id === pendingScrapePageId);
                    if (pObj && res.post_count !== undefined) {
                        pObj.post_count = res.post_count;
                        const cell = document.getElementById('post-count-cell-' + pendingScrapePageId);
                        if (cell) cell.innerText = parseInt(res.post_count).toLocaleString('vi-VN');
                    }
                } else {
                    alert('Lỗi: ' + res.message);
                    tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #ef4444; padding: 30px;">⚠ Lỗi khi quét bài viết: ${escapeHtml(res.message)}</td></tr>`;
                }
            })
            .catch(err => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #ef4444; padding: 30px;">⚠ Lỗi mạng: ${err.message}</td></tr>`;
            });
    }

    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return (unsafe + '').replace(/[&<"'>]/g, function (match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#039;';
                default: return match;
            }
        });
    }
</script>

<?php include 'includes/footer.php'; ?>