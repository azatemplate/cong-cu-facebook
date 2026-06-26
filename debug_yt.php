<?php
// debug_yt.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');

$campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 1748;

// Xử lý xoá lock nếu có yêu cầu
$msg = '';
if (isset($_POST['action']) && $_POST['action'] === 'clear_locks') {
    $lock_dir = __DIR__ . '/locks';
    if (is_dir($lock_dir)) {
        $files = glob($lock_dir . '/*.lock');
        $deleted = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
        $msg = "<div style='padding: 10px; background: #dcfce7; color: #16a34a; border-radius: 4px; margin-bottom: 15px;'>Đã xoá thành công $deleted file lock!</div>";
    } else {
        $msg = "<div style='padding: 10px; background: #fee2e2; color: #dc2626; border-radius: 4px; margin-bottom: 15px;'>Thư mục locks không tồn tại!</div>";
    }
}

// Xử lý Reset status về pending để chạy lại
if (isset($_POST['action']) && $_POST['action'] === 'reset_posts') {
    $stmt = $pdo->prepare("UPDATE scheduled_posts SET status = 'pending', error_msg = NULL, retry_count = 0 WHERE campaign_id = ?");
    $stmt->execute([$campaign_id]);
    $msg = "<div style='padding: 10px; background: #dcfce7; color: #16a34a; border-radius: 4px; margin-bottom: 15px;'>Đã reset trạng thái tất cả các bài thuộc chiến dịch $campaign_id về 'pending' (Đang chờ) thành công!</div>";
}

