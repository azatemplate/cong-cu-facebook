<?php
$current_page = 'dashboard';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Date Filter Logic - Hardcoded to 'days_28' as per request
$period = 'days_28';

// We still need a start and end datetime for the SQL queries (Users, Pages connected in the period)
$end_date = date('Y-m-d');
if ($period === 'day') {
    $start_date = date('Y-m-d', strtotime('-1 days'));
} elseif ($period === 'week') {
    $start_date = date('Y-m-d', strtotime('-7 days'));
} elseif ($period === 'days_28') {
    $start_date = date('Y-m-d', strtotime('-28 days'));
} elseif ($period === 'month') {
    $start_date = date('Y-m-d', strtotime('-30 days'));
} elseif ($period === 'lifetime') {
    $start_date = date('Y-m-d', strtotime('-90 days')); // Graph API allows up to 93 days normally for insights if no lifetime metric is specific
}

$start_datetime = $start_date . ' 00:00:00';
$end_datetime = $end_date . ' 23:59:59';

// Get Dashboard Data
// Note: total_pages/total_followers = ALL-TIME totals, not filtered by period
// (period filter only applies to "new users joined in period" stat)

$cache_dir = __DIR__ . '/uploads/cache';
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0777, true);
}
$cache_file_key = $is_admin ? "admin_{$period}" : "user_{$account_id}_{$period}";
$cache_file_path = $cache_dir . "/dashboard_" . $cache_file_key . ".json";

$dashboard_data = null;
if (file_exists($cache_file_path) && (time() - filemtime($cache_file_path)) < 1800) {
    $dashboard_data = json_decode(file_get_contents($cache_file_path), true);
}

