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
    .page-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .composer-grid {
        display: grid;
        grid-template-columns: 360px 1fr;
        gap: 24px;
        margin-bottom: 30px;
    }

    .card-box {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .card-title {
        font-size: 16px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 12px;
    }

    .acc-group-header {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        padding: 8px 10px;
        background: #f8fafc;
        border-radius: 6px;
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
        border-radius: 6px;
        transition: background 0.15s;
        cursor: pointer;
    }

    .channel-checkbox-item:hover {
        background: #f0f7ff;
    }

    .channel-checkbox-item input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #0284c7;
        cursor: pointer;
    }

    .channel-mini-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        object-fit: cover;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #475569;
        margin-bottom: 6px;
    }

    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 14px;
        outline: none;
        box-sizing: border-box;
    }

    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        border-color: #0284c7;
        box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
    }

    .btn-submit-post {
        width: 100%;
        padding: 12px;
        background: #0284c7;
        color: #fff;
        font-size: 15px;
        font-weight: 700;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .btn-submit-post:hover {
        background: #0369a1;
    }

    .media-source-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 14px;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 8px;
    }
    .media-tab-btn {
        padding: 6px 14px;
        font-size: 13px;
        font-weight: 600;
        color: #64748b;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .media-tab-btn.active {
        background: #0284c7;
        color: #fff;
        border-color: #0284c7;
    }

    /* Channels Management UI */
    .api-header-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    
    .api-title {
        font-size: 20px;
        font-weight: 800;
        color: #1e293b;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .api-subtitle {
        font-size: 14px;
        color: #64748b;
        line-height: 1.5;
        margin-bottom: 20px;
    }

    .api-subtitle a {
        color: #0284c7;
        font-weight: 600;
        text-decoration: underline;
    }

    .btn-add-connection {
        background: #0284c7;
        color: #fff;
        font-size: 14px;
        font-weight: 700;
        padding: 10px 20px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: background 0.2s;
        text-decoration: none;
    }

    .btn-add-connection:hover {
        background: #0369a1;
    }

    .acc-block {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        margin-bottom: 24px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        overflow: hidden;
    }

    .acc-header {
        padding: 16px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .acc-email {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
    }

    .acc-token-snippet {
        font-size: 12px;
        color: #94a3b8;
        font-family: monospace;
        margin-left: 6px;
    }

    .acc-sync-time {
        font-size: 12px;
        color: #64748b;
        margin-top: 2px;
    }

    .acc-actions {
        display: flex;
        gap: 8px;
    }

    .btn-action {
        padding: 6px 14px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.15s;
        text-decoration: none;
    }

    .btn-action:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
    }

    .btn-action-danger {
        color: #dc2626;
        border-color: #fecaca;
    }
    .btn-action-danger:hover {
        background: #fef2f2;
        border-color: #f87171;
    }

    .chan-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .chan-table th {
        background: #fafafa;
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 10px 16px;
        border-bottom: 1px solid #e2e8f0;
        text-align: left;
    }

    .chan-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        vertical-align: middle;
    }

    .chan-table tr:last-child td {
        border-bottom: none;
    }

    .chan-table tr:hover td {
        background: #f8fafc;
    }

    .channel-link {
        color: #0284c7;
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
        border: 1px solid #e2e8f0;
    }

    .channel-avatar-placeholder {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #e2e8f0;
        color: #64748b;
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
    .platform-default { background: #e2e8f0; color: #475569; }

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
        background: #fff;
        border-radius: 12px;
        width: 100%;
        max-width: 500px;
        padding: 24px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    .modal-title {
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
    }
    .modal-close {
        font-size: 20px;
        color: #94a3b8;
        cursor: pointer;
        border: none;
        background: transparent;
    }
</style>

<div class="page-container">
    <div class="page-title" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <div style="font-size: 22px; font-weight: 800; color: var(--text-main);">Hệ Thống Buffer AI Multi-Channel</div>
        <?php if ($active_tab === 'channels'): ?>
            <button onclick="openModalAddConnection()" class="btn-add-connection">
                <span>➕</span> THÊM KẾT NỐI BUFFER MỚI
            </button>
        <?php endif; ?>
    </div>

    <!-- Navigation Tabs -->
    <div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:2px solid var(--border-color); padding-bottom:12px;">
        <a href="buffer.php?tab=scheduler" style="padding:9px 18px; border-radius:8px; font-weight:600; font-size:14px; text-decoration:none; display:flex; align-items:center; gap:8px; transition:all 0.2s; <?php echo ($active_tab === 'scheduler') ? 'background:var(--primary-color, #0284c7); color:white;' : 'background:#f1f5f9; color:var(--text-main);'; ?>">
            🚀 Đăng Bài &amp; Lịch Trình Buffer
        </a>
        <a href="buffer.php?tab=channels" style="padding:9px 18px; border-radius:8px; font-weight:600; font-size:14px; text-decoration:none; display:flex; align-items:center; gap:8px; transition:all 0.2s; <?php echo ($active_tab === 'channels') ? 'background:var(--primary-color, #0284c7); color:white;' : 'background:#f1f5f9; color:var(--text-main);'; ?>">
            📡 Quản Lý Kênh &amp; API Key (<?php echo $total_channels; ?>)
        </a>
    </div>

    <!-- PHP Flash Notifications -->
    <?php if (isset($_SESSION['flash_msg'])): ?>
        <div style="margin-bottom: 20px; padding: 14px 18px; background: #dcfce7; color: #15803d; border-radius: 8px; border: 1px solid #bbf7d0; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <span>✅</span>
            <div><?php echo htmlspecialchars($_SESSION['flash_msg']); unset($_SESSION['flash_msg']); ?></div>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_error'])): ?>
        <div style="margin-bottom: 20px; padding: 14px 18px; background: #fef2f2; color: #dc2626; border-radius: 8px; border: 1px solid #fecaca; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <span>⚠️</span>
            <div><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
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
                    <button type="submit" class="btn-action" style="background:#f8fafc; font-weight:700;">
                        <span>🔄</span> ĐỒNG BỘ TẤT CẢ KÊNH
                    </button>
                </form>
            </div>
        </div>

        <?php if (empty($buffer_accounts)): ?>
            <div class="acc-block" style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                <span style="font-size: 48px; display: block; margin-bottom: 12px;">📡</span>
                <h3 style="margin: 0 0 8px 0; color: #475569;">Chưa có kết nối Buffer API nào</h3>
                <p style="font-size: 14px; margin-bottom: 20px;">Vui lòng bấm nút <strong>+ THÊM KẾT NỐI</strong> ở trên để thêm Email &amp; Access Token của Buffer.</p>
                <button onclick="openModalAddConnection()" class="btn-add-connection">➕ THÊM KẾT NỐI BUFFER MỚI</button>
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
                                    <td colspan="6" style="text-align: center; padding: 24px; color: #94a3b8;">
                                        Chưa quét thấy kênh nào thuộc tài khoản này. Bấm <strong>Đồng bộ lại</strong> để quét lại kênh từ Buffer.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($acc['channels'] as $idx => $chan): ?>
                                    <tr>
                                        <td style="color: #94a3b8; font-weight: 600;"><?php echo ($idx + 1); ?></td>
                                        <td style="font-weight: 600; color: #475569;"><?php echo htmlspecialchars($chan['organization'] ?: 'My Organization'); ?></td>
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
                                        <td style="font-family: monospace; font-size: 12px; color: #64748b; font-weight: 600;">
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

                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" onclick="closeModalConnection()" class="btn-action" style="margin-right: 8px;">Hủy</button>
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
            <div class="card-box" style="text-align: center; padding: 50px 20px; margin-bottom: 30px;">
                <span style="font-size: 48px; display: block; margin-bottom: 12px;">📡</span>
                <h3 style="margin: 0 0 8px 0; color: #475569;">Chưa có kênh Buffer nào được quét</h3>
                <p style="color: #64748b; margin-bottom: 20px;">Vui lòng chuyển sang tab <strong>Quản Lý Kênh &amp; API Key</strong> để đồng bộ kênh trước khi đăng bài.</p>
                <a href="buffer.php?tab=channels" class="btn-add-connection">➕ Thêm &amp; Đồng Bộ Kênh Buffer</a>
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
                            <label style="margin-left: auto; font-size: 12px; font-weight: 500; cursor: pointer; color: #0284c7;">
                                <input type="checkbox" onchange="toggleSelectAll(this)" style="vertical-align: middle;"> Chọn tất cả
                            </label>
                        </div>
                        
                        <div style="max-height: 560px; overflow-y: auto; padding-right: 4px;">
                            <?php foreach ($buffer_accounts as $acc): ?>
                                <div class="acc-group-header">
                                    <span>📧 <?php echo htmlspecialchars($acc['email']); ?></span>
                                    <small style="color: #64748b; font-weight: normal;"><?php echo count($acc['channels']); ?> kênh</small>
                                </div>
                                <?php foreach ($acc['channels'] as $c): ?>
                                    <label class="channel-checkbox-item">
                                        <input type="checkbox" name="channels[]" value="<?php echo htmlspecialchars($c['channel_id']); ?>" class="chan-checkbox">
                                        <?php if ($c['avatar']): ?>
                                            <img src="<?php echo htmlspecialchars($c['avatar']); ?>" class="channel-mini-avatar" alt="Avatar">
                                        <?php else: ?>
                                            <div class="channel-mini-avatar" style="background:#cbd5e1; text-align:center; line-height:28px; font-size:12px; font-weight:bold; color:#475569;"><?php echo strtoupper(substr($c['channel_name'],0,1)); ?></div>
                                        <?php endif; ?>
                                        <div style="flex:1; min-width:0;">
                                            <div style="font-size:13px; font-weight:600; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($c['channel_name']); ?></div>
                                            <div style="font-size:11px; color:#64748b;"><?php echo getPlatformBadgeShort($c['service']); ?></div>
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
                        <div class="form-group" style="background: #fdf2f8; padding: 12px 15px; border-radius: 8px; border: 1px dashed #fbcfe8; margin-bottom: 16px;">
                            <label style="color: #be185d; font-weight: 600; cursor: pointer; margin: 0;">
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
                            <label>Chọn Nguồn Media (Ảnh / Video / Link TikTok / Drive)</label>
                            <div class="media-source-tabs">
                                <button type="button" class="media-tab-btn active" onclick="switchMediaTab('local')">💻 Tải từ Máy Tính (Tự upload Drive)</button>
                                <button type="button" class="media-tab-btn" onclick="switchMediaTab('drive')">📁 Google Drive</button>
                                <button type="button" class="media-tab-btn" onclick="switchMediaTab('tiktok')">🎵 Link TikTok</button>
                            </div>

                            <!-- 1. Local Computer File Upload Panel -->
                            <div id="panel_local" style="display: block; background: #fafafa; padding: 14px; border-radius: 8px; border: 1px dashed #cbd5e1;">
                                <?php if ($disable_local_upload): ?>
                                    <div style="color: #b45309; font-size: 13px; font-weight: 600;">🔒 Admin đã tắt tính năng tải file trực tiếp từ máy tính. Vui lòng chọn tệp từ Google Drive hoặc TikTok.</div>
                                <?php else: ?>
                                    <label style="font-size: 13px;">Chọn tệp từ máy tính (Ảnh / Video) - Hệ thống sẽ tự động tải lên Google Drive:</label>
                                    <input type="file" id="media_files" name="media_files[]" multiple accept="image/*,video/*" style="margin-top: 6px;" onchange="clearDriveSelection()">
                                <?php endif; ?>
                            </div>

                            <!-- 2. Google Drive Picker Panel -->
                            <div id="panel_drive" style="display: none; background: #f0f9ff; padding: 14px; border-radius: 8px; border: 1px dashed #bae6fd;">
                                <button type="button" class="btn btn-secondary" onclick="openDriveModal('multiple')" style="background: #fff; border: 1px solid #cbd5e1; color: #0369a1; font-weight:700; display: flex; align-items: center; gap: 6px;">
                                    📁 Chọn File / Thư Mục từ Google Drive
                                </button>
                                
                                <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 10px 14px; background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 6px; font-size: 13px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; color: #0369a1; font-weight: bold;">
                                        <span>Đã chọn <span id="driveSelectedCount">0</span> mục từ Drive:</span>
                                        <button type="button" onclick="clearDriveSelection()" style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12px;">Hủy chọn</button>
                                    </div>
                                    <ul id="driveSelectedList" style="margin: 6px 0 0 0; padding-left: 20px; color: #0c4a6e; max-height: 100px; overflow-y: auto;"></ul>
                                </div>

                                <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #bae6fd;">
                                    <label style="color: #0d9488; font-weight: 500; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" name="delete_drive_file" value="1" style="width: 15px; height: 15px; accent-color: #0d9488;">
                                        🛡️ Chống trùng bài và tự động xóa file sau khi đăng trên Google Drive
                                    </label>
                                </div>
                            </div>

                            <!-- 3. TikTok Bulk URLs Panel -->
                            <div id="panel_tiktok" style="display: none; background: #fdf2f8; padding: 14px; border-radius: 8px; border: 1px dashed #fbcfe8;">
                                <label style="color: #be185d; font-size: 13px;">Dán danh sách Link TikTok (Tự động tải Video không logo &amp; tiêu đề gốc):</label>
                                <textarea name="tiktok_urls" id="tiktok_urls" rows="3" placeholder="https://www.tiktok.com/@user/video/123456789&#10;https://www.tiktok.com/@user/video/987654321..." style="margin-top: 6px; font-size: 13px;"></textarea>
                            </div>
                        </div>

                        <!-- Uploading Status Alert Bar -->
                        <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 10px 14px; border-radius: 6px; font-size: 13px;"></div>

                        <!-- Bulk Matrix Scheduler Box -->
                        <div class="form-group" style="background: #faf5ff; padding: 16px; border-radius: 8px; border: 1px solid #e9d5ff; margin-top: 15px;">
                            <label style="color: #6b21a8; font-size: 15px; font-weight: 700; display: block; margin-bottom: 6px;">
                                5. Lên lịch tự động hàng loạt (Tùy chọn)
                            </label>
                            <p style="font-size: 13px; color: #7e22ce; margin-top: 0; margin-bottom: 14px; line-height: 1.4;">
                                Chọn khoảng ngày và các khung giờ. Hệ thống sẽ trộn ngẫu nhiên tất cả các bài bạn cung cấp (từ file, link tiktok, drive) và xếp lịch rải đều. Nếu bạn không nhập lịch, tất cả sẽ được đăng / push lên hàng đợi ngay lập tức.
                            </p>

                            <div style="display: flex; gap: 15px; margin-bottom: 12px;">
                                <div style="flex: 1;">
                                    <label style="font-size: 13px; font-weight: 600; color: #475569;">Từ ngày:</label>
                                    <input type="date" name="start_date" id="start_date" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                </div>
                                <div style="flex: 1;">
                                    <label style="font-size: 13px; font-weight: 600; color: #475569;">Đến ngày:</label>
                                    <input type="date" name="end_date" id="end_date" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                </div>
                            </div>

                            <div>
                                <label style="font-size: 13px; font-weight: 600; color: #475569;">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                                <input type="text" name="time_slots" id="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            </div>

                            <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed #d8b4fe; color: #6b21a8; font-size: 12px;">
                                * Ghi chú: Nếu hệ thống tính toán ra cùng lịch cho nhiều video/bài đăng, chúng sẽ được xếp cách nhau 5 phút.
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 14px;">
                            <label style="cursor: pointer; font-weight: normal; color: #0284c7;">
                                <input type="checkbox" name="use_ai" value="1" style="width: 16px; height: 16px; vertical-align: middle;">
                                🤖 Tự động viết lại nội dung / tiêu đề bằng AI trước khi xuất bản
                            </label>
                        </div>

                        <div style="margin-top: 20px;">
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
                    alert('Vui lòng chọn ít nhất 1 kênh Buffer để đăng bài!');
                    return;
                }

                const mediaInput = document.getElementById('media_files');
                const hasLocalFiles = mediaInput && mediaInput.files && mediaInput.files.length > 0;
                const driveVal = document.getElementById('drive_file_id').value;
                const tiktokVal = document.getElementById('tiktok_urls') ? document.getElementById('tiktok_urls').value.trim() : '';
                const hasMedia = hasLocalFiles || driveVal || tiktokVal;

                let hasIg = false;
                let hasTiktok = false;
                selectedChans.forEach(cb => {
                    const text = cb.closest('label').textContent.toLowerCase();
                    if (text.includes('instagram')) hasIg = true;
                    if (text.includes('tiktok')) hasTiktok = true;
                });

                if (hasIg && !hasMedia) {
                    alert('📷 Kênh Instagram bắt buộc phải đính kèm ít nhất 1 hình ảnh hoặc video. Vui lòng chọn tệp media trước khi đăng!');
                    return;
                }

                if (hasTiktok && !hasMedia) {
                    alert('🎵 Kênh TikTok bắt buộc phải đính kèm ít nhất 1 hình ảnh hoặc video. Vui lòng chọn tệp media trước khi đăng!');
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
                        localStatus.style.background = '#fef3cd';
                        localStatus.style.color = '#856404';
                        localStatus.style.border = '1px solid #ffeeba';
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
            </script>

            <?php include 'includes/drive_browser.php'; ?>
            <?php include 'includes/emoji_picker.php'; ?>
            <script>initEmojiPicker('emojiTriggerBuffer', 'emojiPopupBuffer', 'post_text');</script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
