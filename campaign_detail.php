<?php
// ── Actions MUST be processed before ANY output (before header.php) ────────
require_once __DIR__ . '/includes/db.php';

$campaign_id = intval($_GET['id'] ?? 0);

function trigger_campaign_publisher_worker($pdo, $campaign_id) {
    try {
        $disabled_funcs = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
        $exec_enabled = function_exists('exec') && !in_array('exec', $disabled_funcs);
        
        $camp_key = 'camp_' . (int)$campaign_id;

        if ($exec_enabled) {
            if (!function_exists('get_php_cli_bin')) @include_once __DIR__ . '/includes/php_cli.php';
            if (function_exists('get_php_cli_bin')) {
                $php_bin = get_php_cli_bin();
                $script = __DIR__ . '/cron/publish_worker.php';
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    @pclose(@popen("start /B \"\" \"$php_bin\" \"$script\" \"$camp_key\" \"1\"", "r"));
                } else {
                    @exec("nohup \"$php_bin\" \"$script\" \"$camp_key\" \"1\" > /dev/null 2>&1 &");
                }
                return;
            }
        }

        // Local HTTP cURL fallback launcher: 1 Campaign = 1 Worker ONLY (Sequential execution)
        $base_url = '';
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
            $root_web_path = rtrim(str_replace('\\', '/', str_replace($doc_root, '', dirname(__DIR__))), '/');
            $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $root_web_path;
        } else {
            try {
                $stmt_u = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'base_site_url'");
                $base_url = $stmt_u ? trim($stmt_u->fetchColumn() ?: '') : '';
            } catch (Exception $e) {}
        }

        if (!empty($base_url) && $campaign_id) {
            $url = rtrim($base_url, '/') . "/run_worker.php?type=publish&page_id=" . urlencode($camp_key) . "&user_id=1";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            @curl_exec($ch);
            @curl_close($ch);
        }
    } catch (Exception $e) {}
}

if ($campaign_id) {
    // Single row actions (GET)
    if (isset($_GET['action'], $_GET['post_id'])) {
        $act     = $_GET['action'];
        $post_id = intval($_GET['post_id']);
        try {
            if ($act === 'delete') {
                $pdo->prepare("DELETE FROM scheduled_posts WHERE id = ? AND campaign_id = ? AND status IN ('pending','failed','checkpoint')")->execute([$post_id, $campaign_id]);
            } elseif ($act === 'retry') {
                $pdo->prepare("UPDATE scheduled_posts SET status='pending', retry_count=0, error_msg=NULL WHERE id = ? AND campaign_id = ? AND status IN ('failed','checkpoint','processing')")->execute([$post_id, $campaign_id]);
                trigger_campaign_publisher_worker($pdo, $campaign_id);
            }
        } catch (PDOException $e) { /* ignore */ }
        $redirect_params = $_GET;
        unset($redirect_params['action'], $redirect_params['post_id']);
        $redirect_url = 'campaign_detail.php?' . http_build_query($redirect_params);
        header("Location: " . $redirect_url);
        exit;
    }

    // Retry all or Force Run (GET)
    if (isset($_GET['action'])) {
        $act = $_GET['action'];
        if ($act === 'retry_all' || $act === 'run_now') {
            try {
                $pdo->prepare("UPDATE scheduled_posts SET status='pending', retry_count=0, error_msg=NULL WHERE campaign_id = ? AND status IN ('failed','checkpoint','processing','pending')")->execute([$campaign_id]);
                trigger_campaign_publisher_worker($pdo, $campaign_id);
            } catch (PDOException $e) { /* ignore */ }
            $redirect_params = $_GET;
            unset($redirect_params['action']);
            $redirect_url = 'campaign_detail.php?' . http_build_query($redirect_params);
            header("Location: " . $redirect_url);
            exit;
        }
    }

    // Bulk delete (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['post_ids'])) {
        $ids = array_map('intval', (array)$_POST['post_ids']);
        if (!empty($ids) && $_POST['bulk_action'] === 'delete') {
            $in = implode(',', array_fill(0, count($ids), '?'));
            try {
                $params = array_merge($ids, [$campaign_id]);
                $pdo->prepare("DELETE FROM scheduled_posts WHERE id IN ($in) AND campaign_id = ? AND status IN ('pending','failed','checkpoint','processing')")->execute($params);
            } catch (PDOException $e) { /* ignore */ }
        }
        $redirect_url = "campaign_detail.php?id=$campaign_id";
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $parsed = parse_url($_SERVER['HTTP_REFERER']);
            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $q_params);
                unset($q_params['action'], $q_params['post_id'], $q_params['bulk_action']);
                if (!empty($q_params)) {
                    $redirect_url = 'campaign_detail.php?' . http_build_query($q_params);
                }
            }
        }
        header("Location: " . $redirect_url);
        exit;
    }
}

// ── Now load the page ──────────────────────────────────────────────────────
$current_page = 'manage_posts';
require_once __DIR__ . '/includes/header.php';

$account_id  = $_SESSION['account_id'];
$is_admin    = ($_SESSION['role'] === 'admin');
$campaign_id = intval($_GET['id'] ?? 0);

