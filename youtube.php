<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];
$is_admin   = (($_SESSION['role'] ?? '') === 'admin');

// Fetch system account youtube_multi_api setting and max limit
$stmt_acc = $pdo->prepare("SELECT youtube_multi_api, max_yt_channels FROM system_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$acc_info = $stmt_acc->fetch(PDO::FETCH_ASSOC);
$youtube_multi_api = (!empty($acc_info['youtube_multi_api']) || $is_admin) ? 1 : 0;
$max_yt_channels = intval($acc_info['max_yt_channels'] ?? 10);
$max_yt_display = $is_admin ? '&infin;' : number_format($max_yt_channels);

// Handle POST actions for channel API editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_api') {
    verify_csrf();
    if ($youtube_multi_api === 1) {
        $channel_id_db = intval($_POST['channel_id_db']);
        $gg_client_id = trim($_POST['gg_client_id'] ?? '');
        $gg_client_secret = trim($_POST['gg_client_secret'] ?? '');
        
        $upd_stmt = $pdo->prepare("UPDATE youtube_channels SET gg_client_id = ?, gg_client_secret = ? WHERE id = ? AND account_id = ?");
        $upd_stmt->execute([
            empty($gg_client_id) ? null : $gg_client_id,
            empty($gg_client_secret) ? null : $gg_client_secret,
            $channel_id_db,
            $account_id
        ]);
        $_SESSION['flash_msg'] = "Cập nhật cấu hình Google API của kênh thành công!";
    } else {
        $_SESSION['flash_msg'] = "Bạn không có quyền thực hiện tính năng này.";
    }
    header("Location: youtube.php?tab=channels");
    exit;
}

// Handle GET delete
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $pdo->prepare("DELETE FROM youtube_channels WHERE id = ? AND account_id = ?")->execute([$del_id, $account_id]);
    $_SESSION['flash_msg'] = "Đã xóa kênh YouTube thành công.";
    header("Location: youtube.php?tab=channels");
    exit;
}

$current_page = 'youtube';
require_once __DIR__ . '/includes/header.php';

// Đọc cấu hình giới hạn upload
$disable_local_upload = false;
try {
    $stmt_upload = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'disable_local_upload'");
    $stmt_upload->execute();
    $row_upload = $stmt_upload->fetch(PDO::FETCH_ASSOC);
    if ($row_upload && $row_upload['setting_value'] === '1' && !$is_admin) $disable_local_upload = true;
} catch (Exception $e) {}

// Get all linked YouTube channels for this account
$stmt = $pdo->prepare("SELECT * FROM youtube_channels WHERE account_id = ? ORDER BY created_at DESC");
$stmt->execute([$account_id]);
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

$channels_json = json_encode($channels);
$active_tab = $_GET['tab'] ?? 'scheduler';
?>

<div class="page-title" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
    <div>Hệ Thống YouTube</div>
    <?php if ($active_tab === 'channels'): ?>
        <?php if ($youtube_multi_api === 1): ?>
            <button onclick="openAddChannelModal()" class="btn btn-primary" style="display: flex; align-items: center; gap: 6px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-family: inherit;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
                Thêm kênh YouTube mới
            </button>
        <?php else: ?>
            <a href="youtube_login.php" class="btn btn-primary" style="display: flex; align-items: center; gap: 6px; text-decoration: none;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
                Thêm kênh YouTube mới
            </a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Navigation Tabs -->
<div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:2px solid var(--border-color); padding-bottom:12px;">
    <a href="youtube.php?tab=scheduler" style="padding:9px 18px; border-radius:8px; font-weight:600; font-size:14px; text-decoration:none; display:flex; align-items:center; gap:8px; transition:all 0.2s; <?php echo ($active_tab === 'scheduler') ? 'background:var(--primary-color); color:white;' : 'background:#f1f5f9; color:var(--text-main);'; ?>">
        🚀 Đăng Video YouTube
    </a>
    <a href="youtube.php?tab=channels" style="padding:9px 18px; border-radius:8px; font-weight:600; font-size:14px; text-decoration:none; display:flex; align-items:center; gap:8px; transition:all 0.2s; <?php echo ($active_tab === 'channels') ? 'background:var(--primary-color); color:white;' : 'background:#f1f5f9; color:var(--text-main);'; ?>">
        📺 Quản Lý Kênh YouTube (<?php echo count($channels); ?> / <?php echo $max_yt_display; ?>)
    </a>
</div>

