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

// ── Bài sẵn sàng đăng (đến/quá giờ) ─────────────────────────────────────────
$ready_stmt = $pdo->prepare("
    SELECT sp.id, sp.account_id, sp.page_id, sp.post_type, sp.status, sp.retry_count, sp.scheduled_time,
           TIMESTAMPDIFF(MINUTE, sp.scheduled_time, NOW()) AS overdue_minutes,
           sa.username AS account_name
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.scheduled_time <= NOW()
      AND (
        sp.status = 'pending'
        OR (sp.status = 'failed' AND (sp.retry_count IS NULL OR sp.retry_count < ?))
      )
    ORDER BY sp.scheduled_time ASC
    LIMIT 30
");
$ready_stmt->execute([$max_retries]);
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
$processing_posts = $pdo->query("
    SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.updated_at,
           TIMESTAMPDIFF(MINUTE, sp.updated_at, NOW()) AS duration_min,
           sa.username AS account_name
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.status = 'processing'
    ORDER BY sp.updated_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Bài chờ điều kiện Insights ──────────────────────────────────────────────
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
// Luon dung 'php' cho AaPanel (shell da cau hinh san)
$php_bin_simple = 'php';

// Tim duong dan day du de hien thi nhu la alternative
$php_bin_full = 'php';
$php_bin_note = '';

if (defined('PHP_BINARY') && PHP_BINARY
    && strpos(PHP_BINARY, 'php-fpm') === false
    && strpos(PHP_BINARY, 'php-cgi') === false
    && file_exists(PHP_BINARY)) {
    $php_bin_full = PHP_BINARY;
    $php_bin_note = 'tu PHP_BINARY';
}
if ($php_bin_full === 'php' || strpos($php_bin_full, 'fpm') !== false) {
    foreach (['/www/server/php/84/bin/php','/www/server/php/83/bin/php',
              '/www/server/php/82/bin/php','/www/server/php/81/bin/php',
              '/www/server/php/80/bin/php','/usr/bin/php8.4',
              '/usr/bin/php8.3','/usr/bin/php8.2','/usr/bin/php8.1',
              '/usr/bin/php','/usr/local/bin/php'] as $p) {
        if (file_exists($p)) { $php_bin_full = $p; $php_bin_note = 'tim thay tren server'; break; }
    }
}
if ($exec_ok && $php_bin_full === 'php') {
    $w = trim((string)@exec('which php 2>/dev/null'));
    if ($w && file_exists($w)) { $php_bin_full = $w; $php_bin_note = 'which php'; }
}

// Cron directory path tuyet doi thuc te tren server
$cron_dir_path = realpath(__DIR__ . '/cron');

// ── Kiểm tra lock files (worker bị stuck) ────────────────────────────────────
$lock_files = [];
$tmp_dir = sys_get_temp_dir();
if (is_dir($tmp_dir)) {
    foreach (glob($tmp_dir . '/facebook_publish_worker_page_*.lock') ?: [] as $lf) {
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
}

// ── Xoá lock files cũ nếu có &clear_locks=1 ──────────────────────────────────
$clear_msg = '';
if (isset($_GET['clear_locks'])) {
    $cleared = 0;
    foreach (glob($tmp_dir . '/facebook_publish_worker_page_*.lock') ?: [] as $lf) {
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
<title>Cron Diagnostics</title>
<style>
body { font-family: monospace; background: #0f0f1a; color: #ddd; margin: 0; padding: 20px; }
h1 { color: #7ee8fa; }
h2 { color: #a78bfa; border-bottom: 1px solid #333; padding-bottom: 5px; }
.card { background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 8px; padding: 15px; margin: 10px 0; }
.ok   { color: #4ade80; font-weight: bold; }
.warn { color: #facc15; font-weight: bold; }
.err  { color: #f87171; font-weight: bold; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: #1e293b; color: #7ee8fa; padding: 8px; text-align: left; }
td { padding: 6px 8px; border-bottom: 1px solid #1e293b; }
tr:hover td { background: #1e293b55; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
.pending   { background:#facc1522; color:#facc15; border:1px solid #facc1555; }
.failed    { background:#f8717122; color:#f87171; border:1px solid #f8717155; }
.published { background:#4ade8022; color:#4ade80; border:1px solid #4ade8055; }
.processing{ background:#38bdf822; color:#38bdf8; border:1px solid #38bdf855; }
.btn { display: inline-block; padding: 10px 22px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; cursor: pointer; margin: 5px; }
.btn-green { background: #16a34a; color: #fff; }
.btn-blue  { background: #1d4ed8; color: #fff; }
.btn:hover { opacity: 0.85; }
.run-output { background: #0a0a14; border: 1px solid #4ade8055; border-radius: 6px; padding: 15px; margin-top: 10px; color: #4ade80; white-space: pre-wrap; }
.overdue { color: #f87171; }
.ok-time { color: #4ade80; }
</style>
</head>
<body>
<h1>🔧 Cron Diagnostics Panel</h1>

<!-- Thời gian -->
<div class="card">
    <h2>⏰ Thời gian Server</h2>
    <p><b>PHP NOW():</b> <span class="ok"><?= $now_php ?></span></p>
    <p><b>MySQL NOW():</b> <span class="ok"><?= $now_mysql ?></span></p>
    <?php if (abs(strtotime($now_php) - strtotime($now_mysql)) > 60): ?>
    <p class="err">⚠ CẢNH BÁO: Giờ PHP và MySQL lệch nhau hơn 1 phút! Đây có thể là nguyên nhân cron sai giờ.</p>
    <?php else: ?>
    <p class="ok">✔ PHP và MySQL đồng bộ giờ tốt.</p>
    <?php endif; ?>
</div>

<!-- Exec -->
<div class="card">
    <h2>⚙ Kiểm tra hàm exec()</h2>
    <?php if ($exec_ok): ?>
    <p class="ok">✔ exec() HOẠT ĐỘNG — Có thể spawn worker process độc lập.</p>
    <?php else: ?>
    <p class="err">✘ exec() BỊ KHÓA — Vào AaPanel → PHP → Disable Functions → Xóa "exec".</p>
    <p class="warn">Cron sẽ dùng cURL fallback (chậm hơn, cần HTTP reachable).</p>
    <?php endif; ?>
</div>

<!-- Thống kê -->
<div class="card">
    <h2>📊 Tổng quan bài đăng</h2>
    <table>
    <tr><th>Trạng thái</th><th>Số bài</th></tr>
    <?php foreach (['pending','processing','published','failed'] as $s): ?>
    <tr>
        <td><span class="badge <?= $s ?>"><?= strtoupper($s) ?></span></td>
        <td><b><?= number_format($stats[$s] ?? 0) ?></b></td>
    </tr>
    <?php endforeach; ?>
    </table>
</div>

<!-- Bài sẵn sàng đăng -->
<div class="card">
    <h2>🚀 Bài đến/quá giờ — Cần đăng ngay (<?= count($ready_posts) ?> bài)</h2>
    <?php if (empty($ready_posts)): ?>
    <p class="ok">✔ Không có bài nào quá hạn.</p>
    <?php else: ?>
    <table>
    <tr><th>ID</th><th>Account</th><th>Page ID</th><th>Loại</th><th>Trạng thái</th><th>Retry</th><th>Giờ hẹn</th><th>Quá hạn</th></tr>
    <?php foreach ($ready_posts as $p): ?>
    <tr>
        <td><?= $p['id'] ?></td>
        <td><span style="color:#a78bfa"><?= htmlspecialchars($p['account_name'] ?? 'System') ?></span></td>
        <td><?= $p['page_id'] ?></td>
        <td><?= $p['post_type'] ?></td>
        <td><span class="badge <?= $p['status'] ?>"><?= $p['status'] ?></span></td>
        <td><?= $p['retry_count'] ?? 0 ?>/<?= $max_retries ?></td>
        <td><?= $p['scheduled_time'] ?></td>
        <td class="overdue"><?= (int)$p['overdue_minutes'] ?> phút trước</td>
    </tr>
    <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<!-- Bài sắp đến giờ -->
<div class="card">
    <h2>🕐 Bài sắp tới giờ (10 bài gần nhất)</h2>
    <?php if (empty($upcoming)): ?>
    <p style="color:#888">Không có bài pending nào trong tương lai.</p>
    <?php else: ?>
    <table>
    <tr><th>ID</th><th>Account</th><th>Page ID</th><th>Loại</th><th>Giờ hẹn</th><th>Còn lại</th></tr>
    <?php foreach ($upcoming as $u): ?>
    <tr>
        <td><?= $u['id'] ?></td>
        <td><span style="color:#a78bfa"><?= htmlspecialchars($u['account_name'] ?? 'System') ?></span></td>
        <td><?= $u['page_id'] ?></td>
        <td><?= $u['post_type'] ?></td>
        <td><?= $u['scheduled_time'] ?></td>
        <td class="ok-time"><?= (int)$u['minutes_left'] ?> phút nữa</td>
    </tr>
    <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<!-- Bài đang PROCESSING -->
<div class="card">
    <h2 style="color: #38bdf8;">⏳ Bài đang xử lý (PROCESSING - <?= count($processing_posts) ?> bài)</h2>
    <?php if ($reset_msg): ?>
    <p class="ok">✔ <?= htmlspecialchars($reset_msg) ?></p>
    <?php endif; ?>
    
    <?php if (empty($processing_posts)): ?>
    <p class="ok">✔ Hiện không có bài nào đang xử lý.</p>
    <?php else: ?>
    <p class="warn">⚠ Lưu ý: Nếu bài ở trạng thái này quá lâu (ví dụ > 20p), có thể tiến trình đã bị treo.</p>
    <table>
    <tr><th>ID</th><th>Account</th><th>Page ID</th><th>Loại</th><th>Giờ hẹn</th><th>Cập nhật cuối</th><th>Đã trôi qua</th></tr>
    <?php foreach ($processing_posts as $p): ?>
    <tr>
        <td><?= $p['id'] ?></td>
        <td><span style="color:#a78bfa"><?= htmlspecialchars($p['account_name'] ?? 'System') ?></span></td>
        <td><?= $p['page_id'] ?></td>
        <td><?= $p['post_type'] ?></td>
        <td><?= $p['scheduled_time'] ?></td>
        <td><?= $p['updated_at'] ?></td>
        <td class="<?= $p['duration_min'] > 20 ? 'err' : 'warn' ?>"><?= (int)$p['duration_min'] ?> phút</td>
    </tr>
    <?php endforeach; ?>
    </table>
    <br>
    <a class="btn" style="background:#e11d48;color:#fff" href="?force_reset=1">🔥 Force Reset To Pending (Giải phóng bài treo)</a>
    <?php endif; ?>
</div>

<!-- Bài chờ Insights -->
<div class="card" style="border-left: 5px solid #10b981;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2 style="color: #10b981;">📊 Bài chờ điều kiện Bình luận (Insights - <?= count($insights_waiting) ?> bài)</h2>
        <div style="text-align:right">
            <span style="font-size:12px; color:#64748b">Lần cuối Cron chạy:</span>
            <strong style="color:<?php
                if ($last_cron_run === 'Chưa từng chạy') {
                    echo '#64748b';
                } else {
                    $diff = time() - strtotime($last_cron_run);
                    echo ($diff < 300) ? '#10b981' : '#ef4444'; 
                }
            ?>"><?= $last_cron_run ?></strong>
        </div>
    </div>
    <p style="font-size:13px;color:#64748b;margin-bottom:15px">Danh sách các bài đã đăng đang được theo dõi View/Like/Comment để tự động bình luận.</p>
    
    <?php if (empty($insights_waiting)): ?>
    <p class="ok">✔ Không có bài nào đang chờ insights.</p>
    <?php else: ?>
    <table>
    <tr><th>ID</th><th>Account</th><th>Post ID</th><th>Ngưỡng (V/L/C)</th><th>Trạng thái</th><th>Giờ đăng</th></tr>
    <?php foreach ($insights_waiting as $p): ?>
    <tr>
        <td>#<?= $p['id'] ?></td>
        <td><span style="color:#a78bfa"><?= htmlspecialchars($p['account_name'] ?? 'System') ?></span></td>
        <td><a href="https://facebook.com/<?= $p['fb_post_id'] ?>" target="_blank" style="font-weight:bold"><?= $p['fb_post_id'] ?></a></td>
        <td>
            <span style="color:#0369a1">👁️ <?= $p['comment_threshold_views'] ?></span> |
            <span style="color:#991b1b">👍 <?= $p['comment_threshold_likes'] ?></span> |
            <span style="color:#166534">💬 <?= $p['comment_threshold_comments'] ?></span>
        </td>
        <td>
            <?php if ($p['comment_status'] === 'waiting_insights'): ?>
                <span style="color:#0891b2">⏳ Đang theo dõi...</span>
            <?php else: ?>
                <span style="color:#64748b"><?= $p['comment_status'] ?></span>
            <?php endif; ?>
        </td>
        <td style="font-size:12px"><?= $p['scheduled_time'] ?></td>
    </tr>
    <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<!-- Lock Files -->
<div class="card">
    <h2>🔒 Worker Lock Files (<?= count($lock_files) ?> files)</h2>
    <?php if ($clear_msg): ?>
    <p class="ok">✔ <?= htmlspecialchars($clear_msg) ?></p>
    <?php endif; ?>
    <?php if (empty($lock_files)): ?>
    <p class="ok">✔ Không có lock file nào. Tất cả worker đều rảnh.</p>
    <?php else: ?>
    <p class="warn">⚠ Có lock file tồn tại. Nếu bài không đăng được, thử xoá lock để unblock worker.</p>
    <table>
    <tr><th>File</th><th>Tuổi (phút)</th><th>Đang chạy?</th></tr>
    <?php foreach ($lock_files as $lf): ?>
    <tr>
        <td style="font-size:11px;word-break:break-all"><?= htmlspecialchars($lf['file']) ?></td>
        <td><?= $lf['age_min'] ?> phút</td>
        <td><?php if ($lf['locked']): ?><span class="badge processing">🔒 LOCKED</span><?php else: ?><span class="badge published">✔ FREE</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </table>
    <br>
    <a class="btn" style="background:#dc2626;color:#fff" href="?clear_locks=1">🗑 Xoá tất cả Lock Files</a>
    <?php endif; ?>
</div>

<!-- Kích hoạt thủ công -->
<div class="card">
    <h2>▶ Kích hoạt thủ công</h2>
    <p style="color:#94a3b8">Bấm nút để chạy dispatcher ngay lập tức (không cần chờ cron):</p>
    <a class="btn btn-green" href="?run=publish">▶ Chạy Publish Dispatcher</a>
    <a class="btn btn-blue"  href="?run=comment">▶ Chạy Comment Dispatcher</a>
    <a class="btn" style="background:#7c3aed;color:#fff" href="?run=insights">▶ Chạy Comment Insights Check</a>
    <?php if ($run_msg): ?>
    <div class="run-output"><?= $run_msg ?></div>
    <?php endif; ?>
</div>

<!-- Hướng dẫn cài Cron -->
<div class="card">
    <h2>📋 Cài đặt Cron trên AaPanel / Linux</h2>

    <!-- PHP Binary Info -->
    <div style="background:#0f172a;border:1px solid #1e3a5f;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
        <p style="margin:0 0 6px;color:#7ee8fa;font-weight:bold;">⚙ Thông tin tự động phát hiện trên server này:</p>
        <table style="font-size:13px;border:none;">
            <tr>
                <td style="color:#64748b;padding:3px 12px 3px 0;border:none;">PHP binary:</td>
                <td style="border:none;">
                    <code style="color:#4ade80;background:#052e16;padding:2px 8px;border-radius:4px;"><?= htmlspecialchars($php_bin_full) ?></code>
                    <?php if ($php_bin_full === 'php'): ?>
                    <span style="color:#facc15;font-size:11px;margin-left:6px;">⚠ fallback — thử chạy <code>which php</code> trên server để xác nhận</span>
                    <?php else: ?>
                    <span style="color:#4ade80;font-size:11px;margin-left:6px;">✔ <?= htmlspecialchars($php_bin_note) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td style="color:#64748b;padding:3px 12px 3px 0;border:none;">PHP version:</td>
                <td style="border:none;"><code style="color:#4ade80;background:#052e16;padding:2px 8px;border-radius:4px;"><?= phpversion() ?></code></td>
            </tr>
            <tr>
                <td style="color:#64748b;padding:3px 12px 3px 0;border:none;">Cron folder:</td>
                <td style="border:none;"><code style="color:#7ee8fa;background:#0a0a14;padding:2px 8px;border-radius:4px;"><?= htmlspecialchars($cron_dir_path) ?></code></td>
            </tr>
        </table>
    </div>

    <p style="color:#94a3b8;margin-bottom:12px;">Bạn cần <b>2 Cron Job</b>. Chọn loại server của bạn:</p>

    <!-- Tab switcher -->
    <div style="display:flex;gap:8px;margin-bottom:16px;">
        <button id="tab-aapanel" onclick="switchTab('aapanel')"
            style="background:#1d4ed8;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;cursor:pointer;font-weight:bold;">
            🟢 AaPanel (php)
        </button>
        <button id="tab-fullpath" onclick="switchTab('fullpath')"
            style="background:#374151;color:#9ca3af;border:none;border-radius:6px;padding:7px 18px;font-size:13px;cursor:pointer;">
            🔵 Crontab hệ thống (đường dẫn đầy đủ)
        </button>
    </div>

    <!-- AaPanel tab -->
    <div id="content-aapanel">
        <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <p style="color:#a78bfa;font-weight:bold;margin:0;">① Cron Job 1 — Publish + Comment (mỗi 1 phút)</p>
                <button onclick="copyText('cron1a')" style="background:#1d4ed8;color:#fff;border:none;border-radius:5px;padding:4px 12px;font-size:12px;cursor:pointer;">📋 Copy</button>
            </div>
            <pre id="cron1a" style="background:#0a0a14;padding:12px;border-radius:6px;color:#7ee8fa;margin:0;white-space:pre-wrap;word-break:break-all;">php <?= htmlspecialchars($cron_dir_path) ?>/start_publish.php >> /tmp/fb_publish.log 2>&1
php <?= htmlspecialchars($cron_dir_path) ?>/start_comment.php >> /tmp/fb_comment.log 2>&1</pre>
            <p style="color:#64748b;font-size:12px;margin-top:5px;">AaPanel: <b>N Minutes → 1 Minute</b> | Type: Shell Script</p>
        </div>
        <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <p style="color:#a78bfa;font-weight:bold;margin:0;">② Cron Job 2 — Comment Insights Check (mỗi 5 phút)</p>
                <button onclick="copyText('cron2a')" style="background:#7c3aed;color:#fff;border:none;border-radius:5px;padding:4px 12px;font-size:12px;cursor:pointer;">📋 Copy</button>
            </div>
            <pre id="cron2a" style="background:#0a0a14;padding:12px;border-radius:6px;color:#c4b5fd;margin:0;white-space:pre-wrap;word-break:break-all;">php <?= htmlspecialchars($cron_dir_path) ?>/comment_insights_worker.php >> /tmp/fb_comment_insights.log 2>&1</pre>
            <p style="color:#64748b;font-size:12px;margin-top:5px;">AaPanel: <b>N Minutes → 5 Minutes</b> | Type: Shell Script</p>
        </div>
    </div>

    <!-- Full path tab -->
    <div id="content-fullpath" style="display:none;">
        <p style="color:#facc15;font-size:12px;margin-bottom:10px;">⚙ PHP binary phát hiện: <code style="color:#4ade80;"><?= htmlspecialchars($php_bin_full) ?></code> <span style="color:#64748b;">(<?= htmlspecialchars($php_bin_note) ?>)</span></p>
        <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <p style="color:#a78bfa;font-weight:bold;margin:0;">① Cron Job 1 — Publish + Comment (mỗi 1 phút)</p>
                <button onclick="copyText('cron1b')" style="background:#1d4ed8;color:#fff;border:none;border-radius:5px;padding:4px 12px;font-size:12px;cursor:pointer;">📋 Copy</button>
            </div>
            <pre id="cron1b" style="background:#0a0a14;padding:12px;border-radius:6px;color:#7ee8fa;margin:0;white-space:pre-wrap;word-break:break-all;"><?= htmlspecialchars($php_bin_full) ?> <?= htmlspecialchars($cron_dir_path) ?>/start_publish.php >> /tmp/fb_publish.log 2>&1
<?= htmlspecialchars($php_bin_full) ?> <?= htmlspecialchars($cron_dir_path) ?>/start_comment.php >> /tmp/fb_comment.log 2>&1</pre>
            <p style="color:#64748b;font-size:12px;margin-top:5px;">Crontab: <code>* * * * *</code> (mỗi phút)</p>
        </div>
        <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <p style="color:#a78bfa;font-weight:bold;margin:0;">② Cron Job 2 — Comment Insights Check (mỗi 5 phút)</p>
                <button onclick="copyText('cron2b')" style="background:#7c3aed;color:#fff;border:none;border-radius:5px;padding:4px 12px;font-size:12px;cursor:pointer;">📋 Copy</button>
            </div>
            <pre id="cron2b" style="background:#0a0a14;padding:12px;border-radius:6px;color:#c4b5fd;margin:0;white-space:pre-wrap;word-break:break-all;"><?= htmlspecialchars($php_bin_full) ?> <?= htmlspecialchars($cron_dir_path) ?>/comment_insights_worker.php >> /tmp/fb_comment_insights.log 2>&1</pre>
            <p style="color:#64748b;font-size:12px;margin-top:5px;">Crontab: <code>*/5 * * * *</code> (mỗi 5 phút)</p>
        </div>
    </div>

    <hr style="border-color:#1e293b;margin:16px 0">
    <p style="color:#94a3b8;font-size:12px;">📂 Xem log: <code>tail -f /tmp/fb_publish.log</code> &nbsp;|&nbsp; <code>tail -f /tmp/fb_comment_insights.log</code></p>
</div>

<script>
function switchTab(tab) {
    document.getElementById('content-aapanel').style.display  = (tab === 'aapanel')  ? '' : 'none';
    document.getElementById('content-fullpath').style.display = (tab === 'fullpath') ? '' : 'none';
    document.getElementById('tab-aapanel').style.background  = (tab === 'aapanel')  ? '#1d4ed8' : '#374151';
    document.getElementById('tab-aapanel').style.color       = (tab === 'aapanel')  ? '#fff'    : '#9ca3af';
    document.getElementById('tab-aapanel').style.fontWeight  = (tab === 'aapanel')  ? 'bold'   : 'normal';
    document.getElementById('tab-fullpath').style.background = (tab === 'fullpath') ? '#6d28d9' : '#374151';
    document.getElementById('tab-fullpath').style.color      = (tab === 'fullpath') ? '#fff'    : '#9ca3af';
    document.getElementById('tab-fullpath').style.fontWeight = (tab === 'fullpath') ? 'bold'   : 'normal';
}
function copyText(id) {
    var el = document.getElementById(id);
    var text = el.innerText || el.textContent;
    navigator.clipboard.writeText(text).then(function() {
        var btn = event.target;
        var orig = btn.textContent;
        btn.textContent = '✔ Đã copy!';
        btn.style.background = '#15803d';
        setTimeout(function() { btn.textContent = orig; btn.style.background = ''; }, 2000);
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
