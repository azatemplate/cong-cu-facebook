<?php
$current_page = 'story';
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

<div class="page-title">Publish Story</div>

<div class="card">
    <h3 style="margin-bottom: 15px;">Đăng Story</h3>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
        Chọn User quản lý (Token), sau đó chọn Fanpage tương ứng để chuẩn bị đăng Story.
    </p>

    <form id="storyForm" enctype="multipart/form-data">
        <div class="form-group">
            <label>1. Chọn User Quản Lý Token</label>
            <select id="user_select" name="user_id" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" required>
                <option value="">-- Chọn User Đã Móc Nối --</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>2. Chọn Fanpage</label>
            <?php include __DIR__ . '/includes/page_selector.php'; ?>
        </div>
        <div class="form-group">
            <label>3. Tải lên Ảnh/Video cho Story <?php echo $disable_local_upload ? '(Drive)' : ''; ?></label>
            <?php if ($disable_local_upload): ?>
            <div style="padding: 10px 15px; background: #fef3cd; border: 1px solid #ffc107; border-radius: 6px; font-size: 13px; color: #856404; margin-bottom: 10px;">
                🔒 Admin đã tắt tính năng tải tệp từ máy tính. Vui lòng sử dụng Google Drive.
            </div>
            <?php endif; ?>
            <div style="display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 10px; border: 1px dashed var(--border-color); border-radius: 6px;">
                <?php if (!$disable_local_upload): ?>
                <input type="file" id="media" name="media[]" multiple accept="image/*,video/mp4,video/x-m4v" style="width: 100%; max-width: 250px; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;" onchange="clearDriveSelection()">
                <div style="font-weight: bold; color: #64748b;">HOẶC</div>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="openDriveModal()" style="background: #fff; border: 1px solid #cbd5e1; color: #334155; display: flex; align-items: center; gap: 5px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                    Chọn từ Google Drive
                </button>
            </div>
            <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 10px 15px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 6px; font-size: 13px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; color: #0369a1; font-weight: bold;">
                    <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg> Đã chọn <span id="driveSelectedCount">0</span> file từ Google Drive:</span>
                    <button type="button" onclick="clearDriveSelection()" style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12px;">Hủy / Xoá hết</button>
                </div>
                <ul id="driveSelectedList" style="margin: 0; padding-left: 20px; color: #0c4a6e; max-height: 120px; overflow-y: auto; line-height: 1.6;"></ul>
            </div>
            <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
        </div>

        <div class="form-group" style="background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid var(--border-color); margin-top: 4px;">
            <label style="color: var(--primary-color);">Lên lịch tự động hàng loạt (Tùy chọn)</label>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px; margin-bottom: 15px;">
                Chọn khoảng ngày và các khung giờ. Hệ thống sẽ trộn ngẫu nhiên tất cả Media (file ảnh/video, drive) bạn cung cấp và rải đều vào các khung giờ. Nếu bạn không nhập lịch, Media đầu tiên sẽ được đăng ngay.
            </p>
            <div style="display: flex; gap: 15px; margin-bottom: 10px;">
                <div style="flex: 1;">
                    <label style="font-size: 13px;">Từ ngày:</label>
                    <input type="date" id="start_date" name="start_date" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 13px;">Đến ngày:</label>
                    <input type="date" id="end_date" name="end_date" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
            </div>
            <div>
                <label style="font-size: 13px;">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
        </div>
        
        <div id="storyResult" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px;"></div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button id="btnSubmit" class="btn btn-primary" type="submit">Xác nhận Đăng / Lên Lịch</button>
            </div>
        </form>
    </div>

<script>
    const allPages = <?php echo $pages_json; ?>;
    const userSelect = document.getElementById('user_select');
    const storyForm = document.getElementById('storyForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const storyResult = document.getElementById('storyResult');

    userSelect.addEventListener('change', function() {
        window.pageSelectorFilterByUser(this.value);
    });

    storyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const mediaEl = document.getElementById('media');
        const mediaFile = mediaEl ? mediaEl.files.length : 0;
        const driveFile = document.getElementById('drive_file_id').value.trim();
        
        if (mediaFile === 0 && !driveFile) {
            alert('Vui lòng đính kèm file Ảnh/Video HOẶC chọn từ Google Drive.');
            return;
        }
        if (!window.pageSelectorValidate()) return;
        
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Đang tải lên và đăng Story...';
        storyResult.style.display = 'none';

        const formData = new FormData(storyForm);

        fetch('actions/publish_story.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            storyResult.style.display = 'block';
            if (data.status === 'success') {
                storyResult.className = 'alert alert-success';
                storyResult.innerHTML = data.msg;
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1500);
                } else if (data.post_id) {
                    storyResult.innerHTML += ' <a href="https://facebook.com/' + data.post_id + '" target="_blank">Xem Story</a>';
                }
                storyForm.reset();
                window.pageSelectorFilterByUser('');
            } else {
                storyResult.className = 'alert alert-danger';
                storyResult.innerHTML = data.msg;
            }
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Đăng Story Ngay';
        })
        .catch(error => {
            storyResult.style.display = 'block';
            storyResult.className = 'alert alert-danger';
            storyResult.innerHTML = 'Lỗi mạng hoặc hệ thống.';
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Đăng Story Ngay';
        });
    });
    
    function onDriveFilesSelected(files) {
        if (files.length === 0) return;
        const fileIds = files.map(f => f.id).join(',');
        
        document.getElementById('drive_file_id').value = fileIds;
        document.getElementById('media').value = ''; // Xóa local file
        
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
<?php include 'includes/footer.php'; ?>