<?php if (isset($_SESSION['flash_msg'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">
        <?php 
        echo htmlspecialchars($_SESSION['flash_msg']); 
        unset($_SESSION['flash_msg']);
        ?>
    </div>
<?php endif; ?>

<?php if ($active_tab === 'channels'): ?>
    <!-- TAB QUẢN LÝ KÊNH YOUTUBE -->
    <div class="card">
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color);">Kênh</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color);">Tên kênh</th>
                    <?php if ($youtube_multi_api === 1): ?>
                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color);">Cấu hình API</th>
                    <?php endif; ?>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color);">Thêm lúc</th>
                    <th style="padding: 12px; text-align: right; border-bottom: 1px solid var(--border-color);">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($channels) > 0): ?>
                    <?php foreach ($channels as $channel): ?>
                        <tr>
                            <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                                <?php if ($channel['channel_avatar']): ?>
                                    <img src="<?php echo htmlspecialchars($channel['channel_avatar']); ?>" alt="Avatar" style="width: 40px; height: 40px; border-radius: 50%;">
                                <?php else: ?>
                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: #ccc; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #fff;">YT</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                                <strong><?php echo htmlspecialchars($channel['channel_title']); ?></strong><br>
                                <small style="color: var(--text-muted);"><?php echo htmlspecialchars($channel['channel_id']); ?></small>
                            </td>
                            <?php if ($youtube_multi_api === 1): ?>
                                <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                                    <?php if (!empty($channel['gg_client_id'])): ?>
                                        <span class="status-tag" style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">API Riêng</span><br>
                                        <small style="color: var(--text-muted); font-size: 11px; word-break: break-all;">ID: <?php echo htmlspecialchars(substr($channel['gg_client_id'], 0, 15)); ?>...</small>
                                    <?php else: ?>
                                        <span class="status-tag" style="background: #f1f5f9; color: #475569; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">Mặc định</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td style="padding: 12px; border-bottom: 1px solid var(--border-color);">
                                <?php echo date('d/m/Y H:i', strtotime($channel['created_at'])); ?>
                            </td>
                            <td style="padding: 12px; border-bottom: 1px solid var(--border-color); text-align: right;">
                                <?php if ($youtube_multi_api === 1): ?>
                                    <button onclick="openEditApiModal(<?php echo $channel['id']; ?>, '<?php echo htmlspecialchars($channel['channel_title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($channel['gg_client_id'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($channel['gg_client_secret'] ?? '', ENT_QUOTES); ?>')" class="btn" style="background: var(--primary-color); color: white; padding: 4px 8px; font-size: 13px; margin-right: 5px; border: none; border-radius: 4px; cursor: pointer;">Sửa API</button>
                                <?php endif; ?>
                                
                                <?php if ($youtube_multi_api === 1 && !empty($channel['gg_client_id'])): ?>
                                    <a href="youtube_login.php?reauth_channel_id=<?php echo $channel['id']; ?>" class="btn" style="background: #10b981; color: white; padding: 4.5px 8px; font-size: 13px; margin-right: 5px; text-decoration: none; border-radius: 4px; display: inline-block;">Cấp quyền lại</a>
                                <?php else: ?>
                                    <a href="youtube_login.php" class="btn" style="background: #10b981; color: white; padding: 4.5px 8px; font-size: 13px; margin-right: 5px; text-decoration: none; border-radius: 4px; display: inline-block;">Cấp quyền lại</a>
                                <?php endif; ?>
                                
                                <a href="youtube.php?tab=channels&delete=<?php echo $channel['id']; ?>" class="btn btn-danger" style="padding: 4px 8px; font-size: 13px;" onclick="return confirm('Bạn có chắc chắn muốn xóa kênh này?');">Xóa kênh</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo ($youtube_multi_api === 1) ? 5 : 4; ?>" style="padding: 20px; text-align: center; color: var(--text-muted);">
                            Chưa có kênh YouTube nào được liên kết.<br>
                            <br>
                            <?php if ($youtube_multi_api === 1): ?>
                                <button onclick="openAddChannelModal()" class="btn btn-secondary">Liên kết kênh đầu tiên</button>
                            <?php else: ?>
                                <a href="youtube_login.php" class="btn btn-secondary">Liên kết kênh đầu tiên</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal Thêm Kênh YouTube mới -->
    <div id="addChannelModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:25px; border-radius:8px; width:100%; max-width:450px; position:relative; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
            <h3 style="margin-top:0;">Thêm Kênh YouTube Mới</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">
                Nếu muốn dùng dự án Google Console riêng để tăng quota, hãy nhập Client ID & Secret ở dưới. Nếu để trống, hệ thống sẽ sử dụng cấu hình mặc định.
            </p>
            <form method="GET" action="youtube_login.php">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client ID (Tùy chọn)</label>
                    <input type="text" name="gg_client_id" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
                </div>
                
                <div class="form-group" style="margin-bottom: 25px;">
                    <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client Secret (Tùy chọn)</label>
                    <input type="text" name="gg_client_secret" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
                </div>
                
                <div style="text-align: right;">
                    <button type="button" onclick="closeAddChannelModal()" class="btn" style="background:#f3f4f6; color:#374151; margin-right:10px; border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Hủy</button>
                    <button type="submit" class="btn btn-primary" style="border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Tiếp Tục Kết Nối</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Sửa API Google của Kênh -->
    <div id="editApiModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:25px; border-radius:8px; width:100%; max-width:450px; position:relative; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
            <h3 style="margin-top:0;">Sửa Cấu Hình Google API Kênh: <span id="e_channel_title_label" style="color:var(--primary-color);"></span></h3>
            <form method="POST" action="youtube.php?tab=channels">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="edit_api">
                <input type="hidden" name="channel_id_db" id="e_channel_id_db">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client ID riêng</label>
                    <input type="text" id="e_gg_client_id" name="gg_client_id" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
                </div>
                
                <div class="form-group" style="margin-bottom: 25px;">
                    <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client Secret riêng</label>
                    <input type="text" id="e_gg_client_secret" name="gg_client_secret" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
                </div>
                
                <div style="text-align: right;">
                    <button type="button" onclick="closeEditApiModal()" class="btn" style="background:#f3f4f6; color:#374151; margin-right:10px; border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Hủy</button>
                    <button type="submit" class="btn btn-primary" style="border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Lưu Thay Đổi</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddChannelModal() { document.getElementById('addChannelModal').style.display = 'flex'; }
    function closeAddChannelModal() { document.getElementById('addChannelModal').style.display = 'none'; }
    function openEditApiModal(id, title, client_id, client_secret) {
        document.getElementById('e_channel_id_db').value = id;
        document.getElementById('e_channel_title_label').textContent = title;
        document.getElementById('e_gg_client_id').value = client_id;
        document.getElementById('e_gg_client_secret').value = client_secret;
        document.getElementById('editApiModal').style.display = 'flex';
    }
    function closeEditApiModal() { document.getElementById('editApiModal').style.display = 'none'; }
    </script>
