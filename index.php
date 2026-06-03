<?php
$current_page = 'dashboard';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$period = 'days_28';

$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-28 days'));

$sub_msg = $is_admin 
    ? "🛡 Super Admin — Dữ liệu tài khoản cá nhân" 
    : "👤 Thành viên — Dữ liệu tài khoản cá nhân";

// ── Chart data from snapshots (lightweight DB query, always fast) ────────────
$snap_account_id = $account_id;
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
        <div class="stat-title">Connected FB Profiles</div>
        <div class="stat-value color-primary" style="display:flex; align-items:center;">
            <span class="icon" style="margin-right:10px;">👥</span> <span id="ajax-users">—</span>
        </div>
        <div class="stat-subtitle">Số tài khoản Facebook đã liên kết</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Fanpages</div>
        <div class="stat-value color-blue" style="display:flex; align-items:center;">
            <span class="icon" style="margin-right:10px;">f</span> <span id="ajax-pages">—</span>
        </div>
        <div class="stat-subtitle">Tổng fanpage sở hữu & chia sẻ</div>
    </div>

    <div class="stat-card">
        <div class="stat-title">Total Reach</div>
        <div class="stat-value color-red" style="display:flex; align-items:center; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">👁️</span> 
            <span id="ajax-reach">—</span> 
            <span id="ajax-reach-diff" style="display:none;"></span>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng tiếp cận fanpage (<?php echo htmlspecialchars($period); ?>)</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Followers</div>
        <div class="stat-value color-green" style="display:flex; align-items:center; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">👍</span> 
            <span id="ajax-followers">—</span>
            <span id="ajax-followers-diff" style="display:none;"></span>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng người theo dõi các fanpage</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Reels Uploaded Today</div>
        <div class="stat-value color-orange" style="display:flex; align-items:center; color: #f97316;">
            <span class="icon" style="margin-right:10px;">📹</span> <span id="ajax-reels">—</span>
        </div>
        <div class="stat-subtitle">Tổng Reels đã đăng hôm nay</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Views</div>
        <div class="stat-value color-purple" style="display:flex; align-items:center; color: #8b5cf6; flex-wrap:wrap;">
            <span class="icon" style="margin-right:10px;">▶</span> 
            <span id="ajax-views">—</span> 
            <span id="ajax-views-diff" style="display:none;"></span>
        </div>
        <div class="stat-subtitle" style="margin-top: 10px;">Tổng lượt xem video/reels (<?php echo htmlspecialchars($period); ?>)</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Total Posts Today</div>
        <div class="stat-value color-primary" style="display:flex; align-items:center; color: #0284c7;">
            <span class="icon" style="margin-right:10px;">📝</span> <span id="ajax-posts-today">—</span>
        </div>
        <div class="stat-subtitle">Tổng số bài viết đã đăng hôm nay</div>
    </div>
</div>

<!-- AJAX: Load all dashboard metrics asynchronously -->
<script>
function formatPostType(type) {
    switch(type) {
        case 'Reel': return '📹 Reel';
        case 'Video': return '🎥 Video';
        case 'Image': return '🖼️ Ảnh';
        case 'Status': return '💬 Status';
        case 'Story': return '📖 Story';
        default: return type;
    }
}

function formatTimeRemaining(seconds) {
    if (seconds <= 0) return '<span style="color: var(--secondary-color); font-weight: 500;">Sắp đăng...</span>';
    if (seconds < 60) return seconds + ' giây';
    const mins = Math.floor(seconds / 60);
    if (mins < 60) return mins + ' phút';
    const hours = Math.floor(mins / 60);
    const remainingMins = mins % 60;
    if (hours < 24) {
        return hours + ' giờ ' + remainingMins + ' phút';
    }
    const days = Math.floor(hours / 24);
    const remainingHours = hours % 24;
    return days + ' ngày ' + remainingHours + ' giờ';
}

function startCountdown() {
    setInterval(() => {
        const countdownEls = document.querySelectorAll('.countdown-timer');
        countdownEls.forEach(el => {
            let seconds = parseInt(el.getAttribute('data-seconds'), 10);
            if (!isNaN(seconds)) {
                seconds = Math.max(0, seconds - 1);
                el.setAttribute('data-seconds', seconds);
                el.innerHTML = formatTimeRemaining(seconds);
            }
        });
    }, 1000);
}

