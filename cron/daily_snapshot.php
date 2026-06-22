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
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

// Lấy tất cả account_id hiện có
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

// Hàm cập nhật Admin Snapshot — gọi FB API trực tiếp cho ALL unique pages (giống growth.php)
function update_admin_snapshot_safe($pdo, $today_date, $period, $start_date, $end_date) {
    try {
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

        // Decrypt tokens, chuẩn bị cho multi-curl
        $fetch_pages = [];
        foreach ($unique as $row) {
            $token = decryptData($row['access_token']);
            if (!empty($token)) {
                $fetch_pages[] = ['page_id' => $row['page_id'], 'access_token' => $token];
            }
        }

        // Gọi FB API giống hệt ajax_insights_all.php (growth.php)
        $global_reach = 0;
        $global_views = 0;
        if (!empty($fetch_pages)) {
            $api_responses = get_fb_page_insights_multi($fetch_pages, $period, $start_date, $end_date);
            foreach ($api_responses as $pid => $api_response) {
                if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
                    foreach ($api_response['data']['data'] as $metric) {
                        if (!in_array($metric['name'], ['page_media_view', 'page_total_media_view_unique'])) continue;
                        if (!isset($metric['values']) || !is_array($metric['values']) || empty($metric['values'])) continue;
                        $latest = end($metric['values']);
                        $val = isset($latest['value']) ? intval($latest['value']) : 0;
                        if ($metric['name'] === 'page_media_view') {
                            $global_views += $val;
                        } else {
                            $global_reach += $val;
                        }
                    }
                }
            }
        }

        // ── Followers Sync: multi-curl song song (giống ajax_insights_all.php) ────
        // Cập nhật followers_count + followers_diff trong bảng pages
        $follower_updates = [];
        $fresh_global_followers = 0;
        $chunks = array_chunk($fetch_pages, 50);

        foreach ($chunks as $chunk) {
            $multi   = curl_multi_init();
            $handles = [];

            foreach ($chunk as $fp) {
                if (empty($fp['access_token'])) continue;
                $url = FB_API_BASE . $fp['page_id'] . '?fields=followers_count&access_token=' . urlencode($fp['access_token']);
                $ch  = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                fb_curl_setssl($ch);
                curl_multi_add_handle($multi, $ch);
                $old_fc = isset($unique[$fp['page_id']]) ? intval($unique[$fp['page_id']]['followers_count']) : 0;
                $handles[$fp['page_id']] = ['ch' => $ch, 'old' => $old_fc];
            }

            $active = null;
            do {
                $mrc = curl_multi_exec($multi, $active);
                if ($active) curl_multi_select($multi, 0.5);
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
                        // API không trả followers, giữ nguyên giá trị DB
                        $fresh_global_followers += $info['old'];
                    }
                } else {
                    $fresh_global_followers += $info['old'];
                }
            }
            curl_multi_close($multi);
            usleep(100000); // 100ms delay giữa các batch
        }

        // Ghi followers mới vào DB
        if (!empty($follower_updates)) {
            $upd_stmt = $pdo->prepare("UPDATE pages SET followers_count = ?, followers_diff = ? WHERE page_id = ?");
            foreach ($follower_updates as $row) {
                $upd_stmt->execute($row);
            }
            echo "Synced followers for " . count($follower_updates) . " pages (admin)<br>\n";
            @flush(); @ob_flush();
        }

        // Dùng followers mới (fresh) cho snapshot thay vì giá trị DB cũ
        if ($fresh_global_followers > 0) {
            $global_followers = $fresh_global_followers;
        }

        $global_accounts = intval($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0);
        
        $reels_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE post_type = 'Reel' AND DATE(scheduled_time) = ?");
        $reels_stmt->execute([$today_date]);
        $global_reels = intval($reels_stmt->fetchColumn() ?: 0);
        
        $posts_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE status = 'published' AND DATE(scheduled_time) = ?");
        $posts_stmt->execute([$today_date]);
        $global_posts = intval($posts_stmt->fetchColumn() ?: 0);

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

        echo "Updated ADMIN snapshot: Pages {$global_pages}, Followers " . number_format($global_followers) . ", Reach " . number_format($global_reach) . ", Views " . number_format($global_views) . ", Accounts {$global_accounts}, Reels {$global_reels}, Posts {$global_posts}<br>\n";
        @flush(); @ob_flush();
        snapshot_log("Updated ADMIN snapshot: Pages {$global_pages}, Followers " . number_format($global_followers) . ", Reach " . number_format($global_reach) . ", Views " . number_format($global_views) . ", Accounts {$global_accounts}, Reels {$global_reels}, Posts {$global_posts}");

        return [$global_reach, $global_views];
    } catch (Exception $e) {
        echo "Lỗi update ADMIN snapshot: " . $e->getMessage() . "<br>\n";
        snapshot_log("Lỗi update ADMIN snapshot: " . $e->getMessage());
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 1: Global (admin) — Sync followers + insights cho TẤT CẢ pages trước
// Chạy trước để cập nhật followers_count trong DB, per-account loop sẽ đọc giá trị mới
// ═══════════════════════════════════════════════════════════════════════════════
$admin_res = update_admin_snapshot_safe($pdo, $today_date, $period, $start_date, $end_date);
if (!$admin_res) {
    echo "Lỗi update ADMIN snapshot.<br>\n";
}
@flush(); @ob_flush();

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 2: Per-account snapshots — đọc followers_count đã fresh từ DB
// ═══════════════════════════════════════════════════════════════════════════════
foreach ($accounts as $acc) {
    $account_id = $acc['account_id'];
    if (!$account_id) continue;
    
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
    
    // Tính tổng page và follower — deduplicate theo page_id (tránh đếm trùng page share)
    $seen_page_ids = [];
    $deduped_pages = [];
    $total_pages = 0;
    $total_followers = 0;
    foreach ($all_user_pages as $p_row) {
        $p_row['access_token'] = decryptData($p_row['access_token']);
        $pid = $p_row['page_id'];
        if (!isset($seen_page_ids[$pid])) {
            $seen_page_ids[$pid] = true;
            $deduped_pages[] = $p_row;
            $total_pages++;
            $total_followers += intval($p_row['followers_count']);
        }
    }

    $display_total_views = 0;
    $display_total_reach = 0;

    $api_responses = get_fb_page_insights_multi($deduped_pages, $period, $start_date, $end_date);
    foreach ($api_responses as $page_id => $api_response) {
        if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
            foreach ($api_response['data']['data'] as $metric) {
                if ($metric['name'] === 'page_media_view' || $metric['name'] === 'page_total_media_view_unique') {
                    if (isset($metric['values']) && is_array($metric['values']) && count($metric['values']) > 0) {
                        $values_arr = $metric['values'];
                        $latest_value = end($values_arr);
                        $val = isset($latest_value['value']) ? intval($latest_value['value']) : 0;
                        if ($metric['name'] === 'page_media_view') {
                            $display_total_views += $val;
                        } else {
                            $display_total_reach += $val;
                        }
                    }
                }
            }
        }
    }

    // Insert dashboard_snapshots cho account_id
    try {
        $accounts_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE account_id = ?");
        $accounts_stmt->execute([$account_id]);
        $user_accounts = intval($accounts_stmt->fetchColumn() ?: 0);
        
        $reels_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE account_id = ? AND post_type = 'Reel' AND DATE(scheduled_time) = ?");
        $reels_stmt->execute([$account_id, $today_date]);
        $user_reels = intval($reels_stmt->fetchColumn() ?: 0);
        
        $posts_stmt = $pdo->prepare("SELECT COUNT(id) FROM scheduled_posts WHERE account_id = ? AND status = 'published' AND DATE(scheduled_time) = ?");
        $posts_stmt->execute([$account_id, $today_date]);
        $user_posts = intval($posts_stmt->fetchColumn() ?: 0);

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
        
        echo "Updated snapshot for account {$account_id}: Followers " . number_format($total_followers) . ", Reach {$display_total_reach}, Views {$display_total_views}, Accounts {$user_accounts}, Reels {$user_reels}, Posts {$user_posts}<br>\n";
        @flush(); @ob_flush();
        snapshot_log("Updated snapshot for account {$account_id}: Followers " . number_format($total_followers) . ", Reach {$display_total_reach}, Views {$display_total_views}, Accounts {$user_accounts}, Reels {$user_reels}, Posts {$user_posts}");
        
    } catch (Exception $e) { 
        echo "Lỗi update snapshot account {$account_id}: " . $e->getMessage() . "<br>\n";
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

echo "--- DAILY SNAPSHOT AUTO-UPDATE DONE ---<br>\n";
@flush(); @ob_flush();
snapshot_log("--- DAILY SNAPSHOT AUTO-UPDATE DONE ---");
