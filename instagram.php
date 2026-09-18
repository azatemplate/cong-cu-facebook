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
/* Dashboard Stat Cards Style (8 Metrics Grid) */
.ig-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 1200px) {
    .ig-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 576px) {
    .ig-stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
}
.ig-stat-card {
    background: var(--card-bg, #ffffff);
    border-radius: 16px;
    padding: 16px 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    border: 1px solid var(--border-color, #e2e8f0);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 12px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.ig-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.06);
}
.ig-stat-card .card-top {
    display: flex;
    align-items: center;
    gap: 12px;
}
.ig-stat-card .icon-box {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
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
    font-size: 14px;
    font-weight: 600;
    color: var(--text-main, #1e293b);
}
.ig-stat-card .val {
    font-size: 28px;
    font-weight: 800;
    line-height: 1;
}

.text-pink   { color: #f43f5e; }
.text-purple { color: #a855f7; }
.text-orange { color: #f97316; }
.text-indigo { color: #6366f1; }
.text-amber  { color: #f59e0b; }
.text-red    { color: #ef4444; }
.text-rose   { color: #ec4899; }
.text-violet { color: #8b5cf6; }

/* Custom Tab Design */
.ig-nav-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 12px;
    flex-wrap: wrap;
}
.ig-tab-btn {
    padding: 10px 20px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.ig-tab-btn.active {
    background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366);
    color: white;
    box-shadow: 0 4px 12px rgba(220, 39, 67, 0.25);
}
.ig-tab-btn:not(.active) {
    background: var(--card-bg);
    color: var(--text-main);
    border: 1px solid var(--border-color);
}
.ig-tab-btn:not(.active):hover {
    background: #f1f5f9;
}
</style>

<div class="page-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <span style="font-size:22px; font-weight:700;">📸 Quản Lý Instagram Business & Insights</span>
        <div style="font-size:13px; color:var(--text-muted); font-weight:400; margin-top:2px;">
            Đăng bài Feed, Video Reels, Story & Xem thống kê hiệu suất kênh Instagram
        </div>
    </div>
    <div>
        <a href="instagram.php?action=sync" class="btn" style="background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); color:white; font-weight:600; text-decoration:none; padding:10px 18px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
            🔄 Đồng bộ kênh từ Fanpages
        </a>
    </div>
</div>

<?php if (isset($_GET['synced'])): ?>
    <div class="alert alert-success">
        ✅ Đã quét và đồng bộ thành công <strong><?php echo (int)$_GET['synced']; ?></strong> tài khoản Instagram Doanh nghiệp kết nối từ Facebook Pages!
    </div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
    <div class="alert alert-success">✅ Đã xóa hủy liên kết tài khoản Instagram thành công!</div>
<?php endif; ?>

<!-- ── TOP INSIGHTS SUMMARY CARDS (8 Metric Cards) ────────────────────── -->
<div class="ig-stats-grid">
    <!-- Row 1: Tài khoản & Tổng bài -->
    <div class="ig-stat-card">
        <div class="card-top">
            <div class="icon-box grad-ig-pink">📷</div>
            <div class="lbl">Tài khoản</div>
        </div>
        <div class="val text-pink"><?php echo number_format($total_ig_accounts) . ' / ' . $max_ig_display; ?></div>
    </div>

    <div class="ig-stat-card">
        <div class="card-top">
            <div class="icon-box grad-ig-purple">📄</div>
            <div class="lbl">Tổng bài</div>
        </div>
        <div class="val text-purple"><?php echo number_format($total_ig_posts); ?></div>
    </div>

    <!-- Row 2: Đã đăng & Đang chờ -->
    <div class="ig-stat-card">
        <div class="card-top">
            <div class="icon-box grad-ig-orange">🎯</div>
            <div class="lbl">Đã đăng</div>
        </div>
        <div class="val text-orange"><?php echo number_format($total_ig_published); ?></div>
    </div>

    <div class="ig-stat-card">
        <div class="card-top">
            <div class="icon-box grad-ig-indigo">🕒</div>
            <div class="lbl">Đang chờ</div>
        </div>
        <div class="val text-indigo"><?php echo number_format($total_ig_pending); ?></div>
    </div>

    <!-- Row 3: Đang đăng & Hỏng -->
    <div class="ig-stat-card">
        <div class="card-top">
            <div class="icon-box grad-ig-amber">🔄</div>
            <div class="lbl">Đang đăng</div>
        </div>
        <div class="val text-amber"><?php echo number_format($total_ig_processing); ?></div>
    </div>

    <div class="ig-stat-card">
        <div class="card-top">
            <div class="icon-box grad-ig-red">❌</div>
            <div class="lbl">Hỏng</div>
        </div>
        <div class="val text-red"><?php echo number_format($total_ig_failed); ?></div>
    </div>

    <!-- Row 4: Reel & Story -->
    <div class="ig-stat-card">
        <div class="card-top">
            <div class="icon-box grad-ig-rose">🎬</div>
            <div class="lbl">Reel</div>
        </div>
        <div class="val text-rose"><?php echo number_format($total_ig_reels); ?></div>
    </div>

    <div class="ig-stat-card">
        <div class="card-top">
            <div class="icon-box grad-ig-violet">🔖</div>
            <div class="lbl">Story</div>
        </div>
        <div class="val text-violet"><?php echo number_format($total_ig_stories); ?></div>
    </div>
</div>

<!-- ── NAVIGATION TABS ─────────────────────────────────────────────────── -->
<div class="ig-nav-tabs">
    <a href="instagram.php?tab=channels" class="ig-tab-btn <?php echo $active_tab==='channels'?'active':''; ?>">
        📌 Các Kênh Đã Đồng Bộ (<?php echo $total_ig_accounts; ?> / <?php echo $max_ig_display; ?>)
    </a>
    <a href="instagram.php?tab=create" class="ig-tab-btn <?php echo $active_tab==='create'?'active':''; ?>">
        🚀 Đăng Bài & Lên Lịch
    </a>
    <a href="instagram.php?tab=media<?php echo !empty($selected_ig_id) ? '&ig_id='.urlencode($selected_ig_id) : ''; ?>" class="ig-tab-btn <?php echo $active_tab==='media'?'active':''; ?>">
        📊 Bài Đăng & Insights Chi Tiết
    </a>
</div>

<!-- ── TAB 1: CÁC KÊNH ĐÃ ĐỒNG BỘ ───────────────────────────────────────── -->
<?php if ($active_tab === 'channels'): ?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
        <h3 style="margin:0;">Danh sách tài khoản Instagram Doanh nghiệp đã kết nối</h3>
        <a href="instagram.php?action=sync" class="btn btn-secondary" style="font-size:13px; display:inline-flex; align-items:center; gap:6px;">
            🔄 Làm mới / Đồng bộ từ Fanpage
        </a>
    </div>

    <?php if (empty($ig_accounts)): ?>
        <div style="text-align:center; padding:50px 20px;">
            <div style="font-size:48px; margin-bottom:12px;">📸</div>
            <h3 style="margin:0 0 8px;">Chưa có tài khoản Instagram nào</h3>
            <p style="color:var(--text-muted); font-size:14px; max-width:520px; margin:0 auto 20px;">
                Tài khoản Instagram của bạn cần chuyển sang loại <strong>Business / Creator</strong> và được nối với Facebook Page trong Cài đặt Trang. Bấm nút bên dưới để hệ thống tự quét & kết nối.
            </p>
            <a href="instagram.php?action=sync" class="btn btn-primary" style="padding:10px 22px; background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366); border:none;">
                🔄 Bắt đầu Đồng bộ Kênh Instagram
            </a>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; text-align:left; font-size:14px;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:2px solid var(--border-color);">
                        <th style="padding:12px 14px;">Tài khoản Instagram</th>
                        <th style="padding:12px 14px;">Trang Facebook Liên Kết</th>
                        <th style="padding:12px 14px; text-align:center;">Followers</th>
                        <th style="padding:12px 14px; text-align:center;">Trạng Thái API</th>
                        <th style="padding:12px 14px; text-align:right;">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ig_accounts as $acc): ?>
                    <tr style="border-bottom:1px solid var(--border-color);">
                        <td style="padding:14px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <?php if (!empty($acc['avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($acc['avatar']); ?>" style="width:44px; height:44px; border-radius:50%; object-fit:cover; border:2px solid #f9a8d4;">
                                <?php else: ?>
                                    <div style="width:44px; height:44px; border-radius:50%; background:linear-gradient(45deg, #f09433, #dc2743); color:white; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:18px;">📸</div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:700; color:var(--text-main); font-size:15px;">@<?php echo htmlspecialchars($acc['username']); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($acc['name']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="padding:14px; color:var(--text-muted);">
                            FB Page ID: <code><?php echo htmlspecialchars($acc['fb_page_id']); ?></code>
                        </td>
                        <td style="padding:14px; text-align:center; font-weight:700; color:#0284c7;">
                            <?php echo number_format($acc['followers_count']); ?>
                        </td>
                        <td style="padding:14px; text-align:center;">
                            <span style="background:#dcfce7; color:#15803d; font-size:12px; padding:4px 10px; border-radius:20px; font-weight:600;">
                                ✅ Sẵn sàng Đăng bài
                            </span>
                        </td>
                        <td style="padding:14px; text-align:right;">
                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                <a href="instagram.php?tab=create&ig_id=<?php echo urlencode($acc['ig_user_id']); ?>" class="btn btn-primary" style="padding:6px 12px; font-size:12px;">
                                    🚀 Đăng bài
                                </a>
                                <a href="instagram.php?tab=media&ig_id=<?php echo urlencode($acc['ig_user_id']); ?>" class="btn btn-secondary" style="padding:6px 12px; font-size:12px;">
                                    📊 Stats
                                </a>
                                <a href="instagram.php?action=delete_account&id=<?php echo $acc['id']; ?>" onclick="return confirm('Hủy kết nối kênh Instagram này?');" style="padding:6px 10px; font-size:12px; color:#dc2626; text-decoration:none; border:1px solid #fca5a5; border-radius:6px; background:#fef2f2;">
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

<!-- ── TAB 2: ĐĂNG BÀI & LÊN LỊCH INSTAGRAM ──────────────────────────────── -->
<?php elseif ($active_tab === 'create'): ?>
<div class="card">
    <h3 style="margin-bottom:15px; display:flex; align-items:center; gap:8px;">🚀 Tạo & Lên Lịch Bài Đăng Instagram</h3>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
        Chọn các kênh Instagram muốn đăng, định dạng Ảnh / Reels / Story, nội dung và các tùy chọn phương tiện từ Máy, Google Drive hoặc Link TikTok.
    </p>

    <?php if (empty($ig_accounts)): ?>
        <div style="text-align:center; padding:40px; background:#fff7ed; border:1px dashed #fdba74; border-radius:8px;">
            ⚠️ Chưa có tài khoản Instagram nào được đồng bộ. Vui lòng bấm <a href="instagram.php?action=sync" style="font-weight:bold; text-decoration:underline; color:#c2410c;">Vào đây để đồng bộ kênh</a> trước khi đăng bài.
        </div>
    <?php else: ?>
        <form id="igPublishForm" method="POST" action="actions/publish_instagram.php" enctype="multipart/form-data">
            
            <!-- 1. Chọn Kênh Instagram (Checkbox + Search UI) -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;">1. Chọn kênh Instagram muốn đăng bài:</label>
                
                <style>
                .ig-ps-wrapper {
                    border: 1px solid var(--border-color, #e2e8f0);
                    border-radius: 8px;
                    overflow: hidden;
                    background: var(--card-bg, #fff);
                }
                .ig-ps-search-bar {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    padding: 8px 12px;
                    border-bottom: 1px solid var(--border-color, #e2e8f0);
                    background: #f8fafc;
                }
                .ig-ps-search-bar svg { flex-shrink: 0; color: #94a3b8; }
                .ig-ps-search-bar input {
                    flex: 1;
                    border: none;
                    background: transparent;
                    outline: none;
                    font-size: 13px;
                    color: var(--text-main, #1e293b);
                }
                .ig-ps-search-bar input::placeholder { color: #94a3b8; }
                .ig-ps-toolbar {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 8px 12px;
                    border-bottom: 1px solid var(--border-color, #e2e8f0);
                    background: #f1f5f9;
                    font-size: 13px;
                    color: var(--text-muted, #64748b);
                }
                .ig-ps-toolbar label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; margin: 0; }
                .ig-ps-toolbar input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary-color, #2563eb); }
                #ig_ps_count { font-size: 13px; color: var(--text-muted, #64748b); }
                .ig-ps-list {
                    max-height: 220px;
                    overflow-y: auto;
                    padding: 4px 0;
                }
                .ig-ps-item {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 8px 12px;
                    cursor: pointer;
                    transition: background 0.12s;
                    font-size: 13px;
                    color: var(--text-main, #1e293b);
                }
                .ig-ps-item:hover { background: #f0f7ff; }
                .ig-ps-item.ig-ps-checked { background: #eff6ff; }
                .ig-ps-item input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; accent-color: var(--primary-color, #2563eb); cursor: pointer; }
                .ig-ps-item label { cursor: pointer; flex: 1; line-height: 1.35; margin: 0; }
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
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="ig_ps_search" placeholder="Tìm kiếm fanpage..." autocomplete="off">
                        <button type="button" id="ig_ps_clear_search" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:0;display:none;">✕</button>
                    </div>
                    <div class="ig-ps-toolbar">
                        <label>
                            <input type="checkbox" id="ig_ps_select_all" checked> Chọn tất cả
                        </label>
                        <span id="ig_ps_count">0 đã chọn</span>
                    </div>
                    <div class="ig-ps-list" id="ig_ps_list">
                        <div class="ig-ps-empty" id="ig_ps_empty">Không tìm thấy fanpage nào</div>
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
                            <div class="ig-ps-item <?php echo $is_checked ? 'ig-ps-checked' : ''; ?>" data-name="<?php echo htmlspecialchars(mb_strtolower($acc['username'] . ' ' . $acc['name'])); ?>">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="ig_user_ids[]" value="<?php echo htmlspecialchars($acc['ig_user_id']); ?>" <?php echo $is_checked ? 'checked' : ''; ?> class="ig-ch-cb">
                                <?php if (!empty($avatar_url)): ?>
                                    <img src="<?php echo htmlspecialchars($avatar_url); ?>" style="width:24px; height:24px; border-radius:50%; object-fit:cover; flex-shrink:0;" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div style="width:24px; height:24px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:11px; color:#64748b; font-weight:bold; flex-shrink:0;">
                                        <?php echo strtoupper(substr($acc['username'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <label for="<?php echo $cb_id; ?>"><?php echo $display_title; ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 2. Chọn Định Dạng -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;">2. Chọn Định Dạng Bài Đăng Instagram:</label>
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <label style="padding:10px 18px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px; background:#fafafa;">
                        <input type="radio" name="post_sub_type" value="Instagram" checked onclick="switchIgPostType('photo')">
                        <span>🖼️ Bài Ảnh / Feed Photo</span>
                    </label>
                    <label style="padding:10px 18px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px; background:#fafafa;">
                        <input type="radio" name="post_sub_type" value="Instagram_Reels" onclick="switchIgPostType('reels')">
                        <span>🎞️ Instagram Reels Video</span>
                    </label>
                    <label style="padding:10px 18px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px; background:#fafafa;">
                        <input type="radio" name="post_sub_type" value="Instagram_Story" onclick="switchIgPostType('story_photo')">
                        <span>⭕ Instagram Story Ảnh</span>
                    </label>
                    <label style="padding:10px 18px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px; background:#fafafa;">
                        <input type="radio" name="post_sub_type" value="Instagram_Story" onclick="switchIgPostType('story_video')">
                        <span>🎬 Instagram Story Video</span>
                    </label>
                </div>
            </div>

            <!-- 3. Phương Tiện (Ảnh & Video) -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;">3. Chọn Phương Tiện (Từ Máy, Google Drive hoặc Link TikTok):</label>
                
                <!-- TikTok Link Section (Hiện khi chọn Video / Reels) -->
                <div id="tiktokSection" style="display:none; background: #fdf2f8; padding: 14px; border-radius: 8px; border: 1px dashed #fbcfe8; margin-bottom: 14px;">
                    <label style="color: #be185d; font-weight: 600; font-size:13px;">Dán Link Video TikTok hàng loạt (Tự tải không logo & lấy Tiêu đề gốc):</label>
                    <textarea id="tiktok_urls" name="tiktok_urls" rows="3" placeholder="https://www.tiktok.com/@user/video/123456...&#10;https://www.tiktok.com/@user/video/789101..." style="width:100%; padding:8px 12px; border:1px solid #f9a8d4; border-radius:6px; font-size:13px; margin-top:6px; box-sizing:border-box;"></textarea>
                </div>

                <!-- Disable local upload warning if configured -->
                <?php if ($disable_local_upload): ?>
                    <div style="padding: 10px 15px; background: #fef3cd; border: 1px solid #ffc107; border-radius: 6px; font-size: 13px; color: #856404; margin-bottom: 10px;">
                        🔒 Admin đã tắt tính năng tải tệp trực tiếp từ máy tính. Vui lòng sử dụng Google Drive hoặc Link TikTok.
                    </div>
                <?php endif; ?>

                <!-- File upload or Drive selector -->
                <div style="display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 12px; border: 1px dashed var(--border-color); border-radius: 8px; flex-wrap:wrap;">
                    <?php if (!$disable_local_upload): ?>
                        <div id="photoInputWrap">
                            <input type="file" id="images" name="images[]" multiple accept="image/*" style="padding:6px; border:1px solid var(--border-color); border-radius:6px; background:#fff;" onchange="clearDriveSelection()">
                        </div>
                        <div id="videoInputWrap" style="display:none;">
                            <input type="file" id="video" name="video[]" multiple accept="video/mp4,video/x-m4v,video/*" style="padding:6px; border:1px solid var(--border-color); border-radius:6px; background:#fff;" onchange="clearDriveSelection()">
                        </div>
                        <div style="font-weight: bold; color: #64748b;">HOẶC</div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" onclick="openDriveModal('multiple')" style="background: #fff; border: 1px solid #cbd5e1; color: #334155; display: flex; align-items: center; gap: 6px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                        Chọn từ Google Drive
                    </button>
                </div>

                <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 8px 12px; border-radius: 4px; font-size: 13px;"></div>
                <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 8px 12px; background: #e0f2fe; color: #0369a1; border-radius: 4px; font-size: 13px;">
                    Đã chọn <strong id="driveSelectedCount">0</strong> file từ Drive. <span id="driveSelectedName"></span>
                    <button type="button" onclick="clearDriveSelection()" style="margin-left: 10px; background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline;">Hủy</button>
                </div>
                <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
            </div>

            <!-- Auto title check for videos -->
            <div id="autoTitleBox" class="form-group" style="display:none; background: #fdf2f8; padding: 12px; border-radius: 6px; border: 1px dashed #fbcfe8; margin-bottom: 20px;">
                <label style="color: #be185d; font-weight: 500; cursor:pointer;">
                    <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="margin-right: 6px;">
                    Tự động lấy Tên File / Tiêu đề TikTok làm Caption Instagram
                </label>
            </div>

            <!-- Random photos option (Only for Photo Feed) -->
            <div id="randomPhotoOptions" class="form-group" style="background: #fff7ed; padding: 12px; border-radius: 6px; border: 1px dashed #fed7aa; margin-bottom: 20px;">
                <label style="color: #c2410c; font-weight: 500; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" id="enable_random_images" name="enable_random_images" value="1">
                    🎲 Random lấy X ảnh từ danh sách đã chọn (Chỉ áp dụng cho Bài Ảnh)
                </label>
                <div id="randomImagesBox" style="display: none; margin-top: 8px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label style="font-size: 13px; color: #9a3412;">Số ảnh mỗi bài:</label>
                        <input type="number" id="random_image_count" name="random_image_count" value="5" min="1" max="50" style="width: 70px; padding: 6px; border: 1px solid #fed7aa; border-radius: 4px; font-weight: bold; text-align: center;">
                    </div>
                </div>
            </div>

            <!-- Anti-duplicate & Delete Drive File option (For both Photos and Videos/Reels) -->
            <div id="deleteDriveBox" class="form-group" style="background: #f0fdfa; padding: 14px; border-radius: 6px; border: 1px dashed #99f6e4; margin-bottom: 20px;">
                <label style="color: #0d9488; font-weight: 600; display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 0;">
                    <input type="checkbox" id="delete_drive_file" name="delete_drive_file" value="1" style="width: 16px; height: 16px; accent-color: #0d9488;">
                    🛡️ Chống trùng và xóa file đã đăng drive
                </label>
                <p style="font-size: 12px; color: #0f766e; margin-top: 5px; margin-bottom: 0;">
                    Khi chọn, nội dung đăng sẽ không trùng lặp và tự động xóa khỏi Google Drive sau khi bài được phát hành thành công.
                </p>
            </div>

            <!-- 4. Nội dung bài viết (Caption) -->
            <div class="form-group" style="margin-bottom:20px; position:relative;" id="captionSection">
                <label style="display: flex; align-items: center; gap: 8px; font-weight:600; margin-bottom:8px;">
                    4. Nội dung Caption Instagram (Hashtag & Spin text):
                    <button type="button" id="emojiTriggerIg" class="emoji-picker-trigger">😀 Emoji</button>
                </label>
                <div id="emojiPopupIg" class="emoji-picker-popup">
                    <div class="emoji-tabs"></div>
                    <div class="emoji-search-box"><input type="text" class="emoji-search-input" placeholder="Tìm emoji..."></div>
                    <div class="emoji-grid-wrap"></div>
                </div>
                <textarea id="caption" name="caption" rows="4" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:8px; font-size:14px; box-sizing:border-box;" placeholder="Nhập mô tả bài viết và hashtag #instagram #reels..."></textarea>
                <small style="color: #64748b; display:block; margin-top:4px;">💡 Hỗ trợ Spin: <code>{nội dung 1|nội dung 2|nội dung 3}</code> — hệ thống sẽ chọn ngẫu nhiên 1 phiên bản mỗi bài đăng.</small>
            </div>

            <!-- AI rewrite option -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                    <input type="checkbox" name="use_ai" value="1" style="width: 17px; height: 17px;">
                    🤖 Tự động viết lại nội dung với AI trước khi đăng (Sử dụng cấu hình AI đang kích hoạt)
                </label>
            </div>

            <!-- 5. Lên Lịch & Tự Động Bình Luận -->
            <div style="display:flex; gap:16px; align-items:stretch; flex-wrap:wrap; margin-bottom:20px;">
                <div style="flex:1; min-width:300px; background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <label style="color: var(--primary-color); font-weight:600;">5. Lên lịch tự động hàng loạt (Tùy chọn)</label>
                    <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px; margin-bottom: 12px;">
                        Chọn khoảng ngày và các khung giờ, tối đa hẹn giờ 3 tháng một chiến dịch.
                    </p>
                    <div style="display: flex; gap: 12px; margin-bottom: 10px;">
                        <div style="flex: 1;">
                            <label style="font-size: 12px;">Từ ngày:</label>
                            <input type="date" id="start_date" name="start_date" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; box-sizing:border-box;">
                        </div>
                        <div style="flex: 1;">
                            <label style="font-size: 12px;">Đến ngày:</label>
                            <input type="date" id="end_date" name="end_date" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; box-sizing:border-box;">
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 12px;">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                        <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; box-sizing:border-box;">
                    </div>
                </div>

                <div style="flex:1; min-width:300px; background:#f0fdf4; padding:15px; border-radius:8px; border:1px solid #bbf7d0;">
                    <label style="color:#15803d; font-weight:600; display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="enable_comment" id="enableComment" value="1" onchange="document.getElementById('commentBox').style.display=this.checked?'block':'none'" style="width:16px;height:16px;accent-color:#16a34a;">
                        💬 Bình luận vào bài viết sau khi đăng (sau 120 giây)
                    </label>
                    <div id="commentBox" style="display:none; margin-top:10px;">
                        <label style="font-size:12px; color:#166534;">Mỗi dòng = 1 nội dung bình luận (random 1 dòng):</label>
                        <textarea name="comment_lines" rows="3" placeholder="Bài viết tuyệt vời quá!&#10;Cảm ơn bạn đã chia sẻ!👍" style="width:100%; margin-top:4px; padding:8px; border:1px solid #86efac; border-radius:6px; font-size:13px; box-sizing:border-box;"></textarea>
                    </div>
                </div>
            </div>

            <div id="postResult" style="display: none; margin-bottom: 15px; padding: 12px; border-radius: 6px;"></div>

            <div>
                <button id="btnSubmitIg" class="btn btn-primary" type="submit" style="padding:12px 30px; font-size:15px; font-weight:600; background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366); border:none;">
                    🚀 Xác Nhận / Lên Lịch Đăng Bài Instagram
                </button>
            </div>

        </form>
    <?php endif; ?>
</div>

<!-- ── TAB 3: BÀI ĐÃ ĐĂNG & INSIGHTS CHI TIẾT ───────────────────────────── -->
<?php else: ?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
        <h3 style="margin:0;">Thống kê bài viết đã đăng trên Instagram</h3>
        <div>
            <label style="font-weight:600; font-size:13px; margin-right:8px;">Chọn Kênh:</label>
            <select onchange="window.location.href='instagram.php?tab=media&ig_id='+this.value;" style="padding:8px 14px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; font-weight:600;">
                <?php foreach ($ig_accounts as $acc): ?>
                    <option value="<?php echo htmlspecialchars($acc['ig_user_id']); ?>" <?php echo $selected_ig_id===$acc['ig_user_id']?'selected':''; ?>>
                        @<?php echo htmlspecialchars($acc['username']); ?> (<?php echo number_format($acc['followers_count']); ?> followers)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (empty($selected_ig_id) || empty($media_list)): ?>
        <div style="text-align:center; padding:40px; color:var(--text-muted);">
            Chưa có bài viết nào hoặc không tải được dữ liệu bài viết cho kênh Instagram này.
        </div>
    <?php else: ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:18px;">
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
            <div style="border:1px solid var(--border-color); border-radius:12px; overflow:hidden; background:var(--card-bg); display:flex; flex-direction:column; box-shadow:0 2px 10px rgba(0,0,0,0.03);">
                <div style="position:relative; height:200px; background:#000;">
                    <?php if ($m_type === 'VIDEO'): ?>
                        <video src="<?php echo htmlspecialchars($m['media_url'] ?? ''); ?>" style="width:100%; height:100%; object-fit:cover;" controls></video>
                    <?php elseif (!empty($m_url)): ?>
                        <img src="<?php echo htmlspecialchars($m_url); ?>" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                        <div style="height:100%; display:flex; align-items:center; justify-content:center; color:#fff;">📸 Instagram Media</div>
                    <?php endif; ?>
                    <span style="position:absolute; top:8px; right:8px; background:rgba(0,0,0,0.75); color:#fff; font-size:11px; padding:3px 8px; border-radius:4px; font-weight:600;">
                        <?php echo htmlspecialchars($m_type); ?>
                    </span>
                </div>
                <div style="padding:14px; flex:1; display:flex; flex-direction:column; justify-content:space-between;">
                    <div style="font-size:12px; color:var(--text-muted); margin-bottom:6px;"><?php echo $m_time; ?></div>
                    <div style="font-size:13px; color:var(--text-main); margin-bottom:12px; line-height:1.4; flex:1;">
                        <?php echo htmlspecialchars($m_caption); ?>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:13px; border-top:1px solid var(--border-color); padding-top:10px; margin-top:8px;">
                        <div style="display:flex; gap:12px; font-weight:600;">
                            <span>❤️ <?php echo number_format($m_likes); ?></span>
                            <span>💬 <?php echo number_format($m_comments); ?></span>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <a href="<?php echo htmlspecialchars($m_link); ?>" target="_blank" style="color:var(--primary-color); text-decoration:none; font-weight:600;">Xem →</a>
                            <a href="instagram.php?action=delete_media&ig_id=<?php echo urlencode($selected_ig_id); ?>&media_id=<?php echo urlencode($m_id); ?>" onclick="return confirm('Bạn có chắc muốn xóa bài viết này trên Instagram?');" style="color:#dc2626; text-decoration:none; font-weight:600;">🗑 Xóa</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── JAVASCRIPT FOR FORM HANDLING ──────────────────────────────────────── -->
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
            countEl.style.color = count > 0 ? 'var(--primary-color, #2563eb)' : '';
            countEl.style.fontWeight = count > 0 ? '600' : '';
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
    const tiktokSec  = document.getElementById('tiktokSection');
    const autoTitle  = document.getElementById('autoTitleBox');
    const photoInput = document.getElementById('photoInputWrap');
    const videoInput = document.getElementById('videoInputWrap');
    const randomPhoto= document.getElementById('randomPhotoOptions');
    const imagesEl   = document.getElementById('images');

    // Make TikTok section always visible across format tabs (like reels.php)
    if (tiktokSec) tiktokSec.style.display = 'block';

    if (type === 'reels') {
        if (autoTitle) autoTitle.style.display = 'block';
        if (photoInput) photoInput.style.display = 'none';
        if (videoInput) videoInput.style.display = 'block';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else if (type === 'story_photo') {
        if (autoTitle) autoTitle.style.display = 'none';
        if (imagesEl) imagesEl.setAttribute('accept', 'image/*');
        if (photoInput) photoInput.style.display = 'block';
        if (videoInput) videoInput.style.display = 'none';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else if (type === 'story_video') {
        if (autoTitle) autoTitle.style.display = 'none';
        if (photoInput) photoInput.style.display = 'none';
        if (videoInput) videoInput.style.display = 'block';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else {
        // photo feed
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
    if (document.getElementById('drive_file_names')) {
        document.getElementById('drive_file_names').value = nameArray.join('|||');
    }
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
    if (document.getElementById('drive_file_names')) {
        document.getElementById('drive_file_names').value = 'folder:' + folderName;
    }
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
    if (document.getElementById('drive_file_names')) {
        document.getElementById('drive_file_names').value = '';
    }
    document.getElementById('driveSelectedCount').innerText = '0';
    document.getElementById('driveSelectedName').innerText = '';
    document.getElementById('driveSelectionInfo').style.display = 'none';

    const localStatus = document.getElementById('localUploadStatus');
    if (localStatus) {
        localStatus.style.display = 'none';
        localStatus.innerText = '';
    }
}

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
                    reject(data.msg || `Lỗi tải file ${file.name} lên Google Drive.`);
                }
            })
            .catch(error => {
                reject(error.message || error || `Lỗi kết nối khi tải file ${file.name}.`);
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
        alert('Vui lòng chọn ít nhất 1 kênh Instagram để đăng bài.');
        return;
    }

    const imgEl = document.getElementById('images');
    const vidEl = document.getElementById('video');
    const isVideoTab = document.getElementById('videoInputWrap')?.style.display !== 'none';
    const activeInput = isVideoTab ? vidEl : imgEl;
    const driveFileId = document.getElementById('drive_file_id')?.value || '';
    const tiktokUrls = document.getElementById('tiktok_urls')?.value || '';

    const hasPhotoFiles = imgEl && imgEl.files && imgEl.files.length > 0;
    const hasVideoFiles = vidEl && vidEl.files && vidEl.files.length > 0;
    const inputToUpload = hasVideoFiles ? vidEl : (hasPhotoFiles ? imgEl : activeInput);
    const hasLocalFiles = hasPhotoFiles || hasVideoFiles;

    if (!hasLocalFiles && !driveFileId && !tiktokUrls) {
        alert('Vui lòng cung cấp phương tiện: chọn ít nhất 1 hình ảnh/video từ máy, Google Drive hoặc dán link TikTok.');
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Đang xử lý...';
    res.style.display = 'none';

    if (localStatus) {
        localStatus.style.display = 'none';
        localStatus.innerText = '';
    }

    uploadLocalFilesPromise(inputToUpload, function(msg) {
        if (localStatus) {
            localStatus.style.display = 'block';
            localStatus.className = 'alert alert-warning';
            localStatus.style.background = '#fef3cd';
            localStatus.style.color = '#856404';
            localStatus.style.border = '1px solid #ffeeba';
            localStatus.innerText = msg;
        }
        btn.textContent = '⏳ Đang tải file lên Google Drive...';
    })
    .then(uploadedFiles => {
        if (uploadedFiles && uploadedFiles.length > 0) {
            if (localStatus) {
                localStatus.className = 'alert alert-success';
                localStatus.style.background = '#d4edda';
                localStatus.style.color = '#155724';
                localStatus.style.border = '1px solid #c3e6cb';
                localStatus.innerText = '✅ Tải tệp lên Google Drive thành công! Đang tiến hành tạo lịch đăng...';
            }
            const fileIds = uploadedFiles.map(f => f.id).join(',');
            document.getElementById('drive_file_id').value = fileIds;
            if (inputToUpload) inputToUpload.value = '';
        }

        btn.textContent = '🚀 Đang lưu thông tin bài đăng...';
        const formData = new FormData(document.getElementById('igPublishForm'));

        return fetch('actions/publish_instagram.php', {
            method: 'POST',
            body: formData
        });
    })
    .then(r => r.json())
    .then(data => {
        res.style.display = 'block';
        if (data.status === 'success') {
            res.className = 'alert alert-success';
            res.innerHTML = data.msg;
            if (data.redirect) {
                setTimeout(() => { window.location.href = data.redirect; }, 1500);
            }
        } else {
            res.className = 'alert alert-danger';
            res.innerHTML = data.msg;
        }
        btn.disabled = false;
        btn.textContent = '🚀 Xác Nhận / Lên Lịch Đăng Bài Instagram';
    })
    .catch(err => {
        res.style.display = 'block';
        res.className = 'alert alert-danger';
        res.innerHTML = 'Lỗi kết nối: ' + (err.message || err);
        btn.disabled = false;
        btn.textContent = '🚀 Xác Nhận / Lên Lịch Đăng Bài Instagram';
    });
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    if (startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            if (this.value) {
                const startDate = new Date(this.value);
                const maxDate = new Date(startDate);
                maxDate.setDate(maxDate.getDate() + 90);
                
                const maxStr = maxDate.toISOString().split('T')[0];
                endDateInput.min = this.value;
                endDateInput.max = maxStr;
                
                if (endDateInput.value && (endDateInput.value < this.value || endDateInput.value > maxStr)) {
                    endDateInput.value = maxStr;
                }
            } else {
                endDateInput.removeAttribute('min');
                endDateInput.removeAttribute('max');
            }
        });
    }
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
