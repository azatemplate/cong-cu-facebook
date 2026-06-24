<?php
$current_page = 'posts';
require_once __DIR__ . '/includes/header.php';

// Fetch all users to display in the dropdown
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

// Fetch all pages grouped by user (for JS filtering)
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
        <div class="form-group" style="position: relative;">
            <label style="display: flex; align-items: center; gap: 8px;">3. Nội dung bài viết
                <button type="button" id="emojiTriggerPost" class="emoji-picker-trigger">😀 Emoji</button>
            </label>
            <div id="emojiPopupPost" class="emoji-picker-popup">
                <div class="emoji-tabs"></div>
                <div class="emoji-search-box"><input type="text" class="emoji-search-input" placeholder="Tìm emoji..."></div>
                <div class="emoji-grid-wrap"></div>
            </div>
            <textarea id="message" name="message" rows="4" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" placeholder="Bạn đang nghĩ gì?" required></textarea>
            <small style="color: #64748b;">💡 Hỗ trợ Spin: <code>{nội dung 1|nội dung 2|nội dung 3}</code> — hệ thống sẽ random chọn 1 phiên bản mỗi lần đăng.</small>
        </div>
        <div class="form-group">
            <label>4. Chọn Hình Ảnh (Tùy chọn<?php echo $disable_local_upload ? ' - Drive' : ' - Có thể chọn nhiều để random'; ?>)</label>
            <?php if ($disable_local_upload): ?>
            <div style="padding: 10px 15px; background: #fef3cd; border: 1px solid #ffc107; border-radius: 6px; font-size: 13px; color: #856404; margin-bottom: 10px;">
                🔒 Admin đã tắt tính năng tải tệp từ máy tính. Vui lòng sử dụng Google Drive.
            </div>
            <?php endif; ?>
            <div style="display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 10px; border: 1px dashed var(--border-color); border-radius: 6px;">
                <?php if (!$disable_local_upload): ?>
                <input type="file" id="images" name="images[]" multiple accept="image/*" style="width: 100%; max-width: 250px; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;" onchange="clearDriveSelection()">
                <div style="font-weight: bold; color: #64748b;">HOẶC</div>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="openDriveModal('multiple')" style="background: #fff; border: 1px solid #cbd5e1; color: #334155; display: flex; align-items: center; gap: 5px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                    Chọn từ Google Drive (Nhiều file)
                </button>
            </div>
            <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 8px 12px; border-radius: 4px; font-size: 13px;"></div>
            <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 8px 12px; background: #e0f2fe; color: #0369a1; border-radius: 4px; font-size: 13px;">
                Đã chọn <strong id="driveSelectedCount">0</strong> file từ Drive. <span id="driveSelectedName"></span>
                <button type="button" onclick="clearDriveSelection()" style="margin-left: 10px; background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline;">Hủy</button>
            </div>
            <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
        </div>

        <div class="form-group" style="background: #fff7ed; padding: 15px; border-radius: 6px; border: 1px dashed #fed7aa; margin-bottom: 0;">
            <label style="color: #c2410c; font-weight: 500; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                <input type="checkbox" id="enable_random_images" name="enable_random_images" value="1" style="width: 16px; height: 16px; accent-color: #ea580c;">
                🎲 Random lấy X ảnh từ danh sách đã chọn
            </label>
            <div id="randomImagesBox" style="display: none; margin-top: 10px;">
                <p style="font-size: 12px; color: #9a3412; margin-top: 0; margin-bottom: 8px;">Chọn hàng trăm ảnh từ Drive, hệ thống sẽ random lấy X ảnh cho mỗi bài đăng.</p>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label style="font-size: 13px; color: #9a3412; white-space: nowrap;">Số ảnh mỗi bài:</label>
                    <input type="number" id="random_image_count" name="random_image_count" value="5" min="1" max="50" style="width: 80px; padding: 8px; border: 1px solid #fed7aa; border-radius: 6px; font-size: 14px; font-weight: bold; text-align: center;">
                </div>
            </div>
        </div>
        
        <div class="form-group" style="background: #f0fdfa; padding: 15px; border-radius: 6px; border: 1px dashed #99f6e4; margin-top: 15px;">
            <label style="color: #0d9488; font-weight: 500; display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 0;">
                <input type="checkbox" id="delete_drive_file" name="delete_drive_file" value="1" style="width: 16px; height: 16px; accent-color: #0d9488;">
                🛡️ Chống trùng và xóa file đã đăng drive
            </label>
            <p style="font-size: 12px; color: #0f766e; margin-top: 5px; margin-bottom: 0;">
                Khi chọn, nội dung đăng sẽ không trùng lặp và tự động xóa khỏi Google Drive sau khi đăng.
            </p>
        </div>
        
        <div id="postResult" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px;"></div>

        <div style="display:flex; gap:14px; align-items:stretch; flex-wrap:wrap;">

        <div class="form-group" style="flex:1; min-width:300px; background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid var(--border-color); margin-bottom:0;">
            <label style="color: var(--primary-color);">5. Lên lịch tự động hàng loạt (Tùy chọn)</label>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px; margin-bottom: 15px;">
                Chọn khoảng ngày và các khung giờ. Hệ thống sẽ rải đều bài đăng vào các khung giờ. Nếu bạn không nhập lịch, bài viết sẽ được đăng ngay lập tức.
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
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--border-color); color: #0369a1; font-size: 12px;">
                * Ghi chú: Nếu hệ thống tính toán ra cùng lịch cho nhiều bài, chúng sẽ được xếp cách nhau 5 phút.
            </div>
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

    // Toggle random images box
    document.getElementById('enable_random_images').addEventListener('change', function() {
        document.getElementById('randomImagesBox').style.display = this.checked ? 'block' : 'none';
    });

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

                fetch('actions/drive_proxy.php?action=upload', {
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
                        reject(data.msg || `Lỗi tải file ${file.name} lên Google Drive.`);
                    }
                })
                .catch(error => {
                    reject(error.message || error || `Lỗi kết nối khi tải file ${file.name}.`);
                });
            }

            uploadNext(0);
        });
    }

    postForm.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!window.pageSelectorValidate()) return;
        
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Đang xử lý...';
        postResult.style.display = 'none';

        const localStatus = document.getElementById('localUploadStatus');
        if (localStatus) {
            localStatus.style.display = 'none';
            localStatus.innerText = '';
        }

        const imagesEl = document.getElementById('images');

        uploadLocalFilesPromise(imagesEl, function(msg) {
            if (localStatus) {
                localStatus.style.display = 'block';
                localStatus.className = 'alert alert-warning';
                localStatus.style.background = '#fef3cd';
                localStatus.style.color = '#856404';
                localStatus.style.border = '1px solid #ffeeba';
                localStatus.innerText = msg;
            }
            btnSubmit.textContent = 'Đang tải file lên Google Drive...';
        })
        .then(uploadedFiles => {
            if (uploadedFiles && uploadedFiles.length > 0) {
                if (localStatus) {
                    localStatus.className = 'alert alert-success';
                    localStatus.style.background = '#d4edda';
                    localStatus.style.color = '#155724';
                    localStatus.style.border = '1px solid #c3e6cb';
                    localStatus.innerText = '✅ Tải lên Google Drive thành công! Đang tiến hành lên lịch...';
                }
                
                const fileIds = uploadedFiles.map(f => f.id).join(',');
                document.getElementById('drive_file_id').value = fileIds;
                
                if (imagesEl) imagesEl.value = '';
            }

            btnSubmit.textContent = 'Đang xử lý đăng bài (Có thể mất 1-2 phút)...';
            const formData = new FormData(postForm);

            return fetch('actions/publish_post.php', {
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
                if (localStatus) localStatus.style.display = 'none';
            } else {
                postResult.className = 'alert alert-danger';
                postResult.innerHTML = data.msg;
            }
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Xác nhận / Lên Lịch';
        })
        .catch(error => {
            postResult.style.display = 'block';
            postResult.className = 'alert alert-danger';
            postResult.innerHTML = 'Lỗi: ' + (error.message || error || 'Lỗi mạng hoặc hệ thống.');
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Xác nhận / Lên Lịch';
        });
    });
    
    // Hỗ trợ chọn nhiều file từ Drive - dùng onDriveFilesSelected (plural) để nhận toàn bộ mảng
    function onDriveFilesSelected(files) {
        const idArray = files.map(f => f.id);
        const nameArray = files.map(f => f.name);
        
        document.getElementById('drive_file_id').value = idArray.join(',');
        const imagesEl = document.getElementById('images');
        if (imagesEl) imagesEl.value = ''; // Xóa local file
        
        document.getElementById('driveSelectedCount').innerText = idArray.length;
        // Chỉ hiển thị tối đa 3 tên file, còn lại ghi "và X file khác"
        let displayName = '';
        if (nameArray.length <= 3) {
            displayName = nameArray.join(', ');
        } else {
            displayName = nameArray.slice(0, 3).join(', ') + ` và ${nameArray.length - 3} file khác`;
        }
        document.getElementById('driveSelectedName').innerText = displayName;
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }

    function onDriveFolderSelected(folderId, folderName) {
        document.getElementById('drive_file_id').value = 'folder:' + folderId;
        const imagesEl = document.getElementById('images');
        if (imagesEl) imagesEl.value = ''; // Xóa local file
        
        document.getElementById('driveSelectedCount').innerText = 'Thư mục';
        document.getElementById('driveSelectedName').innerText = folderName;
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }
    
    function clearDriveSelection() {
        document.getElementById('drive_file_id').value = '';
        document.getElementById('driveSelectedCount').innerText = '0';
        document.getElementById('driveSelectedName').innerText = '';
        document.getElementById('driveSelectionInfo').style.display = 'none';
        
        const localStatus = document.getElementById('localUploadStatus');
        if (localStatus) {
            localStatus.style.display = 'none';
            localStatus.innerText = '';
        }
    }
</script>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/emoji_picker.php'; ?>
<script>initEmojiPicker('emojiTriggerPost', 'emojiPopupPost', 'message');</script>
<?php include 'includes/footer.php'; ?>
