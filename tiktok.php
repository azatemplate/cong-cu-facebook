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
$tiktok_accounts = $stmt_tt->fetchAll(PDO::FETCH_ASSOC);

$stmt_lim = $pdo->prepare("SELECT max_tiktok_accounts FROM system_accounts WHERE id = ?");
$stmt_lim->execute([$account_id]);
$max_tiktok_accounts = intval($stmt_lim->fetchColumn() ?: 10);
$max_tt_display = $is_admin ? '&infin;' : number_format($max_tiktok_accounts);

// Check if credentials exist
$cfg = get_tiktok_client_config();
$has_credentials = !empty($cfg['client_key']) && !empty($cfg['client_secret']);
$active_tab = $_GET['tab'] ?? 'scheduler';
$is_tt_limit_reached = (!$is_admin && count($tiktok_accounts) >= $max_tiktok_accounts);
?>

<style>
/* Evondev Skill Styling for TikTok Manager */
.tiktok-container,
.tiktok-container button,
.tiktok-container input,
.tiktok-container select,
.tiktok-container textarea {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.tiktok-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

/* Header Banner Card */
.tiktok-header-card {
    background: linear-gradient(135deg, #09090b 0%, #18181b 40%, #000000 100%);
    border: 1px solid #27272a;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    color: #ffffff;
}

.tiktok-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 4px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.tiktok-header-info p {
    font-size: 13.5px;
    color: #a1a1aa;
    margin: 0;
}

/* Buttons */
.btn-tt-connect {
    background: linear-gradient(135deg, #fe2c55 0%, #25f4ee 100%);
    color: #ffffff;
    font-weight: 800;
    font-size: 13.5px;
    text-decoration: none;
    padding: 10px 22px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 16px rgba(254, 44, 85, 0.4);
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
}
.btn-tt-connect:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(254, 44, 85, 0.5);
    color: #ffffff;
}

/* Navigation Tab Bar */
.tiktok-nav-tabs {
    display: flex;
    gap: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 6px;
    border-radius: 14px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.tiktok-tab-btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
}
.tiktok-tab-btn:hover {
    color: #1e293b;
    background: rgba(255, 255, 255, 0.6);
}
.tiktok-tab-btn.active {
    background: #0f172a;
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.25);
}

.tiktok-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
}

.btn-tt-secondary {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 9px 18px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    text-decoration: none;
}
.btn-tt-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
}
</style>

