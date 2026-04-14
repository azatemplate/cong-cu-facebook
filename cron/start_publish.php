<?php
// cron/start_publish.php
// Dispatcher: Quet xem co bao nhieu User co bai can dang, sau do kich hoat bay nhieu luong rieng biet.

require_once __DIR__ . '/../includes/db.php';

// Include Scraper Cron Logic (Chạy tự động cùng luồng Dispatcher Publlish)
echo "--- KHOI DONG SCRAPER WORKER ---\n";
@include_once __DIR__ . '/start_scraper.php';
echo "--------------------------------\n\n";

// --- ĐẢM BẢO BÁO CÁO HÀNG NGÀY CHẠY ĐÚNG ---
try {
    $today = date('Y-m-d');
    $stmt_rep = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_daily_report_date'");
    $last_report = $stmt_rep ? $stmt_rep->fetchColumn() : '';
    $current_h = (int)date('H');
    $current_m = (int)date('i');
    
    // Nếu chưa chạy hôm nay và bây giờ >= 07:30
    if ($last_report !== $today && ($current_h > 7 || ($current_h === 7 && $current_m >= 30))) {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_daily_report_date', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
            ->execute([$today, $today]);
        require_once __DIR__ . '/../includes/telegram.php';
        send_telegram_daily_report($pdo);
        
        // Chạy kèm dọn dẹp hệ thống 1 lần/ngày
        require_once __DIR__ . '/cleanup.php';
    }
} catch (Exception $e) {}

// Auto-migrate newly required columns in case the user missed accessing settings.php
try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN post_delay_seconds INT DEFAULT 15");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN retry_interval_minutes INT DEFAULT 1");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN max_retries INT DEFAULT 3");
} catch (Exception $e) {}

// Auto-migrate updated_at for scheduled_posts (stuck detection)
try {
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
} catch (Exception $e) {}

try {
    $stuck_count = $pdo->exec("UPDATE scheduled_posts SET status='pending' WHERE status='processing' AND updated_at <= DATE_SUB(NOW(), INTERVAL 20 MINUTE)");
    if ($stuck_count > 0) {
        echo "  [RESET] Da reset $stuck_count bai bi stuck 'processing' => 'pending'.\n";
    }
} catch (Exception $e) {
    echo "Loi reset stuck posts: " . $e->getMessage() . "\n";
}

// Cấu hình giới hạn luồng cho máy chủ (Throttling)
$MAX_WORKERS = 30;

// Đếm số luồng đang chạy (processing)
$active_workers = (int)$pdo->query("SELECT COUNT(DISTINCT page_id) FROM scheduled_posts WHERE status = 'processing'")->fetchColumn();

echo "  [THROTTLE] Hien dang co $active_workers luong dang xu ly.\n";

$available_slots = $MAX_WORKERS - $active_workers;
if ($available_slots <= 0) {
    echo "He thong dang dat gioi han MAX_WORKERS ($MAX_WORKERS). Cho luot cron ke tiep...\n";
    exit;
}

// Tìm các page có bài cần đăng: pending HOẶC failed còn retry (retry_count < max từ accounts)
$sql = "
    SELECT DISTINCT sp.page_id, sp.account_id 
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.scheduled_time <= NOW()
      AND sp.page_id IS NOT NULL
      AND (
        sp.status = 'pending'
        OR (sp.status = 'failed' AND (sp.retry_count IS NULL OR sp.retry_count < COALESCE(sa.max_retries, 3)))
      )
";
$stmt = $pdo->prepare($sql);
if (!$stmt) {
    echo "Loi prepare SQL"; exit;
}
if (!$stmt->execute()) {
    echo "Loi execute SQL"; exit;
}

$raw_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($raw_pages)) {
    echo "Khong co Fanpage nao can dang tai.\n";
    exit;
}

// Nhóm page theo từng định danh User để phân phối Round-Robin
$pages_by_account = [];
foreach ($raw_pages as $row) {
    // Lưu ý: Có trường hợp hiếm hoi account_id null nếu dữ liệu rác, nên fallback
    $acc_id = $row['account_id'] ?: 'unknown';
    $pages_by_account[$acc_id][] = $row['page_id'];
}

// Thuật toán Round-Robin lấy công bằng Page cho tất cả các User đang chờ
$selected_pages = [];
$keep_going = true;

while ($keep_going && count($selected_pages) < $available_slots) {
    $keep_going = false;
    foreach ($pages_by_account as $acc_id => &$account_pages) {
        if (!empty($account_pages)) {
            $selected_pages[] = array_shift($account_pages);
            $keep_going = true;
            if (count($selected_pages) >= $available_slots) {
                break 2;
            }
        }
    }
}

$pages = $selected_pages; // Gán lại mảng cho vòng lặp exec bên dưới

echo "Co " . count($raw_pages) . " Fanpage tren he thong dang cho. Da xuat ra " . count($pages) . " luong cong bang (Round-Robin)...\n";

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
