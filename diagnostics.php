<?php
// diagnostics.php — Trang chẩn đoán & kích hoạt thủ công Cron
require_once __DIR__ . '/includes/db.php';

// ── Khởi session trước khi check quyền ───────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=utf-8');

// ── Cho phép bypass qua cron_secret (để CLI/cron có thể check) ───────────────
$cron_secret = '';
try {
    $cs = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='cron_secret'");
    if ($cs) $cron_secret = trim($cs->fetchColumn() ?: '');
} catch (Exception $e) {}

$bypass_ok = ($cron_secret && isset($_GET['secret']) && hash_equals($cron_secret, $_GET['secret']));

if (!$bypass_ok) {
    if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('<h2 style="font-family:monospace;color:red;text-align:center;margin-top:100px">403 - Forbidden: Chỉ Admin mới được truy cập trang này.</h2>');
    }
}

$now_php   = date('Y-m-d H:i:s');
$now_mysql = $pdo->query("SELECT NOW()")->fetchColumn();

// ── Lấy thời gian cron chạy cuối ──────────────────────────────────────────────
$last_cron_run = 'Chưa từng chạy';
try {
    $lcr = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='last_insights_cron_run'");
    if ($lcr) $last_cron_run = $lcr->fetchColumn() ?: 'Chưa từng chạy';
} catch (Exception $e) {}

// ── Lấy max_retries từ settings ──────────────────────────────────────────────
$max_retries = 3;
try {
    $mr = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='max_retries'");
    if ($mr) $max_retries = (int)($mr->fetchColumn() ?: 3);
} catch (Exception $e) {}

// ── Lấy cấu hình Throttling từ settings ─────────────────────────────────────
$max_publish_workers = 30;
$max_comment_workers = 15;
try {
    $stmt_throttle = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_publish_workers', 'max_comment_workers')");
    while ($row_t = $stmt_throttle->fetch(PDO::FETCH_ASSOC)) {
        if ($row_t['setting_key'] === 'max_publish_workers') $max_publish_workers = (int)$row_t['setting_value'];
        if ($row_t['setting_key'] === 'max_comment_workers') $max_comment_workers = (int)$row_t['setting_value'];
    }
} catch (Exception $e) {}

// ── Đếm số worker đang thực sự chạy (active) ────────────────────────────────
$active_publish = (int)$pdo->query("SELECT COUNT(DISTINCT page_id) FROM scheduled_posts WHERE status = 'processing'")->fetchColumn();
$tmp_dir = sys_get_temp_dir();
$comment_locks = glob($tmp_dir . "/facebook_comment_worker_account_*.lock") ?: [];
$active_comment = count($comment_locks);

// ── Kích hoạt thủ công nếu có ?run=1 ─────────────────────────────────────────
$run_msg = '';
if (isset($_GET['run'])) {
    ob_start();
    $type = $_GET['run'];
    if ($type === 'publish') {
        require __DIR__ . '/cron/start_publish.php';
    } elseif ($type === 'comment') {
        require __DIR__ . '/cron/start_comment.php';
    } elseif ($type === 'insights') {
        require __DIR__ . '/cron/comment_insights_worker.php';
    }
    $run_msg = nl2br(htmlspecialchars(ob_get_clean()));
    if (isset($_GET['ajax'])) {
        echo strip_tags($run_msg);
        exit;
    }
}

// ── Xóa bài đăng chỉ định ───────────────────────────────────────────────────
if (isset($_GET['delete_post'])) {
    $del_post_id = intval($_GET['delete_post']);
    if ($del_post_id > 0) {
        try {
            $pdo->prepare("DELETE FROM scheduled_posts WHERE id = ?")->execute([$del_post_id]);
        } catch (Exception $e) {}
    }
    header("Location: diagnostics.php");
    exit;
}

// ── Force Reset Stuck Posts ──────────────────────────────────────────────────
$reset_msg = '';
if (isset($_GET['force_reset'])) {
    try {
        $reset_count = $pdo->exec("UPDATE scheduled_posts SET status='pending' WHERE status='processing'");
        $reset_msg = "Đã ép buộc đưa $reset_count bài từ 'processing' về 'pending'.";
    } catch (Exception $e) {
        $reset_msg = "Lỗi reset: " . $e->getMessage();
    }
}