// 1. Lấy thông tin các bài đăng của chiến dịch
$stmt = $pdo->prepare("
    SELECT sp.*, sa.username AS acc_username, sa.expire_date AS acc_expire
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.campaign_id = ?
    ORDER BY sp.scheduled_time ASC
");
$stmt->execute([$campaign_id]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Lấy danh sách kênh YouTube liên quan
$channels = [];
if (!empty($posts)) {
    $page_ids = array_unique(array_filter(array_column($posts, 'page_id')));
    if (!empty($page_ids)) {
        $placeholders = implode(',', array_fill(0, count($page_ids), '?'));
        $stmt_yt = $pdo->prepare("
            SELECT yc.*, sa.username AS acc_username 
            FROM youtube_channels yc
            JOIN system_accounts sa ON yc.account_id = sa.id
            WHERE yc.id IN ($placeholders)
        ");
        $stmt_yt->execute($page_ids);
        while ($row = $stmt_yt->fetch(PDO::FETCH_ASSOC)) {
            $channels[$row['id']] = $row;
        }
    }
}

// 3. Kiểm tra các file lock hiện có
$lock_files = [];
$lock_dir = __DIR__ . '/locks';
if (is_dir($lock_dir)) {
    $files = glob($lock_dir . '/*.lock');
    foreach ($files as $f) {
        $lock_files[] = [
            'name' => basename($f),
            'mtime' => date('Y-m-d H:i:s', filemtime($f)),
            'size' => filesize($f),
            'age' => time() - filemtime($f)
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Chẩn đoán chiến dịch YouTube #<?= $campaign_id ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 30px; background: #0f172a; color: #e2e8f0; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #1e293b; padding: 24px; border-radius: 12px; border: 1px solid #334155; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        h1, h2, h3 { margin-top: 0; color: #38bdf8; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: #0f172a; border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #334155; font-size: 14px; }
        th { background: #1e293b; color: #94a3b8; font-weight: 600; }
        tr:hover { background: #1e293b; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: bold; }
        .badge-pending { background: rgba(234, 179, 8, 0.2); color: #eab308; }
        .badge-processing { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .badge-published { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
        .badge-failed { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .btn { display: inline-block; padding: 10px 20px; background: #0284c7; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; border: none; cursor: pointer; font-size: 14px; margin-right: 10px; }
        .btn:hover { background: #0369a1; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-warning { background: #d97706; }
        .btn-warning:hover { background: #b45309; }
        .flex { display: flex; gap: 15px; align-items: center; }
        .mono { font-family: monospace; background: #0f172a; padding: 2px 6px; border-radius: 4px; color: #f43f5e; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .info-item { background: #0f172a; padding: 15px; border-radius: 8px; border: 1px solid #334155; }
        .info-label { font-size: 12px; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; }
        .info-value { font-size: 16px; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <h1 style="margin:0;">Bảng kiểm tra & Chẩn đoán YouTube (Camp #<?= $campaign_id ?>)</h1>
        <a href="diagnostics.php" class="btn" style="background:#475569;">Quay lại Chẩn đoán chung</a>
    </div>

    <?= $msg ?>

    <div class="card">
        <h2>Công cụ xử lý nhanh</h2>
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="clear_locks">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Bạn có chắc chắn muốn giải phóng toàn bộ file lock? Việc này giúp các luồng bị treo chạy lại được ngay.');">
                    🔓 Giải phóng toàn bộ Lock file
                </button>
            </form>

            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="reset_posts">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Bạn có chắc chắn muốn reset trạng thái các bài viết của camp này về pending?');">
                    🔄 Reset trạng thái bài về 'Đang chờ'
                </button>
            </form>

            <a href="cron/start_publish.php" target="_blank" class="btn" style="background:#10b981;">
                🚀 Chạy luồng Dispatcher (start_publish.php)
            </a>
        </div>
        <p style="font-size: 12px; color: #94a3b8; margin-top: 10px; margin-bottom: 0;">
            * Mẹo: Click "Chạy luồng Dispatcher" sẽ mở tab mới để kích hoạt cron chạy ngay lập tức mà không cần đợi chu kỳ phút tiếp theo.
        </p>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Tổng số bài của Camp</div>
            <div class="info-value"><?= count($posts) ?> bài</div>
        </div>
        <div class="info-item">
            <div class="info-label">Đang chờ (Pending)</div>
            <div class="info-value" style="color:#eab308;"><?= count(array_filter($posts, function($p) { return $p['status'] == 'pending'; })) ?> bài</div>
        </div>
        <div class="info-item">
            <div class="info-label">Đang xử lý (Processing)</div>
            <div class="info-value" style="color:#3b82f6;"><?= count(array_filter($posts, function($p) { return $p['status'] == 'processing'; })) ?> bài</div>
        </div>
        <div class="info-item">
            <div class="info-label">Thất bại (Failed)</div>
            <div class="info-value" style="color:#ef4444;"><?= count(array_filter($posts, function($p) { return $p['status'] == 'failed'; })) ?> bài</div>
        </div>
    </div>

    <div class="card">
        <h2>Trạng thái Lock files hiện tại (Thư mục `/locks`)</h2>
        <?php if (empty($lock_files)): ?>
            <p style="color:#22c55e; margin:0;">Không có file lock nào hoạt động. Hệ thống sạch!</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Tên Lock file</th>
                        <th>Ngày tạo/Cập nhật</th>
                        <th>Kích thước</th>
                        <th>Thời gian tồn tại</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lock_files as $lf): ?>
                    <tr>
                        <td class="mono" style="color:#38bdf8;"><?= htmlspecialchars($lf['name']) ?></td>
                        <td><?= $lf['mtime'] ?></td>
                        <td><?= $lf['size'] ?> bytes</td>
                        <td><?= round($lf['age'] / 60, 1) ?> phút</td>
                        <td>
                            <?php if ($lf['age'] > 900): ?>
                                <span class="badge badge-failed">Quá hạn (>15 phút)</span>
                            <?php else: ?>
                                <span class="badge badge-processing">Đang chạy</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Danh sách các bài đăng trong Camp #<?= $campaign_id ?></h2>
        <?php if (empty($posts)): ?>
            <p>Không tìm thấy bài viết nào.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Kênh YouTube</th>
                            <th>Đường dẫn Media</th>
                            <th>Giờ hẹn đăng</th>
                            <th>Cập nhật cuối</th>
                            <th>Thử lại</th>
                            <th>Trạng thái</th>
                            <th>Thông tin lỗi</th>
                            <th>Chạy thủ công</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $p): ?>
                        <?php 
                            $yt_chan = isset($channels[$p['page_id']]) ? $channels[$p['page_id']] : null;
                            $chan_title = $yt_chan ? $yt_chan['channel_title'] : "Không rõ (ID: {$p['page_id']})";
                            $has_token = $yt_chan && !empty($yt_chan['refresh_token']);
                        ?>
                        <tr>
                            <td class="mono" style="font-weight:bold;">#<?= $p['id'] ?></td>
                            <td>
                                <strong style="color:#a78bfa;"><?= htmlspecialchars($chan_title) ?></strong><br>
                                <span style="font-size:11px; color:#94a3b8;">
                                    Tài khoản: <?= htmlspecialchars($p['acc_username'] ?? 'Không rõ') ?><br>
                                    Refresh Token: <?= $has_token ? "<span style='color:#22c55e;'>Đã kết nối</span>" : "<span style='color:#ef4444;'>Thiếu token</span>" ?>
                                </span>
                            </td>
                            <td class="mono" style="font-size:12px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($p['media_path']) ?>">
                                <?= htmlspecialchars($p['media_path']) ?>
                            </td>
                            <td><?= $p['scheduled_time'] ?></td>
                            <td><?= $p['updated_at'] ?></td>
                            <td><?= $p['retry_count'] ?>/<?= $p['limit_retries'] ?></td>
                            <td>
                                <span class="badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span>
                            </td>
                            <td style="color:#ef4444; font-size:12px; max-width: 250px;" title="<?= htmlspecialchars($p['error_msg'] ?? '') ?>">
                                <?= htmlspecialchars($p['error_msg'] ?? '-') ?>
                            </td>
                            <td>
                                <?php if ($p['status'] !== 'published'): ?>
                                <a href="cron/publish_worker.php?page_id=<?= urlencode($p['page_id']) ?>&user_id=yt_<?= urlencode($p['account_id']) ?>" target="_blank" class="btn" style="padding: 4px 8px; font-size: 11px; background: #10b981; margin:0;">
                                    ▶ Chạy ngay
                                </a>
                                <?php else: ?>
                                -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
