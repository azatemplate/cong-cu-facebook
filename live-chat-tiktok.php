<?php
// live-chat-tiktok.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

$current_page = 'live_chat_tiktok';
require_once __DIR__ . '/includes/header.php';

try {
    @include_once __DIR__ . '/includes/tiktok_api.php';
    @include_once __DIR__ . '/setup_tiktok_chat.php';
} catch (Throwable $e) {}

$account_id = $_SESSION['account_id'] ?? 1;

// 1. Fetch connected TikTok Accounts
$tiktok_accounts = [];
try {
    $stmt_tt = $pdo->prepare("SELECT * FROM tiktok_accounts WHERE account_id = ? AND is_active = 1 ORDER BY created_at DESC");
    $stmt_tt->execute([$account_id]);
    $tiktok_accounts = $stmt_tt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 2. Fetch sales_list
$sales_list = '';
try {
    $stmt_acc = $pdo->prepare("SELECT sales_list FROM system_accounts WHERE id = ?");
    $stmt_acc->execute([$account_id]);
    $acc_setup = $stmt_acc->fetch(PDO::FETCH_ASSOC);
    $sales_list = $acc_setup['sales_list'] ?? '';
} catch (Exception $e) {}

// Build Webhook & Redirect URL domain info
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$base_path = function_exists('get_base_url') ? get_base_url() : '/';
$domain = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base_path;
$webhook_url = $domain . 'tiktok_webhook.php';
$redirect_url = $domain . 'tiktok_callback.php';
?>

<!-- Custom CSS for Premium TikTok Live Chat -->
<style>
    .tiktok-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 10px;
    }
    .tiktok-tab-btn {
        background: transparent;
        border: none;
        color: var(--text-muted);
        padding: 10px 20px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        border-radius: 8px;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .tiktok-tab-btn:hover {
        background: rgba(254, 44, 85, 0.08);
        color: #fe2c55;
    }
    .tiktok-tab-btn.active {
        background: #fe2c55 !important;
        color: #fff !important;
        box-shadow: 0 4px 12px rgba(254, 44, 85, 0.35);
    }
    
    .tiktok-tab-content {
        display: none;
    }
    .tiktok-tab-content.active {
        display: block !important;
    }

    /* Container Layout */
    .livechat-container {
        display: flex;
        gap: 0;
        height: calc(100vh - 180px);
        min-height: 520px;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        overflow: hidden;
    }

    /* Conversations Column */
    .lc-conversations {
        width: 320px;
        flex-shrink: 0;
        border-right: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
        background: var(--card-bg);
    }
    .lc-conv-header {
        padding: 16px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .lc-filters {
        display: flex;
        gap: 6px;
    }
    .filter-btn {
        flex: 1;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        border: 1px solid var(--border-color);
        background: transparent;
        color: var(--text-muted);
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;
    }
    .filter-btn.active {
        background: #fe2c55;
        color: #fff;
        border-color: #fe2c55;
    }
    .conv-list {
        flex: 1;
        overflow-y: auto;
    }
    .conv-item {
        display: flex;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid var(--border-color);
        cursor: pointer;
        transition: background 0.15s ease;
        position: relative;
    }
    .conv-item:hover {
        background: rgba(254, 44, 85, 0.05);
    }
    .conv-item.active {
        background: rgba(254, 44, 85, 0.1);
        border-left: 4px solid #fe2c55;
        padding-left: 12px;
    }

    /* Chatbox Column */
    .lc-chatbox {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        background: var(--card-bg);
        border-right: 1px solid var(--border-color);
    }
    .chat-header {
        padding: 14px 20px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .chat-messages {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 15px;
        background: var(--bg-color);
    }
    .msg-group {
        display: flex;
        flex-direction: column;
        max-width: 75%;
    }
    .msg-group.msg-sent {
        align-self: flex-end;
        align-items: flex-end;
    }
    .msg-group.msg-received {
        align-self: flex-start;
        align-items: flex-start;
    }
    .msg-bubble {
        padding: 10px 14px;
        border-radius: 14px;
        font-size: 14px;
        line-height: 1.45;
        word-break: break-word;
    }
    .msg-sent .msg-bubble {
        background: #fe2c55;
        color: #fff;
        border-bottom-right-radius: 2px;
    }
    .msg-received .msg-bubble {
        background: var(--card-bg);
        color: var(--text-main);
        border-bottom-left-radius: 2px;
        border: 1px solid var(--border-color);
    }

    /* Input Footer */
    .chat-footer {
        padding: 16px;
        border-top: 1px solid var(--border-color);
        background: var(--card-bg);
    }
    .chat-input-wrapper {
        display: flex;
        align-items: flex-end;
        gap: 10px;
    }
    .chat-input-wrapper textarea {
        flex: 1;
        resize: none;
        height: 42px;
        max-height: 120px;
        padding: 10px 14px;
        border-radius: 20px;
        font-size: 14px;
        border: 1px solid var(--border-color);
    }
    .chat-send-btn {
        background: #fe2c55;
        color: #fff;
        border: none;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
    }

    /* Info Panel */
    .lc-infopanel {
        width: 300px;
        flex-shrink: 0;
        background: var(--card-bg);
        display: flex;
        flex-direction: column;
    }
    .info-header {
        padding: 16px;
        border-bottom: 1px solid var(--border-color);
        font-weight: 600;
        font-size: 15px;
    }
    .info-content {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
    }
</style>

<!-- Platform Switcher Tabs -->
<div class="platform-tabs" style="display: flex; gap: 20px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; padding-bottom: 0;">
    <a href="live_chat.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>📘</span> Facebook Fanpage
    </a>
    <a href="live-chat-oa.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>💬</span> Zalo Official Account
    </a>
    <a href="live-chat-tiktok.php" class="platform-tab-btn active" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #fe2c55; border-bottom: 3px solid #fe2c55; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🎵</span> TikTok Live Chat
    </a>
    <a href="website.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🌐</span> Live Chat Website
    </a>
    <a href="customers.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>👥</span> Khách Hàng
    </a>
</div>

<!-- Page Title -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
    <div class="page-title" style="margin-bottom:0;">🎵 TikTok Live Chat & Quản Lý Kênh</div>
</div>

<!-- TikTok Tabs -->
<div class="tiktok-tabs">
    <button class="tiktok-tab-btn active" data-target="tab-livechat-tt">
        <span>💬</span> TikTok Live Chat
    </button>
    <button class="tiktok-tab-btn" data-target="tab-channels-tt">
        <span>🔌</span> Kênh TikTok Đã Liên Kết
    </button>
    <button class="tiktok-tab-btn" data-target="tab-webhook-config">
        <span>⚙️</span> Cấu Hình Webhook & App TikTok
    </button>
</div>

<!-- ==================== TAB 1: LIVE CHAT ==================== -->
<div id="tab-livechat-tt" class="tiktok-tab-content active">
    <div class="livechat-container">
        <!-- Left: Conversations List -->
        <div class="lc-conversations">
            <div class="lc-conv-header">
                <select id="selected_tt_account" onchange="loadTikTokConversations()">
                    <option value="">-- Chọn kênh TikTok --</option>
                    <?php foreach ($tiktok_accounts as $tt): ?>
                        <option value="<?php echo htmlspecialchars($tt['open_id']); ?>">
                            🎵 <?php echo htmlspecialchars($tt['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="lc-filters">
                    <button class="filter-btn active" data-filter="all" onclick="filterTTConversations('all')">Tất cả</button>
                    <button class="filter-btn" data-filter="unread" onclick="filterTTConversations('unread')">Chưa đọc</button>
                    <button class="filter-btn" data-filter="phone" onclick="filterTTConversations('phone')">Có SĐT</button>
                </div>
                <div style="padding: 4px 0 0 0;">
                    <input type="text" id="tt_conv_search" placeholder="🔍 Tìm tên/SĐT khách TikTok..." oninput="filterTTConversations()" style="width:100%; padding:6px 10px; border:1px solid var(--border-color); border-radius:6px; font-size:12px; box-sizing:border-box; background: var(--card-bg); color: var(--text-main);">
                </div>
            </div>
            <div class="conv-list" id="tt_conv_list_container">
                <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">
                    Vui lòng chọn một kênh TikTok để bắt đầu trò chuyện.
                </div>
            </div>
        </div>

        <!-- Center: Chat Window -->
        <div class="lc-chatbox">
            <div class="chat-header">
                <div style="display:flex; align-items:center; gap:12px;" id="tt_chat_active_user">
                    <div style="font-weight:500; font-size:14px; color:var(--text-muted);">
                        Chưa chọn cuộc hội thoại nào
                    </div>
                </div>
            </div>

            <div class="chat-messages" id="tt_chat_messages_container">
                <div style="margin:auto; text-align:center; color:var(--text-muted); font-size:14px; padding:20px;">
                    <div style="font-size:36px; margin-bottom:10px;">🎵</div>
                    Chọn khách hàng TikTok để xem tin nhắn.
                </div>
            </div>

            <div class="chat-footer">
                <form id="tt_chat_form" onsubmit="handleSendTTMessage(event)" style="margin:0;">
                    <div class="chat-input-wrapper">
                        <textarea id="tt_chat_input" placeholder="Nhập nội dung tin nhắn phản hồi TikTok..." disabled onkeydown="if(event.key==='Enter' && !event.shiftKey){ event.preventDefault(); handleSendTTMessage(event); }"></textarea>
                        <button type="submit" id="btn_send_tt" class="chat-send-btn" title="Gửi tin nhắn TikTok" disabled>
                            ➤
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right: Customer Profile Panel -->
        <div class="lc-infopanel">
            <div class="info-header">
                <span>👤</span> Hồ sơ khách TikTok
            </div>
            <div class="info-content">
                <form id="tt_customer_profile_form" onsubmit="handleSaveTTCustomer(event)">
                    <div class="form-group">
                        <label>Tên khách hàng TikTok</label>
                        <input type="text" id="tt_cust_name" required placeholder="Tên khách TikTok" disabled>
                    </div>
                    <div class="form-group">
                        <label>Số điện thoại</label>
                        <input type="text" id="tt_cust_phone" placeholder="Nhập số điện thoại" disabled>
                    </div>
                    <div class="form-group">
                        <label>Tỉnh thành</label>
                        <input type="text" id="tt_cust_province" placeholder="Nhập tỉnh thành" disabled>
                    </div>
                    <div class="form-group">
                        <label>Ghi chú nhu cầu</label>
                        <textarea id="tt_cust_notes" rows="4" placeholder="Nhu cầu sản phẩm..." disabled></textarea>
                    </div>
                    <div class="form-group">
                        <label>Trạng thái tư vấn</label>
                        <select id="tt_cust_consulted" disabled style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; background:var(--card-bg); color:var(--text-main);">
                            <option value="0">🆕 Chưa tư vấn</option>
                            <option value="4">⏳ Chờ xử lý</option>
                            <option value="1">✅ Đã tư vấn</option>
                            <option value="2">🔄 Khách quay lại</option>
                            <option value="3">⛔ Dừng tư vấn</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sales phụ trách</label>
                        <input type="text" id="tt_cust_sales_phone" list="sales_phone_list" placeholder="SĐT Sales" disabled>
                        <datalist id="sales_phone_list">
                            <?php
                            if (!empty($sales_list)) {
                                $lines = explode("\n", $sales_list);
                                foreach ($lines as $line) {
                                    $trimmed = trim($line);
                                    if ($trimmed !== '') {
                                        echo '<option value="' . htmlspecialchars($trimmed) . '"></option>';
                                    }
                                }
                            }
                            ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label>Ghi chú Sales</label>
                        <textarea id="tt_cust_sales_notes" rows="2" placeholder="Ghi chú Sales" disabled></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" id="btn_save_tt_cust" style="width:100%; font-weight:600; background:#fe2c55; border:none; margin-top:10px;" disabled>
                        Lưu Thông Tin
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ==================== TAB 2: KÊNH TIKTOK ==================== -->
<div id="tab-channels-tt" class="tiktok-tab-content">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:20px;">
            <div style="font-weight:600; font-size:16px;">Kênh TikTok Đã Liên Kết</div>
            <a href="tiktok_login.php" class="btn btn-primary" style="background:#fe2c55; border:none; box-shadow: 0 4px 12px rgba(254, 44, 85, 0.4);">
                ➕ Kết Nối Tài Khoản TikTok Mới
            </a>
        </div>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
            <?php foreach ($tiktok_accounts as $acc): ?>
                <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; padding:18px; display:flex; align-items:center; gap:14px;">
                    <img src="<?php echo htmlspecialchars($acc['avatar'] ?: 'assets/images/tiktok_default.png'); ?>" style="width:50px; height:50px; border-radius:50%; object-fit:cover; border:1px solid #cbd5e1;" onerror="this.src='https://cdn-icons-png.flaticon.com/512/3046/3046124.png'">
                    <div>
                        <div style="font-weight:700; font-size:15px;"><?php echo htmlspecialchars($acc['display_name']); ?></div>
                        <div style="font-size:11px; color:var(--text-muted);">OpenID: <?php echo htmlspecialchars(substr($acc['open_id'], 0, 14)); ?>...</div>
                        <span style="font-size:11px; background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:10px; font-weight:600; margin-top:4px; display:inline-block;">✓ Hoạt động</span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($tiktok_accounts)): ?>
                <div style="grid-column: 1 / -1; text-align:center; padding:40px; color:var(--text-muted);">
                    Chưa có kênh TikTok nào được ủy quyền. Hãy nhấn nút "Kết Nối Tài Khoản TikTok Mới" ở trên.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== TAB 3: CẤU HÌNH WEBHOOK & APP ==================== -->
<div id="tab-webhook-config" class="tiktok-tab-content">
    <div class="card" style="max-width:750px; margin:0 auto;">
        <div style="font-weight:700; font-size:17px; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:20px;">
            ⚙️ Hướng Dẫn Cấu Hình Webhook & Tránh Lỗi 403 Forbidden
        </div>

        <div style="margin-bottom:20px;">
            <label style="font-weight:600; font-size:14px; display:block; margin-bottom:6px;">1. Webhook Callback URL (Điền vào TikTok Developer Portal):</label>
            <div style="display:flex; gap:10px;">
                <input type="text" id="wh_url_box" readonly value="<?php echo htmlspecialchars($webhook_url); ?>" style="flex:1; padding:10px; font-family:monospace; font-size:13px; border:1px solid var(--border-color); border-radius:6px; background:var(--bg-color);">
                <button onclick="navigator.clipboard.writeText(document.getElementById('wh_url_box').value); alert('Đã copy Webhook URL!');" class="btn btn-primary" style="background:#fe2c55; border:none; font-weight:600;">Copy</button>
            </div>
        </div>

        <div style="margin-bottom:20px;">
            <label style="font-weight:600; font-size:14px; display:block; margin-bottom:6px;">2. Login Kit Redirect URI (Điền vào phần Login Kit -> Redirect URI):</label>
            <div style="display:flex; gap:10px;">
                <input type="text" id="red_url_box" readonly value="<?php echo htmlspecialchars($redirect_url); ?>" style="flex:1; padding:10px; font-family:monospace; font-size:13px; border:1px solid var(--border-color); border-radius:6px; background:var(--bg-color);">
                <button onclick="navigator.clipboard.writeText(document.getElementById('red_url_box').value); alert('Đã copy Redirect URI!');" class="btn btn-primary" style="background:#0068ff; border:none; font-weight:600;">Copy</button>
            </div>
        </div>

        <div style="background:#fff7ed; border-left:4px solid #f97316; padding:16px; border-radius:0 8px 8px 0; font-size:13px; color:#9a3412; line-height:1.6;">
            <strong>⚠️ Nguyên nhân & Cách khắc phục khi TikTok báo <code>403 Forbidden</code>:</strong>
            <ol style="margin:8px 0 0 20px; padding:0;">
                <li><b>Chưa thêm Sản phẩm Webhook/Messaging:</b> Vào TikTok Developer Console &rarr; chọn App của bạn &rarr; mục <b>Products</b> &rarr; Thêm <b>Webhooks</b> và <b>Content Posting API / Direct Post</b>.</li>
                <li><b>Trạng thái App ở dạng Nháp (Draft):</b> Đảm bảo App đã bật tính năng Sandbox hoặc đã kích hoạt các sản phẩm liên quan.</li>
                <li><b>URL phản hồi Challenge:</b> File <code>tiktok_webhook.php</code> đã được tự động tối ưu sẵn để luôn trả về <code>HTTP 200 OK</code> kèm mã Handshake verification từ TikTok.</li>
            </ol>
        </div>
    </div>
</div>

<script>
    // Tab switcher
    document.querySelectorAll('.tiktok-tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tiktok-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tiktok-tab-content').forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            document.getElementById(this.dataset.target).classList.add('active');
        });
    });

    let currentOpenId = '';
    let currentSenderId = '';
    let allTTConversations = [];
    let currentFilter = 'all';

    document.addEventListener("DOMContentLoaded", function() {
        const select = document.getElementById('selected_tt_account');
        if (select && select.options.length > 1) {
            select.selectedIndex = 1;
            loadTikTokConversations();
        }
    });

    function loadTikTokConversations() {
        const select = document.getElementById('selected_tt_account');
        currentOpenId = select.value;
        const container = document.getElementById('tt_conv_list_container');

        if (!currentOpenId) {
            container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">Vui lòng chọn một kênh TikTok để bắt đầu.</div>';
            return;
        }

        container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">⏳ Đang tải cuộc hội thoại TikTok...</div>';

        fetch('actions/tiktok_chat_api.php?action=get_conversations&open_id=' + encodeURIComponent(currentOpenId))
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    allTTConversations = res.conversations;
                    filterTTConversations();
                } else {
                    container.innerHTML = `<div style="text-align:center; padding:30px; color:#ef4444; font-size:13px;">Lỗi: ${res.msg}</div>`;
                }
            });
    }

    function filterTTConversations(filterType) {
        if (filterType) {
            currentFilter = filterType;
            document.querySelectorAll('.lc-filters .filter-btn').forEach(b => b.classList.remove('active'));
            const btn = document.querySelector(`.lc-filters .filter-btn[data-filter="${filterType}"]`);
            if (btn) btn.classList.add('active');
        }

        const q = document.getElementById('tt_conv_search').value.toLowerCase().trim();
        const container = document.getElementById('tt_conv_list_container');

        let filtered = allTTConversations.filter(c => {
            let matchFilter = true;
            if (currentFilter === 'unread') matchFilter = (c.unread_count > 0);
            if (currentFilter === 'phone') matchFilter = (c.phone && c.phone.trim() !== '');

            let matchQuery = true;
            if (q) {
                matchQuery = (c.name || '').toLowerCase().includes(q) || (c.phone || '').toLowerCase().includes(q);
            }
            return matchFilter && matchQuery;
        });

        if (filtered.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">Không tìm thấy cuộc hội thoại nào.</div>';
            return;
        }

        let html = '';
        filtered.forEach(c => {
            const activeClass = (c.sender_id === currentSenderId) ? 'active' : '';
            const unreadDot = c.unread_count > 0 ? `<div class="unread-dot"></div>` : '';
            const phoneBadge = c.phone ? `<span class="badge-phone">📞 ${c.phone}</span>` : '';

            html += `
                <div class="conv-item ${activeClass}" onclick="selectTTCustomer('${c.sender_id}')">
                    <div class="conv-avatar-wrapper">
                        <img src="${c.avatar || 'https://cdn-icons-png.flaticon.com/512/3046/3046124.png'}" class="conv-avatar" onerror="this.src='https://cdn-icons-png.flaticon.com/512/3046/3046124.png'">
                        ${unreadDot}
                    </div>
                    <div class="conv-info">
                        <div class="conv-meta">
                            <span class="conv-name">${c.name || 'Khách TikTok'}</span>
                            <span class="conv-time">${c.updated_at ? c.updated_at.substring(11, 16) : ''}</span>
                        </div>
                        <div class="conv-last-msg">${c.last_message || 'Bắt đầu cuộc trò chuyện...'}</div>
                        ${phoneBadge}
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    function selectTTCustomer(senderId) {
        currentSenderId = senderId;
        const cust = allTTConversations.find(c => c.sender_id === senderId);
        if (!cust) return;

        // Active state update
        filterTTConversations();

        // Render header active
        document.getElementById('tt_chat_active_user').innerHTML = `
            <img src="${cust.avatar || 'https://cdn-icons-png.flaticon.com/512/3046/3046124.png'}" style="width:38px; height:38px; border-radius:50%; object-fit:cover;" onerror="this.src='https://cdn-icons-png.flaticon.com/512/3046/3046124.png'">
            <div>
                <div style="font-weight:600; font-size:15px;">${cust.name || 'Khách TikTok'}</div>
                <div style="font-size:12px; color:var(--text-muted);">${cust.phone ? ('📞 ' + cust.phone) : 'Khách hàng TikTok'}</div>
            </div>
        `;

        // Enable inputs
        document.getElementById('tt_chat_input').disabled = false;
        document.getElementById('btn_send_tt').disabled = false;

        // Enable right profile form
        document.getElementById('tt_cust_name').disabled = false;
        document.getElementById('tt_cust_name').value = cust.name || '';
        document.getElementById('tt_cust_phone').disabled = false;
        document.getElementById('tt_cust_phone').value = cust.phone || '';
        document.getElementById('tt_cust_province').disabled = false;
        document.getElementById('tt_cust_province').value = cust.province || '';
        document.getElementById('tt_cust_notes').disabled = false;
        document.getElementById('tt_cust_notes').value = cust.notes || '';
        document.getElementById('tt_cust_consulted').disabled = false;
        document.getElementById('tt_cust_consulted').value = cust.consulted || 0;
        document.getElementById('tt_cust_sales_phone').disabled = false;
        document.getElementById('tt_cust_sales_phone').value = cust.sales_phone || '';
        document.getElementById('tt_cust_sales_notes').disabled = false;
        document.getElementById('tt_cust_sales_notes').value = cust.sales_notes || '';
        document.getElementById('btn_save_tt_cust').disabled = false;

        loadTTMessages();
    }

    function loadTTMessages() {
        if (!currentOpenId || !currentSenderId) return;

        fetch(`actions/tiktok_chat_api.php?action=get_messages&open_id=${encodeURIComponent(currentOpenId)}&sender_id=${encodeURIComponent(currentSenderId)}`)
            .then(r => r.json())
            .then(res => {
                const container = document.getElementById('tt_chat_messages_container');
                container.innerHTML = '';
                if (res.status === 'success') {
                    if (res.messages.length === 0) {
                        container.innerHTML = '<div style="margin:auto; text-align:center; color:var(--text-muted); font-size:13px;">Chưa có lịch sử tin nhắn.</div>';
                        return;
                    }
                    res.messages.forEach(m => {
                        const isAgent = m.sender_type === 'agent';
                        const grp = document.createElement('div');
                        grp.className = 'msg-group ' + (isAgent ? 'msg-sent' : 'msg-received');
                        
                        const bub = document.createElement('div');
                        bub.className = 'msg-bubble';
                        bub.innerHTML = (m.message || '').replace(/\n/g, '<br>');

                        const tm = document.createElement('div');
                        tm.style.cssText = 'font-size:10px; color:var(--text-muted); margin-top:3px;';
                        tm.textContent = m.created_at || '';

                        grp.appendChild(bub);
                        grp.appendChild(tm);
                        container.appendChild(grp);
                    });
                    container.scrollTop = container.scrollHeight;
                }
            });
    }

    function handleSendTTMessage(e) {
        e.preventDefault();
        const input = document.getElementById('tt_chat_input');
        const text = input.value.trim();
        if (!text || !currentOpenId || !currentSenderId) return;

        input.value = '';

        const fd = new FormData();
        fd.append('action', 'send_message');
        fd.append('open_id', currentOpenId);
        fd.append('sender_id', currentSenderId);
        fd.append('message', text);

        fetch('actions/tiktok_chat_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    loadTTMessages();
                    loadTikTokConversations();
                } else {
                    alert('Lỗi: ' + res.msg);
                }
            });
    }

    function handleSaveTTCustomer(e) {
        e.preventDefault();
        if (!currentOpenId || !currentSenderId) return;

        const fd = new FormData();
        fd.append('action', 'save_customer_profile');
        fd.append('open_id', currentOpenId);
        fd.append('sender_id', currentSenderId);
        fd.append('name', document.getElementById('tt_cust_name').value);
        fd.append('phone', document.getElementById('tt_cust_phone').value);
        fd.append('province', document.getElementById('tt_cust_province').value);
        fd.append('notes', document.getElementById('tt_cust_notes').value);
        fd.append('consulted', document.getElementById('tt_cust_consulted').value);
        fd.append('sales_phone', document.getElementById('tt_cust_sales_phone').value);
        fd.append('sales_notes', document.getElementById('tt_cust_sales_notes').value);

        fetch('actions/tiktok_chat_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    alert(res.msg);
                    loadTikTokConversations();
                } else {
                    alert('Lỗi: ' + res.msg);
                }
            });
    }

    // Polling TikTok messages every 5s
    setInterval(() => {
        if (currentOpenId && currentSenderId) {
            loadTTMessages();
        }
    }, 5000);
</script>

<?php include 'includes/footer.php'; ?>