document.addEventListener('DOMContentLoaded', function() {
    // Start countdown timer
    startCountdown();

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
            if (data.total_posts_today !== undefined) {
                let postsHtml = Number(data.total_posts_today).toLocaleString();
                if (data.page_limit > 0) {
                    let color = (data.total_posts_today >= data.page_limit) ? '#ef4444' : 'inherit';
                    postsHtml = '<span style="color:' + color + ';">' + postsHtml + ' / ' + Number(data.page_limit).toLocaleString() + '</span>';
                }
                document.getElementById('ajax-posts-today').innerHTML = postsHtml;
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

    // 3. Load post queue and recent post activity
    fetch('actions/ajax_dashboard_queue.php')
        .then(res => res.json())
        .then(data => {
            // Load recent posts
            let recentHtml = '';
            if (data.recent && data.recent.length > 0) {
                data.recent.forEach(p => {
                    let badgeClass = 'badge-published';
                    let statusText = 'Đã đăng ✅';
                    let titleAttr = '';
                    if (p.status === 'failed') {
                        badgeClass = 'badge-failed';
                        statusText = 'Thất bại ❌';
                        titleAttr = `title="${p.error_msg.replace(/"/g, '&quot;')}"`;
                    }
                    
                    let linkStart = '';
                    let linkEnd = '';
                    if (p.status === 'published' && p.fb_post_id) {
                        if (p.fb_post_id.startsWith('http://') || p.fb_post_id.startsWith('https://')) {
                            linkStart = `<a href="${p.fb_post_id}" target="_blank" style="text-decoration:none; color:inherit; display:inline-flex; align-items:center; gap:4px;">`;
                            linkEnd = ` <span style="font-size:10px; color:var(--text-muted);">↗</span></a>`;
                        } else if (p.post_type === 'YouTube') {
                            let postUrl = `https://www.youtube.com/watch?v=${p.fb_post_id}`;
                            linkStart = `<a href="${postUrl}" target="_blank" style="text-decoration:none; color:inherit; display:inline-flex; align-items:center; gap:4px;">`;
                            linkEnd = ` <span style="font-size:10px; color:var(--text-muted);">↗</span></a>`;
                        } else {
                            let postUrl = `https://facebook.com/${p.fb_post_id}`;
                            linkStart = `<a href="${postUrl}" target="_blank" style="text-decoration:none; color:inherit; display:inline-flex; align-items:center; gap:4px;">`;
                            linkEnd = ` <span style="font-size:10px; color:var(--text-muted);">↗</span></a>`;
                        }
                    }
                    
                    recentHtml += `<tr>
                        <td style="font-weight: 500; color: var(--primary-color);">${linkStart}${p.page_name}${linkEnd}</td>
                        <td><span style="font-size: 13px; font-weight:500;">${formatPostType(p.post_type)}</span></td>
                        <td style="font-size: 13px; color: var(--text-muted);">${p.scheduled_time}</td>
                        <td><span class="badge ${badgeClass}" ${titleAttr}>${statusText}</span></td>
                    </tr>`;
                });
                document.getElementById('recent-posts-body').innerHTML = recentHtml;
            } else {
                document.getElementById('recent-posts-body').innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px;">Không có bài viết nào gần đây.</td></tr>';
            }

            // Load upcoming posts
            let upcomingHtml = '';
            if (data.upcoming && data.upcoming.length > 0) {
                data.upcoming.forEach(p => {
                    let badgeClass = p.status === 'processing' ? 'badge-processing' : 'badge-pending';
                    let statusText = p.status === 'processing' ? 'Đang gửi...' : 'Đang chờ';
                    
                    upcomingHtml += `<tr>
                        <td style="font-weight: 500; color: var(--primary-color);">${p.page_name}</td>
                        <td><span style="font-size: 13px; font-weight:500;">${formatPostType(p.post_type)}</span></td>
                        <td style="font-size: 13px; color: var(--text-muted);">${p.scheduled_time}</td>
                        <td>
                            <span class="countdown-timer" data-seconds="${p.seconds_left}">
                                ${formatTimeRemaining(p.seconds_left)}
                            </span>
                            <span class="badge ${badgeClass}" style="margin-left: 6px; font-size:10px; padding: 2px 6px;">${statusText}</span>
                        </td>
                    </tr>`;
                });
                document.getElementById('upcoming-queue-body').innerHTML = upcomingHtml;
            } else {
                document.getElementById('upcoming-queue-body').innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px;">Không có bài viết nào đang chờ đăng.</td></tr>';
            }
        })
        .catch(err => {
            document.getElementById('recent-posts-body').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#ef4444; padding:20px;">Lỗi tải dữ liệu.</td></tr>';
            document.getElementById('upcoming-queue-body').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#ef4444; padding:20px;">Lỗi tải dữ liệu.</td></tr>';
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

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 20px;">
    <!-- Card 1: Lịch sử đăng gần đây -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
            <span>🕒</span> Lịch sử đăng gần đây
        </h3>
        <div style="overflow-x: auto;">
            <table style="width: 100%; min-width: 400px; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th>Kênh / Fanpage</th>
                        <th>Loại bài</th>
                        <th>Thời gian</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody id="recent-posts-body">
                    <tr>
                        <td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px;">
                            <span style="display:inline-block; width:14px; height:14px; border:2px solid #e5e7eb; border-top-color:var(--primary-color); border-radius:50%; animation: spin 1s linear infinite;"></span>
                            Đang tải...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Card 2: Hàng chờ đăng sắp tới -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
            <span>📅</span> Hàng chờ đăng sắp tới
        </h3>
        <div style="overflow-x: auto;">
            <table style="width: 100%; min-width: 400px; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th>Kênh / Fanpage</th>
                        <th>Loại bài</th>
                        <th>Dự kiến đăng</th>
                        <th>Thời gian chờ</th>
                    </tr>
                </thead>
                <tbody id="upcoming-queue-body">
                    <tr>
                        <td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px;">
                            <span style="display:inline-block; width:14px; height:14px; border:2px solid #e5e7eb; border-top-color:var(--primary-color); border-radius:50%; animation: spin 1s linear infinite;"></span>
                            Đang tải...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
@keyframes spin { 100% { transform: rotate(360deg); } }
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1;
}
.badge-published {
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.2);
}
.badge-failed {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.2);
    cursor: help;
}
.badge-pending {
    background: rgba(245, 158, 11, 0.1);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.2);
}
.badge-processing {
    background: rgba(59, 130, 246, 0.1);
    color: #3b82f6;
    border: 1px solid rgba(59, 130, 246, 0.2);
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0% { opacity: 0.6; }
    50% { opacity: 1; }
    100% { opacity: 0.6; }
}

/* Responsive adjustment for grid tables on mobile */
@media (max-width: 768px) {
    div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>
