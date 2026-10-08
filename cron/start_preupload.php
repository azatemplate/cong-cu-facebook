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

// ── BỘ KIỂM TRA ƯU TIÊN (PRIORITY GATE): CHỈ PRE-UPLOAD KHI HỆ THỐNG RẢNH ──
try {
    $overdue_count = (int)$pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE scheduled_time <= NOW() AND status IN ('pending', 'failed')")->fetchColumn();
    if ($overdue_count > 20) {
        echo "[" . date('H:i:s') . "] ⚠ Hàng đợi quá hạn đang có {$overdue_count} bài cần đăng gấp. Tạm dừng Pre-upload để nhường tài nguyên.\n";
        $rq_pre->releaseLock('lock:cron:start_preupload');
        exit;
    }
} catch (Exception $e) {
    $rq_pre->releaseLock('lock:cron:start_preupload');
    exit;
}

// ── BƯỚC 2: QUÉT BÀI VIẾT HẸN GIỜ TRONG TƯƠNG LAI (15 phút đến 24 giờ tới) ──
try {
    $sql = "
        SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time
        FROM scheduled_posts sp
        WHERE sp.status = 'pending'
          AND (sp.preupload_status IS NULL OR sp.preupload_status IN ('none', 'failed'))
          AND sp.scheduled_time BETWEEN DATE_ADD(NOW(), INTERVAL 15 MINUTE) AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
          AND sp.media_path IS NOT NULL AND sp.media_path != ''
          AND (sp.post_type IN ('Video', 'Reel', 'Photo', 'Facebook', 'Facebook Reel') OR sp.post_type LIKE 'Facebook%')
        ORDER BY sp.scheduled_time ASC
        LIMIT 10
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
        
        // Đánh dấu status 'uploading' tạm thời để tránh duplicate run
        $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'uploading' WHERE id = {$pid}");

        if ($is_win) {
            @pclose(@popen("start /B \"\" \"$php_bin\" \"$script_worker\" \"$pid\"", "r"));
        } else {
            @exec("nohup \"$php_bin\" \"$script_worker\" \"$pid\" > /dev/null 2>&1 &");
        }
        echo "   -> Dispatched Pre-upload Worker cho Post #{$pid}\n";
    }

} catch (Exception $e) {
    echo "Lỗi Pre-upload Dispatcher: " . $e->getMessage() . "\n";
}

$rq_pre->releaseLock('lock:cron:start_preupload');
echo "Completed start_preupload.\n";
