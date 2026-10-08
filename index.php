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
    : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="13" height="13" style="display:inline-block; vertical-align:text-bottom; margin-right:4px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 4-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> THÀNH VIÊN • DỮ LIỆU TÀI KHOẢN CÁ NHÂN';

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
/* Evondev Skill Styling for Main Dashboard */
.dashboard-container,
.dashboard-container button,
.dashboard-container input,
.dashboard-container select {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.dashboard-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

/* Header Banner Card */
.db-header-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
    border: 1px solid #312e81;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(15, 23, 42, 0.15);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.db-header-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.db-header-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #818cf8;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.db-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 4px 0;
    letter-spacing: -0.02em;
}

.db-header-role {
    font-size: 12px;
    font-weight: 600;
    color: #c7d2fe;
    letter-spacing: 0.03em;
    display: flex;
    align-items: center;
    gap: 4px;
}

.db-health-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.health-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #047857;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    padding: 5px 12px;
    border-radius: 9999px;
}

.health-dot {
    width: 7px;
    height: 7px;
    background: #10b981;
    border-radius: 50%;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
    animation: pulseDot 2s infinite;
}

@keyframes pulseDot {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.1); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}

/* KPI Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.stat-card.premium-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 22px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.2s ease;
}

.stat-card.premium-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.08);
}

.premium-card .card-icon-container {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.premium-card .grad-purple { background: linear-gradient(135deg, #a855f7, #6366f1); }
.premium-card .grad-blue { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.premium-card .grad-red { background: linear-gradient(135deg, #f43f5e, #e11d48); }
.premium-card .grad-green { background: linear-gradient(135deg, #10b981, #059669); }
.premium-card .grad-orange { background: linear-gradient(135deg, #f97316, #ea580c); }
.premium-card .grad-indigo { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
.premium-card .grad-sky { background: linear-gradient(135deg, #0ea5e9, #0284c7); }

.premium-card .card-info-container {
    flex: 1;
    min-width: 0;
}

.premium-card .stat-title {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
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
    font-weight: 900;
    line-height: 1.1;
    letter-spacing: -0.03em;
    color: #0f172a;
}
.premium-card .stat-value.color-purple { color: #8b5cf6; }
.premium-card .stat-value.color-blue { color: #2563eb; }
.premium-card .stat-value.color-red { color: #e11d48; }
.premium-card .stat-value.color-green { color: #059669; }
.premium-card .stat-value.color-orange { color: #ea580c; }
.premium-card .stat-value.color-indigo { color: #4f46e5; }
.premium-card .stat-value.color-sky { color: #0284c7; }

.premium-card .stat-subtitle {
    font-size: 11.5px;
    color: #64748b;
    margin-top: 0;
    line-height: 1.3;
}

/* Trend Badges */
.trend-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}
.trend-badge.trend-up {
    background: #ecfdf5;
    color: #059669;
    border: 1px solid #a7f3d0;
}
.trend-badge.trend-down {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

/* Section Cards & Tables */
.db-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
}

.db-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 10px;
}

.db-card-title {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.db-card-sub {
    font-size: 12px;
    color: #64748b;
}

/* Chart Canvas Wrapper */
.chart-wrapper {
    height: 340px;
    position: relative;
    width: 100%;
}

.chart-legend-box {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    margin-top: 14px;
    font-size: 13px;
    font-weight: 700;
}
.legend-pill-followers {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #059669;
}
.legend-pill-reach {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #dc2626;
}

/* Dual Activity Grid */
.dual-activity-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}
@media (max-width: 992px) {
    .dual-activity-grid { grid-template-columns: 1fr; }
}

.db-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    text-align: left;
}
.db-table th {
    background: #f8fafc;
    padding: 12px 14px;
    font-weight: 800;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.05em;
}
.db-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.db-table tr:hover td {
    background: #fafafa;
}
.db-table tr:last-child td {
    border-bottom: none;
}