if (!$campaign_id) {
    header('Location: manage_posts.php');
    exit;
}

// Load campaign info
$campaign = null;
try {
    $auth_where  = ' AND account_id = ?';
    $auth_params = [$campaign_id, $account_id];
    $c_stmt = $pdo->prepare("SELECT * FROM post_campaigns WHERE id = ? $auth_where");
    $c_stmt->execute($auth_params);
    $campaign = $c_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

if (!$campaign) {
    echo "<div class='page-title'>Không tìm thấy chiến dịch</div>";
    echo "<div class='card' style='text-align:center;padding:40px;'>Chiến dịch không tồn tại. <a href='manage_posts.php'>← Quay lại</a></div>";
    include 'includes/footer.php';
    exit;
}

// Filter
$filter = $_GET['filter'] ?? 'all';
if ($filter === 'published')     $filter_sql = "AND sp.status = 'published'";
elseif ($filter === 'pending')   $filter_sql = "AND sp.status IN ('pending','processing')";
elseif ($filter === 'failed')    $filter_sql = "AND sp.status IN ('failed','checkpoint')";
else                             $filter_sql = '';

// Pagination
$per_page = 50;
$current_pg = max(1, intval($_GET['pg'] ?? 1));
$offset = ($current_pg - 1) * $per_page;

// Count total for pagination
$total_filtered = 0;
try {
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts sp WHERE sp.campaign_id = ? $filter_sql");
    $cnt_stmt->execute([$campaign_id]);
    $total_filtered = (int)$cnt_stmt->fetchColumn();
} catch (PDOException $e) {}
$total_pgs = max(1, ceil($total_filtered / $per_page));

// Posts
$posts = [];
try {
    $posts_stmt = $pdo->prepare("
        SELECT sp.*, 
               p.name AS page_name,
               p.avatar AS page_avatar,
               COALESCE(yt1.channel_title, yt2.channel_title) AS yt_channel_name,
               COALESCE(yt1.channel_avatar, yt2.channel_avatar) AS yt_channel_avatar,
               bc.channel_name AS buffer_channel_name,
               bc.avatar AS buffer_avatar,
               bc.service AS buffer_service,
               tt.display_name AS tt_channel_name,
               tt.avatar AS tt_channel_avatar,
               COALESCE(ig1.username, ig2.username) AS ig_username,
               COALESCE(ig1.name, ig2.name) AS ig_name,
               COALESCE(ig1.avatar, ig2.avatar) AS ig_avatar
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id AND sp.post_type NOT LIKE 'Buffer%' AND sp.post_type != 'YouTube' AND sp.post_type != 'TikTok' AND sp.post_type NOT LIKE 'Instagram%'
        LEFT JOIN instagram_accounts ig1 ON CAST(sp.page_id AS BINARY) = CAST(ig1.ig_user_id AS BINARY) AND sp.post_type LIKE 'Instagram%'
        LEFT JOIN instagram_accounts ig2 ON CAST(sp.page_id AS BINARY) = CAST(ig2.id AS BINARY) AND sp.post_type LIKE 'Instagram%'
        LEFT JOIN youtube_channels yt1 ON CAST(sp.page_id AS BINARY) = CAST(yt1.channel_id AS BINARY) AND sp.post_type = 'YouTube'
        LEFT JOIN youtube_channels yt2 ON CAST(sp.page_id AS BINARY) = CAST(yt2.id AS BINARY) AND sp.post_type = 'YouTube'
        LEFT JOIN buffer_channels bc ON CAST(sp.page_id AS BINARY) = CAST(bc.channel_id AS BINARY) AND sp.post_type LIKE 'Buffer%'
        LEFT JOIN tiktok_accounts tt ON CAST(sp.page_id AS BINARY) = CAST(tt.id AS BINARY) AND sp.post_type = 'TikTok'
        WHERE sp.campaign_id = ? $filter_sql
        ORDER BY sp.scheduled_time ASC, sp.id ASC
        LIMIT $per_page OFFSET $offset
    ");
    $posts_stmt->execute([$campaign_id]);
    $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    file_put_contents('debug_db.log', $e->getMessage() . "\n", FILE_APPEND);
    try {
        $posts_stmt = $pdo->prepare("
            SELECT sp.*, p.name AS page_name, p.avatar AS page_avatar
            FROM scheduled_posts sp
            LEFT JOIN pages p ON sp.page_id = p.page_id
            WHERE sp.campaign_id = ? $filter_sql
            ORDER BY sp.scheduled_time ASC, sp.id ASC
            LIMIT $per_page OFFSET $offset
        ");
        $posts_stmt->execute([$campaign_id]);
        $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {}
}

$has_comment_status_col = true;


$stats = ['pub' => 0, 'pend' => 0, 'proc' => 0, 'fail' => 0, 'total' => 0];
try {
    $st = $pdo->prepare("SELECT
        SUM(CASE WHEN status='published'  THEN 1 ELSE 0 END) AS pub,
        SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) AS pend,
        SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS proc,
        SUM(CASE WHEN status='failed'     THEN 1 ELSE 0 END) AS fail,
        SUM(CASE WHEN status='checkpoint' THEN 1 ELSE 0 END) AS chk,
        COUNT(*) AS total
        FROM scheduled_posts WHERE campaign_id = ?");
    $st->execute([$campaign_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $stats = $row;
} catch (PDOException $e) {}

$progress = $stats['total'] > 0 ? round($stats['pub'] / $stats['total'] * 100) : 0;

function status_bg($s) {
    if ($s === 'published')  return '#d1fae5';
    if ($s === 'pending')    return '#fef3c7';
    if ($s === 'processing') return '#e0f2fe';
    if ($s === 'failed')     return '#fee2e2';
    if ($s === 'checkpoint') return '#fee2e2';
    return '#f3f4f6';
}
function status_tc($s) {
    if ($s === 'published')  return '#065f46';
    if ($s === 'pending')    return '#d97706';
    if ($s === 'processing') return '#0369a1';
    if ($s === 'failed')     return '#dc2626';
    if ($s === 'checkpoint') return '#991b1b';
    return '#6b7280';
}
function status_label($s) {
    if ($s === 'published')  return '✅ Đã đăng';
    if ($s === 'pending')    return '⏳ Chờ';
    if ($s === 'processing') return '🔄 Đang đăng';
    if ($s === 'failed')     return '❌ Lỗi';
    if ($s === 'checkpoint') return '🚫 Tài khoản bị checkpoint';
    return htmlspecialchars($s);
}
?>

<style>
/* Evondev Skill Styling for Campaign Detail */
.cd-container,
.cd-container button,
.cd-container input,
.cd-container select,
.cd-container textarea {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.cd-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

.cd-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13.5px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 20px;
}
.cd-breadcrumb a {
    color: #6366f1;
    text-decoration: none;
    transition: color 0.2s ease;
}
.cd-breadcrumb a:hover {
    color: #4f46e5;
    text-decoration: underline;
}

.cd-header-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
    border: 1px solid #312e81;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(15, 23, 42, 0.15);
    color: #ffffff;
}

.cd-header-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 20px;
}

.cd-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.cd-header-info p {
    font-size: 13px;
    color: #cbd5e1;
    margin: 0 0 14px 0;
}

.cd-stat-grid {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.cd-stat-card {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(8px);
    padding: 12px 18px;
    border-radius: 12px;
    text-align: center;
    min-width: 100px;
}

.cd-stat-num {
    font-size: 22px;
    font-weight: 800;
    line-height: 1.2;
}

.cd-stat-lbl {
    font-size: 11px;
    color: #e2e8f0;
    margin-top: 2px;
    font-weight: 600;
}

.cd-progress-bar-wrap {
    background: rgba(255, 255, 255, 0.15);
    height: 10px;
    border-radius: 99px;
    overflow: hidden;
    margin-top: 8px;
}

.cd-progress-bar-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.4s ease;
}

.cd-action-bar {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.12);
    flex-wrap: wrap;
    align-items: center;
}

.cd-btn {
    padding: 9px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    text-decoration: none;
}
.cd-btn-blue {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
}
.cd-btn-blue:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.4);
}
.cd-btn-danger {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}
.cd-btn-danger:hover {
    background: #fee2e2;
    color: #b91c1c;
}

.cd-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.cd-tab-item {
    padding: 9px 16px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    border: 1px solid #e2e8f0;
    color: #64748b;
    background: #ffffff;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.cd-tab-item:hover {
    color: #1e293b;
    border-color: #cbd5e1;
    background: #f8fafc;
}
.cd-tab-item.active {
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.2);
}
.cd-tab-count {
    background: rgba(0, 0, 0, 0.08);
    color: inherit;
    padding: 2px 8px;
    border-radius: 99px;
    font-size: 11.5px;
    font-weight: 800;
}
.cd-tab-item.active .cd-tab-count {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

.cd-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    margin-bottom: 24px;
}

