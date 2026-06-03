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

// --- ĐẢM BẢO SNAPSHOT HÀNG NGÀY CHẠY ĐÚNG 6:00 AM và 6:00 PM ---
try {
    $today = date('Y-m-d');
    $current_h = (int)date('H');
    
    $stmt_snap = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_snapshot_time'");
    $last_snap = $stmt_snap ? $stmt_snap->fetchColumn() : '';
    
    $current_shift = '';
    if ($current_h >= 6 && $current_h < 18) {
        $current_shift = 'am';
    } elseif ($current_h >= 18) {
        $current_shift = 'pm';
    }
    
    if ($current_shift !== '') {
        $snap_key = $today . '_' . $current_shift;
        if ($last_snap !== $snap_key) {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_snapshot_time', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$snap_key, $snap_key]);
            
            // Tự động lấy snapshot (Followers, Reach, Views) cho toàn bộ user và lưu vào db
            require_once __DIR__ . '/daily_snapshot.php';
        }
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
try {
    $res_limit = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_publish_workers'")->fetchColumn();
    if ($res_limit) $MAX_WORKERS = (int)$res_limit;
} catch (Exception $e) {}

// Đếm số luồng đang chạy (processing)
$active_workers = (int)$pdo->query("SELECT COUNT(DISTINCT page_id) FROM scheduled_posts WHERE status = 'processing'")->fetchColumn();

echo "  [THROTTLE] Hien dang co $active_workers luong dang xu ly.\n";

$available_slots = $MAX_WORKERS - $active_workers;
if ($available_slots <= 0) {
    echo "He thong dang dat gioi han MAX_WORKERS ($MAX_WORKERS). Cho luot cron ke tiep...\n";
    exit;
}

// Tìm các bài cần đăng và nhóm theo user_id (Token User) để đảm bảo 1 Token User chỉ chạy 1 worker
// Mỗi Token User sẽ xử lý tuần tự tất cả các page của mình với delay giữa mỗi post
$sql = "
    SELECT DISTINCT sp.page_id, sp.account_id, p.user_id
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    LEFT JOIN pages p ON sp.page_id = p.page_id
    WHERE sp.scheduled_time <= NOW()
      AND sp.page_id IS NOT NULL
      AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
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

// ── Nhóm page theo user_id (Token User) ──────────────────────────────────
// Mỗi user_id sẽ chỉ có 1 worker duy nhất, xử lý tuần tự tất cả page của user đó
$pages_by_user = [];
foreach ($raw_pages as $row) {
    $uid = $row['user_id'] ?: ('noid_' . $row['page_id']); // Fallback nếu không có user_id
    if (!isset($pages_by_user[$uid])) {
        $pages_by_user[$uid] = [];
    }
    $pages_by_user[$uid][] = $row['page_id'];
}

// Round-Robin chọn user_id để đảm bảo công bằng
$selected_users = [];
$keep_going = true;
$user_keys = array_keys($pages_by_user);

while ($keep_going && count($selected_users) < $available_slots) {
    $keep_going = false;
    foreach ($user_keys as $uid) {
        if (!in_array($uid, $selected_users)) {
            $selected_users[] = $uid;
            $keep_going = true;
            if (count($selected_users) >= $available_slots) {
                break 2;
            }
        }
    }
}

$total_pages = count($raw_pages);
$total_users = count($selected_users);
echo "Co {$total_pages} Fanpage tren {$total_users} Token User dang cho. Moi Token User = 1 Worker doc lap...\n";

$is_web = isset($_SERVER['HTTP_HOST']);
$exec_enabled = function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false;

foreach ($selected_users as $uid) {
    $user_page_ids = $pages_by_user[$uid];
    // Truyền danh sách page_ids cho worker, cách nhau bởi dấu phẩy
    $page_ids_str = implode(',', $user_page_ids);
    
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
            pclose(popen("start /B \"\" \"$php_bin\" \"$script_path\" \"$page_ids_str\" \"$uid\"", "r"));
        } else {
            exec("\"$php_bin\" \"$script_path\" \"$page_ids_str\" \"$uid\" > /dev/null 2>&1 &");
        }
        echo "  -> Da kich hoat luong CLI cho Token User #$uid (" . count($user_page_ids) . " pages: $page_ids_str)\n";
    } elseif ($is_web) {
        // Fallback Web-Forking (cURL Async) qua Wrapper goc
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        $root_web_path = rtrim(str_replace('\\', '/', str_replace($doc_root, '', dirname(__DIR__))), '/');
        
        $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $root_web_path . "/run_worker.php?type=publish&page_id=" . urlencode($page_ids_str) . "&user_id=" . urlencode($uid);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_exec($ch);
        curl_close($ch);
        echo "  -> Da kich luong WEB-AJAX cho Token User #$uid (" . count($user_page_ids) . " pages)\n";
    } else {
        echo "  -> THAT BAI: Ham exec() bi khoa.\n";
    }
}

echo "Da Dispatch hoan tat.\n";
