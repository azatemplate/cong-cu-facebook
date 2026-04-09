<?php
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];

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
    <a href="youtube_login.php" class="btn btn-primary" style="display: flex; align-items: center; gap: 6px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
        Thêm kênh YouTube mới
    </a>
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
                        <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                            <?php echo date('d/m/Y H:i', strtotime($channel['created_at'])); ?>
                        </td>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border-color); text-align: right;">
                            <a href="youtube_channels.php?delete=<?php echo $channel['id']; ?>" class="btn btn-danger" style="padding: 4px 8px; font-size: 13px;">Xóa kênh</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">
                        Chưa có kênh YouTube nào được liên kết.<br>
                        <br>
                        <a href="youtube_login.php" class="btn btn-secondary">Liên kết kênh đầu tiên</a>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
