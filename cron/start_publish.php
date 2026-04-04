<?php
// cron/start_publish.php
// Dispatcher: Quet xem co bao nhieu User co bai can dang, sau do kich hoat bay nhieu luong rieng biet.

require_once __DIR__ . '/../includes/db.php';

// ── BUOC 1: Reset bai bi ket o 'processing' qua 10 phut ve 'pending' ─────────
// Giai quyet truong hop worker crash ma khong release status
try {
    $stuck_count = $pdo->exec("UPDATE scheduled_posts SET status='pending' WHERE status='processing' AND scheduled_time <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    if ($stuck_count > 0) {
        echo "  [RESET] Da reset $stuck_count bai bi stuck 'processing' => 'pending'.\n";
    }
} catch (Exception $e) {
    echo "Loi reset stuck posts: " . $e->getMessage() . "\n";
}

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
    echo "Khong co Fanpage nao can dang tai.\n";
    exit;
}

echo "Co " . count($pages) . " Fanpage dang co bai hen gio dang trung luc. Khoi chay " . count($pages) . " luong doc lap...\n";

$is_web = isset($_SERVER['HTTP_HOST']);
$exec_enabled = function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false;

foreach ($pages as $pid) {
    if (!$pid) continue; // Skip NULL
    
    if ($exec_enabled) {
        $php_bin = 'php';
        if (defined('PHP_BINARY') && PHP_BINARY && strpos(PHP_BINARY, 'php-fpm') === false && strpos(PHP_BINARY, 'php-cgi') === false) {
            $php_bin = PHP_BINARY;
        } elseif (file_exists('/www/server/php/81/bin/php')) {
            $php_bin = '/www/server/php/81/bin/php'; // Fallback manh nhat cho aaPanel
        } elseif (file_exists('/www/server/php/82/bin/php')) {
            $php_bin = '/www/server/php/82/bin/php';
        } elseif (file_exists('/usr/bin/php')) {
            $php_bin = '/usr/bin/php';
        }
        
        $script_path = __DIR__ . DIRECTORY_SEPARATOR . 'publish_worker.php';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B \"\" \"$php_bin\" \"$script_path\" \"$pid\"", "r"));
        } else {
            exec("\"$php_bin\" \"$script_path\" \"$pid\" > /dev/null 2>&1 &");
        }
        echo "  -> Da kich hoat luong CLI ($php_bin) cho Page ID: $pid\n";
    } elseif ($is_web) {
        // Fallback Web-Forking (cURL Async) qua Wrapper goc
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        // Attempt to build accurate web path to current cron directory
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        $root_web_path = rtrim(str_replace('\\', '/', str_replace($doc_root, '', dirname(__DIR__))), '/');
        
        $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $root_web_path . "/run_worker.php?type=publish&page_id=" . urlencode($pid);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500); // Tang timeout de webserver kip nhan request kich luong
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_exec($ch);
        curl_close($ch);
        echo "  -> Da kich luong WEB-AJAX cho Page ID: $pid\n";
    } else {
        echo "  -> THAT BAI: Ham exec() bi khoa.\n";
    }
}

echo "Da Dispatch hoan tat.\n";