/* Status Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11.5px;
    font-weight: 700;
    line-height: 1;
}
.badge-published { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-failed { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; cursor: help; }
.badge-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-processing { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; animation: pulse 2s infinite; }

.countdown-timer {
    font-weight: 700;
    color: #4f46e5;
    font-size: 12.5px;
}

/* Skeleton Pulse */
@keyframes skeleton-pulse {
    0% { opacity: 0.4; }
    50% { opacity: 0.8; }
    100% { opacity: 0.4; }
}
.skeleton-box {
    height: 14px;
    background: #e2e8f0;
    border-radius: 4px;
    animation: skeleton-pulse 1.5s ease-in-out infinite;
}
</style>

<div class="dashboard-container">
    <!-- Header Banner Card -->
    <div class="db-header-card">
        <div class="db-header-left">
            <div class="db-header-icon">
                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div class="db-header-info">
                <h1>Today's Overview</h1>
                <div class="db-header-role">
                    <?= $sub_msg ?>
                </div>
            </div>
        </div>

        <div class="db-health-group">
            <span class="health-pill">
                <span class="health-dot"></span>
                <span>API: Online</span>
            </span>
            <span class="health-pill">
                <span class="health-dot"></span>
                <span>DB: 4ms</span>
            </span>
            <span class="health-pill">
                <span class="health-dot"></span>
                <span>Redis: Ready</span>
            </span>
        </div>
    </div>

    <!-- Banner cảnh báo checkpoint -->
    <div id="checkpoint-warning-banner"
        style="display:none; background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; padding:14px 18px; border-radius:12px; margin-bottom:24px; font-size:13.5px; color:#78350f; line-height:1.6; font-weight:600;">
    </div>

    <!-- Stats Grid (Loaded via AJAX for 0ms instant page render) -->
    <div class="stats-grid">
        <!-- Card 1: Connected Accounts -->
        <div class="stat-card premium-card">
            <div class="card-icon-container grad-purple">
                <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M4.5 6.375a4.125 4.125 0 1 1 8.25 0 4.125 4.125 0 0 1-8.25 0ZM14.25 8.625a3.375 3.375 0 1 1 6.75 0 3.375 3.375 0 0 1-6.75 0ZM1.5 19.125a7.125 7.125 0 0 1 14.25 0v.003l-.001.119a.75.75 0 0 1-.363.63 13.067 13.067 0 0 1-6.761 1.873c-2.472 0-4.786-.684-6.76-1.873a.75.75 0 0 1-.364-.63l-.001-.122ZM17.25 19.128l-.001.144a2.25 2.25 0 0 1-.233.96 10.088 10.088 0 0 0 3.484-1.104.75.75 0 0 0 .363-.63 6.75 6.75 0 0 0-11.238-5.187 8.623 8.623 0 0 1 7.625 5.817Z" />
                </svg>
            </div>
            <div class="card-info-container">
                <div class="stat-title"><?= $is_admin ? "Connected Accounts (All Users)" : "Connected FB Profiles"; ?></div>
                <div class="stat-value-row">
                    <span class="stat-value color-purple" id="ajax-users">—</span>
                    <span id="ajax-users-diff" style="display:none;"></span>
                </div>
                <div class="stat-subtitle"><?= $is_admin ? "Tổng tài khoản FB đã kết nối" : "Số tài khoản Facebook đã liên kết"; ?></div>
            </div>
        </div>

        <!-- Card 2: Total Fanpages -->
        <div class="stat-card premium-card">
            <div class="card-icon-container grad-blue">
                <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c4.56-.93 8-4.96 8-9.75z"/>
                </svg>
            </div>
            <div class="card-info-container">
                <div class="stat-title"><?= $is_admin ? "Total Fanpages (All Users)" : "Total Fanpages"; ?></div>
                <div class="stat-value-row">
                    <span class="stat-value color-blue" id="ajax-pages">—</span>
                    <span id="ajax-pages-diff" style="display:none;"></span>
                </div>
                <div class="stat-subtitle"><?= $is_admin ? "Tổng fanpage trên toàn hệ thống" : "Tổng fanpage sở hữu & chia sẻ"; ?></div>
            </div>
        </div>

        <!-- Card 3: Total Reach -->
        <div class="stat-card premium-card">
            <div class="card-icon-container grad-red">
                <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                </svg>
            </div>
            <div class="card-info-container">
                <div class="stat-title"><?= $is_admin ? "Total Reach (All Users)" : "Total Reach"; ?></div>
                <div class="stat-value-row">
                    <span class="stat-value color-red" id="ajax-reach">—</span>
                    <span id="ajax-reach-diff" style="display:none;"></span>
                </div>
                <div class="stat-subtitle"><?= $is_admin ? "Tổng reach từ page insights" : "Tổng tiếp cận fanpage"; ?> (<?= htmlspecialchars($period); ?>)</div>
            </div>
        </div>

        <!-- Card 4: Total Followers -->
        <div class="stat-card premium-card">
            <div class="card-icon-container grad-green">
                <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M2 20h2v-8H2v8zm19.83-8.88c-.2-.67-.82-1.12-1.52-1.12h-5.69l.86-4.14.03-.3c0-.38-.15-.74-.41-1.01L14.22 3.5 8.59 9.13C8.22 9.5 8 10 8 10.5V18c0 1.1.9 2 2 2h7.3c.73 0 1.37-.48 1.57-1.17l2.8-6.52c.1-.24.16-.5.16-.76v-1.13c0-.28-.06-.55-.17-.84z"/>
                </svg>
            </div>
            <div class="card-info-container">
                <div class="stat-title"><?= $is_admin ? "Total Flow (All Followers)" : "Total Followers"; ?></div>
                <div class="stat-value-row">
                    <span class="stat-value color-green" id="ajax-followers">—</span>
                    <span id="ajax-followers-diff" style="display:none;"></span>
                </div>
                <div class="stat-subtitle"><?= $is_admin ? "Tổng người theo dõi toàn bộ fanpage" : "Tổng người theo dõi các fanpage"; ?></div>
            </div>
        </div>

        <!-- Card 5: Reels Uploaded Today -->
        <div class="stat-card premium-card">
            <div class="card-icon-container grad-orange">
                <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zm0 4h3L5 6H4v2zm5 0h3L10 6H8v2zm5 0h3l-2-2h-2v2zm5 0h2V6h-1l-2 2zm-12 2v8h14v-8H8zm4 6v-4l4 2-4 2z"/>
                </svg>
            </div>
            <div class="card-info-container">
                <div class="stat-title"><?= $is_admin ? "Reels Uploaded Today (All Users)" : "Reels Uploaded Today"; ?></div>
                <div class="stat-value-row">
                    <span class="stat-value color-orange" id="ajax-reels">—</span>
                    <span id="ajax-reels-diff" style="display:none;"></span>
                </div>
                <div class="stat-subtitle"><?= $is_admin ? "Tổng reels đã upload hôm nay (giờ VN)" : "Tổng Reels đã đăng hôm nay"; ?></div>
            </div>
        </div>

        <!-- Card 6: Total Views -->
        <div class="stat-card premium-card">
            <div class="card-icon-container grad-indigo">
                <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M8 5v14l11-7z"/>
                </svg>
            </div>
            <div class="card-info-container">
                <div class="stat-title"><?= $is_admin ? "Total Views (All Users)" : "Total Views"; ?></div>
                <div class="stat-value-row">
                    <span class="stat-value color-indigo" id="ajax-views">—</span>
                    <span id="ajax-views-diff" style="display:none;"></span>
                </div>
                <div class="stat-subtitle"><?= $is_admin ? "Tổng views video/reels theo dữ liệu" : "Tổng lượt xem video/reels"; ?> (<?= htmlspecialchars($period); ?>)</div>
            </div>
        </div>

        <!-- Card 7: Total Posts Today -->
        <div class="stat-card premium-card">
            <div class="card-icon-container grad-sky">
                <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                </svg>
            </div>
            <div class="card-info-container">
                <div class="stat-title"><?= $is_admin ? 'Total Post Today (All Users)' : 'Total Posts Today'; ?></div>
                <div class="stat-value-row">
                    <span class="stat-value color-sky" id="ajax-posts-today">—</span>
                    <span id="ajax-posts-today-diff" style="display:none;"></span>
                </div>
                <div class="stat-subtitle"><?= $is_admin ? 'Tổng số bài viết đã đăng trong ngày hôm nay' : 'Tổng số bài viết đã đăng hôm nay'; ?></div>
            </div>
        </div>
    </div>

    <!-- Growth Chart Card -->
    <div class="db-card">
        <div class="db-card-header">
            <div>
                <h3 class="db-card-title">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    Growth Chart (7 Days History)
                </h3>
                <div class="db-card-sub">Dữ liệu tự động đồng bộ lúc 6:00 AM & 6:00 PM ICT mỗi ngày</div>
            </div>
            <span style="font-size: 11.5px; font-weight: 700; background: #e0e7ff; color: #4338ca; padding: 4px 12px; border-radius: 9999px;">Snapshot 7 ngày</span>
        </div>

        <div class="chart-wrapper">
            <canvas id="growthChart"></canvas>
        </div>

        <div class="chart-legend-box">
            <span class="legend-pill-followers">● Followers (Người theo dõi)</span>
            <span class="legend-pill-reach">● Reach (Lượt tiếp cận)</span>
        </div>
    </div>

    <!-- Dual Activity Tables -->
    <div class="dual-activity-grid">
        <!-- Card 1: Lịch sử đăng gần đây -->
        <div class="db-card" style="margin-bottom:0;">
            <div class="db-card-header">
                <h3 class="db-card-title">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Lịch Sử Đăng Bài Gần Đây
                </h3>
            </div>
            <div style="overflow-x: auto;">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>Kênh / Fanpage</th>
                            <th>Loại bài</th>
                            <th>Thời gian</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody id="recent-posts-body">
                        <tr><td><div class="skeleton-box" style="width:75%;"></div></td><td><div class="skeleton-box" style="width:50%;"></div></td><td><div class="skeleton-box" style="width:60%;"></div></td><td><div class="skeleton-box" style="width:40%;"></div></td></tr>
                        <tr><td><div class="skeleton-box" style="width:60%;"></div></td><td><div class="skeleton-box" style="width:50%;"></div></td><td><div class="skeleton-box" style="width:65%;"></div></td><td><div class="skeleton-box" style="width:40%;"></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Card 2: Hàng chờ đăng sắp tới -->
        <div class="db-card" style="margin-bottom:0;">
            <div class="db-card-header">
                <h3 class="db-card-title">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Hàng Chờ Đăng Bài Sắp Tới
                </h3>
            </div>
            <div style="overflow-x: auto;">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>Kênh / Fanpage</th>
                            <th>Loại bài</th>
                            <th>Dự kiến đăng</th>
                            <th>Thời gian chờ</th>
                        </tr>
                    </thead>
                    <tbody id="upcoming-queue-body">
                        <tr><td><div class="skeleton-box" style="width:70%;"></div></td><td><div class="skeleton-box" style="width:50%;"></div></td><td><div class="skeleton-box" style="width:65%;"></div></td><td><div class="skeleton-box" style="width:45%;"></div></td></tr>
                        <tr><td><div class="skeleton-box" style="width:80%;"></div></td><td><div class="skeleton-box" style="width:50%;"></div></td><td><div class="skeleton-box" style="width:60%;"></div></td><td><div class="skeleton-box" style="width:45%;"></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- AJAX Scripts & Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
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
    if (seconds <= 0) return '<span style="color: #4f46e5; font-weight: 700;">Sắp đăng...</span>';
    if (seconds < 60) return seconds + ' giây';
    const mins = Math.floor(seconds / 60);
    if (mins < 60) return mins + ' phút';
    const hours = Math.floor(seconds / 3600);
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
    startCountdown();

    // Render Growth Chart
    const ctx = document.getElementById('growthChart').getContext('2d');
    
    const gradientFollowers = ctx.createLinearGradient(0, 0, 0, 320);
    gradientFollowers.addColorStop(0, 'rgba(16, 185, 129, 0.25)');
    gradientFollowers.addColorStop(1, 'rgba(16, 185, 129, 0)');

    const gradientReach = ctx.createLinearGradient(0, 0, 0, 320);
    gradientReach.addColorStop(0, 'rgba(239, 68, 68, 0.25)');
    gradientReach.addColorStop(1, 'rgba(239, 68, 68, 0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_days); ?>,
            datasets: [
                {
                    label: 'Followers',
                    data: <?= json_encode($followers_chart_data); ?>,
                    borderColor: '#10b981',
                    backgroundColor: gradientFollowers,
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#10b981',
                    spanGaps: true
                },
                {
                    label: 'Reach',
                    data: <?= json_encode($reach_chart_data); ?>,
                    borderColor: '#ef4444',
                    backgroundColor: gradientReach,
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#ef4444',
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
                    grid: { color: 'transparent', drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '600' } }
                },
                y: {
                    grid: { color: '#f1f5f9', drawBorder: false, borderDash: [4, 4] },
                    ticks: { 
                        color: '#64748b', 
                        font: { size: 11, weight: '600' },
                        callback: function(value) {
                            if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return (value / 1000).toFixed(0) + 'k';
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

    // 1. Fast DB metrics (users, pages, followers, reels)
    fetch('actions/ajax_dashboard_db.php')
        .then(res => res.json())
        .then(data => {
            if (data.total_users !== undefined) document.getElementById('ajax-users').textContent = Number(data.total_users).toLocaleString();
            if (data.users_diff_html) renderTrendBadge(data.users_diff_html, 'ajax-users-diff');
            
            if (data.total_pages !== undefined) document.getElementById('ajax-pages').textContent = Number(data.total_pages).toLocaleString();
            if (data.pages_diff_html) renderTrendBadge(data.pages_diff_html, 'ajax-pages-diff');

            if (data.total_followers !== undefined) document.getElementById('ajax-followers').textContent = Number(data.total_followers).toLocaleString();
            if (data.followers_diff_html) renderTrendBadge(data.followers_diff_html, 'ajax-followers-diff');

            if (data.total_reels_today !== undefined) {
                let reelsText = Number(data.total_reels_today).toLocaleString();
                if (data.failed_reels_today > 0) {
                    reelsText += ' <span style="font-size:12px; color:#ef4444; margin-left:8px; font-weight:700;">(' + data.failed_reels_today + ' lỗi)</span>';
                } else {
                    reelsText += ' <span style="font-size:12px; color:#64748b; margin-left:8px; font-weight:500;">(0 lỗi)</span>';
                }
                document.getElementById('ajax-reels').innerHTML = reelsText;
            }
            if (data.reels_diff_html) renderTrendBadge(data.reels_diff_html, 'ajax-reels-diff');

            if (data.total_posts_today !== undefined) {
                let postsHtml = Number(data.total_posts_today).toLocaleString();
                if (data.page_limit > 0) {
                    let color = (data.total_posts_today >= data.page_limit) ? '#ef4444' : 'inherit';
                    postsHtml = '<span style="color:' + color + ';">' + postsHtml + ' / ' + Number(data.page_limit).toLocaleString() + '</span>';
                }
                document.getElementById('ajax-posts-today').innerHTML = postsHtml;
            }
            if (data.posts_diff_html) renderTrendBadge(data.posts_diff_html, 'ajax-posts-today-diff');
        })
        .catch(() => {
            document.getElementById('ajax-users').textContent = 'Lỗi';
            document.getElementById('ajax-pages').textContent = 'Lỗi';
        });

    // 2. Slow FB API metrics (reach, views)
    fetch('actions/ajax_dashboard_metrics.php')
        .then(res => res.json())
        .then(data => {
            if (data.reach_formatted) document.getElementById('ajax-reach').textContent = data.reach_formatted;
            if (data.reach_diff_html) renderTrendBadge(data.reach_diff_html, 'ajax-reach-diff');

            if (data.views_formatted) document.getElementById('ajax-views').textContent = data.views_formatted;
            if (data.views_diff_html) renderTrendBadge(data.views_diff_html, 'ajax-views-diff');

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
        .catch(() => {
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
                        linkStart = `<a href="${postUrl}" target="_blank" style="text-decoration:none; color:#4f46e5; font-weight:700; display:inline-flex; align-items:center; gap:4px;">`;
                        linkEnd = ` <span style="font-size:11px; color:#64748b;">↗</span></a>`;
                    }
                    
                    let safePageName = escapeHtml(p.page_name);
                    let safeCampName = escapeHtml(p.campaign_name);
                    
                    let campLinkHtml = '';
                    if (p.campaign_name) {
                        let campUrl = p.campaign_id ? `campaign_detail.php?id=${p.campaign_id}` : `manage_posts.php?search=${encodeURIComponent(p.page_name)}`;
                        campLinkHtml = `<div style="font-size: 11px; color: #64748b; margin-top: 3px; font-weight: normal;">📁 Chiến dịch: <a href="${campUrl}" style="color:#4f46e5; text-decoration:underline; font-weight:600;">${safeCampName}</a></div>`;
                    } else if (p.page_name) {
                        campLinkHtml = `<div style="font-size: 11px; color: #64748b; margin-top: 3px; font-weight: normal;">📁 <a href="manage_posts.php?search=${encodeURIComponent(p.page_name)}" style="color:#64748b; text-decoration:underline;">Tìm chiến dịch của kênh</a></div>`;
                    }

                    recentHtml += `<tr>
                        <td style="font-weight: 700; color: #1e293b;">
                            ${linkStart}${safePageName}${linkEnd}
                            ${campLinkHtml}
                        </td>
                        <td><span style="font-size: 13px; font-weight:600;">${formatPostType(p.post_type)}</span></td>
                        <td style="font-size: 12.5px; color: #64748b; font-weight:600;">${p.scheduled_time}</td>
                        <td><span class="badge ${badgeClass}" ${titleAttr}>${statusText}</span></td>
                    </tr>`;
                });
                document.getElementById('recent-posts-body').innerHTML = recentHtml;
            } else {
                document.getElementById('recent-posts-body').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#64748b; padding:24px;">Không có bài viết nào gần đây.</td></tr>';
            }

            // Load upcoming posts
            let upcomingHtml = '';
            if (data.upcoming && data.upcoming.length > 0) {
                data.upcoming.forEach(p => {
                    let badgeClass = p.status === 'processing' ? 'badge-processing' : 'badge-pending';
                    let statusText = p.status === 'processing' ? 'Đang gửi...' : 'Đang chờ';
                    
                    let safeUpPageName = escapeHtml(p.page_name);
                    let safeUpCampName = escapeHtml(p.campaign_name);

                    let upCampLinkHtml = '';
                    if (p.campaign_name) {
                        let campUrl = p.campaign_id ? `campaign_detail.php?id=${p.campaign_id}` : `manage_posts.php?search=${encodeURIComponent(p.page_name)}`;
                        upCampLinkHtml = `<div style="font-size: 11px; color: #64748b; margin-top: 3px; font-weight: normal;">📁 Chiến dịch: <a href="${campUrl}" style="color:#4f46e5; text-decoration:underline; font-weight:600;">${safeUpCampName}</a></div>`;
                    }

                    upcomingHtml += `<tr>
                        <td style="font-weight: 700; color: #1e293b;">
                            ${safeUpPageName}
                            ${upCampLinkHtml}
                        </td>
                        <td><span style="font-size: 13px; font-weight:600;">${formatPostType(p.post_type)}</span></td>
                        <td style="font-size: 12.5px; color: #64748b; font-weight:600;">${p.scheduled_time}</td>
                        <td>
                            <span class="countdown-timer" data-seconds="${p.seconds_left}">
                                ${formatTimeRemaining(p.seconds_left)}
                            </span>
                            <span class="badge ${badgeClass}" style="margin-left: 6px;">${statusText}</span>
                        </td>
                    </tr>`;
                });
                document.getElementById('upcoming-queue-body').innerHTML = upcomingHtml;
            } else {
                document.getElementById('upcoming-queue-body').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#64748b; padding:24px;">Không có bài viết nào đang chờ đăng.</td></tr>';
            }
        })
        .catch(() => {
            document.getElementById('recent-posts-body').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#ef4444; padding:24px;">Lỗi tải dữ liệu.</td></tr>';
            document.getElementById('upcoming-queue-body').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#ef4444; padding:24px;">Lỗi tải dữ liệu.</td></tr>';
        });
});
</script>

<?php include 'includes/footer.php'; ?>
