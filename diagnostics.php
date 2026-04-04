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
    }
    $run_msg = nl2br(htmlspecialchars(ob_get_clean()));
    if (isset($_GET['ajax'])) {
        echo strip_tags($run_msg);
        exit;
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
    SELECT id, account_id, page_id, post_type, status, retry_count, scheduled_time,
           TIMESTAMPDIFF(MINUTE, scheduled_time, NOW()) AS overdue_minutes
    FROM scheduled_posts
    WHERE scheduled_time <= NOW()
      AND (
        status = 'pending'
        OR (status = 'failed' AND (retry_count IS NULL OR retry_count < ?))
      )
    ORDER BY scheduled_time ASC
    LIMIT 30
");
$ready_stmt->execute([$max_retries]);
$ready_posts = $ready_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Bài pending chưa tới giờ ─────────────────────────────────────────────────
$upcoming = $pdo->query("
    SELECT id, page_id, post_type, scheduled_time,
           TIMESTAMPDIFF(MINUTE, NOW(), scheduled_time) AS minutes_left
    FROM scheduled_posts
    WHERE status = 'pending' AND scheduled_time > NOW()
    ORDER BY scheduled_time ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Kiểm tra exec() ──────────────────────────────────────────────────────────
$exec_ok = function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false;

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
    <tr><th>ID</th><th>Page ID</th><th>Loại</th><th>Trạng thái</th><th>Retry</th><th>Giờ hẹn</th><th>Quá hạn</th></tr>
    <?php foreach ($ready_posts as $p): ?>
    <tr>
        <td><?= $p['id'] ?></td>
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
    <tr><th>ID</th><th>Page ID</th><th>Loại</th><th>Giờ hẹn</th><th>Còn lại</th></tr>
    <?php foreach ($upcoming as $u): ?>
    <tr>
        <td><?= $u['id'] ?></td>
        <td><?= $u['page_id'] ?></td>
        <td><?= $u['post_type'] ?></td>
        <td><?= $u['scheduled_time'] ?></td>
        <td class="ok-time"><?= (int)$u['minutes_left'] ?> phút nữa</td>
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
    <?php if ($run_msg): ?>
    <div class="run-output"><?= $run_msg ?></div>
    <?php endif; ?>
</div>

<!-- Hướng dẫn cài Cron -->
<div class="card">
    <h2>📋 Cài đặt Cron trên AaPanel / Linux</h2>
    <p style="color:#94a3b8">Thêm 2 dòng sau vào <b>crontab</b> (chạy mỗi phút):</p>
    <pre style="background:#0a0a14;padding:12px;border-radius:6px;color:#7ee8fa;">* * * * * /www/server/php/81/bin/php <?= realpath(__DIR__ . '/cron') ?>/start_publish.php >> /tmp/fb_publish.log 2>&1
* * * * * /www/server/php/81/bin/php <?= realpath(__DIR__ . '/cron') ?>/start_comment.php >> /tmp/fb_comment.log 2>&1</pre>
    <p style="color:#94a3b8;font-size:12px;">Trên AaPanel: Cron Jobs → Add Cron Job → Shell Script → mỗi 1 phút.</p>
    <p style="color:#facc15;font-size:12px;">⚠ <b>Lưu ý quan trọng:</b> Đường dẫn PHP phải dùng đường dẫn tuyệt đối (ví dụ: <code>/www/server/php/81/bin/php</code>). Nếu cron chạy mà không có output, kiểm tra file log <code>/tmp/fb_publish.log</code></p>
    <p style="color:#94a3b8;font-size:12px;">Xem log realtime: <code>tail -f /tmp/fb_publish.log</code></p>
</div>

</body>
</html>
