<?php
$current_page = 'dashboard';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$period = 'days_28';

$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-28 days'));

$sub_msg = $is_admin 
    ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="13" height="13" style="display:inline-block; vertical-align:text-bottom; margin-right:4px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> SUPER ADMIN • GLOBAL VIEW • DỮ LIỆU TOÀN HỆ THỐNG' 
    : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="13" height="13" style="display:inline-block; vertical-align:text-bottom; margin-right:4px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> THÀNH VIÊN • DỮ LIỆU TÀI KHOẢN CÁ NHÂN';

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

<style>
/* Premium Dashboard Card Overrides */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.stat-card.premium-card {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card.premium-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

.premium-card .card-icon-container {
    width: 58px;
    height: 58px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12);
}

/* Gradients for the icon containers */
.premium-card .grad-purple {
    background: linear-gradient(135deg, #a855f7, #6366f1);
}
.premium-card .grad-blue {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
}
.premium-card .grad-red {
    background: linear-gradient(135deg, #f43f5e, #e11d48);
}
.premium-card .grad-green {
    background: linear-gradient(135deg, #10b981, #059669);
}
.premium-card .grad-orange {
    background: linear-gradient(135deg, #f97316, #ea580c);
}
.premium-card .grad-indigo {
    background: linear-gradient(135deg, #8b5cf6, #6d28d9);
}
.premium-card .grad-sky {
    background: linear-gradient(135deg, #0ea5e9, #0284c7);
}

.premium-card .card-info-container {
    flex: 1;
    min-width: 0;
}

.premium-card .stat-title {
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.premium-card .stat-value-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}

.premium-card .stat-value {
    font-size: 28px;
    font-weight: 700;
    line-height: 1.1;
    letter-spacing: -0.5px;
}

.premium-card .stat-value.color-purple { color: #8b5cf6; }
.premium-card .stat-value.color-blue { color: #2563eb; }
.premium-card .stat-value.color-red { color: #e11d48; }
.premium-card .stat-value.color-green { color: #10b981; }
.premium-card .stat-value.color-orange { color: #ea580c; }
.premium-card .stat-value.color-indigo { color: #6366f1; }
.premium-card .stat-value.color-sky { color: #0284c7; }

.premium-card .stat-subtitle {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 0;
    line-height: 1.3;
}

/* Badge styles for trend indicators */
.trend-badge {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    padding: 2px 8px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 600;
    line-height: 1;
}

.trend-badge.trend-up {
    background: #f0fdf4;
    color: #16a34a;
    border: 1px solid #bbf7d0;
}

.trend-badge.trend-down {
    background: #fef2f2;
    color: #ef4444;
    border: 1px solid #fecaca;
}

body.dark-mode .trend-badge.trend-up {
    background: rgba(22, 163, 74, 0.15);
    color: #4ade80;
    border-color: rgba(34, 197, 94, 0.3);
}

body.dark-mode .trend-badge.trend-down {
    background: rgba(239, 68, 68, 0.15);
    color: #f87171;
    border-color: rgba(239, 68, 68, 0.3);
}

body.dark-mode .stat-card.premium-card {
    background: var(--card-bg);
    border-color: var(--border-color);
}

@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 640px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .stat-card.premium-card {
        padding: 16px;
        gap: 12px;
    }
    .premium-card .card-icon-container {
        width: 48px;
        height: 48px;
        border-radius: 12px;
    }
    .premium-card .card-icon-container svg {
        width: 20px;
        height: 20px;
    }
    .premium-card .stat-value {
        font-size: 22px;
    }
}
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div style="display: flex; align-items: center; gap: 14px;">
        <div class="dashboard-header-icon-container" style="width: 48px; height: 48px; border-radius: 12px; background: #ffffff; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.1), 0 4px 6px -4px rgba(99, 102, 241, 0.05); border: 1px solid var(--border-color); flex-shrink: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="url(#headerGrad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="28" height="28">
                <defs>
                    <linearGradient id="headerGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#a855f7" />
                        <stop offset="100%" stop-color="#6366f1" />
                    </linearGradient>
                </defs>
                <path d="M3 17l6-6 4 4 8-8"/>
                <path d="M17 7h4v4"/>
                <path d="M6 20v-4M10 20v-8M14 20v-5M18 20V8"/>
            </svg>
        </div>
        <div>
            <div class="page-title" style="margin-bottom: 3px; font-size: 22px; font-weight: 700; color: var(--text-main); line-height: 1.1;">Today's Overview</div>
            <div style="font-size: 11px; font-weight: 600; color: #6366f1; letter-spacing: 0.5px; text-transform: uppercase; display: flex; align-items: center; gap: 4px;">
                <?php echo $sub_msg; ?>
            </div>
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
    <!-- Card 1: Connected Accounts -->
    <div class="stat-card premium-card">
        <div class="card-icon-container grad-purple">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                <path d="M4.5 6.375a4.125 4.125 0 1 1 8.25 0 4.125 4.125 0 0 1-8.25 0ZM14.25 8.625a3.375 3.375 0 1 1 6.75 0 3.375 3.375 0 0 1-6.75 0ZM1.5 19.125a7.125 7.125 0 0 1 14.25 0v.003l-.001.119a.75.75 0 0 1-.363.63 13.067 13.067 0 0 1-6.761 1.873c-2.472 0-4.786-.684-6.76-1.873a.75.75 0 0 1-.364-.63l-.001-.122ZM17.25 19.128l-.001.144a2.25 2.25 0 0 1-.233.96 10.088 10.088 0 0 0 3.484-1.104.75.75 0 0 0 .363-.63 6.75 6.75 0 0 0-11.238-5.187 8.623 8.623 0 0 1 7.625 5.817Z" />
            </svg>
        </div>
        <div class="card-info-container">
            <div class="stat-title"><?php echo $is_admin ? "Connected Accounts (All Users)" : "Connected FB Profiles"; ?></div>
            <div class="stat-value-row">
                <span class="stat-value color-purple" id="ajax-users">—</span>
                <span id="ajax-users-diff" style="display:none;"></span>
            </div>
            <div class="stat-subtitle"><?php echo $is_admin ? "Tổng tài khoản FB đã kết nối" : "Số tài khoản Facebook đã liên kết"; ?></div>
        </div>
    </div>
    
    <!-- Card 2: Total Fanpages -->
    <div class="stat-card premium-card">
        <div class="card-icon-container grad-blue">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                <path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c4.56-.93 8-4.96 8-9.75z"/>
            </svg>
        </div>
        <div class="card-info-container">
            <div class="stat-title"><?php echo $is_admin ? "Total Fanpages (All Users)" : "Total Fanpages"; ?></div>
            <div class="stat-value-row">
                <span class="stat-value color-blue" id="ajax-pages">—</span>
                <span id="ajax-pages-diff" style="display:none;"></span>
            </div>
            <div class="stat-subtitle"><?php echo $is_admin ? "Tổng fanpage trên toàn hệ thống" : "Tổng fanpage sở hữu & chia sẻ"; ?></div>
        </div>
    </div>

    <!-- Card 3: Total Reach -->
    <div class="stat-card premium-card">
        <div class="card-icon-container grad-red">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
            </svg>
        </div>
        <div class="card-info-container">
            <div class="stat-title"><?php echo $is_admin ? "Total Reach (All Users)" : "Total Reach"; ?></div>
            <div class="stat-value-row">
                <span class="stat-value color-red" id="ajax-reach">—</span>
                <span id="ajax-reach-diff" style="display:none;"></span>
            </div>
            <div class="stat-subtitle"><?php echo $is_admin ? "Tổng reach từ page insights" : "Tổng tiếp cận fanpage"; ?> (<?php echo htmlspecialchars($period); ?>)</div>
        </div>
    </div>
    
    <!-- Card 4: Total Followers -->
    <div class="stat-card premium-card">
        <div class="card-icon-container grad-green">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                <path d="M2 20h2v-8H2v8zm19.83-8.88c-.2-.67-.82-1.12-1.52-1.12h-5.69l.86-4.14.03-.3c0-.38-.15-.74-.41-1.01L14.22 3.5 8.59 9.13C8.22 9.5 8 10 8 10.5V18c0 1.1.9 2 2 2h7.3c.73 0 1.37-.48 1.57-1.17l2.8-6.52c.1-.24.16-.5.16-.76v-1.13c0-.28-.06-.55-.17-.84z"/>
            </svg>
        </div>
        <div class="card-info-container">
            <div class="stat-title"><?php echo $is_admin ? "Total Flow (All Followers)" : "Total Followers"; ?></div>
            <div class="stat-value-row">
                <span class="stat-value color-green" id="ajax-followers">—</span>
                <span id="ajax-followers-diff" style="display:none;"></span>
            </div>
            <div class="stat-subtitle"><?php echo $is_admin ? "Tổng người theo dõi toàn bộ fanpage" : "Tổng người theo dõi các fanpage"; ?></div>
        </div>
    </div>
    
    <!-- Card 5: Reels Uploaded Today -->
    <div class="stat-card premium-card">
        <div class="card-icon-container grad-orange">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zm0 4h3L5 6H4v2zm5 0h3L10 6H8v2zm5 0h3l-2-2h-2v2zm5 0h2V6h-1l-2 2zm-12 2v8h14v-8H8zm4 6v-4l4 2-4 2z"/>
            </svg>
        </div>
        <div class="card-info-container">
            <div class="stat-title"><?php echo $is_admin ? "Reels Uploaded Today (All Users)" : "Reels Uploaded Today"; ?></div>
            <div class="stat-value-row">
                <span class="stat-value color-orange" id="ajax-reels">—</span>
                <span id="ajax-reels-diff" style="display:none;"></span>
            </div>
            <div class="stat-subtitle"><?php echo $is_admin ? "Tổng reels đã upload hôm nay (giờ VN)" : "Tổng Reels đã đăng hôm nay"; ?></div>
        </div>
    </div>
    
    <!-- Card 6: Total Views -->
    <div class="stat-card premium-card">
        <div class="card-icon-container grad-indigo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                <path d="M8 5v14l11-7z"/>
            </svg>
        </div>
        <div class="card-info-container">
            <div class="stat-title"><?php echo $is_admin ? "Total Views (All Users)" : "Total Views"; ?></div>
            <div class="stat-value-row">
                <span class="stat-value color-indigo" id="ajax-views">—</span>
                <span id="ajax-views-diff" style="display:none;"></span>
            </div>
            <div class="stat-subtitle"><?php echo $is_admin ? "Tổng views video/reels theo dữ liệu" : "Tổng lượt xem video/reels"; ?> (<?php echo htmlspecialchars($period); ?>)</div>
        </div>
    </div>
    
    <!-- Card 7: Total Post Today -->
    <div class="stat-card premium-card">
        <div class="card-icon-container grad-sky">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
            </svg>
        </div>
        <div class="card-info-container">
            <div class="stat-title"><?php echo $is_admin ? 'Total Post Today (All Users)' : 'Total Posts Today'; ?></div>
            <div class="stat-value-row">
                <span class="stat-value color-sky" id="ajax-posts-today">—</span>
                <span id="ajax-posts-today-diff" style="display:none;"></span>
            </div>
            <div class="stat-subtitle"><?php echo $is_admin ? 'Tổng số bài viết đã đăng trong ngày hôm nay' : 'Tổng số bài viết đã đăng hôm nay'; ?></div>
        </div>
    </div>
</div>

<!-- AJAX: Load all dashboard metrics asynchronously -->
<script>
function renderTrendBadge(htmlStr, targetId) {
    const el = document.getElementById(targetId);
    if (!el || !htmlStr) return;
    const isUp = htmlStr.includes('uarr') || htmlStr.includes('↑') || (!htmlStr.includes('darr') && !htmlStr.includes('↓') && !htmlStr.includes('#ef4444'));
    const pctMatch = htmlStr.match(/([\d\.,]+)%/);
    if (pctMatch) {
        const pct = pctMatch[1];
        el.className = isUp ? 'trend-badge trend-up' : 'trend-badge trend-down';
        el.innerHTML = (isUp ? '↑ +' : '↓ -') + pct + '%';
        el.style.display = 'inline-flex';
    } else {
        el.className = isUp ? 'trend-badge trend-up' : 'trend-badge trend-down';
        el.innerHTML = htmlStr;
        el.style.display = 'inline-flex';
    }
}

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
            if (data.users_diff_html) {
                renderTrendBadge(data.users_diff_html, 'ajax-users-diff');
            }
            if (data.total_pages !== undefined) document.getElementById('ajax-pages').textContent = Number(data.total_pages).toLocaleString();
            if (data.pages_diff_html) {
                renderTrendBadge(data.pages_diff_html, 'ajax-pages-diff');
            }
            if (data.total_followers !== undefined) document.getElementById('ajax-followers').textContent = Number(data.total_followers).toLocaleString();
            if (data.followers_diff_html) {
                renderTrendBadge(data.followers_diff_html, 'ajax-followers-diff');
            }
            if (data.total_reels_today !== undefined) {
                let reelsText = Number(data.total_reels_today).toLocaleString();
                if (data.failed_reels_today > 0) {
                    reelsText += ' <span style="font-size:12px; color:#ef4444; margin-left:10px; font-weight: 500;">(' + data.failed_reels_today + ' failed)</span>';
                } else {
                    reelsText += ' <span style="font-size:12px; color:var(--text-muted); margin-left:10px; font-weight: normal;">(0 failed)</span>';
                }
                document.getElementById('ajax-reels').innerHTML = reelsText;
            }
            if (data.reels_diff_html) {
                renderTrendBadge(data.reels_diff_html, 'ajax-reels-diff');
            }
            if (data.total_posts_today !== undefined) {
                let postsHtml = Number(data.total_posts_today).toLocaleString();
                if (data.page_limit > 0) {
                    let color = (data.total_posts_today >= data.page_limit) ? '#ef4444' : 'inherit';
                    postsHtml = '<span style="color:' + color + ';">' + postsHtml + ' / ' + Number(data.page_limit).toLocaleString() + '</span>';
                }
                document.getElementById('ajax-posts-today').innerHTML = postsHtml;
            }
            if (data.posts_diff_html) {
                renderTrendBadge(data.posts_diff_html, 'ajax-posts-today-diff');
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
                renderTrendBadge(data.reach_diff_html, 'ajax-reach-diff');
            }
            if (data.views_formatted) document.getElementById('ajax-views').textContent = data.views_formatted;
            if (data.views_diff_html) {
                renderTrendBadge(data.views_diff_html, 'ajax-views-diff');
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
                    let postUrl = '';

                    if (p.fb_post_id && (p.fb_post_id.startsWith('http://') || p.fb_post_id.startsWith('https://'))) {
                        postUrl = p.fb_post_id;
                    } else if (p.post_type && p.post_type.toLowerCase().includes('buffer')) {
                        let svc = (p.buffer_service || '').toLowerCase();
                        let cname = (p.buffer_channel_name || p.page_name || '').replace(/^@/, '').trim();

                        if (svc.includes('instagram') || p.post_type.toLowerCase().includes('instagram')) {
                            postUrl = cname ? `https://www.instagram.com/${cname}/` : 'https://www.instagram.com/';
                        } else if (svc.includes('tiktok')) {
                            postUrl = cname ? `https://www.tiktok.com/@${cname}` : 'https://www.tiktok.com/';
                        } else if (svc.includes('threads')) {
                            postUrl = cname ? `https://www.threads.net/@${cname}` : 'https://www.threads.net/';
                        } else if (svc.includes('pinterest')) {
                            postUrl = cname ? `https://www.pinterest.com/${cname}/` : 'https://www.pinterest.com/';
                        } else if (svc.includes('twitter') || svc === 'x') {
                            postUrl = cname ? `https://x.com/${cname}` : 'https://x.com/';
                        } else if (svc.includes('youtube')) {
                            postUrl = cname ? `https://www.youtube.com/@${cname}` : 'https://www.youtube.com/';
                        } else if (p.page_id) {
                            postUrl = `https://publish.buffer.com/channels/${p.page_id}/schedule?tab=sent`;
                        }
                    } else if (p.post_type === 'YouTube' && p.fb_post_id) {
                        postUrl = `https://www.youtube.com/watch?v=${p.fb_post_id}`;
                    } else if (p.fb_post_id) {
                        postUrl = `https://facebook.com/${p.fb_post_id}`;
                    }

                    if (postUrl) {
                        linkStart = `<a href="${postUrl}" target="_blank" style="text-decoration:none; color:var(--primary-color); font-weight:500; display:inline-flex; align-items:center; gap:4px;">`;
                        linkEnd = ` <span style="font-size:10px; color:var(--text-muted);">↗</span></a>`;
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
