<?php
// check_campaigns.php — Dynamic Campaign & Instagram Post Diagnostic Tool
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/drive_utils.php';
require_once __DIR__ . '/includes/instagram_api.php';

if (session_status() === PHP_SESSION_NONE) @session_start();
$account_id = $_SESSION['account_id'] ?? 0;
$is_admin   = ($_SESSION['role'] ?? '') === 'admin';

$ids_input = $_GET['id'] ?? '3921,3919';
$campaign_ids = array_filter(array_map('intval', explode(',', $ids_input)));

// Action: Force run start_publish.php or reset stuck posts
$action_msg = '';
$has_processing = false;

if (isset($_GET['action'])) {
    $act = $_GET['action'];
    if ($act === 'run') {
        try {
            if (!empty($campaign_ids)) {
                $in_clause = implode(',', array_fill(0, count($campaign_ids), '?'));
                $reset_stmt = $pdo->prepare("UPDATE scheduled_posts SET status = 'pending', retry_count = 0, error_msg = NULL WHERE campaign_id IN ($in_clause) AND status IN ('processing', 'failed')");
                $reset_stmt->execute($campaign_ids);
                $reset_cnt = $reset_stmt->rowCount();
                $action_msg = "✅ Đã reset {$reset_cnt} bài từ 'processing'/'failed' về 'pending'.";
            }
            
            // Include start_publish.php synchronously to capture output
            ob_start();
            include __DIR__ . '/cron/start_publish.php';
            $cron_output = ob_get_clean();
            $action_msg .= "<br>⚡ <strong>Kết quả kích hoạt start_publish.php:</strong><pre style='background:#1e293b; color:#38bdf8; padding:10px; border-radius:6px; overflow:auto; max-height:200px;'>" . htmlspecialchars($cron_output) . "</pre>";
        } catch (Exception $e) {
            $action_msg = "❌ Lỗi thực thi: " . $e->getMessage();
        }
    } elseif ($act === 'run_sync') {
        try {
            if (!empty($campaign_ids)) {
                $in_clause = implode(',', array_fill(0, count($campaign_ids), '?'));
                $pdo->prepare("UPDATE scheduled_posts SET status = 'pending', retry_count = 0, error_msg = NULL WHERE campaign_id IN ($in_clause) AND status IN ('processing', 'failed')")
                    ->execute($campaign_ids);
                
                // Fetch page_ids for these campaigns
                $stmt_p = $pdo->prepare("SELECT DISTINCT page_id, account_id, post_type FROM scheduled_posts WHERE campaign_id IN ($in_clause) AND status = 'pending'");
                $stmt_p->execute($campaign_ids);
                $rows = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rows)) {
                    ob_start();
                    foreach ($rows as $r) {
                        $p_id = $r['page_id'];
                        $uid = (strpos($r['post_type'], 'Instagram') !== false) ? 'ig_' . $p_id : $p_id;
                        echo "=== CHẠY PUBLISH WORKER CHO PAGE ID: $p_id (UID: $uid) ===\n";
                        $argv = [__FILE__, $p_id, $uid];
                        $argc = 3;
                        @include __DIR__ . '/cron/publish_worker.php';
                        echo "\n";
                    }
                    $sync_out = ob_get_clean();
                    $action_msg = "✅ <strong>Kết quả chạy worker trực tiếp (Synchronous Diagnostic):</strong><pre style='background:#1e293b; color:#38bdf8; padding:12px; border-radius:6px; overflow:auto; max-height:300px; font-family:monospace;'>" . htmlspecialchars($sync_out) . "</pre>";
                } else {
                    $action_msg = "ℹ️ Không có bài viết nào cần đăng trong chiến dịch này.";
                }
            }
        } catch (Exception $e) {
            $action_msg = "❌ Lỗi thực thi trực tiếp: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-title">
    <span>🔍 Công Cụ Kiểm Tra Thấu Kính Chiến Dịch (Diagnostics Tool)</span>
</div>

<div class="card" style="margin-bottom:20px;">
    <form method="GET" action="check_campaigns.php" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <label style="font-weight:600;">Nhập ID Chiến dịch (cách nhau bởi dấu phẩy):</label>
        <input type="text" name="id" value="<?php echo htmlspecialchars(implode(',', $campaign_ids)); ?>" style="padding:8px 12px; border:1px solid var(--border-color); border-radius:6px; font-weight:bold; width:200px;">
        <button type="submit" class="btn btn-primary">🔍 Kiểm Tra Ngay</button>
        <?php if (!empty($campaign_ids)): ?>
            <a href="check_campaigns.php?id=<?php echo urlencode(implode(',', $campaign_ids)); ?>&action=run" class="btn" style="background:#10b981; color:white; font-weight:600; text-decoration:none;">
                ⚡ Reset & Khởi Chạy Ngầm
            </a>
            <a href="check_campaigns.php?id=<?php echo urlencode(implode(',', $campaign_ids)); ?>&action=run_sync" class="btn" style="background:#8b5cf6; color:white; font-weight:600; text-decoration:none;">
                ▶️ Đăng Trực Tiếp & Xem Log Chi Tiết
            </a>
        <?php endif; ?>
    </form>
</div>

<?php if ($action_msg): ?>
    <div class="alert alert-info" style="margin-bottom:20px;"><?php echo $action_msg; ?></div>
<?php endif; ?>

<?php if (empty($campaign_ids)): ?>
    <div class="alert alert-warning">Vui lòng nhập ít nhất 1 ID chiến dịch để kiểm tra.</div>
<?php else: ?>
    <?php foreach ($campaign_ids as $c_id): ?>
        <?php
        // Fetch campaign details
        $c_stmt = $pdo->prepare("SELECT * FROM post_campaigns WHERE id = ?");
        $c_stmt->execute([$c_id]);
        $campaign = $c_stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch scheduled posts
        $p_stmt = $pdo->prepare("SELECT * FROM scheduled_posts WHERE campaign_id = ? ORDER BY id ASC");
        $p_stmt->execute([$c_id]);
        $posts = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="card" style="margin-bottom:24px; border:2px solid var(--border-color);">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:16px;">
                <div>
                    <h3 style="margin:0;">Chiến dịch #<?php echo $c_id; ?>: <?php echo htmlspecialchars($campaign['name'] ?? 'Không tìm thấy'); ?></h3>
                    <div style="font-size:13px; color:var(--text-muted); margin-top:4px;">
                        Loại: <strong><?php echo htmlspecialchars($campaign['post_type'] ?? 'N/A'); ?></strong> | 
                        Tạo lúc: <?php echo $campaign['created_at'] ?? 'N/A'; ?> | 
                        Tổng bài trong Campaign: <?php echo count($posts); ?>
                    </div>
                </div>
                <div>
                    <a href="campaign_detail.php?id=<?php echo $c_id; ?>" class="btn btn-secondary" target="_blank" style="font-size:12px;">Xem trong Campaign Detail →</a>
                </div>
            </div>

            <?php if (!$campaign): ?>
                <div style="color:#dc2626; font-weight:600;">❌ Không tìm thấy chiến dịch này trong cơ sở dữ liệu `post_campaigns`.</div>
            <?php elseif (empty($posts)): ?>
                <div style="color:#dc2626; font-weight:600;">❌ Chiến dịch tồn tại nhưng không có bài viết nào trong `scheduled_posts`.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:2px solid var(--border-color);">
                                <th style="padding:10px;">ID Bài</th>
                                <th style="padding:10px;">Loại</th>
                                <th style="padding:10px;">Kênh (Page ID / IG User ID)</th>
                                <th style="padding:10px;">Nguồn Phương Tiện (Media Path)</th>
                                <th style="padding:10px;">Trạng Thái</th>
                                <th style="padding:10px;">Thông Báo Lỗi / Log</th>
                                <th style="padding:10px;">Kiểm Tra Chi Tiết</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posts as $post): ?>
                                <?php
                                $status = $post['status'];
                                $page_id = $post['page_id'];
                                $account_id_post = $post['account_id'];
                                $media_path = $post['media_path'];

                                // 1. Check Instagram account token
                                $ig_stmt = $pdo->prepare("SELECT * FROM instagram_accounts WHERE (ig_user_id = ? OR id = ?) AND account_id = ?");
                                $ig_stmt->execute([$page_id, $page_id, $account_id_post]);
                                $ig_acc = $ig_stmt->fetch(PDO::FETCH_ASSOC);

                                $token_status = "❌ Không tìm thấy tài khoản Instagram kết nối";
                                if ($ig_acc) {
                                    if (!empty($ig_acc['access_token'])) {
                                        $token_status = "✅ Token sẵn sàng (@" . htmlspecialchars($ig_acc['username']) . ")";
                                    } else {
                                        $token_status = "⚠️ Đã tìm thấy kênh @" . htmlspecialchars($ig_acc['username']) . " nhưng Token rỗng";
                                    }
                                }

                                // 2. Check Drive token access
                                $drive_token = get_drive_access_token($pdo, $account_id_post, $page_id);
                                $drive_status = $drive_token ? "✅ Token Drive OK" : "⚠️ Không có Token Drive";

                                // 3. Analyze why stuck
                                $analysis = [];
                                if ($status === 'processing') {
                                    $has_processing = true;
                                    $updated_time = strtotime($post['updated_at']);
                                    $diff_mins = round((time() - $updated_time) / 60, 1);
                                    $analysis[] = "🔄 Bài đang ở trạng thái `processing` ({$diff_mins} phút trước). " . ($diff_mins > 5 ? "⚠️ Bị kẹt do worker cũ hoặc cron dừng đột ngột." : "⏳ Worker đang xử lý tải file/mã hóa Meta (Trang tự reload sau 5s).");
                                } elseif ($status === 'pending') {
                                    $sched_time = strtotime($post['scheduled_time']);
                                    if ($sched_time <= time()) {
                                        $analysis[] = "⏳ Bài đang ở `pending` và đã đến/qua giờ hẹn. Đang chờ Cron dispatcher chọn.";
                                    } else {
                                        $analysis[] = "⏰ Bài ở `pending`, hẹn giờ trong tương lai (" . date('H:i d/m/Y', $sched_time) . ").";
                                    }
                                } elseif ($status === 'failed') {
                                    $analysis[] = "❌ Đăng thất bại: " . htmlspecialchars($post['error_msg'] ?? 'Không có lỗi cụ thể');
                                }
                                ?>
                                <tr style="border-bottom:1px solid var(--border-color);">
                                    <td style="padding:10px; font-weight:bold;">#<?php echo $post['id']; ?></td>
                                    <td style="padding:10px;"><span style="background:#e0f2fe; color:#0369a1; padding:3px 8px; border-radius:4px; font-weight:600;"><?php echo htmlspecialchars($post['post_type']); ?></span></td>
                                    <td style="padding:10px;">
                                        <code><?php echo htmlspecialchars($page_id); ?></code>
                                        <div style="font-size:11px; margin-top:2px;"><?php echo $token_status; ?></div>
                                    </td>
                                    <td style="padding:10px; word-break:break-all; max-width:220px;">
                                        <code><?php echo htmlspecialchars(mb_strimwidth($media_path, 0, 60, '…')); ?></code>
                                        <div style="font-size:11px; color:var(--text-muted); margin-top:2px;"><?php echo $drive_status; ?></div>
                                    </td>
                                    <td style="padding:10px;">
                                        <span style="padding:4px 10px; border-radius:12px; font-size:12px; font-weight:bold; 
                                            background:<?php echo $status==='published'?'#d1fae5':($status==='processing'?'#e0f2fe':($status==='failed'?'#fee2e2':'#fef3c7')); ?>; 
                                            color:<?php echo $status==='published'?'#065f46':($status==='processing'?'#0369a1':($status==='failed'?'#dc2626':'#d97706')); ?>;">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
                                    <td style="padding:10px; color:#dc2626; font-size:12px;">
                                        <?php echo htmlspecialchars($post['error_msg'] ?? 'Không'); ?>
                                    </td>
                                    <td style="padding:10px; font-size:12px; line-height:1.4;">
                                        <?php foreach ($analysis as $msg): ?>
                                            <div><?php echo $msg; ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($has_processing): ?>
    <script>
        console.log("Detect processing status... Auto refreshing page in 5s");
        setTimeout(function() {
            window.location.reload();
        }, 5000);
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
