<?php
$current_page = 'reels';
require_once __DIR__ . '/includes/header.php';

// Fetch all users
$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Đọc cấu hình giới hạn upload
$disable_local_upload = false;
try {
    $stmt_upload = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'disable_local_upload'");
    $stmt_upload->execute();
    $row_upload = $stmt_upload->fetch(PDO::FETCH_ASSOC);
    if ($row_upload && $row_upload['setting_value'] === '1' && !$is_admin) $disable_local_upload = true;
} catch (Exception $e) {}

$stmt = $pdo->prepare("SELECT id, name FROM users WHERE account_id = ? ORDER BY name ASC");
$stmt->execute([$account_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users (owners of available pages)
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.name 
    FROM users u 
    LEFT JOIN pages p ON u.id = p.user_id 
    LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
    WHERE u.account_id = :aid OR ps.shared_with_account_id = :aid2
    ORDER BY u.name ASC
");
$stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all pages grouped by user
// Includes owned pages AND shared pages
$stmt2 = $pdo->prepare("
    (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id 
     FROM pages p JOIN users u ON p.user_id = u.id 
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.id, p.page_id, p.name, p.avatar, u.id as user_id
     FROM pages p 
     JOIN page_shares ps ON p.page_id = ps.page_id 
     JOIN users u ON p.user_id = u.id
     WHERE ps.shared_with_account_id = :aid2)
    ORDER BY name ASC
");
$stmt2->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt2->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt2->execute();
$pages = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$pages_json = json_encode($pages);
?>

<div class="page-title">Reels Scheduler</div>

<div class="card">
    <h3 style="margin-bottom: 15px;">Đăng Reels</h3>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
        Chọn User quản lý (Token), sau đó chọn Fanpage tương ứng để chuẩn bị đăng Reels.
    </p>

    <form id="reelsForm" enctype="multipart/form-data">
        <div class="form-group">
            <label>1. Chọn User Quản Lý Token</label>
            <select id="user_select" name="user_id"
                style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" required>
                <option value="">-- Chọn User Quản Lý --</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                        <?php
                endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>2. Chọn Fanpage</label>
            <?php include __DIR__ . '/includes/page_selector.php'; ?>
        </div>
        <div class="form-group"
            style="background: #fdf2f8; padding: 15px; border-radius: 6px; border: 1px dashed #fbcfe8; margin-bottom: 20px;">
            <label style="color: #be185d; font-weight: 500;">
                <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="margin-right: 5px;">
                Tự động dùng Tên File / Tiêu đề TikTok làm Tiêu đề và Mô tả
            </label>
            <p style="font-size: 13px; color: #9d174d; margin-top: 5px; margin-bottom: 0;">(Nếu chọn, hệ thống sẽ bỏ qua
                2 ô nhập Tiêu đề/Mô tả bên dưới)</p>
        </div>

        <div class="form-group" style="position: relative;">
            <label style="display: flex; align-items: center; gap: 8px;">3. Mô tả Reels chung (Tùy chọn)
                <button type="button" id="emojiTriggerReels" class="emoji-picker-trigger">😀 Emoji</button>
            </label>
            <div id="emojiPopupReels" class="emoji-picker-popup">
                <div class="emoji-tabs"></div>
                <div class="emoji-search-box"><input type="text" class="emoji-search-input" placeholder="Tìm emoji..."></div>
                <div class="emoji-grid-wrap"></div>
            </div>
            <textarea id="description" name="description" rows="3"
                style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;"
                placeholder="Nhập mô tả chung..."></textarea>
            <small style="color: #64748b;">(Reels chủ yếu sử dụng Mô tả làm text hiển thị) · 💡 Hỗ trợ Spin: <code>{nd1|nd2|nd3}</code> — random mỗi lần đăng.</small>
        </div>
        <div class="form-group"
            style="background: #fdf2f8; padding: 15px; border-radius: 6px; border: 1px dashed #fbcfe8; margin-bottom: 20px;">
            <label style="color: #be185d; font-weight: 500;">Tùy chọn tải video TikTok Hàng Loạt (Không logo)</label>
            <p style="font-size: 13px; color: #9d174d; margin-top: 5px; margin-bottom: 10px;">
                Dán nhiều link TikTok vào đây (Mỗi link 1 dòng) để hệ thống tự động tải Reels và lấy Tiêu đề gốc của
                TikTok.
            </p>
            <textarea id="tiktok_urls" name="tiktok_urls" rows="4"
                placeholder="VD:&#10;https://www.tiktok.com/@user/video/123...&#10;https://www.tiktok.com/@user/video/456..."
                style="width: 100%; padding: 10px; border: 1px solid #f9a8d4; border-radius: 6px;"></textarea>
        </div>
        <div class="form-group">
            <label>4. Tải lên Reels <?php echo $disable_local_upload ? '(Drive / TikTok)' : 'từ máy (hoặc Drive)'; ?></label>
            <?php if ($disable_local_upload): ?>
            <div style="padding: 10px 15px; background: #fef3cd; border: 1px solid #ffc107; border-radius: 6px; font-size: 13px; color: #856404; margin-bottom: 10px;">
                🔒 Admin đã tắt tính năng tải tệp từ máy tính. Vui lòng sử dụng Google Drive hoặc Link TikTok.
            </div>
            <?php endif; ?>
            <div
                style="display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 10px; border: 1px dashed var(--border-color); border-radius: 6px;">
                <?php if (!$disable_local_upload): ?>
                <input type="file" id="video" name="video[]" multiple accept="video/mp4,video/x-m4v,video/*"
                    style="width: 100%; max-width: 250px; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;"
                    onchange="clearDriveSelection()">
                <div style="font-weight: bold; color: #64748b;">HOẶC</div>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="openDriveModal()"
                    style="background: #fff; border: 1px solid #cbd5e1; color: #334155; display: flex; align-items: center; gap: 5px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                    </svg>
                    Chọn từ Google Drive
                </button>
            </div>
            <div id="driveSelectionInfo"
                style="margin-top: 10px; display: none; padding: 10px 15px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 6px; font-size: 13px;">
                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; color: #0369a1; font-weight: bold;">
                    <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z">
                            </path>
                        </svg> Đã chọn <span id="driveSelectedCount">0</span> file từ Google Drive:</span>
                    <button type="button" onclick="clearDriveSelection()"
                        style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12px;">Hủy
                        / Xoá hết</button>
                </div>
                <ul id="driveSelectedList"
                    style="margin: 0; padding-left: 20px; color: #0c4a6e; max-height: 120px; overflow-y: auto; line-height: 1.6;">
                </ul>
            </div>
            <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
        </div>

        <div style="display:flex; gap:14px; align-items:stretch; margin-top:4px; flex-wrap:wrap;">

            <div class="form-group"
                style="flex:1; min-width:300px; background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid var(--border-color); margin-bottom:0;">
                <label style="color: var(--primary-color);">5. Lên lịch tự động hàng loạt (Tùy chọn)</label>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px; margin-bottom: 15px;">
                    Chọn khoảng ngày và các khung giờ. Hệ thống sẽ trộn ngẫu nhiên tất cả các Reels bạn cung cấp (từ
                    file, link tiktok, drive) và xếp lịch rải đều. Nếu bạn không nhập lịch, tất cả sẽ được đăng / push
                    lên hàng đợi ngay lập tức.
                </p>
                <div style="display: flex; gap: 15px; margin-bottom: 10px;">
                    <div style="flex: 1;">
                        <label style="font-size: 13px;">Từ ngày:</label>
                        <input type="date" id="start_date" name="start_date"
                            style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 13px;">Đến ngày:</label>
                        <input type="date" id="end_date" name="end_date"
                            style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                    </div>
                </div>
                <div>
                    <label style="font-size: 13px;">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                    <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div
                    style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--border-color); color: #0369a1; font-size: 12px;">
                    * Ghi chú: Nếu hệ thống tính toán ra cùng lịch cho nhiều video, chúng sẽ được xếp cách nhau 5 phút.
                </div>
            </div>

            <div class="form-group"
                style="flex:1; min-width:300px; background:#f0fdf4; padding:15px; border-radius:6px; border:1px solid #bbf7d0; margin-bottom:0;">
                <label
                    style="color:#15803d; font-weight:500; display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="enable_comment" id="enableComment" value="1"
                        onchange="toggleCommentMode('timer')" style="width:16px;height:16px;accent-color:#16a34a;">
                    💬 Bình luận vào bài viết sau khi đăng (120 giây)
                </label>
                <div id="commentBox" style="display:none; margin-top:12px;">
                    <label style="font-size:13px; color:#166534;">Mỗi dòng = 1 nội dung bình luận (random 1
                        dòng):</label>
                    <textarea name="comment_lines" id="commentLinesTimer" rows="4"
                        placeholder="Bình luận hay quá!&#10;Cảm ơn bạn đã xem!&#10;Video rất bổ ích 👍"
                        style="width:100%; margin-top:6px; padding:8px 10px; border:1px solid #86efac; border-radius:6px; font-size:13px; resize:vertical; background:#fff;"></textarea>
                    <p style="font-size:11px; color:#166534; margin-top:5px; margin-bottom:0;">⚡ Hệ thống sẽ chọn ngẫu
                        nhiên 1 dòng để bình luận sau 120 giây kể từ khi bài được đăng thành công.</p>
                </div>

                <div style="border-top: 1px dashed #86efac; margin-top: 14px; padding-top: 14px;">
                    <label
                        style="color:#15803d; font-weight:500; display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="enable_comment_insights" id="enableCommentInsights" value="1"
                            onchange="toggleCommentMode('insights')"
                            style="width:16px;height:16px;accent-color:#16a34a;">
                        💬 Bình luận khi đủ số Like, View, Bình luận
                    </label>
                    <div id="commentInsightsBox" style="display:none; margin-top:12px;">
                        <div style="display:flex; gap:10px; margin-bottom:10px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:90px;">
                                <label style="font-size:12px; color:#166534; font-weight:500;">👁️ View tối
                                    thiểu</label>
                                <input type="number" name="threshold_views" id="thresholdViews" value="1000" min="0"
                                    style="width:100%; padding:7px 10px; border:1px solid #86efac; border-radius:6px; font-size:13px; background:#fff; margin-top:4px;">
                            </div>
                            <div style="flex:1; min-width:90px;">
                                <label style="font-size:12px; color:#166534; font-weight:500;">👍 Like tối thiểu</label>
                                <input type="number" name="threshold_likes" id="thresholdLikes" value="10" min="0"
                                    style="width:100%; padding:7px 10px; border:1px solid #86efac; border-radius:6px; font-size:13px; background:#fff; margin-top:4px;">
                            </div>
                            <div style="flex:1; min-width:90px;">
                                <label style="font-size:12px; color:#166534; font-weight:500;">💬 Comment tối
                                    thiểu</label>
                                <input type="number" name="threshold_comments" id="thresholdComments" value="5" min="0"
                                    style="width:100%; padding:7px 10px; border:1px solid #86efac; border-radius:6px; font-size:13px; background:#fff; margin-top:4px;">
                            </div>
                        </div>
                        <label style="font-size:13px; color:#166534;">Mỗi dòng = 1 nội dung bình luận (random 1
                            dòng):</label>
                        <textarea name="comment_lines_insights" id="commentLinesInsights" rows="4"
                            placeholder="Bình luận hay quá!&#10;Cảm ơn bạn đã xem!&#10;Video rất bổ ích 👍"
                            style="width:100%; margin-top:6px; padding:8px 10px; border:1px solid #86efac; border-radius:6px; font-size:13px; resize:vertical; background:#fff;"></textarea>
                        <p style="font-size:11px; color:#166534; margin-top:5px; margin-bottom:0;">📊 Cron sẽ kiểm tra
                            mỗi 1 phút. Khi Reels đạt đủ <b>tất cả</b> các ngưỡng (View, Like, Comment), hệ thống sẽ tự
                            động bình luận.</p>
                    </div>
                </div>
            </div>

        </div>

        <input type="hidden" name="is_reel" value="1">

        <div id="reelsResult" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px;"></div>

        <div class="form-group" style="margin-top: 15px;">
            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                <input type="checkbox" name="use_ai" value="1" style="width: 18px; height: 18px;">
                🤖 Tự động viết lại nội dung/tiêu đề với AI trước khi đăng
            </label>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button id="btnSubmit" class="btn btn-primary" type="submit">Xác nhận Đăng / Lên Lịch</button>
        </div>
    </form>
</div>

<script>
    // Toggle between two comment modes (mutually exclusive)
    function toggleCommentMode(mode) {
        const timerCb = document.getElementById('enableComment');
        const insightsCb = document.getElementById('enableCommentInsights');
        const timerBox = document.getElementById('commentBox');
        const insightsBox = document.getElementById('commentInsightsBox');

        if (mode === 'timer') {
            if (timerCb.checked) {
                insightsCb.checked = false;
                insightsBox.style.display = 'none';
            }
            timerBox.style.display = timerCb.checked ? 'block' : 'none';
        } else {
            if (insightsCb.checked) {
                timerCb.checked = false;
                timerBox.style.display = 'none';
            }
            insightsBox.style.display = insightsCb.checked ? 'block' : 'none';
        }
    }

    const allPages = <?php echo $pages_json; ?>;
    const userSelect = document.getElementById('user_select');
    const pageSelect = document.getElementById('page_select');
    const reelsForm = document.getElementById('reelsForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const reelsResult = document.getElementById('reelsResult');

    userSelect.addEventListener('change', function () {
        window.pageSelectorFilterByUser(this.value);
    });

    reelsForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const tiktokUrls = document.getElementById('tiktok_urls').value.trim();
        const videoEl = document.getElementById('video');
        const videoFiles = videoEl ? videoEl.files.length : 0;
        const driveFileId = document.getElementById('drive_file_id').value.trim();

        if (!tiktokUrls && videoFiles === 0 && !driveFileId) {
            alert('Vui lòng cung cấp ít nhất 1 Link TikTok, HOẶC File Video tải lên, HOẶC tệp Google Drive.');
            return;
        }
        if (!window.pageSelectorValidate()) return;

        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Đang tải lên và thực hiện (Có thể mất đến 1-2 phút)...';
        reelsResult.style.display = 'none';

        const formData = new FormData(reelsForm);

        fetch('actions/publish_video.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                reelsResult.style.display = 'block';
                if (data.status === 'success') {
                    reelsResult.className = 'alert alert-success';
                    reelsResult.innerHTML = data.msg;
                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1500);
                    } else if (data.post_id) {
                        reelsResult.innerHTML += ' <a href="https://facebook.com/' + data.post_id + '" target="_blank">Xem Reels</a>';
                    }
                    reelsForm.reset();
                    window.pageSelectorFilterByUser('');
                } else {
                    reelsResult.className = 'alert alert-danger';
                    reelsResult.innerHTML = data.msg;
                }
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Xác nhận Đăng / Lên Lịch';
            })
            .catch(error => {
                reelsResult.style.display = 'block';
                reelsResult.className = 'alert alert-danger';
                reelsResult.innerHTML = 'Lỗi mạng hoặc hệ thống.';
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Xác nhận Đăng / Lên Lịch';
            });
    });

    function onDriveFilesSelected(files) {
        if (files.length === 0) return;
        const fileIds = files.map(f => f.id).join(',');

        document.getElementById('drive_file_id').value = fileIds;
        document.getElementById('video').value = ''; // Xóa local file

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

    function clearDriveSelection() {
        document.getElementById('drive_file_id').value = '';
        document.getElementById('driveSelectionInfo').style.display = 'none';
    }
</script>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/emoji_picker.php'; ?>
<script>initEmojiPicker('emojiTriggerReels', 'emojiPopupReels', 'description');</script>
<?php include 'includes/footer.php'; ?>