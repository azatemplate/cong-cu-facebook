<?php
$current_page = 'videos';
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

$page_groups = [];
$page_group_items_map = [];
try {
    // Fetch page groups for account
    $stmt_groups = $pdo->prepare("SELECT id, name FROM page_groups WHERE account_id = ? ORDER BY name ASC");
    $stmt_groups->execute([$account_id]);
    $page_groups = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);

    // Fetch group item mappings
    if (!empty($page_groups)) {
        $stmt_items = $pdo->prepare("
            SELECT pgi.group_id, pgi.page_id 
            FROM page_group_items pgi
            JOIN page_groups pg ON pgi.group_id = pg.id
            WHERE pg.account_id = ?
        ");
        $stmt_items->execute([$account_id]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $page_group_items_map[$item['group_id']][] = (string)$item['page_id'];
        }
    }
} catch (Exception $e) {}
?>

<style>
/* Evondev Skill Styling for Video Scheduler */
.video-container,
.video-container button,
.video-container input,
.video-container select,
.video-container textarea {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.video-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

.video-header-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
    border: 1px solid #312e81;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(15, 23, 42, 0.15);
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.video-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.video-header-info p {
    font-size: 13.5px;
    color: #cbd5e1;
    margin: 0;
}

.video-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
}

/* Mode toggle pills */
.mode-toggle-group {
    display: inline-flex;
    gap: 4px;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.btn-mode {
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 700;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    background: transparent;
    color: #64748b;
    transition: all 0.2s ease;
}
.btn-mode.active {
    background: #ffffff;
    color: #6366f1;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.video-section-title {
    font-weight: 800;
    font-size: 14.5px;
    color: #0f172a;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.video-input,
.video-select,
.video-textarea {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.video-input:focus,
.video-select:focus,
.video-textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.btn-video-submit {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: #ffffff;
    font-weight: 800;
    font-size: 15px;
    padding: 14px 36px;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.35);
    transition: all 0.2s ease;
}
.btn-video-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.45);
}
.btn-video-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
</style>

