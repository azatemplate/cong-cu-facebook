<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];
$is_admin   = (($_SESSION['role'] ?? '') === 'admin');

// Fetch system account youtube_multi_api setting and max limit
$stmt_acc = $pdo->prepare("SELECT youtube_multi_api, max_yt_channels FROM system_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$acc_info = $stmt_acc->fetch(PDO::FETCH_ASSOC);
$youtube_multi_api = (!empty($acc_info['youtube_multi_api']) || $is_admin) ? 1 : 0;
$max_yt_channels = intval($acc_info['max_yt_channels'] ?? 10);
$max_yt_display = $is_admin ? '&infin;' : number_format($max_yt_channels);

// Handle POST actions for channel API editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_api') {
    verify_csrf();
    if ($youtube_multi_api === 1) {
        $channel_id_db = intval($_POST['channel_id_db']);
        $gg_client_id = trim($_POST['gg_client_id'] ?? '');
        $gg_client_secret = trim($_POST['gg_client_secret'] ?? '');
        
        $upd_stmt = $pdo->prepare("UPDATE youtube_channels SET gg_client_id = ?, gg_client_secret = ? WHERE id = ? AND account_id = ?");
        $upd_stmt->execute([
            empty($gg_client_id) ? null : $gg_client_id,
            empty($gg_client_secret) ? null : $gg_client_secret,
            $channel_id_db,
            $account_id
        ]);
        $_SESSION['flash_msg'] = "Cập nhật cấu hình Google API của kênh thành công!";
    } else {
        $_SESSION['flash_msg'] = "Bạn không có quyền thực hiện tính năng này.";
    }
    header("Location: youtube.php?tab=channels");
    exit;
}

// Handle GET delete
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $pdo->prepare("DELETE FROM youtube_channels WHERE id = ? AND account_id = ?")->execute([$del_id, $account_id]);
    $_SESSION['flash_msg'] = "Đã xóa kênh YouTube thành công.";
    header("Location: youtube.php?tab=channels");
    exit;
}

$current_page = 'youtube';
require_once __DIR__ . '/includes/header.php';

// Đọc cấu hình giới hạn upload
$disable_local_upload = false;
try {
    $stmt_upload = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'disable_local_upload'");
    $stmt_upload->execute();
    $row_upload = $stmt_upload->fetch(PDO::FETCH_ASSOC);
    if ($row_upload && $row_upload['setting_value'] === '1' && !$is_admin) $disable_local_upload = true;
} catch (Exception $e) {}

// Get all linked YouTube channels for this account
$stmt = $pdo->prepare("SELECT * FROM youtube_channels WHERE account_id = ? ORDER BY created_at DESC");
$stmt->execute([$account_id]);
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

$channels_json = json_encode($channels);
$active_tab = $_GET['tab'] ?? 'scheduler';
$is_yt_limit_reached = (!$is_admin && count($channels) >= $max_yt_channels);
?>

