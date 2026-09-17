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

// 4. Fetch system account settings & feature flags
$is_admin = (($_SESSION['role'] ?? '') === 'admin');
$stmt_acc = $pdo->prepare("SELECT sales_list, enable_live_chat, enable_live_chat_oa, enable_live_chat_tiktok, enable_website, enable_customers FROM system_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$acc_setup = $stmt_acc->fetch(PDO::FETCH_ASSOC) ?: [];
$sales_list = $acc_setup['sales_list'] ?? '';

$enable_live_chat = $is_admin ? 1 : (int)($acc_setup['enable_live_chat'] ?? 1);
$enable_live_chat_oa = $is_admin ? 1 : (int)($acc_setup['enable_live_chat_oa'] ?? 1);
$enable_live_chat_tiktok = $is_admin ? 1 : (int)($acc_setup['enable_live_chat_tiktok'] ?? 1);
$enable_website = $is_admin ? 1 : (int)($acc_setup['enable_website'] ?? 1);
$enable_customers = $is_admin ? 1 : (int)($acc_setup['enable_customers'] ?? 1);

if (!$enable_live_chat_oa) {
    echo '<div style="padding: 30px; text-align: center; color: #ef4444; font-weight: bold; font-size: 18px;">⚠️ Tính năng Live Chat Zalo OA đã bị tắt cho tài khoản của bạn. Vui lòng liên hệ Admin.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
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
    .badge-pulse-custom {
        animation: pulse-animation-custom 2s infinite;
    }
    @keyframes pulse-animation-custom {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.6; }
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
    <?php if ($enable_live_chat): ?>
    <a href="live_chat.php" class="platform-tab-btn <?php echo ($current_page === 'live_chat') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'live_chat') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'live_chat') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>📘</span> Facebook Fanpage
    </a>
    <?php endif; ?>
    <?php if ($enable_live_chat_oa): ?>
    <a href="live-chat-oa.php" class="platform-tab-btn <?php echo ($current_page === 'live_chat_zalo') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'live_chat_zalo') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'live_chat_zalo') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>💬</span> Zalo Official Account
    </a>
    <?php endif; ?>
    <?php if ($enable_live_chat_tiktok): ?>
    <a href="live-chat-tiktok.php" class="platform-tab-btn <?php echo ($current_page === 'live_chat_tiktok') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'live_chat_tiktok') ? '#fe2c55' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'live_chat_tiktok') ? '#fe2c55' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🎵</span> TikTok
    </a>
    <?php endif; ?>
    <?php if ($enable_website): ?>
    <a href="website.php" class="platform-tab-btn <?php echo ($current_page === 'website') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'website') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'website') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🌐</span> Live Chat Website
    </a>
    <?php endif; ?>
    <?php if ($enable_customers): ?>
    <a href="customers.php" class="platform-tab-btn <?php echo ($current_page === 'customers') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'customers') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'customers') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>👥</span> Khách Hàng
    </a>
    <?php endif; ?>
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
                    <button class="filter-btn" data-filter="return" onclick="filterConversations('return')">🔄 Quay lại</button>
                    <button class="filter-btn" data-filter="phone" onclick="filterConversations('phone')">Có SĐT</button>
                </div>
                <!-- Search Input -->
                <div style="padding: 8px 0 0 0;">
                    <input type="text" id="zalo_conv_search" placeholder="🔍 Tìm tên, SĐT khách hàng..." style="width:100%; padding:6px 10px; border:1px solid var(--border-color); border-radius:6px; font-size:12px; box-sizing:border-box; outline:none; background: var(--card-bg); color: var(--text-main);">
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

            <!-- 7 days Warning banner -->
            <div id="policy_7d_banner" style="display:none; margin: 0 16px 10px 16px; padding:12px 16px; background:#fef2f2; border:1px solid #fee2e2; border-radius:8px; font-size:13px; color:#b91c1c; align-items:flex-start; gap:10px; line-height:1.5;">
                <span>⚠️ Do chính sách Zalo OA, hội thoại đã quá 7 ngày kể từ tương tác cuối cùng của khách hàng. Bạn không thể tiếp tục gửi tin nhắn chăm sóc khách hàng.</span>
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
                    <div class="form-group">
                        <label for="cust_consulted">Trạng thái tư vấn</label>
                        <select id="cust_consulted" disabled style="width:100%; padding:8px 10px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; background:var(--card-bg); color:var(--text-main); cursor:pointer;">
                            <option value="0">🆕 Chưa tư vấn</option>
                            <option value="4">⏳ Chờ xử lý</option>
                            <option value="1">✅ Đã tư vấn</option>
                            <option value="2">🔄 Khách quay lại</option>
                            <option value="3">⛔ Dừng tư vấn</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cust_sales_phone">Số điện thoại Sales</label>
                        <input type="text" id="cust_sales_phone" list="sales_phone_list" placeholder="Nhập SĐT Sales" disabled>
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
                        <label for="cust_sales_notes">Ghi chú Sales</label>
                        <textarea id="cust_sales_notes" rows="3" placeholder="Nhập ghi chú của Sales" style="height:auto;" disabled></textarea>
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
        <div style="display:flex; gap:10px; margin-bottom:15px; border-bottom:1px solid var(--border-color); padding-bottom:10px; flex-wrap: wrap;">
            <button id="tab_welcome" onclick="switchBotTab('welcome')" style="padding:8px 16px; border:none; background:#0068ff; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;">Tin nhắn chào mừng</button>
            <button id="tab_keyword" onclick="switchBotTab('keyword')" style="padding:8px 16px; border:none; background:#f3f4f6; color:#374151; border-radius:6px; cursor:pointer; font-weight:600;">Tin nhắn theo từ khóa</button>
            <button id="tab_ai_reply" onclick="switchBotTab('ai_reply')" style="padding:8px 16px; border:none; background:#f3f4f6; color:#374151; border-radius:6px; cursor:pointer; font-weight:600;">Chat bot AI tự trả lời</button>
            <button id="tab_phone_request" onclick="switchBotTab('phone_request')" style="padding:8px 16px; border:none; background:#f3f4f6; color:#374151; border-radius:6px; cursor:pointer; font-weight:600;">Tự động xin thông tin</button>
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

        <!-- Nội dung Tab 4: Tự động xin thông tin Zalo (SĐT -> Tỉnh -> Nhu cầu) -->
        <div id="content_phone_request" style="display:none;">
            <p style="font-size:13px; color:var(--text-muted); margin-top:0; text-align:left; margin-bottom:15px;">Hệ thống sẽ tự động quét và gửi tin nhắn xin các thông tin còn thiếu của khách hàng Zalo theo thứ tự ưu tiên (SĐT -> Tỉnh thành -> Nhu cầu/Sản phẩm) sau X giờ kể từ tin nhắn cuối cùng của họ (tối đa 7 ngày).</p>
            
            <form id="frm_zalo_phone_request" onsubmit="saveZaloPhoneRequestSettings(event)" style="display:flex; flex-direction:column; gap:15px; text-align:left;">
                <div style="display:flex; align-items:center; justify-content:space-between; background:var(--bg-color); padding:10px; border-radius:6px; border:1px solid var(--border-color);">
                    <label style="font-weight:600; font-size:14px; color:var(--text-main); cursor:pointer; display:flex; align-items:center; gap:8px; margin:0;">
                        <input type="checkbox" id="zalo_phone_request_enabled" name="phone_request_enabled" value="1" style="width:18px; height:18px; cursor:pointer;">
                        Kích hoạt tự động xin thông tin
                    </label>
                </div>

                <div style="display:flex; gap:15px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:200px; display:flex; flex-direction:column; gap:5px;">
                        <label style="font-weight:600; font-size:13px; color:var(--text-main);">Thời gian chờ gửi tin nhắn (giờ)</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="number" id="zalo_phone_request_hours" name="phone_request_hours" min="1" max="168" value="1" style="width:80px; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; background:var(--card-bg); color:var(--text-main);">
                            <span style="font-size:13px; color:var(--text-muted);">giờ (từ 1 đến 168 giờ (7 ngày). Khuyến nghị: 1-2 giờ)</span>
                        </div>
                    </div>
                    <div style="flex:1; min-width:200px; display:flex; flex-direction:column; gap:5px;">
                        <label style="font-weight:600; font-size:13px; color:var(--text-main);">Số lần xin tối đa</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="number" id="zalo_phone_request_limit" name="phone_request_limit" min="1" max="10" value="3" style="width:80px; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; background:var(--card-bg); color:var(--text-main);">
                            <span style="font-size:13px; color:var(--text-muted);">lần (tối đa 10 lần. Mặc định: 3 lần)</span>
                        </div>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main); display:flex; align-items:center; gap:5px;">
                        📞 Mẫu tin nhắn xin Số điện thoại
                    </label>
                    <textarea id="zalo_phone_request_text" name="phone_request_text" rows="2" placeholder="Ví dụ: Dạ {name} cho em xin số điện thoại để tiện liên hệ tư vấn ạ!" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; resize:vertical; background:var(--card-bg); color:var(--text-main);"></textarea>
                    <span style="font-size:11px; color:var(--text-muted);">Dùng {name} để gọi tên khách. Để trống nếu không muốn tự động xin SĐT.</span>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main); display:flex; align-items:center; gap:5px;">
                        📍 Mẫu tin nhắn xin Tỉnh thành
                    </label>
                    <textarea id="zalo_province_request_text" name="province_request_text" rows="2" placeholder="Ví dụ: Dạ hiện tại {name} đang ở tỉnh thành nào để em báo phí ship cho mình ạ?" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; resize:vertical; background:var(--card-bg); color:var(--text-main);"></textarea>
                    <span style="font-size:11px; color:var(--text-muted);">Gửi khi đã có SĐT nhưng chưa có Tỉnh thành. Để trống để bỏ qua bước này.</span>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main); display:flex; align-items:center; gap:5px;">
                        🛍️ Mẫu tin nhắn xin Nhu cầu / Sản phẩm quan tâm
                    </label>
                    <textarea id="zalo_product_request_text" name="product_request_text" rows="2" placeholder="Ví dụ: Dạ {name} đang quan tâm đến dòng sản phẩm nào bên em để em gửi thông tin chi tiết ạ?" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; resize:vertical; background:var(--card-bg); color:var(--text-main);"></textarea>
                    <span style="font-size:11px; color:var(--text-muted);">Gửi khi đã có SĐT và Tỉnh thành nhưng chưa có ghi chú/nhu cầu. Để trống để bỏ qua bước này.</span>
                </div>

                <hr style="border:0; border-top:1px dashed var(--border-color); margin:10px 0;">

                <div style="display:flex; align-items:center; justify-content:space-between; background:var(--bg-color); padding:10px; border-radius:6px; border:1px solid var(--border-color);">
                    <label style="font-weight:600; font-size:14px; color:var(--text-main); cursor:pointer; display:flex; align-items:center; gap:8px; margin:0;">
                        <input type="checkbox" id="zalo_followup_request_enabled" name="followup_request_enabled" value="1" style="width:18px; height:18px; cursor:pointer;">
                        Kích hoạt gửi tin CSKH/Follow-up sau khi đủ thông tin
                    </label>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main);">Thời gian chờ gửi tin</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="number" id="zalo_followup_request_val" min="1" max="720" value="12" style="width:80px; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; background:var(--card-bg); color:var(--text-main);">
                        <select id="zalo_followup_request_unit" style="padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; background:var(--card-bg); color:var(--text-main);">
                            <option value="hours" selected>Giờ</option>
                            <option value="days">Ngày</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main); display:flex; align-items:center; gap:5px;">
                        ✉️ Mẫu tin nhắn CSKH/Follow-up
                    </label>
                    <textarea id="zalo_followup_request_text" name="followup_request_text" rows="2" placeholder="Ví dụ: Dạ {name} đã nhận được báo giá bên em chưa ạ?" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; resize:vertical; background:var(--card-bg); color:var(--text-main);"></textarea>
                    <span style="font-size:11px; color:var(--text-muted);">Gửi sau khi khách hàng đã cung cấp đủ thông tin (không còn thông tin nào cần xin). Dùng {name} để gọi tên.</span>
                </div>

                <div style="text-align:right; margin-top:5px;">
                    <button type="submit" class="btn btn-primary" style="background:#0068ff; color:#fff; border:none; padding:8px 20px; font-weight:600; border-radius:6px; cursor:pointer;">Lưu cấu hình</button>
                </div>
            </form>
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
    let messagePollingInterval = null;
    let activeConvOver7Days = false;
    let activeOaName = '';
    let currentOffset = 0;
    let hasMoreMessages = true;
    let isLoadingMore = false;
    let latestMsgId = '';

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

        // Tìm kiếm cuộc hội thoại Zalo (Quét DB ngầm)
        const searchInput = document.getElementById('zalo_conv_search');
        let searchTimeout = null;
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                renderConversations(); // Phản hồi tức thì cho phần đã tải
                
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    loadConversations('', true); // Quét toàn bộ DB qua AJAX
                }, 400);
            });
        }

        const chatMessages = document.getElementById('chat_messages_container');
        if (chatMessages) {
            chatMessages.addEventListener('scroll', function() {
                if (this.scrollTop === 0) {
                    loadMoreMessages();
                }
            });
        }
        
        // Setup polling for messages when tab is visible (Snappy 4s interval)
        pollInterval = setInterval(() => {
            if (document.getElementById('tab-livechat').classList.contains('active') && activeOaId && activeSenderId) {
                pollNewMessages();
            }
        }, 4000);

        // Setup polling for the left sidebar conversation list to bubble up new chats in real-time (4s interval)
        setInterval(() => {
            if (document.getElementById('tab-livechat').classList.contains('active') && activeOaId) {
                let url = `actions/zalo_get_conversations.php?oa_id=${activeOaId}`;
                const searchInputEl = document.getElementById('zalo_conv_search');
                const searchVal = searchInputEl ? searchInputEl.value.trim() : '';
                if (searchVal) {
                    url += '&search=' + encodeURIComponent(searchVal);
                }
                fetch(url)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        // Cập nhật conversationsCache và render lại cột bên trái
                        conversationsCache = res.data;
                        renderConversations();
                    }
                }).catch(err => console.error("Zalo conversations polling error:", err));
            }
        }, 4000);

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

    function loadConversations(autoSelectSenderId = '', keepActiveSender = false) {
        const selector = document.getElementById('selected_oa');
        activeOaId = selector.value;
        if (!keepActiveSender) {
            activeSenderId = ''; // reset chatbox
            resetChatboxUI();
        }
        
        const container = document.getElementById('conv_list_container');
        if (!activeOaId) {
            container.innerHTML = `
                <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">
                    Vui lòng chọn một kênh Zalo OA để bắt đầu.
                </div>
            `;
            return;
        }

        if (!keepActiveSender) {
            container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">Đang tải danh sách chat...</div>';
        }

        let url = `actions/zalo_get_conversations.php?oa_id=${activeOaId}`;
        const searchInputEl = document.getElementById('zalo_conv_search');
        const searchVal = searchInputEl ? searchInputEl.value.trim() : '';
        if (searchVal) {
            url += '&search=' + encodeURIComponent(searchVal);
        }

        fetch(url)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                conversationsCache = res.data;
                renderConversations();
                
                if (!keepActiveSender) {
                    // Auto select a specific customer chat if passed
                    if (autoSelectSenderId) {
                        selectConversation(autoSelectSenderId);
                        // Clear the URL query parameters so page refresh behaves normally
                        const newUrl = window.location.pathname;
                        window.history.replaceState({}, document.title, newUrl);
                    } else {
                        // Auto select last active conversation from localStorage
                        const savedSenderId = localStorage.getItem('last_zalo_sender_id_' + activeOaId);
                        if (savedSenderId && conversationsCache.some(x => x.sender_id === savedSenderId)) {
                            selectConversation(savedSenderId);
                        }
                    }
                }
            } else {
                if (!keepActiveSender) {
                    showToast(res.msg, 'error');
                    container.innerHTML = `<div style="text-align:center; padding:20px; color:#ef4444; font-size:13px;">${res.msg}</div>`;
                }
            }
        })
        .catch(() => {
            if (!keepActiveSender) {
                container.innerHTML = '<div style="text-align:center; padding:20px; color:#ef4444; font-size:13px;">Lỗi tải dữ liệu.</div>';
            }
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
        } else if (currentFilter === 'return') {
            filtered = conversationsCache.filter(c => parseInt(c.consulted || 0) === 2);
        }

        // Lọc theo từ khóa tìm kiếm (tên khách hàng, SĐT, ID, nội dung)
        const searchQuery = document.getElementById('zalo_conv_search')?.value.trim().toLowerCase() || '';
        if (searchQuery) {
            const searchClean = searchQuery.replace(/[\s\.\-\(\)]/g, '');
            filtered = filtered.filter(c => {
                const name = (c.sender_name || 'Khách hàng Zalo').toLowerCase();
                const custName = (c.cust_name || '').toLowerCase();
                const phone = (c.phone || '').toLowerCase();
                const phoneClean = phone.replace(/[\s\.\-\(\)]/g, '');
                const senderId = (c.sender_id || '').toLowerCase();
                const snippet = (c.snippet || '').toLowerCase();

                const matchName = name.includes(searchQuery) || custName.includes(searchQuery);
                const matchId = senderId.includes(searchQuery);
                const matchSnippet = snippet.includes(searchQuery);
                const matchPhone = phone.includes(searchQuery) || (searchClean.length >= 3 && phoneClean.includes(searchClean));

                return matchName || matchId || matchSnippet || matchPhone;
            });
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
            
            let consultedBadgeHtml = '';
            const consulted = parseInt(c.consulted || 0);
            if (consulted === 0) {
                consultedBadgeHtml = `<span style="background-color:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;">🆕 Chưa tư vấn</span>`;
            } else if (consulted === 4) {
                consultedBadgeHtml = `<span style="background-color:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;">⏳ Chờ xử lý</span>`;
            } else if (consulted === 1) {
                consultedBadgeHtml = `<span style="background-color:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;">✅ Đã tư vấn</span>`;
            } else if (consulted === 2) {
                consultedBadgeHtml = `<span class="badge-pulse-custom" style="background-color:#fff7ed;color:#9a3412;border:1px solid #fed7aa;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;">🔄 Khách quay lại</span>`;
            } else if (consulted === 3) {
                consultedBadgeHtml = `<span style="background-color:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;">⛔ Dừng tư vấn</span>`;
            }

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
                        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                            ${consultedBadgeHtml}
                            ${phoneBadge}
                        </div>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    function selectConversation(senderId) {
        activeSenderId = senderId;
        localStorage.setItem('last_zalo_sender_id_' + activeOaId, senderId);
        
        // Highlight active conversation node
        document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
        const activeNode = document.getElementById(`conv_${senderId}`);
        if (activeNode) {
            activeNode.classList.add('active');
            // Remove unread dot from DOM
            const dot = activeNode.querySelector('.unread-dot');
            if (dot) dot.remove();
        }

        // Update header customer
        const conv = conversationsCache.find(x => x.sender_id === senderId);
        if (conv) {
            conv.unread_count = 0; // Reset unread count locally
        }
        const headerContainer = document.getElementById('chat_active_user_info');
        if (conv) {
            headerContainer.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; justify-content:space-between; width:100%; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <img class="chat-active-avatar" src="${conv.sender_avatar || 'https://ui-avatars.com/api/?name=Zalo'}" alt="Avatar">
                        <div>
                            <div class="chat-active-name">${conv.sender_name}</div>
                            <div class="chat-active-status">Zalo User ID: ${conv.sender_id}</div>
                        </div>
                    </div>
                    <button id="btn_toggle_bot" onclick="toggleBotLock()" class="btn" style="padding:6px 12px; font-size:12px; font-weight:600; border-radius:20px; border:1px solid #d1d5db; background:#fff; color:#374151; display:flex; align-items:center; gap:4px; cursor:pointer;" title="Tạm dừng hoặc Bật lại bot tự trả lời cho khách này">
                        🤖 Bot: ON
                    </button>
                </div>
            `;
            
            // Populate Right Customer Profile form
            document.getElementById('cust_name').value = conv.sender_name || '';
            document.getElementById('cust_phone').value = conv.phone || '';
            document.getElementById('cust_province').value = conv.province || '';
            document.getElementById('cust_notes').value = '';
            document.getElementById('cust_sales_phone').value = '';
            document.getElementById('cust_sales_notes').value = '';
            
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
        document.getElementById('cust_consulted').disabled = !enable;
        document.getElementById('cust_sales_phone').disabled = !enable;
        document.getElementById('cust_sales_notes').disabled = !enable;
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
        document.getElementById('policy_7d_banner').style.display = 'none';
        enableChatInputs(false);
        enableCustomerFormInputs(false);
    }

    function fetchCustomerNotes(senderId) {
        fetch(`actions/zalo_get_customer.php?oa_id=${activeOaId}&sender_id=${senderId}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                if (res.data) {
                    document.getElementById('cust_name').value = res.data.name || '';
                    document.getElementById('cust_phone').value = res.data.phone || '';
                    document.getElementById('cust_province').value = res.data.province || '';
                    document.getElementById('cust_notes').value = res.data.notes || '';
                    document.getElementById('cust_consulted').value = String(res.data.consulted || 0);
                    document.getElementById('cust_sales_phone').value = res.data.sales_phone || '';
                    document.getElementById('cust_sales_notes').value = res.data.sales_notes || '';

                    // Update Zalo bot toggle button status
                    const isLocked = res.data.is_locked == 1;
                    const btnToggleBot = document.getElementById('btn_toggle_bot');
                    if (btnToggleBot) {
                        if (isLocked) {
                            btnToggleBot.innerHTML = '❌ Bot: OFF';
                            btnToggleBot.style.background = '#fee2e2';
                            btnToggleBot.style.color = '#991b1b';
                            btnToggleBot.style.borderColor = '#fca5a5';
                        } else {
                            btnToggleBot.innerHTML = '🤖 Bot: ON';
                            btnToggleBot.style.background = '#dcfce7';
                            btnToggleBot.style.color = '#166534';
                            btnToggleBot.style.borderColor = '#86efac';
                        }
                    }
                }
            }
        });
    }

    window.toggleBotLock = function() {
        if (!activeSenderId || !activeOaId) return;

        const btn = document.getElementById('btn_toggle_bot');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '⏱️ ...';
        }

        const fd = new FormData();
        fd.append('type', 'zalo');
        fd.append('sender_id', activeSenderId);
        fd.append('channel_id', activeOaId);
        fd.append('action', 'toggle');

        fetch('actions/toggle_bot_lock.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                fetchCustomerNotes(activeSenderId);
                showToast(res.msg, 'success');
            } else {
                showToast('Lỗi: ' + res.msg, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Có lỗi xảy ra khi kết nối máy chủ!', 'error');
        })
        .finally(() => {
            if (btn) btn.disabled = false;
        });
    }

    function loadMessages() {
        currentOffset = 0;
        hasMoreMessages = true;
        isLoadingMore = false;
        latestMsgId = '';

        const container = document.getElementById('chat_messages_container');
        container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted);">Đang tải tin nhắn...</div>';

        fetch(`actions/zalo_get_messages.php?oa_id=${activeOaId}&sender_id=${activeSenderId}&offset=0&count=10`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                // Zalo returns messages in reverse order (most recent first)
                const messages = res.data.reverse();
                renderMessageBubbles(messages, true, false);
                
                currentOffset = res.data.length;
                if (res.data.length < 10) {
                    hasMoreMessages = false;
                }
                
                // Enforce Zalo OA 7-day Policy check
                checkPolicy7Days(messages);
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
        if (!activeOaId || !activeSenderId || isLoadingMore) return;

        fetch(`actions/zalo_get_messages.php?oa_id=${activeOaId}&sender_id=${activeSenderId}&offset=0&count=10`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                const messages = res.data.reverse();
                
                if (messages.length > 0) {
                    const newLatestId = messages[messages.length - 1].message_id || '';
                    if (newLatestId === latestMsgId) {
                        // No new messages, do absolutely nothing to prevent scrolling/resetting
                        return;
                    }
                }
                
                // Check if scroll is at the bottom
                const container = document.getElementById('chat_messages_container');
                const isAtBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 80;
                
                if (isAtBottom || currentOffset <= 10) {
                    currentOffset = Math.max(10, messages.length);
                    renderMessageBubbles(messages, true, false);
                    checkPolicy7Days(messages);
                }
            }
        });
    }

    function loadMoreMessages() {
        if (isLoadingMore || !hasMoreMessages || !activeOaId || !activeSenderId) return;

        isLoadingMore = true;
        const container = document.getElementById('chat_messages_container');
        
        const loader = document.createElement('div');
        loader.id = 'load_more_spinner';
        loader.style.cssText = 'text-align:center;padding:8px;color:#9ca3af;font-size:11px;clear:both;';
        loader.innerText = 'Đang tải tin nhắn cũ...';
        container.insertBefore(loader, container.firstChild);

        fetch(`actions/zalo_get_messages.php?oa_id=${activeOaId}&sender_id=${activeSenderId}&offset=${currentOffset}&count=10`)
        .then(r => r.json())
        .then(res => {
            const sp = document.getElementById('load_more_spinner');
            if (sp) sp.remove();
            
            if (res.status === 'success') {
                const newMsgs = res.data;
                if (newMsgs.length === 0) {
                    hasMoreMessages = false;
                    isLoadingMore = false;
                    return;
                }
                
                const messages = newMsgs.reverse();
                renderMessageBubbles(messages, false, true);
                
                currentOffset += newMsgs.length;
                if (newMsgs.length < 10) {
                    hasMoreMessages = false;
                }
            }
            isLoadingMore = false;
        })
        .catch(() => {
            const sp = document.getElementById('load_more_spinner');
            if (sp) sp.remove();
            isLoadingMore = false;
        });
    }

    function checkPolicy7Days(messages) {
        let isOver7Days = false;
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
            if (diffHours > 168) { // 7 days = 168 hours
                isOver7Days = true;
            }
        } else {
            // Fallback: check conversation update time in list
            const conv = conversationsCache.find(x => x.sender_id === activeSenderId);
            if (conv) {
                const updTime = new Date(conv.updated_time).getTime();
                const now = new Date().getTime();
                const diffHours = (now - updTime) / (1000 * 60 * 60);
                if (diffHours > 168) {
                    isOver7Days = true;
                }
            }
        }

        activeConvOver7Days = isOver7Days;
        const banner = document.getElementById('policy_7d_banner');
        banner.style.display = isOver7Days ? 'flex' : 'none';
        
        // Disable chat input elements if over 7 days
        if (isOver7Days) {
            document.getElementById('chat_message_input').disabled = true;
            document.getElementById('chat_message_input').placeholder = "Hội thoại đã quá 7 ngày (Đã khóa gửi tin)";
            document.getElementById('btn_send').disabled = true;
            document.getElementById('btn_attach').disabled = true;
        } else {
            document.getElementById('chat_message_input').disabled = false;
            document.getElementById('chat_message_input').placeholder = "Nhập nội dung tin nhắn...";
            document.getElementById('btn_send').disabled = false;
            document.getElementById('btn_attach').disabled = false;
        }
    }

    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    function extractContactCardInfo(msgObj) {
        let name = '';
        let phone = '';
        let avatar = '';
        let qrCodeUrl = '';
        let isCard = false;

        function checkObj(obj) {
            if (!obj || typeof obj !== 'object') return;
            if (obj.phone) { phone = String(obj.phone); isCard = true; }
            if (obj.contactUid || obj.userId) { isCard = true; }
            if (obj.qrCodeUrl) { qrCodeUrl = obj.qrCodeUrl; isCard = true; }
            if (obj.title || obj.name || obj.displayName) {
                const candidate = obj.title || obj.name || obj.displayName;
                if (candidate !== 'sendBubbleMessage' && candidate !== 'Tệp đính kèm' && candidate !== 'Danh thiếp' && candidate !== 'This is test message') {
                    name = candidate;
                }
            }
            if (obj.avatar || obj.thumb || obj.thumbnail) {
                avatar = obj.avatar || obj.thumb || obj.thumbnail;
            }
            if (obj.description && typeof obj.description === 'string') {
                if (obj.description.startsWith('{') || obj.description.startsWith('[')) {
                    try {
                        const sub = JSON.parse(obj.description);
                        checkObj(sub);
                    } catch(e) {}
                } else {
                    if (!phone) {
                        const pMatch = obj.description.match(/(03|05|07|08|09)\d{8}/) || obj.description.match(/SĐT:\s*(\d+)/i);
                        if (pMatch) { phone = pMatch[0]; isCard = true; }
                    }
                }
            }
        }

        if (msgObj.phone) { phone = String(msgObj.phone); isCard = true; }
        if (msgObj.contactUid) { isCard = true; }
        if (msgObj.name || msgObj.title || msgObj.displayName) {
            const c = msgObj.name || msgObj.title || msgObj.displayName;
            if (c !== 'sendBubbleMessage' && c !== 'Tệp đính kèm' && c !== 'This is test message') name = c;
        }
        if (msgObj.avatar) avatar = msgObj.avatar;

        if (msgObj.content) {
            if (typeof msgObj.content === 'object') {
                checkObj(msgObj.content);
            } else if (typeof msgObj.content === 'string') {
                if (msgObj.content.startsWith('{') || msgObj.content.startsWith('[')) {
                    try { checkObj(JSON.parse(msgObj.content)); } catch(e) {}
                } else if (msgObj.content.startsWith('[Danh thiếp]')) {
                    isCard = true;
                    const m = msgObj.content.match(/^\[Danh thiếp\]\s*(.*?)(?:\s*-\s*SĐT:\s*(\d+))?$/);
                    if (m) {
                        if (m[1]) name = m[1].trim();
                        if (m[2]) phone = m[2];
                    }
                }
            }
        }

        if (msgObj.msgInfo) {
            if (typeof msgObj.msgInfo === 'object') {
                checkObj(msgObj.msgInfo);
            } else if (typeof msgObj.msgInfo === 'string' && (msgObj.msgInfo.startsWith('{') || msgObj.msgInfo.startsWith('['))) {
                try { checkObj(JSON.parse(msgObj.msgInfo)); } catch(e) {}
            }
        }

        let firstAtt = (msgObj.attachments && msgObj.attachments.length > 0) ? msgObj.attachments[0] : (msgObj.attachment || null);
        if (firstAtt) {
            const attType = String(firstAtt.type || '').toLowerCase();
            if (attType === 'recommended' || attType === 'business_card' || attType === 'contact' || attType === 'namecard' || attType === 'card' || attType === '6') {
                isCard = true;
            }
            if (firstAtt.payload) {
                checkObj(firstAtt.payload);
            }
        }

        if (msgObj._file_meta) {
            const fm = msgObj._file_meta;
            if (fm.type === 'namecard') isCard = true;
            if (fm.url && (fm.url.startsWith('{') || fm.url.startsWith('['))) {
                try {
                    const parsedMeta = JSON.parse(fm.url);
                    if (parsedMeta.name && parsedMeta.name !== 'Danh thiếp Zalo') name = parsedMeta.name;
                    if (parsedMeta.phone) phone = parsedMeta.phone;
                    if (parsedMeta.avatar) avatar = parsedMeta.avatar;
                    if (parsedMeta.qr_code) qrCodeUrl = parsedMeta.qr_code;
                    isCard = true;
                } catch(e) {}
            }
        }

        const textStr = msgObj.message || msgObj.description || '';
        if (textStr) {
            if (typeof textStr === 'string' && (textStr.startsWith('{') || textStr.startsWith('['))) {
                try { checkObj(JSON.parse(textStr)); } catch(e) {}
            }
            if (textStr.includes('recommended') || textStr.includes('recommened') || textStr.startsWith('[Danh thiếp]')) {
                isCard = true;
            }
            if (!phone) {
                const pMatch = textStr.match(/(03|05|07|08|09)\d{8}/) || textStr.match(/SĐT:\s*(\d+)/i);
                if (pMatch) { phone = pMatch[0]; }
            }
            if (!name || name === 'Danh thiếp' || name === 'sendBubbleMessage' || name === 'This is test message') {
                const nMatch = textStr.match(/Tên:\s*([^,\n]+)/i) || textStr.match(/^\[Danh thiếp\]\s*(.*?)(?:\s*-\s*SĐT:|$)/);
                if (nMatch) name = nMatch[1].trim();
            }
        }

        const rawType = String(msgObj.type || '').toLowerCase();
        if (rawType === 'namecard' || rawType === 'contact' || rawType === 'card' || rawType === 'business_card' || rawType === 'recommended' || rawType === 'chat.recommended' || rawType === '6' || rawType === 'user_send_business_card') {
            isCard = true;
        }

        if (!name || name === 'This is test message' || name === 'sendBubbleMessage') {
            name = 'Danh thiếp Zalo';
        }

        return { isCard, name, phone, avatar, qrCodeUrl };
    }

    function renderMessageBubbles(messages, shouldScroll = true, isLoadMore = false) {
        const container = document.getElementById('chat_messages_container');
        if (messages.length === 0 && !isLoadMore) {
            container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">Không có tin nhắn.</div>';
            return;
        }

        if (!isLoadMore && messages.length > 0) {
            latestMsgId = messages[messages.length - 1].message_id || '';
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
            
            // Helper: auto link URLs inside text messages
            function autoLinkText(text) {
                if (!text) return '';
                const escaped = escapeHtml(text);
                const urlRegex = /(https?:\/\/[^\s<]+)/gi;
                return escaped.replace(urlRegex, function(url) {
                    return `<a href="${url}" target="_blank" rel="noopener noreferrer" style="color:#0068ff; text-decoration:underline; font-weight:600; word-break:break-all;">${url}</a>`;
                }).replace(/\n/g, '<br>');
            }

            // Determine type from msg.type or first attachment type
            let msgType = msg.type || '';
            let firstAttachment = null;
            if (msg.attachments && msg.attachments.length > 0) {
                firstAttachment = msg.attachments[0];
                if (firstAttachment.type) {
                    msgType = firstAttachment.type;
                }
            } else if (msg.attachment) {
                firstAttachment = msg.attachment;
                if (msg.attachment.type) {
                    msgType = msg.attachment.type;
                }
            }
            
            // Check Contact Card (Danh thiếp)
            const cardInfo = extractContactCardInfo(msg);
            if (cardInfo.isCard) {
                msgType = 'namecard';
            }

            // Normalize msgType aliases across Zalo API versions
            const rawTypeStr = String(msgType).toLowerCase();
            if (rawTypeStr === 'user_send_video' || rawTypeStr === 'chat.video.msg' || rawTypeStr === '5') msgType = 'video';
            if (rawTypeStr === 'user_send_image' || rawTypeStr === 'photo' || rawTypeStr === 'chat.photo' || rawTypeStr === '2') msgType = 'image';
            if (rawTypeStr === 'user_send_file' || rawTypeStr === 'share.file' || rawTypeStr === '46') msgType = 'file';
            if (rawTypeStr === 'user_send_audio' || rawTypeStr === 'user_send_voice' || rawTypeStr === 'chat.voice' || rawTypeStr === '3') msgType = 'voice';
            if (rawTypeStr === 'user_send_sticker' || rawTypeStr === 'chat.sticker' || rawTypeStr === '4') msgType = 'sticker';
            if (rawTypeStr === 'user_send_link' || rawTypeStr === 'chat.link' || rawTypeStr === '38') msgType = 'link';
            
            // Check if text message contains URL
            if (msgType === 'text' && msg.message && /(https?:\/\/[^\s<]+)/i.test(msg.message)) {
                msgType = 'text_link';
            }
            
            // Zalo API v2.0: file/media messages often have NO type, NO message, NO url
            if (!msgType && !msg.message && !msg.url && !msg.thumb) {
                msgType = '_unknown_attachment';
            } else if (!msgType) {
                msgType = 'text';
            }
            
            // Helper: get file icon based on extension
            function getFileIcon(fileName) {
                const parts = (fileName || '').split('.');
                const ext = parts.length > 1 ? parts.pop().toLowerCase() : '';
                const icons = {
                    'pdf': { bg: '#fee2e2', color: '#dc2626', label: 'PDF' },
                    'doc': { bg: '#dbeafe', color: '#2563eb', label: 'DOC' },
                    'docx': { bg: '#dbeafe', color: '#2563eb', label: 'DOC' },
                    'xls': { bg: '#dcfce7', color: '#16a34a', label: 'XLS' },
                    'xlsx': { bg: '#dcfce7', color: '#16a34a', label: 'XLS' },
                    'ppt': { bg: '#fef3c7', color: '#d97706', label: 'PPT' },
                    'pptx': { bg: '#fef3c7', color: '#d97706', label: 'PPT' },
                    'txt': { bg: '#f3f4f6', color: '#6b7280', label: 'TXT' },
                    'zip': { bg: '#fef3c7', color: '#92400e', label: 'ZIP' },
                    'rar': { bg: '#fef3c7', color: '#92400e', label: 'RAR' },
                    'mp3': { bg: '#ede9fe', color: '#7c3aed', label: 'MP3' },
                    'mp4': { bg: '#ede9fe', color: '#7c3aed', label: 'MP4' },
                    'png': { bg: '#dbeafe', color: '#2563eb', label: 'PNG' },
                    'jpg': { bg: '#dbeafe', color: '#2563eb', label: 'JPG' },
                    'jpeg': { bg: '#dbeafe', color: '#2563eb', label: 'JPG' },
                };
                if (ext && icons[ext]) return icons[ext];
                if (ext && ext.length <= 4) return { bg: '#e0e7ff', color: '#4f46e5', label: ext.toUpperCase() };
                return { bg: '#e0e7ff', color: '#4f46e5', label: '📄' };
            }

            function renderFileCard(fileName, fileUrl, fileSize, fileType) {
                const icon = getFileIcon(fileName);
                const sizeStr = fileSize > 0 ? formatBytes(fileSize) : '';
                
                let downloadBtn = '';
                if (fileUrl) {
                    downloadBtn = `<a href="${fileUrl}" target="_blank" download title="Tải xuống" style="
                        display:flex; align-items:center; justify-content:center;
                        width:32px; height:32px; border-radius:6px;
                        background:var(--bg-secondary, #f3f4f6); color:var(--text-muted, #6b7280);
                        text-decoration:none; flex-shrink:0; transition:background 0.2s;
                    " onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='var(--bg-secondary, #f3f4f6)'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                    </a>`;
                }
                
                return `<div style="display:flex; align-items:center; gap:10px; padding:6px 2px; min-width:200px;">
                    <div style="width:40px; height:40px; border-radius:8px; background:${icon.bg}; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <span style="font-size:11px; font-weight:700; color:${icon.color}; letter-spacing:0.5px;">${icon.label}</span>
                    </div>
                    <div style="flex:1; min-width:0; text-align:left;">
                        <div style="font-weight:600; font-size:13px; color:var(--text-primary, #1a1a2e); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(fileName)}">${escapeHtml(fileName)}</div>
                        <div style="font-size:11px; color:var(--text-muted, #9ca3af); margin-top:1px;">${sizeStr || (fileUrl ? 'Nhấp để tải' : 'Mở Zalo để xem')}</div>
                    </div>
                    ${downloadBtn}
                </div>`;
            }
            
            // Check for _file_meta injected by backend
            const fileMeta = msg._file_meta || null;
            
            // Render different types of message (text, image, video, namecard, sticker, file, voice, link, etc.)
            if (msgType === 'text') {
                if (msg.message) {
                    content = `<div>${autoLinkText(msg.message)}</div>`;
                } else if (fileMeta) {
                    content = renderFileCard(fileMeta.name, fileMeta.url, fileMeta.size, fileMeta.type);
                } else {
                    content = renderFileCard('Tệp đính kèm', '', 0, 'file');
                }
            } else if (msgType === 'text_link') {
                content = `<div>${autoLinkText(msg.message)}</div>`;
            } else if (msgType === 'image' || msgType === 'photo') {
                let imgUrl = msg.url || msg.thumb || '';
                let imgDesc = msg.description || msg.message || '';
                
                if (firstAttachment && firstAttachment.payload) {
                    imgUrl = firstAttachment.payload.url || firstAttachment.payload.thumbnail || firstAttachment.payload.thumb || imgUrl;
                    imgDesc = firstAttachment.payload.description || firstAttachment.payload.title || imgDesc;
                }
                if (!imgUrl && fileMeta) {
                    imgUrl = fileMeta.url || '';
                }
                
                if (imgUrl) {
                    content = `<img src="${imgUrl}" alt="Hình ảnh" onclick="window.open('${imgUrl}')" style="max-width:100%; max-height:300px; border-radius:8px; cursor:pointer; margin-top:5px;">`;
                    if (imgDesc) {
                        content += `<div style="margin-top:5px;">${autoLinkText(imgDesc)}</div>`;
                    }
                } else {
                    content = renderFileCard(fileMeta ? fileMeta.name : 'Hình ảnh', fileMeta ? fileMeta.url : '', fileMeta ? fileMeta.size : 0, 'image');
                }
            } else if (msgType === 'video') {
                let videoUrl = msg.url || '';
                let thumbUrl = msg.thumb || msg.thumbnail || '';
                let videoTitle = msg.title || msg.name || msg.message || 'Video';
                
                if (firstAttachment && firstAttachment.payload) {
                    videoUrl = firstAttachment.payload.url || firstAttachment.payload.href || firstAttachment.payload.normalUrl || firstAttachment.payload.hdUrl || videoUrl;
                    thumbUrl = firstAttachment.payload.thumbnail || firstAttachment.payload.thumb || thumbUrl;
                    videoTitle = firstAttachment.payload.title || firstAttachment.payload.name || videoTitle;
                }
                if (!videoUrl && fileMeta) {
                    videoUrl = fileMeta.url || '';
                }
                
                if (videoUrl) {
                    content = `<div style="margin-top:5px; max-width:320px; border-radius:8px; overflow:hidden; border:1px solid var(--border-color); background:#000;">
                        <video src="${videoUrl}" ${thumbUrl ? `poster="${thumbUrl}"` : ''} controls preload="metadata" playsinline style="width:100%; max-height:300px; display:block;"></video>
                        <div style="padding:6px 10px; background:rgba(0,0,0,0.6); display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:11px; color:#fff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:200px;">${escapeHtml(videoTitle)}</span>
                            <a href="${videoUrl}" target="_blank" download style="color:#0068ff; font-size:11px; font-weight:600; text-decoration:none; background:#fff; padding:2px 8px; border-radius:4px;">Tải xuống</a>
                        </div>
                    </div>`;
                } else {
                    content = renderFileCard(videoTitle !== 'Video' ? videoTitle : 'Video đính kèm', fileMeta ? fileMeta.url : '', fileMeta ? fileMeta.size : 0, 'video');
                }
            } else if (msgType === 'namecard') {
                let cardName = cardInfo.name || 'Danh thiếp Zalo';
                let cardPhone = cardInfo.phone || '';
                let cardAvatar = cardInfo.avatar || '';
                let cardQr = cardInfo.qrCodeUrl || '';
                if (!cardAvatar) cardAvatar = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(cardName) + '&background=2563eb&color=ffffff';
                
                content = `<div style="margin-top:6px; max-width:320px; border-radius:12px; overflow:hidden; border:1px solid #cbd5e1; box-shadow:0 4px 12px rgba(0,0,0,0.08); text-align:left; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
                    <div style="background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); padding:14px; color:#ffffff; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                        <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0;">
                            <img src="${cardAvatar}" style="width:46px; height:46px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,0.8); flex-shrink:0;" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(cardName)}&background=ffffff&color=2563eb'">
                            <div style="overflow:hidden; flex:1;">
                                <div style="font-weight:700; font-size:14px; color:#ffffff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(cardName)}">${escapeHtml(cardName)}</div>
                                <div style="font-size:12px; color:rgba(255,255,255,0.9); margin-top:2px; font-weight:500;">${escapeHtml(cardPhone || 'Danh thiếp Zalo')}</div>
                            </div>
                        </div>
                        ${cardQr ? `
                        <div style="background:#ffffff; padding:3px; border-radius:6px; flex-shrink:0; cursor:pointer;" onclick="window.open('${cardQr}')" title="Xem mã QR">
                            <img src="${cardQr}" style="width:44px; height:44px; display:block; border-radius:4px;">
                        </div>
                        ` : `
                        <div style="width:36px; height:36px; background:rgba(255,255,255,0.15); border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:18px;">
                            🎴
                        </div>
                        `}
                    </div>
                    <div style="background:#f8fafc; padding:8px 12px; border-top:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; gap:8px;">
                        ${cardPhone ? `
                        <a href="tel:${cardPhone}" style="flex:1; text-align:center; padding:6px 0; font-size:12px; font-weight:600; color:#1e293b; text-decoration:none; background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; display:flex; align-items:center; justify-content:center; gap:4px;">
                            📞 Gọi Điện
                        </a>
                        <button type="button" onclick="navigator.clipboard.writeText('${cardPhone}'); showToast('Đã copy số điện thoại!', 'success');" style="flex:1; text-align:center; padding:6px 0; font-size:12px; font-weight:600; color:#2563eb; background:#ffffff; border:1px solid #93c5fd; border-radius:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:4px;">
                            📋 Copy SĐT
                        </button>
                        ` : (cardQr ? `
                        <a href="${cardQr}" target="_blank" style="flex:1; text-align:center; padding:6px 0; font-size:12px; font-weight:600; color:#2563eb; text-decoration:none; background:#ffffff; border:1px solid #93c5fd; border-radius:6px; display:flex; align-items:center; justify-content:center; gap:4px;">
                            🔍 Quét Mã QR Zalo
                        </a>
                        ` : `
                        <div style="font-size:11px; color:#64748b; text-align:center; width:100%;">Danh thiếp liên hệ Zalo</div>
                        `)}
                    </div>
                </div>`;
            } else if (msgType === 'sticker') {
                let stickerUrl = msg.url || '';
                if (firstAttachment && firstAttachment.payload) {
                    stickerUrl = firstAttachment.payload.url || stickerUrl;
                }
                content = `<img src="${stickerUrl}" style="max-width:120px;" alt="Sticker">`;
            } else if (msgType === 'file') {
                let fileUrl = msg.url || '';
                let fileName = msg.name || msg.title || msg.message || 'Tệp đính kèm';
                let fileSize = 0;
                
                if (firstAttachment && firstAttachment.payload) {
                    fileUrl = firstAttachment.payload.url || fileUrl;
                    fileName = firstAttachment.payload.name || firstAttachment.payload.title || fileName;
                    fileSize = firstAttachment.payload.size || 0;
                } else if (msg.attachment && msg.attachment.payload) {
                    fileUrl = msg.attachment.payload.url || fileUrl;
                    fileName = msg.attachment.payload.name || msg.attachment.payload.title || fileName;
                    fileSize = msg.attachment.payload.size || 0;
                }
                
                if (fileMeta) {
                    if (!fileUrl && fileMeta.url) fileUrl = fileMeta.url;
                    if (fileName === 'Tệp đính kèm' && fileMeta.name) fileName = fileMeta.name;
                    if (!fileSize && fileMeta.size) fileSize = fileMeta.size;
                }
                
                content = renderFileCard(fileName, fileUrl, fileSize, 'file');
            } else if (msgType === 'voice' || msgType === 'audio') {
                let voiceUrl = msg.url || '';
                if (firstAttachment && firstAttachment.payload) {
                    voiceUrl = firstAttachment.payload.url || voiceUrl;
                }
                if (!voiceUrl && fileMeta) {
                    voiceUrl = fileMeta.url || '';
                }
                if (voiceUrl) {
                    content = `<div style="padding:5px 0;">
                        <div style="font-size:12px; color:var(--text-muted); margin-bottom:4px; display:flex; align-items:center; gap:4px;">
                            <span>🔊</span> Tin nhắn thoại
                        </div>
                        <audio src="${voiceUrl}" controls style="max-width:100%; height:40px; border-radius:4px; outline:none;"></audio>
                    </div>`;
                } else {
                    content = renderFileCard(fileMeta ? fileMeta.name : 'Tin nhắn thoại', fileMeta ? fileMeta.url : '', fileMeta ? fileMeta.size : 0, 'audio');
                }
            } else if (msgType === 'link' || msgType === 'links') {
                let linkUrl = msg.url || msg.href || '';
                if (firstAttachment && firstAttachment.payload) {
                    linkUrl = firstAttachment.payload.url || firstAttachment.payload.href || firstAttachment.payload.link || linkUrl;
                }
                if (!linkUrl && msg.links && msg.links.length > 0) {
                    linkUrl = msg.links[0].url || msg.links[0].href || '';
                }
                if (!linkUrl && msg.message) {
                    const urlMatch = msg.message.match(/(https?:\/\/[^\s<]+)/i);
                    if (urlMatch) {
                        linkUrl = urlMatch[0];
                    }
                }
                
                let linkTitle = msg.title || msg.name || (firstAttachment && firstAttachment.payload ? (firstAttachment.payload.title || firstAttachment.payload.name) : '') || '';
                if (!linkTitle || linkTitle === 'Liên kết') {
                    linkTitle = linkUrl || msg.message || 'Liên kết';
                }
                let linkDesc = msg.description || (firstAttachment && firstAttachment.payload ? firstAttachment.payload.description : '') || '';
                let linkThumb = msg.thumb || msg.thumbnail || (firstAttachment && firstAttachment.payload ? (firstAttachment.payload.thumbnail || firstAttachment.payload.thumb) : '') || '';
                
                if (linkUrl) {
                    content = `<div style="text-align:left; border: 1px solid var(--border-color); border-radius:8px; overflow:hidden; background:rgba(0,0,0,0.02); max-width:320px; margin-top:5px;">`;
                    if (linkThumb) {
                        content += `<img src="${linkThumb}" style="width:100%; height:140px; object-fit:cover; display:block;" onerror="this.style.display='none'">`;
                    }
                    content += `<div style="padding:10px;">
                        <a href="${linkUrl}" target="_blank" rel="noopener noreferrer" style="font-weight:600; color:#0068ff; text-decoration:underline; display:block; margin-bottom:4px; font-size:13px; line-height:1.4; word-break:break-all;">🔗 ${escapeHtml(linkTitle)}</a>`;
                    if (linkDesc) {
                        content += `<div style="font-size:12px; color:var(--text-muted); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">${escapeHtml(linkDesc)}</div>`;
                    }
                    content += `</div></div>`;
                } else if (msg.message) {
                    content = `<div>${autoLinkText(msg.message)}</div>`;
                } else {
                    content = `<div>🔗 Liên kết</div>`;
                }
            } else if (msgType === '_unknown_attachment') {
                if (fileMeta) {
                    content = renderFileCard(fileMeta.name, fileMeta.url, fileMeta.size, fileMeta.type);
                } else {
                    content = `<div style="display:flex; align-items:center; gap:10px; padding:6px 2px; min-width:200px;">
                        <div style="width:40px; height:40px; border-radius:8px; background:#e0e7ff; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <span style="font-size:16px;">🎴</span>
                        </div>
                        <div style="flex:1; min-width:0; text-align:left;">
                            <div style="font-weight:600; font-size:13px; color:var(--text-primary, #1a1a2e);">Danh thiếp / Nội dung từ Zalo</div>
                            <div style="font-size:11px; color:var(--text-muted, #9ca3af); margin-top:1px;">Mở ứng dụng Zalo OA để xem</div>
                        </div>
                    </div>`;
                }
            } else {
                let fallbackUrl = msg.url || (firstAttachment && firstAttachment.payload ? firstAttachment.payload.url : '') || (fileMeta ? fileMeta.url : '');
                let fallbackName = msg.name || msg.title || (firstAttachment && firstAttachment.payload ? (firstAttachment.payload.name || firstAttachment.payload.title) : '') || (fileMeta ? fileMeta.name : '');
                
                if (fallbackUrl) {
                    content = renderFileCard(fallbackName || 'Tệp đính kèm', fallbackUrl, fileMeta ? fileMeta.size : 0, 'file');
                } else if (msg.message) {
                    content = `<div>${autoLinkText(msg.message)}</div>`;
                } else if (fileMeta) {
                    content = renderFileCard(fileMeta.name, fileMeta.url, fileMeta.size, fileMeta.type);
                } else {
                    content = `<div style="display:flex; align-items:center; gap:10px; padding:6px 2px; min-width:200px;">
                        <div style="width:40px; height:40px; border-radius:8px; background:#e0e7ff; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <span style="font-size:16px;">🎴</span>
                        </div>
                        <div style="flex:1; min-width:0; text-align:left;">
                            <div style="font-weight:600; font-size:13px; color:var(--text-primary, #1a1a2e);">Danh thiếp / Nội dung từ Zalo</div>
                            <div style="font-size:11px; color:var(--text-muted, #9ca3af); margin-top:1px;">Mở ứng dụng Zalo OA để xem</div>
                        </div>
                    </div>`;
                }
            }

            html += `
                <div class="msg-group ${groupClass}">
                    <div class="msg-bubble">${content}</div>
                    <div class="msg-time">${timeStr}</div>
                </div>
            `;
        });

        const oldScrollTop = container.scrollTop;
        const oldScrollHeight = container.scrollHeight;

        if (!isLoadMore) {
            container.innerHTML = html;
            if (shouldScroll) {
                container.scrollTop = container.scrollHeight;
            }
        } else {
            const tmp = document.createElement('div');
            tmp.innerHTML = html;
            while (tmp.lastChild) {
                container.insertBefore(tmp.lastChild, container.firstChild);
            }
            container.scrollTop = oldScrollTop + (container.scrollHeight - oldScrollHeight);
        }
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
        
        if (activeConvOver7Days) {
            showToast('Không thể gửi: Hội thoại đã quá hạn 7 ngày.', 'error');
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

        if (activeConvOver7Days) {
            showToast('Không thể gửi: Hội thoại đã quá hạn 7 ngày.', 'error');
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
        const consulted = document.getElementById('cust_consulted').value;
        const sales_phone = document.getElementById('cust_sales_phone').value.trim();
        const sales_notes = document.getElementById('cust_sales_notes').value.trim();

        const fd = new FormData();
        fd.append('oa_id', activeOaId);
        fd.append('sender_id', activeSenderId);
        fd.append('name', name);
        fd.append('phone', phone);
        fd.append('province', province);
        fd.append('notes', notes);
        fd.append('consulted', consulted);
        fd.append('sales_phone', sales_phone);
        fd.append('sales_notes', sales_notes);

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
        const tabs = ['welcome', 'keyword', 'ai_reply', 'phone_request'];
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
        if (tab === 'phone_request') {
            window.loadZaloPhoneRequestSettings();
        }
    }

    window.loadZaloPhoneRequestSettings = function() {
        fetch('actions/zalo_settings.php')
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success' && res.data) {
                document.getElementById('zalo_phone_request_enabled').checked = res.data.phone_request_enabled == 1;
                document.getElementById('zalo_phone_request_hours').value = res.data.phone_request_hours || 1;
                document.getElementById('zalo_phone_request_text').value = res.data.phone_request_text || '';
                document.getElementById('zalo_province_request_text').value = res.data.province_request_text || '';
                document.getElementById('zalo_product_request_text').value = res.data.product_request_text || '';
                document.getElementById('zalo_phone_request_limit').value = res.data.phone_request_limit || 3;
                
                // Load follow-up settings
                document.getElementById('zalo_followup_request_enabled').checked = res.data.followup_request_enabled == 1;
                const followupHours = res.data.followup_request_hours || 12;
                if (followupHours >= 24 && followupHours % 24 === 0) {
                    document.getElementById('zalo_followup_request_val').value = followupHours / 24;
                    document.getElementById('zalo_followup_request_unit').value = 'days';
                } else {
                    document.getElementById('zalo_followup_request_val').value = followupHours;
                    document.getElementById('zalo_followup_request_unit').value = 'hours';
                }
                document.getElementById('zalo_followup_request_text').value = res.data.followup_request_text || '';
            }
        })
        .catch(err => console.error('Error loading Zalo phone settings:', err));
    }

    window.saveZaloPhoneRequestSettings = function(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerText = 'Đang lưu...';
        
        const fd = new FormData();
        fd.append('phone_request_enabled', document.getElementById('zalo_phone_request_enabled').checked ? 1 : 0);
        fd.append('phone_request_hours', document.getElementById('zalo_phone_request_hours').value);
        fd.append('phone_request_text', document.getElementById('zalo_phone_request_text').value);
        fd.append('province_request_text', document.getElementById('zalo_province_request_text').value);
        fd.append('product_request_text', document.getElementById('zalo_product_request_text').value);
        fd.append('phone_request_limit', document.getElementById('zalo_phone_request_limit').value);
        
        const followupVal = parseInt(document.getElementById('zalo_followup_request_val').value) || 12;
        const followupUnit = document.getElementById('zalo_followup_request_unit').value;
        const followupHours = followupUnit === 'days' ? followupVal * 24 : followupVal;
        
        fd.append('followup_request_enabled', document.getElementById('zalo_followup_request_enabled').checked ? 1 : 0);
        fd.append('followup_request_hours', followupHours);
        fd.append('followup_request_text', document.getElementById('zalo_followup_request_text').value);
        
        fetch('actions/zalo_save_phone_settings.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                showToast('Lưu cấu hình tự động xin thông tin Zalo thành công!', 'success');
            } else {
                showToast('Lỗi: ' + res.msg, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Có lỗi xảy ra khi kết nối máy chủ!', 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerText = 'Lưu cấu hình';
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