<div class="video-container">
    <!-- Header Banner Card -->
    <div class="video-header-card">
        <div class="video-header-info">
            <h1>
                <svg width="26" height="26" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                Hệ Thống Đăng Video & Lên Lịch Facebook Video
            </h1>
            <p>Đăng video trực tiếp hoặc lên lịch hàng loạt cho Fanpage từ máy tính, Google Drive hoặc Kho Data</p>
        </div>
    </div>

    <!-- Main Card -->
    <div class="video-card">
        <form id="videoForm" enctype="multipart/form-data">
            <!-- 1. Fanpage Select Mode -->
            <div class="form-group" style="margin-bottom: 24px;">
                <div class="video-section-title">
                    <span>1. Chọn Fanpage Cần Đăng:</span>
                    <div class="mode-toggle-group">
                        <button type="button" class="btn-mode active" id="btn_mode_user" onclick="switchSelectMode('user')">👤 Theo User Token</button>
                        <button type="button" class="btn-mode" id="btn_mode_group" onclick="switchSelectMode('group')">📂 Theo Nhóm Fanpage</button>
                    </div>
                </div>

                <input type="hidden" id="select_mode" name="select_mode" value="user">
                <div id="wrap_user_select">
                    <select id="user_select" name="user_id" class="video-select">
                        <option value="">-- Chọn User Quản Lý --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="wrap_group_select" style="display: none;">
                    <select id="group_select" name="group_id" class="video-select">
                        <option value="">-- Chọn Nhóm Fanpage --</option>
                        <?php foreach ($page_groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 2. Page Selector -->
            <div class="form-group" style="margin-bottom: 24px;">
                <label class="video-section-title">2. Chọn Fanpage Cụ Thể:</label>
                <?php include __DIR__ . '/includes/page_selector.php'; ?>
            </div>

            <!-- Auto Title Checkbox -->
            <div class="form-group" style="background: #fdf2f8; padding: 18px; border-radius: 14px; border: 1px dashed #fbcfe8; margin-bottom: 24px;">
                <label style="color: #be185d; font-weight: 800; font-size: 13.5px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="width: 17px; height: 17px; accent-color: #be185d;"> 
                    <span>🎬 Tự động dùng Tên File / Tiêu đề TikTok làm Tiêu đề và Mô tả Video</span>
                </label>
                <p style="font-size: 12.5px; color: #9d174d; margin: 6px 0 0 25px;">
                    (Nếu chọn, hệ thống sẽ ưu tiên tiêu đề tệp video từ TikTok/Drive bỏ qua 2 ô nhập bên dưới)
                </p>
            </div>
            
            <!-- 3. Video Title -->
            <div class="form-group" style="margin-bottom: 24px;">
                <label class="video-section-title">3. Tiêu đề Video chung (Tùy chọn):</label>
                <input type="text" id="title" name="title" class="video-input" placeholder="Nhập tiêu đề chung cho các video...">
                <small style="color: #64748b; display: block; margin-top: 6px; font-size: 12px;">💡 Hỗ trợ Spin text: <code>{nội dung 1|nội dung 2|nội dung 3}</code></small>
            </div>

            <!-- 4. Video Description -->
            <div class="form-group" style="margin-bottom: 24px; position: relative;">
                <div class="video-section-title">
                    <span>4. Mô tả Video chung (Tùy chọn):</span>
                    <button type="button" id="emojiTriggerVideo" class="emoji-picker-trigger">😀 Chèn Emoji</button>
                </div>
                <div id="emojiPopupVideo" class="emoji-picker-popup">
                    <div class="emoji-tabs"></div>
                    <div class="emoji-search-box"><input type="text" class="emoji-search-input" placeholder="Tìm emoji..."></div>
                    <div class="emoji-grid-wrap"></div>
                </div>
                <textarea id="description" name="description" rows="3" class="video-textarea" placeholder="Nhập mô tả chung cho video..."></textarea>
                <small style="color: #64748b; display: block; margin-top: 6px; font-size: 12px;">💡 Hỗ trợ Spin text: <code>{nội dung 1|nội dung 2|nội dung 3}</code> — hệ thống sẽ random chọn 1 phiên bản mỗi lần đăng.</small>
            </div>

            <!-- 5. Video File Source -->
            <div class="form-group" style="margin-bottom: 24px;">
                <label class="video-section-title">5. Tải lên Video <?php echo $disable_local_upload ? '(Google Drive / Kho Data)' : '(Từ Máy tính, Drive hoặc Kho Data)'; ?>:</label>
                
                <?php if ($disable_local_upload): ?>
                <div style="padding: 12px 16px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; font-size: 13px; color: #c2410c; font-weight: 700; margin-bottom: 12px;">
                    🔒 Admin đã tắt tính năng tải tệp từ máy tính. Vui lòng sử dụng Google Drive hoặc Kho Data.
                </div>
                <?php endif; ?>

                <div style="display: flex; gap: 12px; align-items: center; background: #f8fafc; padding: 16px; border: 1px dashed #cbd5e1; border-radius: 12px; flex-wrap: wrap;">
                    <?php if (!$disable_local_upload): ?>
                    <input type="file" id="video" name="video[]" multiple accept="video/mp4,video/x-m4v,video/*" style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; background: #ffffff; font-size: 13px;" onchange="if(this.files && this.files.length > 0) { clearDriveSelection(); clearKhoDataSelection(); }">
                    <div style="font-weight: 800; color: #64748b; font-size: 12px;">HOẶC</div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" onclick="openDriveModal()" style="background: #ffffff; border: 1px solid #cbd5e1; color: #334155; padding: 9px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                        Chọn từ Google Drive
                    </button>
                    <?php include __DIR__ . '/includes/kho_data_selector.php'; ?>
                </div>

                <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 700;"></div>
                <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 14px 18px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 12px; font-size: 13px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; color: #0369a1; font-weight: 800;">
                        <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg> Đã chọn <span id="driveSelectedCount">0</span> file từ Google Drive:</span>
                        <button type="button" onclick="clearDriveSelection()" style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12.5px; font-weight: 800;">Hủy / Xoá hết</button>
                    </div>
                    <ul id="driveSelectedList" style="margin: 0; padding-left: 20px; color: #0c4a6e; max-height: 120px; overflow-y: auto; line-height: 1.6; font-weight: 600;"></ul>
                </div>
                <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
                <input type="hidden" id="drive_file_names" name="drive_file_names" value="">
            </div>
            
            <!-- Anti-dup card -->
            <div class="form-group" style="background: #f0fdfa; padding: 18px; border-radius: 14px; border: 1px dashed #99f6e4; margin-bottom: 24px;">
                <label style="color: #0d9488; font-weight: 800; font-size: 13.5px; display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 0;">
                    <input type="checkbox" id="delete_drive_file" name="delete_drive_file" value="1" style="width: 17px; height: 17px; accent-color: #0d9488;">
                    <span>🛡️ Chống trùng và xóa file đã đăng trên Google Drive</span>
                </label>
                <p style="font-size: 12.5px; color: #0f766e; margin-top: 6px; margin-bottom: 0;">
                    Khi chọn, nội dung đăng sẽ không trùng lặp và tự động xóa khỏi Google Drive sau khi đăng.
                </p>
            </div>

            <div id="videoResult" style="display: none; margin-bottom: 20px; padding: 14px 18px; border-radius: 12px; font-weight: 700; font-size: 13.5px;"></div>

            <!-- Schedule & Comment Grid -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px; margin-bottom: 24px;">
                <!-- 6. Bulk Scheduling Card -->
                <div class="form-group" style="background: #f8fafc; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; margin-bottom:0;">
                    <label style="color: #4f46e5; font-weight: 800; font-size: 14px; display: block; margin-bottom: 4px;">6. Lên lịch tự động hàng loạt (Tùy chọn)</label>
                    <p style="font-size: 12.5px; color: #64748b; margin-top: 0; margin-bottom: 14px;">
                        Chọn khoảng ngày và các khung giờ, tối đa 3 tháng mỗi chiến dịch.
                    </p>
                    <div style="display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 130px;">
                            <label style="font-size: 12px; font-weight: 700; color: #334155;">Từ ngày:</label>
                            <input type="date" id="start_date" name="start_date" class="video-input" style="padding: 8px 12px; font-size: 13px;">
                        </div>
                        <div style="flex: 1; min-width: 130px;">
                            <label style="font-size: 12px; font-weight: 700; color: #334155;">Đến ngày:</label>
                            <input type="date" id="end_date" name="end_date" class="video-input" style="padding: 8px 12px; font-size: 13px;">
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 12px; font-weight: 700; color: #334155;">Khung giờ đăng (dấu phẩy):</label>
                        <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" class="video-input" style="padding: 8px 12px; font-size: 13px;">
                    </div>
                    <div style="margin-top: 12px; font-size: 11.5px; color: #0369a1; font-weight: 600;">
                        💡 Ghi chú: Video trùng khung giờ sẽ tự động giãn cách 5 phút.
                    </div>
                </div>

                <!-- Auto Comment Card -->
                <div class="form-group" style="background:#f0fdf4; padding:20px; border-radius:14px; border:1px solid #bbf7d0; margin-bottom:0;">
                    <label style="color:#15803d; font-weight:800; font-size:14px; display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="enable_comment" id="enableComment" value="1" onchange="document.getElementById('commentBox').style.display=this.checked?'block':'none'" style="width:17px; height:17px; accent-color:#16a34a;">
                        💬 Bình luận tự động vào video sau khi đăng (120s)
                    </label>
                    <div id="commentBox" style="display:none; margin-top:14px;">
                        <label style="font-size:12.5px; color:#166534; font-weight:700;">Mỗi dòng = 1 nội dung bình luận (random 1 dòng):</label>
                        <textarea name="comment_lines" rows="6" placeholder="Bình luận hay quá!&#10;Cảm ơn bạn đã xem!&#10;Video rất bổ ích 👍" class="video-textarea" style="margin-top:6px; font-size:13px; border-color:#86efac; background:#ffffff;"></textarea>
                        <p style="font-size:11.5px; color:#166534; margin-top:6px; margin-bottom:0; font-weight:600;">⚡ Hệ thống sẽ chọn ngẫu nhiên 1 dòng để bình luận sau 120s.</p>
                    </div>
                </div>
            </div>

            <!-- AI Rewrite Option -->
            <div class="form-group" style="margin-bottom: 24px; background: #faf5ff; padding: 16px; border-radius: 12px; border: 1px solid #e9d5ff;">
                <label style="display: flex; align-items: center; gap: 10px; font-weight: 700; color: #7e22ce; font-size: 13.5px; cursor: pointer;">
                    <input type="checkbox" name="use_ai" value="1" style="width: 18px; height: 18px; accent-color: #9333ea;">
                    <span>🤖 Tự động viết lại nội dung/tiêu đề với AI trước khi đăng</span>
                </label>
            </div>

            <div>
                <button id="btnSubmit" class="btn-video-submit" type="submit">
                    🚀 Xác nhận Đăng / Lên Lịch Video
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const allPages = <?php echo $pages_json; ?>;
    const groupItemsMap = <?php echo json_encode($page_group_items_map); ?>;
    const userSelect = document.getElementById('user_select');
    const groupSelect = document.getElementById('group_select');
    const videoForm = document.getElementById('videoForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const videoResult = document.getElementById('videoResult');

    function switchSelectMode(mode) {
        const btnUser = document.getElementById('btn_mode_user');
        const btnGroup = document.getElementById('btn_mode_group');
        const wrapUser = document.getElementById('wrap_user_select');
        const wrapGroup = document.getElementById('wrap_group_select');
        const selectModeInput = document.getElementById('select_mode');
        if (selectModeInput) selectModeInput.value = mode;

        if (mode === 'user') {
            btnUser.classList.add('active');
            btnGroup.classList.remove('active');

            wrapUser.style.display = 'block';
            wrapGroup.style.display = 'none';
            if (groupSelect) groupSelect.value = '';

            window.pageSelectorFilterByUser(userSelect ? userSelect.value : '');
        } else {
            btnGroup.classList.add('active');
            btnUser.classList.remove('active');

            wrapUser.style.display = 'none';
            wrapGroup.style.display = 'block';
            if (userSelect) userSelect.value = '';

            if (groupSelect && groupSelect.value) {
                const pageIds = groupItemsMap[groupSelect.value] || [];
                window.pageSelectorFilterByGroup(pageIds);
            } else {
                window.pageSelectorFilterByGroup([]);
            }
        }
    }

    if (groupSelect) {
        groupSelect.addEventListener('change', function() {
            const pageIds = groupItemsMap[this.value] || [];
            window.pageSelectorFilterByGroup(pageIds);
        });
    }

    userSelect.addEventListener('change', function() {
        window.pageSelectorFilterByUser(this.value);
    });

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

                const userId = document.getElementById('user_select')?.value || '';
                fetch('actions/drive_proxy.php?action=upload&user_id=' + encodeURIComponent(userId), {
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
                        if (progressCallback) {
                            progressCallback(`⚠️ Drive bận/lỗi khi tải ${file.name}. Tự động chuyển sang tải trực tiếp từ máy...`);
                        }
                        uploadNext(index + 1);
                    }
                })
                .catch(error => {
                    console.warn(`Drive upload error/timeout for ${file.name}:`, error);
                    if (progressCallback) {
                        progressCallback(`⚠️ Máy chủ Drive quá tải/timeout (504) khi tải ${file.name}. Tự động chuyển sang tải trực tiếp từ máy...`);
                    }
                    uploadNext(index + 1);
                });
            }

            uploadNext(0);
        });
    }

    videoForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const dataGroupId = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
        const videoEl = document.getElementById('video');
        const videoFiles = videoEl ? videoEl.files.length : 0;
        const driveFileId = document.getElementById('drive_file_id').value.trim();
        
        if (!dataGroupId && videoFiles === 0 && !driveFileId) {
            if (typeof window.showNotice === 'function') {
                window.showNotice('Vui lòng chọn File Video tải lên, HOẶC tệp Google Drive, HOẶC chọn Nhóm Data từ Kho Data.', 'warning');
            } else {
                alert('Vui lòng chọn File Video tải lên, HOẶC tệp Google Drive, HOẶC chọn Nhóm Data từ Kho Data.');
            }
            return;
        }
        if (!window.pageSelectorValidate()) return;

        btnSubmit.disabled = true;
        btnSubmit.textContent = '⏳ Đang tải lên và thực hiện (Có thể mất đến 1-2 phút)...';
        videoResult.style.display = 'none';

        const localStatus = document.getElementById('localUploadStatus');
        if (localStatus) {
            localStatus.style.display = 'none';
            localStatus.innerText = '';
        }

        uploadLocalFilesPromise(videoEl, function(msg) {
            if (localStatus) {
                localStatus.style.display = 'block';
                localStatus.style.background = '#fff7ed';
                localStatus.style.color = '#c2410c';
                localStatus.style.border = '1px solid #fed7aa';
                localStatus.innerText = msg;
            }
            btnSubmit.textContent = '⏳ Đang tải file lên Google Drive...';
        })
        .then(uploadedFiles => {
            if (uploadedFiles && uploadedFiles.length > 0) {
                if (localStatus) {
                    localStatus.style.background = '#ecfdf5';
                    localStatus.style.color = '#065f46';
                    localStatus.style.border = '1px solid #a7f3d0';
                    localStatus.innerText = '✅ Tải lên Google Drive thành công! Đang tiến hành lên lịch...';
                }
                
                const fileIds = uploadedFiles.map(f => f.id).join(',');
                const fileNames = uploadedFiles.map(f => f.name).join('|||');
                
                document.getElementById('drive_file_id').value = fileIds;
                document.getElementById('drive_file_names').value = fileNames;
                
                if (videoEl) videoEl.value = '';
            }

            btnSubmit.textContent = '⏳ Đang xử lý đăng bài (Có thể mất đến 1-2 phút)...';
            const formData = new FormData(videoForm);
            const kdGroup = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
            const kdMode = document.getElementById('data_mode') ? document.getElementById('data_mode').value.trim() : 'dedup';
            if (kdGroup) {
                formData.set('data_group_id', kdGroup);
                formData.set('data_mode', kdMode);
            }

            return fetch('actions/publish_video.php', {
                method: 'POST',
                body: formData
            });
        })
        .then(response => {
            if (response instanceof Response) {
                return response.json();
            }
            throw new Error('Không nhận được phản hồi hợp lệ từ máy chủ.');
        })
        .then(data => {
            videoResult.style.display = 'block';
            if (data.status === 'success') {
                videoResult.style.background = '#ecfdf5';
                videoResult.style.color = '#065f46';
                videoResult.style.border = '1px solid #a7f3d0';
                videoResult.innerHTML = data.msg;
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1500);
                } else if (data.post_id) {
                    videoResult.innerHTML += ' <a href="https://facebook.com/' + data.post_id + '" target="_blank" style="color:#059669; font-weight:700;">Xem Video</a>';
                }
                videoForm.reset();
                window.pageSelectorFilterByUser('');
                if (localStatus) localStatus.style.display = 'none';
            } else {
                videoResult.style.background = '#fff7ed';
                videoResult.style.color = '#c2410c';
                videoResult.style.border = '1px solid #fed7aa';
                videoResult.innerHTML = data.msg;
            }
            btnSubmit.disabled = false;
            btnSubmit.textContent = '🚀 Xác nhận Đăng / Lên Lịch Video';
        })
        .catch(error => {
            videoResult.style.display = 'block';
            videoResult.style.background = '#fef2f2';
            videoResult.style.color = '#dc2626';
            videoResult.style.border = '1px solid #fecaca';
            videoResult.innerHTML = 'Lỗi: ' + (error.message || error || 'Lỗi mạng hoặc hệ thống.');
            btnSubmit.disabled = false;
            btnSubmit.textContent = '🚀 Xác nhận Đăng / Lên Lịch Video';
        });
    });

    function onDriveFilesSelected(files) {
        if (files.length === 0) return;
        const fileIds = files.map(f => f.id).join(',');
        const fileNames = files.map(f => f.name).join('|||');
        
        document.getElementById('drive_file_id').value = fileIds;
        document.getElementById('drive_file_names').value = fileNames;
        const videoEl = document.getElementById('video');
        if (videoEl) videoEl.value = '';

        const listEl = document.getElementById('driveSelectedList');
        if (listEl) {
            listEl.innerHTML = '';
            files.forEach(f => {
                const li = document.createElement('li');
                li.textContent = f.name;
                listEl.appendChild(li);
            });
        }
        
        document.getElementById('driveSelectedCount').innerText = files.length;
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }

    function onDriveFolderSelected(folderId, folderName) {
        document.getElementById('drive_file_id').value = 'folder:' + folderId;
        document.getElementById('drive_file_names').value = 'folder:' + folderName;
        const videoEl = document.getElementById('video');
        if (videoEl) videoEl.value = '';

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
        
        const localStatus = document.getElementById('localUploadStatus');
        if (localStatus) {
            localStatus.style.display = 'none';
            localStatus.innerText = '';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const startDateEl = document.getElementById('start_date');
        const endDateEl = document.getElementById('end_date');
        if (startDateEl && endDateEl) {
            function updateDateLimits() {
                if (startDateEl.value) {
                    endDateEl.min = startDateEl.value;
                    const start = new Date(startDateEl.value + 'T00:00:00');
                    const maxEnd = new Date(start);
                    maxEnd.setDate(maxEnd.getDate() + 90);
                    const yyyy = maxEnd.getFullYear();
                    const mm = String(maxEnd.getMonth() + 1).padStart(2, '0');
                    const dd = String(maxEnd.getDate()).padStart(2, '0');
                    const maxDateStr = `${yyyy}-${mm}-${dd}`;
                    endDateEl.max = maxDateStr;

                    if (endDateEl.value) {
                        if (endDateEl.value < startDateEl.value) {
                            endDateEl.value = startDateEl.value;
                        } else if (endDateEl.value > maxDateStr) {
                            endDateEl.value = maxDateStr;
                        }
                    }
                } else {
                    endDateEl.removeAttribute('min');
                    endDateEl.removeAttribute('max');
                }
            }
            startDateEl.addEventListener('change', updateDateLimits);
            startDateEl.addEventListener('input', updateDateLimits);
            endDateEl.addEventListener('change', function() {
                if (startDateEl.value && this.value) {
                    const start = new Date(startDateEl.value + 'T00:00:00');
                    const maxEnd = new Date(start);
                    maxEnd.setDate(maxEnd.getDate() + 90);
                    const maxDateStr = `${maxEnd.getFullYear()}-${String(maxEnd.getMonth() + 1).padStart(2, '0')}-${String(maxEnd.getDate()).padStart(2, '0')}`;
                    if (this.value > maxDateStr) {
                        alert('Thời gian hẹn giờ tối đa là 3 tháng (90 ngày) kể từ ngày bắt đầu.');
                        this.value = maxDateStr;
                    } else if (this.value < startDateEl.value) {
                        this.value = startDateEl.value;
                    }
                }
            });
            if (startDateEl.value) updateDateLimits();
        }
    });
</script>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/emoji_picker.php'; ?>
<script>initEmojiPicker('emojiTriggerVideo', 'emojiPopupVideo', 'description');</script>
<?php include 'includes/footer.php'; ?>