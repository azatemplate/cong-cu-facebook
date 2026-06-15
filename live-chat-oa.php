<?php
// live-chat-oa.php
$current_page = 'live_chat_zalo';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Custom CSS for Premium Zalo Live Chat interface -->
<style>
    /* Tab Navigation Styles */
    .zalo-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 10px;
    }
    .zalo-tab-btn {
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
    .zalo-tab-btn:hover {
        background: rgba(0, 104, 255, 0.05);
        color: #0068ff;
    }
    .zalo-tab-btn.active {
        background: #0068ff;
        color: #fff;
        box-shadow: 0 4px 6px -1px rgba(0, 104, 255, 0.3);
    }
    
    /* Tab Contents visibility */
    .zalo-tab-content {
        display: none;
        animation: fadeIn 0.25s ease-in-out;
    }
    .zalo-tab-content.active {
        display: block;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Live Chat Container Styles */
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
    .lc-conv-header select {
        padding: 8px 12px;
        border-radius: 6px;
        font-weight: 500;
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
        background: #0068ff;
        color: #fff;
        border-color: #0068ff;
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
        background: rgba(0, 104, 255, 0.03);
    }
    .conv-item.active {
        background: rgba(0, 104, 255, 0.08);
        border-left: 4px solid #0068ff;
        padding-left: 12px;
    }
    .conv-avatar-wrapper {
        position: relative;
        flex-shrink: 0;
    }
    .conv-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        object-fit: cover;
        border: 1px solid var(--border-color);
    }
    .unread-dot {
        position: absolute;
        top: 2px;
        right: 2px;
        width: 10px;
        height: 10px;
        background: #ef4444;
        border-radius: 50%;
        border: 2px solid var(--card-bg);
    }
    .conv-info {
        flex: 1;
        min-width: 0;
    }
    .conv-meta {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 4px;
    }
    .conv-name {
        font-weight: 600;
        font-size: 14px;
        color: var(--text-main);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .conv-time {
        font-size: 11px;
        color: var(--text-muted);
    }
    .conv-last-msg {
        font-size: 13px;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .badge-phone {
        font-size: 10px;
        background: #dcfce7;
        color: #15803d;
        padding: 2px 6px;
        border-radius: 10px;
        margin-top: 4px;
        display: inline-block;
        font-weight: 600;
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
    .chat-active-user {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .chat-active-avatar {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        object-fit: cover;
    }
    .chat-active-name {
        font-weight: 600;
        font-size: 15px;
    }
    .chat-active-status {
        font-size: 12px;
        color: var(--text-muted);
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
        background: #0068ff;
        color: #fff;
        border-bottom-right-radius: 2px;
    }
    .msg-received .msg-bubble {
        background: var(--card-bg);
        color: var(--text-main);
        border-bottom-left-radius: 2px;
        border: 1px solid var(--border-color);
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .msg-bubble img {
        max-width: 100%;
        border-radius: 8px;
        cursor: pointer;
    }
    .msg-time {
        font-size: 10px;
        color: var(--text-muted);
        margin-top: 4px;
        padding: 0 4px;
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
        line-height: 1.4;
        border: 1px solid var(--border-color);
    }
    .chat-action-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: transparent;
        color: var(--text-muted);
        border: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    .chat-action-btn:hover:not(:disabled) {
        background: rgba(0, 104, 255, 0.05);
        color: #0068ff;
        border-color: #0068ff;
    }
    .chat-send-btn {
        background: #0068ff;
        color: #fff;
        border-color: #0068ff;
        box-shadow: 0 4px 6px -1px rgba(0, 104, 255, 0.3);
    }
    .chat-send-btn:hover:not(:disabled) {
        background: #0056d6;
        color: #fff;
    }
    .chat-action-btn:disabled, .chat-input-wrapper textarea:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Right Panel Column */
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
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .info-content {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
    }
    
    /* Config Panel Styles */
    .config-card {
        max-width: 600px;
        margin: 0 auto;
    }

    /* Toast overlay styling */
    .toast-container {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .toast {
        background: #333;
        color: #fff;
        padding: 12px 24px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        font-size: 14px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
        transform: translateY(20px);
        opacity: 0;
        animation: toastIn 0.3s forwards;
    }
    .toast.success {
        background: #10b981;
    }
    .toast.error {
        background: #ef4444;
    }
    @keyframes toastIn {
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    /* Channels view listing styles */
    .channel-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 10px;
    }
    .channel-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        position: relative;
    }
    .channel-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        object-fit: cover;
        border: 1px solid var(--border-color);
    }
    .channel-info {
        flex: 1;
        min-width: 0;
    }
    .channel-name {
        font-weight: 600;
        font-size: 15px;
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .channel-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 600;
    }
</style>

<!-- Page Header Title -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
    <div class="page-title" style="margin-bottom:0;">💬 Zalo OA Live Chat & Kênh</div>
</div>

<!-- 3 Tabs Navigation -->
<div class="zalo-tabs">
    <button class="zalo-tab-btn active" data-target="tab-livechat">
        <span>💬</span> Live Chat
    </button>
    <button class="zalo-tab-btn" data-target="tab-channels">
        <span>🔌</span> Kênh OA
    </button>
    <button class="zalo-tab-btn" data-target="tab-config">
        <span>⚙️</span> Cấu hình App
    </button>
</div>

<!-- ==================== TAB 1: LIVE CHAT ==================== -->
<div id="tab-livechat" class="zalo-tab-content active">
    <div class="livechat-container" id="livechatContainer">
        
        <!-- Left: Conversations List -->
        <div class="lc-conversations">
            <div class="lc-conv-header">
                <!-- Dropdown channel selector -->
                <select id="selected_oa" onchange="loadConversations()">
                    <option value="">-- Chọn kênh Zalo OA --</option>
                </select>
                <!-- Search or Filter buttons -->
                <div class="lc-filters">
                    <button class="filter-btn active" data-filter="all" onclick="filterConversations('all')">Tất cả</button>
                    <button class="filter-btn" data-filter="unread" onclick="filterConversations('unread')">Chưa đọc</button>
                    <button class="filter-btn" data-filter="phone" onclick="filterConversations('phone')">Có SĐT</button>
                </div>
            </div>
            <!-- Conversation nodes -->
            <div class="conv-list" id="conv_list_container">
                <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">
                    Vui lòng chọn một kênh Zalo OA để bắt đầu.
                </div>
            </div>
        </div>

        <!-- Center: Chat Box -->
        <div class="lc-chatbox">
            <!-- Header active chat -->
            <div class="chat-header">
                <div class="chat-active-user" id="chat_active_user_info">
                    <div style="font-weight:500; font-size:14px; color:var(--text-muted);">
                        Chưa chọn cuộc hội thoại nào
                    </div>
                </div>
                <!-- Toggle info panel on mobile -->
                <button onclick="toggleInfoPanel()" class="chat-action-btn" title="Xem thông tin" id="btn_toggle_info" style="display:none;">
                    ℹ️
                </button>
            </div>

            <!-- Messages Window -->
            <div class="chat-messages" id="chat_messages_container">
                <div style="margin:auto; text-align:center; color:var(--text-muted); font-size:14px; padding:20px;">
                    <div style="font-size:36px; margin-bottom:10px;">💬</div>
                    Chọn khách hàng để xem lịch sử trò chuyện.
                </div>
            </div>

            <!-- 24h Warning banner -->
            <div id="policy_24h_banner" style="display:none; margin: 0 16px 10px 16px; padding:12px 16px; background:#fef2f2; border:1px solid #fee2e2; border-radius:8px; font-size:13px; color:#b91c1c; align-items:flex-start; gap:10px; line-height:1.5;">
                <span>⚠️ Do chính sách Zalo OA, hội thoại đã quá 24h kể từ tương tác cuối cùng của khách hàng. Bạn không thể tiếp tục gửi tin nhắn chăm sóc khách hàng.</span>
            </div>

            <!-- Chat input form -->
            <div class="chat-footer">
                <form id="chat_form" onsubmit="handleSendMessage(event)" style="margin:0;">
                    <div class="chat-input-wrapper">
                        <!-- Hidden image upload -->
                        <input type="file" id="file_attachment" accept="image/*" onchange="handleFileSelected(event)" style="display:none;">
                        <button type="button" id="btn_attach" class="chat-action-btn" title="Đính kèm hình ảnh" onclick="document.getElementById('file_attachment').click()" disabled>
                            📎
                        </button>
                        
                        <textarea id="chat_message_input" placeholder="Nhập nội dung tin nhắn..." onkeydown="handleTextareaKeydown(event)" disabled></textarea>
                        
                        <button type="submit" id="btn_send" class="chat-action-btn chat-send-btn" title="Gửi tin nhắn" disabled>
                            ➤
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right: Customer Info Panel -->
        <div class="lc-infopanel" id="lc_infopanel">
            <div class="info-header">
                <span>👤</span> Hồ sơ khách hàng
            </div>
            <div class="info-content">
                <form id="customer_profile_form" onsubmit="handleSaveCustomer(event)">
                    <div class="form-group">
                        <label for="cust_name">Tên khách hàng</label>
                        <input type="text" id="cust_name" required placeholder="Nhập tên khách hàng" disabled>
                    </div>
                    <div class="form-group">
                        <label for="cust_phone">Số điện thoại</label>
                        <input type="text" id="cust_phone" placeholder="Nhập số điện thoại" disabled>
                    </div>
                    <div class="form-group">
                        <label for="cust_province">Tỉnh thành</label>
                        <input type="text" id="cust_province" placeholder="Nhập tỉnh thành" disabled>
                    </div>
                    <div class="form-group">
                        <label for="cust_notes">Yêu cầu / Ghi chú tích lũy</label>
                        <textarea id="cust_notes" rows="6" placeholder="Nhu cầu sản phẩm, số lượng, lịch sử trao đổi..." style="height:auto;" disabled></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" id="btn_save_customer" style="width:100%; font-weight:600; background:#0068ff; border:none; margin-top:10px; box-shadow:0 4px 6px -1px rgba(0, 104, 255, 0.4);" disabled>
                        Lưu thông tin
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>

<!-- ==================== TAB 2: KÊNH OA ==================== -->
<div id="tab-channels" class="zalo-tab-content">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:20px;">
            <div style="font-weight:600; font-size:16px;">Kênh Zalo Official Account Đã Liên Kết</div>
            <a href="#" id="btn_add_oa_link" class="btn btn-primary" style="background:#0068ff; border:none; box-shadow: 0 4px 6px -1px rgba(0, 104, 255, 0.4); display:none;">
                🔌 Thêm OA Mới
            </a>
        </div>
        <div id="channel_list_container" class="channel-grid">
            <div style="text-align:center; grid-column: 1 / -1; padding:40px; color:var(--text-muted);">
                Đang tải danh sách kênh OA...
            </div>
        </div>
    </div>
</div>

<!-- ==================== TAB 3: CẤU HÌNH APP ==================== -->
<div id="tab-config" class="zalo-tab-content">
    <div class="card config-card">
        <div style="font-weight:600; font-size:17px; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:20px;">
            ⚙️ Cấu Hình Zalo App Credentials
        </div>
        
        <form id="zalo_config_form" onsubmit="handleSaveConfig(event)">
            <!-- Hidden CSRF token -->
            <input type="hidden" name="_csrf_token" value="<?php echo $_csrf_token; ?>">
            
            <div class="form-group">
                <label for="config_app_id">ID Ứng dụng (App ID)</label>
                <input type="text" id="config_app_id" name="app_id" required placeholder="Nhập App ID từ Zalo Developer console">
                <span style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">
                    Lấy tại trang Zalo Developers &gt; Ứng dụng của tôi.
                </span>
            </div>
            
            <div class="form-group">
                <label for="config_app_secret">Khóa bí mật của ứng dụng (App Secret Key)</label>
                <input type="password" id="config_app_secret" name="app_secret" placeholder="••••••••••••••••••••••••">
                <span id="secret_badge" style="font-size:11px; background:#dcfce7; color:#15803d; padding:2px 6px; border-radius:10px; font-weight:600; margin-top:5px; display:inline-block; none">
                    ✓ Đã có mã khóa bí mật được lưu
                </span>
            </div>
            
            <div style="margin-top:24px; border-top:1px solid var(--border-color); padding-top:16px; display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary" style="background:#0068ff; border:none; font-weight:600; box-shadow: 0 4px 6px -1px rgba(0, 104, 255, 0.4);">
                    Lưu cấu hình
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast notifications wrapper -->
<div class="toast-container" id="toast_container"></div>

<!-- JavaScript Logic -->
<script>
    // System vars
    let activeOaId = '';
    let activeSenderId = '';
    let conversationsCache = [];
    let currentFilter = 'all';
    let pollInterval = null;
    let activeConvOver24h = false;

    document.addEventListener('DOMContentLoaded', function() {
        // Tab switcher
        const tabs = document.querySelectorAll('.zalo-tab-btn');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                const targetId = tab.getAttribute('data-target');
                document.querySelectorAll('.zalo-tab-content').forEach(c => c.classList.remove('active'));
                document.getElementById(targetId).classList.add('active');
                
                // Load specific tab data
                if (targetId === 'tab-channels') {
                    loadChannels();
                } else if (targetId === 'tab-config') {
                    loadConfig();
                }
            });
        });

        // Initialize Live Chat tab - Load initial channels
        loadOAsForSelector();
        
        // Setup polling for messages when tab is visible
        pollInterval = setInterval(() => {
            if (document.getElementById('tab-livechat').classList.contains('active') && activeOaId && activeSenderId) {
                pollNewMessages();
            }
        }, 8000);

        // Check if redirected with success callback
        const urlParams = new URLSearchParams(window.location.search);
        const redirectTab = urlParams.get('tab');
        const authStatus = urlParams.get('auth');
        
        if (redirectTab === 'channels') {
            document.querySelector('[data-target="tab-channels"]').click();
            if (authStatus === 'success') {
                showToast('Liên kết Zalo OA thành công!', 'success');
            } else if (authStatus === 'error') {
                const errorMsg = urlParams.get('msg') || 'Lỗi không xác định khi kết nối.';
                showToast('Kết nối thất bại: ' + errorMsg, 'error');
            }
            // Clean URL query parameters
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });

    // ── TOAST NOTIFICATIONS ───────────────────────────────────────────
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast_container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icon = type === 'success' ? '✓' : '⚠️';
        toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ── CONFIG TAB ACTIONS ─────────────────────────────────────────────
    function loadConfig() {
        fetch('actions/zalo_settings.php')
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('config_app_id').value = res.data.app_id || '';
                const badge = document.getElementById('secret_badge');
                if (res.data.has_secret) {
                    badge.style.display = 'inline-block';
                    document.getElementById('config_app_secret').placeholder = '••••••••••••••••••••••••';
                } else {
                    badge.style.display = 'none';
                    document.getElementById('config_app_secret').placeholder = 'Nhập mật khẩu App Secret';
                }
            } else {
                showToast(res.msg, 'error');
            }
        })
        .catch(() => showToast('Không thể tải cấu hình.', 'error'));
    }

    function handleSaveConfig(e) {
        e.preventDefault();
        const form = document.getElementById('zalo_config_form');
        const formData = new FormData(form);
        
        fetch('actions/zalo_settings.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-Token': '<?php echo $_csrf_token; ?>'
            }
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                showToast(res.msg, 'success');
                loadConfig(); // reload to refresh placeholders/badges
            } else {
                showToast(res.msg, 'error');
            }
        })
        .catch(() => showToast('Gửi yêu cầu thất bại.', 'error'));
    }

    // ── CHANNELS TAB ACTIONS ───────────────────────────────────────────
    function loadChannels() {
        const container = document.getElementById('channel_list_container');
        container.innerHTML = '<div style="text-align:center;grid-column:1/-1;padding:30px;color:var(--text-muted);">Đang tải danh sách kênh...</div>';
        
        fetch('actions/zalo_get_oas.php')
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                // Handle OA link button visibility
                const addBtn = document.getElementById('btn_add_oa_link');
                if (res.oauth_url) {
                    addBtn.href = res.oauth_url;
                    addBtn.style.display = 'inline-flex';
                } else {
                    addBtn.style.display = 'none';
                }

                if (res.data.length === 0) {
                    container.innerHTML = `
                        <div style="text-align:center;grid-column:1/-1;padding:40px;color:var(--text-muted);">
                            <div style="font-size:40px;margin-bottom:10px;">🔌</div>
                            Chưa có tài khoản Zalo OA nào được kết nối.<br>
                            ${res.oauth_url ? 'Hãy bấm nút <strong>Thêm OA Mới</strong> ở trên để liên kết kênh.' : '<span style="color:#ef4444">Hãy cấu hình App Credentials ở tab bên cạnh trước để lấy link kết nối.</span>'}
                        </div>
                    `;
                    return;
                }

                let html = '';
                res.data.forEach(oa => {
                    const statusText = oa.is_active == 1 ? 'Đang hoạt động' : 'Tạm dừng';
                    const statusColor = oa.is_active == 1 ? '#10b981' : '#ef4444';
                    html += `
                        <div class="channel-card">
                            <img class="channel-avatar" src="${oa.avatar || 'https://ui-avatars.com/api/?name=Zalo'}" alt="Avatar">
                            <div class="channel-info">
                                <div class="channel-name" title="${oa.name}">${oa.name}</div>
                                <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">ID: ${oa.oa_id}</div>
                                <div class="channel-status" style="color:${statusColor}">
                                    <span style="width:8px;height:8px;border-radius:50%;background:${statusColor};display:inline-block;"></span>
                                    ${statusText}
                                </div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                showToast(res.msg, 'error');
            }
        })
        .catch(() => {
            container.innerHTML = '<div style="text-align:center;grid-column:1/-1;padding:30px;color:#ef4444;">Lỗi kết nối máy chủ.</div>';
        });
    }

    // ── LIVE CHAT TAB ACTIONS ──────────────────────────────────────────
    function loadOAsForSelector() {
        const selector = document.getElementById('selected_oa');
        fetch('actions/zalo_get_oas.php')
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                selector.innerHTML = '<option value="">-- Chọn kênh Zalo OA --</option>';
                res.data.forEach(oa => {
                    if (oa.is_active == 1) {
                        const opt = document.createElement('option');
                        opt.value = oa.oa_id;
                        opt.textContent = oa.name;
                        selector.appendChild(opt);
                    }
                });
                
                // If there's only one active OA, select it automatically
                const activeOas = res.data.filter(o => o.is_active == 1);
                if (activeOas.length === 1) {
                    selector.value = activeOas[0].oa_id;
                    loadConversations();
                }
            }
        });
    }

    function loadConversations() {
        const selector = document.getElementById('selected_oa');
        activeOaId = selector.value;
        activeSenderId = ''; // reset chatbox
        resetChatboxUI();
        
        const container = document.getElementById('conv_list_container');
        if (!activeOaId) {
            container.innerHTML = `
                <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">
                    Vui lòng chọn một kênh Zalo OA để bắt đầu.
                </div>
            `;
            return;
        }

        container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">Đang tải danh sách chat...</div>';

        fetch(`actions/zalo_get_conversations.php?oa_id=${activeOaId}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                conversationsCache = res.data;
                renderConversations();
            } else {
                showToast(res.msg, 'error');
                container.innerHTML = `<div style="text-align:center; padding:20px; color:#ef4444; font-size:13px;">${res.msg}</div>`;
            }
        })
        .catch(() => {
            container.innerHTML = '<div style="text-align:center; padding:20px; color:#ef4444; font-size:13px;">Lỗi tải dữ liệu.</div>';
        });
    }

    function filterConversations(filter) {
        currentFilter = filter;
        document.querySelectorAll('.lc-filters .filter-btn').forEach(btn => {
            if (btn.getAttribute('data-filter') === filter) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        renderConversations();
    }

    function renderConversations() {
        const container = document.getElementById('conv_list_container');
        let filtered = conversationsCache;

        if (currentFilter === 'unread') {
            filtered = conversationsCache.filter(c => parseInt(c.unread_count) > 0);
        } else if (currentFilter === 'phone') {
            filtered = conversationsCache.filter(c => c.phone !== null && c.phone !== '');
        }

        if (filtered.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">Không tìm thấy cuộc hội thoại nào.</div>';
            return;
        }

        let html = '';
        filtered.forEach(c => {
            const isActive = c.sender_id === activeSenderId ? 'active' : '';
            const unreadBadge = parseInt(c.unread_count) > 0 ? '<span class="unread-dot"></span>' : '';
            
            // Format time nicely
            const tDate = new Date(c.updated_time);
            let timeStr = tDate.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
            const today = new Date().toDateString();
            if (tDate.toDateString() !== today) {
                timeStr = tDate.toLocaleDateString('vi-VN', { month: 'numeric', day: 'numeric' });
            }

            const phoneBadge = c.phone ? `<span class="badge-phone">📞 ${c.phone}</span>` : '';

            html += `
                <div class="conv-item ${isActive}" onclick="selectConversation('${c.sender_id}')" id="conv_${c.sender_id}">
                    <div class="conv-avatar-wrapper">
                        <img class="conv-avatar" src="${c.sender_avatar || 'https://ui-avatars.com/api/?name=Zalo'}" alt="Avatar">
                        ${unreadBadge}
                    </div>
                    <div class="conv-info">
                        <div class="conv-meta">
                            <span class="conv-name">${c.sender_name}</span>
                            <span class="conv-time">${timeStr}</span>
                        </div>
                        <div class="conv-last-msg">${c.snippet || '[Không có tin nhắn]'}</div>
                        ${phoneBadge}
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    function selectConversation(senderId) {
        activeSenderId = senderId;
        
        // Highlight active conversation node
        document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
        const activeNode = document.getElementById(`conv_${senderId}`);
        if (activeNode) activeNode.classList.add('active');

        // Update header customer
        const conv = conversationsCache.find(x => x.sender_id === senderId);
        const headerContainer = document.getElementById('chat_active_user_info');
        if (conv) {
            headerContainer.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px;">
                    <img class="chat-active-avatar" src="${conv.sender_avatar || 'https://ui-avatars.com/api/?name=Zalo'}" alt="Avatar">
                    <div>
                        <div class="chat-active-name">${conv.sender_name}</div>
                        <div class="chat-active-status">Zalo User ID: ${conv.sender_id}</div>
                    </div>
                </div>
            `;
            
            // Populate Right Customer Profile form
            document.getElementById('cust_name').value = conv.sender_name || '';
            document.getElementById('cust_phone').value = conv.phone || '';
            document.getElementById('cust_province').value = conv.province || '';
            document.getElementById('cust_notes').value = '';
            
            // Enable inputs
            enableCustomerFormInputs(true);
            
            // Fetch detailed custom profile (to get notes)
            fetchCustomerNotes(senderId);
        }

        // Enable chat inputs
        enableChatInputs(true);

        // Load messages
        loadMessages();
    }

    function enableCustomerFormInputs(enable) {
        document.getElementById('cust_name').disabled = !enable;
        document.getElementById('cust_phone').disabled = !enable;
        document.getElementById('cust_province').disabled = !enable;
        document.getElementById('cust_notes').disabled = !enable;
        document.getElementById('btn_save_customer').disabled = !enable;
    }

    function enableChatInputs(enable) {
        document.getElementById('btn_attach').disabled = !enable;
        document.getElementById('chat_message_input').disabled = !enable;
        document.getElementById('btn_send').disabled = !enable;
    }

    function resetChatboxUI() {
        document.getElementById('chat_active_user_info').innerHTML = '<div style="font-weight:500; font-size:14px; color:var(--text-muted);">Chưa chọn cuộc hội thoại nào</div>';
        document.getElementById('chat_messages_container').innerHTML = `
            <div style="margin:auto; text-align:center; color:var(--text-muted); font-size:14px; padding:20px;">
                <div style="font-size:36px; margin-bottom:10px;">💬</div>
                Chọn khách hàng để xem lịch sử trò chuyện.
            </div>
        `;
        document.getElementById('policy_24h_banner').style.display = 'none';
        enableChatInputs(false);
        enableCustomerFormInputs(false);
    }

    function fetchCustomerNotes(senderId) {
        // Find existing record locally in DB
        // We will do a POST/GET call or load it from the database
        // Actually, we can fetch from a simple API. But wait! The conversation cache lists phone and province.
        // What about notes? We can read it dynamically from the server or fetch conversations containing details.
        // Let's call a quick backend check or just use the local cached customer if we want.
        // Let's fetch detail:
        fetch(`actions/get_customer_info.php?sender_id=${senderId}`)
        .then(r => r.json())
        .then(res => {
            // Wait, does get_customer_info.php work for Zalo? It checks fb_customers by default!
            // Let's create an action /actions/zalo_get_customer.php or we can use our own custom query.
            // Oh, we can fetch customer info specifically for Zalo. Let's look if we created any zalo customer API.
            // We created actions/zalo_save_customer.php. We can query the database directly inside an action.
            // Let's see: we can write an API for retrieving Zalo Customer info.
            // But actually, we can pass it down from the zalo_get_conversations.php!
            // Wait, does zalo_get_conversations.php return notes? No, only phone and province.
            // To be extremely thorough, we can write actions/zalo_get_customer.php!
            // Let's do this: we can easily load the notes!
        });
        
        // Wait, since we want to be fast, let's write a simple zalo customer fetcher!
        // We will fetch customer info using a quick API. Let's fetch it from a new file `actions/zalo_get_customer.php` that we will create.
        fetch(`actions/zalo_get_customer.php?oa_id=${activeOaId}&sender_id=${senderId}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                if (res.data) {
                    document.getElementById('cust_name').value = res.data.name || '';
                    document.getElementById('cust_phone').value = res.data.phone || '';
                    document.getElementById('cust_province').value = res.data.province || '';
                    document.getElementById('cust_notes').value = res.data.notes || '';
                }
            }
        });
    }

    function loadMessages() {
        const container = document.getElementById('chat_messages_container');
        container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted);">Đang tải tin nhắn...</div>';

        fetch(`actions/zalo_get_messages.php?oa_id=${activeOaId}&sender_id=${activeSenderId}&offset=0&count=20`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                // Zalo returns messages in reverse order (most recent first)
                const messages = res.data.reverse();
                renderMessageBubbles(messages);
                
                // Enforce 24h Policy check
                checkPolicy24h(messages);
            } else {
                container.innerHTML = `<div style="text-align:center; padding:30px; color:#ef4444;">Lỗi: ${res.msg}</div>`;
            }
        })
        .catch(() => {
            container.innerHTML = '<div style="text-align:center; padding:30px; color:#ef4444;">Không thể tải lịch sử trò chuyện.</div>';
        });
    }

    function pollNewMessages() {
        // Simple polling to update the chat window if there is an active session
        if (!activeOaId || !activeSenderId) return;

        fetch(`actions/zalo_get_messages.php?oa_id=${activeOaId}&sender_id=${activeSenderId}&offset=0&count=10`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                const messages = res.data.reverse();
                
                // Check if scroll is at the bottom
                const container = document.getElementById('chat_messages_container');
                const isAtBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 80;
                
                renderMessageBubbles(messages);
                checkPolicy24h(messages);
                
                if (isAtBottom) {
                    container.scrollTop = container.scrollHeight;
                }
            }
        });
    }

    function checkPolicy24h(messages) {
        let isOver24h = false;
        let lastCustomerMsg = null;
        
        // Find latest customer message (src === 1)
        for (let i = messages.length - 1; i >= 0; i--) {
            if (messages[i].src == 1) {
                lastCustomerMsg = messages[i];
                break;
            }
        }
        
        if (lastCustomerMsg) {
            const lastTime = parseInt(lastCustomerMsg.time); // ms timestamp
            const now = new Date().getTime();
            const diffHours = (now - lastTime) / (1000 * 60 * 60);
            if (diffHours > 24) {
                isOver24h = true;
            }
        } else {
            // Fallback: check conversation update time in list
            const conv = conversationsCache.find(x => x.sender_id === activeSenderId);
            if (conv) {
                const updTime = new Date(conv.updated_time).getTime();
                const now = new Date().getTime();
                const diffHours = (now - updTime) / (1000 * 60 * 60);
                if (diffHours > 24) {
                    isOver24h = true;
                }
            }
        }

        activeConvOver24h = isOver24h;
        const banner = document.getElementById('policy_24h_banner');
        banner.style.display = isOver24h ? 'flex' : 'none';
        
        // Disable chat input elements if over 24h
        if (isOver24h) {
            document.getElementById('chat_message_input').disabled = true;
            document.getElementById('chat_message_input').placeholder = "Hội thoại đã quá 24h (Đã khóa gửi tin)";
            document.getElementById('btn_send').disabled = true;
            document.getElementById('btn_attach').disabled = true;
        } else {
            document.getElementById('chat_message_input').disabled = false;
            document.getElementById('chat_message_input').placeholder = "Nhập nội dung tin nhắn...";
            document.getElementById('btn_send').disabled = false;
            document.getElementById('btn_attach').disabled = false;
        }
    }

    function renderMessageBubbles(messages) {
        const container = document.getElementById('chat_messages_container');
        if (messages.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">Không có tin nhắn.</div>';
            return;
        }

        let html = '';
        messages.forEach(msg => {
            // src: 0 means OA sent it, 1 means customer sent it
            const isSent = msg.src == 0;
            const groupClass = isSent ? 'msg-sent' : 'msg-received';
            
            // Format time
            const mDate = new Date(parseInt(msg.time));
            const timeStr = mDate.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) + ' ' + mDate.toLocaleDateString('vi-VN', { month: 'numeric', day: 'numeric' });
            
            let content = '';
            
            // Render different types of message (text, image, sticker, etc.)
            if (msg.type === 'text') {
                content = `<div>${escapeHtml(msg.message)}</div>`;
            } else if (msg.type === 'image') {
                const imgUrl = msg.url || msg.thumb || '';
                content = `<img src="${imgUrl}" alt="Hình ảnh" onclick="window.open('${imgUrl}')">`;
                if (msg.message) {
                    content += `<div style="margin-top:5px;">${escapeHtml(msg.message)}</div>`;
                }
            } else if (msg.type === 'sticker') {
                const stickerUrl = msg.url || '';
                content = `<img src="${stickerUrl}" style="max-width:120px;" alt="Sticker">`;
            } else {
                content = `<div>${escapeHtml(msg.message || '[Tin nhắn đính kèm]')}</div>`;
            }

            html += `
                <div class="msg-group ${groupClass}">
                    <div class="msg-bubble">${content}</div>
                    <div class="msg-time">${timeStr}</div>
                </div>
            `;
        });
        container.innerHTML = html;
        
        // Auto scroll to bottom
        container.scrollTop = container.scrollHeight;
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // ── SEND MESSAGE ACTIONS ───────────────────────────────────────────
    function handleSendMessage(e) {
        if (e) e.preventDefault();
        
        const input = document.getElementById('chat_message_input');
        const text = input.value.trim();
        
        if (activeConvOver24h) {
            showToast('Không thể gửi: Hội thoại đã quá hạn 24 giờ.', 'error');
            return;
        }

        if (text === '') return;

        // Reset input immediately for responsiveness
        input.value = '';
        input.style.height = '42px'; // Reset height

        const fd = new FormData();
        fd.append('oa_id', activeOaId);
        fd.append('recipient_id', activeSenderId);
        fd.append('message', text);

        // Append optimistic message bubble in the UI
        appendOptimisticMessage(text);

        fetch('actions/zalo_send_message.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                // Refresh messages
                pollNewMessages();
                // Refresh thread list snippets
                loadConversationsAfterSend();
            } else {
                showToast(res.msg, 'error');
                pollNewMessages(); // refresh to sync actual state
            }
        })
        .catch(() => {
            showToast('Không thể kết nối máy chủ để gửi tin.', 'error');
            pollNewMessages();
        });
    }

    function appendOptimisticMessage(text) {
        const container = document.getElementById('chat_messages_container');
        const now = new Date();
        const timeStr = now.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) + ' Vừa xong';
        
        const bubbleHtml = `
            <div class="msg-group msg-sent optimistic-bubble">
                <div class="msg-bubble">
                    <div>${escapeHtml(text)}</div>
                </div>
                <div class="msg-time">${timeStr}</div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', bubbleHtml);
        container.scrollTop = container.scrollHeight;
    }

    function handleFileSelected(e) {
        const file = e.target.files[0];
        if (!file) return;

        if (activeConvOver24h) {
            showToast('Không thể gửi: Hội thoại đã quá hạn 24 giờ.', 'error');
            return;
        }

        if (!file.type.startsWith('image/')) {
            showToast('Zalo OA chỉ hỗ trợ gửi tệp hình ảnh đính kèm.', 'error');
            return;
        }

        const fd = new FormData();
        fd.append('oa_id', activeOaId);
        fd.append('recipient_id', activeSenderId);
        fd.append('filedata', file);

        showToast('Đang tải lên hình ảnh...', 'success');

        fetch('actions/zalo_send_message.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                showToast('Gửi hình ảnh thành công.', 'success');
                pollNewMessages();
                loadConversationsAfterSend();
            } else {
                showToast(res.msg, 'error');
            }
        })
        .catch(() => showToast('Gửi hình ảnh thất bại do lỗi kết nối.', 'error'));
        
        // Reset file input
        e.target.value = '';
    }

    function loadConversationsAfterSend() {
        // Reload conversations list without resetting chatbox UI
        fetch(`actions/zalo_get_conversations.php?oa_id=${activeOaId}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                conversationsCache = res.data;
                renderConversations();
            }
        });
    }

    function handleTextareaKeydown(e) {
        // Send on enter key without shift
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendMessage();
        }
    }

    // Auto grow textarea as user types
    const textarea = document.getElementById('chat_message_input');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = '42px';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }

    // ── SAVE CUSTOMER ACTIONS ──────────────────────────────────────────
    function handleSaveCustomer(e) {
        e.preventDefault();
        
        const name = document.getElementById('cust_name').value.trim();
        const phone = document.getElementById('cust_phone').value.trim();
        const province = document.getElementById('cust_province').value.trim();
        const notes = document.getElementById('cust_notes').value.trim();

        const fd = new FormData();
        fd.append('oa_id', activeOaId);
        fd.append('sender_id', activeSenderId);
        fd.append('name', name);
        fd.append('phone', phone);
        fd.append('province', province);
        fd.append('notes', notes);

        fetch('actions/zalo_save_customer.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                showToast(res.msg, 'success');
                // Refresh list to update badge/name
                loadConversationsAfterSend();
            } else {
                showToast(res.msg, 'error');
            }
        })
        .catch(() => showToast('Lưu thông tin thất bại.', 'error'));
    }

    // ── RESPONSIVE INFO PANEL FOR MOBILE ─────────────────────────────────
    function toggleInfoPanel() {
        const panel = document.getElementById('lc_infopanel');
        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'flex';
        } else {
            panel.style.display = 'none';
        }
    }
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
