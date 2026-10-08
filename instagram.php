<?php
// instagram.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/instagram_api.php';

if (session_status() === PHP_SESSION_NONE) @session_start();
$account_id = $_SESSION['account_id'] ?? 0;
$is_admin   = ($_SESSION['role'] ?? '') === 'admin';

// ── Read local upload restriction ──────────────────────────────────────────
$disable_local_upload = false;
try {
    $stmt_upload = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'disable_local_upload'");
    $stmt_upload->execute();
    $row_upload = $stmt_upload->fetch(PDO::FETCH_ASSOC);
    if ($row_upload && $row_upload['setting_value'] === '1' && !$is_admin) $disable_local_upload = true;
} catch (Exception $e) {}

// Sync Instagram accounts action (MUST be before header.php output)
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    $count = sync_instagram_accounts($account_id);
    header('Location: instagram.php?synced=' . $count);
    exit;
}

// Delete media action
if (isset($_GET['action']) && $_GET['action'] === 'delete_media' && !empty($_GET['media_id']) && !empty($_GET['ig_id'])) {
    $ig_id = $_GET['ig_id'];
    $media_id = $_GET['media_id'];
    $ig_accounts = get_instagram_accounts($account_id);
    $token = '';
    foreach ($ig_accounts as $acc) {
        if ($acc['ig_user_id'] === $ig_id) { $token = $acc['access_token']; break; }
    }
    if ($token) {
        delete_instagram_media($media_id, $token);
    }
    header('Location: instagram.php?tab=media&ig_id=' . urlencode($ig_id));
    exit;
}

// Delete IG account link
if (isset($_GET['action']) && $_GET['action'] === 'delete_account' && !empty($_GET['id'])) {
    $del_stmt = $pdo->prepare("DELETE FROM instagram_accounts WHERE id = ? AND account_id = ?");
    $del_stmt->execute([intval($_GET['id']), $account_id]);
    header('Location: instagram.php?tab=channels&msg=deleted');
    exit;
}

// ── Header Output ─────────────────────────────────────────────────────────
$current_page = 'instagram';
require_once __DIR__ . '/includes/header.php';

// Fetch Instagram Accounts & limit
$ig_accounts = get_instagram_accounts($account_id);
$selected_ig_id = $_GET['ig_id'] ?? ($ig_accounts[0]['ig_user_id'] ?? '');
$active_tab = $_GET['tab'] ?? 'channels';

$stmt_lim = $pdo->prepare("SELECT max_instagram_accounts FROM system_accounts WHERE id = ?");
$stmt_lim->execute([$account_id]);
$max_ig_accounts = intval($stmt_lim->fetchColumn() ?: 10);
$max_ig_display = $is_admin ? '&infin;' : number_format($max_ig_accounts);

// ── Calculate Dashboard Insights Metrics (8 Metrics) ────────────────────
$total_ig_accounts   = count($ig_accounts);
$total_ig_posts      = 0;
$total_ig_published  = 0;
$total_ig_pending    = 0;
$total_ig_processing = 0;
$total_ig_failed     = 0;
$total_ig_reels      = 0;
$total_ig_stories    = 0;