// ── Đếm bài theo trạng thái ───────────────────────────────────────────────────
$stats = $pdo->query("
    SELECT status, COUNT(*) as cnt
    FROM scheduled_posts
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$today_start = date('Y-m-d 00:00:00');
$today_end   = date('Y-m-d 23:59:59');

// ── Đếm bài theo trạng thái HÔM NAY (Tối ưu dùng Index trong ngày) ──────────────
$stats_today = [];
try {
    $stmt_today = $pdo->prepare("
        SELECT status, COUNT(*) as cnt
        FROM scheduled_posts
        WHERE scheduled_time >= ? AND scheduled_time <= ?
        GROUP BY status
    ");
    $stmt_today->execute([$today_start, $today_end]);
    $stats_today = $stmt_today->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

// ── Top 10 Tài khoản bị lỗi nhiều hôm nay (Tối ưu dùng Index) ────────────────────
$top_failed_accounts = [];
try {
    $stmt_tf = $pdo->prepare("
        SELECT sa.username, COUNT(*) as cnt
        FROM scheduled_posts sp
        JOIN system_accounts sa ON sp.account_id = sa.id
        WHERE sp.status = 'failed' 
          AND ((sp.scheduled_time >= ? AND sp.scheduled_time <= ?) OR (sp.updated_at >= ? AND sp.updated_at <= ?))
        GROUP BY sp.account_id, sa.username
        ORDER BY cnt DESC
        LIMIT 10
    ");
    $stmt_tf->execute([$today_start, $today_end, $today_start, $today_end]);
    $top_failed_accounts = $stmt_tf->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Bài viết bị lỗi hôm nay (Tối ưu dùng Index) ──────────────────────────────────
$failed_posts_today = [];
try {
    $failed_today_stmt = $pdo->prepare("
        SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.error_msg, sp.retry_count, sp.updated_at,
               sa.username AS account_name,
               COALESCE(sa.max_retries, ?) AS limit_retries
        FROM scheduled_posts sp
        LEFT JOIN system_accounts sa ON sp.account_id = sa.id
        WHERE sp.status = 'failed' 
          AND ((sp.scheduled_time >= ? AND sp.scheduled_time <= ?) OR (sp.updated_at >= ? AND sp.updated_at <= ?))
        ORDER BY sp.updated_at DESC, sp.id DESC
        LIMIT 50
    ");
    $failed_today_stmt->execute([$max_retries, $today_start, $today_end, $today_start, $today_end]);
    $failed_posts_today = $failed_today_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Thống kê bài viết theo Tài khoản User (Hôm nay & Tổng) ─────────────────
$user_posts_breakdown = [];
try {
    $stmt_ub = $pdo->prepare("
        SELECT 
            COALESCE(sa.username, CONCAT('Acc #', sp.account_id)) AS username,
            COUNT(*) AS total_posts,
            SUM(CASE WHEN sp.scheduled_time >= ? AND sp.scheduled_time <= ? THEN 1 ELSE 0 END) AS today_posts,
            SUM(CASE WHEN sp.status = 'pending' THEN 1 ELSE 0 END) AS pending_posts,
            SUM(CASE WHEN sp.status = 'published' THEN 1 ELSE 0 END) AS published_posts,
            SUM(CASE WHEN sp.status = 'failed' THEN 1 ELSE 0 END) AS failed_posts
        FROM scheduled_posts sp
        LEFT JOIN system_accounts sa ON sp.account_id = sa.id
        GROUP BY sp.account_id, sa.username
        ORDER BY today_posts DESC, total_posts DESC
        LIMIT 15
    ");
    $stmt_ub->execute([$today_start, $today_end]);
    $user_posts_breakdown = $stmt_ub->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}


// ── Bài sẵn sàng đăng (đến/quá giờ) ─────────────────────────────────────────
$ready_total_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.scheduled_time <= NOW()
      AND (sp.retry_count IS NULL OR sp.retry_count < COALESCE(sa.max_retries, ?))
      AND sp.status IN ('pending', 'failed')
");
$ready_total_stmt->execute([$max_retries]);
$ready_total_count = (int)$ready_total_stmt->fetchColumn();

$ready_stmt = $pdo->prepare("
    SELECT sp.id, sp.account_id, sp.page_id, sp.post_type, sp.status, sp.retry_count, sp.scheduled_time, sp.error_msg,
           TIMESTAMPDIFF(MINUTE, sp.scheduled_time, NOW()) AS overdue_minutes,
           sa.username AS account_name,
           COALESCE(sa.max_retries, ?) AS limit_retries
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.scheduled_time <= NOW()
      AND (sp.retry_count IS NULL OR sp.retry_count < COALESCE(sa.max_retries, ?))
      AND sp.status IN ('pending', 'failed')
    ORDER BY sp.id DESC
    LIMIT 20
");
$ready_stmt->execute([$max_retries, $max_retries]);
$ready_posts = $ready_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Bài pending chưa tới giờ ─────────────────────────────────────────────────
$upcoming = $pdo->query("
    SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time,
           TIMESTAMPDIFF(MINUTE, NOW(), sp.scheduled_time) AS minutes_left,
           sa.username AS account_name
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.status = 'pending' AND sp.scheduled_time > NOW()
    ORDER BY sp.scheduled_time ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Bài đang xử lý (Processing) ──────────────────────────────────────────────
$processing_total_count = (int)$pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE status = 'processing'")->fetchColumn();
$processing_posts = $pdo->query("
    SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.updated_at,
           TIMESTAMPDIFF(MINUTE, sp.updated_at, NOW()) AS duration_min,
           sa.username AS account_name
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.status = 'processing'
    ORDER BY sp.updated_at DESC, sp.id DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ── Bài chờ điều kiện Insights ──────────────────────────────────────────────
$insights_total_count = (int)$pdo->query("
    SELECT COUNT(*)
    FROM scheduled_posts
    WHERE status = 'published' 
      AND comment_mode = 'insights' 
      AND comment_done = 0
")->fetchColumn();

$insights_waiting = $pdo->query("
    SELECT sp.id, sp.page_id, sp.fb_post_id, sp.comment_threshold_views, 
           sp.comment_threshold_likes, sp.comment_threshold_comments,
           sp.comment_status, sp.scheduled_time,
           sa.username AS account_name
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.status = 'published' 
      AND sp.comment_mode = 'insights' 
      AND sp.comment_done = 0
    ORDER BY sp.id DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ── Kiểm tra exec() ──────────────────────────────────────────────────────────
$disabled_funcs = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
$exec_ok = function_exists('exec') && !in_array('exec', $disabled_funcs);

// ── Auto-detect PHP binary path (dùng cho hướng dẫn cron) ────────────────────
$php_bin_simple = 'php';
$php_bin_full = 'php';
$php_bin_note = '';

$current_php_version = phpversion();
$version_parts = explode('.', $current_php_version);
if (count($version_parts) >= 2) {
    $ver_num = $version_parts[0] . $version_parts[1];
    $php_bin_full = "/www/server/php/{$ver_num}/bin/php";
    $php_bin_note = 'tự động nhận diện từ PHP Web';
} else {
    if (defined('PHP_BINARY') && PHP_BINARY
        && strpos(PHP_BINARY, 'php-fpm') === false
        && strpos(PHP_BINARY, 'php-cgi') === false
        && @file_exists(PHP_BINARY)) {
        $php_bin_full = PHP_BINARY;
        $php_bin_note = 'tu PHP_BINARY';
    }
    if ($php_bin_full === 'php' || strpos($php_bin_full, 'fpm') !== false) {
        foreach (['/www/server/php/85/bin/php','/www/server/php/84/bin/php',
                  '/www/server/php/83/bin/php','/www/server/php/82/bin/php',
                  '/www/server/php/81/bin/php','/www/server/php/80/bin/php',
                  '/usr/bin/php8.5','/usr/bin/php8.4','/usr/bin/php8.3',
                  '/usr/bin/php8.2','/usr/bin/php8.1','/usr/bin/php','/usr/local/bin/php'] as $p) {
            if (@file_exists($p)) { $php_bin_full = $p; $php_bin_note = 'tim thay tren server'; break; }
        }
    }
}
if ($exec_ok && $php_bin_full === 'php' && PHP_OS_FAMILY !== 'Windows') {
    $w = trim((string)@exec('which php 2>/dev/null'));
    if ($w && file_exists($w)) { $php_bin_full = $w; $php_bin_note = 'which php'; }
}

// Cron directory path tuyet doi thuc te tren server
$cron_dir_path = realpath(__DIR__ . '/cron');

// ── Kiểm tra lock files (worker bị stuck) ────────────────────────────────────
$lock_files = [];
$publish_locks = is_dir($tmp_dir) ? (glob($tmp_dir . '/facebook_publish_worker_page_*.lock') ?: []) : [];
foreach ($publish_locks as $lf) {
    $fp = @fopen($lf, 'r');
    $is_locked = false;
    if ($fp) {
        $is_locked = !flock($fp, LOCK_EX | LOCK_NB);
        if (!$is_locked) flock($fp, LOCK_UN);
        fclose($fp);
    }
    $lock_files[] = [
        'file'   => basename($lf),
        'mtime'  => filemtime($lf),
        'locked' => $is_locked,
        'age_min'=> round((time() - filemtime($lf)) / 60, 1),
    ];
}

// ── Xoá lock files cũ nếu có &clear_locks=1 ──────────────────────────────────
$clear_msg = '';
if (isset($_GET['clear_locks'])) {
    $cleared = 0;
    foreach ($publish_locks as $lf) {
        if (@unlink($lf)) $cleared++;
    }
    $clear_msg = "Đã xoá $cleared lock file(s).";
    $lock_files = []; // Refresh
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cron Diagnostics Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg-main: #060813;
    --bg-surface: rgba(13, 17, 33, 0.7);
    --bg-surface-solid: #0d1121;
    --border-color: rgba(255, 255, 255, 0.06);
    --border-color-hover: rgba(255, 255, 255, 0.12);
    --text-primary: #f3f4f6;
    --text-secondary: #9ca3af;
    --text-muted: #626a7a;
    
    --color-primary: #6366f1;
    --color-primary-hover: #4f46e5;
    --color-primary-glow: rgba(99, 102, 241, 0.15);
    
    --color-success: #10b981;
    --color-success-bg: rgba(16, 185, 129, 0.08);
    --color-success-border: rgba(16, 185, 129, 0.15);
    
    --color-warning: #f59e0b;
    --color-warning-bg: rgba(245, 158, 11, 0.08);
    --color-warning-border: rgba(245, 158, 11, 0.15);
    
    --color-danger: #ef4444;
    --color-danger-bg: rgba(239, 68, 68, 0.08);
    --color-danger-border: rgba(239, 68, 68, 0.15);
    
    --color-info: #06b6d4;
    --color-info-bg: rgba(6, 182, 212, 0.08);
    --color-info-border: rgba(6, 182, 212, 0.15);
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background-color: var(--bg-main);
    background-image: 
        radial-gradient(circle at 20% 0%, rgba(99, 102, 241, 0.08) 0%, transparent 40%),
        radial-gradient(circle at 80% 90%, rgba(6, 182, 212, 0.08) 0%, transparent 40%);
    background-attachment: fixed;
    color: var(--text-primary);
    min-height: 100vh;
    padding: 24px;
}

/* Scrollbar styling */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.02); }
::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.2); }

.dashboard-wrapper {
    max-width: 1600px;
    margin: 0 auto;
}

.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 28px;
    flex-wrap: wrap;
    gap: 16px;
}

.header-title h1 {
    font-size: 26px;
    font-weight: 700;
    letter-spacing: -0.02em;
    background: linear-gradient(135deg, #ffffff 30%, #a5b4fc 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    display: flex;
    align-items: center;
    gap: 10px;
}

.system-pulse {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-success);
    background: var(--color-success-bg);
    border: 1px solid var(--color-success-border);
    padding: 4px 10px;
    border-radius: 99px;
}

.pulse-dot {
    width: 8px;
    height: 8px;
    background-color: var(--color-success);
    border-radius: 50%;
    position: relative;
}
.pulse-dot::after {
    content: '';
    position: absolute;
    width: 100%;
    height: 100%;
    background-color: inherit;
    border-radius: 50%;
    animation: pulse 1.5s ease-out infinite;
}
@keyframes pulse {
    0% { transform: scale(1); opacity: 1; }
    100% { transform: scale(3.5); opacity: 0; }
}

.dashboard-layout {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 28px;
    align-items: start;
}

@media (max-width: 1150px) {
    .dashboard-layout {
        grid-template-columns: 1fr;
    }
}

.card {
    background: var(--bg-surface);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 22px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.25);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.card:hover {
    border-color: var(--border-color-hover);
    box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.35);
    transform: translateY(-2px);
}

.card-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 12px;
}

.card-title svg {
    color: var(--color-primary);
}

/* Stats grid for right side panel */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-box {
    background: linear-gradient(135deg, rgba(20, 27, 50, 0.4) 0%, rgba(10, 14, 28, 0.7) 100%);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 18px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s;
}

.stat-box:hover {
    border-color: rgba(255, 255, 255, 0.1);
    transform: translateY(-1px);
}

.stat-box-value {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 6px;
}

.stat-box-label {
    font-size: 12px;
    color: var(--text-secondary);
    font-weight: 500;
}

.stat-box-icon {
    position: absolute;
    right: 14px;
    top: 18px;
    opacity: 0.15;
    color: var(--text-primary);
}

/* Color types for stats */
.stat-processing { border-left: 3px solid var(--color-info); }
.stat-processing .stat-box-value { color: var(--color-info); }
.stat-overdue { border-left: 3px solid var(--color-danger); }
.stat-overdue .stat-box-value { color: var(--color-danger); }
.stat-insights { border-left: 3px solid var(--color-success); }
.stat-insights .stat-box-value { color: var(--color-success); }

/* Table styles */
.table-responsive {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    text-align: left;
}

th {
    padding: 10px 14px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
    background: rgba(255, 255, 255, 0.01);
}

td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    vertical-align: middle;
}

tr:hover td {
    background: rgba(255, 255, 255, 0.015);
}

tr:last-child td {
    border-bottom: none;
}

/* Badge System */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.badge.pending { background: var(--color-warning-bg); color: var(--color-warning); border: 1px solid var(--color-warning-border); }
.badge.failed { background: var(--color-danger-bg); color: var(--color-danger); border: 1px solid var(--color-danger-border); }
.badge.published { background: var(--color-success-bg); color: var(--color-success); border: 1px solid var(--color-success-border); }
.badge.processing { background: var(--color-info-bg); color: var(--color-info); border: 1px solid var(--color-info-border); }
.badge.locked { background: var(--color-danger-bg); color: var(--color-danger); border: 1px solid var(--color-danger-border); }
.badge.free { background: var(--color-success-bg); color: var(--color-success); border: 1px solid var(--color-success-border); }

/* Buttons */
.btn-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid transparent;
}

.btn-primary { background: var(--color-primary); color: white; box-shadow: 0 4px 12px var(--color-primary-glow); }
.btn-primary:hover { background: var(--color-primary-hover); transform: translateY(-1px); }

.btn-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15); }
.btn-success:hover { transform: translateY(-1px); }

.btn-info { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color: white; box-shadow: 0 4px 12px rgba(6, 182, 212, 0.15); }
.btn-info:hover { transform: translateY(-1px); }

.btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15); }
.btn-danger:hover { transform: translateY(-1px); }

