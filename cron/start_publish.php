<?php
// cron/start_publish.php
// Dispatcher: Quét xem có bao nhiêu User có bài cần đăng, sau đó kích hoạt bấy nhiêu luồng riêng biệt.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/redis_queue.php';

$rq_pub = RedisQueue::getInstance();
if (!$rq_pub->acquireLock('lock:cron:start_publish', 15)) {
    echo "[" . date('H:i:s') . "] Dispatcher start_publish.php đang chạy ở tiến trình khác. Bỏ qua.\n";
    exit;
}

if (!function_exists('get_php_cli_bin')) {
    require_once __DIR__ . '/../includes/php_cli.php';
}
$php_bin = get_php_cli_bin();
$is_win = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

// Kích hoạt Scraper ngầm (không block start_publish)
try {
    $script_scraper = __DIR__ . '/start_scraper.php';
    if ($is_win) {
        @pclose(@popen("start /B \"\" \"$php_bin\" \"$script_scraper\"", "r"));
    } else {
        @exec("nohup \"$php_bin\" \"$script_scraper\" > /dev/null 2>&1 &");
    }
} catch (Exception $e) {}

// Kích hoạt Auto Request Phone ngầm (không block start_publish)
try {
    $script_auto_phone = __DIR__ . '/auto_request_phone.php';
    if ($is_win) {
        @pclose(@popen("start /B \"\" \"$php_bin\" \"$script_auto_phone\"", "r"));
    } else {
        @exec("nohup \"$php_bin\" \"$script_auto_phone\" > /dev/null 2>&1 &");
    }
} catch (Exception $e) {}

// --- ĐẢM BẢO BÁO CÁO HÀNG NGÀY & CLEANUP CHẠY ĐÚNG ---
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
        
        $script_clean = __DIR__ . '/cleanup.php';
        if ($is_win) {
            @pclose(@popen("start /B \"\" \"$php_bin\" \"$script_clean\"", "r"));
        } else {
            @exec("nohup \"$php_bin\" \"$script_clean\" > /dev/null 2>&1 &");
        }
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
            
            $script_snap = __DIR__ . '/daily_snapshot.php';
            if ($is_win) {
                @pclose(@popen("start /B \"\" \"$php_bin\" \"$script_snap\"", "r"));
            } else {
                @exec("nohup \"$php_bin\" \"$script_snap\" > /dev/null 2>&1 &");
            }
        }
    }
} catch (Exception $e) {}

