<?php
// check_publish_status.php — Kiểm tra tình hình Cron & Luồng đăng bài (start_publish.php & publish_worker.php)

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/php_cli.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=utf-8');

// Bypass auth qua secret hoặc admin session
$cron_secret = '';
try {
    $cs = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='cron_secret'");
    if ($cs) $cron_secret = trim($cs->fetchColumn() ?: '');
} catch (Exception $e) {}

$bypass_ok = ($cron_secret && isset($_GET['secret']) && hash_equals($cron_secret, $_GET['secret'])) || (PHP_SAPI === 'cli');

if (!$bypass_ok) {
    if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif;color:#ef4444;text-align:center;margin-top:100px">403 - Access Denied: Chỉ Admin mới được truy cập trang chẩn đoán này.</h2>');
    }
}

// ── XỬ LÝ HÀNH ĐỘNG ──────────────────────────────────────────────────────────
$action_msg = '';

// Force reset stuck processing posts
if (isset($_GET['action']) && $_GET['action'] === 'reset_stuck') {
    try {
        $count = $pdo->exec("UPDATE scheduled_posts SET status='pending', retry_count=0 WHERE status='processing'");
        $action_msg = "<div class='alert success'>✅ Đã ép reset $count bài từ 'processing' về 'pending'.</div>";
    } catch (Exception $e) {
        $action_msg = "<div class='alert danger'>❌ Lỗi reset bài stuck: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Clear stale locks
if (isset($_GET['action']) && $_GET['action'] === 'clear_locks') {
    $cleared = 0;
    $lock_dir = __DIR__ . '/locks';
    if (is_dir($lock_dir)) {
        foreach (glob($lock_dir . '/*.lock') as $lf) {
            if (@unlink($lf)) $cleared++;
        }
    }
    $action_msg = "<div class='alert success'>✅ Đã xóa $cleared file lock.</div>";
}

// Test Run start_publish.php
$test_run_output = '';
if (isset($_GET['action']) && $_GET['action'] === 'run_publish') {
    ob_start();
    include __DIR__ . '/cron/start_publish.php';
    $test_run_output = ob_get_clean();
    $action_msg = "<div class='alert info'>🚀 Kết quả chạy thử start_publish.php:</div>";
}

// ── 1. KIỂM TRA MÔI TRƯỜNG & THỜI GIAN SERVER ───────────────────────────────
$now_php = date('Y-m-d H:i:s');
$now_mysql = $pdo->query("SELECT NOW()")->fetchColumn();
$time_diff_sec = abs(strtotime($now_php) - strtotime($now_mysql));
$time_sync_ok = ($time_diff_sec <= 5);

$disabled_funcs = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
$exec_enabled = function_exists('exec') && !in_array('exec', $disabled_funcs);
$php_bin = function_exists('get_php_cli_bin') ? get_php_cli_bin() : 'php';

// ── 2. KIỂM TRA THROTTLING & MAX WORKERS ────────────────────────────────────
$max_workers = 30;
try {
    $mw = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_publish_workers'")->fetchColumn();
    if ($mw) $max_workers = (int)$mw;
} catch (Exception $e) {}

// Đếm worker thực sự đang chạy (updated_at <= 3 phút)
$active_workers_3m = (int)$pdo->query("SELECT COUNT(DISTINCT page_id) FROM scheduled_posts WHERE status = 'processing' AND updated_at > DATE_SUB(NOW(), INTERVAL 3 MINUTE)")->fetchColumn();
// Đếm tổng số bài mang status processing trong DB
$total_processing_db = (int)$pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE status = 'processing'")->fetchColumn();
// Đếm bài processing bị ngắt/kẹt (> 3 phút)
$stuck_processing_count = (int)$pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE status = 'processing' AND (updated_at IS NULL OR updated_at <= DATE_SUB(NOW(), INTERVAL 3 MINUTE))")->fetchColumn();

// ── 3. THỐNG KÊ TRẠNG THÁI BÀI VIẾT DỰ ÁN ──────────────────────────────────
$status_counts = $pdo->query("SELECT status, COUNT(*) AS cnt FROM scheduled_posts GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

// ── 4. GIẢ LẬP NHÓM BÀI CHỜ ĐẮNG (GROUPING DIAGNOSTIC) ─────────────────────
// Mô phỏng chính xác thuật toán grouping của start_publish.php
$raw_query = "
    SELECT DISTINCT sp.id, sp.page_id, sp.account_id, sp.post_type, sp.scheduled_time,
           p.user_id, bc.buffer_account_id, p.name AS page_name
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    LEFT JOIN pages p ON sp.page_id = p.page_id
    LEFT JOIN buffer_channels bc ON sp.page_id = bc.channel_id
    WHERE sp.scheduled_time <= NOW()
      AND sp.page_id IS NOT NULL
      AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
      AND (sp.retry_count IS NULL OR sp.retry_count < COALESCE(sa.max_retries, 3))
      AND sp.status IN ('pending', 'failed')
    ORDER BY sp.scheduled_time ASC, sp.id ASC
";
$stmt_raw = $pdo->query($raw_query);
$ready_posts_raw = $stmt_raw ? $stmt_raw->fetchAll(PDO::FETCH_ASSOC) : [];

$grouped_tokens = [];
foreach ($ready_posts_raw as $row) {
    if ($row['post_type'] === 'YouTube') {
        $yt_chan_id = !empty($row['page_id']) ? $row['page_id'] : $row['account_id'];
        $uid = 'yt_chan_' . $yt_chan_id;
    } elseif (strpos($row['post_type'], 'Buffer') !== false) {
        $buf_acc_id = !empty($row['buffer_account_id']) ? $row['buffer_account_id'] : $row['account_id'];
        $uid = 'buf_acc_' . $buf_acc_id;
    } elseif ($row['post_type'] === 'TikTok') {
        $uid = 'tt_' . $row['account_id'];
    } elseif (strpos($row['post_type'], 'Instagram') !== false) {
        $uid = 'ig_' . $row['page_id'];
    } else {
        // FB Token ID (user_id)
        $uid = !empty($row['user_id']) ? ('usr_' . $row['user_id']) : (!empty($row['account_id']) ? ('acc_' . $row['account_id']) : ('noid_' . $row['page_id']));
    }

    if (!isset($grouped_tokens[$uid])) {
        $grouped_tokens[$uid] = [
            'token_key' => $uid,
            'pages' => [],
            'posts_count' => 0,
            'post_ids' => []
        ];
    }
    if (!in_array($row['page_id'], $grouped_tokens[$uid]['pages'])) {
        $grouped_tokens[$uid]['pages'][] = $row['page_id'];
    }
    $grouped_tokens[$uid]['posts_count']++;
    $grouped_tokens[$uid]['post_ids'][] = '#' . $row['id'];
}

// ── 5. KIỂM TRA TỆP LOCK FILES HIỆN CÓ ──────────────────────────────────────
$lock_files = [];
$lock_dir = __DIR__ . '/locks';
if (is_dir($lock_dir)) {
    foreach (glob($lock_dir . '/*.lock') as $lf) {
        $age_sec = time() - filemtime($lf);
        $fp = @fopen($lf, 'r');
        $is_locked = false;
        if ($fp) {
            $is_locked = !flock($fp, LOCK_EX | LOCK_NB);
            if (!$is_locked) flock($fp, LOCK_UN);
            fclose($fp);
        }
        $lock_files[] = [
            'name' => basename($lf),
            'age_sec' => $age_sec,
            'age_str' => round($age_sec / 60, 1) . ' phút',
            'locked' => $is_locked,
            'stale' => ($age_sec > 900)
        ];
    }
}

// ── 6. LẤY NHẬT KÝ LỖI MỚI NHẤT ──────────────────────────────────────────────
$recent_errors = [];
try {
    $stmt_err = $pdo->query("
        SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.status, sp.error_msg, sp.retry_count, sp.updated_at,
               sa.username AS account_name
        FROM scheduled_posts sp
        LEFT JOIN system_accounts sa ON sp.account_id = sa.id
        WHERE sp.status IN ('failed', 'checkpoint') OR (sp.error_msg IS NOT NULL AND sp.error_msg != '')
        ORDER BY sp.updated_at DESC, sp.id DESC
        LIMIT 15
    ");
    if ($stmt_err) $recent_errors = $stmt_err->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$worker_log_content = '';
$log_path = __DIR__ . '/cron/worker_error.log';
if (file_exists($log_path) && filesize($log_path) > 0) {
    $lines = file($log_path);
    $last_lines = array_slice($lines, -25);
    $worker_log_content = implode('', $last_lines);
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chẩn đoán Cron & Luồng Đăng Bài (start_publish & publish_worker)</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
    --bg-dark: #080c18;
    --card-bg: #0f172a;
    --border: #1e293b;
    --primary: #6366f1;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --text: #f8fafc;
    --text-muted: #94a3b8;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background-color: var(--bg-dark);
    color: var(--text);
    font-family: 'Outfit', sans-serif;
    padding: 24px;
    font-size: 14px;
    line-height: 1.5;
}
.container { max-width: 1400px; margin: 0 auto; }
.header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--border);
}
.title h1 { font-size: 24px; font-weight: 700; color: #fff; }
.title p { color: var(--text-muted); font-size: 13px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px; }
.card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.card-title {
    font-size: 15px; font-weight: 600; margin-bottom: 14px; color: #38bdf8;
    display: flex; align-items: center; justify-content: space-between;
}
.metric { font-size: 28px; font-weight: 700; margin: 8px 0; }
.metric-subtitle { font-size: 12px; color: var(--text-muted); }
.status-pill {
    display: inline-block; padding: 3px 8px; border-radius: 6px;
    font-size: 11px; font-weight: 600; text-transform: uppercase;
}
.pill-success { background: rgba(16, 185, 129, 0.15); color: var(--success); border: 1px solid var(--success); }
.pill-warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); border: 1px solid var(--warning); }
.pill-danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); border: 1px solid var(--danger); }
.pill-info { background: rgba(99, 102, 241, 0.15); color: var(--primary); border: 1px solid var(--primary); }
table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
th { color: var(--text-muted); font-weight: 600; background: rgba(255,255,255,0.02); }
.btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px;
    border-radius: 8px; font-weight: 600; text-decoration: none; font-size: 12px;
    cursor: pointer; border: none; transition: 0.2s;
}
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { opacity: 0.9; }
.btn-warning { background: var(--warning); color: #000; }
.btn-danger { background: var(--danger); color: #fff; }
.btn-secondary { background: #334155; color: #fff; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; font-size: 13px; }
.alert.success { background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); }
.alert.danger { background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); }
.alert.info { background: rgba(99, 102, 241, 0.1); border: 1px solid var(--primary); color: #a5b4fc; }
pre {
    background: #020617; padding: 14px; border-radius: 8px; font-family: 'JetBrains Mono', monospace;
    font-size: 12px; color: #34d399; overflow-x: auto; max-height: 250px; border: 1px solid var(--border);
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="title">
            <h1>🔍 Diagnostics: Cron & Publish Worker Status</h1>
            <p>Kiểm tra toàn diện trạng thái Dispatcher start_publish.php & Tiến trình publish_worker.php</p>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="check_publish_status.php?action=reset_stuck" class="btn btn-warning" onclick="return confirm('Reset tất cả bài stuck về Pending?')">⚡ Reset Bài Stuck Processing</a>
            <a href="check_publish_status.php?action=clear_locks" class="btn btn-secondary" onclick="return confirm('Clear tất cả lock files?')">🧹 Clear Lock Files</a>
            <a href="check_publish_status.php?action=run_publish" class="btn btn-primary">🚀 Chạy Thử start_publish.php</a>
        </div>
    </div>

    <?= $action_msg ?>

    <?php if ($test_run_output): ?>
    <div class="card" style="margin-bottom:20px;">
        <div class="card-title">Console Output: start_publish.php</div>
        <pre><?= htmlspecialchars($test_run_output) ?></pre>
    </div>
    <?php endif; ?>

    <!-- GRID TOP METRICS -->
    <div class="grid">
        <!-- SERVER & ENGINE STATUS -->
        <div class="card">
            <div class="card-title">Môi Trường & Thời Gian</div>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <div>
                    <span class="metric-subtitle">Đồng bộ PHP & MySQL:</span>
                    <div>PHP: <code><?= $now_php ?></code> | MySQL: <code><?= $now_mysql ?></code></div>
                    <?php if ($time_sync_ok): ?>
                        <span class="status-pill pill-success">Đồng bộ chuẩn (Chênh <?= $time_diff_sec ?>s)</span>
                    <?php else: ?>
                        <span class="status-pill pill-danger">Lỗi chênh lệch giờ (Chênh <?= $time_diff_sec ?>s)</span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="metric-subtitle">Chế độ CLI exec():</span>
                    <?php if ($exec_enabled): ?>
                        <span class="status-pill pill-success">HOẠT ĐỘNG (CLI Background Enabled)</span>
                    <?php else: ?>
                        <span class="status-pill pill-warning">BỊ TẮT (Chuyển sang cURL Web Async)</span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="metric-subtitle">PHP Binary CLI Path:</span>
                    <div><code><?= htmlspecialchars($php_bin) ?></code></div>
                </div>
            </div>
        </div>

        <!-- WORKER THROTTLING -->
        <div class="card">
            <div class="card-title">Giới Hạn Luồng (Throttling)</div>
            <div class="metric" style="color:#38bdf8;"><?= $active_workers_3m ?> <span style="font-size:16px; color:var(--text-muted)">/ <?= $max_workers ?> Slots</span></div>
            <div class="metric-subtitle">Số luồng thực sự đang chạy bài gần đây (updated_at <= 3 min)</div>
            <div style="margin-top:12px; font-size:13px;">
                <div>• Tổng bài có status='processing' trong DB: <strong><?= $total_processing_db ?></strong></div>
                <div>• Bài bị kẹt/dừng đột ngột (> 3 min): 
                    <?php if ($stuck_processing_count > 0): ?>
                        <span style="color:var(--danger); font-weight:bold;"><?= $stuck_processing_count ?> bài kẹt</span>
                    <?php else: ?>
                        <span style="color:var(--success);">0 bài</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- POST STATUS SUMMARY -->
        <div class="card">
            <div class="card-title">Tổng Quan Hàng Chờ Đăng</div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
                <div><span class="status-pill pill-warning">Pending (Chờ)</span> <strong style="font-size:18px; display:block;"><?= $status_counts['pending'] ?? 0 ?></strong></div>
                <div><span class="status-pill pill-info">Processing (Đang)</span> <strong style="font-size:18px; display:block;"><?= $status_counts['processing'] ?? 0 ?></strong></div>
                <div><span class="status-pill pill-success">Published (Đã xong)</span> <strong style="font-size:18px; display:block;"><?= $status_counts['published'] ?? 0 ?></strong></div>
                <div><span class="status-pill pill-danger">Failed / Checkpoint</span> <strong style="font-size:18px; display:block;"><?= ($status_counts['failed'] ?? 0) + ($status_counts['checkpoint'] ?? 0) ?></strong></div>
            </div>
        </div>
    </div>

    <!-- GROUPING SIMULATION -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-title">
            <span>🎯 Phân Nhóm Luồng Quét Tự Động (Simulation start_publish.php)</span>
            <span style="font-size:12px; font-weight:normal; color:var(--text-muted)">Phát hiện <?= count($ready_posts_raw) ?> bài quá hạn cần đăng ngay trên <?= count($grouped_tokens) ?> nhóm Token</span>
        </div>

        <?php if (empty($grouped_tokens)): ?>
            <p style="color:var(--success); font-weight:500;">✅ Hiện tại không có bài viết nào quá hạn cần đăng ngay.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Nhóm Token / Channel Key</th>
                            <th>Loại</th>
                            <th>Số bài chờ</th>
                            <th>Danh sách Page ID</th>
                            <th>Các bài ID đại diện</th>
                            <th>Trạng thái Phân luồng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grouped_tokens as $tkey => $info): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($tkey) ?></code></td>
                            <td>
                                <?php 
                                if (strpos($tkey, 'yt_') === 0) echo '<span class="status-pill pill-danger">YouTube</span>';
                                elseif (strpos($tkey, 'tt_') === 0) echo '<span class="status-pill pill-warning">TikTok</span>';
                                elseif (strpos($tkey, 'buf_') === 0) echo '<span class="status-pill pill-info">Buffer</span>';
                                else echo '<span class="status-pill pill-success">Facebook Token</span>';
                                ?>
                            </td>
                            <td><strong><?= $info['posts_count'] ?></strong> bài</td>
                            <td><?= implode(', ', array_slice($info['pages'], 0, 5)) ?><?= count($info['pages']) > 5 ? '…' : '' ?></td>
                            <td><code><?= implode(', ', array_slice($info['post_ids'], 0, 5)) ?></code></td>
                            <td>
                                <span class="status-pill pill-success">Sẵn sàng cấp 1 Worker độc lập</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- LOCK FILES & LOGS GRID -->
    <div class="grid">
        <!-- LOCK FILES -->
        <div class="card">
            <div class="card-title">Trạng Thái Locks Directory (locks/*.lock)</div>
            <?php if (empty($lock_files)): ?>
                <p style="color:var(--text-muted); font-size:13px;">Không có file lock nào đang tồn tại.</p>
            <?php else: ?>
                <div style="max-height:220px; overflow-y:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>File Lock</th>
                                <th>Tuổi File</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lock_files as $lf): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($lf['name']) ?></code></td>
                                <td><?= $lf['age_str'] ?></td>
                                <td>
                                    <?php if ($lf['stale']): ?>
                                        <span class="status-pill pill-danger">Stale (> 15m)</span>
                                    <?php elseif ($lf['locked']): ?>
                                        <span class="status-pill pill-warning">Locked (Bận)</span>
                                    <?php else: ?>
                                        <span class="status-pill pill-info">Idle</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- RECENT WORKER LOG -->
        <div class="card">
            <div class="card-title">Worker Error Log (cron/worker_error.log)</div>
            <?php if ($worker_log_content): ?>
                <pre><?= htmlspecialchars($worker_log_content) ?></pre>
            <?php else: ?>
                <p style="color:var(--success); font-size:13px;">File worker_error.log trống hoặc không có lỗi PHP mới.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- RECENT ERRORS TABLE -->
    <div class="card">
        <div class="card-title">Nhật Ký Lỗi / Checkpoint Bài Đăng Gần Đây</div>
        <?php if (empty($recent_errors)): ?>
            <p style="color:var(--success); font-weight:500;">🎉 Không có lỗi đăng bài hoặc checkpoint nào gần đây.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Bài ID</th>
                            <th>Tài khoản</th>
                            <th>Fanpage ID</th>
                            <th>Loại</th>
                            <th>Trạng thái</th>
                            <th>Lần thử</th>
                            <th>Nội dung thông báo lỗi</th>
                            <th>Thời gian</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_errors as $err): ?>
                        <tr>
                            <td>#<?= $err['id'] ?></td>
                            <td><?= htmlspecialchars($err['account_name'] ?? 'N/A') ?></td>
                            <td><code><?= htmlspecialchars($err['page_id']) ?></code></td>
                            <td><?= htmlspecialchars($err['post_type']) ?></td>
                            <td>
                                <?php if ($err['status'] === 'checkpoint'): ?>
                                    <span class="status-pill pill-danger">CHECKPOINT</span>
                                <?php else: ?>
                                    <span class="status-pill pill-warning">FAILED</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $err['retry_count'] ?></td>
                            <td style="color:#f87171; font-size:12px;"><?= htmlspecialchars($err['error_msg'] ?? '') ?></td>
                            <td style="font-size:12px; color:var(--text-muted);"><?= $err['updated_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