.btn-secondary { background: rgba(255, 255, 255, 0.05); color: var(--text-primary); border-color: var(--border-color); }
.btn-secondary:hover { background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.15); }

/* Monospace text */
.mono {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
}

/* Custom lists */
.info-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.03);
    padding-bottom: 8px;
}

.info-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.info-label {
    color: var(--text-secondary);
}

.info-value {
    font-weight: 500;
}

/* Shell / Output styling */
.run-output {
    background: #04060f;
    border: 1px solid var(--border-color-hover);
    border-radius: 10px;
    padding: 16px;
    margin-top: 14px;
    color: #34d399;
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    white-space: pre-wrap;
    box-shadow: inset 0 2px 10px rgba(0,0,0,0.8);
    max-height: 250px;
    overflow-y: auto;
}

/* Cron instruction tabs */
.tabs-control {
    display: inline-flex;
    background: rgba(255, 255, 255, 0.03);
    padding: 4px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    margin-bottom: 18px;
}

.tab-btn {
    background: transparent;
    color: var(--text-secondary);
    border: none;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: inherit;
}

.tab-btn.active {
    background: var(--color-primary);
    color: white;
    box-shadow: 0 4px 10px var(--color-primary-glow);
}

pre {
    background: #04060f;
    padding: 14px;
    border-radius: 10px;
    color: #a5b4fc;
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    overflow-x: auto;
    border: 1px solid var(--border-color);
    margin-bottom: 10px;
}