try {
    $stmt_m = $pdo->prepare("
        SELECT 
            COUNT(*) as total_posts,
            SUM(IF(status = 'published', 1, 0)) as published_count,
            SUM(IF(status = 'pending', 1, 0)) as pending_count,
            SUM(IF(status = 'processing', 1, 0)) as processing_count,
            SUM(IF(status = 'failed', 1, 0)) as failed_count,
            SUM(IF(post_type LIKE '%Reel%', 1, 0)) as reels_count,
            SUM(IF(post_type LIKE '%Story%', 1, 0)) as stories_count
        FROM scheduled_posts 
        WHERE account_id = ? AND post_type LIKE 'Instagram%'
    ");
    $stmt_m->execute([$account_id]);
    $row_m = $stmt_m->fetch(PDO::FETCH_ASSOC);
    if ($row_m) {
        $total_ig_posts      = intval($row_m['total_posts'] ?? 0);
        $total_ig_published  = intval($row_m['published_count'] ?? 0);
        $total_ig_pending    = intval($row_m['pending_count'] ?? 0);
        $total_ig_processing = intval($row_m['processing_count'] ?? 0);
        $total_ig_failed     = intval($row_m['failed_count'] ?? 0);
        $total_ig_reels      = intval($row_m['reels_count'] ?? 0);
        $total_ig_stories    = intval($row_m['stories_count'] ?? 0);
    }
} catch (Exception $e) {}

// Published Media List for 'media' tab
$media_list = [];
$selected_acc = null;
if ($active_tab === 'media' && !empty($selected_ig_id)) {
    foreach ($ig_accounts as $acc) {
        if ($acc['ig_user_id'] === $selected_ig_id) { $selected_acc = $acc; break; }
    }
    if ($selected_acc) {
        $media_list = get_instagram_media_list($selected_acc['ig_user_id'], $selected_acc['access_token'], 30);
    }
}
?>

<style>
/* Evondev Skill Styling for Instagram Manager */
.ig-page-container,
.ig-page-container button,
.ig-page-container input,
.ig-page-container select,
.ig-page-container textarea {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.ig-page-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

/* Header Banner Card */
.ig-header-card {
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

.ig-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.ig-header-info p {
    font-size: 13.5px;
    color: #cbd5e1;
    margin: 0;
}

.ig-sync-btn {
    background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
    color: #ffffff;
    font-weight: 800;
    font-size: 13.5px;
    text-decoration: none;
    padding: 10px 22px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(220, 39, 67, 0.3);
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
}
.ig-sync-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(220, 39, 67, 0.4);
    color: #ffffff;
}

/* Flash Notifications */
.ig-alert {
    padding: 14px 18px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.ig-alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.ig-alert-warning { background: #fff7ed; border: 1px solid #fed7aa; color: #c2410c; }

/* 8 Metrics KPI Grid */
.ig-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 1200px) { .ig-stats-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 576px) { .ig-stats-grid { grid-template-columns: 1fr; } }

.ig-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px 20px;
    box-shadow: 0 4px 20px -2px rgba(0,0,0,0.04);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.2s ease;
}
.ig-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px -4px rgba(0,0,0,0.08);
}

.ig-stat-card .icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #ffffff;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.grad-ig-pink   { background: linear-gradient(135deg, #f43f5e, #e11d48); }
.grad-ig-purple { background: linear-gradient(135deg, #a855f7, #7e22ce); }
.grad-ig-orange { background: linear-gradient(135deg, #f97316, #ea580c); }
.grad-ig-indigo { background: linear-gradient(135deg, #6366f1, #4338ca); }
.grad-ig-amber  { background: linear-gradient(135deg, #f59e0b, #d97706); }
.grad-ig-red    { background: linear-gradient(135deg, #ef4444, #b91c1c); }
.grad-ig-rose   { background: linear-gradient(135deg, #ec4899, #be185d); }
.grad-ig-violet { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }

.ig-stat-card .lbl {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 2px;
}
.ig-stat-card .val {
    font-size: 26px;
    font-weight: 900;
    line-height: 1.1;
    letter-spacing: -0.02em;
}

.text-pink   { color: #f43f5e; }
.text-purple { color: #a855f7; }
.text-orange { color: #f97316; }
.text-indigo { color: #6366f1; }
.text-amber  { color: #f59e0b; }
.text-red    { color: #ef4444; }
.text-rose   { color: #ec4899; }
.text-violet { color: #8b5cf6; }

/* Navigation Tab Bar */
.ig-nav-tabs {
    display: flex;
    gap: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 6px;
    border-radius: 14px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.ig-tab-btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
}
.ig-tab-btn:hover {
    color: #1e293b;
    background: rgba(255, 255, 255, 0.6);
}
.ig-tab-btn.active {
    background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366);
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(220, 39, 67, 0.25);
}

/* Card Container Surface */
.ig-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
}

/* Table Surface */
.ig-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
    text-align: left;
}
.ig-table th {
    background: #f8fafc;
    padding: 14px 18px;
    font-weight: 800;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase;
    font-size: 11.5px;
    letter-spacing: 0.05em;
}
.ig-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.ig-table tr:hover td { background: #fafafa; }
.ig-table tr:last-child td { border-bottom: none; }

/* Buttons */
.btn-ig-primary {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: #ffffff;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.btn-ig-primary:hover { transform: translateY(-1px); }

.btn-ig-secondary {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.btn-ig-secondary:hover { background: #f8fafc; border-color: #94a3b8; }
</style>

<div class="ig-page-container">
    <!-- Header Banner Card -->
    <div class="ig-header-card">
        <div class="ig-header-info">
            <h1>
                <svg width="26" height="26" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="4"/></svg>
                Quản Lý Instagram Business & Insights
            </h1>
            <p>Đăng bài Feed, Video Reels, Story tự động & Xem chỉ số đo lường hiệu suất kênh Instagram</p>
        </div>
        <div>
            <a href="instagram.php?action=sync" class="ig-sync-btn">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span>Đồng bộ kênh từ Fanpages</span>
            </a>
        </div>
    </div>

    <!-- Flash Notifications -->
    <?php if (!empty($global_flash_msg)): ?>
        <div class="ig-alert ig-alert-warning">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><?= htmlspecialchars($global_flash_msg); ?></span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['synced'])): ?>
        <div class="ig-alert ig-alert-success">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>Đã quét và đồng bộ thành công <strong><?= (int)$_GET['synced']; ?></strong> tài khoản Instagram Doanh nghiệp kết nối từ Facebook Pages!</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
        <div class="ig-alert ig-alert-success">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>Đã xóa hủy liên kết tài khoản Instagram thành công!</span>
        </div>
    <?php endif; ?>

    <!-- 8 Metrics KPI Grid -->
    <div class="ig-stats-grid">
        <div class="ig-stat-card">
            <div class="icon-box grad-ig-pink">📷</div>
            <div>
                <div class="lbl">Tài khoản</div>
                <div class="val text-pink"><?= number_format($total_ig_accounts) . ' / ' . $max_ig_display; ?></div>
            </div>
        </div>

        <div class="ig-stat-card">
            <div class="icon-box grad-ig-purple">📄</div>
            <div>
                <div class="lbl">Tổng bài</div>
                <div class="val text-purple"><?= number_format($total_ig_posts); ?></div>
            </div>
        </div>

        <div class="ig-stat-card">
            <div class="icon-box grad-ig-orange">🎯</div>
            <div>
                <div class="lbl">Đã đăng</div>
                <div class="val text-orange"><?= number_format($total_ig_published); ?></div>
            </div>
        </div>

        <div class="ig-stat-card">
            <div class="icon-box grad-ig-indigo">🕒</div>
            <div>
                <div class="lbl">Đang chờ</div>
                <div class="val text-indigo"><?= number_format($total_ig_pending); ?></div>
            </div>
        </div>

        <div class="ig-stat-card">
            <div class="icon-box grad-ig-amber">🔄</div>
            <div>
                <div class="lbl">Đang đăng</div>
                <div class="val text-amber"><?= number_format($total_ig_processing); ?></div>
            </div>
        </div>

        <div class="ig-stat-card">
            <div class="icon-box grad-ig-red">❌</div>
            <div>
                <div class="lbl">Hỏng</div>
                <div class="val text-red"><?= number_format($total_ig_failed); ?></div>
            </div>
        </div>

        <div class="ig-stat-card">
            <div class="icon-box grad-ig-rose">🎬</div>
            <div>
                <div class="lbl">Reel</div>
                <div class="val text-rose"><?= number_format($total_ig_reels); ?></div>
            </div>
        </div>

        <div class="ig-stat-card">
            <div class="icon-box grad-ig-violet">🔖</div>
            <div>
                <div class="lbl">Story</div>
                <div class="val text-violet"><?= number_format($total_ig_stories); ?></div>
            </div>
        </div>
    </div>

    <!-- Navigation Tab Pills -->
    <div class="ig-nav-tabs">
        <a href="instagram.php?tab=channels" class="ig-tab-btn <?= $active_tab==='channels'?'active':''; ?>">
            📌 Các Kênh Đã Đồng Bộ (<?= $total_ig_accounts; ?> / <?= $max_ig_display; ?>)
        </a>
        <a href="instagram.php?tab=create" class="ig-tab-btn <?= $active_tab==='create'?'active':''; ?>">
            🚀 Đăng Bài & Lên Lịch Instagram
        </a>
        <a href="instagram.php?tab=media<?= !empty($selected_ig_id) ? '&ig_id='.urlencode($selected_ig_id) : ''; ?>" class="ig-tab-btn <?= $active_tab==='media'?'active':''; ?>">
            📊 Bài Đăng & Insights Chi Tiết
        </a>
    </div>

    <!-- TAB 1: CÁC KÊNH ĐÃ ĐỒNG BỘ -->
    <?php if ($active_tab === 'channels'): ?>
    <div class="ig-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <h3 style="margin:0; font-weight:800; font-size:16px; color:#0f172a;">Danh sách tài khoản Instagram Doanh nghiệp đã kết nối</h3>
            <a href="instagram.php?action=sync" class="btn-ig-secondary">
                🔄 Làm mới / Đồng bộ từ Fanpage
            </a>
        </div>

        <?php if (empty($ig_accounts)): ?>
            <div style="text-align:center; padding:50px 20px;">
                <div style="font-size:48px; margin-bottom:12px;">📸</div>
                <h3 style="margin:0 0 8px; font-weight:800; color:#0f172a;">Chưa có tài khoản Instagram nào</h3>
                <p style="color:#64748b; font-size:14px; max-width:520px; margin:0 auto 20px;">
                    Tài khoản Instagram của bạn cần chuyển sang loại <strong>Business / Creator</strong> và được nối với Facebook Page trong Cài đặt Trang. Bấm nút bên dưới để hệ thống tự quét & kết nối.
                </p>
                <a href="instagram.php?action=sync" class="ig-sync-btn">
                    🔄 Bắt đầu Đồng bộ Kênh Instagram
                </a>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="ig-table">
                    <thead>
                        <tr>
                            <th>Tài khoản Instagram</th>
                            <th>Trang Facebook Liên Kết</th>
                            <th style="text-align:center;">Followers</th>
                            <th style="text-align:center;">Trạng Thái API</th>
                            <th style="text-align:right;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ig_accounts as $acc): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <?php if (!empty($acc['avatar'])): ?>
                                        <img src="<?= htmlspecialchars($acc['avatar']); ?>" style="width:44px; height:44px; border-radius:50%; object-fit:cover; border:2px solid #f9a8d4;">
                                    <?php else: ?>
                                        <div style="width:44px; height:44px; border-radius:50%; background:linear-gradient(45deg, #f09433, #dc2743); color:white; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:18px;">📸</div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight:800; color:#0f172a; font-size:15px;">@<?= htmlspecialchars($acc['username']); ?></div>
                                        <div style="font-size:12px; color:#64748b;"><?= htmlspecialchars($acc['name']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="color:#64748b; font-size:13px;">
                                FB Page ID: <code style="font-family:monospace; background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:700; color:#334155;"><?= htmlspecialchars($acc['fb_page_id']); ?></code>
                            </td>
                            <td style="text-align:center; font-weight:800; color:#0284c7;">
                                <?= number_format($acc['followers_count']); ?>
                            </td>
                            <td style="text-align:center;">
                                <span style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:12px; padding:4px 10px; border-radius:9999px; font-weight:700;">
                                    ✅ Sẵn sàng Đăng bài
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:flex; gap:8px; justify-content:flex-end;">
                                    <a href="instagram.php?tab=create&ig_id=<?= urlencode($acc['ig_user_id']); ?>" class="btn-ig-primary">
                                        🚀 Đăng bài
                                    </a>
                                    <a href="instagram.php?tab=media&ig_id=<?= urlencode($acc['ig_user_id']); ?>" class="btn-ig-secondary">
                                        📊 Stats
                                    </a>
                                    <a href="instagram.php?action=delete_account&id=<?= $acc['id']; ?>" onclick="return confirm('Hủy kết nối kênh Instagram này?');" style="padding:8px 12px; font-size:12.5px; font-weight:700; color:#dc2626; text-decoration:none; border:1px solid #fecaca; border-radius:8px; background:#fef2f2;">
                                        🗑️ Hủy
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 2: ĐĂNG BÀI & LÊN LỊCH INSTAGRAM -->
    <?php elseif ($active_tab === 'create'): ?>
    <div class="ig-card">
        <h3 style="margin:0 0 6px 0; font-weight:800; font-size:18px; color:#0f172a; display:flex; align-items:center; gap:8px;">
            🚀 Tạo & Lên Lịch Bài Đăng Instagram
        </h3>
        <p style="color: #64748b; font-size: 13.5px; margin-top:0; margin-bottom: 24px;">
            Chọn các kênh Instagram muốn đăng, định dạng Ảnh / Reels / Story, nội dung và tùy chọn phương tiện từ Máy, Google Drive hoặc Link TikTok.
        </p>

        <?php if (empty($ig_accounts)): ?>
            <div style="text-align:center; padding:40px; background:#fff7ed; border:1px dashed #fdba74; border-radius:12px; font-size:14px; color:#c2410c; font-weight:600;">
                ⚠️ Chưa có tài khoản Instagram nào được đồng bộ. Vui lòng bấm <a href="instagram.php?action=sync" style="font-weight:800; text-decoration:underline; color:#c2410c;">Vào đây để đồng bộ kênh</a> trước khi đăng bài.
            </div>
        <?php else: ?>
            <form id="igPublishForm" enctype="multipart/form-data">
                
                <!-- 1. Chọn Kênh Instagram (Checkbox + Search UI) -->
                <div class="form-group" style="margin-bottom:24px;">
                    <label style="font-weight:700; font-size:14px; color:#1e293b; display:block; margin-bottom:10px;">1. Chọn kênh Instagram muốn đăng bài:</label>
                    
                    <style>
                    .ig-ps-wrapper {
                        border: 1px solid #cbd5e1;
                        border-radius: 12px;
                        overflow: hidden;
                        background: #ffffff;
                    }
                    .ig-ps-search-bar {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        padding: 10px 14px;
                        border-bottom: 1px solid #e2e8f0;
                        background: #f8fafc;
                    }
                    .ig-ps-search-bar svg { flex-shrink: 0; color: #94a3b8; }
                    .ig-ps-search-bar input {
                        flex: 1;
                        border: none;
                        background: transparent;
                        outline: none;
                        font-size: 13.5px;
                        color: #0f172a;
                    }
                    .ig-ps-toolbar {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 10px 14px;
                        border-bottom: 1px solid #e2e8f0;
                        background: #f1f5f9;
                        font-size: 13px;
                        color: #475569;
                        font-weight: 700;
                    }
                    .ig-ps-toolbar label { display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; }
                    .ig-ps-toolbar input[type=checkbox] { width: 17px; height: 17px; cursor: pointer; accent-color: #6366f1; }
                    #ig_ps_count { font-size: 13px; color: #6366f1; font-weight:700; }
                    .ig-ps-list {
                        max-height: 230px;
                        overflow-y: auto;
                        padding: 6px 0;
                    }
                    .ig-ps-item {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        padding: 10px 14px;
                        cursor: pointer;
                        transition: background 0.15s;
                        font-size: 13.5px;
                        color: #0f172a;
                    }
                    .ig-ps-item:hover { background: #f0f7ff; }
                    .ig-ps-item.ig-ps-checked { background: #eff6ff; }
                    .ig-ps-item input[type=checkbox] { width: 17px; height: 17px; flex-shrink: 0; accent-color: #6366f1; cursor: pointer; }
                    .ig-ps-item label { cursor: pointer; flex: 1; line-height: 1.35; margin: 0; font-weight: 600; }
                    .ig-ps-empty {
                        text-align: center;
                        padding: 24px;
                        color: #94a3b8;
                        font-size: 13px;
                        display: none;
                    }
                    </style>

                    <div class="ig-ps-wrapper">
                        <div class="ig-ps-search-bar">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="ig_ps_search" placeholder="Tìm kiếm fanpage..." autocomplete="off">
                            <button type="button" id="ig_ps_clear_search" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:0;display:none;">✕</button>
                        </div>
                        <div class="ig-ps-toolbar">
                            <label>
                                <input type="checkbox" id="ig_ps_select_all" checked> Chọn tất cả kênh
                            </label>
                            <span id="ig_ps_count">0 đã chọn</span>
                        </div>
                        <div class="ig-ps-list" id="ig_ps_list">
                            <div class="ig-ps-empty" id="ig_ps_empty">Không tìm thấy kênh nào</div>
                            <?php 
                            $preset_ig_id = $_GET['ig_id'] ?? '';
                            foreach ($ig_accounts as $idx => $acc): 
                            ?>
                                <?php 
                                $cb_id = 'ig_cb_' . htmlspecialchars($acc['ig_user_id']); 
                                $avatar_url = $acc['avatar'] ?? '';
                                $display_title = '@' . htmlspecialchars($acc['username']) . ' — ' . htmlspecialchars($acc['name']);
                                if (!empty($acc['followers_count'])) {
                                    $display_title .= ' (' . number_format($acc['followers_count']) . ' followers)';
                                }
                                $is_checked = empty($preset_ig_id) || ($preset_ig_id === $acc['ig_user_id']);
                                ?>
                                <div class="ig-ps-item <?= $is_checked ? 'ig-ps-checked' : ''; ?>" data-name="<?= htmlspecialchars(mb_strtolower($acc['username'] . ' ' . $acc['name'])); ?>">
                                    <input type="checkbox" id="<?= $cb_id; ?>" name="ig_user_ids[]" value="<?= htmlspecialchars($acc['ig_user_id']); ?>" <?= $is_checked ? 'checked' : ''; ?> class="ig-ch-cb">
                                    <?php if (!empty($avatar_url)): ?>
                                        <img src="<?= htmlspecialchars($avatar_url); ?>" style="width:28px; height:28px; border-radius:50%; object-fit:cover; flex-shrink:0;" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div style="width:28px; height:28px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:12px; color:#475569; font-weight:800; flex-shrink:0;">
                                            <?= strtoupper(substr($acc['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <label for="<?= $cb_id; ?>"><?= $display_title; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 2. Chọn Định Dạng -->
                <div class="form-group" style="margin-bottom:24px;">
                    <label style="font-weight:700; font-size:14px; color:#1e293b; display:block; margin-bottom:10px;">2. Chọn Định Dạng Bài Đăng Instagram:</label>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <label style="padding:12px 18px; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer; display:flex; align-items:center; gap:8px; background:#f8fafc; font-weight:700; font-size:13.5px; color:#334155;">
                            <input type="radio" name="post_sub_type" value="Instagram" checked onclick="switchIgPostType('photo')">
                            <span>🖼️ Bài Ảnh / Feed Photo</span>
                        </label>
                        <label style="padding:12px 18px; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer; display:flex; align-items:center; gap:8px; background:#f8fafc; font-weight:700; font-size:13.5px; color:#334155;">
                            <input type="radio" name="post_sub_type" value="Instagram_Reels" onclick="switchIgPostType('reels')">
                            <span>🎞️ Instagram Reels Video</span>
                        </label>
                        <label style="padding:12px 18px; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer; display:flex; align-items:center; gap:8px; background:#f8fafc; font-weight:700; font-size:13.5px; color:#334155;">
                            <input type="radio" name="post_sub_type" value="Instagram_Story" onclick="switchIgPostType('story_photo')">
                            <span>⭕ Instagram Story Ảnh</span>
                        </label>
                        <label style="padding:12px 18px; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer; display:flex; align-items:center; gap:8px; background:#f8fafc; font-weight:700; font-size:13.5px; color:#334155;">
                            <input type="radio" name="post_sub_type" value="Instagram_Story" onclick="switchIgPostType('story_video')">
                            <span>🎬 Instagram Story Video</span>
                        </label>
                    </div>
                </div>

                <!-- 3. Phương Tiện (Ảnh & Video) -->
                <div class="form-group" style="margin-bottom:24px;">
                    <label style="font-weight:700; font-size:14px; color:#1e293b; display:block; margin-bottom:10px;">3. Chọn Phương Tiện (Từ Máy, Google Drive hoặc Link TikTok):</label>
                    
                    <?php if ($disable_local_upload): ?>
                        <div style="padding: 12px 16px; background: #fef3cd; border: 1px solid #ffc107; border-radius: 10px; font-size: 13px; color: #856404; font-weight:700; margin-bottom: 12px;">
                            🔒 Admin đã tắt tính năng tải tệp trực tiếp từ máy tính. Vui lòng sử dụng Google Drive hoặc Kho Data.
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; gap: 12px; align-items: center; background: #f8fafc; padding: 16px; border: 1px dashed #cbd5e1; border-radius: 12px; flex-wrap:wrap;">
                        <?php if (!$disable_local_upload): ?>
                            <div id="photoInputWrap">
                                <input type="file" id="images" name="images[]" multiple accept="image/*" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; font-size:13px;" onchange="clearDriveSelection(); clearKhoDataSelection();">
                            </div>
                            <div id="videoInputWrap" style="display:none;">
                                <input type="file" id="video" name="video[]" multiple accept="video/mp4,video/x-m4v,video/*" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; font-size:13px;" onchange="if(this.files && this.files.length > 0) { clearDriveSelection(); clearKhoDataSelection(); }">
                            </div>
                            <div style="font-weight: 800; color: #64748b; font-size: 12px;">HOẶC</div>
                        <?php endif; ?>
                        <button type="button" class="btn-ig-secondary" onclick="openDriveModal('multiple')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                            Chọn từ Google Drive
                        </button>
                        <div id="khoDataBtnWrap" style="display:none;">
                            <?php include __DIR__ . '/includes/kho_data_selector.php'; ?>
                        </div>
                    </div>

                    <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 10px 14px; border-radius: 8px; font-size: 13px; font-weight:700;"></div>
                    <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 10px 14px; background: #e0f2fe; color: #0369a1; border-radius: 8px; font-size: 13px; font-weight:700;">
                        Đã chọn <strong id="driveSelectedCount">0</strong> file từ Drive. <span id="driveSelectedName"></span>
                        <button type="button" onclick="clearDriveSelection()" style="margin-left: 10px; background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-weight:bold;">Hủy</button>
                    </div>
                    <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
                </div>

                <!-- Auto title check for videos -->
                <div id="autoTitleBox" class="form-group" style="display:none; background: #fdf2f8; padding: 14px; border-radius: 10px; border: 1px dashed #fbcfe8; margin-bottom: 24px;">
                    <label style="color: #be185d; font-weight: 700; cursor:pointer; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="width:16px;height:16px;accent-color:#be185d;">
                        Tự động lấy Tên File / Tiêu đề TikTok làm Caption Instagram
                    </label>
                </div>

                <!-- Random photos option (Only for Photo Feed) -->
                <div id="randomPhotoOptions" class="form-group" style="background: #fff7ed; padding: 14px; border-radius: 10px; border: 1px dashed #fed7aa; margin-bottom: 24px;">
                    <label style="color: #c2410c; font-weight: 700; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="enable_random_images" name="enable_random_images" value="1" style="width:16px;height:16px;accent-color:#c2410c;">
                        🎲 Random lấy X ảnh từ danh sách đã chọn (Chỉ áp dụng cho Bài Ảnh Feed)
                    </label>
                    <div id="randomImagesBox" style="display: none; margin-top: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <label style="font-size: 13px; color: #9a3412; font-weight:700;">Số ảnh mỗi bài:</label>
                            <input type="number" id="random_image_count" name="random_image_count" value="5" min="1" max="50" style="width: 75px; padding: 6px; border: 1px solid #fed7aa; border-radius: 6px; font-weight: 800; text-align: center;">
                        </div>
                    </div>
                </div>

                <!-- Anti-duplicate & Delete Drive File option -->
                <div id="deleteDriveBox" class="form-group" style="background: #f0fdfa; padding: 16px; border-radius: 12px; border: 1px dashed #99f6e4; margin-bottom: 24px;">
                    <label style="color: #0d9488; font-weight: 800; display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 4px;">
                        <input type="checkbox" id="delete_drive_file" name="delete_drive_file" value="1" style="width: 17px; height: 17px; accent-color: #0d9488;">
                        🛡️ Chống trùng và xóa file đã đăng trên Google Drive
                    </label>
                    <p style="font-size: 12px; color: #0f766e; margin: 0;">
                        Khi chọn, nội dung đăng sẽ không trùng lặp và tự động xóa khỏi Google Drive sau khi bài phát hành thành công.
                    </p>
                </div>

                <!-- 4. Nội dung bài viết (Caption) -->
                <div class="form-group" style="margin-bottom:24px; position:relative;" id="captionSection">
                    <label style="display: flex; align-items: center; justify-content:space-between; font-weight:700; font-size:14px; color:#1e293b; margin-bottom:8px;">
                        <span>4. Nội dung Caption Instagram (Hashtag & Spin text):</span>
                        <button type="button" id="emojiTriggerIg" class="emoji-picker-trigger">😀 Chèn Emoji</button>
                    </label>
                    <div id="emojiPopupIg" class="emoji-picker-popup">
                        <div class="emoji-tabs"></div>
                        <div class="emoji-search-box"><input type="text" class="emoji-search-input" placeholder="Tìm emoji..."></div>
                        <div class="emoji-grid-wrap"></div>
                    </div>
                    <textarea id="caption" name="caption" rows="4" style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:10px; font-size:13.5px; box-sizing:border-box;" placeholder="Nhập mô tả bài viết và hashtag #instagram #reels..."></textarea>
                    <small style="color: #64748b; display:block; margin-top:6px; font-size:12px;">💡 Hỗ trợ Spin text: <code>{nội dung 1|nội dung 2|nội dung 3}</code> — hệ thống tự chọn ngẫu nhiên 1 phiên bản cho mỗi bài.</small>
                </div>

                <!-- AI rewrite option -->
                <div class="form-group" style="margin-bottom:24px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 700; cursor: pointer; color:#1e293b; font-size:13.5px;">
                        <input type="checkbox" name="use_ai" value="1" style="width: 18px; height: 18px; accent-color:#6366f1;">
                        🤖 Tự động viết lại nội dung với AI trước khi đăng (Dùng cấu hình AI đang kích hoạt)
                    </label>
                </div>

                <!-- 5. Lên Lịch & Tự Động Bình Luận -->
                <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:20px; margin-bottom:24px;">
                    <div style="background: #f8fafc; padding: 18px; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <label style="color: #4f46e5; font-weight:800; font-size:14px; display:block; margin-bottom:4px;">5. Lên lịch tự động hàng loạt (Tùy chọn)</label>
                        <p style="font-size: 12.5px; color: #64748b; margin-top: 0; margin-bottom: 12px;">
                            Nếu không nhập lịch, hệ thống sẽ đưa bài vào hàng chờ đăng ngay lập tức.
                        </p>
                        <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                            <div style="flex: 1;">
                                <label style="font-size: 12px; font-weight:700; color:#334155;">Từ ngày:</label>
                                <input type="date" id="start_date" name="start_date" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing:border-box;">
                            </div>
                            <div style="flex: 1;">
                                <label style="font-size: 12px; font-weight:700; color:#334155;">Đến ngày:</label>
                                <input type="date" id="end_date" name="end_date" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing:border-box;">
                            </div>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight:700; color:#334155;">Các khung giờ đăng mỗi ngày (Phân cách bởi dấu phẩy):</label>
                            <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing:border-box;">
                        </div>
                    </div>

                    <div style="background:#f0fdf4; padding:18px; border-radius:12px; border:1px solid #bbf7d0;">
                        <label style="color:#15803d; font-weight:800; font-size:14px; display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="enable_comment" id="enableComment" value="1" onchange="document.getElementById('commentBox').style.display=this.checked?'block':'none'" style="width:18px;height:18px;accent-color:#16a34a;">
                            💬 Tự động bình luận vào bài viết sau khi đăng (sau 120s)
                        </label>
                        <div id="commentBox" style="display:none; margin-top:12px;">
                            <label style="font-size:12px; color:#166534; font-weight:700;">Mỗi dòng = 1 nội dung bình luận (random 1 dòng):</label>
                            <textarea name="comment_lines" rows="3" placeholder="Bài viết tuyệt vời quá!&#10;Cảm ơn bạn đã chia sẻ!👍" style="width:100%; margin-top:6px; padding:10px; border:1px solid #86efac; border-radius:8px; font-size:13px; box-sizing:border-box;"></textarea>
                        </div>
                    </div>
                </div>

                <div id="postResult" style="display: none; margin-bottom: 20px; padding: 14px 18px; border-radius: 10px; font-weight:700;"></div>

                <div>
                    <button id="btnSubmitIg" class="ig-sync-btn" type="submit" style="padding:14px 36px; font-size:15px;">
                        🚀 Xác Nhận / Lên Lịch Đăng Bài Instagram
                    </button>
                </div>

            </form>
        <?php endif; ?>
    </div>

    <!-- TAB 3: BÀI ĐÃ ĐĂNG & INSIGHTS CHI TIẾT -->
    <?php else: ?>
    <div class="ig-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
            <h3 style="margin:0; font-weight:800; font-size:16px; color:#0f172a;">Thống kê bài viết đã đăng trên Instagram</h3>
            <div>
                <label style="font-weight:700; font-size:13px; margin-right:8px; color:#475569;">Chọn Kênh:</label>
                <select onchange="window.location.href='instagram.php?tab=media&ig_id='+this.value;" style="padding:9px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:13.5px; font-weight:700;">
                    <?php foreach ($ig_accounts as $acc): ?>
                        <option value="<?= htmlspecialchars($acc['ig_user_id']); ?>" <?= $selected_ig_id===$acc['ig_user_id']?'selected':''; ?>>
                            @<?= htmlspecialchars($acc['username']); ?> (<?= number_format($acc['followers_count']); ?> followers)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (empty($selected_ig_id) || empty($media_list)): ?>
            <div style="text-align:center; padding:48px 20px; color:#64748b;">
                <div style="font-size:36px; margin-bottom:8px;">📊</div>
                <div style="font-weight:700; font-size:15px; color:#1e293b;">Chưa có bài viết nào</div>
                <div style="font-size:13px; margin-top:4px;">Hoặc không tải được dữ liệu bài viết cho kênh Instagram được chọn.</div>
            </div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
                <?php foreach ($media_list as $m): 
                    $m_id = $m['id'];
                    $m_type = $m['media_type'] ?? 'IMAGE';
                    $m_url = $m['thumbnail_url'] ?? $m['media_url'] ?? '';
                    $m_caption = mb_strimwidth($m['caption'] ?? '', 0, 100, '…');
                    $m_link = $m['permalink'] ?? '#';
                    $m_likes = $m['like_count'] ?? 0;
                    $m_comments = $m['comments_count'] ?? 0;
                    $m_time = !empty($m['timestamp']) ? date('d/m/Y H:i', strtotime($m['timestamp'])) : '';
                ?>
                <div style="border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; background:#ffffff; display:flex; flex-direction:column; box-shadow:0 4px 15px rgba(0,0,0,0.04);">
                    <div style="position:relative; height:200px; background:#0f172a;">
                        <?php if ($m_type === 'VIDEO'): ?>
                            <video src="<?= htmlspecialchars($m['media_url'] ?? ''); ?>" style="width:100%; height:100%; object-fit:cover;" controls></video>
                        <?php elseif (!empty($m_url)): ?>
                            <img src="<?= htmlspecialchars($m_url); ?>" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                            <div style="height:100%; display:flex; align-items:center; justify-content:center; color:#ffffff; font-weight:700;">📸 Instagram Media</div>
                        <?php endif; ?>
                        <span style="position:absolute; top:8px; right:8px; background:rgba(15,23,42,0.85); color:#ffffff; font-size:10.5px; padding:3px 8px; border-radius:6px; font-weight:800; text-transform:uppercase;">
                            <?= htmlspecialchars($m_type); ?>
                        </span>
                    </div>
                    <div style="padding:16px; flex:1; display:flex; flex-direction:column; justify-content:space-between;">
                        <div style="font-size:12px; color:#64748b; margin-bottom:6px; font-weight:700;"><?= $m_time; ?></div>
                        <div style="font-size:13px; color:#0f172a; margin-bottom:12px; line-height:1.4; flex:1; font-weight:600;">
                            <?= htmlspecialchars($m_caption); ?>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:13px; border-top:1px solid #f1f5f9; padding-top:12px; margin-top:8px;">
                            <div style="display:flex; gap:12px; font-weight:800;">
                                <span style="color:#e11d48;">❤️ <?= number_format($m_likes); ?></span>
                                <span style="color:#2563eb;">💬 <?= number_format($m_comments); ?></span>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <a href="<?= htmlspecialchars($m_link); ?>" target="_blank" style="color:#4f46e5; text-decoration:none; font-weight:800; font-size:12.5px;">Xem →</a>
                                <a href="instagram.php?action=delete_media&ig_id=<?= urlencode($selected_ig_id); ?>&media_id=<?= urlencode($m_id); ?>" onclick="return confirm('Bạn có chắc muốn xóa bài viết này trên Instagram?');" style="color:#dc2626; text-decoration:none; font-weight:800; font-size:12.5px;">🗑 Xóa</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- JavaScript for Form Handling -->
<script>
(function() {
    const searchEl = document.getElementById('ig_ps_search');
    const clearBtn = document.getElementById('ig_ps_clear_search');
    const selectAllEl = document.getElementById('ig_ps_select_all');
    const countEl = document.getElementById('ig_ps_count');
    const listEl = document.getElementById('ig_ps_list');
    const emptyEl = document.getElementById('ig_ps_empty');
    if (!listEl) return;

    function updateIgCount() {
        const checkboxes = listEl.querySelectorAll('.ig-ch-cb');
        const checked = listEl.querySelectorAll('.ig-ch-cb:checked');
        const count = checked.length;
        
        if (countEl) {
            countEl.textContent = count + ' đã chọn';
            countEl.style.color = count > 0 ? '#6366f1' : '';
            countEl.style.fontWeight = count > 0 ? '700' : '';
        }
        if (selectAllEl) {
            selectAllEl.checked = checkboxes.length > 0 && count === checkboxes.length;
            selectAllEl.indeterminate = count > 0 && count < checkboxes.length;
        }
    }

    window.selectAllIgChannels = function(selectState) {
        listEl.querySelectorAll('.ig-ps-item').forEach(item => {
            const cb = item.querySelector('.ig-ch-cb');
            if (cb) {
                cb.checked = selectState;
                if (selectState) item.classList.add('ig-ps-checked');
                else item.classList.remove('ig-ps-checked');
            }
        });
        updateIgCount();
    };

    listEl.querySelectorAll('.ig-ps-item').forEach(item => {
        const cb = item.querySelector('.ig-ch-cb');
        cb?.addEventListener('change', function() {
            if (this.checked) item.classList.add('ig-ps-checked');
            else item.classList.remove('ig-ps-checked');
            updateIgCount();
        });

        item.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
            if (cb) {
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change'));
            }
        });
    });

    selectAllEl?.addEventListener('change', function() {
        const isChecked = this.checked;
        listEl.querySelectorAll('.ig-ps-item').forEach(item => {
            if (item.style.display !== 'none') {
                const cb = item.querySelector('.ig-ch-cb');
                if (cb) {
                    cb.checked = isChecked;
                    if (isChecked) item.classList.add('ig-ps-checked');
                    else item.classList.remove('ig-ps-checked');
                }
            }
        });
        updateIgCount();
    });

    searchEl?.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';
        
        let visibleCount = 0;
        listEl.querySelectorAll('.ig-ps-item').forEach(item => {
            const name = item.getAttribute('data-name') || item.textContent.toLowerCase();
            if (!q || name.includes(q)) {
                item.style.display = 'flex';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        if (emptyEl) emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
        updateIgCount();
    });

    clearBtn?.addEventListener('click', function() {
        if (searchEl) searchEl.value = '';
        this.style.display = 'none';
        searchEl?.dispatchEvent(new Event('input'));
        searchEl?.focus();
    });

    updateIgCount();
})();

function switchIgPostType(type) {
    const khoDataWrap = document.getElementById('khoDataBtnWrap');
    const autoTitle   = document.getElementById('autoTitleBox');
    const photoInput  = document.getElementById('photoInputWrap');
    const videoInput  = document.getElementById('videoInputWrap');
    const randomPhoto = document.getElementById('randomPhotoOptions');
    const imagesEl    = document.getElementById('images');

    if (type === 'reels') {
        if (khoDataWrap) khoDataWrap.style.display = 'flex';
        if (autoTitle) autoTitle.style.display = 'block';
        if (photoInput) photoInput.style.display = 'none';
        if (videoInput) videoInput.style.display = 'block';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else if (type === 'story_photo') {
        if (khoDataWrap) khoDataWrap.style.display = 'none';
        if (autoTitle) autoTitle.style.display = 'none';
        if (imagesEl) imagesEl.setAttribute('accept', 'image/*');
        if (photoInput) photoInput.style.display = 'block';
        if (videoInput) videoInput.style.display = 'none';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else if (type === 'story_video') {
        if (khoDataWrap) khoDataWrap.style.display = 'flex';
        if (autoTitle) autoTitle.style.display = 'none';
        if (photoInput) photoInput.style.display = 'none';
        if (videoInput) videoInput.style.display = 'block';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else {
        // photo feed
        if (khoDataWrap) khoDataWrap.style.display = 'none';
        if (autoTitle) autoTitle.style.display = 'none';
        if (imagesEl) imagesEl.setAttribute('accept', 'image/*');
        if (photoInput) photoInput.style.display = 'block';
        if (videoInput) videoInput.style.display = 'none';
        if (randomPhoto) randomPhoto.style.display = 'block';
    }
}

document.getElementById('enable_random_images')?.addEventListener('change', function() {
    const box = document.getElementById('randomImagesBox');
    if (box) box.style.display = this.checked ? 'block' : 'none';
});

function onDriveFilesSelected(files) {
    const idArray = files.map(f => f.id);
    const nameArray = files.map(f => f.name);
    
    document.getElementById('drive_file_id').value = idArray.join(',');
    const imgEl = document.getElementById('images');
    const vidEl = document.getElementById('video');
    if (imgEl) imgEl.value = '';
    if (vidEl) vidEl.value = '';
    
    document.getElementById('driveSelectedCount').innerText = idArray.length;
    let displayName = nameArray.length <= 3 ? nameArray.join(', ') : nameArray.slice(0, 3).join(', ') + ` và ${nameArray.length - 3} file khác`;
    document.getElementById('driveSelectedName').innerText = displayName;
    document.getElementById('driveSelectionInfo').style.display = 'block';
}

function onDriveFolderSelected(folderId, folderName) {
    document.getElementById('drive_file_id').value = 'folder:' + folderId;
    const imgEl = document.getElementById('images');
    const vidEl = document.getElementById('video');
    if (imgEl) imgEl.value = '';
    if (vidEl) vidEl.value = '';
    
    document.getElementById('driveSelectedCount').innerText = 'Thư mục';
    document.getElementById('driveSelectedName').innerText = folderName;
    document.getElementById('driveSelectionInfo').style.display = 'block';
}

function clearDriveSelection() {
    document.getElementById('drive_file_id').value = '';
    document.getElementById('driveSelectedCount').innerText = '0';
    document.getElementById('driveSelectedName').innerText = '';
    document.getElementById('driveSelectionInfo').style.display = 'none';

    const localStatus = document.getElementById('localUploadStatus');
    if (localStatus) {
        localStatus.style.display = 'none';
        localStatus.innerText = '';
    }
}

const isDisableLocalUpload = <?= $disable_local_upload ? 'true' : 'false'; ?>;

function uploadLocalFilesPromise(inputEl, progressCallback) {
    return new Promise((resolve, reject) => {
        if (!inputEl || !inputEl.files || inputEl.files.length === 0) {
            resolve(null);
            return;
        }

        const files = Array.from(inputEl.files);
        const uploadedResults = [];
        
        function uploadNext(index) {
            if (index >= files.length) {
                resolve(uploadedResults);
                return;
            }

            const file = files[index];
            if (progressCallback) {
                progressCallback(`⏳ Đang tải file ${index + 1}/${files.length} lên Google Drive: ${file.name}...`);
            }

            const formData = new FormData();
            formData.append('file', file);

            fetch('actions/drive_proxy.php?action=upload', {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const text = await response.text();
                if (!response.ok) {
                    throw new Error(`Tải file ${file.name} lên Google Drive thất bại: ${text}`);
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error(`Lỗi phản hồi từ server: ${text.substring(0, 300)}`);
                }
            })
            .then(data => {
                if (data.status === 'success' && data.files && data.files.length > 0) {
                    uploadedResults.push(...data.files);
                    uploadNext(index + 1);
                } else {
                    if (!isDisableLocalUpload) {
                        console.warn('Google Drive auto-upload skipped, falling back to direct upload:', data.msg);
                        resolve(null);
                    } else {
                        reject(data.msg || `Lỗi tải file ${file.name} lên Google Drive.`);
                    }
                }
            })
            .catch(error => {
                if (!isDisableLocalUpload) {
                    console.warn('Google Drive auto-upload skipped, falling back to direct upload:', error);
                    resolve(null);
                } else {
                    reject(error.message || error || `Lỗi kết nối khi tải file ${file.name}.`);
                }
            });
        }

        uploadNext(0);
    });
}

// Submit IG Form via AJAX
document.getElementById('igPublishForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitIg');
    const res = document.getElementById('postResult');
    const localStatus = document.getElementById('localUploadStatus');

    const checkedChannels = document.querySelectorAll('.ig-ch-cb:checked');
    if (checkedChannels.length === 0) {
        if (typeof window.showNotice === 'function') {
            window.showNotice('Vui lòng chọn ít nhất 1 kênh Instagram để đăng bài.', 'warning');
        } else {
            alert('Vui lòng chọn ít nhất 1 kênh Instagram để đăng bài.');
        }
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Đang xử lý...';
    res.style.display = 'none';

    if (localStatus) {
        localStatus.style.display = 'none';
        localStatus.innerText = '';
    }

    const activeInput = (document.getElementById('videoInputWrap')?.style.display !== 'none') 
        ? document.getElementById('video') 
        : document.getElementById('images');

    uploadLocalFilesPromise(activeInput, function(msg) {
        if (localStatus) {
            localStatus.style.display = 'block';
            localStatus.className = 'ig-alert ig-alert-warning';
            localStatus.innerText = msg;
        }
        btn.textContent = '⏳ Đang tải file lên Google Drive...';
    })
    .then(uploadedFiles => {
        if (uploadedFiles && uploadedFiles.length > 0) {
            if (localStatus) {
                localStatus.className = 'ig-alert ig-alert-success';
                localStatus.innerText = '✅ Tải tệp lên Google Drive thành công! Đang tiến hành tạo lịch đăng...';
            }
            const fileIds = uploadedFiles.map(f => f.id).join(',');
            document.getElementById('drive_file_id').value = fileIds;
            if (activeInput) activeInput.value = '';
        }

        btn.textContent = '🚀 Đang lưu thông tin bài đăng...';
        const formData = new FormData(document.getElementById('igPublishForm'));
        const kdGroup = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
        const kdMode = document.getElementById('data_mode') ? document.getElementById('data_mode').value.trim() : 'dedup';
        if (kdGroup) {
            formData.set('data_group_id', kdGroup);
            formData.set('data_mode', kdMode);
        }

        return fetch('actions/publish_instagram.php', {
            method: 'POST',
            body: formData
        });
    })
    .then(r => r.json())
    .then(data => {
        res.style.display = 'block';
        if (data.status === 'success') {
            res.className = 'ig-alert ig-alert-success';
            res.innerHTML = data.msg;
            if (data.redirect) {
                setTimeout(() => { window.location.href = data.redirect; }, 1500);
            }
        } else {
            res.className = 'ig-alert ig-alert-warning';
            res.innerHTML = data.msg;
        }
        btn.disabled = false;
        btn.textContent = '🚀 Xác Nhận / Lên Lịch Đăng Bài Instagram';
    })
    .catch(err => {
        res.style.display = 'block';
        res.className = 'ig-alert ig-alert-warning';
        res.innerHTML = 'Lỗi kết nối: ' + (err.message || err);
        btn.disabled = false;
        btn.textContent = '🚀 Xác Nhận / Lên Lịch Đăng Bài Instagram';
    });
});
</script>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/emoji_picker.php'; ?>
<script>
if (document.getElementById('emojiTriggerIg')) {
    initEmojiPicker('emojiTriggerIg', 'emojiPopupIg', 'caption');
}
</script>
<?php include 'includes/footer.php'; ?>
