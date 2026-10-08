<?php
// buffer.php - Unified Buffer AI Posting & Channels Management Interface
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];
$is_admin   = (($_SESSION['role'] ?? '') === 'admin');

// Đọc cấu hình giới hạn upload
$disable_local_upload = false;
try {
    $stmt_upload = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'disable_local_upload'");
    $stmt_upload->execute();
    $row_upload = $stmt_upload->fetch(PDO::FETCH_ASSOC);
    if ($row_upload && $row_upload['setting_value'] === '1' && !$is_admin) $disable_local_upload = true;
} catch (Exception $e) {}

// Lấy danh sách tài khoản & kênh Buffer
$stmt_acc = $pdo->prepare("SELECT * FROM buffer_accounts WHERE account_id = ? ORDER BY id DESC");
$stmt_acc->execute([$account_id]);
$buffer_accounts = $stmt_acc->fetchAll(PDO::FETCH_ASSOC);

$stmt_lim = $pdo->prepare("SELECT max_buffer_channels FROM system_accounts WHERE id = ?");
$stmt_lim->execute([$account_id]);
$max_buffer_channels = intval($stmt_lim->fetchColumn() ?: 10);
$max_buf_display = $is_admin ? '&infin;' : number_format($max_buffer_channels);

$total_channels = 0;
$has_channels = false;
foreach ($buffer_accounts as &$acc) {
    $stmt_chan = $pdo->prepare("SELECT * FROM buffer_channels WHERE buffer_account_id = ? AND account_id = ? ORDER BY service ASC");
    $stmt_chan->execute([$acc['id'], $account_id]);
    $chans = $stmt_chan->fetchAll(PDO::FETCH_ASSOC);
    $acc['channels'] = $chans;
    $total_channels += count($chans);
    if (!empty($chans)) {
        $has_channels = true;
    }
}
unset($acc);

function getPlatformBadgeShort($service) {
    $svc = strtolower(trim($service));
    switch ($svc) {
        case 'tiktok': return '🎵 TikTok';
        case 'pinterest': return '📌 Pinterest';
        case 'threads': return '@ Threads';
        case 'youtube': case 'youtube short': case 'youtubeshort': return '▶ YT Short';
        case 'instagram': return '📷 Instagram';
        case 'facebook': return '📘 Facebook';
        case 'twitter': case 'x': return '𝕏 Twitter';
        case 'linkedin': return '💼 LinkedIn';
        default: return '🌐 ' . ucfirst($service);
    }
}

function getPlatformBadgeFull($service) {
    $svc = strtolower(trim($service));
    switch ($svc) {
        case 'tiktok':
            return '<span class="platform-badge platform-tiktok"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12.539 2.236a.75.75 0 0 1 .75.75v5.82a4.96 4.96 0 0 0 3.238 1.157 4.97 4.97 0 0 0 1.222-.152.75.75 0 0 1 .931.724v3.13a.75.75 0 0 1-.703.748 8.01 8.01 0 0 1-4.688-1.424v5.337a5.86 5.86 0 1 1-5.86-5.86 5.8 5.8 0 0 1 2.37.503.75.75 0 0 1 .44.686v3.125a.75.75 0 0 1-1.026.7 2.86 2.86 0 1 0 1.966 2.72v-16.71a.75.75 0 0 1 .416-.67z"/></svg> TikTok</span>';
        case 'pinterest':
            return '<span class="platform-badge platform-pinterest">📌 Pinterest</span>';
        case 'threads':
            return '<span class="platform-badge platform-threads">@ Threads</span>';
        case 'youtube':
        case 'youtube short':
        case 'youtubeshort':
            return '<span class="platform-badge platform-youtube">▶ YouTube Short</span>';
        case 'instagram':
            return '<span class="platform-badge platform-instagram">📷 Instagram</span>';
        case 'facebook':
            return '<span class="platform-badge platform-facebook">📘 Facebook</span>';
        case 'twitter':
        case 'x':
            return '<span class="platform-badge platform-twitter">𝕏 Twitter / X</span>';
        case 'linkedin':
            return '<span class="platform-badge platform-linkedin">💼 LinkedIn</span>';
        default:
            return '<span class="platform-badge platform-default">🌐 ' . htmlspecialchars(ucfirst($service)) . '</span>';
    }
}

$current_page = 'buffer';
require_once __DIR__ . '/includes/header.php';

$active_tab = $_GET['tab'] ?? 'scheduler';
?>

