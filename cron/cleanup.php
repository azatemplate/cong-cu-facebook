<?php
// cron/cleanup.php
// Script dọn dẹp Tự Động: Dọn File Rác Temp/Uploads/Logs liên tục + Dọn DB 1 lần/ngày
// Crontab chạy mỗi 10-15 phút: */10 * * * * php /path/to/cron/cleanup.php >> /tmp/fb_cleanup.log 2>&1

ignore_user_abort(true);
set_time_limit(180);

require_once __DIR__ . '/../includes/redis_queue.php';
$rq_clean = RedisQueue::getInstance();

// Khóa Atomic Lock 2 phút tránh 2 tiến trình cleanup đè lên nhau
if (!$rq_clean->acquireLock('lock:cron:cleanup_task', 120)) {
    echo "[" . date('H:i:s') . "] Cleanup đang chạy ở tiến trình khác. Bỏ qua.\n";
    return;
}

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../includes/db.php';

echo "\n========================================\n";
echo "  FB AUTO-CLEANUP — " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

$stats = [
    'temp_files'       => 0,
    'uploads_tmp'      => 0,
    'sys_tmp_files'    => 0,
    'logs_truncated'   => 0,
    'media_files'      => 0,
    'scheduled_posts'  => 0,
    'posts_history'    => 0,
    'campaigns'        => 0,
    'lock_files'       => 0,
];

// ═══════════════════════════════════════════════════════════════════════════════
// PHẦN 1: DỌN DẸP FILE RÁC SIÊU TỐC (CHẠY MỖI LẦN CLEANUP - CŨ HƠN 5 PHÚT)
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[STEP 1] Dọn dẹp thư mục temp/ & uploads/tmp/ (Files cũ > 5 phút)...\n";

$root_dir = realpath(__DIR__ . '/../');
$temp_dirs = [
    $root_dir . '/temp',
    $root_dir . '/uploads/tmp',
    $root_dir . '/uploads'
];

$cutoff_5m = time() - 300; // 5 phút (300 giây)

foreach ($temp_dirs as $tdir) {
    if (!is_dir($tdir)) continue;
    $files = glob($tdir . '/*');
    if (!$files) continue;

    foreach ($files as $f) {
        if (!is_file($f)) continue;
        
        $fname = basename($f);
        $mtime = filemtime($f);

        // Bảo vệ tuyệt đối không đụng vào file socket hệ thống (.sock)
        if (substr($fname, -5) === '.sock' || @is_link($f) || @filetype($f) === 'socket') continue;

        // Đối với thư mục /uploads/, CHỈ xóa các file tạm có tiền tố buf_, gdrive_, yt_tik_ khi đã tạo > 5 PHÚT
        if ($tdir === $root_dir . '/uploads') {
            $is_buf_junk = (strpos($fname, 'buf_') === 0 || strpos($fname, 'gdrive_') === 0 || strpos($fname, 'yt_tik_') === 0);
            if ($is_buf_junk && $mtime < $cutoff_5m) {
                if (@unlink($f)) {
                    $stats['uploads_tmp']++;
                }
            }
            continue;
        }

        // Các định dạng file tạm lớn hoặc file cURL / Drive / Chunk trong /temp và /uploads/tmp/
        $is_junk = preg_match('/\.(mp4|mov|avi|tmp|png|jpg|jpeg|webp|mkv)$/i', $fname) ||
                   strpos($fname, 'buf_chk_') === 0 ||
                   strpos($fname, 'buf_') === 0 ||
                   strpos($fname, 'gdrive_') === 0 ||
                   strpos($fname, 'curl_') === 0 ||
                   strpos($fname, 'yt_tik_') === 0 ||
                   strpos($fname, 'fb_att_') === 0 ||
                   strpos($fname, 'zalo_att_') === 0;

        if ($is_junk && $mtime < $cutoff_5m) {
            if (@unlink($f)) {
                if (strpos($tdir, 'uploads/tmp') !== false) {
                    $stats['uploads_tmp']++;
                } else {
                    $stats['temp_files']++;
                }
            }
        }
    }
}
echo "  -> Đã xóa: {$stats['temp_files']} files trong temp/, {$stats['uploads_tmp']} files trong uploads/tmp/ & uploads/ (đã tạo > 5 phút)\n";

