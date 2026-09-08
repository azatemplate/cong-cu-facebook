<?php
// instagram.php
$current_page = 'instagram';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/instagram_api.php';

$account_id = $_SESSION['account_id'];
$is_admin   = ($_SESSION['role'] === 'admin');

// Sync Instagram accounts action
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

// Process Post Creation (Form POST)
$msg_type = '';
$msg_text = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_post') {
    verify_csrf();
    $ig_user_ids   = (array)($_POST['ig_user_ids'] ?? []);
    $post_sub_type = $_POST['post_sub_type'] ?? 'Instagram'; // Instagram, Instagram_Reels, Instagram_Story
    $caption       = trim($_POST['caption'] ?? '');
    $media_url     = trim($_POST['media_url'] ?? '');
    $schedule_type = $_POST['schedule_type'] ?? 'now';
    $scheduled_time = $_POST['scheduled_time'] ?? date('Y-m-d H:i:s');

    if (empty($ig_user_ids)) {
        $msg_type = 'danger'; $msg_text = 'Vui lòng chọn ít nhất 1 kênh Instagram!';
    } elseif (empty($media_url)) {
        $msg_type = 'danger'; $msg_text = 'Vui lòng nhập URL hình ảnh hoặc video!';
    } else {
        $ig_accounts = get_instagram_accounts($account_id);
        $ig_map = [];
        foreach ($ig_accounts as $acc) { $ig_map[$acc['ig_user_id']] = $acc; }

        $success_cnt = 0;
        $fail_cnt = 0;

        // Create campaign for tracking if scheduling or posting bulk
        $camp_name = "Instagram " . ucfirst(str_replace('Instagram_', '', $post_sub_type)) . " - " . date('d/m/Y H:i');
        $c_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, ?, ?, ?)");
        $c_stmt->execute([$account_id, $camp_name, $post_sub_type, count($ig_user_ids), $schedule_type === 'now' ? date('Y-m-d H:i:s') : $scheduled_time]);
        $campaign_id = $pdo->lastInsertId();

        foreach ($ig_user_ids as $ig_id) {
            if (!isset($ig_map[$ig_id])) continue;
            $ig_acc = $ig_map[$ig_id];

            if ($schedule_type === 'now') {
                // Post directly
                if ($post_sub_type === 'Instagram_Reels') {
                    $res = post_instagram_reels($ig_acc['ig_user_id'], $ig_acc['access_token'], $media_url, $caption);
                } elseif ($post_sub_type === 'Instagram_Story') {
                    $is_vid = (strpos(strtolower($media_url), '.mp4') !== false || strpos(strtolower($media_url), '.mov') !== false);
                    $res = post_instagram_story($ig_acc['ig_user_id'], $ig_acc['access_token'], $media_url, $is_vid);
                } else {
                    $res = post_instagram_photo($ig_acc['ig_user_id'], $ig_acc['access_token'], $media_url, $caption);
                }

                if ($res['status'] === 'success') {
                    $success_cnt++;
                    $sp_stmt = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, fb_post_id, campaign_id) VALUES (?, ?, ?, ?, ?, NOW(), 'published', ?, ?)");
                    $sp_stmt->execute([$account_id, $ig_id, $post_sub_type, json_encode(['description' => $caption]), $media_url, $res['id'] ?? '', $campaign_id]);
                } else {
                    $fail_cnt++;
                    $sp_stmt = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, error_msg, campaign_id) VALUES (?, ?, ?, ?, ?, NOW(), 'failed', ?, ?)");
                    $sp_stmt->execute([$account_id, $ig_id, $post_sub_type, json_encode(['description' => $caption]), $media_url, $res['msg'] ?? 'Lỗi không xác định', $campaign_id]);
                }
            } else {
                // Schedule post
                $sp_stmt = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
                $sp_stmt->execute([$account_id, $ig_id, $post_sub_type, json_encode(['description' => $caption]), $media_url, $scheduled_time, $campaign_id]);
                $success_cnt++;
            }
        }

        if ($schedule_type === 'now') {
            $msg_type = $fail_cnt === 0 ? 'success' : 'warning';
            $msg_text = "🎉 Đăng bài Instagram hoàn tất: {$success_cnt} thành công" . ($fail_cnt > 0 ? ", {$fail_cnt} thất bại." : "!");
        } else {
            $msg_type = 'success';
            $msg_text = "⏰ Đã hẹn giờ đăng {$success_cnt} bài viết Instagram thành công!";
        }
    }
}

