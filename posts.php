<?php
$current_page = 'posts';
require_once __DIR__ . '/includes/header.php';

// Fetch all users to display in the dropdown
$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

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

// Fetch all pages grouped by user (for JS filtering)
// Includes owned pages AND shared pages
$stmt2 = $pdo->prepare("
    (SELECT p.id, p.page_id, p.name, p.user_id 
     FROM pages p JOIN users u ON p.user_id = u.id 
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.id, p.page_id, p.name, u.id as user_id
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

<div class="page-title">Image & Status Posts</div>

<div class="card">
    <h3 style="margin-bottom: 15px;">Tạo bài viết mới</h3>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
        Chọn User quản lý (Token), sau đó chọn Fanpage tương ứng để chuẩn bị đăng bài.
    </p>

    <form id="postForm" enctype="multipart/form-data">
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
            <label>3. Nội dung bài viết</label>
            <textarea id="message" name="message" rows="4" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" placeholder="Bạn đang nghĩ gì?" required></textarea>
        </div>
        <div class="form-group">
            <label>4. Chọn Hình Ảnh (Tùy chọn - Có thể chọn nhiều để random)</label>
            <div style="display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 10px; border: 1px dashed var(--border-color); border-radius: 6px;">
                <input type="file" id="images" name="images[]" multiple accept="image/*" style="width: 100%; max-width: 250px; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;" onchange="clearDriveSelection()">
                <div style="font-weight: bold; color: #64748b;">HOẶC</div>
                <button type="button" class="btn btn-secondary" onclick="openDriveModal('multiple')" style="background: #fff; border: 1px solid #cbd5e1; color: #334155; display: flex; align-items: center; gap: 5px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                    Chọn từ Google Drive (Nhiều file)
                </button>
            </div>
            <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 8px 12px; background: #e0f2fe; color: #0369a1; border-radius: 4px; font-size: 13px;">
                Đã chọn <strong id="driveSelectedCount">0</strong> file từ Drive. <span id="driveSelectedName"></span>
                <button type="button" onclick="clearDriveSelection()" style="margin-left: 10px; background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline;">Hủy</button>
            </div>
            <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
        </div>
        
        <div id="postResult" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px;"></div>

        <div style="display:flex; gap:14px; align-items:stretch; flex-wrap:wrap;">

        <div class="form-group" style="flex:1; min-width:300px; background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid var(--border-color); margin-bottom:0;">
            <label style="color: var(--primary-color);">Lên lịch tự động đăng (Tùy chọn)</label>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 0; margin-bottom: 10px;">Nếu để trống, bài viết sẽ được đăng ngay lập tức. Tính năng này chạy ngầm qua thư mục Cronjob.</p>
            <input type="datetime-local" id="scheduled_time" name="scheduled_time" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; width:100%;">
        </div>

        <div class="form-group" style="flex:1; min-width:300px; background:#f0fdf4; padding:15px; border-radius:6px; border:1px solid #bbf7d0; margin-bottom:0;">
            <label style="color:#15803d; font-weight:500; display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" name="enable_comment" id="enableComment" value="1" onchange="document.getElementById('commentBox').style.display=this.checked?'block':'none'" style="width:16px;height:16px;accent-color:#16a34a;">
                💬 Bình luận vào bài viết sau khi đăng (120 giây)
            </label>
            <div id="commentBox" style="display:none; margin-top:12px;">
                <label style="font-size:13px; color:#166534;">Mỗi dòng = 1 nội dung bình luận (random 1 dòng):</label>
                <textarea name="comment_lines" rows="4" placeholder="Bình luận hay quá!&#10;Cảm ơn bạn đã xem!&#10;Rất bổ ích 👍" style="width:100%; margin-top:6px; padding:8px 10px; border:1px solid #86efac; border-radius:6px; font-size:13px; resize:vertical; background:#fff;"></textarea>
                <p style="font-size:11px; color:#166534; margin-top:5px; margin-bottom:0;">⚡ Hệ thống sẽ chọn ngẫu nhiên 1 dòng để bình luận sau 120 giây kể từ khi bài được đăng thành công.</p>
            </div>
        </div>

        </div>

        <div class="form-group" style="margin-top: 15px;">
            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                <input type="checkbox" name="use_ai" value="1" style="width: 18px; height: 18px;">
                🤖 Tự động viết lại nội dung với AI trước khi đăng (Dùng cấu hình đang Active ở Cấu hình AI)
            </label>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button id="btnSubmit" class="btn btn-primary" type="submit">Xác nhận / Lên Lịch</button>
        </div>
    </form>
</div>

<script>
    const allPages = <?php echo $pages_json; ?>;
    const userSelect = document.getElementById('user_select');
    const postForm = document.getElementById('postForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const postResult = document.getElementById('postResult');

    userSelect.addEventListener('change', function() {
        window.pageSelectorFilterByUser(this.value);
    });

    postForm.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!window.pageSelectorValidate()) return;
        
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Đang đăng bài...';
        postResult.style.display = 'none';

        const formData = new FormData(postForm);

        fetch('actions/publish_post.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            postResult.style.display = 'block';
            if (data.status === 'success') {
                postResult.className = 'alert alert-success';
                postResult.innerHTML = data.msg;
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1500);
                } else if (data.post_id) {
                    postResult.innerHTML += ' <a href="https://facebook.com/' + data.post_id + '" target="_blank">Xem Bài Viết</a>';
                }
                postForm.reset();
                window.pageSelectorFilterByUser('');
            } else {
                postResult.className = 'alert alert-danger';
                postResult.innerHTML = data.msg;
            }
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Đăng Ngay';
        })
        .catch(error => {
            postResult.style.display = 'block';
            postResult.className = 'alert alert-danger';
            postResult.innerHTML = 'Lỗi mạng hoặc hệ thống.';
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Đăng Ngay';
        });
    });
    
    // Hỗ trợ chọn nhiều file từ Drive (giống videos.php)
    function onDriveFileSelected(fileId, fileName) {
        let currentIds = document.getElementById('drive_file_id').value;
        let idArray = currentIds ? currentIds.split(',') : [];
        
        let currentNamesText = document.getElementById('driveSelectedName').innerText;
        let nameArray = currentNamesText ? currentNamesText.split(', ') : [];
        
        // Cập nhật giá trị vào mảng
        if (Array.isArray(fileId)) {
            idArray = fileId;
            nameArray = Array.isArray(fileName) ? fileName : [fileName];
        } else {
             if(idArray.length === 0 && !Array.isArray(fileId)) {
                  idArray.push(fileId);
                  nameArray.push(fileName);
             } else {
                 if (!idArray.includes(fileId)) {
                    idArray.push(fileId);
                    nameArray.push(fileName);
                 }
             }
        }
        
        document.getElementById('drive_file_id').value = idArray.join(',');
        document.getElementById('images').value = ''; // Xóa local file
        
        document.getElementById('driveSelectedCount').innerText = idArray.length;
        document.getElementById('driveSelectedName').innerText = nameArray.join(', ');
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }
    
    function clearDriveSelection() {
        document.getElementById('drive_file_id').value = '';
        document.getElementById('driveSelectedCount').innerText = '0';
        document.getElementById('driveSelectedName').innerText = '';
        document.getElementById('driveSelectionInfo').style.display = 'none';
    }
</script>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/footer.php'; ?>