try {
    $stuck_stmt = $pdo->query("SELECT id FROM scheduled_posts WHERE status='processing' AND updated_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    if ($stuck_stmt) {
        $stuck_ids = $stuck_stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($stuck_ids)) {
            $pdo->exec("UPDATE scheduled_posts SET status='pending', retry_count=0 WHERE id IN (" . implode(',', array_map('intval', $stuck_ids)) . ")");
            echo "  [RESET] Da reset " . count($stuck_ids) . " bai bi stuck 'processing' => 'pending'.\n";
            $rq_clean_stuck = RedisQueue::getInstance();
            foreach ($stuck_ids as $sid) {
                $rq_clean_stuck->releaseLock("lock:post:" . $sid);
            }
        }
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

// --- TỰ ĐỘNG KHÔI PHỤC BÀI PENDING BỊ VƯỢT MAX RETRIES VỀ RETRY_COUNT = 0 (Chạy 1 lần/ngày) ---
try {
    $retry_reset_flag = sys_get_temp_dir() . '/fb_retry_reset_' . date('Y-m-d') . '.done';
    if (!file_exists($retry_reset_flag)) {
        $pdo->exec("UPDATE scheduled_posts SET retry_count = 0 WHERE status = 'pending' AND retry_count >= 3");
        @file_put_contents($retry_reset_flag, date('Y-m-d H:i:s'));
    }
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

// --- TỰ ĐỘNG RESET BÀI BỊ KẸT PROCESSING VỀ PENDING (>15 PHÚT) ---
try {
    $pdo->exec("UPDATE scheduled_posts SET status = 'pending' WHERE status = 'processing' AND updated_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
} catch (Exception $e) {}

// Cấu hình giới hạn luồng cho máy chủ (Throttling an toàn cho RAM & CPU)
$MAX_WORKERS = 15;
try {
    $res_limit = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_publish_workers'")->fetchColumn();
    if ($res_limit) $MAX_WORKERS = min(25, max(5, (int)$res_limit));
} catch (Exception $e) {}

// Đếm số luồng thực tế đang chạy dựa trên file lock hoạt động
$lock_dir = dirname(__DIR__) . '/locks';
$active_locks = 0;
if (is_dir($lock_dir)) {
    $active_locks = count(glob($lock_dir . '/publish_user_*.lock'));
}
$active_workers = $active_locks;

echo "  [THROTTLE] Hien dang co $active_workers luong dang xu ly (Max: $MAX_WORKERS).\n";

$available_slots = max(0, $MAX_WORKERS - $active_workers);
if ($available_slots <= 0) {
    echo "He thong dang dat gioi han MAX_WORKERS ($MAX_WORKERS). Cho luot cron ke tiep...\n";
    $rq_pub->releaseLock('lock:cron:start_publish');
    exit;
}

// Support triggering for a single campaign specified via CLI arg or GET param
$target_camp_id = 0;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $target_camp_id = (int)$argv[1];
} elseif (isset($_GET['campaign_id']) && is_numeric($_GET['campaign_id'])) {
    $target_camp_id = (int)$_GET['campaign_id'];
}

$camp_filter = "";
$params_sp = [];
if ($target_camp_id > 0) {
    $camp_filter = " AND sp.campaign_id = ? ";
    $params_sp[] = $target_camp_id;
}

// Tìm các bài cần đăng và nhóm theo Campaign ID (1 Campaign = 1 Worker độc lập, chạy tuần tự tất cả nền tảng)
$sql = "
    SELECT DISTINCT sp.page_id, sp.account_id, sp.post_type, sp.campaign_id,
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
      $camp_filter
";
$stmt = $pdo->prepare($sql);
if (!$stmt) {
    echo "Loi prepare SQL";
    $rq_pub->releaseLock('lock:cron:start_publish');
    exit;
}
if (!$stmt->execute($params_sp)) {
    echo "Loi execute SQL";
    $rq_pub->releaseLock('lock:cron:start_publish');
    exit;
}

$raw_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($raw_pages)) {
    echo "Khong co Fanpage nao can dang tai.\n";
    $rq_pub->releaseLock('lock:cron:start_publish');
    exit;
}

// ── 1. Nhóm tất cả Bài đang chờ theo Campaign ID ──
// Mục tiêu: 1 Campaign ID = 1 Worker duy nhất. Worker đó sẽ tự vòng lặp đăng qua các nền tảng tuần tự.
$owner_channels = [];
foreach ($raw_pages as $row) {
    $owner_id = !empty($row['account_id']) ? ('acc_' . $row['account_id']) : (!empty($row['user_id']) ? ('usr_' . $row['user_id']) : 'system');
    
    // Gom theo campaign_id. Nếu không có campaign_id (bài viết cũ/lẻ), dùng lại logic phân nền tảng cũ.
    if (!empty($row['campaign_id'])) {
        $chan_key = 'camp_' . $row['campaign_id'];
    } else {
        if ($row['post_type'] === 'YouTube') {
            $chan_key = 'yt_chan_' . (!empty($row['page_id']) ? $row['page_id'] : $row['account_id']);
        } elseif (strpos($row['post_type'], 'Buffer') !== false) {
            $chan_key = 'buf_acc_' . (!empty($row['buffer_account_id']) ? $row['buffer_account_id'] : $row['account_id']);
        } elseif ($row['post_type'] === 'TikTok') {
            $chan_key = 'tt_' . $row['account_id'];
        } elseif (strpos($row['post_type'], 'Instagram') !== false) {
            $chan_key = 'ig_' . $row['page_id'];
        } else {
            $chan_key = $row['user_id'] ?: $row['page_id'];
        }
    }

    if (!isset($owner_channels[$owner_id])) {
        $owner_channels[$owner_id] = [];
    }
    if (!isset($owner_channels[$owner_id][$chan_key])) {
        $owner_channels[$owner_id][$chan_key] = [];
    }
    
    $page_val = !empty($row['campaign_id']) ? $chan_key : $row['page_id'];
    if (!in_array($page_val, $owner_channels[$owner_id][$chan_key])) {
        $owner_channels[$owner_id][$chan_key][] = $page_val;
    }
}

// ── 2. Tuyệt đối KHÔNG chia nhỏ luồng của 1 Campaign, đảm bảo 1 Campaign = 1 luồng duy nhất ──
$owner_chan_lists = [];
foreach ($owner_channels as $oid => $chans) {
    $owner_chan_lists[$oid] = [];
    foreach ($chans as $ckey => $pids) {
        $owner_chan_lists[$oid][] = [
            'chan_key' => $ckey, 
            'page_id' => implode(',', $pids), // Phục hồi lại implode cho danh sách page_ids lẻ
            'owner_id' => $oid
        ];
    }
}

// ── 3. Thuật toán chia đều Throttling công bằng cho tất cả Tài khoản (Equal Share Interleaving) ──
$dispatch_list = [];
$owner_keys = array_keys($owner_channels);
$owner_pointers = array_fill_keys($owner_keys, 0);

$has_more = true;
while ($has_more && count($dispatch_list) < $available_slots) {
    $has_more = false;
    foreach ($owner_keys as $oid) {
        $ptr = $owner_pointers[$oid];
        if (isset($owner_chan_lists[$oid][$ptr])) {
            $dispatch_list[] = $owner_chan_lists[$oid][$ptr];
            $owner_pointers[$oid]++;
            $has_more = true;
            
            if (count($dispatch_list) >= $available_slots) {
                break 2;
            }
        }
    }
}

$total_pages = count($raw_pages);
$total_active_accounts = count($owner_channels);
$total_dispatched = count($dispatch_list);
echo "Phat hien {$total_pages} Fanpage thuoc {$total_active_accounts} Tai khoan khach hang. Dang cap phat cong bang thanh {$total_dispatched} Worker...\n";

$is_web = isset($_SERVER['HTTP_HOST']);
$disabled_funcs = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
$exec_enabled = function_exists('exec') && !in_array('exec', $disabled_funcs);

foreach ($dispatch_list as $dispatch) {
    $uid = $dispatch['chan_key']; // Token key (e.g. yt_chan_123, tt_123, or user_id)
    $owner = $dispatch['owner_id']; // Tài khoản chủ sở hữu
    $page_ids_str = $dispatch['page_id']; // Chuỗi các page_id thuộc Token này

    // Kiểm tra và giải phóng lock cũ trước khi spawn worker
    // Nếu lock > 15 phút → worker cũ đã crash hoặc account vừa được gia hạn
    $lock_dir = dirname(__DIR__) . '/locks';
    $lock_key = md5('uid_' . $uid);
    $lock_file = $lock_dir . "/publish_user_" . $lock_key . ".lock";
    if (file_exists($lock_file)) {
        $lock_age = time() - filemtime($lock_file);
        if ($lock_age > 900) { // > 15 phút
            @unlink($lock_file);
            echo "  ⚠ Đã dọn lock cũ ($lock_age giây) cho token #$uid trước khi spawn worker.\n";
        }
    }
    
    // Kích hoạt luồng CLI hoặc Web-Async cURL (Ưu tiên CLI, chỉ fallback Web nếu không có exec)
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
    } elseif (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
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
        $p_cnt = !empty($page_ids_str) ? count(explode(',', $page_ids_str)) : 1;
        echo "  -> Da kích hoạt luong ngam cho nhom #$uid ($p_cnt pages: $page_ids_str)\n";
    } else {
        echo "  -> THAT BAI: Khong the kích hoat luong cho nhom #$uid\n";
    }
}

echo "Da Dispatch hoan tat.\n";
$rq_pub->releaseLock('lock:cron:start_publish');