<?php else: ?>
    <!-- TAB SCHEDULER: ĐĂNG VIDEO YOUTUBE -->

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
            <p style="font-size: 12px; color: #ef4444; font-weight: bold; margin-top: 5px; margin-bottom: 10px;">
                ⚠️ Lưu ý: Tính năng này có thể chạy lâu dài sẽ không ổn định, khuyến nghị kết nối drive
            </p>
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
            <div id="localUploadStatus" style="margin-top: 10px; display: none; padding: 8px 12px; border-radius: 4px; font-size: 13px;"></div>
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
        
        <div class="form-group" style="background: #f0fdfa; padding: 15px; border-radius: 6px; border: 1px dashed #99f6e4; margin-top: 15px;">
            <label style="color: #0d9488; font-weight: 500; display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 0;">
                <input type="checkbox" id="delete_drive_file" name="delete_drive_file" value="1" style="width: 16px; height: 16px; accent-color: #0d9488;">
                🛡️ Chống trùng và xóa file đã đăng drive
            </label>
            <p style="font-size: 12px; color: #0f766e; margin-top: 5px; margin-bottom: 0;">
                Khi chọn, nội dung đăng sẽ không trùng lặp và tự động xóa khỏi Google Drive sau khi đăng.
            </p>
        </div>

        <div id="youtubeResult" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px;"></div>

        <div class="form-group" style="margin-top: 15px;">
            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                <input type="checkbox" name="use_ai" value="1" style="width: 18px; height: 18px;">
                🤖 Tự động viết lại nội dung/tiêu đề chuẩn SEO YouTube
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
        btnSubmit.textContent = 'Đang tải lên và thực hiện...';
        youtubeResult.style.display = 'none';

        const localStatus = document.getElementById('localUploadStatus');
        if (localStatus) {
            localStatus.style.display = 'none';
            localStatus.innerText = '';
        }

        uploadLocalFilesPromise(videoEl, function(msg) {
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
                const fileNames = uploadedFiles.map(f => f.name).join('|||');
                
                document.getElementById('drive_file_id').value = fileIds;
                document.getElementById('drive_file_names').value = fileNames;
                
                if (videoEl) videoEl.value = '';
            }

            btnSubmit.textContent = 'Đang xử lý đăng bài (Có thể mất thời gian)...';
            const formData = new FormData(youtubeForm);

            return fetch('actions/publish_youtube.php', {
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
                if (localStatus) localStatus.style.display = 'none';
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
            youtubeResult.innerHTML = 'Lỗi: ' + (error.message || error || 'Lỗi mạng hoặc hệ thống.');
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

    function onDriveFolderSelected(folderId, folderName) {
        document.getElementById('drive_file_id').value = 'folder:' + folderId;
        if (document.getElementById('drive_file_names')) {
            document.getElementById('drive_file_names').value = 'folder:' + folderName;
        }
        if (document.getElementById('video')) {
            document.getElementById('video').value = ''; 
        }

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
</script>
<?php endif; ?>

<?php include 'includes/drive_browser.php'; ?>
<?php include 'includes/footer.php'; ?>