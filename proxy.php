<?php
// proxy.php
$current_page = 'proxy';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

$account_id = $_SESSION['account_id'];

// Flash messages from PHP session (consumed in header.php)
$flash_msg = $global_flash_msg ?? '';
$flash_type = $global_flash_type ?? 'success';

// Auto check untested proxies on page load via fast parallel multi-curl
function auto_check_untested_proxies($pdo, $account_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM proxies WHERE account_id = ? AND status = 'untested' LIMIT 30");
        $stmt->execute([$account_id]);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($list)) return;

        $mh = curl_multi_init();
        $handles = [];

        foreach ($list as $px) {
            $ch = curl_init('https://graph.facebook.com');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

            $ip_str = (($px['ip_type'] ?? '') === 'IPv6' && strpos($px['ip'], ':') !== false) ? '[' . $px['ip'] . ']' : $px['ip'];
            curl_setopt($ch, CURLOPT_PROXY, $ip_str . ':' . $px['port']);
            if (!empty($px['username']) && !empty($px['password'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $px['username'] . ':' . $px['password']);
            }
            if (strtolower($px['protocol'] ?? '') === 'socks5') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            }
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            curl_multi_add_handle($mh, $ch);
            $handles[] = [
                'ch' => $ch,
                'px' => $px,
                'start' => microtime(true)
            ];
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            usleep(5000);
        } while ($running > 0);

        $upd = $pdo->prepare("UPDATE proxies SET status = ?, latency = ? WHERE id = ?");

        foreach ($handles as $item) {
            $ch = $item['ch'];
            $px = $item['px'];
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $duration = round((microtime(true) - $item['start']) * 1000);

            if ($http_code > 0 && $http_code < 500) {
                $upd->execute(['live', $duration, $px['id']]);
            } else {
                $upd->execute(['dead', 0, $px['id']]);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    } catch (Exception $e) {}
}

