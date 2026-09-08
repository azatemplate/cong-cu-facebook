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

// Fetch Instagram Accounts
$ig_accounts = get_instagram_accounts($account_id);
$selected_ig_id = $_GET['ig_id'] ?? ($ig_accounts[0]['ig_user_id'] ?? '');
$active_tab = $_GET['tab'] ?? 'channels';

// ── Calculate Dashboard Insights Metrics ──────────────────────────────────
$total_ig_accounts = count($ig_accounts);
$total_followers   = 0;
foreach ($ig_accounts as $acc) {
    $total_followers += (int)($acc['followers_count'] ?? 0);
}

// Total Posts Count from scheduled_posts
$total_ig_posts = 0;
try {
    $stmt_posts = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE account_id = ? AND post_type LIKE 'Instagram%'");
    $stmt_posts->execute([$account_id]);
    $total_ig_posts = intval($stmt_posts->fetchColumn());
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
/* Dashboard Stat Cards Style (giống index.php) */
.ig-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.ig-stat-card {
    background: var(--card-bg);
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.ig-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.06);
}
.ig-stat-card .icon-box {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    flex-shrink: 0;
}
.grad-ig-purple { background: linear-gradient(135deg, #833ab4, #fd1d1d); }
.grad-ig-orange { background: linear-gradient(135deg, #f56040, #ffc837); }
.grad-ig-blue   { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.grad-ig-green  { background: linear-gradient(135deg, #10b981, #059669); }

.ig-stat-card .val { font-size: 24px; font-weight: 700; color: var(--text-main); line-height: 1.2; }
.ig-stat-card .lbl { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

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

<!-- ── TOP INSIGHTS SUMMARY CARDS (Tương tự index.php) ────────────────────── -->
<div class="ig-stats-grid">
    <div class="ig-stat-card">
        <div class="icon-box grad-ig-purple">📸</div>
        <div>
            <div class="val"><?php echo number_format($total_ig_accounts); ?></div>
            <div class="lbl">Kênh Instagram</div>
        </div>
    </div>
    <div class="ig-stat-card">
        <div class="icon-box grad-ig-orange">👥</div>
        <div>
            <div class="val"><?php echo number_format($total_followers); ?></div>
            <div class="lbl">Tổng Followers</div>
        </div>
    </div>
    <div class="ig-stat-card">
        <div class="icon-box grad-ig-blue">📝</div>
        <div>
            <div class="val"><?php echo number_format($total_ig_posts); ?></div>
            <div class="lbl">Bài Viết & Hẹn Giờ</div>
        </div>
    </div>
    <div class="ig-stat-card">
        <div class="icon-box grad-ig-green">⚡</div>
        <div>
            <div class="val"><?php echo number_format(count($media_list)); ?></div>
            <div class="lbl">Bài Trên Kênh Đang Chọn</div>
        </div>
    </div>
</div>

<!-- ── NAVIGATION TABS ─────────────────────────────────────────────────── -->
<div class="ig-nav-tabs">
    <a href="instagram.php?tab=channels" class="ig-tab-btn <?php echo $active_tab==='channels'?'active':''; ?>">
        📌 Các Kênh Đã Đồng Bộ (<?php echo $total_ig_accounts; ?>)
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
        <form id="igPublishForm" enctype="multipart/form-data">
            
            <!-- 1. Menu sổ xuống Chọn Kênh Instagram -->
            <div class="form-group" style="margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <label style="font-weight:600; margin:0;">1. Chọn kênh Instagram muốn đăng bài (Menu sổ xuống):</label>
                    <button type="button" onclick="selectAllIgChannels(true)" style="background:none; border:none; color:var(--primary-color); font-size:13px; cursor:pointer; text-decoration:underline;">Tích Chọn Tất Cả Kênh</button>
                </div>
                <select id="ig_user_ids" name="ig_user_ids[]" multiple style="width:100%; height:110px; padding:8px 12px; border:1px solid var(--border-color); border-radius:8px; font-size:14px; box-sizing:border-box;" required>
                    <?php foreach ($ig_accounts as $acc): ?>
                        <option value="<?php echo htmlspecialchars($acc['ig_user_id']); ?>" selected>
                            @<?php echo htmlspecialchars($acc['username']); ?> — <?php echo htmlspecialchars($acc['name']); ?> (<?php echo number_format($acc['followers_count']); ?> followers)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#64748b; margin-top:4px; display:block;">💡 Giữ phím <code>Ctrl</code> (hoặc <code>Cmd</code> trên Mac) để chọn nhiều kênh cùng lúc.</small>
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
                        Nếu không nhập lịch, hệ thống sẽ đưa bài vào hàng đợi đăng ngay lập tức.
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
function selectAllIgChannels(selectState) {
    const sel = document.getElementById('ig_user_ids');
    if (!sel) return;
    for (let i = 0; i < sel.options.length; i++) {
        sel.options[i].selected = selectState;
    }
}

function switchIgPostType(type) {
    const tiktokSec  = document.getElementById('tiktokSection');
    const autoTitle  = document.getElementById('autoTitleBox');
    const photoInput = document.getElementById('photoInputWrap');
    const videoInput = document.getElementById('videoInputWrap');
    const randomPhoto= document.getElementById('randomPhotoOptions');
    const imagesEl   = document.getElementById('images');

    if (type === 'reels') {
        if (tiktokSec) tiktokSec.style.display = 'block';
        if (autoTitle) autoTitle.style.display = 'block';
        if (photoInput) photoInput.style.display = 'none';
        if (videoInput) videoInput.style.display = 'block';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else if (type === 'story_photo') {
        if (tiktokSec) tiktokSec.style.display = 'none';
        if (autoTitle) autoTitle.style.display = 'none';
        if (imagesEl) imagesEl.setAttribute('accept', 'image/*');
        if (photoInput) photoInput.style.display = 'block';
        if (videoInput) videoInput.style.display = 'none';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else if (type === 'story_video') {
        if (tiktokSec) tiktokSec.style.display = 'block';
        if (autoTitle) autoTitle.style.display = 'none';
        if (photoInput) photoInput.style.display = 'none';
        if (videoInput) videoInput.style.display = 'block';
        if (randomPhoto) randomPhoto.style.display = 'none';
    } else {
        // photo feed
        if (tiktokSec) tiktokSec.style.display = 'none';
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
    const imgEl = document.getElementById('images');
    const vidEl = document.getElementById('video');
    if (imgEl) imgEl.value = '';
    if (vidEl) vidEl.value = '';
    
    document.getElementById('driveSelectedCount').innerText = idArray.length;
    let displayName = nameArray.length <= 3 ? nameArray.join(', ') : nameArray.slice(0, 3).join(', ') + ` và ${nameArray.length - 3} file khác`;
    document.getElementById('driveSelectedName').innerText = displayName;
    document.getElementById('driveSelectionInfo').style.display = 'block';
}

function clearDriveSelection() {
    document.getElementById('drive_file_id').value = '';
    document.getElementById('driveSelectedCount').innerText = '0';
    document.getElementById('driveSelectedName').innerText = '';
    document.getElementById('driveSelectionInfo').style.display = 'none';
}

// Submit IG Form via AJAX
document.getElementById('igPublishForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitIg');
    const res = document.getElementById('postResult');

    btn.disabled = true;
    btn.textContent = '⏳ Đang xử lý...';
    res.style.display = 'none';

    const formData = new FormData(this);

    fetch('actions/publish_instagram.php', {
        method: 'POST',
        body: formData
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
