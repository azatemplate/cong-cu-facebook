<?php
// cron/start_comment.php
// Dispatcher: Quét xem có bao nhiêu User có bình luận cần đăng, sau đó kích hoạt bấy nhiêu luồng độc lập.

require_once __DIR__ . '/../includes/db.php';

// Tìm các user có bình luận pending cần đăng ngay lúc này
$stmt = $pdo->query("SELECT DISTINCT account_id FROM scheduled_posts 
                     WHERE status = 'published'
                       AND comment_lines IS NOT NULL
                       AND comment_at IS NOT NULL
                       AND comment_at <= NOW()
                       AND comment_done = 0");
$accounts = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($accounts)) {
    echo "Không có user nào cần bình luận.\n";
    exit;
}

echo "Có " . count($accounts) . " user đang có bình luận cần đăng. Khởi chạy " . count($accounts) . " luồng độc lập...\n";

$is_web = isset($_SERVER['HTTP_HOST']);
$exec_enabled = function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false;

foreach ($accounts as $aid) {
    if (!$aid) continue; 
    
    if ($exec_enabled) {
        $php_bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $script_path = __DIR__ . DIRECTORY_SEPARATOR . 'comment_worker.php';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B \"\" \"$php_bin\" \"$script_path\" $aid", "r"));
        } else {
            exec("\"$php_bin\" \"$script_path\" $aid > /dev/null 2>&1 &");
        }
        echo "  -> Đã kích hoạt luồng bình luận CLI cho Account ID: $aid\n";
    } elseif ($is_web) {
        // Fallback Web-Forking (cURL Async Timeout 1ms)
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/cron/start_comment.php';
        $base_dir = dirname($uri);
        $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $base_dir . "/comment_worker.php?account_id=" . ((int)$aid);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500); 
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_exec($ch);
        curl_close($ch);
        echo "  -> Đã kích hoạt luồng bình luận WEB-AJAX cho Account ID: $aid\n";
    } else {
         echo "  -> THẤT BẠI: Hàm exec() bị khóa. Vui lòng mở khóa trên AaPanel!\n";
    }
}

echo "Đã Dispatch Comment hoàn tất.\n";
