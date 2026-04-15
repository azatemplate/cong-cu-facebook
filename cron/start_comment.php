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

$MAX_COMMENT_WORKERS = 15;
try {
    $res_limit = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_comment_workers'")->fetchColumn();
    if ($res_limit) $MAX_COMMENT_WORKERS = (int)$res_limit;
} catch (Exception $e) {}
$active_comments = count(glob(sys_get_temp_dir() . "/facebook_comment_worker_account_*.lock"));

echo "  [THROTTLE] Hiện đang có $active_comments luồng bình luận.\n";

$available_slots = $MAX_COMMENT_WORKERS - $active_comments;
if ($available_slots <= 0) {
    echo "Hệ thống đang đạt giới hạn MAX_COMMENT_WORKERS ($MAX_COMMENT_WORKERS). Chờ lượt sau...\n";
    exit;
}

$accounts = array_slice($accounts, 0, $available_slots);

echo "Có " . count($accounts) . " user đang có bình luận cần đăng. Khởi chạy " . count($accounts) . " luồng độc lập...\n";

$is_web = isset($_SERVER['HTTP_HOST']);
$exec_enabled = function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false;

foreach ($accounts as $aid) {
    if (!$aid) continue; 
    
    if ($exec_enabled) {
        $php_bin = 'php';
        if (defined('PHP_BINARY') && PHP_BINARY && strpos(PHP_BINARY, 'php-fpm') === false && strpos(PHP_BINARY, 'php-cgi') === false) {
            $php_bin = PHP_BINARY;
        } elseif (file_exists('/www/server/php/81/bin/php')) {
            $php_bin = '/www/server/php/81/bin/php';
        } elseif (file_exists('/usr/bin/php')) {
            $php_bin = '/usr/bin/php';
        }
        
        $script_path = __DIR__ . DIRECTORY_SEPARATOR . 'comment_worker.php';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B \"\" \"$php_bin\" \"$script_path\" $aid", "r"));
        } else {
            exec("\"$php_bin\" \"$script_path\" $aid > /dev/null 2>&1 &");
        }
        echo "  -> Đã kích hoạt luồng bình luận CLI ($php_bin) cho Account ID: $aid\n";
    } elseif ($is_web) {
        // Fallback Web-Forking (cURL Async Timeout 1ms) qua Wrapper gốc
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        $root_web_path = rtrim(str_replace('\\', '/', str_replace($doc_root, '', dirname(__DIR__))), '/');
        
        $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $root_web_path . "/run_worker.php?type=comment&page_id=" . ((int)$aid);
        
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