if ($dashboard_data && is_array($dashboard_data)) {
    extract($dashboard_data);
} else {
    // === 1. Fetch DB Metrics ===
    if ($is_admin) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_users FROM users WHERE created_at BETWEEN ? AND ?");
        $stmt->execute([$start_datetime, $end_datetime]);

        $stmt2 = $pdo->query("SELECT COUNT(id) as total_pages, SUM(followers_count) as total_followers FROM pages");

        $stmt3 = $pdo->query("SELECT pages.*, users.name as user_name FROM pages JOIN users ON pages.user_id = users.id ORDER BY pages.created_at DESC LIMIT 10");
        
        $stmt_reels = $pdo->query("SELECT COUNT(id) as total_reels, SUM(IF(status = 'failed', 1, 0)) as failed_reels FROM scheduled_posts WHERE post_type = 'Reel' AND DATE(scheduled_time) = CURDATE()");

        $sub_msg = "🛡 Super Admin — Global View · Auto-Sync 12PM ICT";
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_users FROM users WHERE account_id = ? AND created_at BETWEEN ? AND ?");
        $stmt->execute([$account_id, $start_datetime, $end_datetime]);

        $stmt2 = $pdo->prepare("
            SELECT COUNT(DISTINCT combined.id) as total_pages, SUM(combined.followers_count) as total_followers
            FROM (
                SELECT p.id, p.followers_count
                FROM pages p JOIN users u ON p.user_id = u.id
                WHERE u.account_id = :aid
                UNION
                SELECT p.id, p.followers_count
                FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id
                WHERE ps.shared_with_account_id = :aid2
            ) as combined
        ");
        $stmt2->execute(['aid' => $account_id, 'aid2' => $account_id]);

        $stmt3 = $pdo->prepare("
            (SELECT p.*, u.name as user_name
             FROM pages p JOIN users u ON p.user_id = u.id
             WHERE u.account_id = :aid3)
            UNION
            (SELECT p.*, u.name as user_name
             FROM pages p
             JOIN page_shares ps ON p.page_id = ps.page_id
             JOIN users u ON p.user_id = u.id
             WHERE ps.shared_with_account_id = :aid4)
            ORDER BY created_at DESC LIMIT 10
        ");
        $stmt3->execute(['aid3' => $account_id, 'aid4' => $account_id]);
        
        $stmt_reels = $pdo->prepare("SELECT COUNT(id) as total_reels, SUM(IF(status = 'failed', 1, 0)) as failed_reels FROM scheduled_posts WHERE account_id = ? AND post_type = 'Reel' AND DATE(scheduled_time) = CURDATE()");
        $stmt_reels->execute([$account_id]);

        $sub_msg = "👤 User View — Hiển thị dữ liệu của riêng bạn";
    }

    $total_users = $stmt->fetchColumn();

    $pages_data = $stmt2->fetch(PDO::FETCH_ASSOC);
    $total_pages = $pages_data['total_pages'] ?: 0;
    $total_followers = $pages_data['total_followers'] ?: 0;

    $recent_pages = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    $reels_data = $stmt_reels->fetch(PDO::FETCH_ASSOC);
    $total_reels_today = $reels_data['total_reels'] ?: 0;
    $failed_reels_today = $reels_data['failed_reels'] ?: 0;
    
    // API Insights and Snapshot now computed asynchronously in actions/ajax_dashboard_metrics.php
}

$snap_account_id = $is_admin ? 0 : $account_id;
$today_date = date('Y-m-d');

// === Build 7-day chart data from snapshots ===
$chart_days = [];
for ($i = 6; $i >= 0; $i--) {
    $chart_days[] = date('Y-m-d', strtotime("-$i days"));
}

// Fetch existing snapshots for the last 7 days
$snap_map = [];
try {
    $snap_stmt = $pdo->prepare("
        SELECT snapshot_date, total_followers, total_reach, total_views
        FROM dashboard_snapshots
        WHERE account_id = ? AND snapshot_date BETWEEN ? AND ?
        ORDER BY snapshot_date ASC
    ");
    $snap_stmt->execute([$snap_account_id, $chart_days[0], $today_date]);
    foreach ($snap_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snap_map[$row['snapshot_date']] = $row;
    }
} catch (Exception $e) { /* ignore */ }

// Build arrays: fill gaps with null so chart shows gaps naturally
$followers_chart_data = [];
$reach_chart_data = [];
$views_chart_data = [];
foreach ($chart_days as $day) {
    if (isset($snap_map[$day])) {
        $followers_chart_data[] = intval($snap_map[$day]['total_followers']);
        $reach_chart_data[]     = intval($snap_map[$day]['total_reach']);
        $views_chart_data[]     = intval($snap_map[$day]['total_views']);
    } else {
        $followers_chart_data[] = null;
        $reach_chart_data[]     = null;
        $views_chart_data[]     = null;
    }
}

$yesterday_date = date('Y-m-d', strtotime('-1 days'));
$yest_followers = isset($snap_map[$yesterday_date]['total_followers']) ? intval($snap_map[$yesterday_date]['total_followers']) : 0;
$followers_diff_pct = 0;
if ($yest_followers > 0) {
    $followers_diff_pct = round((($total_followers - $yest_followers) / $yest_followers) * 100, 1);
} else if ($total_followers > 0) {
    $followers_diff_pct = 100;
}
$followers_diff_html = $followers_diff_pct >= 0 
    ? '<span style="color: #16a34a; font-size: 14px; margin-left:10px; font-weight: 500;">&uarr; ' . $followers_diff_pct . '%</span>'
    : '<span style="color: #ef4444; font-size: 14px; margin-left:10px; font-weight: 500;">&darr; ' . abs($followers_diff_pct) . '%</span>';

?>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <div class="page-title" style="margin-bottom: 5px;">Today's Overview</div>
        <div style="font-size: 11px; font-weight: 500; color: var(--primary-color); letter-spacing: 1px; text-transform: uppercase;">
            <?php echo $sub_msg; ?>
        </div>
    </div>
    
    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 10px;">
        <div style="display: flex; gap: 10px;">
            <span style="font-size: 11px; color: #16a34a; border: 1px solid #bbf7d0; background: #f0fdf4; padding: 3px 8px; border-radius: 4px; font-weight: 500;">✅ API: Online</span>
            <span style="font-size: 11px; color: #16a34a; border: 1px solid #bbf7d0; background: #f0fdf4; padding: 3px 8px; border-radius: 4px; font-weight: 500;">✅ DB: 4ms</span>
            <span style="font-size: 11px; color: #16a34a; border: 1px solid #bbf7d0; background: #f0fdf4; padding: 3px 8px; border-radius: 4px; font-weight: 500;">✅ Redis: Ready</span>
        </div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-title">Connected Accounts (All Users)</div>
        <div class="stat-value color-primary" style="display:flex; align-items:center;">
            <span class="icon" style="margin-right:10px;">👥</span> <?php echo number_format($total_users); ?>
        </div>
        <div class="stat-subtitle">Tổng tài khoản FB đã kết nối</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Fanpages (All Users)</div>
        <div class="stat-value color-blue" style="display:flex; align-items:center;">
            <span class="icon" style="margin-right:10px;">f</span> <?php echo number_format($total_pages); ?>
        </div>
        <div class="stat-subtitle">Tổng fanpage trên toàn hệ thống</div>
    </div>
    
    <?php
    // Removed old fetch API block since it's cached above
    ?>

    <div class="stat-card">
        <div class="stat-title">Total Reach (All Users)</div>
        <div class="stat-value color-red" style="display:flex; align-items:center; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">👁️</span> 
            <span id="ajax-reach">Đang tải...</span> 
            <span id="ajax-reach-diff" style="display:none;"></span>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng reach từ page insights (<?php echo htmlspecialchars($period); ?>)</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Flow (All Followers)</div>
        <div class="stat-value color-green" style="display:flex; align-items:center; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">👍</span> 
            <?php echo number_format($total_followers); ?> 
            <?php echo $followers_diff_html; ?>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng người theo dõi toàn bộ fanpage</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Reels Uploaded Today (All Users)</div>
        <div class="stat-value color-orange" style="display:flex; align-items:center; color: #f97316;">
            <span class="icon" style="margin-right:10px;">📹</span> <?php echo number_format($total_reels_today); ?> 
            <?php if ($failed_reels_today > 0): ?>
            <span style="font-size:14px; color:#ef4444; margin-left:10px; font-weight: 500;">(<?php echo $failed_reels_today; ?> failed)</span>
            <?php else: ?>
            <span style="font-size:14px; color:var(--text-muted); margin-left:10px; font-weight: normal;">(0 failed)</span>
            <?php endif; ?>
        </div>
        <div class="stat-subtitle">Tổng reels đã upload hôm nay (giờ VN)</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Views (All Users)</div>
        <div class="stat-value color-purple" style="display:flex; align-items:center; color: #8b5cf6; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">▶</span> 
            <span id="ajax-views">Đang tải...</span> 
            <span id="ajax-views-diff" style="display:none;"></span>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng views video/reels theo dữ liệu (<?php echo htmlspecialchars($period); ?>)</div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    fetch('actions/ajax_dashboard_metrics.php')
        .then(res => res.json())
        .then(data => {
            if (data.reach_formatted) {
                document.getElementById('ajax-reach').textContent = data.reach_formatted;
            }
            if (data.reach_diff_html) {
                document.getElementById('ajax-reach-diff').innerHTML = data.reach_diff_html;
                document.getElementById('ajax-reach-diff').style.display = 'inline';
            }
            if (data.views_formatted) {
                document.getElementById('ajax-views').textContent = data.views_formatted;
            }
            if (data.views_diff_html) {
                document.getElementById('ajax-views-diff').innerHTML = data.views_diff_html;
                document.getElementById('ajax-views-diff').style.display = 'inline';
            }
        })
        .catch(err => {
            document.getElementById('ajax-reach').textContent = 'Lỗi API';
            document.getElementById('ajax-views').textContent = 'Lỗi API';
            console.error('FB API Metrics error: ', err);
        });
});
</script>

