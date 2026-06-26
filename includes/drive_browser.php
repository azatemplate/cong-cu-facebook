<!-- includes/drive_browser.php -->
<div id="driveModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #fff; width: 90%; max-width: 600px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: flex; flex-direction: column; overflow: hidden; max-height: 80vh;">
        <div style="padding: 15px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h4 style="margin: 0; color: #334155; display: flex; align-items: center; gap: 8px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                Chọn file từ Google Drive
            </h4>
            <button type="button" onclick="closeDriveModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        
        <div style="padding: 10px 15px; border-bottom: 1px solid var(--border-color); background: #f1f5f9; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button type="button" id="driveBtnBack" onclick="driveNavigateUp()" class="btn btn-secondary" style="padding: 5px 10px; font-size: 13px;" disabled>&#8592; Quay lại</button>
            <span id="driveCurrentPath" style="font-size: 13px; color: #475569; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;">/ Root</span>
            <span id="driveTotalCount" style="font-size: 12px; color: #64748b; font-weight: 500;"></span>
        </div>

        <!-- Toolbar: Select All / Deselect All -->
        <div style="padding: 6px 15px; border-bottom: 1px solid var(--border-color); background: #eff6ff; display: flex; gap: 10px; align-items: center; justify-content: space-between;">
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" id="driveBtnSelectAll" onclick="driveSelectAllFiles()" class="btn btn-secondary" style="padding: 4px 12px; font-size: 12px; background: #dbeafe; border: 1px solid #93c5fd; color: #1d4ed8; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                    ☑️ Chọn tất cả
                </button>
                <button type="button" id="driveBtnDeselectAll" onclick="driveDeselectAllFiles()" class="btn btn-secondary" style="padding: 4px 12px; font-size: 12px; background: #fff; border: 1px solid #e2e8f0; color: #64748b; border-radius: 4px; cursor: pointer; display: none; align-items: center; gap: 4px;">
                    ✖️ Bỏ chọn tất cả
                </button>
                <button type="button" id="driveBtnSelectFolder" onclick="driveSelectCurrentFolder()" class="btn btn-success" style="padding: 4px 12px; font-size: 12px; background: #059669; border: 1px solid #047857; color: #fff; border-radius: 4px; display: none; align-items: center; gap: 4px; cursor: pointer;">📁 Chọn thư mục này</button>
            </div>
            <span id="driveSelectionCount" style="font-size: 12px; color: #1d4ed8; font-weight: 600;">0 đã chọn</span>
        </div>

        <div id="driveFileList" style="padding: 15px; overflow-y: auto; flex-grow: 1; min-height: 200px;">
            <div style="text-align: center; color: #64748b; padding: 20px;">Đang tải danh sách...</div>
        </div>
        <div style="padding: 15px; border-top: 1px solid var(--border-color); background: #f8fafc; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div>
                <button type="button" id="driveBtnSelectFolderBottom" onclick="driveSelectCurrentFolder()" class="btn btn-success" style="display: none; align-items: center; gap: 6px; background: #10b981; border: 1px solid #059669; color: #fff; border-radius: 6px; padding: 8px 16px; font-weight: 600; cursor: pointer; transition: background 0.2s; font-size: 13px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    📁 Chọn cả thư mục: <span id="driveSelectedFolderNameBottom" style="font-weight: 700; text-decoration: underline;"></span>
                </button>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button type="button" class="btn btn-secondary" onclick="closeDriveModal()" style="background: #fff; border: 1px solid var(--border-color); color: #334155; padding: 8px 16px; border-radius: 6px; font-weight: 500; font-size: 13px;">Thoát</button>
                <button type="button" id="btnConfirmDriveSelection" class="btn btn-primary" onclick="confirmDriveSelection()" style="padding: 8px 16px; border-radius: 6px; font-weight: 500; font-size: 13px;" disabled>Xác nhận chọn (0)</button>
            </div>
        </div>
    </div>
</div>

<style>
.drive-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    transition: background 0.2s;
    border-radius: 4px;
}
.drive-item:hover {
    background: #f1f5f9;
}
.drive-item.drive-selected {
    background: #e0f2fe !important;
}
.drive-icon {
    width: 32px;
    height: 32px;
    margin-right: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}
