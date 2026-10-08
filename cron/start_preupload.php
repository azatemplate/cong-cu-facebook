<?php
// cron/start_preupload.php
// Dispatcher: Quét các bài viết lên lịch trong tương lai để upload nháp trước khi RẢNH.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/redis_queue.php';

$rq_pre = RedisQueue::getInstance();
if (!$rq_pre->acquireLock('lock:cron:start_preupload', 30)) {
    echo "[" . date('H:i:s') . "] Dispatcher start_preupload.php đang chạy ở tiến trình khác. Bỏ qua.\n";
    exit;
}

if (!function_exists('get_php_cli_bin')) {
    require_once __DIR__ . '/../includes/php_cli.php';
}
$php_bin = get_php_cli_bin();
$is_win = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

// ── TỰ ĐỘNG DỌN PRE-UPLOAD BỊ TREO (>15 PHÚT) ──
try {
    $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'none' WHERE preupload_status = 'uploading' AND updated_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
} catch (Exception $e) {}

// ── BỘ KIỂM TRA THROTTLING SLOTS: TỔNG (PUBLISH + PREUPLOAD) KHÔNG ĐƯỢC VƯỢT MAX ──
$MAX_WORKERS = 30;
try {
    $res_limit = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_publish_workers'")->fetchColumn();
    if ($res_limit !== false && $res_limit !== null && $res_limit !== '') {
        $MAX_WORKERS = max(1, (int)$res_limit);
    }
} catch (Exception $e) {}

$lock_dir = dirname(__DIR__) . '/locks';
$active_lock_workers = 0;
if (is_dir($lock_dir)) {
    foreach (glob($lock_dir . '/publish_user_*.lock') ?: [] as $lf) {
        if (!file_exists($lf)) continue;
        $fp = @fopen($lf, 'c+');
        if ($fp) {
            if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                $active_lock_workers++;
                @fclose($fp);
            } else {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                @unlink($lf);
            }
        }
    }
}

$active_publish_db   = 0;
$active_preupload_db = 0;
try {
    $active_publish_db   = (int)$pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE status = 'processing'")->fetchColumn();
    $active_preupload_db = (int)$pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE preupload_status = 'uploading'")->fetchColumn();
} catch (Exception $e) {}

$active_publish = max($active_lock_workers, $active_publish_db);
$total_active   = $active_publish + $active_preupload_db;

$available_slots = max(0, $MAX_WORKERS - $total_active);

if ($available_slots <= 0) {
    echo "[" . date('H:i:s') . "] ⚠ Throttling đã đạt trần ($active_publish Đăng + $active_preupload_db Preupload = $total_active/$MAX_WORKERS luồng). Tạm dừng Pre-upload.\n";
    $rq_pre->releaseLock('lock:cron:start_preupload');
    exit;
}

// ── ĐẢM BẢO CÁC CỘT PREUPLOAD ĐÃ TỒN TẠI TRONG CSDL ──
try {
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS preupload_status ENUM('none','pending','uploading','uploaded','failed') DEFAULT 'none'");
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS preuploaded_media_id VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS preupload_session_id VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS preupload_error TEXT DEFAULT NULL");
} catch (Exception $e) {}

// ── BƯỚC 2: QUÉT BÀI VIẾT HẸN GIỜ TRONG TƯƠNG LAI (15 phút đến 24 giờ tới) ──
try {
    $fetch_limit = min(10, $available_slots);
    $sql = "
        SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.campaign_id
        FROM scheduled_posts sp
        WHERE sp.status = 'pending'
          AND (sp.preupload_status IS NULL OR sp.preupload_status = 'none')
          AND sp.scheduled_time >= DATE_ADD(NOW(), INTERVAL 1 MINUTE)
          AND sp.media_path IS NOT NULL AND sp.media_path != ''
          AND (LOWER(sp.post_type) IN ('video', 'reel', 'photo', 'facebook', 'facebook reel') OR sp.post_type LIKE '%Facebook%' OR sp.post_type LIKE '%Reel%' OR sp.post_type LIKE '%Video%')
        ORDER BY sp.scheduled_time ASC
        LIMIT {$fetch_limit}
    ";

    $posts = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($posts)) {
        echo "[" . date('H:i:s') . "] Không có bài viết nào cần Pre-upload trong 24h tới.\n";
        $rq_pre->releaseLock('lock:cron:start_preupload');
        exit;
    }

    echo "[" . date('H:i:s') . "] Tìm thấy " . count($posts) . " bài viết cần Pre-upload. Đang khởi tạo luồng ngầm...\n";

    $script_worker = __DIR__ . '/preupload_worker.php';
    foreach ($posts as $p) {
        $pid = (int)$p['id'];
        $cid = (int)($p['campaign_id'] ?? 0);
        $time_str = date('H:i d/m', strtotime($p['scheduled_time']));
        
        // Đánh dấu status 'uploading' tạm thời để tránh duplicate run
        $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'uploading' WHERE id = {$pid}");

        if ($is_win) {
            @pclose(@popen("start /B \"\" \"$php_bin\" \"$script_worker\" \"$pid\"", "r"));
        } else {
            @exec("nohup \"$php_bin\" \"$script_worker\" \"$pid\" > /dev/null 2>&1 &");
        }
        echo "   -> Dispatched Pre-upload Worker cho Post #{$pid} (Campaign #{$cid} - Hẹn: {$time_str})\n";
    }

} catch (Exception $e) {
    echo "Lỗi Pre-upload Dispatcher: " . $e->getMessage() . "\n";
}

$rq_pre->releaseLock('lock:cron:start_preupload');
echo "Completed start_preupload.\n";
