<?php
// live-chat-oa.php
$current_page = 'live_chat_zalo';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];

// 1. Fetch FB Pages
$stmt_fb = $pdo->prepare("
    (SELECT p.page_id, p.name, p.avatar, 'Facebook' AS user_name
     FROM pages p JOIN users u ON p.user_id = u.id
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.page_id, p.name, p.avatar, 'Facebook' AS user_name
     FROM pages p
     JOIN page_shares ps ON p.page_id = ps.page_id
     JOIN users u ON p.user_id = u.id
     WHERE ps.shared_with_account_id = :aid2)
");
$stmt_fb->bindValue(':aid',  $account_id, PDO::PARAM_INT);
$stmt_fb->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt_fb->execute();
$fb_pages = $stmt_fb->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Zalo OAs
$stmt_zalo = $pdo->prepare("
    SELECT oa_id AS page_id, name, avatar, 'Zalo' AS user_name
    FROM zalo_oas
    WHERE account_id = :aid AND is_active = 1
");
$stmt_zalo->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt_zalo->execute();
$zalo_oas_list = $stmt_zalo->fetchAll(PDO::FETCH_ASSOC);

// 3. Merge lists
$pages = array_merge($fb_pages, $zalo_oas_list);
$pages_json = json_encode($pages);
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
<style>
    .platform-tab-btn:hover {
        color: #0068ff !important;
        border-bottom-color: #cbd5e1 !important;
    }
    .platform-tab-btn.active:hover {
        border-bottom-color: #0068ff !important;
    }
</style>

<!-- Platform Switcher Tabs -->
<div class="platform-tabs" style="display: flex; gap: 20px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; padding-bottom: 0;">
    <a href="live_chat.php" class="platform-tab-btn <?php echo ($current_page === 'live_chat') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'live_chat') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'live_chat') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>📘</span> Facebook Fanpage
    </a>
    <a href="live-chat-oa.php" class="platform-tab-btn <?php echo ($current_page === 'live_chat_zalo') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'live_chat_zalo') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'live_chat_zalo') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>💬</span> Zalo Official Account
    </a>
</div>

<!-- Page Header Title -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
    <div class="page-title" style="margin-bottom:0;">💬 Zalo OA Live Chat & Kênh</div>
    <button onclick="openBotSettings()" class="btn btn-secondary" style="background:#f59e0b; color:#fff; border:none; display:flex; align-items:center; gap:5px; font-weight:600;"><span style="font-size:16px;">⚙️</span> Cài đặt Bot Tự Động</button>
</div>

<!-- 4 Tabs Navigation -->
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
    <button class="zalo-tab-btn" data-target="tab-bot-settings">
        <span>🤖</span> Bot Tự Động
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
                <button type="button" id="btn_refresh_profile" onclick="handleRefreshZaloProfile()" class="btn" style="width:100%; font-weight:600; background:#f3f4f6; border:1px solid #d1d5db; color:#374151; margin-bottom:15px; display:flex; align-items:center; justify-content:center; gap:6px; font-size:13px;" disabled>
                    🔄 Đồng bộ Zalo Profile
                </button>
                
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
                <span id="secret_badge" style="font-size:11px; background:#dcfce7; color:#15803d; padding:2px 6px; border-radius:10px; font-weight:600; margin-top:5px; display:none;">
                    ✓ Đã có mã khóa bí mật ứng dụng được lưu
                </span>
            </div>
            
            <div class="form-group" style="margin-top:15px;">
                <label for="config_oa_secret">Khóa bí mật Webhook (OA Secret Key)</label>
                <input type="password" id="config_oa_secret" name="oa_secret" placeholder="••••••••••••••••••••••••">
                <span id="oa_secret_badge" style="font-size:11px; background:#dcfce7; color:#15803d; padding:2px 6px; border-radius:10px; font-weight:600; margin-top:5px; display:none;">
                    ✓ Đã có mã khóa bí mật Webhook (OA Secret Key) được lưu
                </span>
                <span style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">
                    Lấy tại trang Cấu hình Webhook của OA trong Zalo Developer Console (Ví dụ: Nggrp6rmCR60EeDM3U6I).
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

<!-- ==================== TAB 4: BOT TỰ ĐỘNG ==================== -->
<div id="tab-bot-settings" class="zalo-tab-content">
    <div class="card" style="max-width:800px; margin:0 auto;">
        <div style="font-weight:600; font-size:17px; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:20px;">
            🤖 Cài đặt Bot Tự Động (Zalo OA & Fanpage)
        </div>
        
        <!-- Bot Tabs -->
        <div style="display:flex; gap:10px; margin-bottom:15px; border-bottom:1px solid var(--border-color); padding-bottom:10px;">
            <button id="tab_welcome" onclick="switchBotTab('welcome')" style="padding:8px 16px; border:none; background:#0068ff; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;">Tin nhắn chào mừng</button>
            <button id="tab_keyword" onclick="switchBotTab('keyword')" style="padding:8px 16px; border:none; background:#f3f4f6; color:#374151; border-radius:6px; cursor:pointer; font-weight:600;">Tin nhắn theo từ khóa</button>
            <button id="tab_ai_reply" onclick="switchBotTab('ai_reply')" style="padding:8px 16px; border:none; background:#f3f4f6; color:#374151; border-radius:6px; cursor:pointer; font-weight:600;">Chat bot AI tự trả lời</button>
        </div>

        <!-- Nội dung Tab 1: Tin nhắn chào mừng -->
        <div id="content_welcome">
            <p style="font-size:13px; color:var(--text-muted); margin-top:0; text-align:left; margin-bottom:12px;">Tin nhắn sẽ tự động gửi khi khách hàng gửi tin nhắn đầu tiên.</p>
            <div id="welcome_rules_list" style="margin-bottom:15px; max-height:350px; overflow-y:auto;">
                <div style="text-align:center; padding:10px; color:var(--text-muted); font-size:13px;">Đang tải...</div>
            </div>
            <button onclick="openRuleForm('welcome')" class="btn btn-secondary" style="width:100%; text-align:center; display:block; padding:8px; border:1px dashed var(--border-color); background:var(--bg-color); color:var(--text-main); border-radius:6px; font-weight:600;">+ Thêm cấu hình chào mừng mới</button>
        </div>

        <!-- Nội dung Tab 2: Tin nhắn theo từ khóa -->
        <div id="content_keyword" style="display:none;">
            <p style="font-size:13px; color:var(--text-muted); margin-top:0; text-align:left; margin-bottom:12px;">Tin nhắn sẽ tự động gửi nếu câu nói của khách hàng có chứa các từ khóa dưới đây.</p>
            <div id="keyword_rules_list" style="margin-bottom:15px; max-height:350px; overflow-y:auto;">
                <div style="text-align:center; padding:10px; color:var(--text-muted); font-size:13px;">Đang tải...</div>
            </div>
            <button onclick="openRuleForm('keyword')" class="btn btn-secondary" style="width:100%; text-align:center; display:block; padding:8px; border:1px dashed var(--border-color); background:var(--bg-color); color:var(--text-main); border-radius:6px; font-weight:600;">+ Thêm cấu hình từ khóa mới</button>
        </div>

        <!-- Nội dung Tab 3: Chat bot AI tự trả lời -->
        <div id="content_ai_reply" style="display:none;">
            <p style="font-size:13px; color:var(--text-muted); margin-top:0; text-align:left; margin-bottom:12px;">Bot sẽ sử dụng AI để tự động trả lời khách hàng dựa trên Prompt bạn cấu hình. <b>Lưu ý:</b> Cần cài đặt API Key AI ở trang Cài đặt AI.</p>
            <div id="ai_reply_rules_list" style="margin-bottom:15px; max-height:350px; overflow-y:auto;">
                <div style="text-align:center; padding:10px; color:var(--text-muted); font-size:13px;">Đang tải...</div>
            </div>
            <button onclick="openRuleForm('ai_reply')" class="btn btn-secondary" style="width:100%; text-align:center; display:block; padding:8px; border:1px dashed var(--border-color); background:var(--bg-color); color:var(--text-main); border-radius:6px; font-weight:600;">+ Thêm cấu hình Bot AI mới</button>
        </div>
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
                } else if (targetId === 'tab-bot-settings') {
                    switchBotTab('welcome');
                    loadBotRules();
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
                    document.getElementById('config_app_secret').value = '••••••••••••••••••••••••';
                } else {
                    badge.style.display = 'none';
                    document.getElementById('config_app_secret').value = '';
                    document.getElementById('config_app_secret').placeholder = 'Nhập mật khẩu App Secret';
                }

                const oaBadge = document.getElementById('oa_secret_badge');
                if (res.data.has_oa_secret) {
                    oaBadge.style.display = 'inline-block';
                    document.getElementById('config_oa_secret').value = '••••••••••••••••••••••••';
                } else {
                    oaBadge.style.display = 'none';
                    document.getElementById('config_oa_secret').value = '';
                    document.getElementById('config_oa_secret').placeholder = 'Nhập OA Secret Key';
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
                
                // Check if redirected with specific oa_id and sender_id in URL
                const urlParams = new URLSearchParams(window.location.search);
                const queryOaId = urlParams.get('oa_id');
                const querySenderId = urlParams.get('sender_id');
                
                if (queryOaId) {
                    selector.value = queryOaId;
                    loadConversations(querySenderId);
                } else {
                    // If there's only one active OA, select it automatically
                    const activeOas = res.data.filter(o => o.is_active == 1);
                    if (activeOas.length === 1) {
                        selector.value = activeOas[0].oa_id;
                        loadConversations();
                    }
                }
            }
        });
    }

    function loadConversations(autoSelectSenderId = '') {
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
                
                // Auto select a specific customer chat if passed
                if (autoSelectSenderId) {
                    selectConversation(autoSelectSenderId);
                    // Clear the URL query parameters so page refresh behaves normally
                    const newUrl = window.location.pathname;
                    window.history.replaceState({}, document.title, newUrl);
                }
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
        
        const refreshBtn = document.getElementById('btn_refresh_profile');
        if (refreshBtn) refreshBtn.disabled = !enable;
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

        fetch(`actions/zalo_get_messages.php?oa_id=${activeOaId}&sender_id=${activeSenderId}&offset=0&count=10`)
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
            } else if (msg.type === 'image' || msg.type === 'photo') {
                const imgUrl = msg.url || msg.thumb || '';
                content = `<img src="${imgUrl}" alt="Hình ảnh" onclick="window.open('${imgUrl}')" style="max-width:100%; max-height:300px; border-radius:8px; cursor:pointer; margin-top:5px;">`;
                const imgDesc = msg.description || msg.message || '';
                if (imgDesc) {
                    content += `<div style="margin-top:5px;">${escapeHtml(imgDesc)}</div>`;
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

    window.handleRefreshZaloProfile = function() {
        if (!activeOaId || !activeSenderId) return;
        
        const btn = document.getElementById('btn_refresh_profile');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '🔄 Đang đồng bộ...';
        
        fetch(`actions/zalo_refresh_customer.php?oa_id=${activeOaId}&sender_id=${activeSenderId}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                showToast(res.msg, 'success');
                if (res.data) {
                    document.getElementById('cust_name').value = res.data.name || '';
                    document.getElementById('cust_province').value = res.data.province || '';
                    
                    // Reload conversations list to update names/avatars in left panel
                    loadConversationsAfterSend();
                    
                    // Reload active header user name and avatar
                    const activeHeader = document.getElementById('chat_active_user_info');
                    if (activeHeader) {
                        const avatarUrl = res.data.avatar || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(res.data.name);
                        activeHeader.innerHTML = `
                            <img src="${avatarUrl}" class="chat-active-avatar">
                            <div>
                                <div class="chat-active-name">${escapeHtml(res.data.name)}</div>
                                <div class="chat-active-status">Zalo User ID: ${activeSenderId}</div>
                            </div>
                        `;
                    }
                }
            } else {
                showToast(res.msg, 'error');
            }
        })
        .catch(() => showToast('Gửi yêu cầu đồng bộ thất bại.', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = origText;
        });
    }

    // ── BOT SETTINGS JS LOGIC ──────────────────────────────────────────
    const allPages = <?php echo $pages_json; ?>;
    let botRules = [];

    window.openBotSettings = function() {
        const tabBtn = document.querySelector('[data-target="tab-bot-settings"]');
        if (tabBtn) {
            tabBtn.click();
        }
    }

    window.switchBotTab = function(tab) {
        const tabs = ['welcome', 'keyword', 'ai_reply'];
        tabs.forEach(t => {
            const btn = document.getElementById('tab_' + t);
            const content = document.getElementById('content_' + t);
            if(btn && content) {
                if (t === tab) {
                    btn.style.background = '#0068ff';
                    btn.style.color = '#fff';
                    content.style.display = 'block';
                } else {
                    btn.style.background = '#f3f4f6';
                    btn.style.color = '#374151';
                    content.style.display = 'none';
                }
            }
        });
    }

    window.loadBotRules = function() {
        fetch('actions/manage_bot_rules.php?action=list')
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    botRules = res.data;
                    renderBotRules('welcome');
                    renderBotRules('keyword');
                    renderBotRules('ai_reply');
                } else {
                    showToast('Lỗi tải cấu hình bot: ' + res.msg, 'error');
                }
            }).catch(e => console.error(e));
    }

    window.renderBotRules = function(type) {
        const listDiv = document.getElementById(type + '_rules_list');
        const rules = botRules.filter(r => r.rule_type === type);
        
        if (rules.length === 0) {
            listDiv.innerHTML = '<div style="padding:15px; text-align:center; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; color:#6b7280; font-size:13px;">Chưa có cấu hình nào.</div>';
            return;
        }
        
        let html = '';
        rules.forEach(r => {
            let pageStr = r.pages_scope === 'ALL' ? '<span style="color:#0068ff;font-weight:600;">Tất cả kênh</span>' : '<span style="color:#10b981;font-weight:600;">Một số kênh</span>';
            if(r.pages_scope !== 'ALL') {
                try {
                    const scopeArr = JSON.parse(r.pages_scope);
                    if (Array.isArray(scopeArr)) {
                        let pNames = scopeArr.map(id => {
                            const p = allPages.find(x => x.page_id == id);
                            return p ? p.name : id;
                        });
                        if (scopeArr.length === 1) {
                            pageStr = '<span style="color:#10b981;font-weight:600;" title="'+pNames[0]+'">1 kênh</span>';
                        } else {
                            pageStr = '<span style="color:#10b981;font-weight:600; cursor:help;" title="'+pNames.join(', ')+'">'+scopeArr.length+' kênh</span>';
                        }
                    }
                } catch(e) {}
            }
            let activeStr = parseInt(r.is_active) === 1 ? '<span style="background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;">Bật</span>' : '<span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;">Tắt</span>';
            
            let timeStr = '';
            if (r.active_time_type === 'CUSTOM') {
                let start = (r.active_start_time || '17:00').substring(0, 5);
                let end = (r.active_end_time || '07:00').substring(0, 5);
                timeStr = `<span style="background:#fff7ed;color:#c2410c;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;margin-left:5px;">🕒 ${start} - ${end}</span>`;
            } else {
                timeStr = `<span style="background:#f0fdf4;color:#16a34a;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;margin-left:5px;">🕒 Cả ngày</span>`;
            }
            
            let kwHtml = type === 'keyword' ? `<div style="font-size:12px; font-weight:600; color:#b91c1c; margin-bottom:4px;">Từ khóa: ${r.keywords}</div>` : '';
            
            html += `<div style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px; margin-bottom:10px; position:relative; color:#374151;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div style="flex:1; min-width:0; text-align:left;">
                        ${activeStr} ${timeStr} <span style="font-size:11px; color:#6b7280; margin-left:5px;">Áp dụng: ${pageStr}</span>
                        <div style="margin-top:6px;">
                            ${kwHtml}
                            <div style="font-size:13px; color:#374151; white-space:pre-wrap; background:#f3f4f6; padding:8px; border-radius:4px;">${r.message}</div>
                        </div>
                    </div>
                    <div style="display:flex; gap:6px; margin-left:10px;">
                        <button onclick="editBotRule(${r.id})" style="background:none; border:none; font-size:14px; cursor:pointer; color:#0068ff;" title="Sửa">✏️</button>
                        <button onclick="deleteBotRule(${r.id})" style="background:none; border:none; font-size:14px; cursor:pointer; color:#ef4444;" title="Xóa">🗑️</button>
                    </div>
                </div>
            </div>`;
        });
        listDiv.innerHTML = html;
    }

    window.openRuleForm = function(type, rule = null) {
        document.getElementById('frm_bot_rule').reset();
        document.getElementById('rule_type').value = type;
        document.getElementById('rule_id').value = rule ? rule.id : 0;
        
        if (type === 'keyword') {
            document.getElementById('rule_keyword_group').style.display = 'block';
            document.getElementById('rule_keywords').required = true;
        } else {
            document.getElementById('rule_keyword_group').style.display = 'none';
            document.getElementById('rule_keywords').required = false;
        }

        if (type === 'ai_reply') {
            document.getElementById('rule_delay_group').style.display = 'block';
            document.getElementById('lbl_rule_message').innerHTML = 'Prompt cho AI <span style="color:red;">*</span>';
            document.getElementById('rule_message').placeholder = 'Ví dụ: Bạn là một chuyên gia tư vấn. Hãy trả lời ngắn gọn, lịch sự...';
            document.getElementById('hint_rule_message').innerHTML = 'Nhập prompt chi tiết để AI có thể tự động đọc và trả lời khách hàng.';
        } else {
            document.getElementById('rule_delay_group').style.display = 'none';
            document.getElementById('lbl_rule_message').innerHTML = 'Nội dung phản hồi <span style="color:red;">*</span>';
            document.getElementById('rule_message').placeholder = 'Nhập tin nhắn...';
            document.getElementById('hint_rule_message').innerHTML = 'Bạn có thể dùng {name} để gọi tên khách. Mỗi dòng 1 mẫu câu để chọn ngẫu nhiên.';
        }

        if (rule) {
            document.getElementById('rule_form_title').innerText = 'Sửa cấu hình';
            document.getElementById('rule_keywords').value = rule.keywords || '';
            document.getElementById('rule_message').value = rule.message || '';
            document.getElementById('rule_delay_seconds').value = rule.delay_seconds !== undefined ? rule.delay_seconds : 10;
            document.getElementById('rule_history_count').value = rule.history_count !== undefined ? rule.history_count : 6;
            
            const timeType = rule.active_time_type || 'ALL_DAY';
            document.getElementById('rule_active_time_type').value = timeType;
            let start = rule.active_start_time || '17:00';
            if (start.split(':').length === 3) start = start.substring(0, 5);
            let end = rule.active_end_time || '07:00';
            if (end.split(':').length === 3) end = end.substring(0, 5);
            document.getElementById('rule_active_start_time').value = start;
            document.getElementById('rule_active_end_time').value = end;
            toggleActiveTimeFields();

            if (rule.pages_scope === 'ALL') {
                document.getElementById('rule_pages_all').checked = true;
                document.querySelectorAll('.rule_page_cb').forEach(el => el.checked = true);
            } else {
                document.getElementById('rule_pages_all').checked = false;
                document.querySelectorAll('.rule_page_cb').forEach(el => el.checked = false);
                try {
                    const arr = JSON.parse(rule.pages_scope);
                    document.querySelectorAll('.rule_page_cb').forEach(el => {
                        if(arr.includes(el.value)) el.checked = true;
                    });
                    checkSelectAll();
                } catch(e) {}
            }
            document.getElementById('rule_is_active').checked = parseInt(rule.is_active) === 1;
        } else {
            let title = 'Thêm cấu hình';
            if(type === 'welcome') title = 'Thêm Lời chào';
            if(type === 'keyword') title = 'Thêm Từ khóa';
            if(type === 'ai_reply') title = 'Thêm Bot AI';
            document.getElementById('rule_form_title').innerText = title;
            document.getElementById('rule_message').value = '';
            document.getElementById('rule_keywords').value = '';
            document.getElementById('rule_delay_seconds').value = '10';
            document.getElementById('rule_history_count').value = '6';
            document.getElementById('rule_pages_all').checked = true;
            document.querySelectorAll('.rule_page_cb').forEach(el => el.checked = true);
            document.getElementById('rule_is_active').checked = true;
            
            document.getElementById('rule_active_time_type').value = 'ALL_DAY';
            document.getElementById('rule_active_start_time').value = '17:00';
            document.getElementById('rule_active_end_time').value = '07:00';
            toggleActiveTimeFields();
        }

        document.getElementById('botRuleFormModal').style.display = 'flex';
    }

    window.toggleActiveTimeFields = function() {
        const val = document.getElementById('rule_active_time_type').value;
        document.getElementById('rule_active_time_range').style.display = (val === 'CUSTOM') ? 'flex' : 'none';
    }

    window.editBotRule = function(id) {
        const r = botRules.find(x => x.id == id);
        if(r) openRuleForm(r.rule_type, r);
    }

    window.deleteBotRule = function(id) {
        if(!confirm('Bạn có chắc muốn xóa cấu hình này?')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fetch('actions/manage_bot_rules.php', { method:'POST', body:fd })
            .then(r=>r.json()).then(res=>{
                if(res.status==='success') loadBotRules();
                else showToast(res.msg, 'error');
            });
    }

    window.filterPagesByUser = function() {
        const user = document.getElementById('filter_user').value;
        const labels = document.querySelectorAll('.page_item_label');
        
        labels.forEach(lbl => {
            if (user === 'ALL' || lbl.getAttribute('data-user') === user) {
                lbl.style.display = 'flex';
            } else {
                lbl.style.display = 'none';
            }
        });
        checkSelectAll();
    }

    window.toggleAllPages = function(cb) {
        const labels = document.querySelectorAll('.page_item_label');
        labels.forEach(lbl => {
            if (lbl.style.display !== 'none') {
                lbl.querySelector('.rule_page_cb').checked = cb.checked;
            }
        });
        checkSelectAll();
    }

    window.checkSelectAll = function() {
        let totalVisible = 0;
        let checkedVisible = 0;
        
        const labels = document.querySelectorAll('.page_item_label');
        labels.forEach(lbl => {
            if (lbl.style.display !== 'none') {
                totalVisible++;
                if (lbl.querySelector('.rule_page_cb').checked) {
                    checkedVisible++;
                }
            }
        });
        
        document.getElementById('rule_pages_all').checked = (totalVisible > 0 && totalVisible === checkedVisible);
    }

    window.saveBotRule = function(e) {
        e.preventDefault();
        const btn = document.getElementById('btn_save_rule');
        
        const totalCbs = document.querySelectorAll('.rule_page_cb');
        const checkedCbs = document.querySelectorAll('.rule_page_cb:checked');
        
        let pagesScope = 'ALL';
        if (totalCbs.length !== checkedCbs.length) {
            const checkedVals = Array.from(checkedCbs).map(el => el.value);
            pagesScope = JSON.stringify(checkedVals);
            if (checkedVals.length === 0) {
                showToast('Vui lòng chọn ít nhất 1 trang hoặc kênh!', 'error');
                return;
            }
        }

        btn.disabled = true; btn.innerText = 'Đang lưu...';
        
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('id', document.getElementById('rule_id').value);
        fd.append('rule_type', document.getElementById('rule_type').value);
        fd.append('keywords', document.getElementById('rule_keywords').value);
        fd.append('message', document.getElementById('rule_message').value);
        fd.append('delay_seconds', document.getElementById('rule_delay_seconds').value);
        fd.append('history_count', document.getElementById('rule_history_count').value);
        fd.append('active_time_type', document.getElementById('rule_active_time_type').value);
        fd.append('active_start_time', document.getElementById('rule_active_start_time').value);
        fd.append('active_end_time', document.getElementById('rule_active_end_time').value);
        fd.append('pages_scope', pagesScope);
        fd.append('is_active', document.getElementById('rule_is_active').checked ? 1 : 0);

        fetch('actions/manage_bot_rules.php', { method:'POST', body:fd })
            .then(r=>r.json()).then(res=>{
                if(res.status==='success'){
                    document.getElementById('botRuleFormModal').style.display='none';
                    loadBotRules();
                    showToast('Đã lưu cấu hình thành công.', 'success');
                }else{
                    showToast(res.msg, 'error');
                }
            }).finally(()=>{
                btn.disabled=false; btn.innerText='Lưu';
            });
    }
</script>

<!-- Modal Cài đặt Bot Tự động was moved to Tab 4 -->

<!-- Form Thêm/Sửa Quy tắc Bot -->
<div id="botRuleFormModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:20px; border-radius:10px; width:100%; max-width:450px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h4 id="rule_form_title" style="margin-top:0; border-bottom:1px solid #e5e7eb; padding-bottom:10px; color:#1f2937;">Thêm quy tắc mới</h4>
        <form id="frm_bot_rule" onsubmit="saveBotRule(event)">
            <input type="hidden" id="rule_id" value="0">
            <input type="hidden" id="rule_type" value="">
            
            <div id="rule_keyword_group" style="margin-bottom:15px; display:none;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; text-align:left; color:#1f2937;">Từ khóa (Cách nhau bằng dấu phẩy)</label>
                <input type="text" id="rule_keywords" placeholder="VD: inbox, giá, bao nhiêu" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
            </div>

            <div id="rule_delay_group" style="margin-bottom:15px; display:none;">
                <div style="display:flex; gap:15px;">
                    <div style="flex:1; text-align:left;">
                        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#1f2937;">Thời gian chờ (giây)</label>
                        <input type="number" id="rule_delay_seconds" min="0" max="60" value="10" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                        <div style="font-size:11px; color:#6b7280; margin-top:4px;">Chờ X giây để gom nhiều tin nhắn. Để 0 để trả lời ngay.</div>
                    </div>
                    <div style="flex:1; text-align:left;">
                        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#1f2937;">Lịch sử trò chuyện (Tin)</label>
                        <input type="number" id="rule_history_count" min="0" max="20" value="6" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                        <div style="font-size:11px; color:#6b7280; margin-top:4px;">Lấy X tin nhắn gần nhất làm ngữ cảnh.</div>
                    </div>
                </div>
            </div>

            <div style="margin-bottom:15px; text-align:left;">
                <label id="lbl_rule_message" style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#1f2937;">Nội dung phản hồi <span style="color:red;">*</span></label>
                <textarea id="rule_message" rows="4" required placeholder="Nhập tin nhắn..." style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box; resize:vertical;"></textarea>
                <div id="hint_rule_message" style="font-size:11px; color:#6b7280; margin-top:4px;">Bạn có thể dùng {name} để gọi tên khách.</div>
            </div>

            <!-- Active Hours Settings -->
            <div style="margin-bottom:15px; text-align:left;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#1f2937;">Thời gian hoạt động</label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <select id="rule_active_time_type" onchange="toggleActiveTimeFields()" style="padding:6px; font-size:13px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer;">
                        <option value="ALL_DAY">Cả ngày (24/24)</option>
                        <option value="CUSTOM">Khung giờ tùy chỉnh</option>
                    </select>
                    <div id="rule_active_time_range" style="display:none; align-items:center; gap:5px;">
                        <input type="time" id="rule_active_start_time" value="17:00" style="padding:4px 6px; border:1px solid #d1d5db; border-radius:6px;">
                        <span style="font-size:12px; color:#4b5563;">đến</span>
                        <input type="time" id="rule_active_end_time" value="07:00" style="padding:4px 6px; border:1px solid #d1d5db; border-radius:6px;">
                    </div>
                </div>
            </div>

            <div style="margin-bottom:15px; text-align:left;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:5px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#1f2937;">Áp dụng cho Fanpage / Zalo OA</label>
                    <?php
                        $unique_users = [];
                        if(!empty($pages)){
                            foreach($pages as $p) $unique_users[$p['user_name']] = true;
                        }
                        $unique_users = array_keys($unique_users);
                        sort($unique_users);
                    ?>
                    <select id="filter_user" onchange="filterPagesByUser()" style="padding:4px 8px; font-size:12px; border-radius:4px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer; width:auto;">
                        <option value="ALL">-- Tất cả kênh --</option>
                        <?php foreach($unique_users as $u): ?>
                            <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="max-height:150px; overflow-y:auto; border:1px solid #d1d5db; border-radius:6px; padding:10px; background:#f9fafb;">
                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-weight:600; cursor:pointer; color:#0068ff; font-size:13px;">
                        <input type="checkbox" id="rule_pages_all" onchange="toggleAllPages(this)" checked> Chọn tất cả (theo bộ lọc)
                    </label>
                    <div id="rule_pages_list">
                        <?php if(!empty($pages)): foreach($pages as $p): ?>
                            <label class="page_item_label" data-user="<?php echo htmlspecialchars($p['user_name']); ?>" style="display:flex; align-items:center; gap:10px; margin-bottom:8px; cursor:pointer; font-size:13px; background:#fff; padding:8px 12px; border:1px solid #e5e7eb; border-radius:6px; transition:all 0.2s;">
                                <input type="checkbox" class="rule_page_cb" value="<?php echo htmlspecialchars($p['page_id']); ?>" onchange="checkSelectAll()" checked style="margin:0; width:16px; height:16px;">
                                <img src="<?php echo htmlspecialchars($p['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($p['name']).'&background=random'); ?>" style="width:28px; height:28px; border-radius:50%; object-fit:cover; border:1px solid #f3f4f6;">
                                <div style="display:flex; flex-direction:column; align-items:flex-start;">
                                    <span style="font-weight:600; color:#1f2937; line-height:1.2; text-align:left;"><?php echo htmlspecialchars($p['name']); ?></span>
                                    <span style="font-size:11px; color:#6b7280; margin-top:2px;">Platform: <?php echo htmlspecialchars($p['user_name']); ?></span>
                                </div>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <div style="display:flex; align-items:center; gap:8px; margin-bottom:20px; text-align:left;">
                <input type="checkbox" id="rule_is_active" checked style="width:16px;height:16px;cursor:pointer;">
                <label for="rule_is_active" style="font-size:13px; font-weight:600; cursor:pointer; color:#1f2937;">Đang bật (Active)</label>
            </div>

            <div style="text-align: right; display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('botRuleFormModal').style.display='none';" class="btn" style="background:#f3f4f6; color:#374151;">Hủy</button>
                <button type="submit" class="btn btn-primary" id="btn_save_rule" style="background:#0068ff; border:none;">Lưu</button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