.drive-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.drive-info {
    flex-grow: 1;
    overflow: hidden;
}
.drive-name {
    font-size: 14px;
    font-weight: 500;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.drive-size {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}
.drive-check-icon {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    margin-left: 8px;
    display: none;
    color: #2563eb;
}
.drive-item.drive-selected .drive-check-icon {
    display: block;
}
</style>

<script>
let currentFolderId = 'root';
let folderHistory = [];
let folderPathNames = ['Root'];
let driveSelectedFiles = [];
let currentDriveFiles = []; // Lưu danh sách file (không phải folder) của thư mục hiện tại

function formatBytes(bytes, decimals = 2) {
    if (!+bytes) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
}

let driveUserId = '';

function openDriveModal(mode) {
    const userSelect = document.getElementById('user_select');
    driveUserId = userSelect ? userSelect.value : '';

    driveSelectedFiles = [];
    updateDriveSelectionUI();
    document.getElementById('driveModal').style.display = 'flex';
    
    // Always force reload from root so we show the correct files/folders for this user's connection
    currentFolderId = 'root';
    folderHistory = [];
    folderPathNames = ['Root'];
    updateDriveBreadcrumb();
    loadDriveFiles('root', 'Root');
}

function closeDriveModal() {
    document.getElementById('driveModal').style.display = 'none';
}

function updateDriveBreadcrumb() {
    document.getElementById('driveCurrentPath').innerText = '/ ' + folderPathNames.join(' / ');
    document.getElementById('driveBtnBack').disabled = folderHistory.length === 0;
}

function loadDriveFiles(folderId, folderName) {
    const listContainer = document.getElementById('driveFileList');
    listContainer.innerHTML = '<div style="text-align: center; color: #64748b; padding: 20px;">Đang tải danh sách...</div>';
    currentDriveFiles = []; // Reset
    document.getElementById('driveTotalCount').textContent = '';
    
    // Toggle Select Folder button visibility (only show when not in root)
    const btnSelectFolder = document.getElementById('driveBtnSelectFolder');
    if (btnSelectFolder) {
        btnSelectFolder.style.display = (folderId === 'root') ? 'none' : 'flex';
    }
    
    const btnSelectFolderBottom = document.getElementById('driveBtnSelectFolderBottom');
    const folderNameSpan = document.getElementById('driveSelectedFolderNameBottom');
    if (btnSelectFolderBottom) {
        if (folderId === 'root') {
            btnSelectFolderBottom.style.display = 'none';
        } else {
            btnSelectFolderBottom.style.display = 'flex';
            if (folderNameSpan) {
                folderNameSpan.textContent = folderName;
            }
        }
    }
    
    fetch(`actions/drive_proxy.php?action=list_files&parent_id=${folderId}&user_id=${driveUserId}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                listContainer.innerHTML = '';
                
                // Hiển thị tổng số file
                const totalCount = data.total || data.files.length;
                const fileCount = data.files.filter(f => f.mimeType !== 'application/vnd.google-apps.folder').length;
                const folderCount = data.files.filter(f => f.mimeType === 'application/vnd.google-apps.folder').length;
                
                let countText = `📁 ${folderCount} thư mục · 📄 ${fileCount} file`;
                document.getElementById('driveTotalCount').textContent = countText;
                
                if (data.files.length === 0) {
                    listContainer.innerHTML = '<div style="text-align: center; color: #64748b; padding: 20px;">Thư mục trống.</div>';
                    return;
                }
                
                // Lưu danh sách file (không bao gồm folder)
                currentDriveFiles = data.files.filter(f => f.mimeType !== 'application/vnd.google-apps.folder');
                
                data.files.forEach(file => {
                    const isFolder = file.mimeType === 'application/vnd.google-apps.folder';
                    
                    let iconHtml = '';
                    if (isFolder) {
                        iconHtml = '<svg width="20" height="20" viewBox="0 0 24 24" fill="#fbbf24" stroke="#d97706" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>';
                    } else if (file.thumbnailLink) {
                        iconHtml = `<img src="${file.thumbnailLink}" alt="thumb">`;
                    } else if (file.mimeType.includes('video/')) {
                        iconHtml = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect><line x1="7" y1="2" x2="7" y2="22"></line><line x1="17" y1="2" x2="17" y2="22"></line><line x1="2" y1="12" x2="22" y2="12"></line><line x1="2" y1="7" x2="7" y2="7"></line><line x1="2" y1="17" x2="7" y2="17"></line><line x1="17" y1="17" x2="22" y2="17"></line><line x1="17" y1="7" x2="22" y2="7"></line></svg>';
                    } else {
                        iconHtml = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>';
                    }

                    const div = document.createElement('div');
                    div.className = 'drive-item';
                    div.dataset.fileId = file.id;
                    
                    if (!isFolder && driveSelectedFiles.some(f => f.id === file.id)) {
                        div.classList.add('drive-selected');
                    }
                    
                    div.onclick = function(e) {
                        if (e.target.closest('.select-folder-row-btn')) {
                            e.stopPropagation();
                            if (typeof onDriveFolderSelected === 'function') {
                                onDriveFolderSelected(file.id, file.name);
                            }
                            closeDriveModal();
                            return;
                        }
                        if (isFolder) {
                            folderHistory.push(currentFolderId);
                            folderPathNames.push(file.name);
                            currentFolderId = file.id;
                            updateDriveBreadcrumb();
                            loadDriveFiles(file.id, file.name);
                        } else {
                            toggleDriveFileSelection(file, div);
                        }
                    };

                    div.innerHTML = `
                        <div class="drive-icon">${iconHtml}</div>
                        <div class="drive-info">
                            <div class="drive-name">${file.name}</div>
                            <div class="drive-size">${isFolder ? 'Thư mục' : formatBytes(file.size)}</div>
                        </div>
                        ${isFolder ? '<button type="button" class="select-folder-row-btn btn btn-success" style="padding: 4px 8px; font-size: 11px; background: #10b981; border: 1px solid #059669; color: #fff; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 3px; margin-left: 8px; font-weight: bold; flex-shrink: 0; box-shadow: 0 1px 2px rgba(0,0,0,0.05); z-index: 10;">📁 Chọn</button>' : ''}
                        ${!isFolder ? '<svg class="drive-check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                    `;
                    listContainer.appendChild(div);
                });
                
                // Cập nhật trạng thái nút select/deselect
                updateSelectAllButtons();
                
            } else {
                listContainer.innerHTML = `<div style="text-align: center; color: red; padding: 20px;">${data.msg}</div>`;
            }
        })
        .catch(err => {
            listContainer.innerHTML = '<div style="text-align: center; color: red; padding: 20px;">Lỗi kết nối.</div>';
        });
}

