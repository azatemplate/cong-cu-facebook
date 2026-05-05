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

echo "--- START DAILY SNAPSHOT AUTO-UPDATE ---<br>\n";
@flush(); @ob_flush();

$today_date = date('Y-m-d');
$period = 'days_28';
$start_date = date('Y-m-d', strtotime('-28 days'));
$end_date = $today_date;

// Lấy tất cả account_id hiện có
$stmt_users = $pdo->query("SELECT DISTINCT account_id FROM users");
$accounts = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// Cấu hình mã lỗi FB để bỏ qua
$CHECKPOINT_ERROR_CODES = [190, 368, 2500, 467, 10902];
$CHECKPOINT_SUBCODES   = [459, 460, 461, 462, 463, 464, 492, 500];

// Hàm cập nhật Admin Snapshot an toàn (lấy data mới nhất của từng user cộng lại)
function update_admin_snapshot_safe($pdo, $today_date) {
    try {
        $stmt2 = $pdo->query("SELECT COUNT(id) as total_pages, SUM(followers_count) as total_followers FROM pages");
        $pages_data = $stmt2->fetch(PDO::FETCH_ASSOC);
        $global_pages = $pages_data['total_pages'] ?: 0;
        $global_followers = $pages_data['total_followers'] ?: 0;

        $sql = "
            SELECT SUM(d.total_reach) as global_reach, SUM(d.total_views) as global_views
            FROM dashboard_snapshots d
            INNER JOIN (
                SELECT account_id, MAX(snapshot_date) as max_date
                FROM dashboard_snapshots
                WHERE account_id != '0'
                GROUP BY account_id
            ) latest ON d.account_id = latest.account_id AND d.snapshot_date = latest.max_date
        ";
        $stmt_admin = $pdo->query($sql);
        $admin_data = $stmt_admin->fetch(PDO::FETCH_ASSOC);
        
        $global_reach = intval($admin_data['global_reach']);
        $global_views = intval($admin_data['global_views']);
        
        $pdo->prepare("
            INSERT INTO dashboard_snapshots (account_id, snapshot_date, total_followers, total_reach, total_views, total_pages)
            VALUES ('0', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_followers = VALUES(total_followers),
                total_reach = VALUES(total_reach),
                total_views = VALUES(total_views),
                total_pages = VALUES(total_pages)
        ")->execute([$today_date, $global_followers, $global_reach, $global_views, $global_pages]);
        
        return [$global_reach, $global_views];
    } catch (Exception $e) {
        return false;
    }
}

foreach ($accounts as $acc) {
    $account_id = $acc['account_id'];
    if (!$account_id) continue;
    
    // Fetch pages cho account_id
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
    
    // Tính tổng page và follower của user này (distinct theo id page)
    $unique_pages = [];
    $total_pages = 0;
    $total_followers = 0;
    foreach ($all_user_pages as &$p_row) {
        $p_row['access_token'] = decryptData($p_row['access_token']);
        if (!isset($unique_pages[$p_row['id']])) {
            $unique_pages[$p_row['id']] = true;
            $total_pages++;
            $total_followers += intval($p_row['followers_count']);
        }
    }
    unset($p_row);

    $display_total_views = 0;
    $display_total_reach = 0;

    $api_responses = get_fb_page_insights_multi($all_user_pages, $period, $start_date, $end_date);
    foreach ($api_responses as $page_id => $api_response) {
        if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
            foreach ($api_response['data']['data'] as $metric) {
                if ($metric['name'] === 'page_media_view' || $metric['name'] === 'page_impressions_unique') {
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
        $pdo->prepare("
            INSERT INTO dashboard_snapshots (account_id, snapshot_date, total_followers, total_reach, total_views, total_pages)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_followers = VALUES(total_followers),
                total_reach = VALUES(total_reach),
                total_views = VALUES(total_views),
                total_pages = VALUES(total_pages)
        ")->execute([$account_id, $today_date, $total_followers, $display_total_reach, $display_total_views, $total_pages]);
        
        echo "Updated snapshot for account {$account_id}: Reach {$display_total_reach}, Views {$display_total_views}<br>\n";
        @flush(); @ob_flush();
        
        // Cập nhật ngay cho Admin để đề phòng script bị timeout giữa chừng
        update_admin_snapshot_safe($pdo, $today_date);
        
    } catch (Exception $e) { 
        echo "Lỗi update snapshot account {$account_id}: " . $e->getMessage() . "<br>\n";
        @flush(); @ob_flush();
    }
}

// Global (admin) - Lấy tổng Views và Reach từ các user
$admin_res = update_admin_snapshot_safe($pdo, $today_date);
if ($admin_res) {
    echo "Updated ADMIN snapshot (account 0): Reach {$admin_res[0]}, Views {$admin_res[1]}<br>\n";
} else {
    echo "Lỗi update ADMIN snapshot.<br>\n";
}
@flush(); @ob_flush();

// Xóa cache file metrics để người dùng thấy data mới nhất
$cache_dir = __DIR__ . '/../uploads/cache';
if (is_dir($cache_dir)) {
    foreach (glob($cache_dir . "/dashboard_*_fbmetrics.json") as $f) {
        @unlink($f);
    }
}

echo "--- DAILY SNAPSHOT AUTO-UPDATE DONE ---<br>\n";
@flush(); @ob_flush();