// Fetch Instagram Accounts
$ig_accounts = get_instagram_accounts($account_id);
$selected_ig_id = $_GET['ig_id'] ?? ($ig_accounts[0]['ig_user_id'] ?? '');

// Active Tab
$active_tab = $_GET['tab'] ?? 'create';

// If media tab selected, fetch published media list
$media_list = [];
$selected_acc = null;
if ($active_tab === 'media' && !empty($selected_ig_id)) {
    foreach ($ig_accounts as $acc) {
        if ($acc['ig_user_id'] === $selected_ig_id) { $selected_acc = $acc; break; }
    }
    if ($selected_acc) {
        $media_list = get_instagram_media_list($selected_acc['ig_user_id'], $selected_acc['access_token'], 20);
    }
}
?>

<div class="page-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <span>📸 Instagram Post & Insights</span>
        <div style="font-size:13px; color:var(--text-muted); font-weight:400; margin-top:2px;">
            Đăng bài Feed, Video Reels, Story & Quản lý chỉ số tương tác Instagram Business
        </div>
    </div>
    <div>
        <a href="instagram.php?action=sync" class="btn" style="background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); color:white; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
            🔄 Đồng bộ kênh từ Fanpages
        </a>
    </div>
</div>

<?php if (isset($_GET['synced'])): ?>
    <div class="alert alert-success">
        ✅ Đã quét và tìm thấy <strong><?php echo (int)$_GET['synced']; ?></strong> tài khoản Instagram Doanh nghiệp kết nối từ Facebook Pages!
    </div>
<?php endif; ?>

<?php if ($msg_text): ?>
    <div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg_text); ?></div>
<?php endif; ?>

<!-- Nav Tabs -->
<div style="display:flex; gap:10px; margin-bottom:16px; border-bottom:2px solid var(--border-color); padding-bottom:10px;">
    <a href="instagram.php?tab=create" style="padding:8px 18px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; background:<?php echo $active_tab==='create'?'var(--primary-color)':'transparent'; ?>; color:<?php echo $active_tab==='create'?'white':'var(--text-main)'; ?>;">
        📝 Đăng / Hẹn Giờ Bài Viết
    </a>
    <a href="instagram.php?tab=media<?php echo !empty($selected_ig_id) ? '&ig_id='.urlencode($selected_ig_id) : ''; ?>" style="padding:8px 18px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; background:<?php echo $active_tab==='media'?'var(--primary-color)':'transparent'; ?>; color:<?php echo $active_tab==='media'?'white':'var(--text-main)'; ?>;">
        📊 Danh Sách Bài Đăng & Insights
    </a>
</div>

