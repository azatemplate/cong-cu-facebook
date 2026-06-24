<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];

// Fetch system account youtube_multi_api setting
$stmt_acc = $pdo->prepare("SELECT youtube_multi_api FROM system_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$acc_info = $stmt_acc->fetch(PDO::FETCH_ASSOC);
$youtube_multi_api = (!empty($acc_info['youtube_multi_api']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) ? 1 : 0;

// Handle POST actions before any output
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
    header("Location: youtube_channels.php");
    exit;
}

if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $pdo->prepare("DELETE FROM youtube_channels WHERE id = ? AND account_id = ?")->execute([$del_id, $account_id]);
    $_SESSION['flash_msg'] = "Đã xóa kênh YouTube thành công.";
    header("Location: youtube_channels.php");
    exit;
}

$current_page = 'youtube_channels';
require_once __DIR__ . '/includes/header.php';

$stmt = $pdo->prepare("SELECT * FROM youtube_channels WHERE account_id = ? ORDER BY created_at DESC");
$stmt->execute([$account_id]);
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-title" style="display: flex; justify-content: space-between; align-items: center;">
    <div>Tài khoản YouTube</div>
    <?php if ($youtube_multi_api === 1): ?>
        <button onclick="openAddChannelModal()" class="btn btn-primary" style="display: flex; align-items: center; gap: 6px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-family: inherit;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
            Thêm kênh YouTube mới
        </button>
    <?php else: ?>
        <a href="youtube_login.php" class="btn btn-primary" style="display: flex; align-items: center; gap: 6px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
            Thêm kênh YouTube mới
        </a>
    <?php endif; ?>
</div>

<?php if (isset($_SESSION['flash_msg'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">
        <?php 
        echo htmlspecialchars($_SESSION['flash_msg']); 
        unset($_SESSION['flash_msg']);
        ?>
    </div>
<?php endif; ?>

<div class="card">
    <table class="table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color);">Kênh</th>
                <th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color);">Tên kênh</th>
                <?php if ($youtube_multi_api === 1): ?>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color);">Cấu hình API</th>
                <?php endif; ?>
                <th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color);">Thêm lúc</th>
                <th style="padding: 12px; text-align: right; border-bottom: 1px solid var(--border-color);">Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($channels) > 0): ?>
                <?php foreach ($channels as $channel): ?>
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                            <?php if ($channel['channel_avatar']): ?>
                                <img src="<?php echo htmlspecialchars($channel['channel_avatar']); ?>" alt="Avatar" style="width: 40px; height: 40px; border-radius: 50%;">
                            <?php else: ?>
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: #ccc; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #fff;">YT</div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                            <strong><?php echo htmlspecialchars($channel['channel_title']); ?></strong><br>
                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($channel['channel_id']); ?></small>
                        </td>
                        <?php if ($youtube_multi_api === 1): ?>
                            <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                                <?php if (!empty($channel['gg_client_id'])): ?>
                                    <span class="status-tag" style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">API Riêng</span><br>
                                    <small style="color: var(--text-muted); font-size: 11px; word-break: break-all;">ID: <?php echo htmlspecialchars(substr($channel['gg_client_id'], 0, 15)); ?>...</small>
                                <?php else: ?>
                                    <span class="status-tag" style="background: #f1f5f9; color: #475569; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">Mặc định</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                            <?php echo date('d/m/Y H:i', strtotime($channel['created_at'])); ?>
                        </td>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border-color); text-align: right;">
                            <?php if ($youtube_multi_api === 1): ?>
                                <button onclick="openEditApiModal(<?php echo $channel['id']; ?>, '<?php echo htmlspecialchars($channel['channel_title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($channel['gg_client_id'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($channel['gg_client_secret'] ?? '', ENT_QUOTES); ?>')" class="btn" style="background: var(--primary-color); color: white; padding: 4px 8px; font-size: 13px; margin-right: 5px; border: none; border-radius: 4px; cursor: pointer;">Sửa API</button>
                            <?php endif; ?>
                            
                            <?php if ($youtube_multi_api === 1 && !empty($channel['gg_client_id'])): ?>
                                <a href="youtube_login.php?reauth_channel_id=<?php echo $channel['id']; ?>" class="btn" style="background: #10b981; color: white; padding: 4.5px 8px; font-size: 13px; margin-right: 5px; text-decoration: none; border-radius: 4px; display: inline-block;">Cấp quyền lại</a>
                            <?php else: ?>
                                <a href="youtube_login.php" class="btn" style="background: #10b981; color: white; padding: 4.5px 8px; font-size: 13px; margin-right: 5px; text-decoration: none; border-radius: 4px; display: inline-block;">Cấp quyền lại</a>
                            <?php endif; ?>
                            
                            <a href="youtube_channels.php?delete=<?php echo $channel['id']; ?>" class="btn btn-danger" style="padding: 4px 8px; font-size: 13px;" onclick="return confirm('Bạn có chắc chắn muốn xóa kênh này?');">Xóa kênh</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?php echo ($youtube_multi_api === 1) ? 5 : 4; ?>" style="padding: 20px; text-align: center; color: var(--text-muted);">
                        Chưa có kênh YouTube nào được liên kết.<br>
                        <br>
                        <?php if ($youtube_multi_api === 1): ?>
                            <button onclick="openAddChannelModal()" class="btn btn-secondary">Liên kết kênh đầu tiên</button>
                        <?php else: ?>
                            <a href="youtube_login.php" class="btn btn-secondary">Liên kết kênh đầu tiên</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Thêm Kênh YouTube mới -->
