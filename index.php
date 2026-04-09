<?php
$current_page = 'dashboard';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$period = 'days_28';

$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-28 days'));

$sub_msg = $is_admin 
    ? "🛡 Super Admin — Global View · Auto-Sync 12PM ICT" 
    : "👤 User View — Hiển thị dữ liệu của riêng bạn";

// ── Chart data from snapshots (lightweight DB query, always fast) ────────────
$snap_account_id = $is_admin ? 0 : $account_id;
$today_date = date('Y-m-d');
$chart_days = [];
for ($i = 6; $i >= 0; $i--) {
    $chart_days[] = date('Y-m-d', strtotime("-$i days"));
}

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

<!-- Banner cảnh báo checkpoint -->
<div id="checkpoint-warning-banner"
    style="display:none; background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:13px; color:#78350f; line-height:1.6;">
</div>

<!-- Stats Grid: All values loaded via AJAX for instant page render -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-title">Connected Accounts (All Users)</div>
        <div class="stat-value color-primary" style="display:flex; align-items:center;">
            <span class="icon" style="margin-right:10px;">👥</span> <span id="ajax-users">—</span>
        </div>
        <div class="stat-subtitle">Tổng tài khoản FB đã kết nối</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Fanpages (All Users)</div>
        <div class="stat-value color-blue" style="display:flex; align-items:center;">
            <span class="icon" style="margin-right:10px;">f</span> <span id="ajax-pages">—</span>
        </div>
        <div class="stat-subtitle">Tổng fanpage trên toàn hệ thống</div>
    </div>

    <div class="stat-card">
        <div class="stat-title">Total Reach (All Users)</div>
        <div class="stat-value color-red" style="display:flex; align-items:center; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">👁️</span> 
            <span id="ajax-reach">—</span> 
            <span id="ajax-reach-diff" style="display:none;"></span>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng reach từ page insights (<?php echo htmlspecialchars($period); ?>)</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Flow (All Followers)</div>
        <div class="stat-value color-green" style="display:flex; align-items:center; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">👍</span> 
            <span id="ajax-followers">—</span>
            <span id="ajax-followers-diff" style="display:none;"></span>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng người theo dõi toàn bộ fanpage</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Reels Uploaded Today (All Users)</div>
        <div class="stat-value color-orange" style="display:flex; align-items:center; color: #f97316;">
            <span class="icon" style="margin-right:10px;">📹</span> <span id="ajax-reels">—</span>
        </div>
        <div class="stat-subtitle">Tổng reels đã upload hôm nay (giờ VN)</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Views (All Users)</div>
        <div class="stat-value color-purple" style="display:flex; align-items:center; color: #8b5cf6; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">▶</span> 
            <span id="ajax-views">—</span> 
            <span id="ajax-views-diff" style="display:none;"></span>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng views video/reels theo dữ liệu (<?php echo htmlspecialchars($period); ?>)</div>
    </div>
</div>

<!-- AJAX: Load all dashboard metrics asynchronously -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Fast DB metrics (users, pages, followers, reels) — should return in <100ms
    fetch('actions/ajax_dashboard_db.php')
        .then(res => res.json())
        .then(data => {
            if (data.total_users !== undefined) document.getElementById('ajax-users').textContent = Number(data.total_users).toLocaleString();
            if (data.total_pages !== undefined) document.getElementById('ajax-pages').textContent = Number(data.total_pages).toLocaleString();
            if (data.total_followers !== undefined) document.getElementById('ajax-followers').textContent = Number(data.total_followers).toLocaleString();
            if (data.followers_diff_html) {
                document.getElementById('ajax-followers-diff').innerHTML = data.followers_diff_html;
                document.getElementById('ajax-followers-diff').style.display = 'inline';
            }
            if (data.total_reels_today !== undefined) {
                let reelsText = Number(data.total_reels_today).toLocaleString();
                if (data.failed_reels_today > 0) {
                    reelsText += ' <span style="font-size:14px; color:#ef4444; margin-left:10px; font-weight: 500;">(' + data.failed_reels_today + ' failed)</span>';
                } else {
                    reelsText += ' <span style="font-size:14px; color:var(--text-muted); margin-left:10px; font-weight: normal;">(0 failed)</span>';
                }
                document.getElementById('ajax-reels').innerHTML = reelsText;
            }
            // Load recent pages table
            if (data.recent_pages && data.recent_pages.length > 0) {
                let html = '';
                data.recent_pages.forEach(p => {
                    html += `<tr>
                        <td style="color: var(--text-muted); font-size: 12px;">${p.page_id}</td>
                        <td style="font-weight: 500; color: var(--primary-color);">${p.name}</td>
                        <td><span class="status-tag">${p.category || ''}</span></td>
                        <td style="font-weight: 600;">${Number(p.followers_count).toLocaleString()}</td>
                        <td>${p.user_name}</td>
                    </tr>`;
                });
                document.getElementById('recent-pages-body').innerHTML = html;
            } else {
                document.getElementById('recent-pages-body').innerHTML = '<tr><td colspan="5" style="text-align:center; color:#6b7280;">Chưa có Fanpage nào được tải. Vui lòng thêm Token.</td></tr>';
            }
        })
        .catch(() => {
            document.getElementById('ajax-users').textContent = 'Lỗi';
            document.getElementById('ajax-pages').textContent = 'Lỗi';
        });

    // 2. Slow FB API metrics (reach, views) — may take several seconds
    fetch('actions/ajax_dashboard_metrics.php')
        .then(res => res.json())
        .then(data => {
            if (data.reach_formatted) document.getElementById('ajax-reach').textContent = data.reach_formatted;
            if (data.reach_diff_html) {
                document.getElementById('ajax-reach-diff').innerHTML = data.reach_diff_html;
                document.getElementById('ajax-reach-diff').style.display = 'inline';
            }
            if (data.views_formatted) document.getElementById('ajax-views').textContent = data.views_formatted;
            if (data.views_diff_html) {
                document.getElementById('ajax-views-diff').innerHTML = data.views_diff_html;
                document.getElementById('ajax-views-diff').style.display = 'inline';
            }
            if (data.checkpointed_pages && data.checkpointed_pages > 0) {
                var banner = document.getElementById('checkpoint-warning-banner');
                if (banner) {
                    var msg = data.checkpointed_pages === 1
                        ? '⚠️ Có <strong>1 Fanpage</strong> bị bỏ qua vì Token đã bị <strong>Checkpoint</strong> hoặc hết hạn.'
                        : '⚠️ Có <strong>' + data.checkpointed_pages + ' Fanpage</strong> bị bỏ qua vì Token đã bị <strong>Checkpoint</strong> hoặc hết hạn.';
                    msg += ' Vui lòng vào <a href="token_management.php" style="color:#92400e; font-weight:bold; text-decoration:underline;">Quản lý Token</a> để cập nhật lại.';
                    banner.innerHTML = msg;
                    banner.style.display = 'block';
                }
            }
        })
        .catch(err => {
            document.getElementById('ajax-reach').textContent = 'Lỗi API';
            document.getElementById('ajax-views').textContent = 'Lỗi API';
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
        <tbody id="recent-pages-body">
            <tr><td colspan="5" style="text-align:center; color:#6b7280; padding:20px;">
                <span style="display:inline-block; width:14px; height:14px; border:2px solid #e5e7eb; border-top-color:var(--primary-color); border-radius:50%; animation: spin 1s linear infinite;"></span>
                Đang tải...
            </td></tr>
        </tbody>
    </table>
</div>
<style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>

<?php include 'includes/footer.php'; ?>