<style>
    /* evondev UI/UX Design System */
    .container, button, input, select, textarea {
        font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    }

    .yt-hero {
        background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
        border: 1px solid rgba(99, 102, 241, 0.25);
        border-radius: 16px;
        padding: 24px 28px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 20px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
    }

    .yt-hero-text h2 {
        font-size: 22px;
        font-weight: 700;
        color: #ffffff;
        margin: 0 0 4px;
        letter-spacing: -0.02em;
    }

    .yt-hero-text p {
        font-size: 13px;
        color: #94a3b8;
        margin: 0;
    }

    .btn-evon-primary {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: #ffffff;
        font-weight: 600;
        border: none;
        border-radius: 10px;
        padding: 10px 20px;
        font-size: 13px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: transform .15s ease, box-shadow .15s ease, opacity .15s;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
    }

    .btn-evon-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.45);
        color: #ffffff;
    }

    .nav-tabs-custom {
        display: flex;
        gap: 10px;
        margin-bottom: 24px;
        border-bottom: 2px solid var(--border-color, #e2e8f0);
        padding-bottom: 4px;
    }

    .nav-tab-link {
        padding: 10px 20px;
        border-radius: 10px 10px 0 0;
        font-weight: 600;
        font-size: 14px;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
        color: var(--text-muted, #64748b);
    }

    .nav-tab-link:hover {
        color: var(--text-main, #1e293b);
        background: rgba(99, 102, 241, 0.05);
    }

    .nav-tab-link.active {
        background: rgba(99, 102, 241, 0.12);
        color: #4f46e5;
        border-bottom: 3px solid #6366f1;
    }

    .evon-card {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .form-group-custom {
        margin-bottom: 20px;
    }

    .form-group-custom label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--text-main, #1e293b);
        margin-bottom: 8px;
    }

    .form-group-custom input[type="text"],
    .form-group-custom textarea {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--border-color, #cbd5e1);
        border-radius: 10px;
        background: var(--card-bg, #ffffff);
        color: var(--text-main, #1e293b);
        font-size: 14px;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
        box-sizing: border-box;
    }

    .form-group-custom input[type="text"]:focus,
    .form-group-custom textarea:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .yt-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }
    .yt-meta-card {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #cbd5e1);
        border-radius: 12px;
        padding: 14px 16px;
    }
    .yt-meta-card label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: var(--text-main, #1e293b);
        margin-bottom: 8px;
    }
    .yt-meta-card select {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid var(--border-color, #cbd5e1);
        border-radius: 8px;
        background: var(--card-bg, #ffffff);
        color: var(--text-main, #1e293b);
        font-size: 13px;
        outline: none;
        cursor: pointer;
    }
</style>

<div class="yt-hero">
    <div class="yt-hero-text">
        <h2>Hệ Thống Đăng & Quản Lý Kênh YouTube</h2>
        <p>Tự động hoá lên lịch đăng video, quản lý API đa kênh và seeding bình luận tự động.</p>
    </div>
    <?php if ($active_tab === 'channels'): ?>
        <?php if ($is_yt_limit_reached): ?>
            <button onclick="alert('⚠️ Tài khoản của bạn đã đạt/vượt giới hạn tối đa <?php echo $max_yt_channels; ?> Kênh YouTube. Vui lòng liên hệ Admin để nâng cấp hạn ngạch!')" class="btn-evon-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
                Thêm kênh YouTube mới
            </button>
        <?php elseif ($youtube_multi_api === 1): ?>
            <button onclick="openAddChannelModal()" class="btn-evon-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
                Thêm kênh YouTube mới
            </button>
        <?php else: ?>
            <a href="youtube_login.php" class="btn-evon-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
                Thêm kênh YouTube mới
            </a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Navigation Tabs -->
<div class="nav-tabs-custom">
    <a href="youtube.php?tab=scheduler" class="nav-tab-link <?php echo ($active_tab === 'scheduler') ? 'active' : ''; ?>">
        🚀 Đăng Video YouTube
    </a>
    <a href="youtube.php?tab=channels" class="nav-tab-link <?php echo ($active_tab === 'channels') ? 'active' : ''; ?>">
        📺 Quản Lý Kênh YouTube (<?php echo count($channels); ?> / <?php echo $max_yt_display; ?>)
    </a>
</div>

<?php 
$display_flash = $global_flash_msg ?: ($_SESSION['flash_msg'] ?? '');
if (!empty($display_flash)): 
    $is_warning = (strpos($display_flash, '⚠️') !== false || strpos($display_flash, 'đạt/vượt') !== false || strpos($display_flash, 'giới hạn') !== false);
    $alert_bg = $is_warning ? 'rgba(245,158,11,0.15)' : 'rgba(99,102,241,0.15)';
    $alert_border = $is_warning ? 'rgba(245,158,11,0.3)' : 'rgba(99,102,241,0.3)';
    $alert_color = $is_warning ? '#fbbf24' : '#818cf8';
?>
    <div class="alert" style="margin-bottom: 24px; padding:14px 18px; border-radius:12px; background:<?php echo $alert_bg; ?>; border:1px solid <?php echo $alert_border; ?>; color:<?php echo $alert_color; ?>; font-weight:600; display:flex; align-items:center; gap:8px;">
        <span><?php echo htmlspecialchars($display_flash); ?></span>
    </div>
    <?php if (isset($_SESSION['flash_msg'])) unset($_SESSION['flash_msg']); ?>
<?php endif; ?>

<?php if ($active_tab === 'channels'): ?>
    <!-- TAB QUẢN LÝ KÊNH YOUTUBE -->
    <div class="evon-card" style="padding:0; overflow:hidden;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size:13px;">
            <thead>
                <tr style="background:var(--bg-color, #f8fafc); border-bottom:1px solid var(--border-color, #e2e8f0); color:var(--text-muted, #64748b); font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:0.5px;">
                    <th style="padding: 14px; text-align: left;">Kênh</th>
                    <th style="padding: 14px; text-align: left;">Tên kênh</th>
                    <?php if ($youtube_multi_api === 1): ?>
                        <th style="padding: 14px; text-align: left;">Cấu hình API</th>
                    <?php endif; ?>
                    <th style="padding: 14px; text-align: left;">Thêm lúc</th>
                    <th style="padding: 14px; text-align: right;">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($channels) > 0): ?>
                    <?php foreach ($channels as $channel): ?>
                        <tr style="border-bottom: 1px solid var(--border-color, #e2e8f0); transition:background 0.15s;" onmouseover="this.style.background='rgba(99,102,241,0.06)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 14px;">
                                <?php if ($channel['channel_avatar']): ?>
                                    <img src="<?php echo htmlspecialchars($channel['channel_avatar']); ?>" alt="Avatar" style="width: 42px; height: 42px; border-radius: 50%; border:1px solid var(--border-color, #e2e8f0);">
                                <?php else: ?>
                                    <div style="width: 42px; height: 42px; border-radius: 50%; background: #4f46e5; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #fff;">YT</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 14px;">
                                <strong style="color:var(--text-main, #1e293b); font-size:14px;"><?php echo htmlspecialchars($channel['channel_title']); ?></strong><br>
                                <small style="color: var(--text-muted, #64748b);"><?php echo htmlspecialchars($channel['channel_id']); ?></small>
                            </td>
                            <?php if ($youtube_multi_api === 1): ?>
                                <td style="padding: 14px;">
                                    <?php if (!empty($channel['gg_client_id'])): ?>
                                        <span class="status-tag" style="background: rgba(14,165,233,0.15); color: #0284c7; font-size: 11px; padding: 3px 8px; border-radius: 12px; font-weight: 600;">API Riêng</span><br>
                                        <small style="color: var(--text-muted, #64748b); font-size: 11px; word-break: break-all;">ID: <?php echo htmlspecialchars(substr($channel['gg_client_id'], 0, 15)); ?>...</small>
                                    <?php else: ?>
                                        <span class="status-tag" style="background: rgba(100,116,139,0.12); color: var(--text-muted, #64748b); font-size: 11px; padding: 3px 8px; border-radius: 12px; font-weight: 500;">Mặc định</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td style="padding: 14px; color:var(--text-muted, #64748b);">
                                <?php echo date('d/m/Y H:i', strtotime($channel['created_at'])); ?>
                            </td>
                            <td style="padding: 14px; text-align: right;">
                                <?php if ($youtube_multi_api === 1): ?>
                                    <button onclick="openEditApiModal(<?php echo $channel['id']; ?>, '<?php echo htmlspecialchars($channel['channel_title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($channel['gg_client_id'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($channel['gg_client_secret'] ?? '', ENT_QUOTES); ?>')" style="background: rgba(99,102,241,0.15); color: #4f46e5; border: 1px solid rgba(99,102,241,0.3); padding: 6px 12px; font-size: 12px; font-weight:600; margin-right: 6px; border-radius: 8px; cursor: pointer;">Sửa API</button>
                                <?php endif; ?>
                                
                                <?php if ($youtube_multi_api === 1 && !empty($channel['gg_client_id'])): ?>
                                    <a href="youtube_login.php?reauth_channel_id=<?php echo $channel['id']; ?>" style="background: rgba(16,185,129,0.15); color: #059669; border: 1px solid rgba(16,185,129,0.3); padding: 6px 12px; font-size: 12px; font-weight:600; margin-right: 6px; text-decoration: none; border-radius: 8px; display: inline-block;">Cấp quyền lại</a>
                                <?php else: ?>
                                    <a href="youtube_login.php" style="background: rgba(16,185,129,0.15); color: #059669; border: 1px solid rgba(16,185,129,0.3); padding: 6px 12px; font-size: 12px; font-weight:600; margin-right: 6px; text-decoration: none; border-radius: 8px; display: inline-block;">Cấp quyền lại</a>
                                <?php endif; ?>
                                
                                <a href="youtube.php?tab=channels&delete=<?php echo $channel['id']; ?>" style="background: rgba(239,68,68,0.12); color: #dc2626; border: 1px solid rgba(239,68,68,0.2); padding: 6px 12px; font-size: 12px; font-weight:600; border-radius: 8px; text-decoration: none; display: inline-block;" onclick="return confirm('Bạn có chắc chắn muốn xóa kênh này?');">Xóa kênh</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo ($youtube_multi_api === 1) ? 5 : 4; ?>" style="padding: 40px; text-align: center; color: var(--text-muted, #64748b);">
                            Chưa có kênh YouTube nào được liên kết.<br><br>
                            <?php if ($youtube_multi_api === 1): ?>
                                <button onclick="openAddChannelModal()" class="btn-evon-primary" style="margin: 0 auto;">Liên kết kênh đầu tiên</button>
                            <?php else: ?>
                                <a href="youtube_login.php" class="btn-evon-primary" style="margin: 0 auto; display: inline-flex;">Liên kết kênh đầu tiên</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal Thêm Kênh YouTube mới -->
    <div id="addChannelModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.75); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:var(--card-bg, #ffffff); padding:28px; border-radius:16px; width:100%; max-width:480px; position:relative; border:1px solid var(--border-color, #cbd5e1); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
            <h3 style="margin-top:0; color:var(--text-main, #1e293b); font-size:18px; font-weight:700;">Thêm Kênh YouTube Mới</h3>
            <p style="font-size: 13px; color: var(--text-muted, #64748b); margin-bottom: 18px; line-height:1.5;">
                Nếu muốn dùng dự án Google Console riêng để tăng quota, hãy nhập Client ID & Secret ở dưới. Nếu để trống, hệ thống sẽ sử dụng cấu hình mặc định.
            </p>
            <form method="GET" action="youtube_login.php">
                <div class="form-group-custom">
                    <label>Google Client ID (Tùy chọn)</label>
                    <input type="text" name="gg_client_id" placeholder="Để trống để dùng API mặc định...">
                </div>
                
                <div class="form-group-custom" style="margin-bottom: 25px;">
                    <label>Google Client Secret (Tùy chọn)</label>
                    <input type="text" name="gg_client_secret" placeholder="Để trống để dùng API mặc định...">
                </div>
                
                <div style="text-align: right; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeAddChannelModal()" style="background:rgba(255,255,255,0.05); color:#94a3b8; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 9px 18px; font-weight:600; font-size:13px; cursor: pointer;">Hủy</button>
                    <button type="submit" class="btn-evon-primary">Tiếp Tục Kết Nối</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Sửa API Google của Kênh -->
    <div id="editApiModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.75); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:var(--card-bg, #1e293b); padding:28px; border-radius:16px; width:100%; max-width:480px; position:relative; border:1px solid var(--border-color, rgba(255,255,255,0.12)); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
            <h3 style="margin-top:0; color:#f8fafc; font-size:18px; font-weight:700;">Sửa Cấu Hình Google API: <span id="e_channel_title_label" style="color:#818cf8;"></span></h3>
            <form method="POST" action="youtube.php?tab=channels">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="edit_api">
                <input type="hidden" name="channel_id_db" id="e_channel_id_db">
                
                <div class="form-group-custom">
                    <label>Google Client ID riêng</label>
                    <input type="text" id="e_gg_client_id" name="gg_client_id" placeholder="Để trống để dùng API mặc định...">
                </div>
                
                <div class="form-group-custom" style="margin-bottom: 25px;">
                    <label>Google Client Secret riêng</label>
                    <input type="text" id="e_gg_client_secret" name="gg_client_secret" placeholder="Để trống để dùng API mặc định...">
                </div>
                
                <div style="text-align: right; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeEditApiModal()" style="background:rgba(255,255,255,0.05); color:#94a3b8; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 9px 18px; font-weight:600; font-size:13px; cursor: pointer;">Hủy</button>
                    <button type="submit" class="btn-evon-primary">Lưu Thay Đổi</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddChannelModal() { document.getElementById('addChannelModal').style.display = 'flex'; }
    function closeAddChannelModal() { document.getElementById('addChannelModal').style.display = 'none'; }
    function openEditApiModal(id, title, client_id, client_secret) {
        document.getElementById('e_channel_id_db').value = id;
        document.getElementById('e_channel_title_label').textContent = title;
        document.getElementById('e_gg_client_id').value = client_id;
        document.getElementById('e_gg_client_secret').value = client_secret;
        document.getElementById('editApiModal').style.display = 'flex';
    }
    function closeEditApiModal() { document.getElementById('editApiModal').style.display = 'none'; }
    </script>
<?php else: ?>
    <!-- TAB SCHEDULER: ĐĂNG VIDEO YOUTUBE -->

<div class="evon-card">
    <h3 style="margin-top:0; margin-bottom: 8px; font-size:18px; font-weight:700; color:var(--text-main, #1e293b);">Đăng Video YouTube</h3>
    <p style="color: var(--text-muted, #64748b); font-size: 13px; margin-bottom: 24px; line-height:1.5;">
        Chọn Kênh YouTube, nhập liên kết hoặc tải lên video, tuỳ chọn nhờ AI viết Title/Description/Tags tự động.
    </p>

    <form id="youtubeForm" enctype="multipart/form-data">
        <style>
        .ps-wrapper { border: 1px solid var(--border-color, #cbd5e1); border-radius: 12px; overflow: hidden; background: var(--card-bg, #ffffff); }
        .ps-search-bar { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-bottom: 1px solid var(--border-color, #e2e8f0); background: var(--bg-color, #f8fafc); }
        .ps-search-bar svg { flex-shrink: 0; color: var(--text-muted, #64748b); }
        .ps-search-bar input { flex: 1; border: none; background: transparent; outline: none; font-size: 13px; color: var(--text-main, #1e293b); }
        .ps-search-bar input::placeholder { color: var(--text-muted, #94a3b8); }
        .ps-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 8px 14px; border-bottom: 1px solid var(--border-color, #e2e8f0); background: var(--bg-color, #f8fafc); font-size: 12px; color: var(--text-muted, #64748b); }
        .ps-toolbar label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 600; color:#4f46e5; }
        .ps-toolbar input[type=checkbox] { width: 15px; height: 15px; cursor: pointer; accent-color: #6366f1; }
        #yt-count { font-size: 12px; color: var(--text-muted, #64748b); }
        .ps-list { max-height: 220px; overflow-y: auto; padding: 4px 0; }
        .ps-item { display: flex; align-items: center; gap: 10px; padding: 8px 14px; cursor: pointer; transition: background 0.12s; font-size: 13px; color: var(--text-main, #1e293b); }
        .ps-item:hover { background: rgba(99, 102, 241, 0.08); }
        .ps-item.ps-checked { background: rgba(99, 102, 241, 0.12); }
        .ps-item input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; accent-color: #6366f1; cursor: pointer; }
        .ps-item label { cursor: pointer; flex: 1; line-height: 1.35; display:flex; align-items:center; gap:8px; color:var(--text-main, #1e293b) !important; }
        .ps-empty { text-align: center; padding: 24px; color: var(--text-muted, #64748b); font-size: 13px; display: none; }
        </style>
        
        <div class="form-group-custom">
            <label>1. Chọn Kênh YouTube</label>
            <div class="ps-wrapper" id="yt-wrapper">
                <div class="ps-search-bar">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="yt-search" placeholder="Tìm kiếm kênh youtube..." autocomplete="off">
                    <button type="button" id="yt-clear-search" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:0;display:none;">✕</button>
                </div>
                <div class="ps-toolbar">
                    <label>
                        <input type="checkbox" id="yt-select-all"> Chọn tất cả
                    </label>
                    <span id="yt-count">0 đã chọn</span>
                </div>
                <div class="ps-list" id="yt-list">
                    <div class="ps-empty" id="yt-empty">Không tìm thấy kênh nào</div>
                </div>
            </div>
            <!-- Hidden inputs submitted with form -->
            <div id="yt-hidden-inputs"></div>
        </div>
        
        <script>
        (function() {
            const ALL_CHANNELS = <?php echo $channels_json; ?>;
            let currentChannels = ALL_CHANNELS;
            let checkedIds = new Set();
            
            const listEl = document.getElementById('yt-list');
            const emptyEl = document.getElementById('yt-empty');
            const searchEl = document.getElementById('yt-search');
            const clearBtn = document.getElementById('yt-clear-search');
            const selectAllEl = document.getElementById('yt-select-all');
            const countEl = document.getElementById('yt-count');
            const hiddenEl = document.getElementById('yt-hidden-inputs');
            
            function render(query) {
                const q = query.trim().toLowerCase();
                const visible = currentChannels.filter(c => !q || c.channel_title.toLowerCase().includes(q) || c.channel_id.toLowerCase().includes(q));
                
                listEl.querySelectorAll('.ps-item').forEach(el => el.remove());
                
                if (visible.length === 0) {
                    emptyEl.style.display = 'block';
                } else {
                    emptyEl.style.display = 'none';
                    visible.forEach(c => {
                        const id = 'yt-cb-' + c.id;
                        const div = document.createElement('div');
                        div.className = 'ps-item' + (checkedIds.has(c.id) ? ' ps-checked' : '');
                        div.dataset.id = c.id;
                        
                        let avatarHtml = '';
                        if (c.channel_avatar) {
                            avatarHtml = `<img src="${escHtml(c.channel_avatar)}" alt="Avatar" style="width:24px;height:24px;border-radius:50%;">`;
                        }
                        
                        div.innerHTML = `<input type="checkbox" id="${id}" value="${c.id}"${checkedIds.has(c.id) ? ' checked' : ''}>
                                         <label for="${id}">${avatarHtml} <span style="color:var(--text-main, #1e293b); font-weight:500;">${escHtml(c.channel_title)}</span> <small style="color:var(--text-muted, #64748b);">(${escHtml(c.channel_id)})</small></label>`;
                        
                        div.querySelector('input').addEventListener('change', function() {
                            if (this.checked) { checkedIds.add(c.id); div.classList.add('ps-checked'); }
                            else { checkedIds.delete(c.id); div.classList.remove('ps-checked'); }
                            syncSelectAll(visible);
                            updateCount();
                            updateHidden();
                        });
                        
                        div.addEventListener('click', function(e) {
                            if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL' || e.target.closest('label')) return;
                            const cb = div.querySelector('input');
                            cb.checked = !cb.checked;
                            cb.dispatchEvent(new Event('change'));
                        });
                        
                        listEl.appendChild(div);
                    });
                }
                syncSelectAll(visible);
                updateCount();
                updateHidden();
            }
            
            function syncSelectAll(visible) {
                const allChecked = visible.length > 0 && visible.every(c => checkedIds.has(c.id));
                selectAllEl.checked = allChecked;
                selectAllEl.indeterminate = !allChecked && visible.some(c => checkedIds.has(c.id));
            }
            
            function updateCount() {
                const n = checkedIds.size;
                countEl.textContent = n > 0 ? n + ' đã chọn' : '0 đã chọn';
                countEl.style.color = n > 0 ? '#818cf8' : '';
                countEl.style.fontWeight = n > 0 ? '600' : '';
            }
            
            function updateHidden() {
                hiddenEl.innerHTML = '';
                checkedIds.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'youtube_channel_ids[]';
                    inp.value = id;
                    hiddenEl.appendChild(inp);
                });
            }
            
            function escHtml(s) {
                if (!s) return '';
                return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            
            selectAllEl.addEventListener('change', function() {
                const q = searchEl.value.trim().toLowerCase();
                const visible = currentChannels.filter(c => !q || c.channel_title.toLowerCase().includes(q) || c.channel_id.toLowerCase().includes(q));
                if (this.checked) visible.forEach(c => checkedIds.add(c.id));
                else visible.forEach(c => checkedIds.delete(c.id));
                render(q);
            });
            
            searchEl.addEventListener('input', function() {
                clearBtn.style.display = this.value ? 'block' : 'none';
                render(this.value);
            });
            
            clearBtn.addEventListener('click', function() {
                searchEl.value = '';
                this.style.display = 'none';
                render('');
                searchEl.focus();
            });
            
            window.youtubeSelectorValidate = function() {
                if (checkedIds.size === 0) {
                    alert('Vui lòng chọn ít nhất 1 Kênh YouTube!');
                    return false;
                }
                return true;
            };

            window.youtubeGetSelectedChannels = function() {
                return Array.from(checkedIds);
            };
            
            render('');
        })();
        </script>
        
        <div class="form-group-custom" style="background: rgba(236,72,153,0.08); padding: 16px; border-radius: 12px; border: 1px dashed rgba(236,72,153,0.3); margin-bottom: 20px;">
            <label style="color: #db2777; font-weight: 600; display:flex; align-items:center; gap:8px; margin:0;">
                <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="width:16px; height:16px;"> 
                Tự động dùng Tên File / Tiêu đề TikTok làm Tiêu đề
            </label>
            <p style="font-size: 12px; color: #be185d; margin-top: 6px; margin-bottom: 0;">(Nếu chọn, hệ thống sẽ tự sinh tên nếu bạn bỏ trống tiêu đề)</p>
        </div>
        
        <div class="form-group-custom">
            <label>2. Tiêu đề Video chung (Tùy chọn)</label>
            <input type="text" id="title" name="title" placeholder="Nhập tiêu đề video...">
        </div>
        <div class="form-group-custom">
            <label>3. Mô tả Video chung (Tùy chọn)</label>
            <textarea id="description" name="description" rows="3" placeholder="Nhập mô tả video..."></textarea>
        </div>
        
        <div class="form-group-custom" style="background: var(--bg-color, #f8fafc); padding: 16px; border-radius: 12px; border: 1px dashed var(--border-color, #cbd5e1); margin-bottom: 20px;">
            <label style="color: var(--text-main, #1e293b); font-weight: 600;">Tags Video (Tùy chọn, cách nhau bởi dấu phẩy)</label>
            <input type="text" id="tags" name="tags" placeholder="VD: tintuc, giaitri, thethao..." style="margin-top: 8px;">
        </div>

        <!-- Cài đặt YouTube Metadata (genthegreat/video-uploader) -->
        <div class="yt-meta-grid">
            <div class="yt-meta-card">
                <label>🔒 Quyền riêng tư (Privacy Status)</label>
                <select name="privacy_status" id="privacy_status">
                    <option value="public" selected>🌐 Công khai (Public) — Ai cũng có thể xem</option>
                    <option value="unlisted">🔗 Không công khai (Unlisted) — Chỉ người có link xem được</option>
                    <option value="private">🔒 Riêng tư (Private) — Chỉ bạn/kênh xem được</option>
                </select>
                <small style="display:block; margin-top:6px; font-size:11.5px; color:var(--text-muted, #64748b);">
                    Chế độ hiển thị video trên YouTube sau khi tải lên.
                </small>
            </div>
            <div class="yt-meta-card">
                <label>📂 Danh mục YouTube (Category)</label>
                <select name="category_id" id="category_id">
                    <option value="22" selected>🧑‍🤝‍🧑 Mọi người & Blog (People & Blogs)</option>
                    <option value="24">🎬 Giải trí (Entertainment)</option>
                    <option value="26">💡 Hướng dẫn & Phong cách (Howto & Style)</option>
                    <option value="27">🎓 Giáo dục (Education)</option>
                    <option value="28">🔬 Khoa học & Công nghệ (Science & Technology)</option>
                    <option value="10">🎵 Âm nhạc (Music)</option>
                    <option value="17">⚽ Thể thao (Sports)</option>
                    <option value="23">😂 Hài kịch (Comedy)</option>
                    <option value="25">📰 Tin tức & Chính trị (News & Politics)</option>
                    <option value="1">🎥 Phim & Hoạt hình (Film & Animation)</option>
                    <option value="20">🎮 Trò chơi (Gaming)</option>
                    <option value="2">🚗 Xe cộ & Ô tô (Autos & Vehicles)</option>
                    <option value="15">🐾 Động vật & Thú cưng (Pets & Animals)</option>
                    <option value="19">✈️ Du lịch & Sự kiện (Travel & Events)</option>
                </select>
                <small style="display:block; margin-top:6px; font-size:11.5px; color:var(--text-muted, #64748b);">
                    Phân loại chủ đề chuẩn cho thuật toán đề xuất YouTube.
                </small>
            </div>
        </div>

        <div class="form-group-custom">
            <label>4. Tải lên Video <?php echo $disable_local_upload ? '(Google Drive / Kho Data)' : '(Từ Máy tính, Drive hoặc Kho Data)'; ?>:</label>
            <?php if ($disable_local_upload): ?>
            <div style="padding: 12px 16px; background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.3); border-radius: 10px; font-size: 13px; color: #d97706; margin-bottom: 12px;">
                🔒 Admin đã tắt tính năng tải tệp từ máy tính. Vui lòng sử dụng Google Drive hoặc Kho Data.
            </div>
            <?php endif; ?>

            <div style="display: flex; gap: 12px; align-items: center; background: #f8fafc; padding: 16px; border: 1px dashed #cbd5e1; border-radius: 12px; flex-wrap: wrap;">
                <?php if (!$disable_local_upload): ?>
                <input type="file" id="video" name="video[]" multiple accept="video/mp4,video/x-m4v,video/*" style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; background: #ffffff; font-size: 13px;" onchange="if(this.files && this.files.length > 0) { clearDriveSelection(); if(typeof clearKhoDataSelection === 'function') clearKhoDataSelection(); }">
                <div style="font-weight: 800; color: #64748b; font-size: 12px;">HOẶC</div>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="openDriveModal()" style="background: #ffffff; border: 1px solid #cbd5e1; color: #334155; padding: 9px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                    Chọn từ Google Drive
                </button>
                <?php include __DIR__ . '/includes/kho_data_selector.php'; ?>
            </div>

            <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 700;"></div>

            <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 14px 18px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 12px; font-size: 13px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; color: #0369a1; font-weight: 800;">
                    <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg> Đã chọn <span id="driveSelectedCount">0</span> file từ Google Drive:</span>
                    <button type="button" onclick="clearDriveSelection()" style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12.5px; font-weight: 800;">Hủy / Xoá hết</button>
                </div>
                <ul id="driveSelectedList" style="margin: 0; padding-left: 20px; color: #0c4a6e; max-height: 120px; overflow-y: auto; line-height: 1.6; font-weight: 600;"></ul>
            </div>
            <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
            <input type="hidden" id="drive_file_names" name="drive_file_names" value="">
        </div>

        <div style="display:flex; gap:16px; align-items:stretch; margin-top:4px; flex-wrap:wrap;">
            <div class="form-group-custom" style="flex:1; min-width:300px; background: var(--bg-color, #f8fafc); padding: 18px; border-radius: 12px; border: 1px solid var(--border-color, #e2e8f0); margin-bottom:0;">
                <label style="color: #4f46e5;">5. Lên lịch tự động hàng loạt (Tùy chọn)</label>
                <p style="font-size: 13px; color: var(--text-muted, #64748b); margin-top: 4px; margin-bottom: 16px;">
                    Chọn khoảng ngày và các khung giờ, tối đa hẹn giờ 3 tháng một chiến dịch.
                </p>
                <div style="display: flex; gap: 14px; margin-bottom: 12px;">
                    <div style="flex: 1;">
                        <label style="font-size: 12px; color:var(--text-muted, #64748b);">Từ ngày:</label>
                        <input type="date" id="start_date" name="start_date" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; background:var(--card-bg, #ffffff); color:var(--text-main, #1e293b);">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 12px; color:var(--text-muted, #64748b);">Đến ngày:</label>
                        <input type="date" id="end_date" name="end_date" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; background:var(--card-bg, #ffffff); color:var(--text-main, #1e293b);">
                    </div>
                </div>
                <div>
                    <label style="font-size: 12px; color:var(--text-muted, #64748b);">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                    <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; background:var(--card-bg, #ffffff); color:var(--text-main, #1e293b);">
                </div>
                <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed var(--border-color, #cbd5e1);">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--text-main, #1e293b); cursor: pointer;">
                        <input type="checkbox" id="spread_time" name="spread_time" value="1" style="width: 16px; height: 16px; accent-color: #4f46e5;">
                        <span>⏱️ Rải đều lịch trong +- 30 phút <span style="color: #ef4444; font-weight: 800;">(Khuyên dùng)</span></span>
                    </label>
                    <small style="display: block; font-size: 11.5px; color: var(--text-muted, #64748b); margin-top: 4px; margin-left: 24px;">
                        Ví dụ: Đặt 06:30 cho 50 Kênh YouTube, hệ thống sẽ tự động rải đều giờ đăng từ 06:00 đến 07:00 (-30 phút đến +30 phút).
                    </small>
                </div>
                <div style="margin-top: 10px; color: #0284c7; font-size: 12px;">
                    * Ghi chú: Kênh YouTube tốn Quota nặng, cẩn thận giới hạn tải lên hàng ngày.
                </div>
            </div>

            <div class="form-group-custom" style="flex:1; min-width:300px; background:rgba(16,185,129,0.06); padding:18px; border-radius:12px; border:1px solid rgba(16,185,129,0.2); margin-bottom:0;">
                <label style="color:#059669; font-weight:600; display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="enable_comment" id="enableComment" value="1" onchange="document.getElementById('commentBox').style.display=this.checked?'block':'none'" style="width:16px;height:16px;accent-color:#10b981;">
                    💬 Bình luận vào video sau khi đăng (120 giây)
                </label>
                <div id="commentBox" style="display:none; margin-top:12px;">
                    <label style="font-size:12px; color:#047857;">Mỗi dòng = 1 nội dung bình luận (random 1 dòng):</label>
                    <textarea name="comment_lines" rows="6" placeholder="Bảo hành đổi trả: link&#10;Xem thêm: link" style="width:100%; margin-top:6px; padding:10px; border:1px solid rgba(16,185,129,0.3); border-radius:8px; font-size:13px; resize:vertical; background:var(--card-bg, #ffffff); color:var(--text-main, #1e293b); outline:none;"></textarea>
                    <p style="font-size:11px; color:#059669; margin-top:6px; margin-bottom:0;">⚡ Hệ thống sẽ gửi bình luận qua API.</p>
                </div>
            </div>
        </div>
        
        <div class="form-group-custom" style="background: rgba(20,184,166,0.06); padding: 16px; border-radius: 12px; border: 1px dashed rgba(20,184,166,0.25); margin-top: 16px;">
            <label style="color: #0d9488; font-weight: 600; display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 0;">
                <input type="checkbox" id="delete_drive_file" name="delete_drive_file" value="1" style="width: 16px; height: 16px; accent-color: #14b8a6;">
                🛡️ Chống trùng và xóa file đã đăng drive
            </label>
            <p style="font-size: 12px; color: #0f766e; margin-top: 6px; margin-bottom: 0;">
                Khi chọn, nội dung đăng sẽ không trùng lặp và tự động xóa khỏi Google Drive sau khi đăng.
            </p>
        </div>

        <div id="youtubeResult" style="display: none; margin-top: 16px; padding: 14px 18px; border-radius: 10px;"></div>

        <div class="form-group-custom" style="margin-top: 16px;">
            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer; color:var(--text-main, #1e293b);">
                <input type="checkbox" name="use_ai" value="1" style="width: 18px; height: 18px; accent-color: #6366f1;">
                🤖 Tự động viết lại nội dung/tiêu đề chuẩn SEO YouTube
            </label>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 24px;">
            <button id="btnSubmit" class="btn-evon-primary" type="submit">Xác nhận Đăng / Lên Lịch</button>
        </div>
    </form>
</div>

<script>
    const youtubeForm = document.getElementById('youtubeForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const youtubeResult = document.getElementById('youtubeResult');
    const localStatus = document.getElementById('localUploadStatus');

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
                        throw new Error(`Sự cố khi tải file ${file.name} (HTTP ${response.status}): ${text}`);
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
                        if (progressCallback) {
                            progressCallback(`⚠️ Drive bận khi tải ${file.name} (${data.msg || 'Không rõ'}). Tự động chuyển sang tải trực tiếp từ máy...`);
                        }
                        uploadNext(index + 1);
                    }
                })
                .catch(error => {
                    console.warn(`Drive upload error/timeout for ${file.name}:`, error);
                    if (progressCallback) {
                        progressCallback(`⚠️ Timeout kết nối Drive khi tải ${file.name}. Chuyển sang tải trực tiếp từ máy...`);
                    }
                    uploadNext(index + 1);
                });
            }

            uploadNext(0);
        });
    }

    youtubeForm.addEventListener('submit', function(e) {
        e.preventDefault();

        if (typeof window.youtubeSelectorValidate === 'function' && !window.youtubeSelectorValidate()) {
            return;
        }

        const selectedChannels = typeof window.youtubeGetSelectedChannels === 'function' 
            ? window.youtubeGetSelectedChannels() 
            : [];
        if (selectedChannels.length === 0) {
            alert('Vui lòng chọn ít nhất 1 Kênh YouTube!');
            return;
        }

        const dataGroupId = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
        const videoEl = document.getElementById('video');
        const videoFiles = videoEl && videoEl.files ? videoEl.files.length : 0;
        const driveFileId = document.getElementById('drive_file_id') ? document.getElementById('drive_file_id').value.trim() : '';

        if (!dataGroupId && videoFiles === 0 && !driveFileId) {
            const msg = 'Vui lòng chọn File Video tải lên, HOẶC tệp Google Drive, HOẶC chọn Nhóm Data từ Kho Data.';
            if (typeof window.showNotice === 'function') {
                window.showNotice(msg, 'warning');
            } else {
                alert(msg);
            }
            return;
        }

        btnSubmit.disabled = true;
        btnSubmit.textContent = '⏳ Đang tải lên và thực hiện...';
        youtubeResult.style.display = 'none';

        if (localStatus) {
            localStatus.style.display = 'none';
            localStatus.innerText = '';
        }

        uploadLocalFilesPromise(videoEl, function(msg) {
            if (localStatus) {
                localStatus.style.display = 'block';
                localStatus.style.background = '#fff7ed';
                localStatus.style.color = '#c2410c';
                localStatus.style.border = '1px solid #fed7aa';
                localStatus.innerText = msg;
            }
            btnSubmit.textContent = '⏳ Đang tải file lên Google Drive...';
        })
        .then(uploadedFiles => {
            if (uploadedFiles && uploadedFiles.length > 0) {
                if (localStatus) {
                    localStatus.style.background = '#ecfdf5';
                    localStatus.style.color = '#065f46';
                    localStatus.style.border = '1px solid #a7f3d0';
                    localStatus.innerText = '✅ Tải lên Google Drive thành công! Đang tiến hành lên lịch YouTube...';
                }
                
                const fileIds = uploadedFiles.map(f => f.id).join(',');
                const fileNames = uploadedFiles.map(f => f.name).join('|||');
                
                document.getElementById('drive_file_id').value = fileIds;
                document.getElementById('drive_file_names').value = fileNames;
                
                if (videoEl) videoEl.value = '';
            }

            btnSubmit.textContent = '⏳ Đang xử lý đăng bài (Có thể mất đến 1-2 phút)...';
            const formData = new FormData(youtubeForm);

            // Đảm bảo đưa chính xác danh sách kênh đã chọn vào formData
            formData.delete('youtube_channel_ids[]');
            selectedChannels.forEach(id => {
                formData.append('youtube_channel_ids[]', id);
            });

            const kdGroup = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
            const kdMode = document.getElementById('data_mode') ? document.getElementById('data_mode').value.trim() : 'dedup';
            if (kdGroup) {
                formData.set('data_group_id', kdGroup);
                formData.set('data_mode', kdMode);
            }

            return fetch('actions/publish_youtube.php', {
                method: 'POST',
                body: formData
            });
        })
        .then(response => {
            if (response instanceof Response) {
                return response.json();
            }
            throw new Error('Không nhận được phản hồi hợp lệ từ máy chủ.');
        })
        .then(data => {
            handleUploadResponse(data);
        })
        .catch(error => {
            handleUploadResponse({ status: 'error', msg: error.message || 'Lỗi mạng hoặc hệ thống.' });
        });
    });

    function handleUploadResponse(data) {
        youtubeResult.style.display = 'block';
        if (data.status === 'success') {
            youtubeResult.className = 'alert alert-success';
            youtubeResult.style.background = 'rgba(16,185,129,0.15)';
            youtubeResult.style.color = '#065f46';
            youtubeResult.style.border = '1px solid rgba(16,185,129,0.3)';
            youtubeResult.innerHTML = `
                <div style="font-weight:700; font-size:14px; margin-bottom:4px;">🎉 ${data.msg}</div>
                <div style="font-size:12px; color:#047857;">
                    Video đã được đưa vào hàng đợi xử lý. Background worker sẽ tự động upload lên YouTube.
                </div>
            `;
            if (data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 1800);
            }
            youtubeForm.reset();
            clearDriveSelection();
            if (typeof clearKhoDataSelection === 'function') clearKhoDataSelection();
            if (localStatus) localStatus.style.display = 'none';
        } else {
            youtubeResult.className = 'alert alert-danger';
            youtubeResult.style.background = 'rgba(239,68,68,0.15)';
            youtubeResult.style.color = '#b91c1c';
            youtubeResult.style.border = '1px solid rgba(239,68,68,0.3)';
            youtubeResult.innerHTML = '❌ ' + (data.msg || 'Có lỗi xảy ra.');
        }
        btnSubmit.disabled = false;
        btnSubmit.textContent = 'Xác nhận Đăng / Lên Lịch';
    }

    function onDriveFilesSelected(files) {
        if (files.length === 0) return;
        const fileIds = files.map(f => f.id).join(',');
        const fileNames = files.map(f => f.name).join('|||');

        document.getElementById('drive_file_id').value = fileIds;
        document.getElementById('drive_file_names').value = fileNames;
        
        const videoEl = document.getElementById('video');
        if (videoEl) videoEl.value = '';
        if (typeof clearKhoDataSelection === 'function') clearKhoDataSelection();

        const listEl = document.getElementById('driveSelectedList');
        if (listEl) {
            listEl.innerHTML = '';
            files.forEach(f => {
                const li = document.createElement('li');
                li.textContent = f.name;
                listEl.appendChild(li);
            });
        }

        document.getElementById('driveSelectedCount').innerText = files.length;
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }

    function onDriveFolderSelected(folderId, folderName) {
        document.getElementById('drive_file_id').value = 'folder:' + folderId;
        if (document.getElementById('drive_file_names')) {
            document.getElementById('drive_file_names').value = 'folder:' + folderName;
        }

        const videoEl = document.getElementById('video');
        if (videoEl) videoEl.value = '';
        if (typeof clearKhoDataSelection === 'function') clearKhoDataSelection();

        const listEl = document.getElementById('driveSelectedList');
        if (listEl) {
            listEl.innerHTML = `<li>📁 Thư mục Google Drive: <strong>${folderName}</strong></li>`;
        }

        document.getElementById('driveSelectedCount').innerText = 'Thư mục';
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }

    function clearDriveSelection() {
        if (document.getElementById('drive_file_id')) document.getElementById('drive_file_id').value = '';
        if (document.getElementById('drive_file_names')) document.getElementById('drive_file_names').value = '';
        if (document.getElementById('driveSelectionInfo')) document.getElementById('driveSelectionInfo').style.display = 'none';

        const localStatus = document.getElementById('localUploadStatus');
        if (localStatus) {
            localStatus.style.display = 'none';
            localStatus.innerText = '';
        }
    }

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
<?php endif; ?>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/footer.php'; ?>