code {
    font-family: 'JetBrains Mono', monospace;
    background: rgba(255, 255, 255, 0.05);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 90%;
    color: #a5b4fc;
}

.text-success { color: var(--color-success); }
.text-danger { color: var(--color-danger); }
.text-warning { color: var(--color-warning); }

.time-sync-box {
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 12px;
    margin-top: 10px;
}
.time-sync-box.ok { background: var(--color-success-bg); border: 1px solid var(--color-success-border); color: var(--color-success); }
.time-sync-box.err { background: var(--color-danger-bg); border: 1px solid var(--color-danger-border); color: var(--color-danger); }

@media (max-width: 768px) {
    body {
        padding: 12px;
    }
    .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 20px;
    }
    .header-title h1 {
        font-size: 22px;
    }
    .card {
        padding: 16px;
        margin-bottom: 16px;
        border-radius: 12px;
    }
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .stat-box {
        padding: 14px;
    }
    .stat-box-value {
        font-size: 28px;
    }
    table {
        font-size: 12px;
    }
    th, td {
        padding: 8px 10px;
    }
    .tabs-control {
        width: 100%;
        display: flex;
    }
    .tab-btn {
        flex: 1;
        text-align: center;
        padding: 8px 4px;
    }
    pre {
        padding: 10px;
        font-size: 11px;
    }
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    .btn-group .btn {
        width: 100%;
        justify-content: center;
    }
}

</style>
</head>
<body>