// ═══════════════════════════════════════════════════════════════════════════════
// PHẦN 2: THU HẸP FILE LOG PHÌNH TO (> 10MB)
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[STEP 2] Thu hẹp các file Log phình to (> 10MB)...\n";
$log_files = [
    $root_dir . '/webhook_debug.txt',
    $root_dir . '/webhook_db_errors.txt',
    $root_dir . '/zalo_webhook_debug.log',
    $root_dir . '/error_log',
    $root_dir . '/actions/error_log',
    sys_get_temp_dir() . '/fb_cleanup.log'
];

// Tìm thêm các file .log trong root
foreach (glob($root_dir . '/*.log') ?: [] as $lf) {
    $log_files[] = $lf;
}

foreach ($log_files as $lf) {
    if (file_exists($lf) && is_file($lf) && filesize($lf) > 10 * 1024 * 1024) { // > 10MB
        $f = @fopen($lf, 'w');
        if ($f) {
            fwrite($f, "[" . date('Y-m-d H:i:s') . "] Log truncated automatically due to size > 10MB.\n");
            fclose($f);
            $stats['logs_truncated']++;
        }
    }
}
echo "  -> Đã thu hẹp: {$stats['logs_truncated']} file log lớn.\n";

// ═══════════════════════════════════════════════════════════════════════════════
// PHẦN 3: DỌN DẸP DATABASE LỚN (CHỈ CHẠY 1 LẦN/NGÀY HẶC KHI FORCE=1)
// ═══════════════════════════════════════════════════════════════════════════════
$is_force = isset($_GET['force']) || (php_sapi_name() === 'cli' && isset($argv) && in_array('--force', $argv));
$flag_file = sys_get_temp_dir() . '/fb_cleanup_db_' . date('Y-m-d') . '.done';

if (!$is_force && file_exists($flag_file)) {
    echo "\n[INFO] Dọn dẹp DB đã hoàn tất hôm nay (" . trim(file_get_contents($flag_file)) . ").\n";
    echo "[DONE] Hoàn tất tiến trình dọn dẹp temp & log.\n";
    $rq_clean->releaseLock('lock:cron:cleanup_task');
    return;
}

// Đọc cấu hình từ DB (Mặc định 3 ngày)
$retain_days = 3;
try {
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('cleanup_retain_days', '3') ON DUPLICATE KEY UPDATE setting_value = '3'");
    $rd = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='cleanup_retain_days'");
    if ($rd) $retain_days = max(1, (int)($rd->fetchColumn() ?: 3));
} catch (Exception $e) {}

$history_retain_days = $retain_days * 2;
echo "\n[INFO DB] Giữ lại bài đã đăng: $retain_days ngày | Lịch sử: $history_retain_days ngày\n";


