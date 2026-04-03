<?php
// cron/start_publish.php
// Dispatcher: Quét xem có bao nhiêu User có bài cần đăng, sau đó kích hoạt bấy nhiêu luồng riêng biệt.

require_once __DIR__ . '/../includes/db.php';

// Tìm các page có bài cần đăng: pending HOẶC failed còn retry (retry_count < max từ settings)
$max_retries = 3;
try {
    $mr = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='max_retries'");
    if ($mr) $max_retries = (int)($mr->fetchColumn() ?: 3);
} catch (Exception $e) {}

$stmt = $pdo->prepare("
    SELECT DISTINCT page_id FROM scheduled_posts
    WHERE scheduled_time <= NOW()
      AND page_id IS NOT NULL
      AND (
        status = 'pending'
        OR (status = 'failed' AND (retry_count IS NULL OR retry_count < ?))
      )
");
$stmt->execute([$max_retries]);
$pages = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($pages)) {
    echo "Không có Fanpage nào cần đăng tải.\n";
    exit;
}

echo "Có " . count($pages) . " Fanpage đang có bài hẹn giờ đăng trùng lúc. Khởi chạy " . count($pages) . " luồng độc lập...\n";

$is_web = isset($_SERVER['HTTP_HOST']);
$exec_enabled = function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false;

foreach ($pages as $pid) {
    if (!$pid) continue; // Skip NULL
    
    if ($exec_enabled) {
        $php_bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $script_path = __DIR__ . DIRECTORY_SEPARATOR . 'publish_worker.php';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B \"\" \"$php_bin\" \"$script_path\" \"$pid\"", "r"));
        } else {
            exec("\"$php_bin\" \"$script_path\" \"$pid\" > /dev/null 2>&1 &");
        }
        echo "  -> Đã kích hoạt luồng CLI cho Page ID: $pid\n";
    } elseif ($is_web) {
        // Fallback Web-Forking (cURL Async)
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        // Attempt to build accurate web path to current cron directory
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        $cron_dir_web_path = str_replace('\\', '/', str_replace($doc_root, '', __DIR__));
        if (empty($cron_dir_web_path)) $cron_dir_web_path = '/cron'; // Fallback
        
        $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . rtrim($cron_dir_web_path, '/') . "/publish_worker.php?page_id=" . urlencode($pid);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500); // Tăng timeout để webserver kịp nhận request kích luồng
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_exec($ch);
        curl_close($ch);
        echo "  -> Đã kích luồng WEB-AJAX cho Page ID: $pid\n";
    } else {
        echo "  -> THẤT BẠI: Hàm exec() bị khóa.\n";
    }
}

echo "Đã Dispatch hoàn tất.\n";
