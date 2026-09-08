<?php
// cron/start_publish.php
// Dispatcher: Quet xem co bao nhieu User co bai can dang, sau do kich hoat bay nhieu luong rieng biet.

require_once __DIR__ . '/../includes/db.php';

// Include Scraper Cron Logic (Chạy tự động cùng luồng Dispatcher Publlish)
echo "--- KHOI DONG SCRAPER WORKER ---\n";
@include_once __DIR__ . '/start_scraper.php';
echo "--------------------------------\n\n";

// Include Auto-Request Info Cron Logic (Chạy tự động cùng luồng Dispatcher Publish)
echo "--- KHOI DONG AUTO-REQUEST INFO WORKER ---\n";
@include_once __DIR__ . '/auto_request_phone.php';
echo "------------------------------------------\n\n";

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



try {
    $stuck_count = $pdo->exec("UPDATE scheduled_posts SET status='pending', retry_count=0 WHERE status='processing' AND updated_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    if ($stuck_count > 0) {
        echo "  [RESET] Da reset $stuck_count bai bi stuck 'processing' => 'pending'.\n";
    }
} catch (Exception $e) {
    echo "Loi reset stuck posts: " . $e->getMessage() . "\n";
}

// --- DỌN TẤT CẢ FILE LOCK CŨ > 15 PHÚT TỰ ĐỘNG ---
try {
    $lock_dir = dirname(__DIR__) . '/locks';
    if (is_dir($lock_dir)) {
        foreach (glob($lock_dir . '/*.lock') as $lf) {
            if (file_exists($lf) && (time() - filemtime($lf)) > 900) {
                @unlink($lf);
            }
        }
    }
} catch (Exception $e) {}

// --- TỰ ĐỘNG KHÔI PHỤC BÀI PENDING BỊ VƯỢT MAX RETRIES VỀ RETRY_COUNT = 0 ---
try {
    $pdo->exec("UPDATE scheduled_posts SET retry_count = 0 WHERE status = 'pending' AND retry_count >= 3");
} catch (Exception $e) {}