function driveNavigateUp() {
    if (folderHistory.length > 0) {
        currentFolderId = folderHistory.pop();
        folderPathNames.pop();
        updateDriveBreadcrumb();
        loadDriveFiles(currentFolderId, folderPathNames[folderPathNames.length - 1]);
    }
}

function toggleDriveFileSelection(file, element) {
    const idx = driveSelectedFiles.findIndex(f => f.id === file.id);
    if (idx > -1) {
        driveSelectedFiles.splice(idx, 1);
        element.classList.remove('drive-selected');
    } else {
        driveSelectedFiles.push({id: file.id, name: file.name});
        element.classList.add('drive-selected');
    }
    updateDriveSelectionUI();
    updateSelectAllButtons();
}

function driveSelectAllFiles() {
    // Chọn tất cả file (không phải folder) trong thư mục hiện tại
    currentDriveFiles.forEach(file => {
        if (!driveSelectedFiles.some(f => f.id === file.id)) {
            driveSelectedFiles.push({id: file.id, name: file.name});
        }
    });
    
    // Cập nhật UI cho các item đang hiển thị
    document.querySelectorAll('#driveFileList .drive-item').forEach(div => {
        const fileId = div.dataset.fileId;
        if (fileId && currentDriveFiles.some(f => f.id === fileId)) {
            div.classList.add('drive-selected');
        }
    });
    
    updateDriveSelectionUI();
    updateSelectAllButtons();
}

function driveDeselectAllFiles() {
    // Bỏ chọn tất cả file trong thư mục hiện tại
    currentDriveFiles.forEach(file => {
        const idx = driveSelectedFiles.findIndex(f => f.id === file.id);
        if (idx > -1) {
            driveSelectedFiles.splice(idx, 1);
        }
    });
    
    // Cập nhật UI
    document.querySelectorAll('#driveFileList .drive-item').forEach(div => {
        div.classList.remove('drive-selected');
    });
    
    updateDriveSelectionUI();
    updateSelectAllButtons();
}

function updateSelectAllButtons() {
    const btnSelectAll = document.getElementById('driveBtnSelectAll');
    const btnDeselectAll = document.getElementById('driveBtnDeselectAll');
    
    if (currentDriveFiles.length === 0) {
        btnSelectAll.style.display = 'none';
        btnDeselectAll.style.display = 'none';
        return;
    }
    
    // Kiểm tra xem tất cả file trong thư mục hiện tại đã được chọn chưa
    const allSelected = currentDriveFiles.every(f => driveSelectedFiles.some(sf => sf.id === f.id));
    
    if (allSelected) {
        btnSelectAll.style.display = 'none';
        btnDeselectAll.style.display = 'flex';
    } else {
        btnSelectAll.style.display = 'flex';
        btnDeselectAll.style.display = currentDriveFiles.some(f => driveSelectedFiles.some(sf => sf.id === f.id)) ? 'flex' : 'none';
    }
}

function updateDriveSelectionUI() {
    const btn = document.getElementById('btnConfirmDriveSelection');
    const countSpan = document.getElementById('driveSelectionCount');
    const n = driveSelectedFiles.length;
    
    if (btn) {
        btn.innerText = `Xác nhận chọn (${n})`;
        btn.disabled = n === 0;
    }
    if (countSpan) {
        countSpan.textContent = n > 0 ? `${n} đã chọn` : '0 đã chọn';
        countSpan.style.color = n > 0 ? '#1d4ed8' : '#64748b';
    }
}

function confirmDriveSelection() {
    if (driveSelectedFiles.length === 0) return;
    
    // Support backward compatibility for components expecting 1 file
    if (typeof onDriveFilesSelected === 'function') {
        onDriveFilesSelected(driveSelectedFiles);
    } else if (typeof onDriveFileSelected === 'function') {
        onDriveFileSelected(driveSelectedFiles[0].id, driveSelectedFiles[0].name);
    }
    closeDriveModal();
}

function driveSelectCurrentFolder() {
    if (currentFolderId === 'root') {
        return; // Don't allow root folder selection
    }
    const folderName = folderPathNames[folderPathNames.length - 1];
    if (typeof onDriveFolderSelected === 'function') {
        onDriveFolderSelected(currentFolderId, folderName);
    }
    closeDriveModal();
}
</script>