<?php if ($active_tab === 'create'): ?>
<!-- Create / Schedule Post Form -->
<div class="card">
    <?php if (empty($ig_accounts)): ?>
        <div style="text-align:center; padding:50px 20px;">
            <div style="font-size:48px; margin-bottom:12px;">📸</div>
            <h3 style="margin:0 0 8px;">Chưa tìm thấy tài khoản Instagram Doanh nghiệp nào</h3>
            <p style="color:var(--text-muted); font-size:14px; max-width:500px; margin:0 auto 20px;">
                Tài khoản Instagram của bạn cần chuyển sang loại <strong>Business / Creator</strong> và liên kết với một Facebook Page. Nhấn nút dưới đây để đồng bộ từ các Trang Facebook của bạn.
            </p>
            <a href="instagram.php?action=sync" class="btn btn-primary" style="padding:10px 20px;">🔄 Bắt đầu Đồng Bộ Kênh Instagram</a>
        </div>
    <?php else: ?>
        <form method="POST" action="instagram.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_post">

            <!-- Select IG Accounts -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;">1. Chọn kênh Instagram muốn đăng bài:</label>
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:10px;">
                    <?php foreach ($ig_accounts as $acc): ?>
                        <label style="display:flex; align-items:center; gap:10px; padding:10px 14px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer; background:var(--card-bg);">
                            <input type="checkbox" name="ig_user_ids[]" value="<?php echo htmlspecialchars($acc['ig_user_id']); ?>" checked style="width:18px; height:18px; accent-color:var(--primary-color);">
                            <?php if (!empty($acc['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($acc['avatar']); ?>" style="width:32px; height:32px; border-radius:50%; object-fit:cover;">
                            <?php else: ?>
                                <div style="width:32px; height:32px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-weight:bold;">📸</div>
                            <?php endif; ?>
                            <div style="min-width:0; flex:1;">
                                <div style="font-weight:600; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">@<?php echo htmlspecialchars($acc['username']); ?></div>
                                <div style="font-size:11px; color:var(--text-muted);"><?php echo number_format($acc['followers_count']); ?> followers</div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Post Type Selector -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;">2. Chọn định dạng nội dung:</label>
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <label style="padding:10px 18px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px;">
                        <input type="radio" name="post_sub_type" value="Instagram" checked onclick="togglePostFields('photo')">
                        <span>🖼️ Bài Ảnh / Carousel Feed</span>
                    </label>
                    <label style="padding:10px 18px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px;">
                        <input type="radio" name="post_sub_type" value="Instagram_Reels" onclick="togglePostFields('reels')">
                        <span>🎞️ Instagram Reels Video</span>
                    </label>
                    <label style="padding:10px 18px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px;">
                        <input type="radio" name="post_sub_type" value="Instagram_Story" onclick="togglePostFields('story')">
                        <span>▶️ Instagram Story (24h)</span>
                    </label>
                </div>
            </div>

            <!-- Media URL -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;" id="media_label">3. URL Ảnh / Media (HTTP/HTTPS công khai):</label>
                <input type="url" name="media_url" placeholder="https://domain.com/uploads/photo.jpg hoặc video.mp4" style="width:100%; padding:10px 14px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; box-sizing:border-box;" required>
                <small style="color:var(--text-muted); display:block; margin-top:4px;">Lưu ý: URL ảnh/video cần truy cập được công khai qua mạng để Instagram Graph API tải xuống.</small>
            </div>

            <!-- Caption / Description -->
            <div class="form-group" style="margin-bottom:20px;" id="caption_group">
                <label style="font-weight:600; display:block; margin-bottom:8px;">4. Nội dung bài viết (Caption & Hashtag):</label>
                <textarea name="caption" rows="4" placeholder="Nhập mô tả bài viết và hashtag #instagram #reels..." style="width:100%; padding:10px 14px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; box-sizing:border-box;"></textarea>
            </div>

            <!-- Schedule Settings -->
            <div class="form-group" style="margin-bottom:24px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;">5. Thời gian đăng:</label>
                <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="radio" name="schedule_type" value="now" checked onclick="document.getElementById('schedule_time_box').style.display='none';">
                        <span>Đăng ngay lập tức</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="radio" name="schedule_type" value="schedule" onclick="document.getElementById('schedule_time_box').style.display='block';">
                        <span>Lên lịch hẹn giờ</span>
                    </label>
                </div>
                <div id="schedule_time_box" style="display:none; margin-top:12px;">
                    <input type="datetime-local" name="scheduled_time" value="<?php echo date('Y-m-d\TH:i'); ?>" style="padding:8px 12px; border:1px solid var(--border-color); border-radius:6px;">
                </div>
            </div>

            <div>
                <button type="submit" class="btn btn-primary" style="padding:12px 28px; font-size:15px; font-weight:600; background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); border:none;">
                    🚀 Xác Nhận Đăng Bài Instagram
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Published Media & Insights Tab -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
        <h3 style="margin:0;">Thống kê bài viết đã đăng trên Instagram</h3>
        <div>
            <select onchange="window.location.href='instagram.php?tab=media&ig_id='+this.value;" style="padding:8px 14px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; font-weight:500;">
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
            Chưa có bài viết nào hoặc không tải được bài viết cho kênh Instagram này.
        </div>
    <?php else: ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:16px;">
            <?php foreach ($media_list as $m): 
                $m_id = $m['id'];
                $m_type = $m['media_type'] ?? 'IMAGE';
                $m_url = $m['thumbnail_url'] ?? $m['media_url'] ?? '';
                $m_caption = mb_strimwidth($m['caption'] ?? '', 0, 90, '…');
                $m_link = $m['permalink'] ?? '#';
                $m_likes = $m['like_count'] ?? 0;
                $m_comments = $m['comments_count'] ?? 0;
                $m_time = !empty($m['timestamp']) ? date('d/m/Y H:i', strtotime($m['timestamp'])) : '';
            ?>
            <div style="border:1px solid var(--border-color); border-radius:10px; overflow:hidden; background:var(--card-bg); display:flex; flex-direction:column;">
                <div style="position:relative; height:180px; background:#000;">
                    <?php if ($m_type === 'VIDEO'): ?>
                        <video src="<?php echo htmlspecialchars($m['media_url'] ?? ''); ?>" style="width:100%; height:100%; object-fit:cover;" controls></video>
                    <?php elseif (!empty($m_url)): ?>
                        <img src="<?php echo htmlspecialchars($m_url); ?>" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                        <div style="height:100%; display:flex; align-items:center; justify-content:center; color:#fff;">📸 Instagram Media</div>
                    <?php endif; ?>
                    <span style="position:absolute; top:8px; right:8px; background:rgba(0,0,0,0.7); color:#fff; font-size:11px; padding:2px 8px; border-radius:4px; font-weight:600;">
                        <?php echo htmlspecialchars($m_type); ?>
                    </span>
                </div>
                <div style="padding:14px; flex:1; display:flex; flex-direction:column; justify-space-between;">
                    <div style="font-size:12px; color:var(--text-muted); margin-bottom:6px;"><?php echo $m_time; ?></div>
                    <div style="font-size:13px; color:var(--text-main); margin-bottom:12px; line-height:1.4; flex:1;">
                        <?php echo htmlspecialchars($m_caption); ?>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:12px; border-top:1px solid var(--border-color); padding-top:10px; margin-top:8px;">
                        <div style="display:flex; gap:12px;">
                            <span>❤️ <?php echo number_format($m_likes); ?></span>
                            <span>💬 <?php echo number_format($m_comments); ?></span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <a href="<?php echo htmlspecialchars($m_link); ?>" target="_blank" style="color:var(--primary-color); text-decoration:none; font-weight:500;">Xem →</a>
                            <a href="instagram.php?action=delete_media&ig_id=<?php echo urlencode($selected_ig_id); ?>&media_id=<?php echo urlencode($m_id); ?>" onclick="return confirm('Bạn có chắc muốn xóa bài viết này trên Instagram?');" style="color:#dc2626; text-decoration:none; font-weight:500;">🗑 Xóa</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function togglePostFields(type) {
    const lbl = document.getElementById('media_label');
    const cap = document.getElementById('caption_group');
    if (type === 'story') {
        lbl.innerText = '3. URL Ảnh / Video cho Story (Tỷ lệ 9:16):';
        cap.style.display = 'none';
    } else if (type === 'reels') {
        lbl.innerText = '3. URL Video Reels (Tỷ lệ 9:16, định dạng MP4/MOV):';
        cap.style.display = 'block';
    } else {
        lbl.innerText = '3. URL Ảnh / Media (HTTP/HTTPS công khai):';
        cap.style.display = 'block';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