<div class="tiktok-container">
    <!-- Header Banner Card -->
    <div class="tiktok-header-card">
        <div class="tiktok-header-info">
            <h1>
                <svg width="26" height="26" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.29 0 .58.04.85.12V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 3 15.68 6.34 6.34 0 0 0 9.35 22a6.33 6.33 0 0 0 6.33-6.33V9.05a8.16 8.16 0 0 0 4.91 1.62V7.22a4.85 4.85 0 0 1-1-.53z"/></svg>
                Hệ Thống Đăng & Lên Lịch TikTok Direct Post
            </h1>
            <p>Quản lý các Kênh TikTok, đăng video tự động, chọn tiêu đề TikTok/tên file không watermark, xếp lịch rải đều</p>
        </div>
        <div>
            <?php if ($is_tt_limit_reached): ?>
                <button onclick="alert('⚠️ Tài khoản của bạn đã đạt/vượt giới hạn tối đa <?php echo $max_tiktok_accounts; ?> Kênh TikTok. Vui lòng liên hệ Admin để nâng cấp hạn ngạch!')" class="btn-tt-connect">
                    <span>➕</span> Kết Nối Kênh TikTok Mới
                </button>
            <?php elseif ($has_credentials): ?>
                <a href="tiktok_login.php" class="btn-tt-connect">
                    <span>➕</span> Kết Nối Kênh TikTok Mới
                </a>
            <?php else: ?>
                <a href="settings.php" class="btn-tt-connect" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <span>⚙️</span> Cấu hình Client Key TikTok
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navigation Tab Pills -->
    <div class="tiktok-nav-tabs">
        <a href="tiktok.php?tab=scheduler" class="tiktok-tab-btn <?php echo ($active_tab === 'scheduler') ? 'active' : ''; ?>">
            🚀 Đăng & Lên Lịch TikTok
        </a>
        <a href="tiktok.php?tab=channels" class="tiktok-tab-btn <?php echo ($active_tab === 'channels') ? 'active' : ''; ?>">
            🎵 Quản Lý Kênh TikTok (<?php echo count($tiktok_accounts); ?> / <?php echo $max_tt_display; ?>)
        </a>
    </div>

    <?php 
    $display_tt_flash = $global_flash_msg ?: ($_SESSION['flash_msg'] ?? '');
    if (!empty($display_tt_flash)): 
        $is_warning = (strpos($display_tt_flash, '⚠️') !== false || strpos($display_tt_flash, 'đạt/vượt') !== false || strpos($display_tt_flash, 'giới hạn') !== false);
        $alert_bg = $is_warning ? '#fff7ed' : '#eff6ff';
        $alert_border = $is_warning ? '#fed7aa' : '#bfdbfe';
        $alert_color = $is_warning ? '#c2410c' : '#1e40af';
    ?>
        <div style="margin-bottom: 20px; padding:14px 18px; border-radius:12px; background:<?php echo $alert_bg; ?>; border:1px solid <?php echo $alert_border; ?>; color:<?php echo $alert_color; ?>; font-weight:700; font-size:14px; display:flex; align-items:center; gap:10px;">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><?php echo htmlspecialchars($display_tt_flash); ?></span>
        </div>
        <?php if (isset($_SESSION['flash_msg'])) unset($_SESSION['flash_msg']); ?>
    <?php endif; ?>

    <?php if ($active_tab === 'channels'): ?>
        <!-- TAB QUẢN LÝ KÊNH TIKTOK -->
        <div class="tiktok-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">📋 Danh Sách Kênh TikTok Đã Kết Nối (<?php echo count($tiktok_accounts); ?> / <?php echo $max_tt_display; ?>)</h3>
                <?php if ($is_tt_limit_reached): ?>
                    <button onclick="alert('⚠️ Tài khoản của bạn đã đạt/vượt giới hạn tối đa <?php echo $max_tiktok_accounts; ?> Kênh TikTok. Vui lòng liên hệ Admin để nâng cấp hạn ngạch!')" class="btn-tt-secondary">
                        ➕ Thêm Kênh Mới
                    </button>
                <?php elseif ($has_credentials): ?>
                    <a href="tiktok_login.php" class="btn-tt-connect" style="padding: 8px 16px; font-size: 13px;">
                        ➕ Thêm Kênh Mới
                    </a>
                <?php else: ?>
                    <a href="settings.php" class="btn-tt-secondary">
                        ⚙️ Cấu hình API
                    </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($tiktok_accounts)): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
                    <?php foreach ($tiktok_accounts as $tt): ?>
                        <div id="tt-acc-card-<?php echo $tt['id']; ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                            <div style="display: flex; align-items: center; gap: 14px; overflow: hidden;">
                                <img src="<?php echo !empty($tt['avatar']) ? htmlspecialchars($tt['avatar']) : 'assets/images/default_avatar.png'; ?>" style="width: 46px; height: 46px; border-radius: 50%; object-fit: cover; border: 2px solid #fe2c55;" onerror="this.src='assets/images/default_avatar.png'">
                                <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <div style="font-weight: 800; font-size: 15px; color: #0f172a; text-overflow: ellipsis; overflow: hidden;"><?php echo htmlspecialchars($tt['display_name']); ?></div>
                                    <div style="font-size: 12px; color: #64748b; font-weight: 600;">OpenID: <?php echo htmlspecialchars(substr($tt['open_id'], 0, 12)); ?>...</div>
                                </div>
                            </div>
                            <button type="button" class="btn-delete-tt" data-id="<?php echo (int)$tt['id']; ?>" data-name="<?php echo htmlspecialchars($tt['display_name'], ENT_QUOTES, 'UTF-8'); ?>" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 14px; font-size: 12.5px; font-weight: 800; cursor: pointer; white-space: nowrap;">
                                🗑️ Hủy
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="padding: 50px 20px; text-align: center; color: #64748b;">
                    <div style="font-size: 48px; margin-bottom: 12px;">🎵</div>
                    <h3 style="font-weight: 800; font-size: 16px; margin: 0 0 8px; color: #0f172a;">Chưa Có Kênh TikTok Nào Được Kết Nối</h3>
                    <p style="font-size: 13.5px; max-width: 440px; margin: 0 auto 20px;">Bấm nút <b>"Thêm Kênh Mới"</b> ở trên để thực hiện kết nối tài khoản TikTok của bạn.</p>
                    <?php if ($has_credentials): ?>
                        <a href="tiktok_login.php" class="btn-tt-connect">➕ Kết Nối TikTok ngay</a>
                    <?php else: ?>
                        <a href="settings.php" class="btn-tt-secondary">⚙️ Cấu hình API trước</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- TAB SCHEDULER: ĐĂNG & LÊN LỊCH TIKTOK -->
        <div class="tiktok-card">
            <h3 style="margin: 0 0 6px 0; font-weight: 800; font-size: 18px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                🚀 Tạo & Lên Lịch Bài Đăng TikTok Direct
            </h3>
            <p style="color: #64748b; font-size: 13.5px; margin-top: 0; margin-bottom: 24px;">
                Chọn các Kênh TikTok muốn đăng, nhập tiêu đề/caption, và tùy chọn phương tiện từ Máy, Google Drive hoặc Kho Data.
            </p>

            <form id="tiktokPublishForm" action="actions/publish_tiktok.php" method="POST" enctype="multipart/form-data">
                
                <!-- 1. Select TikTok Accounts (Multi-Select) -->
                <style>
                .tt-wrapper { border: 1px solid #cbd5e1; border-radius: 12px; overflow: hidden; background: #ffffff; margin-bottom: 24px; }
                .tt-search-bar { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
                .tt-search-bar svg { flex-shrink: 0; color: #94a3b8; }
                .tt-search-bar input { flex: 1; border: none; background: transparent; outline: none; font-size: 13.5px; color: #0f172a; }
                .tt-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid #e2e8f0; background: #f1f5f9; font-size: 13px; color: #475569; font-weight: 700; }
                .tt-toolbar label { display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; }
                .tt-toolbar input[type=checkbox] { width: 17px; height: 17px; cursor: pointer; accent-color: #fe2c55; }
                #tt-count { font-size: 13px; color: #fe2c55; font-weight: 700; }
                .tt-list { max-height: 230px; overflow-y: auto; padding: 6px 0; }
                .tt-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; cursor: pointer; transition: background 0.15s; font-size: 13.5px; color: #0f172a; }
                .tt-item:hover { background: #fff1f2; }
                .tt-item.tt-checked { background: #ffe4e6; }
                .tt-item input[type=checkbox] { width: 17px; height: 17px; flex-shrink: 0; accent-color: #fe2c55; cursor: pointer; }
                .tt-item label { cursor: pointer; flex: 1; line-height: 1.35; display: flex; align-items: center; gap: 10px; margin: 0; font-weight: 600; }
                .tt-empty { text-align: center; padding: 24px; color: #94a3b8; font-size: 13px; display: none; }
                </style>

                <div class="form-group" style="margin-bottom: 24px;">
                    <label style="font-weight: 700; font-size: 14px; color: #1e293b; display: block; margin-bottom: 10px;">1. Chọn Kênh TikTok Đăng Bài (Chọn 1 hoặc nhiều kênh):</label>
                    <div class="tt-wrapper" id="tt-wrapper">
                        <div class="tt-search-bar">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="tt-search" placeholder="Tìm kiếm kênh TikTok..." autocomplete="off">
                            <button type="button" id="tt-clear-search" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:0;display:none;">✕</button>
                        </div>
                        <div class="tt-toolbar">
                            <label>
                                <input type="checkbox" id="tt-select-all"> Chọn tất cả kênh
                            </label>
                            <span id="tt-count">0 đã chọn</span>
                        </div>
                        <div class="tt-list" id="tt-list">
                            <div class="tt-empty" id="tt-empty">Không tìm thấy kênh TikTok nào</div>
                        </div>
                    </div>
                    <div id="tt-hidden-inputs"></div>
                    <?php if (empty($tiktok_accounts)): ?>
                        <div style="padding: 12px 16px; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 10px; font-size: 13px; color: #c2410c; font-weight: 600;">
                            ⚠️ Bạn chưa kết nối kênh TikTok nào. Vui lòng bấm nút <b>"Kết Nối Kênh TikTok Mới"</b> ở trên để ủy quyền.
                        </div>
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
                                
                                let avatarHtml = `<img src="${c.avatar ? escHtml(c.avatar) : 'assets/images/default_avatar.png'}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;" onerror="this.src='assets/images/default_avatar.png'">`;
                                
                                div.innerHTML = `<input type="checkbox" id="${id}" value="${c.id}"${checkedIds.has(c.id) ? ' checked' : ''}>
                                                 <label for="${id}">${avatarHtml} <strong>${escHtml(c.display_name)}</strong> <small style="color:#64748b;">(OpenID: ${escHtml(c.open_id.substring(0, 10))}...)</small></label>`;
                                
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
                        countEl.style.fontWeight = n > 0 ? '700' : '';
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
                <div class="form-group" style="background: #fdf2f8; padding: 16px; border-radius: 12px; border: 1px dashed #fbcfe8; margin-bottom: 24px;">
                    <label style="color: #be185d; font-weight: 800; font-size: 13.5px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="auto_title" name="auto_title" value="1" checked style="width: 17px; height: 17px; accent-color: #be185d;">
                        <span>🎵 Tự động dùng Tên File / Tiêu đề TikTok làm Tiêu đề và Mô tả</span>
                    </label>
                    <p style="font-size: 12.5px; color: #9d174d; margin: 4px 0 0 25px;">
                        (Nếu chọn, hệ thống sẽ ưu tiên tên tệp hoặc tiêu đề video gốc từ TikTok/Drive làm nội dung đăng bài)
                    </p>
                </div>

                <!-- 2. Video Title / Caption -->
                <div class="form-group" style="margin-bottom: 24px; position: relative;">
                    <label style="display: flex; align-items: center; justify-content: space-between; font-weight: 700; font-size: 14px; color: #1e293b; margin-bottom: 8px;">
                        <span>2. Mô tả TikTok chung (Tùy chọn):</span>
                        <button type="button" id="emojiTriggerTikTok" class="emoji-picker-trigger">😀 Chèn Emoji</button>
                    </label>
                    <div id="emojiPopupTikTok" class="emoji-picker-popup">
                        <div class="emoji-tabs"></div>
                        <div class="emoji-search-box"><input type="text" class="emoji-search-input" placeholder="Tìm emoji..."></div>
                        <div class="emoji-grid-wrap"></div>
                    </div>
                    <textarea id="title" name="title" rows="3" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13.5px; box-sizing: border-box;" placeholder="Nhập tiêu đề / nội dung video TikTok..."></textarea>
                    <small style="color: #64748b; display: block; margin-top: 6px; font-size: 12px;">💡 Hỗ trợ Spin text: <code>{nội dung 1|nội dung 2|nội dung 3}</code> — random mỗi bài đăng.</small>
                </div>

                <!-- 3. Video Source Selection -->
                <div class="form-group" style="margin-bottom: 24px;">
                    <label style="font-weight: 700; font-size: 14px; color: #1e293b; display: block; margin-bottom: 10px;">3. Tải lên Video TikTok <?php echo $disable_local_upload ? '(Google Drive / Kho Data)' : '(Từ Máy tính, Google Drive hoặc Kho Data)'; ?>:</label>
                    
                    <?php if ($disable_local_upload): ?>
                        <div style="padding: 12px 16px; background: #fef3cd; border: 1px solid #ffc107; border-radius: 10px; font-size: 13px; color: #856404; font-weight: 700; margin-bottom: 12px;">
                            🔒 Admin đã tắt tính năng tải tệp trực tiếp từ máy tính. Vui lòng sử dụng Google Drive hoặc Kho Data.
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; gap: 12px; align-items: center; background: #f8fafc; padding: 16px; border: 1px dashed #cbd5e1; border-radius: 12px; flex-wrap: wrap;">
                        <?php if (!$disable_local_upload): ?>
                            <input type="file" id="video" name="video[]" multiple accept="video/mp4,video/x-m4v,video/*" style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; background: #ffffff; font-size: 13px;" onchange="if(this.files && this.files.length > 0) { clearDriveSelection(); clearKhoDataSelection(); }">
                            <div style="font-weight: 800; color: #64748b; font-size: 12px;">HOẶC</div>
                        <?php endif; ?>
                        <button type="button" class="btn-tt-secondary" onclick="openDriveModal()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                            Chọn từ Google Drive
                        </button>
                        <?php include __DIR__ . '/includes/kho_data_selector.php'; ?>
                    </div>

                    <div id="driveSelectionInfo" style="margin-top: 10px; display: none; padding: 12px 16px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 10px; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; color: #0369a1; font-weight: 800;">
                            <span>📁 Đã chọn <span id="driveSelectedCount">0</span> file từ Google Drive:</span>
                            <button type="button" onclick="clearDriveSelection()" style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12.5px; font-weight: 800;">Hủy / Xoá hết</button>
                        </div>
                        <ul id="driveSelectedList" style="margin: 0; padding-left: 20px; color: #0c4a6e; max-height: 120px; overflow-y: auto; line-height: 1.6; font-weight: 600;"></ul>
                    </div>
                    <input type="hidden" id="drive_file_id" name="drive_file_id" value="">
                    <input type="hidden" id="drive_file_names" name="drive_file_names" value="">
                </div>

                <!-- 4. Bulk Scheduling Section -->
                <div class="form-group" style="background: #f8fafc; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
                    <label style="color: #4f46e5; font-weight: 800; font-size: 14.5px; display: block; margin-bottom: 4px;">4. Lên lịch tự động hàng loạt (Tùy chọn)</label>
                    <p style="font-size: 12.5px; color: #64748b; margin-top: 0; margin-bottom: 14px;">
                        Chọn khoảng ngày và các khung giờ đăng (tối đa 3 tháng mỗi chiến dịch).
                    </p>
                    <div style="display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 180px;">
                            <label style="font-size: 12px; font-weight: 700; color: #334155;">Từ ngày:</label>
                            <input type="date" id="start_date" name="start_date" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box; background: #ffffff;">
                        </div>
                        <div style="flex: 1; min-width: 180px;">
                            <label style="font-size: 12px; font-weight: 700; color: #334155;">Đến ngày:</label>
                            <input type="date" id="end_date" name="end_date" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box; background: #ffffff;">
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 12px; font-weight: 700; color: #334155;">Các khung giờ đăng mỗi ngày (Cách nhau bởi dấu phẩy):</label>
                        <input type="text" id="time_slots" name="time_slots" placeholder="VD: 07:00, 11:30, 15:00, 19:45" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box; background: #ffffff;">
                    </div>
                    <div style="margin-top: 12px; font-size: 11.5px; color: #0369a1; font-weight: 600;">
                        💡 Ghi chú: Nếu nhiều video trùng khung giờ, hệ thống tự động giãn cách 5 phút mỗi video.
                    </div>
                </div>

                <!-- 5. TikTok Direct Post Options -->
                <div class="form-group" style="background: #faf5ff; padding: 20px; border-radius: 14px; border: 1px solid #e9d5ff; margin-bottom: 24px;">
                    <label style="color: #7e22ce; font-weight: 800; font-size: 14.5px; margin-bottom: 12px; display: block;">5. Cấu hình Quyền riêng tư & Tương tác TikTok Direct Post</label>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 14px;">
                        <div style="flex: 1; min-width: 220px;">
                            <label style="font-size: 12.5px; font-weight: 700; color: #581c87;">Quyền xem Video:</label>
                            <select name="privacy_level" style="width: 100%; padding: 9px 12px; border: 1px solid #c084fc; border-radius: 8px; background: #ffffff; font-size: 13px; font-weight: 700; margin-top: 4px;">
                                <option value="PUBLIC_TO_EVERYONE">🌐 Công khai (PUBLIC_TO_EVERYONE)</option>
                                <option value="MUTUAL_FOLLOW_FRIENDS">👥 Bạn bè tương tác (MUTUAL_FOLLOW_FRIENDS)</option>
                                <option value="SELF_ONLY">🔒 Chỉ mình tôi (SELF_ONLY)</option>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: #6b21a8; font-weight: 700;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="allow_comment" value="1" checked style="width: 17px; height: 17px; accent-color: #9333ea;"> Cho phép bình luận
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="allow_duet" value="1" checked style="width: 17px; height: 17px; accent-color: #9333ea;"> Cho phép Duet
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="allow_stitch" value="1" checked style="width: 17px; height: 17px; accent-color: #9333ea;"> Cho phép Stitch
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="auto_add_music" value="1" checked style="width: 17px; height: 17px; accent-color: #9333ea;"> Tự động thêm nhạc nền TikTok
                        </label>
                    </div>
                </div>

                <div id="tiktokResult" style="display: none; margin-bottom: 20px; padding: 14px 18px; border-radius: 10px; font-weight: 700;"></div>

                <div>
                    <button id="btnSubmitTikTok" class="btn-tt-connect" type="submit" style="padding: 14px 36px; font-size: 15px;">
                        🚀 Đăng & Lên Lịch Video TikTok
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
    const tiktokForm = document.getElementById('tiktokPublishForm');
    const btnSubmitTT = document.getElementById('btnSubmitTikTok');
    const tiktokResult = document.getElementById('tiktokResult');

    if (tiktokForm) {
    tiktokForm.addEventListener('submit', function (e) {
        e.preventDefault();

        if (typeof window.tiktokSelectorValidate === 'function' && !window.tiktokSelectorValidate()) {
            return;
        }

        const dataGroupId = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
        const videoEl = document.getElementById('video');
        const videoFiles = videoEl ? videoEl.files.length : 0;
        const driveFileId = document.getElementById('drive_file_id') ? document.getElementById('drive_file_id').value.trim() : '';

        if (!dataGroupId && videoFiles === 0 && !driveFileId) {
            if (typeof window.showNotice === 'function') {
                window.showNotice('Vui lòng chọn File Video tải lên, HOẶC tệp Google Drive, HOẶC chọn Nhóm Data từ Kho Data.', 'warning');
            } else {
                alert('Vui lòng chọn File Video tải lên, HOẶC tệp Google Drive, HOẶC chọn Nhóm Data từ Kho Data.');
            }
            return;
        }

        btnSubmitTT.disabled = true;
        btnSubmitTT.textContent = '⏳ Đang xử lý đăng bài / lên lịch TikTok...';
        tiktokResult.style.display = 'none';

        const formData = new FormData(tiktokForm);
        formData.append('is_ajax', '1');
        const kdGroup = document.getElementById('data_group_id') ? document.getElementById('data_group_id').value.trim() : '';
        const kdMode = document.getElementById('data_mode') ? document.getElementById('data_mode').value.trim() : 'dedup';
        if (kdGroup) {
            formData.set('data_group_id', kdGroup);
            formData.set('data_mode', kdMode);
        }

        fetch('actions/publish_tiktok.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
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
                tiktokResult.style.background = '#ecfdf5';
                tiktokResult.style.color = '#065f46';
                tiktokResult.style.border = '1px solid #a7f3d0';
                tiktokResult.innerHTML = data.msg + ' (Đang chuyển sang trang Quản Lý Bài Đăng...)';
                setTimeout(function() {
                    const dest = data.campaign_id ? ('campaign_detail.php?id=' + data.campaign_id) : (data.redirect || 'manage_posts.php');
                    window.location.href = dest;
                }, 800);
            } else {
                tiktokResult.style.background = '#fff7ed';
                tiktokResult.style.color = '#c2410c';
                tiktokResult.style.border = '1px solid #fed7aa';
                tiktokResult.innerHTML = data.msg;
                btnSubmitTT.disabled = false;
                btnSubmitTT.textContent = '🚀 Đăng & Lên Lịch Video TikTok';
            }
        })
        .catch(err => {
            tiktokResult.style.display = 'block';
            tiktokResult.style.background = '#fef2f2';
            tiktokResult.style.color = '#dc2626';
            tiktokResult.style.border = '1px solid #fecaca';
            tiktokResult.innerHTML = 'Lỗi kết nối máy chủ: ' + err.message;
            btnSubmitTT.disabled = false;
            btnSubmitTT.textContent = '🚀 Đăng & Lên Lịch Video TikTok';
        });
    });
    }

    function onDriveFilesSelected(files) {
        console.log("[TikTokPage] onDriveFilesSelected called with:", files);
        if (!files || files.length === 0) return;
        const fileIds = files.map(f => f.id).join(',');
        const fileNames = files.map(f => f.name).join('|||');

        document.getElementById('drive_file_id').value = fileIds;
        document.getElementById('drive_file_names').value = fileNames;
        const videoEl2 = document.getElementById('video');
        if (videoEl2) videoEl2.value = '';

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
        const infoEl = document.getElementById('driveSelectionInfo');
        if (infoEl) {
            infoEl.style.display = 'block';
            infoEl.style.setProperty('display', 'block', 'important');
        }
    }

    function onDriveFolderSelected(folderId, folderName) {
        console.log("[TikTokPage] onDriveFolderSelected called with:", folderId, folderName);
        document.getElementById('drive_file_id').value = 'folder:' + folderId;
        if (document.getElementById('drive_file_names')) {
            document.getElementById('drive_file_names').value = 'folder:' + folderName;
        }
        const videoEl2 = document.getElementById('video');
        if (videoEl2) videoEl2.value = '';

        const listEl = document.getElementById('driveSelectedList');
        if (listEl) {
            listEl.innerHTML = `<li>📁 Thư mục Google Drive: <strong>${folderName}</strong></li>`;
        }

        document.getElementById('driveSelectedCount').innerText = 'Thư mục';
        const infoEl = document.getElementById('driveSelectionInfo');
        if (infoEl) {
            infoEl.style.display = 'block';
            infoEl.style.setProperty('display', 'block', 'important');
        }
    }

    function clearDriveSelection() {
        document.getElementById('drive_file_id').value = '';
        document.getElementById('drive_file_names').value = '';
        document.getElementById('driveSelectionInfo').style.display = 'none';
    }

    window.onDriveFilesSelected = onDriveFilesSelected;
    window.onDriveFileSelected = function(id, name) { onDriveFilesSelected([{id: id, name: name}]); };
    window.onDriveFolderSelected = onDriveFolderSelected;
    window.clearDriveSelection = clearDriveSelection;

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
        .then(async r => {
            const text = await r.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error("Server raw response:", text);
                alert("Lỗi phản hồi máy chủ: " + text.substring(0, 300));
                return;
            }
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
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-delete-tt');
        if (btn) {
            const id = btn.dataset.id;
            const name = btn.dataset.name;
            deleteTikTokAccount(id, name);
        }
    });

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

