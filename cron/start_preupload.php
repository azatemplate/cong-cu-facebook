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

$lock_dir = dirname(__DIR__) . '/locks';

// ── 1. ĐẾM VÀ DỌN DẸP PRE-UPLOAD WORKERS THỰC TẾ (OS-LEVEL LOCKS) ──
$active_preupload_workers = 0;
$active_preupload_ids = [];
if (is_dir($lock_dir)) {
    foreach (glob($lock_dir . '/preupload_post_*.lock') ?: [] as $lf) {
        if (!file_exists($lf)) continue;
        $fp = @fopen($lf, 'c+');
        if ($fp) {
            if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                $active_preupload_workers++;
                if (preg_match('/preupload_post_(\d+)\.lock$/', $lf, $m)) {
                    $active_preupload_ids[] = (int)$m[1];
                }
                @fclose($fp);
            } else {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                @unlink($lf);
            }
        }
    }
}

// Giải phóng toàn bộ bài kẹt status 'uploading' nhưng không còn file lock hoạt động
try {
    if (!empty($active_preupload_ids)) {
        $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'none' WHERE preupload_status = 'uploading' AND id NOT IN (" . implode(',', $active_preupload_ids) . ")");
    } else {
        $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'none' WHERE preupload_status = 'uploading'");
    }
} catch (Exception $e) {}

// ── 2. BỘ KIỂM TRA THROTTLING SLOTS: TỔNG (PUBLISH + PREUPLOAD) KHÔNG ĐƯỢC VƯỢT MAX ──
$MAX_WORKERS = 30;
try {
    $res_limit = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_publish_workers'")->fetchColumn();
    if ($res_limit !== false && $res_limit !== null && $res_limit !== '') {
        $MAX_WORKERS = max(1, (int)$res_limit);
    }
} catch (Exception $e) {}

$active_publish_locks = 0;
if (is_dir($lock_dir)) {
    foreach (glob($lock_dir . '/publish_user_*.lock') ?: [] as $lf) {
        if (!file_exists($lf)) continue;
        $fp = @fopen($lf, 'c+');
        if ($fp) {
            if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                $active_publish_locks++;
                @fclose($fp);
            } else {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                @unlink($lf);
            }
        }
    }
}

$active_publish_db = 0;
try {
    $active_publish_db = (int)$pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE status = 'processing'")->fetchColumn();
} catch (Exception $e) {}

$active_publish = max($active_publish_locks, $active_publish_db);

// ── 3. ĐẾM BÀI SẼ ĐẮNG TRONG 10 PHÚT TỚI ĐỂ DÀNH SLOT TRỐNG ──
$upcoming_publish_count_10m = 0;
try {
    $upcoming_publish_count_10m = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM scheduled_posts 
        WHERE status IN ('pending', 'failed')
          AND scheduled_time <= DATE_ADD(NOW(), INTERVAL 10 MINUTE)
    ")->fetchColumn();
} catch (Exception $e) {}

// Số slot bắt buộc dành riêng cho xuất bản bài viết trong 10 phút tới
$reserved_publish_slots = max($active_publish, $upcoming_publish_count_10m);

// Số slot thực sự rảnh rỗi có thể dành cho Pre-upload bài tương lai (>10 phút nữa)
$available_slots = max(0, $MAX_WORKERS - $reserved_publish_slots - $active_preupload_workers);

if ($available_slots <= 0) {
    echo "[" . date('H:i:s') . "] ⚠ Throttling 10 phút tới cần dùng ($reserved_publish_slots bài xuất bản + $active_preupload_workers bài Pre-upload = " . ($reserved_publish_slots + $active_preupload_workers) . "/$MAX_WORKERS luồng). Tạm dừng Pre-upload để nhường tài nguyên.\n";
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

// ── BƯỚC 2: QUÉT BÀI VIẾT HẸN GIỜ TRONG TƯƠNG LAI (Sau 10 phút nữa đến 24 giờ tới) ──
try {
    $fetch_limit = min(10, $available_slots);
    $sql = "
        SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.campaign_id
        FROM scheduled_posts sp
        WHERE sp.status = 'pending'
          AND (sp.preupload_status IS NULL OR sp.preupload_status = 'none')
          AND sp.scheduled_time >= DATE_ADD(NOW(), INTERVAL 10 MINUTE)
          AND sp.media_path IS NOT NULL AND sp.media_path != ''
          AND (LOWER(sp.post_type) IN ('video', 'reel', 'photo', 'image', 'facebook', 'facebook reel') OR sp.post_type LIKE '%Facebook%' OR sp.post_type LIKE '%Reel%' OR sp.post_type LIKE '%Video%' OR sp.post_type LIKE '%Photo%' OR sp.post_type LIKE '%Image%')
        ORDER BY sp.scheduled_time ASC
        LIMIT {$fetch_limit}
    ";

    $posts = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($posts)) {
        echo "[" . date('H:i:s') . "] Không có bài viết nào cần Pre-upload (lịch >10m nữa) trong 24h tới.\n";
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