// --- TỰ ĐỘNG QUÉT SĐT TỪ TIN NHẮN CŨ (Chạy ngầm 200 khách hàng mỗi 5 phút) ---
try {
    $stmt_scan_time = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_auto_phone_scan_time'");
    $last_auto_scan = $stmt_scan_time ? (int)$stmt_scan_time->fetchColumn() : 0;
    
    if (time() - $last_auto_scan >= 300) { // Mỗi 5 phút
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_auto_phone_scan_time', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
            ->execute([time(), time()]);
            
        // Lấy danh sách account_id hoạt động để quét
        $stmt_accs = $pdo->query("SELECT DISTINCT u.account_id FROM pages p JOIN users u ON p.user_id = u.id");
        $active_accs = $stmt_accs->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($active_accs)) {
            if (!function_exists('get_php_cli_bin')) {
                require_once __DIR__ . '/../includes/php_cli.php';
            }
            $php_bin = get_php_cli_bin();
            $script = dirname(__DIR__) . '/actions/scan_old_phones.php';
            
            foreach ($active_accs as $aid) {
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    pclose(popen("start /B \"\" \"$php_bin\" \"$script\" \"$aid\"", "r"));
                } else {
                    exec("nohup \"$php_bin\" \"$script\" \"$aid\" > /dev/null 2>&1 &");
                }
            }
            echo "  [AUTO-SCAN] Da kich hoat tu dong quet SĐT ngam cho active accounts.\n";
        }
    }
} catch (Exception $e) {
    echo "Loi tu dong quet SĐT: " . $e->getMessage() . "\n";
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

// Tìm các bài cần đăng và nhóm theo user_id (Token User) với FB, page_id với YouTube (mỗi kênh YouTube = 1 Slot), account_id với TikTok, và buffer_account_id với Buffer (mỗi Token Buffer = 1 Slot độc lập)
$sql = "
    SELECT DISTINCT sp.page_id, sp.account_id, sp.post_type, 
           COALESCE(p.user_id, u_ig.id) AS user_id, 
           bc.buffer_account_id
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    LEFT JOIN pages p ON sp.page_id = p.page_id AND sp.post_type NOT LIKE 'Instagram%'
    LEFT JOIN instagram_accounts ig ON (sp.page_id = ig.ig_user_id OR sp.page_id = ig.id) AND sp.post_type LIKE 'Instagram%'
    LEFT JOIN users u_ig ON ig.account_id = u_ig.account_id
    LEFT JOIN buffer_channels bc ON sp.page_id = bc.channel_id
    WHERE sp.scheduled_time <= NOW()
      AND sp.page_id IS NOT NULL
      AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
      AND (sp.retry_count IS NULL OR sp.retry_count < COALESCE(sa.max_retries, 3))
      AND sp.status IN ('pending', 'failed')
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

// ── Nhóm page theo user_id của FB, page_id của YouTube, account_id của TT, hoặc buffer_account_id của Buffer ──
$pages_by_user = [];
foreach ($raw_pages as $row) {
    if ($row['post_type'] === 'YouTube') {
        $yt_chan_id = !empty($row['page_id']) ? $row['page_id'] : $row['account_id'];
        $uid = 'yt_chan_' . $yt_chan_id;
    } elseif (strpos($row['post_type'], 'Buffer') !== false) {
        $buf_acc_id = !empty($row['buffer_account_id']) ? $row['buffer_account_id'] : $row['account_id'];
        $uid = 'buf_acc_' . $buf_acc_id;
    } elseif ($row['post_type'] === 'TikTok') {
        $uid = 'tt_' . $row['account_id'];
    } elseif (strpos($row['post_type'], 'Instagram') !== false) {
        $uid = 'ig_' . $row['page_id'];
    } else {
        $uid = $row['user_id'] ?: ('noid_' . $row['page_id']);
    }
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
echo "Co {$total_pages} Fanpage/Kênh tren {$total_users} Tai khoan/Token dang cho. Moi nhom = 1 Worker doc lap...\n";

$is_web = isset($_SERVER['HTTP_HOST']);
$disabled_funcs = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
$exec_enabled = function_exists('exec') && !in_array('exec', $disabled_funcs);

foreach ($selected_users as $uid) {
    $user_page_ids = $pages_by_user[$uid];
    // Truyền danh sách page_ids cho worker, cách nhau bởi dấu phẩy
    $page_ids_str = implode(',', $user_page_ids);

    // Kiểm tra và giải phóng lock cũ trước khi spawn worker
    // Nếu lock > 15 phút → worker cũ đã crash hoặc account vừa được gia hạn
    $lock_dir = dirname(__DIR__) . '/locks';
    $lock_key = md5('uid_' . $uid);
    $lock_file = $lock_dir . "/publish_user_" . $lock_key . ".lock";
    if (file_exists($lock_file)) {
        $lock_age = time() - filemtime($lock_file);
        if ($lock_age > 900) { // > 15 phút
            @unlink($lock_file);
            echo "  ⚠ Đã dọn lock cũ ($lock_age giây) cho user #$uid trước khi spawn worker.\n";
        }
    }
    
    // Kích hoạt song song luồng CLI và Web-Async cURL để chống cản trở bởi open_basedir / aaPanel
    $dispatched = false;
    if ($exec_enabled) {
        if (!function_exists('get_php_cli_bin')) {
            require_once __DIR__ . '/../includes/php_cli.php';
        }
        $php_bin = get_php_cli_bin();
        $script_path = __DIR__ . DIRECTORY_SEPARATOR . 'publish_worker.php';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @pclose(@popen("start /B \"\" \"$php_bin\" \"$script_path\" \"$page_ids_str\" \"$uid\"", "r"));
        } else {
            @exec("nohup \"$php_bin\" \"$script_path\" \"$page_ids_str\" \"$uid\" > /dev/null 2>&1 &");
        }
        $dispatched = true;
    }
    
    if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $root_web_path = rtrim(str_replace('\\', '/', str_replace($doc_root, '', dirname(__DIR__))), '/');
        
        $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $root_web_path . "/run_worker.php?type=publish&page_id=" . urlencode($page_ids_str) . "&user_id=" . urlencode($uid);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        @curl_exec($ch);
        @curl_close($ch);
        $dispatched = true;
    }

    if ($dispatched) {
        echo "  -> Da kích hoạt luong ngam cho nhom #$uid (" . count($user_page_ids) . " pages: $page_ids_str)\n";
    } else {
        echo "  -> THAT BAI: Khong the kích hoat luong cho nhom #$uid\n";
    }
}

echo "Da Dispatch hoan tat.\n";
