<?php
// view_publish_log.php - Xem log đăng bài trực tiếp trên trình duyệt
require_once __DIR__ . '/includes/db.php';

$campaign_id = intval($_GET['campaign_id'] ?? 0);
$limit = intval($_GET['limit'] ?? 50);
$log_file = __DIR__ . '/cron/worker_publish.log';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>📋 Log Đăng Bài Facebook</title>
<meta http-equiv="refresh" content="5">
<style>
body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; margin: 0; }
h1 { color: #38bdf8; font-size: 20px; }
.info { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; }
.info span { color: #94a3b8; }
.info b { color: #f0f9ff; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th { background: #1e293b; color: #94a3b8; text-align: left; padding: 8px 12px; border-bottom: 2px solid #334155; position: sticky; top: 0; }
td { padding: 6px 12px; border-bottom: 1px solid #1e293b; vertical-align: top; }
tr:hover { background: #1e293b; }
.status-processing { color: #38bdf8; font-weight: 600; }
.status-published { color: #4ade80; font-weight: 600; }
.status-failed { color: #f87171; font-weight: 600; }
.status-checkpoint { color: #fb923c; font-weight: 600; }
.status-pending { color: #fbbf24; }
.stage { color: #7dd3fc; font-size: 11px; margin-top: 2px; }
.error-msg { color: #f87171; font-size: 11px; word-break: break-all; margin-top: 2px; max-width: 400px; }
.log-section { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 16px; margin-top: 20px; }
.log-line { font-family: 'Consolas', 'Courier New', monospace; font-size: 11px; line-height: 1.6; color: #cbd5e1; white-space: pre-wrap; word-break: break-all; }
.log-line .ts { color: #64748b; }
.log-line .postid { color: #38bdf8; }
.log-line .result { color: #4ade80; }
.log-line .error { color: #f87171; }
.tab-bar { display: flex; gap: 8px; margin-bottom: 16px; }
.tab-bar a { padding: 6px 16px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; }
.tab-bar a.active { background: #0ea5e9; color: white; }
.tab-bar a:not(.active) { background: #1e293b; color: #94a3b8; border: 1px solid #334155; }
.tab-bar a:hover:not(.active) { background: #334155; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
</style>
</head>
<body>
<h1>📋 Log Đăng Bài Facebook <?php if ($campaign_id): ?>(Campaign #<?php echo $campaign_id; ?>)<?php endif; ?></h1>
<div class="info">
    <span>🔄 Tự động refresh mỗi 5 giây</span> |
    <span>Giờ VPS: <b><?php echo date('H:i:s d/m/Y'); ?></b></span>
    <?php if ($campaign_id): ?> | <a href="campaign_detail.php?id=<?php echo $campaign_id; ?>" style="color:#38bdf8;">← Quay lại Campaign</a><?php endif; ?>
</div>

<?php
// ====== PHẦN 1: Trạng thái các bài đang đăng/lỗi từ DB ======
$where = "1=1";
$params_q = [];
if ($campaign_id) {
    $where .= " AND sp.campaign_id = ?";
    $params_q[] = $campaign_id;
}

$sql = "SELECT sp.id, sp.page_id, sp.post_type, sp.status, sp.error_msg, sp.updated_at, sp.scheduled_time, sp.media_path, p.name as page_name
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id
        WHERE $where AND sp.status IN ('processing', 'failed', 'checkpoint', 'pending')
        ORDER BY FIELD(sp.status, 'processing', 'failed', 'checkpoint', 'pending'), sp.updated_at DESC
        LIMIT ?";
$params_q[] = $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params_q);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2 style="color:#94a3b8; font-size: 15px; margin-top: 24px;">🔍 Trạng thái bài đăng (<?php echo count($posts); ?> bài)</h2>
<table>
<thead>
<tr>
    <th>ID</th>
    <th>Page</th>
    <th>Loại</th>
    <th>Trạng thái</th>
    <th>Stage / Error</th>
    <th>Media</th>
    <th>Cập nhật</th>
</tr>
</thead>
<tbody>
<?php foreach ($posts as $p):
    $elapsed = max(0, time() - strtotime($p['updated_at'] ?: $p['scheduled_time']));
    $elapsed_str = ($elapsed < 60) ? ($elapsed . 's') : (floor($elapsed / 60) . 'm ' . ($elapsed % 60) . 's');
    
    $is_stage = !empty($p['error_msg']) && (mb_strpos($p['error_msg'], '⏳') !== false || mb_strpos($p['error_msg'], 'Đang') !== false);
    $status_class = 'status-' . $p['status'];
?>
<tr>
    <td><?php echo $p['id']; ?></td>
    <td><?php echo htmlspecialchars($p['page_name'] ?: $p['page_id']); ?></td>
    <td><?php echo htmlspecialchars($p['post_type']); ?></td>
    <td>
        <span class="<?php echo $status_class; ?>">
        <?php
        if ($p['status'] === 'processing') echo "🔄 Đang đăng ($elapsed_str)";
        elseif ($p['status'] === 'failed') echo "❌ Thất bại";
        elseif ($p['status'] === 'checkpoint') echo "🚫 Checkpoint";
        else echo "⏳ Chờ đăng";
        ?>
        </span>
        <?php if ($p['status'] === 'processing' && $elapsed > 180): ?>
        <div style="color:#f87171; font-size:11px; margin-top:2px;">⚠️ Treo >3m</div>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($is_stage): ?>
        <div class="stage"><?php echo htmlspecialchars($p['error_msg']); ?></div>
        <?php elseif (!empty($p['error_msg'])): ?>
        <div class="error-msg"><?php echo htmlspecialchars($p['error_msg']); ?></div>
        <?php else: ?>
        <span style="color:#64748b;">—</span>
        <?php endif; ?>
    </td>
    <td style="max-width:200px; word-break:break-all; font-size:11px; color:#64748b;"><?php echo htmlspecialchars(mb_strimwidth($p['media_path'] ?? '', 0, 60, '…')); ?></td>
    <td style="white-space:nowrap; color:#64748b; font-size:11px;">
        <?php echo date('H:i:s', strtotime($p['updated_at'] ?: $p['scheduled_time'])); ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php
// ====== PHẦN 2: Nội dung file log ======
?>
<h2 style="color:#94a3b8; font-size: 15px; margin-top: 32px;">📄 File Log: worker_publish.log (50 dòng gần nhất)</h2>
<div class="log-section">
<?php
if (file_exists($log_file)) {
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($lines, -50);
    $lines = array_reverse($lines);
    foreach ($lines as $line) {
        $hl = htmlspecialchars($line);
        // Highlight timestamps
        $hl = preg_replace('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', '<span class="ts">$1</span>', $hl);
        // Highlight PostID
        $hl = preg_replace('/(\[PostID:\d+\])/', '<span class="postid">$1</span>', $hl);
        // Highlight HTTP results
        $hl = preg_replace('/(HTTP=200)/', '<span class="result">$1</span>', $hl);
        $hl = preg_replace('/(HTTP=[^2]\d+)/', '<span class="error">$1</span>', $hl);
        $hl = preg_replace('/(ERROR_DETAIL:.*)$/', '<span class="error">$1</span>', $hl);
        echo "<div class='log-line'>$hl</div>";
    }
    if (empty($lines)) {
        echo '<div class="log-line" style="color:#64748b;">Chưa có log nào. File log sẽ được ghi khi worker chạy lần tiếp theo.</div>';
    }
} else {
    echo '<div class="log-line" style="color:#fbbf24;">⚠️ File log chưa tồn tại: ' . htmlspecialchars($log_file) . '</div>';
    echo '<div class="log-line" style="color:#64748b;">File sẽ được tạo tự động khi worker đăng bài lần tiếp theo.</div>';
}
?>
</div>

<div style="margin-top: 24px; color: #475569; font-size: 12px;">
    💡 Nếu bài treo ở "4/4: Đang gửi API..." quá lâu, có thể do:<br>
    &nbsp;&nbsp;1. Video dung lượng lớn → Facebook cần thời gian download từ CDN<br>
    &nbsp;&nbsp;2. Proxy chậm hoặc bị chặn → cURL timeout<br>
    &nbsp;&nbsp;3. Token hết hạn hoặc Checkpoint → Kiểm tra lại Token<br>
    &nbsp;&nbsp;4. CDN URL không truy cập được → Kiểm tra https://data.hongdolab.com
</div>

</body>
</html>
