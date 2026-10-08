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

// ── BỘ KIỂM TRA THROTTLING SLOTS: CHỈ PRE-UPLOAD KHI THROTTLING CÒN SLOT TRỐNG ──
$MAX_WORKERS = 30;
try {
    $res_limit = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_publish_workers'")->fetchColumn();
    if ($res_limit !== false && $res_limit !== null && $res_limit !== '') {
        $MAX_WORKERS = max(1, (int)$res_limit);
    }
} catch (Exception $e) {}

$lock_dir = dirname(__DIR__) . '/locks';
$active_workers = 0;
if (is_dir($lock_dir)) {
    foreach (glob($lock_dir . '/publish_user_*.lock') ?: [] as $lf) {
        if (!file_exists($lf)) continue;
        $fp = @fopen($lf, 'c+');
        if ($fp) {
            if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                $active_workers++;
                @fclose($fp);
            } else {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                @unlink($lf);
            }
        }
    }
}

$available_slots = max(0, $MAX_WORKERS - $active_workers);

if ($available_slots <= 0) {
    echo "[" . date('H:i:s') . "] ⚠ Throttling đang hết slot trống ($active_workers/$MAX_WORKERS luồng). Tạm dừng Pre-upload.\n";
    $rq_pre->releaseLock('lock:cron:start_preupload');
    exit;
}

// ── ĐẢM BẢO CÁC CỘT PREUPLOAD ĐÃ TỒN TẠI TRONG CSDL ──
try {
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS preupload_status ENUM('none','pending','uploading','uploaded','failed') DEFAULT 'none'");
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS preuploaded_media_id VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS preupload_session_id VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) {}

// ── BƯỚC 2: QUÉT BÀI VIẾT HẸN GIỜ TRONG TƯƠNG LAI (15 phút đến 24 giờ tới) ──
try {
    $fetch_limit = min(10, $available_slots);
    $sql = "
        SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.campaign_id
        FROM scheduled_posts sp
        WHERE sp.status = 'pending'
          AND (sp.preupload_status IS NULL OR sp.preupload_status IN ('none', 'failed'))
          AND sp.scheduled_time BETWEEN DATE_ADD(NOW(), INTERVAL 2 MINUTE) AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
          AND sp.media_path IS NOT NULL AND sp.media_path != ''
          AND (sp.post_type IN ('Video', 'Reel', 'Photo', 'Facebook', 'Facebook Reel') OR sp.post_type LIKE 'Facebook%')
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
