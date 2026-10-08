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
/* Evondev Skill Styling for Story Publisher */
.story-container,
.story-container button,
.story-container input,
.story-container select,
.story-container textarea {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.story-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

.story-header-card {
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

.story-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.story-header-info p {
    font-size: 13.5px;
    color: #cbd5e1;
    margin: 0;
}

.story-card {
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

.story-section-title {
    font-weight: 800;
    font-size: 14.5px;
    color: #0f172a;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.story-input,
.story-select,
.story-textarea {
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

.story-input:focus,
.story-select:focus,
.story-textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.btn-story-submit {
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
.btn-story-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.45);
}
.btn-story-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
</style>

<div class="story-container">
    <!-- Header Banner Card -->
    <div class="story-header-card">
        <div class="story-header-info">
            <h1>
                <svg width="26" height="26" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Hệ Thống Đăng & Lên Lịch Facebook Story
            </h1>
            <p>Đăng Ảnh/Video Story trực tiếp hoặc xếp lịch rải đều tự động cho danh sách Fanpage</p>
        </div>
    </div>

    <!-- Main Card -->
    <div class="story-card">
        <form id="storyForm" enctype="multipart/form-data">
            <!-- 1. Select Mode -->
            <div class="form-group" style="margin-bottom: 24px;">
                <div class="story-section-title">
                    <span>1. Chọn Fanpage Cần Đăng:</span>
                    <div class="mode-toggle-group">
                        <button type="button" class="btn-mode active" id="btn_mode_user" onclick="switchSelectMode('user')">👤 Theo User Token</button>
                        <button type="button" class="btn-mode" id="btn_mode_group" onclick="switchSelectMode('group')">📂 Theo Nhóm Fanpage</button>
                    </div>
                </div>

                <input type="hidden" id="select_mode" name="select_mode" value="user">
                <div id="wrap_user_select">
                    <select id="user_select" name="user_id" class="story-select">
                        <option value="">-- Chọn User Quản Lý --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="wrap_group_select" style="display: none;">
                    <select id="group_select" name="group_id" class="story-select">
                        <option value="">-- Chọn Nhóm Fanpage --</option>
                        <?php foreach ($page_groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 2. Page Selector -->
            <div class="form-group" style="margin-bottom: 24px;">
                <label class="story-section-title">2. Chọn Fanpage Cụ Thể:</label>
                <?php include __DIR__ . '/includes/page_selector.php'; ?>
            </div>

            <!-- 3. Story Media Upload -->
            <div class="form-group" style="margin-bottom: 24px;">
                <label class="story-section-title">3. Tải lên Ảnh / Video cho Story <?php echo $disable_local_upload ? '(Google Drive)' : '(Máy tính hoặc Drive)'; ?>:</label>
                
                <?php if ($disable_local_upload): ?>
                <div style="padding: 12px 16px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; font-size: 13px; color: #c2410c; font-weight: 700; margin-bottom: 12px;">
                    🔒 Admin đã tắt tính năng tải tệp từ máy tính. Vui lòng sử dụng Google Drive.
                </div>
                <?php endif; ?>

                <div style="display: flex; gap: 12px; align-items: center; background: #f8fafc; padding: 16px; border: 1px dashed #cbd5e1; border-radius: 12px; flex-wrap: wrap;">
                    <?php if (!$disable_local_upload): ?>
                    <input type="file" id="media" name="media[]" multiple accept="image/*,video/mp4,video/x-m4v" style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; background: #ffffff; font-size: 13px;" onchange="clearDriveSelection()">
                    <div style="font-weight: 800; color: #64748b; font-size: 12px;">HOẶC</div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" onclick="openDriveModal()" style="background: #ffffff; border: 1px solid #cbd5e1; color: #334155; padding: 9px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                        Chọn từ Google Drive
                    </button>
                </div>
                
                <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 700;"></div>
                <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 14px 18px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 12px; font-size: 13px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; color: #0369a1; font-weight: 800;">
                        <span>📁 Đã chọn <span id="driveSelectedCount">0</span> file từ Google Drive:</span>
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
                    <span>🛡️ Chống trùng và xóa file đã đăng Story trên Google Drive</span>
                </label>
                <p style="font-size: 12.5px; color: #0f766e; margin-top: 6px; margin-bottom: 0;">
                    Khi chọn, tệp story sẽ không trùng lặp và tự động xóa khỏi Google Drive sau khi đăng.
                </p>
            </div>

            <!-- 4. Bulk Scheduling Card -->
            <div class="form-group" style="background: #f8fafc; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
                <label style="color: #4f46e5; font-weight: 800; font-size: 14px; display: block; margin-bottom: 4px;">4. Lên lịch tự động hàng loạt (Tùy chọn)</label>
                <p style="font-size: 12.5px; color: #64748b; margin-top: 0; margin-bottom: 14px;">
                    Chọn khoảng ngày và các khung giờ đăng, tối đa 3 tháng mỗi chiến dịch Story.
                </p>
                <div style="display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 150px;">
                        <label style="font-size: 12px; font-weight: 700; color: #334155;">Từ ngày:</label>
                        <input type="date" id="start_date" name="start_date" class="story-input" style="padding: 8px 12px; font-size: 13px;">
                    </div>
                    <div style="flex: 1; min-width: 150px;">
                        <label style="font-size: 12px; font-weight: 700; color: #334155;">Đến ngày:</label>
                        <input type="date" id="end_date" name="end_date" class="story-input" style="padding: 8px 12px; font-size: 13px;">
                    </div>
                </div>
                <div>
                    <label style="font-size: 12px; font-weight: 700; color: #334155;">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                    <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" class="story-input" style="padding: 8px 12px; font-size: 13px;">
                </div>
            </div>

            <div id="storyResult" style="display: none; margin-bottom: 20px; padding: 14px 18px; border-radius: 12px; font-weight: 700; font-size: 13.5px;"></div>

            <div>
                <button id="btnSubmit" class="btn-story-submit" type="submit">
                    🚀 Xác nhận Đăng / Lên Lịch Story
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
    const storyForm = document.getElementById('storyForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const storyResult = document.getElementById('storyResult');

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
        btnSubmit.textContent = '⏳ Đang tải lên và thực hiện...';
        storyResult.style.display = 'none';

        const localStatus = document.getElementById('localUploadStatus');
        if (localStatus) {
            localStatus.style.display = 'none';
            localStatus.innerText = '';
        }

        uploadLocalFilesPromise(mediaEl, function(msg) {
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
                
                if (mediaEl) mediaEl.value = '';
            }

            btnSubmit.textContent = '⏳ Đang xử lý đăng bài (Có thể mất đến 1-2 phút)...';
            const formData = new FormData(storyForm);

            return fetch('actions/publish_story.php', {
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
            storyResult.style.display = 'block';
            if (data.status === 'success') {
                storyResult.style.background = '#ecfdf5';
                storyResult.style.color = '#065f46';
                storyResult.style.border = '1px solid #a7f3d0';
                storyResult.innerHTML = data.msg;
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1500);
                } else if (data.post_id) {
                    storyResult.innerHTML += ' <a href="https://facebook.com/' + data.post_id + '" target="_blank" style="color:#059669; font-weight:700;">Xem Story</a>';
                }
                storyForm.reset();
                window.pageSelectorFilterByUser('');
                if (localStatus) localStatus.style.display = 'none';
            } else {
                storyResult.style.background = '#fff7ed';
                storyResult.style.color = '#c2410c';
                storyResult.style.border = '1px solid #fed7aa';
                storyResult.innerHTML = data.msg;
            }
            btnSubmit.disabled = false;
            btnSubmit.textContent = '🚀 Xác nhận Đăng / Lên Lịch Story';
        })
        .catch(error => {
            storyResult.style.display = 'block';
            storyResult.style.background = '#fef2f2';
            storyResult.style.color = '#dc2626';
            storyResult.style.border = '1px solid #fecaca';
            storyResult.innerHTML = 'Lỗi: ' + (error.message || error || 'Lỗi mạng hoặc hệ thống.');
            btnSubmit.disabled = false;
            btnSubmit.textContent = '🚀 Xác nhận Đăng / Lên Lịch Story';
        });
    });
    
    function onDriveFilesSelected(files) {
        if (files.length === 0) return;
        const fileIds = files.map(f => f.id).join(',');
        const fileNames = files.map(f => f.name).join('|||');
        
        document.getElementById('drive_file_id').value = fileIds;
        document.getElementById('drive_file_names').value = fileNames;
        const mediaEl2 = document.getElementById('media');
        if (mediaEl2) mediaEl2.value = '';
        
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

    function onDriveFolderSelected(folderId, folderName) {
        document.getElementById('drive_file_id').value = 'folder:' + folderId;
        if (document.getElementById('drive_file_names')) {
            document.getElementById('drive_file_names').value = 'folder:' + folderName;
        }
        const mediaEl2 = document.getElementById('media');
        if (mediaEl2) mediaEl2.value = '';

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
<?php include 'includes/footer.php'; ?>