.cd-toolbar {
    padding: 12px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}

.cd-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

.cd-table th {
    text-align: left;
    padding: 12px 18px;
    font-size: 12.5px;
    color: #64748b;
    font-weight: 700;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.cd-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13.5px;
    color: #1e293b;
    vertical-align: middle;
}

.cd-table tr:last-child td {
    border-bottom: none;
}

.cd-table tr:hover td {
    background: #f8fafc;
}

.cd-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 99px;
    font-size: 12px;
    font-weight: 700;
}
</style>

<div class="cd-container">
    <!-- Breadcrumb -->
    <div class="cd-breadcrumb">
        <a href="manage_posts.php">← Quản lý Chiến dịch</a>
        <span>/</span>
        <span style="color:#0f172a; font-weight:700;"><?php echo htmlspecialchars($campaign['name']); ?></span>
    </div>

    <!-- Campaign Header Card -->
    <div class="cd-header-card">
        <div class="cd-header-top">
            <div class="cd-header-info" style="flex: 1; min-width: 260px;">
                <h1>
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    <?php echo htmlspecialchars($campaign['name']); ?>
                </h1>
                <p>
                    📅 Tạo lúc: <strong><?php echo date('H:i d/m/Y', strtotime($campaign['created_at'])); ?></strong>
                    <?php if (!empty($campaign['scheduled_time'])): ?>
                        | ⏰ Hẹn giờ: <strong><?php echo date('H:i d/m/Y', strtotime($campaign['scheduled_time'])); ?></strong>
                    <?php endif; ?>
                </p>
                <div style="margin-top: 10px;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:700; margin-bottom:6px; color:#e2e8f0;">
                        <span>Tiến độ hoàn thành</span>
                        <span><?php echo (int)$stats['pub']; ?> / <?php echo (int)$stats['total']; ?> bài (<?php echo $progress; ?>%)</span>
                    </div>
                    <div class="cd-progress-bar-wrap">
                        <div class="cd-progress-bar-fill" style="width:<?php echo $progress; ?>%; background:<?php echo $progress==100 ? 'linear-gradient(90deg, #10b981, #34d399)' : 'linear-gradient(90deg, #6366f1, #818cf8)'; ?>;"></div>
                    </div>
                </div>
            </div>

            <!-- Stats grid -->
            <div class="cd-stat-grid">
                <div class="cd-stat-card">
                    <div class="cd-stat-num" style="color: #34d399;"><?php echo (int)$stats['pub']; ?></div>
                    <div class="cd-stat-lbl">✅ Đã đăng</div>
                </div>
                <div class="cd-stat-card">
                    <div class="cd-stat-num" style="color: #fbbf24;"><?php echo (int)$stats['pend'] + (int)$stats['proc']; ?></div>
                    <div class="cd-stat-lbl">⏳ Đang chờ</div>
                </div>
                <div class="cd-stat-card">
                    <div class="cd-stat-num" style="color: #f87171;"><?php echo (int)$stats['fail'] + (int)($stats['chk'] ?? 0); ?></div>
                    <div class="cd-stat-lbl">❌ Lỗi / Checkpoint</div>
                </div>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="cd-action-bar">
            <?php if ((int)$stats['fail'] > 0 || (int)($stats['chk'] ?? 0) > 0 || (int)$stats['proc'] > 0): ?>
                <button onclick="showCampaignModal('retry_all', <?php echo $campaign_id; ?>, 'Thử lại tất cả bài kẹt/lỗi trong chiến dịch này?', false)" class="cd-btn cd-btn-blue">
                    🔄 Reset / Thử Lại Tất Cả
                </button>
            <?php endif; ?>
            <?php if ((int)$stats['pend'] > 0 || (int)$stats['proc'] > 0 || (int)$stats['fail'] > 0 || (int)($stats['chk'] ?? 0) > 0): ?>
                <button onclick="showCampaignModal('delete_pending', <?php echo $campaign_id; ?>, 'Xóa toàn bộ bài chưa hoàn tất trong chiến dịch này?', true)" class="cd-btn cd-btn-danger">
                    🗑 Xóa bài chưa hoàn tất/lỗi
                </button>
            <?php endif; ?>
            <?php if ((int)$stats['total'] === 0): ?>
                <button onclick="showCampaignModal('delete_campaign_empty', <?php echo $campaign_id; ?>, 'Xóa chiến dịch trống này?', true)" class="cd-btn cd-btn-danger">
                    🗑 Xóa Campaign trống
                </button>
            <?php endif ?>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="cd-tabs">
        <?php foreach ([
            ['all',       'Tất cả bài đăng',     (int)$stats['total']],
            ['published', '✅ Đã đăng thành công', (int)$stats['pub']],
            ['pending',   '⏳ Đang chờ / Xử lý',  (int)$stats['pend']+(int)$stats['proc']],
            ['failed',    '❌ Bị dừng / Lỗi',    (int)$stats['fail']+(int)($stats['chk'] ?? 0)],
        ] as $tab):
            list($val, $lbl, $cnt) = $tab;
            $active = ($filter === $val); ?>
        <a href="campaign_detail.php?id=<?php echo $campaign_id; ?>&filter=<?php echo $val; ?>" class="cd-tab-item <?php echo $active ? 'active' : ''; ?>">
            <span><?php echo $lbl; ?></span>
            <span class="cd-tab-count"><?php echo $cnt; ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Posts Table with Bulk Action -->
    <div class="cd-table-card">
        <?php if (empty($posts)): ?>
            <div style="text-align:center; padding:50px 20px; color:#64748b;">
                <div style="font-size: 40px; margin-bottom: 10px;">📋</div>
                <h4 style="margin: 0 0 6px 0; font-weight: 800; color: #0f172a; font-size: 15px;">Không Có Bài Viết Nào</h4>
                <p style="margin: 0; font-size: 13px;">Không tìm thấy bài viết nào phù hợp với bộ lọc hiện tại.</p>
            </div>
        <?php else: ?>
        <form id="bulkDeleteForm" method="POST" action="campaign_detail.php?id=<?php echo $campaign_id; ?>">
            <input type="hidden" name="bulk_action" value="delete">
            
            <div class="cd-toolbar">
                <label style="font-size:13.5px; cursor:pointer; display:flex; align-items:center; gap:8px; font-weight:700; color:#334155;">
                    <input type="checkbox" id="selectAll" onchange="toggleAll(this)" style="width:17px; height:17px; accent-color:#6366f1; cursor:pointer;">
                    Chọn tất cả
                </label>
                <button type="submit" onclick="return confirmBulk()" class="cd-btn cd-btn-danger" style="padding: 6px 14px; font-size: 12.5px;">
                    🗑 Xóa đã chọn
                </button>
            </div>

            <div style="overflow-x: auto;">
                <table class="cd-table">
                    <thead>
                        <tr>
                            <th style="width:36px; text-align:center;"></th>
                            <th style="width:320px;">Kênh / Nguồn Đăng</th>
                            <th>Loại Bài</th>
                            <th>Thời Gian Hẹn</th>
                            <th>Trạng Thái</th>
                            <?php if ($has_comment_status_col): ?>
                            <th>💬 Bình Luận</th>
                            <?php endif; ?>
                            <th style="text-align:right;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($posts as $post):
                        $s = $post['status'];
                        $content_data = @json_decode($post['content'], true);
                        $desc = is_array($content_data) ? ($content_data['description'] ?? $content_data['text'] ?? '') : $post['content'];
                        $desc_short = mb_strimwidth($desc, 0, 80, '…');
                        $original_source = is_array($content_data) ? ($content_data['original_source'] ?? '') : '';
                        if (empty($original_source) && !empty($post['media_path'])) {
                            $mp = $post['media_path'];
                            if (strpos($mp, 'tiktok:') === 0) {
                                $original_source = substr($mp, 7);
                            } elseif (strpos($mp, 'folder:') === 0) {
                                $original_source = 'Google Drive Folder';
                            } elseif (strpos($mp, 'drive:') === 0) {
                                $original_source = 'Google Drive File';
                            } elseif (strpos($mp, 'uploads/') !== false) {
                                $decoded_mp = @json_decode($mp, true);
                                if (is_array($decoded_mp)) {
                                    $original_source = implode(', ', array_map('basename', $decoded_mp));
                                } else {
                                    $original_source = basename($mp);
                                }
                            }
                        }
                        if (strpos($original_source, 'Google Drive Folder') === 0) {
                            $original_source = 'Google Drive Folder';
                        } elseif (strpos($original_source, 'Google Drive File') === 0) {
                            $original_source = 'Google Drive File';
                        }
                    ?>
                    <tr>
                        <td style="text-align:center;">
                            <?php if (in_array($s, ['pending','failed','checkpoint'])): ?>
                            <input type="checkbox" name="post_ids[]" value="<?php echo $post['id']; ?>" class="row-check" style="width:16px; height:16px; accent-color:#6366f1; cursor:pointer;">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                                $is_buffer    = strpos($post['post_type'], 'Buffer') !== false;
                                $is_youtube   = strpos($post['post_type'], 'YouTube') !== false;
                                $is_tiktok    = strpos($post['post_type'], 'TikTok') !== false;
                                $is_instagram = strpos($post['post_type'], 'Instagram') !== false;
                                
                                if ($is_buffer) {
                                    $disp_name = !empty($post['buffer_channel_name']) ? $post['buffer_channel_name'] : ($post['page_id'] ?? '—');
                                    $disp_avatar = $post['buffer_avatar'] ?? null;
                                } elseif ($is_youtube) {
                                    $disp_name = !empty($post['yt_channel_name']) ? $post['yt_channel_name'] : '—';
                                    $disp_avatar = !empty($post['yt_channel_avatar']) ? $post['yt_channel_avatar'] : null;
                                } elseif ($is_tiktok) {
                                    $disp_name = !empty($post['tt_channel_name']) ? $post['tt_channel_name'] : '—';
                                    $disp_avatar = !empty($post['tt_channel_avatar']) ? $post['tt_channel_avatar'] : null;
                                } elseif ($is_instagram) {
                                    $disp_name = !empty($post['ig_username']) ? ('@' . $post['ig_username']) : (!empty($post['page_name']) ? $post['page_name'] : '—');
                                    $disp_avatar = !empty($post['ig_avatar']) ? $post['ig_avatar'] : ($post['page_avatar'] ?? null);
                                } else {
                                    $disp_name = !empty($post['page_name']) ? $post['page_name'] : '—';
                                    $disp_avatar = $post['page_avatar'] ?? null;
                                }
                                $disp_desc = !empty($original_source) ? $original_source : $desc;
                            ?>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <?php if (!empty($disp_avatar)): ?>
                                    <img src="<?php echo htmlspecialchars($disp_avatar); ?>" style="width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0; border: 1px solid #cbd5e1;" alt="">
                                <?php else: ?>
                                    <div style="width:32px; height:32px; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:12px; color:#475569; font-weight:800; flex-shrink:0; border: 1px solid #cbd5e1;">
                                        <?php echo mb_strtoupper(mb_substr($disp_name, 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="min-width:0; flex:1;">
                                    <div style="font-weight:700; font-size:13.5px; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($disp_name); ?>">
                                        <?php echo htmlspecialchars($disp_name); ?>
                                    </div>
                                    <div style="font-size:12px; color:#64748b; margin-top:2px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;" title="<?php echo htmlspecialchars($disp_desc); ?>">
                                        <?php 
                                            if (!empty($original_source)) {
                                                echo htmlspecialchars($original_source);
                                            } else {
                                                echo htmlspecialchars($desc_short);
                                            }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (in_array($s, ['failed', 'checkpoint']) && !empty($post['error_msg'])): ?>
                            <div style="font-size:11.5px; color:#dc2626; margin-top:6px; background:#fef2f2; border: 1px solid #fecaca; padding:6px 10px; border-radius:8px; word-break:break-all; line-height:1.4;">
                                <strong>Log lỗi:</strong> <?php echo htmlspecialchars($post['error_msg']); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="background:#f1f5f9; color:#475569; font-size:12px; font-weight:700; padding:4px 10px; border-radius:8px; border:1px solid #e2e8f0;">
                                <?php echo htmlspecialchars($post['post_type']); ?>
                            </span>
                        </td>
                        <td style="font-size:13px; color:#475569; font-weight:600; white-space:nowrap;">
                            <?php echo date('H:i d/m/Y', strtotime($post['scheduled_time'])); ?>
                        </td>
                        <td>
                            <?php if ($s === 'processing'): ?>
                                <span class="cd-badge" style="background:#e0f2fe; color:#0369a1;">
                                    🔄 Đang đăng
                                </span>
                                <?php if (!empty($post['error_msg'])): ?>
                                    <div style="font-size:11px; color:#0284c7; margin-top:4px; font-weight:600; background:#f0f9ff; padding:3px 8px; border-radius:6px; border:1px solid #bae6fd; max-width:240px;">
                                        ⚡ <?php echo htmlspecialchars($post['error_msg']); ?>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($s === 'published'): ?>
                                <span class="cd-badge" style="background:#d1fae5; color:#065f46;">✅ Đã đăng</span>
                            <?php elseif ($s === 'pending'): ?>
                                <?php if (!empty($post['preupload_status']) && $post['preupload_status'] === 'uploaded' && !empty($post['preuploaded_media_id'])): ?>
                                    <span class="cd-badge" style="background:#ccfbf1; color:#0f766e; border:1px solid #99f6e4;" title="Đã Upload nháp sang Meta CDN, sẵn sàng xuất bản tức thì 0.5s">⚡ Đã Upload nháp</span>
                                <?php else: ?>
                                    <span class="cd-badge" style="background:#fef3c7; color:#d97706;">⏳ Chờ</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cd-badge" style="background:<?php echo status_bg($s); ?>; color:<?php echo status_tc($s); ?>;"><?php echo status_label($s); ?></span>
                                <?php if (!empty($post['retry_count'])): ?>
                                    <span style="font-size:11px; color:#94a3b8; font-weight:700;"> ×<?php echo (int)$post['retry_count']; ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($has_comment_status_col):
                            $cs = $post['comment_status'] ?? null;
                            if (!isset($post['comment_lines']) || empty($post['comment_lines'])) {
                                $cs_bg = '#f1f5f9'; $cs_tc = '#64748b'; $cs_label = '—';
                            } elseif ($cs === 'done') {
                                $cs_bg = '#d1fae5'; $cs_tc = '#065f46'; $cs_label = '✅ Đã BL';
                            } elseif ($cs === 'error') {
                                $cs_bg = '#fef2f2'; $cs_tc = '#dc2626'; $cs_label = '❌ Lỗi BL';
                            } elseif ($cs === 'expired_insights') {
                                $cs_bg = '#f1f5f9'; $cs_tc = '#64748b'; $cs_label = '💬 Không đủ ĐK';
                            } elseif ($cs === 'pending') {
                                $cs_bg = '#fef3c7'; $cs_tc = '#d97706'; $cs_label = '⏳ Chờ BL';
                            } else {
                                $cs_bg = '#e0f2fe'; $cs_tc = '#0369a1'; $cs_label = '💬 Đã cài';
                            }
                        ?>
                        <td>
                            <span class="cd-badge" style="background:<?php echo $cs_bg; ?>; color:<?php echo $cs_tc; ?>;"><?php echo $cs_label; ?></span>
                        </td>
                        <?php endif; ?>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                            <?php 
                                $view_id = !empty($post['fb_post_id']) ? $post['fb_post_id'] : (!empty($post['error_msg']) ? $post['error_msg'] : '');
                                if ($s === 'published' && $view_id): 
                                    if (strpos($view_id, 'http://') === 0 || strpos($view_id, 'https://') === 0) {
                                        $clean_u = explode('#', $view_id)[0];
                                        $clean_u = explode('|', $clean_u)[0];
                                        $view_url = htmlspecialchars($clean_u);
                                    } elseif (strpos($post['post_type'], 'Buffer') !== false) {
                                        $svc = strtolower($post['buffer_service'] ?? '');
                                        $cname = ltrim(trim($post['buffer_channel_name'] ?? ''), '@');
                                        
                                        if (strpos($svc, 'instagram') !== false || strpos(strtolower($post['post_type']), 'instagram') !== false) {
                                            $view_url = !empty($cname) ? "https://www.instagram.com/" . htmlspecialchars($cname) . "/" : "https://www.instagram.com/";
                                        } elseif (strpos($svc, 'tiktok') !== false) {
                                            $view_url = !empty($cname) ? "https://www.tiktok.com/@" . htmlspecialchars($cname) : "https://www.tiktok.com/";
                                        } elseif (strpos($svc, 'threads') !== false) {
                                            $view_url = !empty($cname) ? "https://www.threads.net/@" . htmlspecialchars($cname) : "https://www.threads.net/";
                                        } elseif (strpos($svc, 'pinterest') !== false) {
                                            $view_url = !empty($cname) ? "https://www.pinterest.com/" . htmlspecialchars($cname) . "/" : "https://www.pinterest.com/";
                                        } elseif (strpos($svc, 'twitter') !== false || $svc === 'x') {
                                            $view_url = !empty($cname) ? "https://x.com/" . htmlspecialchars($cname) : "https://x.com/";
                                        } elseif (strpos($svc, 'youtube') !== false) {
                                            $view_url = !empty($cname) ? "https://www.youtube.com/@" . htmlspecialchars($cname) : "https://www.youtube.com/";
                                        } else {
                                            $view_url = "https://publish.buffer.com/channels/" . htmlspecialchars($post['page_id']) . "/schedule?tab=sent";
                                        }
                                    } elseif ($post['post_type'] === 'YouTube') {
                                        $view_url = "https://youtube.com/watch?v=" . htmlspecialchars($view_id);
                                    } elseif ($is_instagram) {
                                        $ig_uname = !empty($post['ig_username']) ? ltrim(trim($post['ig_username']), '@') : '';
                                        if (!empty($ig_uname)) {
                                            $view_url = "https://www.instagram.com/" . htmlspecialchars($ig_uname) . "/";
                                        } else {
                                            $view_url = "https://www.instagram.com/";
                                        }
                                    } else {
                                        $view_url = "https://facebook.com/" . htmlspecialchars($view_id);
                                    }
                            ?>
                                <a href="<?php echo $view_url; ?>" target="_blank" style="font-size:12.5px; color:#4f46e5; font-weight:700; text-decoration:none; padding:4px 10px; border:1px solid #c7d2fe; border-radius:6px; background:#eef2ff;">Xem</a>
                            <?php endif; ?>
                            <?php if (in_array($s, ['pending','failed','checkpoint'])): ?>
                                <a href="#" onclick="showSingleDeleteModal(<?php echo $post['id']; ?>, '<?php echo $filter; ?>'); return false;" style="font-size:12.5px; color:#dc2626; font-weight:700; text-decoration:none; padding:4px 10px; border:1px solid #fecaca; border-radius:6px; background:#fef2f2;">Xóa</a>
                            <?php endif; ?>
                            <?php if (in_array($s, ['failed','checkpoint'])): ?>
                                <a href="campaign_detail.php?id=<?php echo $campaign_id; ?>&action=retry&post_id=<?php echo $post['id']; ?>&filter=<?php echo $filter; ?>&pg=<?php echo $current_pg; ?>" style="font-size:12.5px; color:#059669; font-weight:700; text-decoration:none; padding:4px 10px; border:1px solid #a7f3d0; border-radius:6px; background:#ecfdf5;">Thử lại</a>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($total_pgs > 1): ?>
    <div style="display:flex; justify-content:center; align-items:center; gap:6px; margin-top:20px; flex-wrap:wrap;">
        <?php
        $base_url = "campaign_detail.php?id=$campaign_id&filter=$filter";
        if ($current_pg > 1): ?>
            <a href="<?php echo $base_url . '&pg=' . ($current_pg - 1); ?>" style="padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px; text-decoration:none; color:#334155; font-size:13px; font-weight:700; background:#ffffff;">← Trước</a>
        <?php endif; ?>
        <?php
        $start_pg = max(1, $current_pg - 3);
        $end_pg = min($total_pgs, $current_pg + 3);
        for ($i = $start_pg; $i <= $end_pg; $i++): ?>
            <a href="<?php echo $base_url . '&pg=' . $i; ?>" style="padding:8px 14px; border:1px solid <?php echo $i==$current_pg ? '#0f172a' : '#cbd5e1'; ?>; border-radius:8px; text-decoration:none; color:<?php echo $i==$current_pg ? '#ffffff' : '#334155'; ?>; background:<?php echo $i==$current_pg ? '#0f172a' : '#ffffff'; ?>; font-weight:700; font-size:13px;"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($current_pg < $total_pgs): ?>
            <a href="<?php echo $base_url . '&pg=' . ($current_pg + 1); ?>" style="padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px; text-decoration:none; color:#334155; font-size:13px; font-weight:700; background:#ffffff;">Sau →</a>
        <?php endif; ?>
        <span style="padding:6px 12px; font-size:12.5px; color:#64748b; font-weight:600;">(<?php echo number_format($total_filtered); ?> bài · Trang <?php echo $current_pg; ?>/<?php echo $total_pgs; ?>)</span>
    </div>
    <?php endif; ?>
</div>

<!-- Confirm Modal -->
<div id="cdConfirmModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(8px); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:20px; padding:32px; max-width:440px; width:90%; box-shadow:0 24px 60px rgba(0,0,0,0.2); text-align:center; color:#0f172a;">
        <div id="cdModalIcon" style="font-size:48px; margin-bottom:14px;">⚠️</div>
        <h3 id="cdModalTitle" style="margin:0 0 10px; font-size:18px; font-weight:800; color:#0f172a;"></h3>
        <p id="cdModalMsg" style="margin:0 0 24px; font-size:14px; color:#64748b; line-height:1.5;"></p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button onclick="hideCdModal()" style="padding:10px 24px; border:1px solid #cbd5e1; border-radius:10px; background:#ffffff; color:#334155; cursor:pointer; font-size:14px; font-weight:700;">Huỷ</button>
            <button id="cdModalOkBtn" onclick="doCdAction()" style="padding:10px 24px; border:none; border-radius:10px; background:#ef4444; color:#fff; cursor:pointer; font-size:14px; font-weight:700; box-shadow: 0 4px 14px rgba(239,68,68,0.3);">Xác nhận</button>
        </div>
    </div>
</div>

<script>
let _cdAction = null;
let _cdId = null;
let _cdExtra = null;

function showCampaignModal(action, id, msg, isDanger) {
    _cdAction = action;
    _cdId = id;
    _cdExtra = null;
    document.getElementById('cdModalIcon').textContent = isDanger ? '🗑️' : '🔄';
    document.getElementById('cdModalTitle').textContent = isDanger ? 'Xác nhận xóa' : 'Xác nhận thử lại';
    document.getElementById('cdModalMsg').textContent = msg;
    document.getElementById('cdModalOkBtn').style.background = isDanger ? '#ef4444' : '#10b981';
    document.getElementById('cdConfirmModal').style.display = 'flex';
}

function showSingleDeleteModal(postId, filter) {
    _cdAction = 'delete_single';
    _cdId = postId;
    _cdExtra = filter;
    document.getElementById('cdModalIcon').textContent = '🗑️';
    document.getElementById('cdModalTitle').textContent = 'Xác nhận xóa';
    document.getElementById('cdModalMsg').textContent = 'Xóa bài đăng này?';
    document.getElementById('cdModalOkBtn').style.background = '#ef4444';
    document.getElementById('cdConfirmModal').style.display = 'flex';
}

function showBulkDeleteModal(checked) {
    _cdAction = 'bulk_delete';
    _cdId = null;
    _cdExtra = checked;
    document.getElementById('cdModalIcon').textContent = '🗑️';
    document.getElementById('cdModalTitle').textContent = 'Xác nhận xóa';
    document.getElementById('cdModalMsg').textContent = 'Xóa ' + checked + ' bài đã chọn?';
    document.getElementById('cdModalOkBtn').style.background = '#ef4444';
    document.getElementById('cdConfirmModal').style.display = 'flex';
}

function hideCdModal() {
    document.getElementById('cdConfirmModal').style.display = 'none';
    _cdAction = null; _cdId = null; _cdExtra = null;
}

function doCdAction() {
    const cid = <?php echo $campaign_id; ?>;
    const urlParams = new URLSearchParams(window.location.search);
    if (_cdAction === 'retry_all') {
        urlParams.set('action', 'retry_all');
        window.location.href = 'campaign_detail.php?' + urlParams.toString();
    } else if (_cdAction === 'delete_pending') {
        window.location.href = 'manage_posts.php?action=delete_campaign&id=' + cid;
    } else if (_cdAction === 'delete_campaign_empty') {
        window.location.href = 'manage_posts.php?action=delete_campaign&id=' + cid;
    } else if (_cdAction === 'delete_single') {
        urlParams.set('action', 'delete');
        urlParams.set('post_id', _cdId);
        if (_cdExtra) urlParams.set('filter', _cdExtra);
        window.location.href = 'campaign_detail.php?' + urlParams.toString();
    } else if (_cdAction === 'bulk_delete') {
        document.getElementById('cdConfirmModal').style.display = 'none';
        document.getElementById('bulkDeleteForm').submit();
    }
}

document.getElementById('cdConfirmModal').addEventListener('click', function(e) {
    if (e.target === this) hideCdModal();
});

function toggleAll(cb) {
    document.querySelectorAll('.row-check').forEach(function(el) { el.checked = cb.checked; });
}
function confirmBulk() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    if (checked === 0) { alert('Chưa chọn bài nào!'); return false; }
    showBulkDeleteModal(checked);
    return false;
}

// Keep scroll position on reload
document.addEventListener("DOMContentLoaded", function() { 
    const key = 'scrollpos_' + window.location.search;
    if (sessionStorage.getItem(key)) window.scrollTo(0, sessionStorage.getItem(key));
});
window.addEventListener("beforeunload", function() {
    sessionStorage.setItem('scrollpos_' + window.location.search, window.scrollY);
});

// Auto-reload if posts are pending/processing
<?php if (((int)$stats['pend'] + (int)$stats['proc']) > 0): ?>
setTimeout(function() { window.location.reload(); }, 15000);
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>

