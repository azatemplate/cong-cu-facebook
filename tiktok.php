<?php
// tiktok.php
$current_page = 'tiktok';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/tiktok_api.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Read upload limits
$disable_local_upload = false;
try {
    $stmt_upload = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'disable_local_upload'");
    $stmt_upload->execute();
    $row_upload = $stmt_upload->fetch(PDO::FETCH_ASSOC);
    if ($row_upload && $row_upload['setting_value'] === '1' && !$is_admin) {
        $disable_local_upload = true;
    }
} catch (Exception $e) {}

// Fetch TikTok Accounts for current user
$stmt_tt = $pdo->prepare("SELECT * FROM tiktok_accounts WHERE account_id = ? AND is_active = 1 ORDER BY created_at DESC");
$stmt_tt->execute([$account_id]);
$stmt_lim = $pdo->prepare("SELECT max_tiktok_accounts FROM system_accounts WHERE id = ?");
$stmt_lim->execute([$account_id]);
$max_tiktok_accounts = intval($stmt_lim->fetchColumn() ?: 10);
$max_tt_display = $is_admin ? 'Không giới hạn' : number_format($max_tiktok_accounts);

// Check if credentials exist
$cfg = get_tiktok_client_config();
$has_credentials = !empty($cfg['client_key']) && !empty($cfg['client_secret']);
?>

<style>
.tiktok-hero-card {
    background: linear-gradient(135deg, #010101 0%, #1a0533 40%, #fe2c55 100%);
    border-radius: 12px; padding: 20px 24px; color: #fff; margin-bottom: 20px;
    display: flex; justify-content: space-between; align-items: center;
}
</style>

<!-- Header Hero Card -->
<div class="tiktok-hero-card">
    <div>
        <h2 style="margin: 0 0 6px; font-size: 20px; font-weight: 700;">🎵 Đăng & Lên Lịch Video TikTok Direct Post</h2>
        <p style="margin: 0; font-size: 13px; opacity: 0.85;">
            Quản lý kênh TikTok, tải video hàng loạt không watermark, lên lịch phát sóng tự động rải đều theo khung giờ.
        </p>
    </div>
    <div>
        <?php if ($has_credentials): ?>
            <a href="tiktok_login.php" class="btn" style="background: #fe2c55; color: #fff; font-weight: 600; padding: 10px 18px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(254,44,85,0.4);">
                <span>➕</span> Kết Nối Tài Khoản TikTok
            </a>
        <?php else: ?>
            <a href="settings.php" class="btn" style="background: #eab308; color: #000; font-weight: 600; padding: 10px 18px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                <span>⚙️</span> Cấu hình Client Key TikTok
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_SESSION['flash_msg'])): ?>
    <div class="alert alert-info" style="margin-bottom: 20px;">
        <?php echo htmlspecialchars($_SESSION['flash_msg']); unset($_SESSION['flash_msg']); ?>
    </div>
<?php endif; ?>

