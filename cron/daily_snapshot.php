<?php
// cron/daily_snapshot.php
// Tự động cập nhật snapshot (Reach, Views, Followers) hàng ngày cho biểu đồ
// Gọi bởi start_publish.php khi gửi báo cáo hàng ngày

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME']) && php_sapi_name() !== 'cli' && !isset($_GET['force'])) {
    die("CLI only");
}

set_time_limit(0);
ignore_user_abort(true);
while (ob_get_level() > 0) ob_end_clean();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/redis_queue.php';

$rq_snap = RedisQueue::getInstance();
if (!$rq_snap->acquireLock('lock:cron:daily_snapshot', 600)) {
    echo "Tiến trình daily_snapshot.php đang chạy (Redis Lock). Bỏ qua.\n";
    return;
}
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

// Lấy tất cả account_id hiện có
ensure_pdo_alive($pdo);
$stmt_users = $pdo->query("SELECT DISTINCT account_id FROM users");
$accounts = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// Đếm tổng số trang để hiển thị thông báo
$stmt_count = $pdo->query("SELECT COUNT(DISTINCT page_id) FROM pages");
$total_pages_count = $stmt_count->fetchColumn() ?: 0;

if (php_sapi_name() !== 'cli') {
    // Trả kết quả ngay lập tức cho trình duyệt và đóng kết nối để tránh Timeout
    ob_start();
    echo "<h3>🚀 Bắt đầu quét Snapshot toàn hệ thống (Chạy ngầm)</h3>";
    echo "<p>Tiến trình đang chạy ngầm trên máy chủ để tránh Timeout...</p>";
    echo "<ul>";
    echo "<li>Tổng số tài khoản cần quét: <strong>" . count($accounts) . "</strong></li>";
    echo "<li>Tổng số Fanpage độc nhất: <strong>" . $total_pages_count . "</strong></li>";
    echo "</ul>";
    echo "<p>Vui lòng chờ khoảng 1-2 phút để tiến trình chạy xong, sau đó tải lại Dashboard để xem số liệu mới nhất.</p>";
    
    $size = ob_get_length();
    header('Connection: close');
    header('Content-Encoding: none');
    header('Content-Length: ' . $size);
    ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

function snapshot_log($msg) {
    $log_file = __DIR__ . '/../uploads/daily_snapshot.log';
    $time = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$time] $msg\n", FILE_APPEND | LOCK_EX);
}

@file_put_contents(__DIR__ . '/../uploads/daily_snapshot.log', "[" . date('Y-m-d H:i:s') . "] --- BẮT ĐẦU QUÉT SNAPSHOT TOÀN HỆ THỐNG ---\n");

$today_date = date('Y-m-d');
$period = 'days_28';
$start_date = date('Y-m-d', strtotime('-28 days'));
$end_date = $today_date;

// Cấu hình mã lỗi FB để bỏ qua
$CHECKPOINT_ERROR_CODES = [190, 368, 2500, 467, 10902];
$CHECKPOINT_SUBCODES   = [459, 460, 461, 462, 463, 464, 492, 500];