<div class="dashboard-wrapper">
    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-title">
            <h1>
                <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                Cron Diagnostics Panel
            </h1>
        </div>
        <div class="system-pulse">
            <span class="pulse-dot"></span>
            Hệ thống đang hoạt động
        </div>
    </header>

    <!-- Layout Grid -->
    <div class="dashboard-layout">
        
        <!-- Left Column: Sidebar Details -->
        <aside class="sidebar-layout">
            
            <!-- Time Settings -->
            <div class="card">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Thời gian Server
                </h3>
                <div class="info-list">
                    <div class="info-item">
                        <span class="info-label">PHP System:</span>
                        <span class="info-value mono"><?= $now_php ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">MySQL DB:</span>
                        <span class="info-value mono"><?= $now_mysql ?></span>
                    </div>
                </div>
                
                <?php if (abs(strtotime($now_php) - strtotime($now_mysql)) > 60): ?>
                <div class="time-sync-box err">
                    ⚠️ Giờ PHP và MySQL bị lệch > 1 phút! Đây có thể là nguyên nhân cron sai giờ.
                </div>
                <?php else: ?>
                <div class="time-sync-box ok">
                    ✔ PHP và MySQL đồng bộ giờ tốt.
                </div>
                <?php endif; ?>
            </div>

            <!-- Exec Check -->
            <div class="card">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
                    Tiến trình nền (CLI)
                </h3>
                <div class="info-list">
                    <div class="info-item">
                        <span class="info-label">Hàm exec():</span>
                        <span class="info-value">
                            <?php if ($exec_ok): ?>
                                <span class="badge published">Hoạt động</span>
                            <?php else: ?>
                                <span class="badge failed">Bị khóa</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <?php if (!$exec_ok): ?>
                <div style="margin-top: 12px; font-size:12px; color: var(--text-secondary); line-height: 1.4;">
                    ❌ Hãy vào AaPanel → PHP → Disable Functions → Xóa "exec" để tối ưu hiệu năng luồng chạy.
                </div>
                <?php endif; ?>
            </div>

            <!-- Throttling Config -->
            <div class="card">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Giới hạn Luồng (Throttling)
                </h3>
                
                <div class="info-list">
                    <!-- Publish workers -->
                    <div class="info-item" style="flex-direction: column; align-items: flex-start; gap: 8px;">
                        <span class="info-label" style="font-weight: 600; color: var(--text-primary);">🚀 Publish Workers (Đăng bài)</span>
                        <div style="width: 100%; display: flex; justify-content: space-between; font-size: 13px;">
                            <span>Đang chạy: <strong class="text-warning"><?= $active_publish ?></strong></span>
                            <span>Tối đa: <strong><?= $max_publish_workers ?></strong></span>
                            <span>Trống: <strong class="text-success"><?= max(0, $max_publish_workers - $active_publish) ?></strong></span>
                        </div>
                    </div>
                    <!-- Comment workers -->
                    <div class="info-item" style="flex-direction: column; align-items: flex-start; gap: 8px; border:none; padding: 0;">
                        <span class="info-label" style="font-weight: 600; color: var(--text-primary);">💬 Comment Workers (Bình luận)</span>
                        <div style="width: 100%; display: flex; justify-content: space-between; font-size: 13px;">
                            <span>Đang chạy: <strong class="text-warning"><?= $active_comment ?></strong></span>
                            <span>Tối đa: <strong><?= $max_comment_workers ?></strong></span>
                            <span>Trống: <strong class="text-success"><?= max(0, $max_comment_workers - $active_comment) ?></strong></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overview Statistics -->
            <div class="card">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/></svg>
                    Thống kê bài viết
                </h3>
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="padding: 6px 8px;">Trạng thái</th>
                            <th style="padding: 6px 8px; text-align: right;">Tổng</th>
                            <th style="padding: 6px 8px; text-align: right;">Hôm nay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['pending','processing','published','failed'] as $s): ?>
                        <tr>
                            <td style="padding: 8px 8px;"><span class="badge <?= $s ?>"><?= $s ?></span></td>
                            <td style="padding: 8px 8px; text-align: right; font-weight: 600;" class="mono"><?= number_format($stats[$s] ?? 0) ?></td>
                            <td style="padding: 8px 8px; text-align: right; font-weight: 600;" class="mono">
                                <span class="<?= ($stats_today[$s] ?? 0) > 0 ? ($s === 'failed' ? 'text-danger' : ($s === 'published' ? 'text-success' : 'text-warning')) : 'text-muted' ?>">
                                    <?= number_format($stats_today[$s] ?? 0) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Thống kê bài viết theo Tài khoản User -->
            <div class="card" style="border-top: 3px solid var(--color-primary);">
                <h3 class="card-title" style="color: var(--color-primary);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Bài viết theo Tài khoản User
                </h3>
                <?php if (empty($user_posts_breakdown)): ?>
                <div style="font-size: 13px; color: var(--text-muted); padding: 4px 0;">
                    Chưa có bài viết nào.
                </div>
                <?php else: ?>
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="padding: 6px 8px;">Tài khoản</th>
                            <th style="padding: 6px 8px; text-align: right;">Hôm nay</th>
                            <th style="padding: 6px 8px; text-align: right;">Tổng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user_posts_breakdown as $ub): ?>
                        <tr>
                            <td style="padding: 8px 8px;">
                                <div style="font-weight: 600; color: #a5b4fc;"><?= htmlspecialchars($ub['username']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                    ⏳<?= number_format($ub['pending_posts']) ?> | ✅<?= number_format($ub['published_posts']) ?> | ❌<?= number_format($ub['failed_posts']) ?>
                                </div>
                            </td>
                            <td style="padding: 8px 8px; text-align: right; font-weight: 700;" class="mono">
                                <span class="<?= $ub['today_posts'] > 0 ? 'text-success' : 'text-muted' ?>">
                                    <?= number_format($ub['today_posts']) ?>
                                </span>
                            </td>
                            <td style="padding: 8px 8px; text-align: right; font-weight: 600;" class="mono">
                                <?= number_format($ub['total_posts']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Top 10 Failed Accounts Today -->
            <div class="card" style="border-top: 3px solid var(--color-danger);">
                <h3 class="card-title" style="color: var(--color-danger);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 22 22 22 12 2"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Tài khoản nhiều bài lỗi (Hôm nay)
                </h3>
                <?php if (empty($top_failed_accounts)): ?>
                <div style="font-size: 13px; color: var(--color-success); font-weight: 500; padding: 4px 0;">
                    ✔ Không có tài khoản bị lỗi đăng.
                </div>
                <?php else: ?>
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="padding: 6px 8px;">Tài khoản</th>
                            <th style="padding: 6px 8px; text-align: right;">Bài lỗi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_failed_accounts as $acc): ?>
                        <tr>
                            <td style="padding: 8px 8px; font-weight: 600; color: #a5b4fc;"><?= htmlspecialchars($acc['username']) ?></td>
                            <td style="padding: 8px 8px; text-align: right; font-weight: 700; color: var(--color-danger);" class="mono"><?= number_format($acc['cnt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        </aside>

        <!-- Right Column: Main Dashboard Content -->
        <main class="main-layout">
            
            <!-- Quick Dashboard Grid -->
            <div class="stats-grid">
                <div class="stat-box stat-processing">
                    <div class="stat-box-value mono"><?= $processing_total_count ?></div>
                    <div class="stat-box-label">Đang xử lý (Processing)</div>
                    <div class="stat-box-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="6.83" y1="18.17" x2="8.24" y2="16.76"/><line x1="15.76" y1="8.24" x2="17.17" y2="6.83"/></svg>
                    </div>
                </div>
                <div class="stat-box stat-overdue">
                    <div class="stat-box-value mono"><?= $ready_total_count ?></div>
                    <div class="stat-box-label">Quá hạn cần đăng ngay</div>
                    <div class="stat-box-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                </div>
                <div class="stat-box stat-insights">
                    <div class="stat-box-value mono"><?= $insights_total_count ?></div>
                    <div class="stat-box-label">Chờ Insights bình luận</div>
                    <div class="stat-box-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                </div>
            </div>

            <!-- Table: Bài đến/quá hạn cần đăng ngay -->
            <div class="card">
                <h3 class="card-title" style="color: var(--color-danger);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 22 22 22 12 2"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Bài đến/quá giờ — Cần đăng ngay (<?= $ready_total_count ?> bài<?= $ready_total_count > 20 ? ', hiển thị 20 mới nhất' : '' ?>)
                </h3>
                
                <?php if (empty($ready_posts)): ?>
                <div style="padding: 16px; color: var(--color-success); font-weight: 500; font-size:14px; display: flex; align-items: center; gap: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Tất cả các bài đăng đều đúng giờ, không có bài nào bị quá hạn.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tài khoản</th>
                                <th>Fanpage ID</th>
                                <th>Loại</th>
                                <th>Trạng thái</th>
                                <th>Retry</th>
                                <th>Giờ hẹn</th>
                                <th>Trễ</th>
                                <th>Thông tin lỗi</th>
                                <th style="text-align: right;">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ready_posts as $p): ?>
                            <tr>
                                <td class="mono font-weight-bold">#<?= $p['id'] ?></td>
                                <td><span style="color:#a78bfa; font-weight: 600;"><?= htmlspecialchars($p['account_name'] ?? 'System') ?></span></td>
                                <td class="mono"><?= $p['page_id'] ?></td>
                                <td><span style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;"><?= $p['post_type'] ?></span></td>
                                <td><span class="badge <?= $p['status'] ?>"><?= $p['status'] ?></span></td>
                                <td class="mono"><?= $p['retry_count'] ?? 0 ?>/<?= $p['limit_retries'] ?></td>
                                <td class="mono"><?= $p['scheduled_time'] ?></td>
                                <td class="text-danger font-weight-bold"><?= (int)$p['overdue_minutes'] ?> phút</td>
                                <td style="color:#f87171; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size:12px;" title="<?= htmlspecialchars($p['error_msg'] ?? '') ?>">
                                    <?= htmlspecialchars($p['error_msg'] ?? '-') ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="?delete_post=<?= $p['id'] ?>" class="btn btn-danger" style="padding: 3px 8px; font-size: 11px;" onclick="return confirm('Bạn có chắc chắn muốn xóa bài ID #<?= $p['id'] ?>?');">🗑 Xóa</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Table: Bài đang xử lý (Processing) -->
            <div class="card" style="border-top: 4px solid var(--color-info);">
                <h3 class="card-title" style="color: var(--color-info);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="6.83" y1="18.17" x2="8.24" y2="16.76"/><line x1="15.76" y1="8.24" x2="17.17" y2="6.83"/></svg>
                    Bài đang xử lý (<?= $processing_total_count ?> bài<?= $processing_total_count > 20 ? ', hiển thị 20 mới nhất' : '' ?>)
                </h3>
                
                <?php if ($reset_msg): ?>
                <div style="background: var(--color-success-bg); border: 1px solid var(--color-success-border); color: var(--color-success); padding: 12px; border-radius: 8px; margin-bottom: 14px; font-size:13px;">
                    ✔ <?= htmlspecialchars($reset_msg) ?>
                </div>
                <?php endif; ?>
                
                <?php if (empty($processing_posts)): ?>
                <div style="padding: 16px; color: var(--text-secondary); font-size:13px;">
                    Hiện không có luồng nào đang xử lý bài viết.
                </div>
                <?php else: ?>
                <div style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.15); color: var(--color-warning); padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; line-height: 1.4;">
                    ⚠️ LƯU Ý: Nếu một bài viết giữ trạng thái <b>Processing</b> quá lâu (ví dụ > 20 phút), có thể tiến trình đã bị dừng đột ngột hoặc crash do hết bộ nhớ.
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tài khoản</th>
                                <th>Fanpage ID</th>
                                <th>Loại</th>
                                <th>Giờ hẹn</th>
                                <th>Cập nhật cuối</th>
                                <th>Đã chạy</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($processing_posts as $p): ?>
                            <tr>
                                <td class="mono font-weight-bold">#<?= $p['id'] ?></td>
                                <td><span style="color:#a78bfa; font-weight: 600;"><?= htmlspecialchars($p['account_name'] ?? 'System') ?></span></td>
                                <td class="mono"><?= $p['page_id'] ?></td>
                                <td><span style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;"><?= $p['post_type'] ?></span></td>
                                <td class="mono"><?= $p['scheduled_time'] ?></td>
                                <td class="mono"><?= $p['updated_at'] ?></td>
                                <td class="<?= $p['duration_min'] > 20 ? 'text-danger font-weight-bold' : 'text-warning' ?> mono">
                                    <?= (int)$p['duration_min'] ?> phút
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 18px;">
                    <a class="btn btn-danger" href="?force_reset=1" onclick="return confirm('Bạn có chắc chắn muốn giải phóng các bài viết đang bị treo?');">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                        Ép buộc Reset về Pending (Giải phóng bài treo)
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Table: Bài chờ Insights -->
            <div class="card" style="border-top: 4px solid var(--color-success);">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 18px;">
                    <h3 style="font-size: 16px; font-weight: 600; color: var(--color-success); display:flex; align-items:center; gap:8px; margin: 0;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Bài chờ bình luận thông minh (Insights - <?= $insights_total_count ?> bài)
                    </h3>
                    <div style="font-size:12px; color: var(--text-secondary);">
                        Cron Insights cuối: 
                        <strong style="color:<?php
                            if ($last_cron_run === 'Chưa từng chạy') {
                                echo 'var(--text-muted)';
                            } else {
                                $diff = time() - strtotime($last_cron_run);
                                echo ($diff < 300) ? 'var(--color-success)' : 'var(--color-danger)'; 
                            }
                        ?>"><?= $last_cron_run ?></strong>
                    </div>
                </div>
                
                <p style="font-size:13px; color:var(--text-secondary); margin-bottom:14px;">
                    Danh sách các bài viết đã xuất bản đang được theo dõi lượt Xem, Thích, Bình luận để tự động kích hoạt kịch bản bình luận thông minh.
                </p>
                
                <?php if (empty($insights_waiting)): ?>
                <div style="padding: 16px; color: var(--text-secondary); font-size:13px;">
                    Không có bài viết nào đang trong trạng thái chờ Insights.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tài khoản</th>
                                <th>Facebook Post ID</th>
                                <th>Ngưỡng kích hoạt (View / Like / Comment)</th>
                                <th>Trạng thái</th>
                                <th>Giờ đăng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($insights_waiting as $p): ?>
                            <tr>
                                <td class="mono font-weight-bold">#<?= $p['id'] ?></td>
                                <td><span style="color:#a78bfa; font-weight: 600;"><?= htmlspecialchars($p['account_name'] ?? 'System') ?></span></td>
                                <td class="mono">
                                    <a href="https://facebook.com/<?= $p['fb_post_id'] ?>" target="_blank" style="color: var(--color-primary); text-decoration:none; display: inline-flex; align-items:center; gap:4px; font-weight:600;">
                                        <?= $p['fb_post_id'] ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    </a>
                                </td>
                                <td>
                                    <span style="color:#06b6d4; font-weight:600;">👁️ <?= number_format($p['comment_threshold_views']) ?></span> &nbsp;|&nbsp;
                                    <span style="color:#ef4444; font-weight:600;">👍 <?= number_format($p['comment_threshold_likes']) ?></span> &nbsp;|&nbsp;
                                    <span style="color:#10b981; font-weight:600;">💬 <?= number_format($p['comment_threshold_comments']) ?></span>
                                </td>
                                <td>
                                    <?php if ($p['comment_status'] === 'waiting_insights'): ?>
                                        <span class="badge processing">🔍 Đang theo dõi</span>
                                    <?php else: ?>
                                        <span class="badge pending"><?= htmlspecialchars($p['comment_status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono"><?= $p['scheduled_time'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Table: Bài sắp tới giờ -->
            <div class="card">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Bài sắp tới giờ đăng (10 bài gần nhất)
                </h3>
                
                <?php if (empty($upcoming)): ?>
                <div style="padding: 16px; color: var(--text-secondary); font-size:13px;">
                    Không có bài viết nào chuẩn bị đăng trong thời gian tới.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tài khoản</th>
                                <th>Fanpage ID</th>
                                <th>Loại</th>
                                <th>Giờ hẹn</th>
                                <th>Thời gian chờ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming as $u): ?>
                            <tr>
                                <td class="mono font-weight-bold">#<?= $u['id'] ?></td>
                                <td><span style="color:#a78bfa; font-weight: 600;"><?= htmlspecialchars($u['account_name'] ?? 'System') ?></span></td>
                                <td class="mono"><?= $u['page_id'] ?></td>
                                <td><span style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;"><?= $u['post_type'] ?></span></td>
                                <td class="mono"><?= $u['scheduled_time'] ?></td>
                                <td class="text-success font-weight-bold"><?= (int)$u['minutes_left'] ?> phút nữa</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actions Controls: Kích hoạt thủ công -->
            <div class="card">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    Kích hoạt Tiến trình Thủ công
                </h3>
                <p style="font-size:13px; color: var(--text-secondary); margin-bottom: 16px;">
                    Click các nút dưới để kích hoạt trực tiếp dispatcher mà không cần đợi chu kỳ Cron tiếp theo:
                </p>
                <div class="btn-group">
                    <a class="btn btn-success" href="?run=publish">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Chạy Publish Dispatcher (Đăng bài)
                    </a>
                    <a class="btn btn-primary" href="?run=comment">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Chạy Comment Dispatcher (Bình luận)
                    </a>
                    <a class="btn btn-info" href="?run=insights">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Kiểm tra Insights (Bình luận thông minh)
                    </a>
                </div>
                
                <?php if ($run_msg): ?>
                <div class="run-output"><?= $run_msg ?></div>
                <?php endif; ?>
            </div>

            <!-- Worker Lock Files Section -->
            <div class="card">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Quản lý tệp khóa (Worker Lock Files - <?= count($lock_files) ?> tệp)
                </h3>
                
                <?php if ($clear_msg): ?>
                <div style="background: var(--color-success-bg); border: 1px solid var(--color-success-border); color: var(--color-success); padding: 12px; border-radius: 8px; margin-bottom: 14px; font-size:13px;">
                    ✔ <?= htmlspecialchars($clear_msg) ?>
                </div>
                <?php endif; ?>
                
                <?php if (empty($lock_files)): ?>
                <div style="padding: 16px; color: var(--color-success); font-size:13px; font-weight: 500;">
                    ✔ Không có tệp khóa nào đang hoạt động. Toàn bộ Worker rảnh rỗi.
                </div>
                <?php else: ?>
                <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.15); color: var(--color-danger); padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; line-height: 1.4;">
                    ⚠️ LƯU Ý: Mỗi khi Worker khởi chạy, hệ thống tạo tệp Lock để tránh trùng luồng. Nếu Worker bị crash đột ngột, tệp Lock có thể bị sót lại và chặn luồng kế tiếp. Bạn có thể xóa khóa thủ công bên dưới để giải phóng luồng đăng.
                </div>
                <div class="table-responsive" style="margin-bottom: 16px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Tên Tệp Khóa</th>
                                <th>Thời gian khởi chạy</th>
                                <th>Trạng thái luồng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lock_files as $lf): ?>
                            <tr>
                                <td class="mono font-weight-bold" style="font-size:11px;"><?= htmlspecialchars($lf['file']) ?></td>
                                <td class="mono"><?= $lf['age_min'] ?> phút trước</td>
                                <td>
                                    <?php if ($lf['locked']): ?>
                                        <span class="badge locked">🔒 Đang Chạy (LOCKED)</span>
                                    <?php else: ?>
                                        <span class="badge free">✔ Đã Giải Phóng (FREE)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <a class="btn btn-secondary" style="color: var(--color-danger); border-color: rgba(239,68,68,0.2);" href="?clear_locks=1" onclick="return confirm('Bạn có chắc chắn muốn xóa toàn bộ Lock Files?');">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Xóa tất cả Lock Files cũ
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Documentation: Hướng dẫn cấu hình Cron -->
            <div class="card">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    Cài đặt Cronjob trên AaPanel / Linux
                </h3>
                
                <div style="background: rgba(99, 102, 241, 0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 16px; margin-bottom: 20px; font-size: 13px;">
                    <div style="font-weight: 700; color: #a5b4fc; margin-bottom: 12px; font-size:14px; display: flex; align-items: center; gap: 6px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        Thông tin hệ thống tự động phát hiện:
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <div>
                            <span style="color: var(--text-secondary)">PHP Binary:</span>
                            <div class="mono" style="margin-top: 4px; font-size:11px; background: rgba(0,0,0,0.3); padding: 4px 8px; border-radius:4px; width: fit-content;"><?= htmlspecialchars($php_bin_full) ?></div>
                        </div>
                        <div>
                            <span style="color: var(--text-secondary)">PHP Version:</span>
                            <div class="mono" style="margin-top: 4px; font-size:11px; background: rgba(0,0,0,0.3); padding: 4px 8px; border-radius:4px; width: fit-content;"><?= phpversion() ?></div>
                        </div>
                        <div style="grid-column: span 2;">
                            <span style="color: var(--text-secondary)">Đường dẫn thư mục Cron:</span>
                            <div class="mono" style="margin-top: 4px; font-size:11px; background: rgba(0,0,0,0.3); padding: 4px 8px; border-radius:4px; word-break: break-all;"><?= htmlspecialchars($cron_dir_path) ?></div>
                        </div>
                    </div>
                </div>

                <p style="font-size:13px; color: var(--text-secondary); margin-bottom:12px;">
                    Hệ thống yêu cầu cài đặt **2 Cron Job** riêng biệt để chạy tự động:
                </p>

                <!-- Switch tabs control -->
                <div class="tabs-control">
                    <button id="tab-aapanel" class="tab-btn active" onclick="switchTab('aapanel')">
                        AaPanel (Dạng Shell Script)
                    </button>
                    <button id="tab-fullpath" class="tab-btn" onclick="switchTab('fullpath')">
                        Linux System Crontab (Đường dẫn đầy đủ)
                    </button>
                </div>

                <!-- Tab content: AaPanel -->
                <div id="content-aapanel">
                    <div style="margin-bottom:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:13px; font-weight:600; color:#a5b4fc;">① Cron 1 — Dispatch Đăng & Bình luận (Tần suất: 1 phút / lần)</span>
                            <button class="btn btn-secondary" style="padding: 4px 10px; font-size:11px;" onclick="copyText('cron1a')">Copy Command</button>
                        </div>
                        <pre id="cron1a">php <?= htmlspecialchars($cron_dir_path) ?>/start_publish.php >> /tmp/fb_publish.log 2>&1