<!-- Connected TikTok Channels Manager -->
<?php if (!empty($tiktok_accounts)): ?>
<div class="card" style="margin-bottom: 20px; border-left: 4px solid #fe2c55;">
    <h4 style="margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #fe2c55;">📋 Danh Sách Kênh TikTok Đã Kết Nối (<?php echo count($tiktok_accounts); ?> / <?php echo $max_tt_display; ?>)</h4>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
        <?php foreach ($tiktok_accounts as $tt): ?>
            <div id="tt-acc-card-<?php echo $tt['id']; ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                    <img src="<?php echo !empty($tt['avatar']) ? htmlspecialchars($tt['avatar']) : 'assets/images/default_avatar.png'; ?>" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid #cbd5e1;" onerror="this.src='assets/images/default_avatar.png'">
                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <div style="font-weight: 600; font-size: 14px; color: #1e293b; text-overflow: ellipsis; overflow: hidden;"><?php echo htmlspecialchars($tt['display_name']); ?></div>
                        <div style="font-size: 11px; color: #64748b;">OpenID: <?php echo htmlspecialchars(substr($tt['open_id'], 0, 10)); ?>...</div>
                    </div>
                </div>
                <button type="button" onclick="deleteTikTokAccount(<?php echo $tt['id']; ?>, '<?php echo htmlspecialchars(addslashes($tt['display_name'])); ?>')" class="btn btn-sm" style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; padding: 5px 10px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;">
                    🗑️ Xóa / Hủy
                </button>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-bottom: 15px;">Đăng & Lên Lịch TikTok</h3>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
        Chọn Kênh TikTok, nhập tiêu đề, chọn tệp từ máy hoặc Google Drive để bắt đầu đăng ngay hoặc lên lịch rải đều.
    </p>

    <form id="tiktokPublishForm" enctype="multipart/form-data">
        <!-- 1. Select TikTok Accounts (Multi-Select) -->
        <style>
        .tt-wrapper { border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: var(--card-bg, #fff); margin-bottom: 20px; }
        .tt-search-bar { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--border-color); background: #f8fafc; }
        .tt-search-bar svg { flex-shrink: 0; color: #94a3b8; }
        .tt-search-bar input { flex: 1; border: none; background: transparent; outline: none; font-size: 13px; color: var(--text-main, #1e293b); }
        .tt-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid var(--border-color); background: #f1f5f9; font-size: 12px; color: var(--text-muted, #64748b); }
        .tt-toolbar label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 500; }
        .tt-toolbar input[type=checkbox] { width: 15px; height: 15px; cursor: pointer; accent-color: #fe2c55; }
        #tt-count { font-size: 12px; color: var(--text-muted, #64748b); }
        .tt-list { max-height: 220px; overflow-y: auto; padding: 4px 0; }
        .tt-item { display: flex; align-items: center; gap: 10px; padding: 7px 12px; cursor: pointer; transition: background 0.12s; font-size: 13px; color: var(--text-main, #1e293b); }
        .tt-item:hover { background: #fff1f2; }
        .tt-item.tt-checked { background: #ffe4e6; }
        .tt-item input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; accent-color: #fe2c55; cursor: pointer; }
        .tt-item label { cursor: pointer; flex: 1; line-height: 1.35; display:flex; align-items:center; gap:8px; }
        .tt-empty { text-align: center; padding: 24px; color: #94a3b8; font-size: 13px; display: none; }
        </style>

        <div class="form-group">
            <label style="font-weight: 600; color: #0f172a;">1. Chọn Kênh TikTok Đăng Bài (Chọn 1 hoặc nhiều kênh)</label>
            <div class="tt-wrapper" id="tt-wrapper">
                <div class="tt-search-bar">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="tt-search" placeholder="Tìm kiếm kênh TikTok..." autocomplete="off">
                    <button type="button" id="tt-clear-search" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:0;display:none;">✕</button>
                </div>
                <div class="tt-toolbar">
                    <label>
                        <input type="checkbox" id="tt-select-all"> Chọn tất cả
                    </label>
                    <span id="tt-count">0 đã chọn</span>
                </div>
                <div class="tt-list" id="tt-list">
                    <div class="tt-empty" id="tt-empty">Không tìm thấy kênh TikTok nào</div>
                </div>
            </div>
            <div id="tt-hidden-inputs"></div>
            <?php if (empty($tiktok_accounts)): ?>
                <small style="color: #ef4444; margin-top: 4px; display: block;">
                    ⚠️ Bạn chưa kết nối kênh TikTok nào. Vui lòng bấm nút <b>"Kết Nối Tài Khoản TikTok"</b> ở trên để ủy quyền.
                </small>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            const ALL_TT_ACCOUNTS = <?php echo json_encode($tiktok_accounts); ?>;
            let checkedIds = new Set();
            
            const listEl = document.getElementById('tt-list');
            const emptyEl = document.getElementById('tt-empty');
            const searchEl = document.getElementById('tt-search');
            const clearBtn = document.getElementById('tt-clear-search');
            const selectAllEl = document.getElementById('tt-select-all');
            const countEl = document.getElementById('tt-count');
            const hiddenEl = document.getElementById('tt-hidden-inputs');
            
            function renderTT(query) {
                const q = query.trim().toLowerCase();
                const visible = ALL_TT_ACCOUNTS.filter(c => !q || c.display_name.toLowerCase().includes(q) || c.open_id.toLowerCase().includes(q));
                
                listEl.querySelectorAll('.tt-item').forEach(el => el.remove());
                
                if (visible.length === 0) {
                    emptyEl.style.display = 'block';
                } else {
                    emptyEl.style.display = 'none';
                    visible.forEach(c => {
                        const id = 'tt-cb-' + c.id;
                        const div = document.createElement('div');
                        div.className = 'tt-item' + (checkedIds.has(c.id) ? ' tt-checked' : '');
                        div.dataset.id = c.id;
                        
                        let avatarHtml = `<img src="${c.avatar ? escHtml(c.avatar) : 'assets/images/default_avatar.png'}" style="width:26px;height:26px;border-radius:50%;object-fit:cover;" onerror="this.src='assets/images/default_avatar.png'">`;
                        
                        div.innerHTML = `<input type="checkbox" id="${id}" value="${c.id}"${checkedIds.has(c.id) ? ' checked' : ''}>
                                         <label for="${id}">${avatarHtml} <strong>${escHtml(c.display_name)}</strong> <small style="color:var(--text-muted);">(OpenID: ${escHtml(c.open_id.substring(0, 10))}...)</small></label>`;
                        
                        div.querySelector('input').addEventListener('change', function() {
                            if (this.checked) { checkedIds.add(c.id); div.classList.add('tt-checked'); }
                            else { checkedIds.delete(c.id); div.classList.remove('tt-checked'); }
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
                countEl.style.color = n > 0 ? '#fe2c55' : '';
                countEl.style.fontWeight = n > 0 ? '600' : '';
            }
            
            function updateHidden() {
                hiddenEl.innerHTML = '';
                checkedIds.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'tiktok_account_ids[]';
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
                const visible = ALL_TT_ACCOUNTS.filter(c => !q || c.display_name.toLowerCase().includes(q) || c.open_id.toLowerCase().includes(q));
                if (this.checked) visible.forEach(c => checkedIds.add(c.id));
                else visible.forEach(c => checkedIds.delete(c.id));
                renderTT(q);
            });
            
            searchEl.addEventListener('input', function() {
                clearBtn.style.display = this.value ? 'block' : 'none';
                renderTT(this.value);
            });
            
            clearBtn.addEventListener('click', function() {
                searchEl.value = '';
                this.style.display = 'none';
                renderTT('');
                searchEl.focus();
            });
            
            window.tiktokSelectorValidate = function() {
                if (checkedIds.size === 0) {
                    alert('Vui lòng chọn ít nhất 1 Kênh TikTok đăng bài!');
                    return false;
                }
                return true;
            };
            
            renderTT('');
        })();
        </script>

        <!-- Auto Title Checkbox -->
        <div class="form-group" style="background: #fdf2f8; padding: 15px; border-radius: 6px; border: 1px dashed #fbcfe8; margin-bottom: 20px;">
            <label style="color: #be185d; font-weight: 500;">
                <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="margin-right: 5px;">
                Tự động dùng Tên File / Tiêu đề TikTok làm Tiêu đề và Mô tả
            </label>
            <p style="font-size: 13px; color: #9d174d; margin-top: 5px; margin-bottom: 0;">
                (Nếu chọn, hệ thống sẽ ưu tiên tên tệp hoặc tiêu đề video gốc từ TikTok/Drive làm nội dung đăng bài)
            </p>
        </div>

        <!-- 2. Video Title / Caption -->
        <div class="form-group" style="position: relative;">
            <label style="display: flex; align-items: center; gap: 8px;">2. Mô tả TikTok chung (Tùy chọn)
                <button type="button" id="emojiTriggerTikTok" class="emoji-picker-trigger">😀 Emoji</button>
            </label>
            <div id="emojiPopupTikTok" class="emoji-picker-popup">
                <div class="emoji-tabs"></div>
                <div class="emoji-search-box"><input type="text" class="emoji-search-input" placeholder="Tìm emoji..."></div>
                <div class="emoji-grid-wrap"></div>
            </div>
            <textarea id="title" name="title" rows="3" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" placeholder="Nhập tiêu đề / nội dung video TikTok..."></textarea>
            <small style="color: #64748b;">💡 Hỗ trợ Spin text: <code>{Tiêu đề 1|Tiêu đề 2|Tiêu đề 3}</code> — hệ thống sẽ ngẫu nhiên chọn 1 dòng cho mỗi video.</small>
        </div>

        <!-- Bulk TikTok URLs -->
        <div class="form-group" style="background: #fdf2f8; padding: 15px; border-radius: 6px; border: 1px dashed #fbcfe8; margin-bottom: 20px;">
            <label style="color: #be185d; font-weight: 500;">Tùy chọn tải video TikTok Hàng Loạt (Không logo)</label>
            <p style="font-size: 13px; color: #9d174d; margin-top: 5px; margin-bottom: 10px;">
                Dán nhiều link TikTok vào đây (Mỗi link 1 dòng) để hệ thống tự động tải video và lấy Tiêu đề gốc của TikTok.
            </p>
            <textarea id="tiktok_urls" name="tiktok_urls" rows="3" placeholder="VD:&#10;https://www.tiktok.com/@user/video/123...&#10;https://www.tiktok.com/@user/video/456..." style="width: 100%; padding: 10px; border: 1px solid #f9a8d4; border-radius: 6px;"></textarea>
        </div>

        <!-- 3. Video Source Selection -->
        <div class="form-group">
            <label>3. Tải lên Video TikTok <?php echo $disable_local_upload ? '(Drive / TikTok)' : 'từ máy (hoặc Drive)'; ?></label>
            <?php if ($disable_local_upload): ?>
                <div style="padding: 10px 15px; background: #fef3cd; border: 1px solid #ffc107; border-radius: 6px; font-size: 13px; color: #856404; margin-bottom: 10px;">
                    🔒 Admin đã tắt tính năng tải tệp từ máy tính. Vui lòng chọn tệp từ Google Drive hoặc link TikTok.
                </div>
            <?php endif; ?>
            <div style="display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 10px; border: 1px dashed var(--border-color); border-radius: 6px;">
                <?php if (!$disable_local_upload): ?>
                    <input type="file" id="video" name="video[]" multiple accept="video/mp4,video/x-m4v,video/*" style="width: 100%; max-width: 250px; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;" onchange="clearDriveSelection()">
                    <div style="font-weight: bold; color: #64748b;">HOẶC</div>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="openDriveModal()" style="background: #fff; border: 1px solid #cbd5e1; color: #334155; display: flex; align-items: center; gap: 5px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                    </svg>
                    Chọn từ Google Drive
                </button>
            </div>
            <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 10px 15px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 6px; font-size: 13px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; color: #0369a1; font-weight: bold;">
                    <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg> Đã chọn từ Google Drive:</span>
                    <button type="button" onclick="clearDriveSelection()" style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12px;">Xoá chọn</button>
                </div>
                <div id="driveSelectedName" style="color: #0c4a6e; font-weight: 500;"></div>
            </div>
            <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
            <input type="hidden" id="drive_file_names" name="drive_file_names" value="">
        </div>

        <!-- 4. Bulk Scheduling Section (Giống Reels.php) -->
        <div class="form-group" style="background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid var(--border-color); margin-bottom: 20px;">
            <label style="color: var(--primary-color); font-weight: 600;">4. Lên lịch tự động hàng loạt (Tùy chọn)</label>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px; margin-bottom: 15px;">
                Chọn khoảng ngày và các khung giờ, tối đa hẹn giờ 3 tháng một chiến dịch.
            </p>
            <div style="display: flex; gap: 15px; margin-bottom: 10px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 180px;">
                    <label style="font-size: 13px; font-weight: 500;">Từ ngày:</label>
                    <input type="date" id="start_date" name="start_date" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;">
                </div>
                <div style="flex: 1; min-width: 180px;">
                    <label style="font-size: 13px; font-weight: 500;">Đến ngày:</label>
                    <input type="date" id="end_date" name="end_date" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;">
                </div>
            </div>
            <div>
                <label style="font-size: 13px; font-weight: 500;">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff;">
            </div>
            <div style="margin-top: 12px; font-size: 12px; color: #0369a1;">
                * Ghi chú: Nếu hệ thống tính toán ra cùng lịch cho nhiều video, chúng sẽ được xếp rải đều theo danh sách khung giờ.
            </div>
        </div>

        <!-- 5. TikTok Direct Post Options -->
        <div class="form-group" style="background: #faf5ff; padding: 15px; border-radius: 6px; border: 1px solid #e9d5ff; margin-bottom: 20px;">
            <label style="color: #7e22ce; font-weight: 600; margin-bottom: 10px; display: block;">5. Cấu hình Quyền riêng tư & Tương tác TikTok Direct Post</label>
            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 12px;">
                <div style="flex: 1; min-width: 200px;">
                    <label style="font-size: 13px; font-weight: 500;">Quyền xem Video:</label>
                    <select name="privacy_level" style="width: 100%; padding: 8px; border: 1px solid #c084fc; border-radius: 6px; background: #fff;">
                        <option value="PUBLIC_TO_EVERYONE">🌐 Công khai (PUBLIC_TO_EVERYONE)</option>
                        <option value="MUTUAL_FOLLOW_FRIENDS">👥 Bạn bè tương tác (MUTUAL_FOLLOW_FRIENDS)</option>
                        <option value="SELF_ONLY">🔒 Chỉ mình tôi (SELF_ONLY)</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: #6b21a8;">
                <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <input type="checkbox" name="allow_comment" value="1" checked style="accent-color: #9333ea;"> Cho phép bình luận
                </label>
                <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <input type="checkbox" name="allow_duet" value="1" checked style="accent-color: #9333ea;"> Cho phép Duet
                </label>
                <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <input type="checkbox" name="allow_stitch" value="1" checked style="accent-color: #9333ea;"> Cho phép Stitch
                </label>
                <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <input type="checkbox" name="auto_add_music" value="1" checked style="accent-color: #9333ea;"> Tự động thêm nhạc nền TikTok
                </label>
            </div>
        </div>

        <div id="tiktokResult" style="display: none; margin-top: 15px; padding: 12px; border-radius: 6px;"></div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button id="btnSubmitTikTok" class="btn btn-primary" type="submit" style="background: #fe2c55; border: none; font-weight: 600; padding: 12px 28px; box-shadow: 0 4px 14px rgba(254,44,85,0.4); font-size: 15px;">
                🚀 Đăng & Lên Lịch Video TikTok
            </button>
        </div>
    </form>
</div>

<script>
    const tiktokForm = document.getElementById('tiktokPublishForm');
    const btnSubmitTT = document.getElementById('btnSubmitTikTok');
    const tiktokResult = document.getElementById('tiktokResult');

    tiktokForm.addEventListener('submit', function (e) {
        e.preventDefault();

        if (typeof window.tiktokSelectorValidate === 'function' && !window.tiktokSelectorValidate()) {
            return;
        }

        btnSubmitTT.disabled = true;
        btnSubmitTT.textContent = '⏳ Đang xử lý đăng bài / lên lịch TikTok...';
        tiktokResult.style.display = 'none';

        const formData = new FormData(tiktokForm);

        fetch('actions/publish_tiktok.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Server Raw Response:', text);
                data = { status: 'error', msg: 'Lỗi phản hồi máy chủ: ' + text.substring(0, 300) };
            }

            tiktokResult.style.display = 'block';
            if (data.status === 'success') {
                tiktokResult.className = 'alert alert-success';
                tiktokResult.style.background = '#dcfce7';
                tiktokResult.style.color = '#15803d';
                tiktokResult.style.border = '1px solid #86efac';
                tiktokResult.innerHTML = data.msg + ' (Đang chuyển sang trang Quản Lý Bài Đăng...)';
                setTimeout(function() {
                    window.location.href = data.redirect || 'manage_posts.php';
                }, 800);
            } else {
                tiktokResult.className = 'alert alert-danger';
                tiktokResult.style.background = '#fee2e2';
                tiktokResult.style.color = '#991b1b';
                tiktokResult.style.border = '1px solid #fca5a5';
                tiktokResult.innerHTML = data.msg;
                btnSubmitTT.disabled = false;
                btnSubmitTT.textContent = '🚀 Đăng & Lên Lịch Video TikTok';
            }
        })
        .catch(err => {
            tiktokResult.style.display = 'block';
            tiktokResult.className = 'alert alert-danger';
            tiktokResult.innerHTML = 'Lỗi kết nối máy chủ: ' + err.message;
            btnSubmitTT.disabled = false;
            btnSubmitTT.textContent = '🚀 Đăng & Lên Lịch Video TikTok';
        });
    });

    function onDriveFilesSelected(files) {
        if (!files || files.length === 0) return;
        const fileIds = files.map(f => f.id).join(',');
        const fileNames = files.map(f => f.name).join(', ');

        document.getElementById('drive_file_id').value = fileIds;
        if (document.getElementById('drive_file_names')) {
            document.getElementById('drive_file_names').value = fileNames;
        }

        const videoEl = document.getElementById('video');
        if (videoEl) videoEl.value = '';

        document.getElementById('driveSelectedName').innerText = fileNames;
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }

    function onDriveFileSelected(fileId, fileName) {
        document.getElementById('drive_file_id').value = fileId;
        if (document.getElementById('drive_file_names')) {
            document.getElementById('drive_file_names').value = fileName;
        }
        const videoEl = document.getElementById('video');
        if (videoEl) videoEl.value = '';

        document.getElementById('driveSelectedName').innerText = fileName;
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }

    function onDriveFolderSelected(folderId, folderName) {
        document.getElementById('drive_file_id').value = 'folder:' + folderId;
        if (document.getElementById('drive_file_names')) {
            document.getElementById('drive_file_names').value = 'folder:' + folderName;
        }
        const videoEl = document.getElementById('video');
        if (videoEl) videoEl.value = '';

        document.getElementById('driveSelectedName').innerText = '📁 Thư mục: ' + folderName;
        document.getElementById('driveSelectionInfo').style.display = 'block';
    }

    function clearDriveSelection() {
        document.getElementById('drive_file_id').value = '';
        if (document.getElementById('drive_file_names')) {
            document.getElementById('drive_file_names').value = '';
        }
        document.getElementById('driveSelectionInfo').style.display = 'none';
    }

    function deleteTikTokAccount(id, name) {
        if (!confirm('Bạn có chắc chắn muốn xóa kênh TikTok "' + name + '" khỏi hệ thống để kết nối lại từ đầu không?')) {
            return;
        }

        const fd = new FormData();
        fd.append('id', id);

        fetch('actions/delete_tiktok_account.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const card = document.getElementById('tt-acc-card-' + id);
                if (card) card.remove();
                const opt = document.querySelector('#tiktok_account_id option[value="' + id + '"]');
                if (opt) opt.remove();
                alert(data.msg);
                location.reload();
            } else {
                alert(data.msg || 'Có lỗi xảy ra khi xóa kênh.');
            }
        })
        .catch(err => {
            alert('Lỗi kết nối: ' + err.message);
        });
    }

document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    if (startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            if (this.value) {
                const startDate = new Date(this.value);
                const maxDate = new Date(startDate);
                maxDate.setDate(maxDate.getDate() + 90);
                
                const maxStr = maxDate.toISOString().split('T')[0];
                endDateInput.min = this.value;
                endDateInput.max = maxStr;
                
                if (endDateInput.value && (endDateInput.value < this.value || endDateInput.value > maxStr)) {
                    endDateInput.value = maxStr;
                }
            } else {
                endDateInput.removeAttribute('min');
                endDateInput.removeAttribute('max');
            }
        });
    }
});
</script>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/emoji_picker.php'; ?>
<script>initEmojiPicker('emojiTriggerTikTok', 'emojiPopupTikTok', 'title');</script>
<?php include 'includes/footer.php'; ?>