<div id="addChannelModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:25px; border-radius:8px; width:100%; max-width:450px; position:relative; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
        <h3 style="margin-top:0;">Thêm Kênh YouTube Mới</h3>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">
            Nếu muốn dùng dự án Google Console riêng để tăng quota (hoặc tránh dùng chung quota với các kênh khác), hãy nhập Client ID & Secret ở dưới. Nếu để trống, hệ thống sẽ sử dụng cấu hình mặc định trong phần Cài đặt.
        </p>
        <form method="GET" action="youtube_login.php">
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client ID (Tùy chọn)</label>
                <input type="text" name="gg_client_id" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
            </div>
            
            <div class="form-group" style="margin-bottom: 25px;">
                <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client Secret (Tùy chọn)</label>
                <input type="text" name="gg_client_secret" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
            </div>
            
            <div style="text-align: right;">
                <button type="button" onclick="closeAddChannelModal()" class="btn" style="background:#f3f4f6; color:#374151; margin-right:10px; border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Hủy</button>
                <button type="submit" class="btn btn-primary" style="border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Tiếp Tục Kết Nối</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Sửa API Google của Kênh -->
<div id="editApiModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:25px; border-radius:8px; width:100%; max-width:450px; position:relative; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
        <h3 style="margin-top:0;">Sửa Cấu Hình Google API Kênh: <span id="e_channel_title_label" style="color:var(--primary-color);"></span></h3>
        <form method="POST" action="youtube_channels.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_api">
            <input type="hidden" name="channel_id_db" id="e_channel_id_db">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client ID riêng</label>
                <input type="text" id="e_gg_client_id" name="gg_client_id" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
            </div>
            
            <div class="form-group" style="margin-bottom: 25px;">
                <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client Secret riêng</label>
                <input type="text" id="e_gg_client_secret" name="gg_client_secret" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
            </div>
            
            <div style="text-align: right;">
                <button type="button" onclick="closeEditApiModal()" class="btn" style="background:#f3f4f6; color:#374151; margin-right:10px; border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Hủy</button>
                <button type="submit" class="btn btn-primary" style="border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Lưu Thay Đổi</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddChannelModal() {
    document.getElementById('addChannelModal').style.display = 'flex';
}
function closeAddChannelModal() {
    document.getElementById('addChannelModal').style.display = 'none';
}
function openEditApiModal(id, title, client_id, client_secret) {
    document.getElementById('e_channel_id_db').value = id;
    document.getElementById('e_channel_title_label').textContent = title;
    document.getElementById('e_gg_client_id').value = client_id;
    document.getElementById('e_gg_client_secret').value = client_secret;
    
    document.getElementById('editApiModal').style.display = 'flex';
}
function closeEditApiModal() {
    document.getElementById('editApiModal').style.display = 'none';
}
</script>

<?php include 'includes/footer.php'; ?>
