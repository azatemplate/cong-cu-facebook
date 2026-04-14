<?php
$current_page = 'youtube';
require_once __DIR__ . '/includes/header.php';

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

// Get all linked YouTube channels for this account
$stmt = $pdo->prepare("SELECT id, channel_id, channel_title, channel_avatar FROM youtube_channels WHERE account_id = ? ORDER BY created_at DESC");
$stmt->execute([$account_id]);
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

$channels_json = json_encode($channels);
?>

<div class="page-title">YouTube Scheduler</div>

<div class="card">
    <h3 style="margin-bottom: 15px;">Đăng Video YouTube</h3>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
        Chọn Kênh YouTube, nhập liên kết hoặc tải lên video, tuỳ chọn nhờ AI viết Title/Description/Tags tự động.
    </p>

    <form id="youtubeForm" enctype="multipart/form-data">
        <style>
        .ps-wrapper { border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: var(--card-bg, #fff); }
        .ps-search-bar { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--border-color); background: #f8fafc; }
        .ps-search-bar svg { flex-shrink: 0; color: #94a3b8; }
        .ps-search-bar input { flex: 1; border: none; background: transparent; outline: none; font-size: 13px; color: var(--text-main, #1e293b); }
        .ps-search-bar input::placeholder { color: #94a3b8; }
        .ps-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid var(--border-color); background: #f1f5f9; font-size: 12px; color: var(--text-muted, #64748b); }
        .ps-toolbar label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 500; }
        .ps-toolbar input[type=checkbox] { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary-color, #2563eb); }
        #yt-count { font-size: 12px; color: var(--text-muted, #64748b); }
        .ps-list { max-height: 220px; overflow-y: auto; padding: 4px 0; }
        .ps-item { display: flex; align-items: center; gap: 10px; padding: 7px 12px; cursor: pointer; transition: background 0.12s; font-size: 13px; color: var(--text-main, #1e293b); }
        .ps-item:hover { background: #f0f7ff; }
        .ps-item.ps-checked { background: #eff6ff; }
        .ps-item input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; accent-color: var(--primary-color, #2563eb); cursor: pointer; }
        .ps-item label { cursor: pointer; flex: 1; line-height: 1.35; display:flex; align-items:center; gap:8px; }
        .ps-empty { text-align: center; padding: 24px; color: #94a3b8; font-size: 13px; display: none; }
        </style>
        
        <div class="form-group">
            <label>1. Chọn Kênh YouTube</label>
            <div class="ps-wrapper" id="yt-wrapper">
                <div class="ps-search-bar">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="yt-search" placeholder="Tìm kiếm kênh youtube..." autocomplete="off">
                    <button type="button" id="yt-clear-search" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:0;display:none;">✕</button>
                </div>
                <div class="ps-toolbar">
                    <label>
                        <input type="checkbox" id="yt-select-all"> Chọn tất cả
                    </label>
                    <span id="yt-count">0 đã chọn</span>
                </div>
                <div class="ps-list" id="yt-list">
                    <div class="ps-empty" id="yt-empty">Không tìm thấy kênh nào</div>
                </div>
            </div>
            <!-- Hidden inputs submitted with form -->
            <div id="yt-hidden-inputs"></div>
        </div>
        
        <script>
        (function() {
            const ALL_CHANNELS = <?php echo $channels_json; ?>;
            let currentChannels = ALL_CHANNELS;
            let checkedIds = new Set();
            
            const listEl = document.getElementById('yt-list');
            const emptyEl = document.getElementById('yt-empty');
            const searchEl = document.getElementById('yt-search');
            const clearBtn = document.getElementById('yt-clear-search');
            const selectAllEl = document.getElementById('yt-select-all');
            const countEl = document.getElementById('yt-count');
            const hiddenEl = document.getElementById('yt-hidden-inputs');
            
            function render(query) {
                const q = query.trim().toLowerCase();
                const visible = currentChannels.filter(c => !q || c.channel_title.toLowerCase().includes(q) || c.channel_id.toLowerCase().includes(q));
                
                listEl.querySelectorAll('.ps-item').forEach(el => el.remove());
                
                if (visible.length === 0) {
                    emptyEl.style.display = 'block';
                } else {
                    emptyEl.style.display = 'none';
                    visible.forEach(c => {
                        const id = 'yt-cb-' + c.id;
                        const div = document.createElement('div');
                        div.className = 'ps-item' + (checkedIds.has(c.id) ? ' ps-checked' : '');
                        div.dataset.id = c.id;
                        
                        let avatarHtml = '';
                        if (c.channel_avatar) {
                            avatarHtml = `<img src="${escHtml(c.channel_avatar)}" alt="Avatar" style="width:24px;height:24px;border-radius:50%;">`;
                        }
                        
                        div.innerHTML = `<input type="checkbox" id="${id}" value="${c.id}"${checkedIds.has(c.id) ? ' checked' : ''}>
                                         <label for="${id}">${avatarHtml} <span>${escHtml(c.channel_title)}</span> <small style="color:var(--text-muted);">(${escHtml(c.channel_id)})</small></label>`;
                        
                        div.querySelector('input').addEventListener('change', function() {
                            if (this.checked) { checkedIds.add(c.id); div.classList.add('ps-checked'); }
                            else { checkedIds.delete(c.id); div.classList.remove('ps-checked'); }
                            syncSelectAll(visible);
                            updateCount();
                            updateHidden();
                        });
                        
                        div.addEventListener('click', function(e) {
                            if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL' || e.target.closest('label')) return;
                            const cb = div.querySelector('input');
                            cb.checked = !cb.checked;
                            cb.dispatchEvent(new Event('change'));
                        });
                        
                        listEl.appendChild(div);
                    });
                }
                syncSelectAll(visible);
                updateCount();
                updateHidden();
            }
            
            function syncSelectAll(visible) {
                const allChecked = visible.length > 0 && visible.every(c => checkedIds.has(c.id));
                selectAllEl.checked = allChecked;
                selectAllEl.indeterminate = !allChecked && visible.some(c => checkedIds.has(c.id));
            }
            
            function updateCount() {
                const n = checkedIds.size;
                countEl.textContent = n > 0 ? n + ' đã chọn' : '0 đã chọn';
                countEl.style.color = n > 0 ? 'var(--primary-color, #2563eb)' : '';
                countEl.style.fontWeight = n > 0 ? '600' : '';
            }
            
            function updateHidden() {
                hiddenEl.innerHTML = '';
                checkedIds.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'youtube_channel_ids[]';
                    inp.value = id;
                    hiddenEl.appendChild(inp);
                });
            }
            
            function escHtml(s) {
                if (!s) return '';
                return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            
            selectAllEl.addEventListener('change', function() {
                const q = searchEl.value.trim().toLowerCase();
                const visible = currentChannels.filter(c => !q || c.channel_title.toLowerCase().includes(q) || c.channel_id.toLowerCase().includes(q));
                if (this.checked) visible.forEach(c => checkedIds.add(c.id));
                else visible.forEach(c => checkedIds.delete(c.id));
                render(q);
            });
            
            searchEl.addEventListener('input', function() {
                clearBtn.style.display = this.value ? 'block' : 'none';
                render(this.value);
            });
            
            clearBtn.addEventListener('click', function() {
                searchEl.value = '';
                this.style.display = 'none';
                render('');
                searchEl.focus();
            });
            
            window.youtubeSelectorValidate = function() {
                if (checkedIds.size === 0) {
                    alert('Vui lòng chọn ít nhất 1 Kênh YouTube!');
                    return false;
                }
                return true;
            };
            
            render('');
        })();
        </script>
        
        <div class="form-group" style="background: #fdf2f8; padding: 15px; border-radius: 6px; border: 1px dashed #fbcfe8; margin-bottom: 20px;">
            <label style="color: #be185d; font-weight: 500;">
                <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="margin-right: 5px;"> 
                Tự động dùng Tên File / Tiêu đề TikTok làm Tiêu đề
            </label>
            <p style="font-size: 13px; color: #9d174d; margin-top: 5px; margin-bottom: 0;">(Nếu chọn, hệ thống sẽ tự sinh tên nếu bạn bỏ trống tiêu đề)</p>
        </div>
        
        <div class="form-group">
            <label>2. Tiêu đề Video chung (Tùy chọn)</label>
            <input type="text" id="title" name="title" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" placeholder="Nhập tiêu đề video...">
        </div>
        <div class="form-group">
            <label>3. Mô tả Video chung (Tùy chọn)</label>
            <textarea id="description" name="description" rows="3" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" placeholder="Nhập mô tả video..."></textarea>
        </div>
        
        <div class="form-group" style="background: #f8fafc; padding: 15px; border-radius: 6px; border: 1px dashed var(--border-color); margin-bottom: 20px;">
            <label style="color: #0f172a; font-weight: 500;">Tags Video (Tùy chọn, cách nhau bởi dấu phẩy)</label>
            <input type="text" id="tags" name="tags" placeholder="VD: tintuc, giaitri, thethao..." style="width: 100%; margin-top: 10px; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
        </div>

        <div class="form-group" style="background: #fdf2f8; padding: 15px; border-radius: 6px; border: 1px dashed #fbcfe8; margin-bottom: 20px;">
            <label style="color: #be185d; font-weight: 500;">Tùy chọn tải video TikTok (Lưu ý: YouTube có thể gỡ nếu quét bản quyền)</label>
            <textarea id="tiktok_urls" name="tiktok_urls" rows="4" placeholder="Dán nhiều link TikTok vào đây (Mỗi link 1 dòng)..." style="width: 100%; margin-top: 10px; padding: 10px; border: 1px solid #f9a8d4; border-radius: 6px;"></textarea>
        </div>
        
        <div class="form-group">
            <label>4. Tải lên Video <?php echo $disable_local_upload ? '(Drive / TikTok)' : 'từ Máy tính (hoặc Drive)'; ?></label>
            <?php if ($disable_local_upload): ?>
            <div style="padding: 10px 15px; background: #fef3cd; border: 1px solid #ffc107; border-radius: 6px; font-size: 13px; color: #856404; margin-bottom: 10px;">
                🔒 Admin đã tắt tính năng tải tệp từ máy tính. Vui lòng sử dụng Google Drive hoặc Link TikTok.
            </div>
            <?php endif; ?>
            <div style="display: flex; gap: 10px; align-items: center; background: #eff6ff; padding: 10px; border: 1px dashed var(--border-color); border-radius: 6px;">
                <?php if (!$disable_local_upload): ?>
                <input type="file" id="video" name="video[]" multiple accept="video/mp4,video/x-m4v,video/*" style="width: 100%; max-width: 250px; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;" onchange="clearDriveSelection()">
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
            <input type="hidden" id="drive_file_names" name="drive_file_names" value="">
        </div>

        <div style="display:flex; gap:14px; align-items:stretch; margin-top:4px; flex-wrap:wrap;">
            <div class="form-group" style="flex:1; min-width:300px; background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid var(--border-color); margin-bottom:0;">
                <label style="color: var(--primary-color);">5. Lên lịch tự động hàng loạt (Tùy chọn)</label>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px; margin-bottom: 15px;">
                    Chọn khoảng ngày và các khung giờ. Hệ thống sẽ trộn ngẫu nhiên tất cả các Video bạn cung cấp (từ file, link tiktok, drive) và xếp lịch rải đều. Nếu bạn không nhập lịch, tất cả sẽ được đưa vào hàng đợi xử lý ngay lập tức.
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
                    * Ghi chú: Kênh YouTube tốn Quota nặng, cẩn thận giới hạn tải lên hàng ngày.
                </div>
            </div>

            <div class="form-group" style="flex:1; min-width:300px; background:#f0fdf4; padding:15px; border-radius:6px; border:1px solid #bbf7d0; margin-bottom:0;">
                <label style="color:#15803d; font-weight:500; display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="enable_comment" id="enableComment" value="1" onchange="document.getElementById('commentBox').style.display=this.checked?'block':'none'" style="width:16px;height:16px;accent-color:#16a34a;">
                    💬 Bình luận vào video sau khi đăng (120 giây)
                </label>
                <div id="commentBox" style="display:none; margin-top:12px;">
                    <label style="font-size:13px; color:#166534;">Mỗi dòng = 1 nội dung bình luận (random 1 dòng):</label>
                    <textarea name="comment_lines" rows="6" placeholder="Bảo hành đổi trả: link&#10;Xem thêm: link" style="width:100%; margin-top:6px; padding:8px 10px; border:1px solid #86efac; border-radius:6px; font-size:13px; resize:vertical; background:#fff;"></textarea>
                    <p style="font-size:11px; color:#166534; margin-top:5px; margin-bottom:0;">⚡ Hệ thống sẽ gửi bình luận qua API.</p>
                </div>
            </div>
        </div>
        
        <div id="youtubeResult" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px;"></div>

        <div class="form-group" style="margin-top: 15px;">
            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                <input type="checkbox" name="use_ai" value="1" style="width: 18px; height: 18px;" checked>
                🤖 Tự động viết lại nội dung/tiêu đề chuẩn SEO YouTube với cấu hình JSON (Trực tiếp bằng Worker ngầm)
            </label>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button id="btnSubmit" class="btn btn-primary" type="submit">Xác nhận Đăng / Lên Lịch</button>
        </div>
    </form>
</div>

<script>
    const youtubeForm = document.getElementById('youtubeForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const youtubeResult = document.getElementById('youtubeResult');

    youtubeForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const tiktokUrls = document.getElementById('tiktok_urls').value.trim();
        const videoEl = document.getElementById('video');
        const videoFiles = videoEl ? videoEl.files.length : 0;
        const driveFileId = document.getElementById('drive_file_id').value.trim();
        
        if (typeof window.youtubeSelectorValidate === 'function' && !window.youtubeSelectorValidate()) {
            return;
        }

        if (!tiktokUrls && videoFiles === 0 && !driveFileId) {
            alert('Vui lòng cung cấp ít nhất 1 Link TikTok, HOẶC File Video, HOẶC Google Drive.');
            return;
        }

        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Đang tải lên hệ thống (Có thể mất thời gian)...';
        youtubeResult.style.display = 'none';

        const formData = new FormData(youtubeForm);

        fetch('actions/publish_youtube.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            youtubeResult.style.display = 'block';
            if (data.status === 'success') {
                youtubeResult.className = 'alert alert-success';
                youtubeResult.innerHTML = data.msg;
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1500);
                } 
                youtubeForm.reset();
                clearDriveSelection();
            } else {
                youtubeResult.className = 'alert alert-danger';
                youtubeResult.innerHTML = data.msg;
            }
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Xác nhận Đăng / Lên Lịch';
        })
        .catch(error => {
            youtubeResult.style.display = 'block';
            youtubeResult.className = 'alert alert-danger';
            youtubeResult.innerHTML = 'Lỗi mạng hoặc hệ thống.';
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Xác nhận Đăng / Lên Lịch';
        });
    });
    
    function onDriveFilesSelected(files) {
        if (files.length === 0) return;
        const fileIds = files.map(f => f.id).join(',');
        const fileNames = files.map(f => f.name).join('|||');
        
        document.getElementById('drive_file_id').value = fileIds;
        document.getElementById('drive_file_names').value = fileNames;
        if (document.getElementById('video')) {
            document.getElementById('video').value = ''; 
        }
        
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
        document.getElementById('drive_file_names').value = '';
        document.getElementById('driveSelectionInfo').style.display = 'none';
    }
</script>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/footer.php'; ?>
