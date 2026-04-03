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
        $php_bin = 'php';
        if (defined('PHP_BINARY') && PHP_BINARY && strpos(PHP_BINARY, 'php-fpm') === false && strpos(PHP_BINARY, 'php-cgi') === false) {
            $php_bin = PHP_BINARY;
        } elseif (file_exists('/www/server/php/81/bin/php')) {
            $php_bin = '/www/server/php/81/bin/php'; // Fallback mạnh nhất cho aaPanel
        } elseif (file_exists('/usr/bin/php')) {
            $php_bin = '/usr/bin/php';
        }
        
        $script_path = __DIR__ . DIRECTORY_SEPARATOR . 'publish_worker.php';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B \"\" \"$php_bin\" \"$script_path\" \"$pid\"", "r"));
        } else {
            exec("\"$php_bin\" \"$script_path\" \"$pid\" > /dev/null 2>&1 &");
        }
        echo "  -> Đã kích hoạt luồng CLI ($php_bin) cho Page ID: $pid\n";
    } elseif ($is_web) {
        // Fallback Web-Forking (cURL Async) qua Wrapper gốc
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        // Attempt to build accurate web path to current cron directory
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        $root_web_path = rtrim(str_replace('\\', '/', str_replace($doc_root, '', dirname(__DIR__))), '/');
        
        $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $root_web_path . "/run_worker.php?type=publish&page_id=" . urlencode($pid);

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