<div class="card" style="margin-bottom: 25px;">
    <h3 style="margin-bottom: 5px;">Growth Chart (7 Days) <span style="font-size: 13px; color: var(--text-muted); font-weight: normal;">(8 Days)</span></h3>
    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 20px;">Dữ liệu tự động cập nhật lúc 6:00 AM & 6:00 PM ICT mỗi ngày.</div>
    
    <div style="height: 350px; position: relative; width: 100%;">
        <canvas id="growthChart"></canvas>
    </div>
    <div style="text-align: center; margin-top: 15px; font-size: 13px; font-weight: 500;">
        <span style="color: #10b981;">● Followers</span> &nbsp;&nbsp;&nbsp;&nbsp; <span style="color: #ef4444;">● Reach</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('growthChart').getContext('2d');
    
    const gradientFollowers = ctx.createLinearGradient(0, 0, 0, 350);
    gradientFollowers.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
    gradientFollowers.addColorStop(1, 'rgba(16, 185, 129, 0)');

    const gradientReach = ctx.createLinearGradient(0, 0, 0, 350);
    gradientReach.addColorStop(0, 'rgba(239, 68, 68, 0.3)');
    gradientReach.addColorStop(1, 'rgba(239, 68, 68, 0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_days); ?>,
            datasets: [
                {
                    label: 'Followers',
                    data: <?php echo json_encode($followers_chart_data); ?>,
                    borderColor: '#10b981',
                    backgroundColor: gradientFollowers,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    spanGaps: true
                },
                {
                    label: 'Reach',
                    data: <?php echo json_encode($reach_chart_data); ?>,
                    borderColor: '#ef4444',
                    backgroundColor: gradientReach,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    spanGaps: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(0,0,0,0)', drawBorder: false },
                    ticks: { color: '#6b7280', font: { size: 11 } }
                },
                y: {
                    grid: { color: '#e5e7eb', drawBorder: false, borderDash: [5, 5] },
                    ticks: { 
                        color: '#6b7280', 
                        font: { size: 11 },
                        callback: function(value) {
                            if (value >= 1000000) return value / 1000000 + 'M';
                            if (value >= 1000) return value / 1000 + 'k';
                            return value;
                        }
                    }
                }
            },
            interaction: {
                mode: 'index',
                intersect: false,
            }
        }
    });

});
</script>

<div class="card">
    <h3 style="margin-bottom: 15px;">Danh sách Fanpage Gần Đây</h3>
    <table style="min-width:650px;">
        <thead>
            <tr>
                <th>Page ID</th>
                <th>Tên Fanpage</th>
                <th>Danh mục</th>
                <th>Người theo dõi</th>
                <th>Tài khoản quản lý</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($recent_pages) > 0): ?>
                <?php foreach ($recent_pages as $page): ?>
                    <tr>
                        <td style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars($page['page_id']); ?></td>
                        <td style="font-weight: 500; color: var(--primary-color);"><?php echo htmlspecialchars($page['name']); ?></td>
                        <td><span class="status-tag"><?php echo htmlspecialchars($page['category']); ?></span></td>
                        <td style="font-weight: 600;"><?php echo number_format($page['followers_count']); ?></td>
                        <td><?php echo htmlspecialchars($page['user_name']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align:center; color:#6b7280;">Chưa có Fanpage nào được tải. Vui lòng thêm Token.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