// ── Xóa media files của bài đã published > retain_days
echo "\n[STEP 3] Xóa media files bài cũ (> {$retain_days} ngày)...\n";
try {
    $media_stmt = $pdo->prepare("
        SELECT id, media_path FROM scheduled_posts
        WHERE status = 'published'
          AND scheduled_time < DATE_SUB(NOW(), INTERVAL ? DAY)
          AND media_path IS NOT NULL
          AND media_path != ''
    ");
    $media_stmt->execute([$retain_days]);
    $media_rows = $media_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($media_rows as $row) {
        $mp = $row['media_path'];
        $decoded = @json_decode($mp, true);
        $paths   = is_array($decoded) ? $decoded : [$mp];

        foreach ($paths as $p) {
            $p = trim($p);
            if (strpos($p, 'uploads/') === false) continue;
            $full_path = $root_dir . '/' . ltrim($p, '/');
            if (!file_exists($full_path) || !is_file($full_path)) continue;

            $usage_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM scheduled_posts
                WHERE status IN ('pending', 'processing', 'failed')
                  AND media_path LIKE ?
            ");
            $usage_stmt->execute(['%' . basename($p) . '%']);
            if ($usage_stmt->fetchColumn() > 0) continue;

            if (@unlink($full_path)) {
                $stats['media_files']++;
            }
        }
    }
    echo "  -> Đã xóa: {$stats['media_files']} file media bài cũ\n";
} catch (Exception $e) {
    echo "  [LỖI] Media cleanup: " . $e->getMessage() . "\n";
}

// ── Xóa scheduled_posts đã published > retain_days
echo "\n[STEP 4] Xóa rows scheduled_posts cũ (published > {$retain_days} ngày)...\n";
try {
    $del_pub = $pdo->prepare("
        DELETE FROM scheduled_posts
        WHERE status = 'published'
          AND scheduled_time < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $del_pub->execute([$retain_days]);
    $stats['scheduled_posts'] += $del_pub->rowCount();
    echo "  -> Đã xóa: {$del_pub->rowCount()} rows (published)\n";
} catch (Exception $e) {
    echo "  [LỖI] Delete published: " . $e->getMessage() . "\n";
}

// ── Xóa scheduled_posts failed đã hết retry
echo "\n[STEP 5] Xóa rows scheduled_posts cũ (failed hết retry > {$retain_days} ngày)...\n";
try {
    $max_retries_cfg = 3;
    $mr = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='max_retries'");
    if ($mr) $max_retries_cfg = (int)($mr->fetchColumn() ?: 3);

    $del_fail = $pdo->prepare("
        DELETE FROM scheduled_posts
        WHERE status = 'failed'
          AND retry_count >= ?
          AND scheduled_time < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $del_fail->execute([$max_retries_cfg, $retain_days]);
    $cnt_fail = $del_fail->rowCount();
    $stats['scheduled_posts'] += $cnt_fail;
    echo "  -> Đã xóa: {$cnt_fail} rows (failed)\n";
} catch (Exception $e) {
    echo "  [LỖI] Delete failed: " . $e->getMessage() . "\n";
}

// ── Xóa posts_history > history_retain_days
echo "\n[STEP 6] Xóa posts_history cũ (> {$history_retain_days} ngày)...\n";
try {
    $del_hist = $pdo->prepare("
        DELETE FROM posts_history
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $del_hist->execute([$history_retain_days]);
    $stats['posts_history'] = $del_hist->rowCount();
    echo "  -> Đã xóa: {$stats['posts_history']} rows lịch sử\n";
} catch (Exception $e) {
    echo "  [LỖI] History cleanup: " . $e->getMessage() . "\n";
}

// ── OPTIMIZE TABLE disabled in automated cron to prevent InnoDB table locking
echo "\n[STEP 7] Bỏ qua OPTIMIZE TABLE tự động (tránh làm khóa bảng DB)...\n";

// Đánh dấu đã dọn DB hôm nay
@file_put_contents($flag_file, date('Y-m-d H:i:s'));

echo "\n========================================\n";
echo "  TỔNG KẾT CLEANUP HÔM NAY\n";
echo "  Temp files đã xóa:      " . ($stats['temp_files'] + $stats['uploads_tmp'] + $stats['sys_tmp_files']) . "\n";
echo "  Media bài cũ đã xóa:    {$stats['media_files']}\n";
echo "  Rows DB đã dọn:         " . ($stats['scheduled_posts'] + $stats['posts_history']) . "\n";
echo "  Thời gian:              " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

$rq_clean->releaseLock('lock:cron:cleanup_task');
echo "\n[DONE] Cleanup hoàn tất.\n";