<style>
    /* evondev UI/UX Design System */
    .container, button, input, select, textarea {
        font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    }

    .page-container {
        max-width: 1200px;
        margin: 0 auto;
    }

    .buf-hero {
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

    .buf-hero-text h2 {
        font-size: 22px;
        font-weight: 700;
        color: #ffffff;
        margin: 0 0 4px;
        letter-spacing: -0.02em;
    }

    .buf-hero-text p {
        font-size: 13px;
        color: #94a3b8;
        margin: 0;
    }
    
    .composer-grid {
        display: grid;
        grid-template-columns: 360px 1fr;
        gap: 24px;
        margin-bottom: 30px;
    }

    .card-box {
        background: var(--card-bg, #ffffff);
        border-radius: 16px;
        border: 1px solid var(--border-color, #e2e8f0);
        padding: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .card-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--text-main, #1e293b);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        padding-bottom: 12px;
    }

    .acc-group-header {
        font-size: 13px;
        font-weight: 700;
        color: var(--text-main, #1e293b);
        padding: 8px 12px;
        background: var(--bg-color, #f8fafc);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 8px;
        margin-top: 10px;
        margin-bottom: 6px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .channel-checkbox-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 10px;
        border-radius: 8px;
        transition: background 0.15s;
        cursor: pointer;
        color: var(--text-main, #1e293b);
    }

    .channel-checkbox-item:hover {
        background: rgba(99, 102, 241, 0.08);
    }

    .channel-checkbox-item input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #6366f1;
        cursor: pointer;
    }

    .channel-mini-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        object-fit: cover;
    }

    .form-group {
        margin-bottom: 18px;
    }

    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--text-muted, #64748b);
        margin-bottom: 8px;
    }

    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--border-color, #cbd5e1);
        border-radius: 10px;
        background: var(--card-bg, #ffffff);
        color: var(--text-main, #1e293b);
        font-size: 14px;
        outline: none;
        box-sizing: border-box;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .btn-submit-post {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: #ffffff;
        font-size: 15px;
        font-weight: 700;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        transition: transform .15s ease, box-shadow .15s ease;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
    }

    .btn-submit-post:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.45);
    }

    .media-source-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 14px;
        border-bottom: 1px solid var(--border-color, rgba(255, 255, 255, 0.08));
        padding-bottom: 8px;
    }
    .media-tab-btn {
        padding: 8px 14px;
        font-size: 13px;
        font-weight: 600;
        color: #94a3b8;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .media-tab-btn.active {
        background: rgba(99, 102, 241, 0.15);
        color: #818cf8;
        border-color: #6366f1;
    }

    /* Channels Management UI */
    .api-header-card {
        background: var(--card-bg, #ffffff);
        border-radius: 16px;
        border: 1px solid var(--border-color, #e2e8f0);
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    
    .api-title {
        font-size: 20px;
        font-weight: 800;
        color: var(--text-main, #1e293b);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .api-subtitle {
        font-size: 14px;
        color: var(--text-muted, #64748b);
        line-height: 1.5;
        margin-bottom: 20px;
    }

    .api-subtitle a {
        color: #4f46e5;
        font-weight: 600;
        text-decoration: underline;
    }

    .btn-add-connection {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: #ffffff;
        font-size: 14px;
        font-weight: 700;
        padding: 10px 20px;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: transform .15s ease, box-shadow .15s ease;
        text-decoration: none;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
    }

    .btn-add-connection:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.45);
        color: #ffffff;
    }

    .acc-block {
        background: var(--card-bg, #ffffff);
        border-radius: 16px;
        border: 1px solid var(--border-color, #e2e8f0);
        margin-bottom: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }

    .acc-header {
        padding: 16px 20px;
        background: var(--bg-color, #f8fafc);
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .acc-email {
        font-size: 15px;
        font-weight: 700;
        color: var(--text-main, #1e293b);
    }

    .acc-token-snippet {
        font-size: 12px;
        color: var(--text-muted, #64748b);
        font-family: monospace;
        margin-left: 6px;
    }

    .acc-sync-time {
        font-size: 12px;
        color: var(--text-muted, #64748b);
        margin-top: 2px;
    }

    .acc-actions {
        display: flex;
        gap: 8px;
    }

    .btn-action {
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 8px;
        border: 1px solid var(--border-color, #cbd5e1);
        background: var(--card-bg, #ffffff);
        color: var(--text-main, #1e293b);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.15s ease;
        text-decoration: none;
    }

    .btn-action:hover {
        background: rgba(99, 102, 241, 0.08);
        color: #4f46e5;
    }

    .btn-action-danger {
        color: #dc2626;
        border-color: rgba(239, 68, 68, 0.3);
        background: rgba(239, 68, 68, 0.08);
    }
    .btn-action-danger:hover {
        background: rgba(239, 68, 68, 0.15);
        color: #b91c1c;
    }

    .chan-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .chan-table th {
        background: var(--bg-color, #f8fafc);
        color: var(--text-muted, #64748b);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 12px 16px;
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        text-align: left;
    }

    .chan-table td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        color: var(--text-main, #1e293b);
        vertical-align: middle;
    }

    .chan-table tr:last-child td {
        border-bottom: none;
    }

    .chan-table tr:hover td {
        background: rgba(99, 102, 241, 0.04);
    }

    .channel-link {
        color: #4f46e5;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .channel-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        object-fit: cover;
        border: 1px solid var(--border-color, #e2e8f0);
    }

    .channel-avatar-placeholder {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #4f46e5;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
    }

    .platform-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 700;
    }
    .platform-tiktok { background: #000; color: #fff; }
    .platform-pinterest { background: #e60023; color: #fff; }
    .platform-threads { background: #000; color: #fff; }
    .platform-youtube { background: #ff0000; color: #fff; }
    .platform-instagram { background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); color: #fff; }
    .platform-facebook { background: #1877f2; color: #fff; }
    .platform-twitter { background: #000; color: #fff; }
    .platform-linkedin { background: #0a66c2; color: #fff; }
    .platform-default { background: var(--bg-color, #f1f5f9); color: var(--text-muted, #64748b); }

    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }
    .modal-card {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 16px;
        width: 100%;
        max-width: 500px;
        padding: 28px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .modal-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--text-main, #1e293b);
    }
    .modal-close {
        font-size: 20px;
        color: var(--text-muted, #64748b);
        cursor: pointer;
        border: none;
        background: transparent;
    }
</style>

<div class="page-container">
    <div class="buf-hero">
        <div class="buf-hero-text">
            <h2>Hệ Thống Buffer AI Multi-Channel</h2>
            <p>Đăng bài đa nền tảng (TikTok, Instagram, Pinterest, Threads, Facebook, YouTube Short...) thông qua Buffer API.</p>
        </div>
        <?php if ($active_tab === 'channels'): ?>
            <button onclick="openModalAddConnection()" class="btn-add-connection">
                <span>➕</span> THÊM KẾT NỐI BUFFER MỚI
            </button>
        <?php endif; ?>
    </div>

    <!-- Navigation Tabs -->
    <div style="display:flex; gap:10px; margin-bottom:24px; border-bottom:2px solid var(--border-color, #e2e8f0); padding-bottom:4px;">
        <a href="buffer.php?tab=scheduler" style="padding:10px 20px; border-radius:10px 10px 0 0; font-weight:600; font-size:14px; text-decoration:none; display:flex; align-items:center; gap:8px; transition:all 0.2s; <?php echo ($active_tab === 'scheduler') ? 'background:rgba(99,102,241,0.12); color:#4f46e5; border-bottom:3px solid #6366f1;' : 'color:var(--text-muted, #64748b);'; ?>">
            🚀 Đăng Bài &amp; Lịch Trình Buffer
        </a>
        <a href="buffer.php?tab=channels" style="padding:10px 20px; border-radius:10px 10px 0 0; font-weight:600; font-size:14px; text-decoration:none; display:flex; align-items:center; gap:8px; transition:all 0.2s; <?php echo ($active_tab === 'channels') ? 'background:rgba(99,102,241,0.12); color:#4f46e5; border-bottom:3px solid #6366f1;' : 'color:var(--text-muted, #64748b);'; ?>">
            📡 Quản Lý Kênh &amp; API Key (<?php echo $total_channels; ?> / <?php echo $max_buf_display; ?>)
        </a>
    </div>

    <!-- PHP Flash Notifications -->
    <?php if (!empty($global_flash_msg)): ?>
        <div style="margin-bottom: 24px; padding: 14px 18px; background: rgba(16,185,129,0.15); color: #059669; border-radius: 12px; border: 1px solid rgba(16,185,129,0.3); font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <span>✅</span>
            <div><?php echo htmlspecialchars($global_flash_msg); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($global_flash_error)): ?>
        <div style="margin-bottom: 24px; padding: 14px 18px; background: rgba(239,68,68,0.15); color: #dc2626; border-radius: 12px; border: 1px solid rgba(239,68,68,0.3); font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <span>⚠️</span>
            <div><?php echo htmlspecialchars($global_flash_error); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($active_tab === 'channels'): ?>
        <!-- ==================== TAB QUẢN LÝ KÊNH BUFFER ==================== -->
        <div class="api-header-card">
            <div class="api-title">
                <span>📡</span> API – Quản lý kết nối Buffer
            </div>
            <div class="api-subtitle">
                Tạo Personal Access Token tại <a href="https://publish.buffer.com/settings/api" target="_blank">publish.buffer.com/settings/api</a>. Mỗi key được lưu theo tài khoản; server gọi Buffer để đồng bộ <strong>organization</strong> và <strong>kênh</strong> (avatar, TikTok, Instagram, Pinterest, Threads, YouTube Short, Facebook...).
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <button onclick="openModalAddConnection()" class="btn-add-connection">
                    <span>➕</span> THÊM KẾT NỐI MỚI
                </button>
                
                <form method="POST" action="actions/buffer_save_account.php" style="margin:0;">
                    <input type="hidden" name="action" value="sync_all">
                    <input type="hidden" name="redirect" value="1">
                    <button type="submit" class="btn-action" style="font-weight:700;">
                        <span>🔄</span> ĐỒNG BỘ TẤT CẢ KÊNH
                    </button>
                </form>
            </div>
        </div>

        <?php if (empty($buffer_accounts)): ?>
            <div class="acc-block" style="text-align: center; padding: 60px 20px; color: var(--text-muted, #64748b);">
                <span style="font-size: 48px; display: block; margin-bottom: 12px;">📡</span>
                <h3 style="margin: 0 0 8px 0; color: var(--text-main, #1e293b);">Chưa có kết nối Buffer API nào</h3>
                <p style="font-size: 14px; margin-bottom: 20px;">Vui lòng bấm nút <strong>+ THÊM KẾT NỐI</strong> ở trên để thêm Email &amp; Access Token của Buffer.</p>
                <button onclick="openModalAddConnection()" class="btn-add-connection" style="margin: 0 auto;">➕ THÊM KẾT NỐI BUFFER MỚI</button>
            </div>
        <?php else: ?>
            <?php foreach ($buffer_accounts as $acc): ?>
                <?php 
                    $token_snippet = !empty($acc['access_token']) ? substr($acc['access_token'], 0, 4) . '...' . substr($acc['access_token'], -4) : 'N/A';
                    $sync_time = !empty($acc['synced_at']) ? date('d/m/Y · H:i', strtotime($acc['synced_at'])) : 'Chưa đồng bộ';
                ?>
                <div class="acc-block">
                    <div class="acc-header">
                        <div>
                            <div class="acc-email">
                                <?php echo htmlspecialchars($acc['email']); ?>
                                <span class="acc-token-snippet"><?php echo htmlspecialchars($token_snippet); ?></span>
                            </div>
                            <div class="acc-sync-time">
                                Đồng bộ: <?php echo htmlspecialchars($sync_time); ?>
                            </div>
                        </div>
                        <div class="acc-actions">
                            <form method="POST" action="actions/buffer_save_account.php" style="margin:0; display:inline;">
                                <input type="hidden" name="action" value="sync_account">
                                <input type="hidden" name="db_id" value="<?php echo $acc['id']; ?>">
                                <input type="hidden" name="redirect" value="1">
                                <button type="submit" class="btn-action">
                                    <span>🔄</span> Đồng bộ lại
                                </button>
                            </form>

                            <button onclick="openModalEditAccount(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars($acc['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($acc['access_token'], ENT_QUOTES); ?>')" class="btn-action">
                                <span>✏️</span> Sửa
                            </button>

                            <form method="POST" action="actions/buffer_save_account.php" style="margin:0; display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa tài khoản Buffer này?');">
                                <input type="hidden" name="action" value="delete_account">
                                <input type="hidden" name="db_id" value="<?php echo $acc['id']; ?>">
                                <input type="hidden" name="redirect" value="1">
                                <button type="submit" class="btn-action btn-action-danger">
                                    <span>🗑️</span> Xóa
                                </button>
                            </form>
                        </div>
                    </div>

                    <table class="chan-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="width: 180px;">ORGANIZATION</th>
                                <th>KÊNH</th>
                                <th style="width: 160px;">NỀN TẢNG</th>
                                <th style="width: 80px; text-align: center;">AVATAR</th>
                                <th style="width: 220px;">CHANNEL ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($acc['channels'])): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 24px; color: var(--text-muted, #64748b);">
                                        Chưa quét thấy kênh nào thuộc tài khoản này. Bấm <strong>Đồng bộ lại</strong> để quét lại kênh từ Buffer.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($acc['channels'] as $idx => $chan): ?>
                                    <tr>
                                        <td style="color: var(--text-muted, #64748b); font-weight: 600;"><?php echo ($idx + 1); ?></td>
                                        <td style="font-weight: 600; color: var(--text-main, #1e293b);"><?php echo htmlspecialchars($chan['organization'] ?: 'My Organization'); ?></td>
                                        <td>
                                            <span class="channel-link">
                                                <?php echo htmlspecialchars($chan['channel_name']); ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo getPlatformBadgeFull($chan['service']); ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if (!empty($chan['avatar'])): ?>
                                                <img src="<?php echo htmlspecialchars($chan['avatar']); ?>" class="channel-avatar" alt="Avatar">
                                            <?php else: ?>
                                                <div class="channel-avatar-placeholder"><?php echo strtoupper(substr($chan['channel_name'], 0, 1)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-family: monospace; font-size: 12px; color: var(--text-muted, #64748b); font-weight: 600;">
                                            <?php echo htmlspecialchars($chan['channel_id']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- MODAL THÊM / SỬA KẾT NỐI BUFFER -->
        <div id="modalConnection" class="modal-overlay">
            <div class="modal-card">
                <div class="modal-header">
                    <div class="modal-title" id="modal_title">➕ Thêm Kết Nối Buffer API Key</div>
                    <button class="modal-close" onclick="closeModalConnection()">✕</button>
                </div>

                <form method="POST" action="actions/buffer_save_account.php" onsubmit="document.getElementById('btn_save_conn').disabled=true;">
                    <input type="hidden" name="action" id="form_action" value="add_account">
                    <input type="hidden" name="db_id" id="form_db_id" value="0">
                    <input type="hidden" name="redirect" value="1">

                    <div class="form-group">
                        <label>Email Tài Khoản Buffer</label>
                        <input type="email" name="email" id="form_email" placeholder="Ví dụ: mipekpun@gmail.com (Có thể để trống để tự động lấy từ Buffer)">
                    </div>
                    
                    <div class="form-group">
                        <label>Personal Access Token / API Key (*)</label>
                        <textarea name="access_token" id="form_access_token" rows="3" required placeholder="Dán Personal Access Token lấy từ publish.buffer.com/settings/api..."></textarea>
                    </div>

                    <div style="text-align: right; margin-top: 24px; display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" onclick="closeModalConnection()" class="btn-action">Hủy</button>
                        <button type="submit" id="btn_save_conn" class="btn-add-connection">💾 Lưu &amp; Đồng Bộ Kênh</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function openModalAddConnection() {
            document.getElementById('modal_title').textContent = '➕ Thêm Kết Nối Buffer API Key Mới';
            document.getElementById('form_action').value = 'add_account';
            document.getElementById('form_db_id').value = '0';
            document.getElementById('form_email').value = '';
            document.getElementById('form_access_token').value = '';
            document.getElementById('modalConnection').style.display = 'flex';
        }

        function openModalEditAccount(id, email, token) {
            document.getElementById('modal_title').textContent = '✏️ Sửa Kết Nối Buffer: ' + email;
            document.getElementById('form_action').value = 'edit_account';
            document.getElementById('form_db_id').value = id;
            document.getElementById('form_email').value = email;
            document.getElementById('form_access_token').value = token;
            document.getElementById('modalConnection').style.display = 'flex';
        }

        function closeModalConnection() {
            document.getElementById('modalConnection').style.display = 'none';
        }
        </script>

    <?php else: ?>
        <!-- ==================== TAB SCHEDULER: ĐĂNG BÀI BUFFER ==================== -->
        <?php if (!$has_channels): ?>
            <div class="card-box" style="text-align: center; padding: 60px 20px; margin-bottom: 30px;">
                <span style="font-size: 48px; display: block; margin-bottom: 12px;">📡</span>
                <h3 style="margin: 0 0 8px 0; color: var(--text-main, #1e293b);">Chưa có kênh Buffer nào được quét</h3>
                <p style="color: var(--text-muted, #64748b); margin-bottom: 20px;">Vui lòng chuyển sang tab <strong>Quản Lý Kênh &amp; API Key</strong> để đồng bộ kênh trước khi đăng bài.</p>
                <a href="buffer.php?tab=channels" class="btn-add-connection" style="display:inline-flex;">➕ Thêm &amp; Đồng Bộ Kênh Buffer</a>
            </div>
        <?php else: ?>
            <form id="bufferPostForm" method="POST" action="actions/publish_buffer.php" enctype="multipart/form-data">
                <input type="hidden" name="redirect" value="1">
                <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
                <input type="hidden" id="drive_file_names" name="drive_file_names" value="">

                <div class="composer-grid">
                    <!-- Left: Channels Selection Panel -->
                    <div class="card-box">
                        <div class="card-title">
                            <span>🎯</span> Chọn Kênh Đăng Bài
                            <label style="margin-left: auto; font-size: 12px; font-weight: 600; cursor: pointer; color: #4f46e5;">
                                <input type="checkbox" onchange="toggleSelectAll(this)" style="vertical-align: middle;"> Chọn tất cả
                            </label>
                        </div>
                        
                        <div style="max-height: 560px; overflow-y: auto; padding-right: 4px;">
                            <?php foreach ($buffer_accounts as $acc): ?>
                                <div class="acc-group-header">
                                    <span>📧 <?php echo htmlspecialchars($acc['email']); ?></span>
                                    <small style="color: var(--text-muted, #64748b); font-weight: normal;"><?php echo count($acc['channels']); ?> kênh</small>
                                </div>
                                <?php foreach ($acc['channels'] as $c): ?>
                                    <label class="channel-checkbox-item">
                                        <input type="checkbox" name="channels[]" value="<?php echo htmlspecialchars($c['channel_id']); ?>" class="chan-checkbox">
                                        <?php if ($c['avatar']): ?>
                                            <img src="<?php echo htmlspecialchars($c['avatar']); ?>" class="channel-mini-avatar" alt="Avatar">
                                        <?php else: ?>
                                            <div class="channel-mini-avatar" style="background:#4f46e5; text-align:center; line-height:28px; font-size:12px; font-weight:bold; color:#ffffff;"><?php echo strtoupper(substr($c['channel_name'],0,1)); ?></div>
                                        <?php endif; ?>
                                        <div style="flex:1; min-width:0;">
                                            <div style="font-size:13px; font-weight:600; color:var(--text-main, #1e293b); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($c['channel_name']); ?></div>
                                            <div style="font-size:11px; color:var(--text-muted, #64748b);"><?php echo getPlatformBadgeShort($c['service']); ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Right: Post Composer Form -->
                    <div class="card-box">
                        <div class="card-title">
                            <span>✏️</span> Soạn Thảo Nội Dung Bài Đăng
                        </div>

                        <!-- Auto Options Box -->
                        <div class="form-group" style="background: rgba(236,72,153,0.08); padding: 14px 16px; border-radius: 12px; border: 1px dashed rgba(236,72,153,0.3); margin-bottom: 18px;">
                            <label style="color: #db2777; font-weight: 600; cursor: pointer; margin: 0;">
                                <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="width:16px; height:16px; vertical-align:middle;"> 
                                Tự động lấy Tên File / Tiêu đề TikTok làm Tiêu đề &amp; Mô tả
                            </label>
                        </div>

                        <!-- Content Textarea with Emoji Picker -->
                        <div class="form-group" style="position: relative;">
                            <label style="display: flex; justify-content: space-between; align-items: center;">
                                <span>Nội dung / Mô tả bài viết (*)</span>
                                <button type="button" id="emojiTriggerBuffer" class="emoji-picker-trigger" style="font-size:12px; padding:2px 8px;">😀 Emoji</button>
                            </label>
                            <div id="emojiPopupBuffer" class="emoji-picker-popup">
                                <div class="emoji-tabs"></div>
                                <div class="emoji-search-box"><input type="text" class="emoji-search-input" placeholder="Tìm emoji..."></div>
                                <div class="emoji-grid-wrap"></div>
                            </div>
                            <textarea name="text" id="post_text" rows="4" placeholder="Nhập nội dung bài đăng (hỗ trợ spin {nội dung 1|nội dung 2}, hashtags, emoji...)..."></textarea>
                        </div>

                        <!-- Media Source Selection (Tabs: Local, Drive, TikTok) -->
                        <div class="form-group">
                            <label>Chọn Nguồn Media (Ảnh / Video / Drive / Kho Data)</label>
                            <div class="media-source-tabs">
                                <button type="button" class="media-tab-btn active" onclick="switchMediaTab('local')">💻 Tải từ Máy Tính (Tự upload Drive)</button>
                                <button type="button" class="media-tab-btn" onclick="switchMediaTab('drive')">📁 Google Drive</button>
                                <button type="button" class="media-tab-btn" onclick="switchMediaTab('tiktok')">📁 Kho Data</button>
                            </div>

                            <!-- 1. Local Computer File Upload Panel -->
                            <div id="panel_local" style="display: block; background: var(--bg-color, #f8fafc); padding: 16px; border-radius: 12px; border: 1px dashed var(--border-color, #cbd5e1);">
                                <?php if ($disable_local_upload): ?>
                                    <div style="color: #d97706; font-size: 13px; font-weight: 600;">🔒 Admin đã tắt tính năng tải file trực tiếp từ máy tính. Vui lòng chọn tệp từ Google Drive hoặc Kho Data.</div>
                                <?php else: ?>
                                    <label style="font-size: 13px;">Chọn tệp từ máy tính (Ảnh / Video) - Hệ thống sẽ tự động tải lên Google Drive:</label>
                                    <input type="file" id="media_files" name="media_files[]" multiple accept="image/*,video/*" style="margin-top: 8px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; padding: 8px; background: var(--card-bg, #ffffff); color: var(--text-main, #1e293b);" onchange="clearDriveSelection()">
                                <?php endif; ?>
                            </div>

                            <!-- 2. Google Drive Picker Panel -->
                            <div id="panel_drive" style="display: none; background: rgba(14,165,233,0.08); padding: 16px; border-radius: 12px; border: 1px dashed rgba(14,165,233,0.25);">
                                <button type="button" onclick="openDriveModal('multiple')" style="background: var(--card-bg, #ffffff); border: 1px solid var(--border-color, #cbd5e1); color: #0284c7; font-weight:700; padding:9px 16px; border-radius:10px; cursor:pointer; display: flex; align-items: center; gap: 6px; font-size:13px;">
                                    📁 Chọn File / Thư Mục từ Google Drive
                                </button>
                                
                                <div id="driveSelectionInfo" style="margin-top: 12px; display: none; padding: 12px 16px; background: rgba(14,165,233,0.12); border: 1px solid rgba(14,165,233,0.25); border-radius: 10px; font-size: 13px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; color: #0284c7; font-weight: bold;">
                                        <span>Đã chọn <span id="driveSelectedCount">0</span> mục từ Drive:</span>
                                        <button type="button" onclick="clearDriveSelection()" style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12px;">Hủy chọn</button>
                                    </div>
                                    <ul id="driveSelectedList" style="margin: 6px 0 0 0; padding-left: 20px; color: var(--text-main, #0369a1); max-height: 100px; overflow-y: auto;"></ul>
                                </div>

                                <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed rgba(14,165,233,0.2);">
                                    <label style="color: #0d9488; font-weight: 500; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" name="delete_drive_file" value="1" style="width: 15px; height: 15px; accent-color: #14b8a6;">
                                        🛡️ Chống trùng bài và tự động xóa file sau khi đăng trên Google Drive
                                    </label>
                                </div>
                            </div>

                            <!-- 3. TikTok / Kho Data Panel -->
                            <div id="panel_tiktok" style="display: none;">
                                <?php include __DIR__ . '/includes/kho_data_selector.php'; ?>
                            </div>
                        </div>

                        <!-- Uploading Status Alert Bar -->
                        <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 10px 14px; border-radius: 8px; font-size: 13px;"></div>

                        <!-- Bulk Matrix Scheduler Box -->
                        <div class="form-group" style="background: var(--bg-color, #f8fafc); padding: 18px; border-radius: 12px; border: 1px solid var(--border-color, #e2e8f0); margin-top: 16px;">
                            <label style="color: #4f46e5; font-size: 15px; font-weight: 700; display: block; margin-bottom: 6px;">
                                5. Lên lịch tự động hàng loạt (Tùy chọn)
                            </label>
                            <p style="font-size: 13px; color: var(--text-muted, #64748b); margin-top: 0; margin-bottom: 16px; line-height: 1.4;">
                                Chọn khoảng ngày và các khung giờ, tối đa hẹn giờ 3 tháng một chiến dịch.
                            </p>

                            <div style="display: flex; gap: 14px; margin-bottom: 12px;">
                                <div style="flex: 1;">
                                    <label style="font-size: 12px; font-weight: 600; color: var(--text-muted, #64748b);">Từ ngày:</label>
                                    <input type="date" name="start_date" id="start_date">
                                </div>
                                <div style="flex: 1;">
                                    <label style="font-size: 12px; font-weight: 600; color: var(--text-muted, #64748b);">Đến ngày:</label>
                                    <input type="date" name="end_date" id="end_date">
                                </div>
                            </div>

                            <div>
                                <label style="font-size: 12px; font-weight: 600; color: var(--text-muted, #64748b);">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                                <input type="text" name="time_slots" id="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45">
                            </div>

                            <div style="margin-top: 14px; padding-top: 10px; border-top: 1px dashed var(--border-color, #cbd5e1); color: #0284c7; font-size: 12px;">
                                * Ghi chú: Nếu hệ thống tính toán ra cùng lịch cho nhiều video/bài đăng, chúng sẽ được xếp cách nhau 5 phút.
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 16px;">
                            <label style="cursor: pointer; font-weight: normal; color: var(--text-main, #1e293b);">
                                <input type="checkbox" name="use_ai" value="1" style="width: 16px; height: 16px; accent-color: #6366f1; vertical-align: middle;">
                                🤖 Tự động viết lại nội dung / tiêu đề bằng AI trước khi xuất bản
                            </label>
                        </div>

                        <div style="margin-top: 24px;">
                            <button type="submit" id="btn_submit" class="btn-submit-post">🚀 Gửi Bài Đăng Qua Buffer API</button>
                        </div>
                    </div>
                </div>
            </form>

            <script>
            function toggleSelectAll(master) {
                document.querySelectorAll('.chan-checkbox').forEach(cb => cb.checked = master.checked);
            }

            function switchMediaTab(tabName) {
                document.querySelectorAll('.media-tab-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('[id^="panel_"]').forEach(p => p.style.display = 'none');

                event.target.classList.add('active');
                document.getElementById('panel_' + tabName).style.display = 'block';
            }

            function onDriveFilesSelected(files) {
                if (files.length === 0) return;
                const fileIds = files.map(f => f.id).join(',');
                const fileNames = files.map(f => f.name).join('|||');
                
                document.getElementById('drive_file_id').value = fileIds;
                document.getElementById('drive_file_names').value = fileNames;
                
                const listEl = document.getElementById('driveSelectedList');
                listEl.innerHTML = '';
                files.forEach(f => {
                    const li = document.createElement('li');
                    li.textContent = f.name;
                    listEl.appendChild(li);
                });
                
                document.getElementById('driveSelectedCount').innerText = files.length;
                document.getElementById('driveSelectionInfo').style.display = 'block';
            }

            function onDriveFolderSelected(folderId, folderName) {
                document.getElementById('drive_file_id').value = 'folder:' + folderId;
                document.getElementById('drive_file_names').value = 'folder:' + folderName;

                const listEl = document.getElementById('driveSelectedList');
                if (listEl) {
                    listEl.innerHTML = `<li>📁 Thư mục Google Drive: <strong>${folderName}</strong></li>`;
                }

                document.getElementById('driveSelectedCount').innerText = 'Thư mục';
                document.getElementById('driveSelectionInfo').style.display = 'block';
            }

            function clearDriveSelection() {
                document.getElementById('drive_file_id').value = '';
                document.getElementById('drive_file_names').value = '';
                document.getElementById('driveSelectionInfo').style.display = 'none';
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
                                throw new Error(`Mạng hoặc máy chủ gặp sự cố khi tải file ${file.name} (HTTP ${response.status}): ${text}`);
                            }
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                throw new Error(`Lỗi phản hồi từ server (không phải JSON): ${text.substring(0, 500)}`);
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

            document.getElementById('bufferPostForm').addEventListener('submit', function(e) {
                e.preventDefault();

                const selectedChans = document.querySelectorAll('.chan-checkbox:checked');
                if (selectedChans.length === 0) {
                    if (typeof window.showNotice === 'function') {
                        window.showNotice('Vui lòng chọn ít nhất 1 kênh Buffer để đăng bài!', 'warning');
                    } else {
                        alert('Vui lòng chọn ít nhất 1 kênh Buffer để đăng bài!');
                    }
                    return;
                }

                const mediaInput = document.getElementById('media_files');
                const hasLocalFiles = mediaInput && mediaInput.files && mediaInput.files.length > 0;
                const driveVal = document.getElementById('drive_file_id').value;
                const dataGroupVal = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
                const hasMedia = hasLocalFiles || driveVal || dataGroupVal;

                let hasIg = false;
                let hasTiktok = false;
                selectedChans.forEach(cb => {
                    const text = cb.closest('label').textContent.toLowerCase();
                    if (text.includes('instagram')) hasIg = true;
                    if (text.includes('tiktok')) hasTiktok = true;
                });

                if (hasIg && !hasMedia) {
                    if (typeof window.showNotice === 'function') {
                        window.showNotice('📷 Kênh Instagram bắt buộc phải đính kèm ít nhất 1 hình ảnh hoặc video.', 'warning');
                    } else {
                        alert('📷 Kênh Instagram bắt buộc phải đính kèm ít nhất 1 hình ảnh hoặc video.');
                    }
                    return;
                }

                if (hasTiktok && !hasMedia) {
                    if (typeof window.showNotice === 'function') {
                        window.showNotice('🎵 Kênh TikTok bắt buộc phải đính kèm ít nhất 1 hình ảnh hoặc video.', 'warning');
                    } else {
                        alert('🎵 Kênh TikTok bắt buộc phải đính kèm ít nhất 1 hình ảnh hoặc video.');
                    }
                    return;
                }

                const btnSubmit = document.getElementById('btn_submit');
                const localStatus = document.getElementById('localUploadStatus');

                btnSubmit.disabled = true;
                btnSubmit.innerText = 'Đang xử lý tạo bài đăng...';

                uploadLocalFilesPromise(mediaInput, function(msg) {
                    if (localStatus) {
                        localStatus.style.display = 'block';
                        localStatus.className = 'alert alert-warning';
                        localStatus.style.background = 'rgba(245,158,11,0.15)';
                        localStatus.style.color = '#fbbf24';
                        localStatus.style.border = '1px solid rgba(245,158,11,0.3)';
                        localStatus.innerText = msg;
                    }
                    btnSubmit.innerText = 'Đang tải file lên Google Drive...';
                })
                .then(uploadedFiles => {
                    if (uploadedFiles && uploadedFiles.length > 0) {
                        const fileIds = uploadedFiles.map(f => f.id).join(',');
                        const fileNames = uploadedFiles.map(f => f.name).join('|||');

                        document.getElementById('drive_file_id').value = fileIds;
                        document.getElementById('drive_file_names').value = fileNames;
                        if (mediaInput) mediaInput.value = '';
                    }

                    btnSubmit.innerText = 'Đang đẩy bài vào hàng đợi...';
                    const formData = new FormData(document.getElementById('bufferPostForm'));
                    const kdGroup = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
                    const kdMode = document.getElementById('data_mode') ? document.getElementById('data_mode').value.trim() : 'dedup';
                    if (kdGroup) {
                        formData.set('data_group_id', kdGroup);
                        formData.set('data_mode', kdMode);
                    }

                    return fetch('actions/publish_buffer.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                })
                .then(async response => {
                    if (response instanceof Response) {
                        const text = await response.text();
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            if (text.includes('manage_posts') || response.redirected) {
                                return { status: 'success', redirect: 'manage_posts.php' };
                            }
                            throw new Error('Mã phản hồi từ server không phải JSON: ' + text.substring(0, 150));
                        }
                    }
                    throw new Error('Không nhận được phản hồi từ server.');
                })
                .then(data => {
                    if (data.status === 'success') {
                        window.location.href = data.redirect || 'manage_posts.php';
                    } else {
                        alert(data.msg || 'Có lỗi xảy ra khi tạo bài đăng.');
                        btnSubmit.disabled = false;
                        btnSubmit.innerText = '🚀 Gửi Bài Đăng Qua Buffer API';
                    }
                })
                .catch(err => {
                    alert('Lỗi: ' + (err.message || err));
                    btnSubmit.disabled = false;
                    btnSubmit.innerText = '🚀 Gửi Bài Đăng Qua Buffer API';
                });
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
            <script>initEmojiPicker('emojiTriggerBuffer', 'emojiPopupBuffer', 'post_text');</script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