php <?= htmlspecialchars($cron_dir_path) ?>/start_comment.php >> /tmp/fb_comment.log 2>&1</pre>
                        <span style="font-size:11px; color: var(--text-muted)">Cấu hình trên AaPanel: <b>N Minutes → 1 Minute</b> | Type: Shell Script</span>
                    </div>

                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:13px; font-weight:600; color:#c4b5fd;">② Cron 2 — Đọc Insights & Bình luận tự động (Tần suất: 5 phút / lần)</span>
                            <button class="btn btn-secondary" style="padding: 4px 10px; font-size:11px;" onclick="copyText('cron2a')">Copy Command</button>
                        </div>
                        <pre id="cron2a">php <?= htmlspecialchars($cron_dir_path) ?>/comment_insights_worker.php >> /tmp/fb_comment_insights.log 2>&1</pre>
                        <span style="font-size:11px; color: var(--text-muted)">Cấu hình trên AaPanel: <b>N Minutes → 5 Minutes</b> | Type: Shell Script</span>
                    </div>
                </div>

                <!-- Tab content: Full path crontab -->
                <div id="content-fullpath" style="display:none;">
                    <div style="margin-bottom:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:13px; font-weight:600; color:#a5b4fc;">① Cron 1 — Dispatch Đăng & Bình luận (Tần suất: 1 phút / lần)</span>
                            <button class="btn btn-secondary" style="padding: 4px 10px; font-size:11px;" onclick="copyText('cron1b')">Copy Command</button>
                        </div>
                        <pre id="cron1b"><?= htmlspecialchars($php_bin_full) ?> <?= htmlspecialchars($cron_dir_path) ?>/start_publish.php >> /tmp/fb_publish.log 2>&1