// Fetch user's proxies with assigned user info
$stmt = $pdo->prepare("
    SELECT p.*, u.name as user_name, u.fb_id
    FROM proxies p
    LEFT JOIN users u ON p.assigned_user_id = u.id
    WHERE p.account_id = ?
    ORDER BY p.id DESC
");
$stmt->execute([$account_id]);
$proxies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user tokens for assignment dropdown
$stmt_u = $pdo->prepare("
    SELECT u.id, u.name, u.fb_id, u.proxy_id, p.proxy_string as assigned_proxy_str
    FROM users u
    LEFT JOIN proxies p ON u.proxy_id = p.id
    WHERE u.account_id = ?
    ORDER BY u.name ASC
");
$stmt_u->execute([$account_id]);
$user_tokens = $stmt_u->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$total_count = count($proxies);
$live_count = 0;
$dead_count = 0;
$untested_count = 0;

foreach ($proxies as $px) {
    if ($px['status'] === 'live') $live_count++;
    elseif ($px['status'] === 'dead') $dead_count++;
    else $untested_count++;
}
?>

<style>
/* Evondev Skill Premium Styling for Proxy Manager */
.proxy-page-container,
.proxy-page-container button,
.proxy-page-container input,
.proxy-page-container select,
.proxy-page-container textarea {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.proxy-page-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

/* Header Banner */
.proxy-header-card {
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

.proxy-header-left h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.proxy-header-left p {
    font-size: 13.5px;
    color: #cbd5e1;
    margin: 0;
}

.proxy-header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.px-btn-secondary {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 10px 18px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.px-btn-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
    transform: translateY(-1px);
}

.px-btn-primary {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: #ffffff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    transition: all 0.2s ease;
}
.px-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}

/* Flash Alert */
.px-alert {
    padding: 14px 18px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.px-alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.px-alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.px-alert-warning { background: #fffbeb; border: 1px solid #fef08a; color: #854d0e; }

/* Stat Cards Grid */
.proxy-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}
@media (max-width: 768px) {
    .proxy-stats-grid { grid-template-columns: 1fr; }
}

.px-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 22px 24px;
    box-shadow: 0 4px 20px -2px rgba(0,0,0,0.04);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.2s ease;
}
.px-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px -4px rgba(0,0,0,0.08);
}

.px-stat-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}
.card-total .px-stat-icon-wrapper { background: #eef2ff; color: #4f46e5; }
.card-live .px-stat-icon-wrapper { background: #ecfdf5; color: #10b981; }
.card-dead .px-stat-icon-wrapper { background: #fef2f2; color: #ef4444; }

.px-stat-val {
    font-size: 30px;
    font-weight: 900;
    line-height: 1.1;
    color: #0f172a;
    letter-spacing: -0.03em;
}
.card-total .px-stat-val { color: #1e293b; }
.card-live .px-stat-val { color: #047857; }
.card-dead .px-stat-val { color: #b91c1c; }

.px-stat-lbl {
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    margin-top: 4px;
}

/* Table Surface Card */
.px-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
}

.px-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
    text-align: left;
}
.px-table th {
    background: #f8fafc;
    padding: 14px 18px;
    font-weight: 800;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase;
    font-size: 11.5px;
    letter-spacing: 0.05em;
}
.px-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.px-table tr:hover td {
    background: #fafafa;
}
.px-table tr:last-child td {
    border-bottom: none;
}

/* Code Pills & Badges */
.px-code-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 12.5px;
    font-weight: 600;
    color: #1e293b;
    background: #f1f5f9;
    padding: 4px 10px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.px-copy-btn {
    background: transparent;
    border: none;
    color: #64748b;
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 4px;
    font-size: 12px;
    transition: color 0.15s;
}
.px-copy-btn:hover {
    color: #4f46e5;
    background: #e0e7ff;
}

.badge-iptype {
    background: #e0f2fe;
    color: #0369a1;
    font-size: 11px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 9999px;
    margin-left: 6px;
}

.px-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 800;
}
.px-status-live {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
.px-status-dead {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}
.px-status-untested {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
}

.status-dot-live {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #16a34a;
}
.status-dot-dead {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #dc2626;
}

.latency-badge {
    font-weight: 800;
    font-size: 12.5px;
}
.latency-good { color: #16a34a; }
.latency-medium { color: #d97706; }
.latency-slow { color: #dc2626; }

.px-action-btn {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    cursor: pointer;
    font-size: 14px;
    padding: 6px 10px;
    border-radius: 8px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
    color: #475569;
    margin-left: 4px;
}
.px-action-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
    transform: translateY(-1px);
}
.px-action-btn-danger:hover {
    background: #fef2f2;
    border-color: #fecaca;
    color: #dc2626;
}

/* Glassmorphism Modals */
.px-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(6px);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    padding: 20px;
}
.px-modal-content {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    width: 100%;
    max-width: 580px;
    padding: 28px;
    box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
    animation: modalFadeIn 0.2s ease-out;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(10px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.px-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.px-modal-header h3 {
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.px-modal-close {
    background: transparent;
    border: none;
    font-size: 20px;
    color: #64748b;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
}
.px-modal-close:hover { background: #f1f5f9; color: #0f172a; }

.px-form-label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 8px;
}
.px-form-input, .px-form-select, .px-form-textarea {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
.px-form-textarea {
    font-family: 'Consolas', 'Monaco', monospace;
    line-height: 1.5;
}
.px-form-input:focus, .px-form-select:focus, .px-form-textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}
</style>

<div class="proxy-page-container">
    <!-- Header Banner -->
    <div class="proxy-header-card">
        <div class="proxy-header-left">
            <h1>
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                Kho Quản Lý Proxy
            </h1>
            <p>Định tuyến kết nối an toàn cho tài khoản Facebook, hỗ trợ IPv4/IPv6 & SOCKS5/HTTP Proxy</p>
        </div>
        <div class="proxy-header-actions">
            <a href="actions/proxy_actions.php?action=check_all_proxies" class="px-btn-secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Kiểm tra tất cả
            </a>
            <button type="button" class="px-btn-secondary" onclick="openAssignModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Gán vào Người Dùng
            </button>
            <button type="button" class="px-btn-primary" onclick="openAddModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Thêm nhiều Proxy
            </button>
        </div>
    </div>

    <!-- Flash Alert -->
    <?php if (!empty($flash_msg)): ?>
        <div class="px-alert px-alert-<?= $flash_type === 'danger' ? 'danger' : ($flash_type === 'warning' ? 'warning' : 'success') ?>">
            <div style="display: flex; align-items: center; gap: 8px;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span><?= htmlspecialchars($flash_msg) ?></span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; font-size:18px; cursor:pointer; color:inherit;">&times;</button>
        </div>
    <?php endif; ?>

    <!-- KPI Stat Cards -->
    <div class="proxy-stats-grid">
        <div class="px-stat-card card-total">
            <div>
                <div class="px-stat-val"><?= number_format($total_count) ?></div>
                <div class="px-stat-lbl">Tổng số Proxy trong hệ thống</div>
            </div>
            <div class="px-stat-icon-wrapper">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            </div>
        </div>

        <div class="px-stat-card card-live">
            <div>
                <div class="px-stat-val"><?= number_format($live_count) ?></div>
                <div class="px-stat-lbl">Proxy Hoạt Động (Live ✓)</div>
            </div>
            <div class="px-stat-icon-wrapper">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>

        <div class="px-stat-card card-dead">
            <div>
                <div class="px-stat-val"><?= number_format($dead_count) ?></div>
                <div class="px-stat-lbl">Proxy Lỗi / Tắt (Dead ✗)</div>
            </div>
            <div class="px-stat-icon-wrapper">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
    </div>

    <!-- Main Table Surface -->
    <div class="px-table-card">
        <div style="overflow-x: auto;">
            <table class="px-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">STT</th>
                        <th>CHUỖI PROXY</th>
                        <th>IP & PORT</th>
                        <th>VỊ TRÍ & LOẠI</th>
                        <th>TỐC ĐỘ (LATENCY)</th>
                        <th>TRẠNG THÁI</th>
                        <th>NGƯỜI DÙNG ĐƯỢC GÁN</th>
                        <th style="text-align: right; min-width: 120px;">THAO TÁC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($proxies)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 48px; color: #64748b;">
                                <div style="font-size: 32px; margin-bottom: 8px;">🌐</div>
                                <div style="font-weight: 700; font-size: 15px; color: #1e293b; margin-bottom: 4px;">Chưa có Proxy nào trong kho</div>
                                <div style="font-size: 13px;">Bấm nút <strong>"+ Thêm nhiều Proxy"</strong> góc phải để nhập danh sách Proxy hàng loạt.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($proxies as $idx => $px): ?>
                            <tr>
                                <td style="color: #94a3b8; font-size: 12.5px; font-weight: 700;">
                                    <?= $idx + 1 ?>
                                </td>
                                <td>
                                    <div class="px-code-pill" title="<?= htmlspecialchars($px['proxy_string']) ?>">
                                        <span><?= htmlspecialchars($px['proxy_string']) ?></span>
                                        <button type="button" class="px-copy-btn" onclick="copyText('<?= htmlspecialchars($px['proxy_string']) ?>', this)" title="Copy Chuỗi Proxy">📋</button>
                                    </div>
                                </td>
                                <td style="font-family: monospace; font-size: 13px; color: #334155; font-weight: 600;">
                                    <?= htmlspecialchars($px['ip']) ?>:<?= htmlspecialchars($px['port']) ?>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: #1e293b;">
                                        <?= htmlspecialchars($px['country'] ?: 'VN') ?>
                                    </span>
                                    <span class="badge-iptype"><?= htmlspecialchars($px['ip_type'] ?: 'IPv4') ?></span>
                                </td>
                                <td>
                                    <?php if ($px['status'] === 'live'): ?>
                                        <?php 
                                        $lat = (int)$px['latency'];
                                        $cls = ($lat < 300) ? 'latency-good' : (($lat < 800) ? 'latency-medium' : 'latency-slow');
                                        ?>
                                        <span class="latency-badge <?= $cls ?>"><?= $lat ?>ms</span>
                                    <?php elseif ($px['status'] === 'dead'): ?>
                                        <span style="color: #ef4444; font-size: 12px; font-weight: 700;">--</span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px; font-style: italic;">Chưa thử</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($px['status'] === 'live'): ?>
                                        <span class="px-status-pill px-status-live">
                                            <span class="status-dot-live"></span>
                                            <span>Sống ✓</span>
                                        </span>
                                    <?php elseif ($px['status'] === 'dead'): ?>
                                        <span class="px-status-pill px-status-dead">
                                            <span class="status-dot-dead"></span>
                                            <span>Chết ✗</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="px-status-pill px-status-untested">Chưa kiểm tra</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($px['user_name'])): ?>
                                        <span style="background: #e0e7ff; color: #4338ca; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                            👤 <?= htmlspecialchars($px['user_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12.5px; font-style: italic;">Chưa gán</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="actions/proxy_actions.php?action=check_proxy&proxy_id=<?= $px['id'] ?>" class="px-action-btn" title="Kiểm tra trạng thái">🔍</a>

                                    <?php if (!empty($px['assigned_user_id'])): ?>
                                        <a href="actions/proxy_actions.php?action=unassign_proxy&proxy_id=<?= $px['id'] ?>" class="px-action-btn" title="Hủy gán cho người dùng">🔗</a>
                                    <?php else: ?>
                                        <button type="button" class="px-action-btn" onclick="openAssignSingleModal(<?= $px['id'] ?>)" title="Gán cho người dùng">➕</button>
                                    <?php endif; ?>

                                    <a href="actions/proxy_actions.php?action=delete_proxy&proxy_id=<?= $px['id'] ?>" class="px-action-btn px-action-btn-danger" onclick="return confirm('Bạn có chắc chắn muốn xóa Proxy này?')" title="Xóa Proxy">🗑️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Thêm Nhiều Proxy Hàng Loạt -->
<div id="addProxyModal" class="px-modal">
    <div class="px-modal-content">
        <div class="px-modal-header">
            <h3>
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Thêm Danh Sách Proxy Hàng Loạt
            </h3>
            <button type="button" class="px-modal-close" onclick="closeAddModal()">&times;</button>
        </div>
        <p style="color: #64748b; font-size: 13px; margin-top: 0; margin-bottom: 18px;">
            Nhập danh sách Proxy (mỗi Proxy nằm trên 1 dòng). Hệ thống tự động phân tích IP, Port, Username, Password cho <strong>IPv4</strong> và <strong>IPv6</strong>.
        </p>

        <form action="actions/proxy_actions.php" method="POST">
            <input type="hidden" name="action" value="add_proxies">

            <div style="margin-bottom: 20px;">
                <label class="px-form-label">Cú pháp mẫu: <span style="font-family:monospace; color:#4f46e5;">ip:port:user:pass</span> hoặc <span style="font-family:monospace; color:#4f46e5;">ip:port</span></label>
                <textarea name="proxy_list" rows="8" class="px-form-textarea" placeholder="103.21.12.34:8080:username:password
2001:db8::1:3128:user:pass
103.22.45.67:8080
http://user:pass@103.23.1.2:8080" required></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="px-btn-secondary" onclick="closeAddModal()">Hủy bỏ</button>
                <button type="submit" class="px-btn-primary">Xác nhận Thêm Proxy</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Gán Proxy Cho Người Dùng -->
<div id="assignProxyModal" class="px-modal">
    <div class="px-modal-content">
        <div class="px-modal-header">
            <h3>
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Gán Proxy Cho Người Dùng (Token)
            </h3>
            <button type="button" class="px-modal-close" onclick="closeAssignModal()">&times;</button>
        </div>
        <p style="color: #64748b; font-size: 13px; margin-top: 0; margin-bottom: 18px;">
            Chọn Proxy và Người dùng Token Facebook tương ứng để định tuyến kết nối Facebook API.
        </p>

        <form action="actions/proxy_actions.php" method="POST">
            <input type="hidden" name="action" value="assign_proxy">

            <div style="margin-bottom: 16px;">
                <label class="px-form-label">1. Chọn Proxy:</label>
                <select name="proxy_id" id="assign_proxy_id" class="px-form-select" required>
                    <option value="">-- Chọn Proxy từ kho --</option>
                    <?php foreach ($proxies as $px): ?>
                        <option value="<?= $px['id'] ?>">
                            <?= htmlspecialchars($px['proxy_string']) ?>
                            (<?= $px['status'] === 'live' ? 'Sống ✓' : ($px['status'] === 'dead' ? 'Chết ✗' : 'Chưa thử') ?>)
                            <?= !empty($px['user_name']) ? ' - [Đã gán: ' . htmlspecialchars($px['user_name']) . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 24px;">
                <label class="px-form-label">2. Chọn Người Dùng (Token Facebook):</label>
                <select name="user_id" id="assign_user_id" class="px-form-select" required>
                    <option value="">-- Chọn Người Dùng Token --</option>
                    <?php foreach ($user_tokens as $ut): ?>
                        <option value="<?= $ut['id'] ?>">
                            👤 <?= htmlspecialchars($ut['name']) ?> (FB ID: <?= htmlspecialchars($ut['fb_id']) ?>)
                            <?= !empty($ut['assigned_proxy_str']) ? ' - [Đã có Proxy: ' . htmlspecialchars($ut['assigned_proxy_str']) . ']' : ' - [Chưa có Proxy]' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="px-btn-secondary" onclick="closeAssignModal()">Hủy bỏ</button>
                <button type="submit" class="px-btn-primary">Xác nhận Gán Proxy</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addProxyModal').style.display = 'flex';
}
function closeAddModal() {
    document.getElementById('addProxyModal').style.display = 'none';
}

function openAssignModal() {
    document.getElementById('assignProxyModal').style.display = 'flex';
}
function openAssignSingleModal(proxyId) {
    document.getElementById('assign_proxy_id').value = proxyId;
    document.getElementById('assignProxyModal').style.display = 'flex';
}
function closeAssignModal() {
    document.getElementById('assignProxyModal').style.display = 'none';
}

function copyText(str, btn) {
    navigator.clipboard.writeText(str).then(function() {
        var orig = btn.innerText;
        btn.innerText = '✓';
        setTimeout(function() { btn.innerText = orig; }, 1200);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