// Hàm cập nhật Admin Snapshot — gọi FB API trực tiếp cho ALL unique pages
function update_admin_snapshot_safe(&$pdo, $today_date, $period, $start_date, $end_date) {
    try {
        ensure_pdo_alive($pdo);
        // Lấy tất cả page từ DB, deduplicate theo page_id
        $stmt_all = $pdo->query("SELECT page_id, access_token, followers_count FROM pages");
        $all_rows = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

        $unique = [];
        $global_followers = 0;
        foreach ($all_rows as $row) {
            $pid = $row['page_id'];
            if (!isset($unique[$pid])) {
                $unique[$pid] = $row;
                $global_followers += intval($row['followers_count']);
            }
        }
        $global_pages = count($unique);
        echo "[" . date('H:i:s') . "] ⚡ [STEP 1] Bắt đầu quét ADMIN Snapshot cho {$global_pages} Fanpage độc nhất...\n";
        @flush(); @ob_flush();

        // Decrypt tokens, chuẩn bị cho multi-curl
        $fetch_pages = [];
        foreach ($unique as $row) {
            $token = decryptData($row['access_token']);
            if (!empty($token)) {
                $fetch_pages[] = ['page_id' => $row['page_id'], 'access_token' => $token];
            }
        }

        // Gọi FB API lấy Insights
        $global_reach = 0;
        $global_views = 0;
        $page_metrics_map = []; // pid => ['reach' => r, 'views' => v]

        if (!empty($fetch_pages)) {
            echo "[" . date('H:i:s') . "] 🌐 Đang gửi Multi-cURL API Facebook lấy chỉ số Insights (Reach, Views)...\n";
            @flush(); @ob_flush();
            $api_responses = get_fb_page_insights_multi($fetch_pages, $period, $start_date, $end_date);
            foreach ($api_responses as $pid => $api_response) {
                $p_reach = 0;
                $p_views = 0;
                if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
                    foreach ($api_response['data']['data'] as $metric) {
                        if (!in_array($metric['name'], ['page_media_view', 'page_total_media_view_unique'])) continue;
                        if (!isset($metric['values']) || !is_array($metric['values']) || empty($metric['values'])) continue;
                        $latest = end($metric['values']);
                        $val = isset($latest['value']) ? intval($latest['value']) : 0;
                        if ($metric['name'] === 'page_media_view') {
                            $p_views += $val;
                            $global_views += $val;
                        } else {
                            $p_reach += $val;
                            $global_reach += $val;
                        }
                    }
                }
                $page_metrics_map[$pid] = ['reach' => $p_reach, 'views' => $p_views];
            }
        }

        // ── Followers Sync: multi-curl song song ────
        echo "[" . date('H:i:s') . "] 👥 Đang đồng bộ số dư Followers từ Facebook API...\n";
        @flush(); @ob_flush();
        $follower_updates = [];
        $fresh_global_followers = 0;
        $chunks = array_chunk($fetch_pages, 50);
        $total_f_chunks = count($chunks);

        foreach ($chunks as $c_idx => $chunk) {
            $multi   = curl_multi_init();
            $handles = [];

            foreach ($chunk as $fp) {
                if (empty($fp['access_token'])) continue;
                $url = FB_API_BASE . $fp['page_id'] . '?fields=followers_count&access_token=' . urlencode($fp['access_token']);
                $ch  = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                fb_curl_setssl($ch);
                curl_multi_add_handle($multi, $ch);
                $old_fc = isset($unique[$fp['page_id']]) ? intval($unique[$fp['page_id']]['followers_count']) : 0;
                $handles[$fp['page_id']] = ['ch' => $ch, 'old' => $old_fc];
            }

            $active = null;
            do {
                $mrc = curl_multi_exec($multi, $active);
                if ($active) {
                    if (curl_multi_select($multi, 0.2) === -1) {
                        usleep(5000);
                    }
                }
            } while ($active && $mrc == CURLM_OK);

            foreach ($handles as $pid => $info) {
                $response  = curl_multi_getcontent($info['ch']);
                $http_code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($multi, $info['ch']);

                if ($http_code === 200 && $response) {
                    $data = json_decode($response, true);
                    if (isset($data['followers_count'])) {
                        $new_count = (int) $data['followers_count'];
                        $old_count = $info['old'];
                        $diff = ($old_count > 0) ? ($new_count - $old_count) : null;
                        $follower_updates[] = [$new_count, $diff, $pid];
                        $fresh_global_followers += $new_count;
                    } else {
                        $fresh_global_followers += $info['old'];
                    }
                } else {
                    $fresh_global_followers += $info['old'];
                }
            }
            curl_multi_close($multi);

            if (php_sapi_name() === 'cli') {
                $b_num = $c_idx + 1;
                echo "   → Followers Batch {$b_num}/{$total_f_chunks} (" . count($chunk) . " fanpages) hoàn tất.\n";
                @flush(); @ob_flush();
            }
            usleep(20000);
        }

        // Ghi followers mới vào DB
        if (!empty($follower_updates)) {
            ensure_pdo_alive($pdo);
            $upd_stmt = $pdo->prepare("UPDATE pages SET followers_count = ?, followers_diff = ? WHERE page_id = ?");
            foreach ($follower_updates as $row) {
                $upd_stmt->execute($row);
            }
            echo "Synced followers for " . count($follower_updates) . " pages (admin)\n";
            @flush(); @ob_flush();
        }

        if ($fresh_global_followers > 0) {
            $global_followers = $fresh_global_followers;
        }

        ensure_pdo_alive($pdo);
        $global_accounts = intval($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0);
        
        $reels_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE post_type = 'Reel' AND DATE(scheduled_time) = ?");
        $reels_stmt->execute([$today_date]);
        $global_reels = intval($reels_stmt->fetchColumn() ?: 0);
        
        $posts_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE status = 'published' AND DATE(scheduled_time) = ?");
        $posts_stmt->execute([$today_date]);
        $global_posts = intval($posts_stmt->fetchColumn() ?: 0);

        ensure_pdo_alive($pdo);
        $pdo->prepare("
            INSERT INTO dashboard_snapshots (account_id, snapshot_date, total_followers, total_reach, total_views, total_pages, total_accounts, total_reels, total_posts)
            VALUES ('0', ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_followers = VALUES(total_followers),
                total_reach = VALUES(total_reach),
                total_views = VALUES(total_views),
                total_pages = VALUES(total_pages),
                total_accounts = VALUES(total_accounts),
                total_reels = VALUES(total_reels),
                total_posts = VALUES(total_posts)
        ")->execute([$today_date, $global_followers, $global_reach, $global_views, $global_pages, $global_accounts, $global_reels, $global_posts]);

        echo "Updated ADMIN snapshot: Pages {$global_pages}, Followers " . number_format($global_followers) . ", Reach " . number_format($global_reach) . ", Views " . number_format($global_views) . ", Accounts {$global_accounts}, Reels {$global_reels}, Posts {$global_posts}\n";
        @flush(); @ob_flush();
        snapshot_log("Updated ADMIN snapshot: Pages {$global_pages}, Followers " . number_format($global_followers) . ", Reach " . number_format($global_reach) . ", Views " . number_format($global_views) . ", Accounts {$global_accounts}, Reels {$global_reels}, Posts {$global_posts}");

        return [$global_reach, $global_views, $page_metrics_map];
    } catch (Exception $e) {
        echo "Lỗi update ADMIN snapshot: " . $e->getMessage() . "\n";
        snapshot_log("Lỗi update ADMIN snapshot: " . $e->getMessage());
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 1: Global (admin) — Sync followers + insights cho TẤT CẢ pages trước
// ═══════════════════════════════════════════════════════════════════════════════
$admin_res = update_admin_snapshot_safe($pdo, $today_date, $period, $start_date, $end_date);
$page_metrics_map = (is_array($admin_res) && isset($admin_res[2])) ? $admin_res[2] : [];

if (!$admin_res) {
    echo "Lỗi update ADMIN snapshot.\n";
}
@flush(); @ob_flush();

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 2: Per-account snapshots — đọc followers_count & metrics đã fresh từ STEP 1
// ═══════════════════════════════════════════════════════════════════════════════
echo "[" . date('H:i:s') . "] ⚡ [STEP 2] Đang cập nhật Snapshot chi tiết cho " . count($accounts) . " tài khoản người dùng...\n";
@flush(); @ob_flush();

foreach ($accounts as $acc) {
    $account_id = $acc['account_id'];
    if (!$account_id) continue;
    
    ensure_pdo_alive($pdo);
    // Fetch pages cho account_id (followers_count đã được update ở STEP 1)
    $stmt_pages = $pdo->prepare("
        SELECT p.page_id, p.access_token, p.followers_count, p.id
        FROM pages p JOIN users u ON p.user_id = u.id 
        WHERE u.account_id = :aid
        UNION
        SELECT p.page_id, p.access_token, p.followers_count, p.id
        FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id 
        WHERE ps.shared_with_account_id = :aid2
    ");
    $stmt_pages->execute(['aid' => $account_id, 'aid2' => $account_id]);
    $all_user_pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($all_user_pages)) continue;
    
    // Tính tổng page và follower — deduplicate theo page_id
    $seen_page_ids = [];
    $total_pages = 0;
    $total_followers = 0;
    $display_total_views = 0;
    $display_total_reach = 0;

    foreach ($all_user_pages as $p_row) {
        $pid = $p_row['page_id'];
        if (!isset($seen_page_ids[$pid])) {
            $seen_page_ids[$pid] = true;
            $total_pages++;
            $total_followers += intval($p_row['followers_count']);

            if (isset($page_metrics_map[$pid])) {
                $display_total_reach += $page_metrics_map[$pid]['reach'];
                $display_total_views += $page_metrics_map[$pid]['views'];
            }
        }
    }

    // Insert dashboard_snapshots cho account_id
    try {
        ensure_pdo_alive($pdo);
        $accounts_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE account_id = ?");
        $accounts_stmt->execute([$account_id]);
        $user_accounts = intval($accounts_stmt->fetchColumn() ?: 0);
        
        $reels_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE account_id = ? AND post_type = 'Reel' AND DATE(scheduled_time) = ?");
        $reels_stmt->execute([$account_id, $today_date]);
        $user_reels = intval($reels_stmt->fetchColumn() ?: 0);
        
        $posts_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE account_id = ? AND status = 'published' AND DATE(scheduled_time) = ?");
        $posts_stmt->execute([$account_id, $today_date]);
        $user_posts = intval($posts_stmt->fetchColumn() ?: 0);

        ensure_pdo_alive($pdo);
        $pdo->prepare("
            INSERT INTO dashboard_snapshots (account_id, snapshot_date, total_followers, total_reach, total_views, total_pages, total_accounts, total_reels, total_posts)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_followers = VALUES(total_followers),
                total_reach = VALUES(total_reach),
                total_views = VALUES(total_views),
                total_pages = VALUES(total_pages),
                total_accounts = VALUES(total_accounts),
                total_reels = VALUES(total_reels),
                total_posts = VALUES(total_posts)
        ")->execute([$account_id, $today_date, $total_followers, $display_total_reach, $display_total_views, $total_pages, $user_accounts, $user_reels, $user_posts]);
        
        echo "Updated snapshot for account {$account_id}: Followers " . number_format($total_followers) . ", Reach {$display_total_reach}, Views {$display_total_views}, Accounts {$user_accounts}, Reels {$user_reels}, Posts {$user_posts}\n";
        @flush(); @ob_flush();
        snapshot_log("Updated snapshot for account {$account_id}: Followers " . number_format($total_followers) . ", Reach {$display_total_reach}, Views {$display_total_views}, Accounts {$user_accounts}, Reels {$user_reels}, Posts {$user_posts}");
        
    } catch (Exception $e) { 
        echo "Lỗi update snapshot account {$account_id}: " . $e->getMessage() . "\n";
        @flush(); @ob_flush();
        snapshot_log("Lỗi update snapshot account {$account_id}: " . $e->getMessage());
    }
}

// Xóa cache file metrics để người dùng thấy data mới nhất
$cache_dir = __DIR__ . '/../uploads/cache';
if (is_dir($cache_dir)) {
    foreach (glob($cache_dir . "/dashboard_*_fbmetrics.json") as $f) {
        @unlink($f);
    }
}

// Cập nhật last_snapshot_time vào DB chỉ khi snapshot hoàn tất thành công
try {
    ensure_pdo_alive($pdo);
    $today_date = date('Y-m-d');
    $current_h = (int)date('H');
    $current_shift = ($current_h >= 6 && $current_h < 18) ? 'am' : 'pm';
    $snap_key = $today_date . '_' . $current_shift;
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_snapshot_time', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
        ->execute([$snap_key, $snap_key]);
} catch (Exception $e) {}

echo "--- DAILY SNAPSHOT AUTO-UPDATE DONE ---\n";
@flush(); @ob_flush();
snapshot_log("--- DAILY SNAPSHOT AUTO-UPDATE DONE ---");