<?= htmlspecialchars($php_bin_full) ?> <?= htmlspecialchars($cron_dir_path) ?>/start_comment.php >> /tmp/fb_comment.log 2>&1</pre>
                        <span style="font-size:11px; color: var(--text-muted)">Hệ thống Crontab: <code>* * * * *</code> (Chạy mỗi phút)</span>
                    </div>

                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:13px; font-weight:600; color:#c4b5fd;">② Cron 2 — Đọc Insights & Bình luận tự động (Tần suất: 5 phút / lần)</span>
                            <button class="btn btn-secondary" style="padding: 4px 10px; font-size:11px;" onclick="copyText('cron2b')">Copy Command</button>
                        </div>
                        <pre id="cron2b"><?= htmlspecialchars($php_bin_full) ?> <?= htmlspecialchars($cron_dir_path) ?>/comment_insights_worker.php >> /tmp/fb_comment_insights.log 2>&1</pre>
                        <span style="font-size:11px; color: var(--text-muted)">Hệ thống Crontab: <code>*/5 * * * *</code> (Chạy mỗi 5 phút)</span>
                    </div>
                </div>

                <div style="margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--border-color); display: flex; gap: 20px; font-size:12px; color: var(--text-secondary);">
                    <span>📂 Log Đăng Bài: <code>tail -f /tmp/fb_publish.log</code></span>
                    <span>📂 Log Insights: <code>tail -f /tmp/fb_comment_insights.log</code></span>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
function switchTab(tab) {
    document.getElementById('content-aapanel').style.display  = (tab === 'aapanel')  ? '' : 'none';
    document.getElementById('content-fullpath').style.display = (tab === 'fullpath') ? '' : 'none';
    
    document.getElementById('tab-aapanel').classList.toggle('active', tab === 'aapanel');
    document.getElementById('tab-fullpath').classList.toggle('active', tab === 'fullpath');
}

function copyText(id) {
    var el = document.getElementById(id);
    var text = el.innerText || el.textContent;
    navigator.clipboard.writeText(text).then(function() {
        var btn = event.target;
        var orig = btn.textContent;
        btn.textContent = '✔ Đã copy!';
        btn.style.background = '#059669';
        btn.style.color = '#fff';
        setTimeout(function() { 
            btn.textContent = orig; 
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    }).catch(function() {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    });
}
</script>

</body>
</html>
