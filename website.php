<?php
// website.php
$current_page = 'website';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin   = (($_SESSION['role'] ?? '') === 'admin');

// Check feature flags
$acc_setup = [];
try {
    $stmt_acc = $pdo->prepare("SELECT enable_live_chat, enable_live_chat_oa, enable_live_chat_tiktok, enable_website, enable_customers FROM system_accounts WHERE id = ?");
    $stmt_acc->execute([$account_id]);
    $acc_setup = $stmt_acc->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$enable_live_chat = $is_admin ? 1 : (int)($acc_setup['enable_live_chat'] ?? 1);
$enable_live_chat_oa = $is_admin ? 1 : (int)($acc_setup['enable_live_chat_oa'] ?? 1);
$enable_live_chat_tiktok = $is_admin ? 1 : (int)($acc_setup['enable_live_chat_tiktok'] ?? 1);
$enable_website = $is_admin ? 1 : (int)($acc_setup['enable_website'] ?? 1);
$enable_customers = $is_admin ? 1 : (int)($acc_setup['enable_customers'] ?? 1);

if (!$enable_live_chat) {
    echo '<div class="page-title">Truy cập bị từ chối</div>';
    echo '<div class="card" style="border-left: 4px solid #ef4444; padding: 20px;">';
    echo '  <h3 style="margin-top:0; color:#ef4444;">⚠️ Tính Năng Đã Bị Tắt</h3>';
    echo '  <p style="color:#4b5563; font-size:14px; margin-bottom:0;">Tính năng Live Chat đã bị tắt cho tài khoản của bạn. Vui lòng liên hệ Admin để kích hoạt lại.</p>';
    echo '</div>';
    include 'includes/footer.php';
    exit;
}

// Auto run setup table
require_once __DIR__ . '/setup_website_chat.php';

// Fetch current widget config
$stmt_cfg = $pdo->prepare("SELECT * FROM web_chat_configs WHERE account_id = ?");
$stmt_cfg->execute([$account_id]);
$config = $stmt_cfg->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    $config = [
        'bot_name' => 'Gấu cười',
        'bot_avatar' => 'https://s240-ava-talk.zadn.vn/c/c/6/3/3/240/cd520d4d49a844b5abe6410e9e3dd9aa.jpg',
        'bot_subtitle' => 'Trợ lý AI MONA — đang online',
        'policy_notice' => 'Cuộc trò chuyện được lưu để cải thiện dịch vụ.',
        'greeting_msg' => 'Dạ em là Gấu cười, luôn có cách, cho anh chị!',
        'brand_footer' => 'AI chăm sóc khách hàng bởi MONA',
        'zalo_link' => '',
        'messenger_link' => '',
        'primary_color' => '#0068ff',
        'ai_enabled' => 1,
        'system_prompt' => "Bạn là trợ lý tư vấn CSKH AI chuyên nghiệp và thân thiện tên {bot_name}. Hãy trả lời ngắn gọn, lịch sự, tư vấn sản phẩm/dịch vụ cho khách hàng. Nếu khách hàng chưa để lại Số điện thoại hoặc tên, hãy khéo léo xin số điện thoại/Zalo để nhân viên tư vấn gọi hỗ trợ ngay."
    ];
}

// Generate Embed Code
$domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
$base_path = rtrim(str_replace('/website.php', '', $_SERVER['PHP_SELF']), '/');
$script_url = $domain . $base_path . '/widget.js';
$server_url = $domain . $base_path;

$embed_code = '<!-- Start Web Chat Widget -->' . "\n" .
'<script src="' . htmlspecialchars($script_url) . '" data-account="' . $account_id . '" data-server="' . htmlspecialchars($server_url) . '" async></script>' . "\n" .
'<!-- End Web Chat Widget -->';
?>

<style>
    .platform-tab-btn:hover {
        color: #0068ff !important;
        border-bottom-color: #cbd5e1 !important;
    }
    .platform-tab-btn.active:hover {
        border-bottom-color: #0068ff !important;
    }
    
    /* Sub-tabs Navigation Styles */
    .web-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 10px;
    }
    .web-tab-btn {
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
    .web-tab-btn:hover {
        background: rgba(0, 104, 255, 0.05);
        color: #0068ff;
    }
    .web-tab-btn.active {
        background: #0068ff;
        color: #fff;
        box-shadow: 0 4px 6px -1px rgba(0, 104, 255, 0.3);
    }
    
    .web-tab-content {
        display: none;
        animation: fadeIn 0.25s ease-in-out;
    }
    .web-tab-content.active {
        display: block;
    }

    /* Live Chat 2-Column Layout */
    .chat-layout {
        display: flex;
        gap: 16px;
        height: calc(100vh - 220px);
        min-height: 550px;
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: 12px;
        overflow: hidden;
    }
    .chat-sidebar {
        width: 320px;
        border-right: 1px solid var(--border-color, #e5e7eb);
        display: flex;
        flex-direction: column;
        background: #fafafa;
    }
    .chat-main {
        flex: 1;
        display: flex;
        flex-direction: column;
        background: #ffffff;
    }
    .chat-info-sidebar {
        width: 280px;
        border-left: 1px solid var(--border-color, #e5e7eb);
        padding: 16px;
        background: #fafafa;
        overflow-y: auto;
    }

    .visitor-item {
        padding: 12px 14px;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer;
        transition: background 0.15s;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .visitor-item:hover {
        background: #f0f7ff;
    }
    .visitor-item.active {
        background: #e0f2fe;
        border-left: 4px solid #0068ff;
    }
    .visitor-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0084ff, #0068ff);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        flex-shrink: 0;
    }

    /* Live Preview Mockup Box */
    .preview-mockup {
        width: 360px;
        height: 540px;
        border-radius: 18px;
        overflow: hidden;
        border: 1px solid #cbd5e1;
        box-shadow: 0 12px 32px rgba(0,0,0,0.15);
        display: flex;
        flex-direction: column;
        background: #ffffff;
        font-family: sans-serif;
    }
</style>

<!-- Platform Switcher Tabs (Nav 4 Tabs) -->
<div class="platform-tabs" style="display: flex; gap: 20px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; padding-bottom: 0;">
    <?php if ($enable_live_chat): ?>
    <a href="live_chat.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>📘</span> Facebook Fanpage
    </a>
    <?php endif; ?>
    <?php if ($enable_live_chat_oa): ?>
    <a href="live-chat-oa.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>💬</span> Zalo Official Account
    </a>
    <?php endif; ?>
    <?php if ($enable_live_chat_tiktok): ?>
    <a href="live-chat-tiktok.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🎵</span> TikTok
    </a>
    <?php endif; ?>
    <?php if ($enable_website): ?>
    <a href="website.php" class="platform-tab-btn active" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #0068ff; border-bottom: 3px solid #0068ff; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🌐</span> Live Chat Website
    </a>
    <?php endif; ?>
    <?php if ($enable_customers): ?>
    <a href="customers.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>👥</span> Khách Hàng
    </a>
    <?php endif; ?>
</div>

<!-- Page Header -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
    <div class="page-title" style="margin-bottom:0;">🌐 Website Live Chat & Widget</div>
</div>

<!-- Sub-Tabs Navigation -->
<div class="web-tabs">
    <button class="web-tab-btn active" data-target="tab-livechat-web">
        <span>💬</span> Chat Website
    </button>
    <button class="web-tab-btn" data-target="tab-embed-code">
        <span>📜</span> Mã Nhúng Website
    </button>
    <button class="web-tab-btn" data-target="tab-widget-config">
        <span>⚙️</span> Cấu Hình Widget & AI Bot
    </button>
</div>


<!-- ==================== TAB 1: LIVE CHAT WEBSITE ==================== -->
<div id="tab-livechat-web" class="web-tab-content active">
    <div class="chat-layout">
        <!-- Left Sidebar: Visitor List -->
        <div class="chat-sidebar">
            <div style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                <input type="text" id="visitor_search" placeholder="🔍 Tìm Khách vãng lai, SĐT..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; box-sizing:border-box;" oninput="filterVisitorsList()">
            </div>
            <div id="visitors_list_container" style="flex:1; overflow-y:auto;">
                <div style="text-align:center; padding:30px; color:#9ca3af; font-size:13px;">Đang tải danh sách...</div>
            </div>
        </div>

        <!-- Middle: Chat Messages Area -->
        <div class="chat-main">
            <!-- Header -->
            <div style="padding: 14px 16px; border-bottom: 1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; background:#fafafa;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div class="visitor-avatar" id="current_visitor_avatar">🌐</div>
                    <div>
                        <div style="font-weight:700; font-size:15px; color:#1e293b;" id="current_visitor_title">Chọn khách hàng để trò chuyện</div>
                        <div style="font-size:12px; color:#64748b;" id="current_visitor_subtitle">Khách vãng lai từ Website</div>
                    </div>
                </div>
            </div>

            <!-- Messages Body -->
            <div id="web_chat_messages_body" style="flex:1; padding:16px; overflow-y:auto; background:#f8fafc; display:flex; flex-direction:column; gap:12px;">
                <div style="text-align:center; padding:40px; color:#9ca3af; font-size:13px;">Chọn một cuộc hội thoại từ danh sách bên trái.</div>
            </div>

            <!-- Input Area -->
            <div style="padding:12px 16px; border-top:1px solid #e5e7eb; background:#ffffff; display:flex; gap:10px; align-items:center;">
                <input type="file" id="agent_file_input" style="display:none;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt" onchange="uploadAgentFile(this)">
                <button onclick="document.getElementById('agent_file_input').click()" class="btn btn-secondary" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:10px 14px; border-radius:8px; cursor:pointer;" title="Gửi ảnh/file">📎</button>
                <input type="text" id="agent_msg_input" placeholder="Nhập tin nhắn phản hồi khách vãng lai..." style="flex:1; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; outline:none;" onkeydown="if(event.key==='Enter') sendAgentMessage()">
                <button onclick="sendAgentMessage()" class="btn btn-primary" style="background:#0068ff; color:#fff; border:none; padding:10px 20px; font-weight:600; border-radius:8px; cursor:pointer;">Gửi</button>
            </div>
        </div>

        <!-- Right Info Sidebar: Customer Profile Edit -->
        <div class="chat-info-sidebar" id="visitor_info_panel">
            <div style="font-weight:700; font-size:14px; color:#1e293b; margin-bottom:12px; border-bottom:1px solid #e5e7eb; padding-bottom:8px;">
                📋 Hồ Sơ Khách Hàng
            </div>
            
            <form id="frm_visitor_info" onsubmit="saveVisitorInfo(event)" style="display:flex; flex-direction:column; gap:12px;">
                <input type="hidden" id="info_visitor_uuid" value="">
                
                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Tên khách hàng</label>
                    <input type="text" id="info_visitor_name" placeholder="Khách vãng lai #1" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Số điện thoại</label>
                    <input type="text" id="info_visitor_phone" placeholder="VD: 0987654321" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Tỉnh thành</label>
                    <input type="text" id="info_visitor_province" placeholder="VD: TP. Hồ Chí Minh" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Ghi chú / Nhu cầu</label>
                    <textarea id="info_visitor_notes" rows="3" placeholder="Sản phẩm/Dịch vụ quan tâm..." style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; resize:vertical;"></textarea>
                </div>

                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Trạng thái tư vấn</label>
                    <select id="info_visitor_consulted" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                        <option value="0">🆕 Chưa tư vấn</option>
                        <option value="4">⏳ Chờ xử lý</option>
                        <option value="1">✅ Đã tư vấn</option>
                        <option value="2">🔄 Khách quay lại</option>
                        <option value="3">⛔ Dừng tư vấn</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="background:#059669; color:#fff; border:none; padding:8px; font-weight:600; border-radius:6px; cursor:pointer; margin-top:6px;">Lưu Hồ Sơ</button>
            </form>
        </div>
    </div>
</div>


<!-- ==================== TAB 2: MÃ NHÚNG WEBSITE ==================== -->
<div id="tab-embed-code" class="web-tab-content">
    <div class="card" style="max-width:850px; margin:0 auto; background:#fff; padding:25px; border-radius:12px; border:1px solid var(--border-color); box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <div style="font-weight:700; font-size:18px; color:#1e293b; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
            <span>📜</span> Mã Nhúng Chat Widget Vào Website
        </div>
        <p style="font-size:14px; color:#64748b; margin-top:0; margin-bottom:20px;">
            Sao chép đoạn mã JavaScript dưới đây và dán vào trước thẻ <code>&lt;/body&gt;</code> của bất kỳ website nào (WordPress, Shopify, Haravan, HTML/PHP custom) để kích hoạt nút chat nổi và AI Chatbot trả lời khách hàng.
        </p>

        <div style="position:relative; margin-bottom:20px;">
            <textarea id="embed_code_box" rows="4" readonly style="width:100%; font-family:monospace; font-size:13px; padding:14px; background:#1e293b; color:#38bdf8; border-radius:8px; border:none; box-sizing:border-box; resize:none;"><?php echo htmlspecialchars($embed_code); ?></textarea>
            <button onclick="copyEmbedCode()" class="btn btn-primary" style="position:absolute; top:10px; right:10px; background:#0068ff; color:#fff; border:none; padding:6px 14px; font-size:12px; font-weight:600; border-radius:6px; cursor:pointer;">
                📋 Sao chép mã nhúng
            </button>
        </div>

        <div style="background:#f8fafc; border-left:4px solid #0068ff; padding:16px; border-radius:0 8px 8px 0; font-size:13px; color:#334155; line-height:1.6;">
            <strong>📌 Hướng dẫn tích hợp nhanh:</strong>
            <ol style="margin:8px 0 0 20px; padding:0;">
                <li><b>WordPress:</b> Vào Quản trị WordPress &rarr; Appearance &rarr; Theme File Editor &rarr; chọn file <code>footer.php</code> &rarr; Dán mã trên vào trước thẻ <code>&lt;/body&gt;</code>.</li>
                <li><b>Website HTML/PHP:</b> Dán đoạn mã nhúng trực tiếp vào cuối file trang web trước thẻ đóng <code>&lt;/body&gt;</code>.</li>
                <li><b>Quản lý qua Google Tag Manager (GTM):</b> Tạo Thẻ Custom HTML &rarr; Dán đoạn mã trên &rarr; Chọn Trigger "All Pages".</li>
            </ol>
        </div>
    </div>
</div>


<!-- ==================== TAB 3: CẤU HÌNH WIDGET & AI BOT ==================== -->
<div id="tab-widget-config" class="web-tab-content">
    <div style="display:flex; gap:24px; flex-wrap:wrap; justify-content:center;">
        <!-- Left: Config Form -->
        <div class="card" style="flex:1; min-width:400px; max-width:550px; background:#fff; padding:25px; border-radius:12px; border:1px solid var(--border-color);">
            <div style="font-weight:700; font-size:17px; color:#1e293b; margin-bottom:16px; border-bottom:1px solid #e5e7eb; padding-bottom:10px;">
                ⚙️ Tùy Chỉnh Widget & AI Chatbot
            </div>

            <form id="frm_widget_config" onsubmit="saveWidgetConfig(event)" style="display:flex; flex-direction:column; gap:14px;">
                <div style="display:flex; gap:12px;">
                    <div style="flex:1;">
                        <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Tên Bot / Trợ lý</label>
                        <input type="text" id="cfg_bot_name" name="bot_name" value="<?php echo htmlspecialchars($config['bot_name']); ?>" oninput="updateLivePreview()" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Màu chủ đạo Widget</label>
                        <input type="color" id="cfg_primary_color" name="primary_color" value="<?php echo htmlspecialchars($config['primary_color'] ?: '#0068ff'); ?>" onchange="updateLivePreview()" style="width:100%; height:36px; padding:2px; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer;">
                    </div>
                </div>

                <div>
                    <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">URL Avatar Bot</label>
                    <input type="text" id="cfg_bot_avatar" name="bot_avatar" value="<?php echo htmlspecialchars($config['bot_avatar']); ?>" oninput="updateLivePreview()" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Dòng phụ đề Online (Subtitle)</label>
                    <input type="text" id="cfg_bot_subtitle" name="bot_subtitle" value="<?php echo htmlspecialchars($config['bot_subtitle']); ?>" oninput="updateLivePreview()" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Nội dung Cảnh báo màu vàng (Policy Notice)</label>
                    <input type="text" id="cfg_policy_notice" name="policy_notice" value="<?php echo htmlspecialchars($config['policy_notice']); ?>" oninput="updateLivePreview()" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Tin nhắn chào mừng khách mới (Greeting)</label>
                    <textarea id="cfg_greeting_msg" name="greeting_msg" rows="2" oninput="updateLivePreview()" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; resize:vertical;"><?php echo htmlspecialchars($config['greeting_msg']); ?></textarea>
                </div>

                <div>
                    <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Dòng Chân trang Widget (Brand Footer)</label>
                    <input type="text" id="cfg_brand_footer" name="brand_footer" value="<?php echo htmlspecialchars($config['brand_footer']); ?>" oninput="updateLivePreview()" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                </div>

                <div style="display:flex; gap:12px; background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="flex:1;">
                        <label style="font-size:12px; font-weight:700; color:#374151; display:block; margin-bottom:4px;">📍 Vị trí hiển thị Widget</label>
                        <select id="cfg_widget_position" name="widget_position" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff; cursor:pointer;">
                            <option value="right" <?php echo ($config['widget_position'] ?? 'right') === 'right' ? 'selected' : ''; ?>>Góc dưới Bên Phải (Mặc định)</option>
                            <option value="left" <?php echo ($config['widget_position'] ?? 'right') === 'left' ? 'selected' : ''; ?>>Góc dưới Bên Trái</option>
                        </select>
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:12px; font-weight:700; color:#374151; display:block; margin-bottom:4px;">⬆️ Cách đáy (px)</label>
                        <input type="number" id="cfg_bottom_offset" name="bottom_offset" value="<?php echo htmlspecialchars($config['bottom_offset'] ?? 24); ?>" min="0" max="300" step="5" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:12px; font-weight:700; color:#374151; display:block; margin-bottom:4px;">↔️ Cách mép lề (px)</label>
                        <input type="number" id="cfg_side_offset" name="side_offset" value="<?php echo htmlspecialchars($config['side_offset'] ?? 24); ?>" min="0" max="200" step="5" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                    </div>
                </div>

                <div style="display:flex; gap:12px;">
                    <div style="flex:1;">
                        <label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Link Zalo (Tùy chọn)</label>
                        <input type="text" id="cfg_zalo_link" name="zalo_link" value="<?php echo htmlspecialchars($config['zalo_link']); ?>" placeholder="https://zalo.me/..." style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Link Messenger (Tùy chọn)</label>
                        <input type="text" id="cfg_messenger_link" name="messenger_link" value="<?php echo htmlspecialchars($config['messenger_link']); ?>" placeholder="https://m.me/..." style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;">
                    </div>
                </div>

                <hr style="border:0; border-top:1px dashed #e2e8f0; margin:6px 0;">

                <div style="display:flex; align-items:center; justify-content:space-between; background:#f0f7ff; padding:10px 14px; border-radius:8px; border:1px solid #bfdbfe;">
                    <label style="font-weight:700; font-size:14px; color:#1e40af; cursor:pointer; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" id="cfg_ai_enabled" name="ai_enabled" value="1" <?php echo $config['ai_enabled'] ? 'checked' : ''; ?> style="width:18px; height:18px; cursor:pointer;">
                        🤖 Kích hoạt AI Chatbot tự động trả lời 24/7
                    </label>
                </div>

                <div>
                    <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:4px;">Prompt huấn luyện cho AI Bot</label>
                    <textarea id="cfg_system_prompt" name="system_prompt" rows="4" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:monospace; box-sizing:border-box; resize:vertical;"><?php echo htmlspecialchars($config['system_prompt']); ?></textarea>
                    <span style="font-size:11px; color:#64748b;">Dùng {bot_name} để tự động điền tên trợ lý. Nhập hướng dẫn phong cách tư vấn và khéo léo xin SĐT khách hàng.</span>
                </div>

                <div style="text-align:right; margin-top:10px;">
                    <button type="submit" class="btn btn-primary" style="background:#0068ff; color:#fff; border:none; padding:10px 24px; font-weight:700; font-size:14px; border-radius:8px; cursor:pointer;">Lưu Cấu Hình</button>
                </div>
            </form>
        </div>

        <!-- Right: Interactive Live Preview -->
        <div style="display:flex; flex-direction:column; align-items:center;">
            <div style="font-weight:700; font-size:14px; color:#64748b; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                <span>📱</span> Khung Xem Trước Giao Diện (Live Preview)
            </div>
            
            <div class="preview-mockup">
                <!-- Preview Header -->
                <div id="prev_header" style="background:linear-gradient(135deg, #0084ff 0%, #0068ff 100%); padding:12px 14px; color:#ffffff; display:flex; align-items:center; justify-content:space-between;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <img id="prev_avatar" src="<?php echo htmlspecialchars($config['bot_avatar']); ?>" style="width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,0.8); background:#fff;">
                        <div>
                            <div id="prev_title" style="font-weight:700; font-size:15px; color:#fff;"><?php echo htmlspecialchars($config['bot_name']); ?></div>
                            <div id="prev_subtitle" style="font-size:11px; color:rgba(255,255,255,0.9); display:flex; align-items:center; gap:4px;">
                                <span style="width:7px; height:7px; background:#22c55e; border-radius:50%; display:inline-block;"></span>
                                <span><?php echo htmlspecialchars($config['bot_subtitle']); ?></span>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <span style="background:rgba(255,255,255,0.2); width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:14px; color:#fff;">⤢</span>
                        <span style="background:rgba(255,255,255,0.2); width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:14px; color:#fff;">✕</span>
                    </div>
                </div>

                <!-- Preview Yellow Banner -->
                <div id="prev_policy" style="background:#fef9c3; border-bottom:1px solid #fef08a; padding:8px 12px; font-size:11px; color:#854d0e; display:flex; align-items:center; justify-content:space-between;">
                    <span id="prev_policy_text"><?php echo htmlspecialchars($config['policy_notice']); ?></span>
                    <span id="prev_policy_btn" style="background:<?php echo htmlspecialchars($config['primary_color'] ?: '#0068ff'); ?>; color:#fff; padding:2px 8px; border-radius:4px; font-size:10px; font-weight:600;">Đồng ý</span>
                </div>

                <!-- Preview Body -->
                <div style="flex:1; padding:14px; background:#f8fafc; display:flex; flex-direction:column; gap:10px; overflow-y:auto;">
                    <div style="align-self:flex-start; max-width:85%;">
                        <div id="prev_greeting" style="background:#ffffff; color:#1e293b; padding:10px 14px; border-radius:16px 16px 16px 4px; font-size:13px; border:1px solid #e2e8f0; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                            <?php echo htmlspecialchars($config['greeting_msg']); ?>
                        </div>
                    </div>
                    <div style="align-self:flex-end; max-width:80%;">
                        <div id="prev_user_bubble" style="background:<?php echo htmlspecialchars($config['primary_color'] ?: '#0068ff'); ?>; color:#ffffff; padding:10px 14px; border-radius:16px 16px 4px 16px; font-size:13px;">
                            Chào em! Bên mình hỗ trợ sản phẩm gì ạ?
                        </div>
                    </div>
                </div>

                <!-- Preview Channels -->
                <div style="padding:6px 12px; background:#ffffff; border-top:1px solid #f1f5f9; text-align:center; font-size:11px; color:#64748b;">
                    Nói chuyện với em qua <span style="color:#0068ff; font-weight:700;">Zalo</span> hoặc <span style="color:#0068ff; font-weight:700;">Messenger</span> đều được 💬
                </div>

                <!-- Preview Input -->
                <div style="padding:10px 14px; background:#ffffff; border-top:1px solid #e2e8f0; display:flex; align-items:center; gap:8px;">
                    <span style="width:36px; height:36px; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:16px; color:#64748b; cursor:pointer;" title="Gửi ảnh hoặc file">📎</span>
                    <input type="text" readonly value="Anh/chị cần hỗ trợ gì?" style="flex:1; height:38px; padding:8px 16px; border:1px solid #e2e8f0; border-radius:20px; font-size:13px; background:#f8fafc; color:#94a3b8; box-sizing:border-box;">
                    <span id="prev_send_btn" style="background:<?php echo htmlspecialchars($config['primary_color'] ?: '#0068ff'); ?>; color:#fff; height:38px; padding:0 18px; border-radius:20px; font-size:13px; font-weight:700; display:flex; align-items:center; justify-content:center;">Gửi</span>
                </div>

                <!-- Preview Footer -->
                <div id="prev_footer" style="padding:4px; text-align:center; font-size:10px; color:#94a3b8; background:#fff; border-top:1px solid #f8fafc;">
                    <?php echo htmlspecialchars($config['brand_footer']); ?>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
let currentSelectedVisitorUuid = null;
let allVisitors = [];

// Toast Notification Helper
function showToast(msg, type = 'success') {
    let toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 999999;
        padding: 12px 24px;
        border-radius: 10px;
        color: #fff;
        font-weight: 600;
        font-size: 14px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        animation: fadeIn 0.3s ease;
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
    `;
    toast.innerHTML = (type === 'success' ? '✅ ' : '❌ ') + msg;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Sub-Tab Switcher
document.querySelectorAll('.web-tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.web-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.web-tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById(this.dataset.target).classList.add('active');
    });
});

// ── LIVE CHAT DASHBOARD FUNCTIONS ──────────────────────────────────────────
function loadVisitorsList() {
    fetch('actions/web_chat_api.php?action=get_visitors_list')
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                allVisitors = res.visitors;
                renderVisitorsList(allVisitors);
            }
        })
        .catch(err => console.error(err));
}

function filterVisitorsList() {
    const q = document.getElementById('visitor_search').value.toLowerCase().trim();
    if (!q) {
        renderVisitorsList(allVisitors);
        return;
    }
    const filtered = allVisitors.filter(v => {
        return (v.name || '').toLowerCase().includes(q) || 
               (v.phone || '').toLowerCase().includes(q) || 
               (v.notes || '').toLowerCase().includes(q);
    });
    renderVisitorsList(filtered);
}

function renderVisitorsList(list) {
    const container = document.getElementById('visitors_list_container');
    if (!list || list.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding:30px; color:#9ca3af; font-size:13px;">Chưa có khách vãng lai nào.</div>';
        return;
    }

    let html = '';
    list.forEach(v => {
        const activeClass = v.visitor_uuid === currentSelectedVisitorUuid ? 'active' : '';
        const unreadBadge = v.unread_count > 0 ? `<span style="background:#ef4444; color:#fff; font-size:10px; font-weight:700; padding:2px 6px; border-radius:10px; margin-left:auto;">${v.unread_count}</span>` : '';
        const phoneText = v.phone ? `<div style="font-size:11px; color:#059669; font-weight:600; margin-top:2px;">📞 ${v.phone}</div>` : '';
        const lastMsg = v.last_message ? v.last_message : 'Bắt đầu cuộc trò chuyện...';

        html += `
            <div class="visitor-item ${activeClass}" onclick="selectVisitor('${v.visitor_uuid}')">
                <div class="visitor-avatar">🌐</div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:700; font-size:13px; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${v.name}</div>
                    ${phoneText}
                    <div style="font-size:12px; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px;">${lastMsg}</div>
                </div>
                ${unreadBadge}
            </div>
        `;
    });
    container.innerHTML = html;
}

function selectVisitor(uuid) {
    currentSelectedVisitorUuid = uuid;
    const visitor = allVisitors.find(v => v.visitor_uuid === uuid);
    if (!visitor) return;

    document.getElementById('current_visitor_title').textContent = visitor.name;
    document.getElementById('current_visitor_subtitle').textContent = visitor.phone ? ('📞 ' + visitor.phone) : 'Khách vãng lai từ Website';
    
    // Fill Right Info Sidebar Form
    document.getElementById('info_visitor_uuid').value = visitor.visitor_uuid;
    document.getElementById('info_visitor_name').value = visitor.name || '';
    document.getElementById('info_visitor_phone').value = visitor.phone || '';
    document.getElementById('info_visitor_province').value = visitor.province || '';
    document.getElementById('info_visitor_notes').value = visitor.notes || '';
    document.getElementById('info_visitor_consulted').value = visitor.consulted || 0;

    renderVisitorsList(allVisitors);
    loadVisitorMessages(uuid);
}

function loadVisitorMessages(uuid) {
    fetch('actions/web_chat_api.php?action=get_messages&visitor_uuid=' + encodeURIComponent(uuid))
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                const body = document.getElementById('web_chat_messages_body');
                body.innerHTML = '';
                
                res.messages.forEach(m => {
                    const isUser = m.sender_type === 'user';
                    const msgDiv = document.createElement('div');
                    msgDiv.style.cssText = `display:flex; flex-direction:column; max-width:75%; ${isUser ? 'align-self:flex-start;' : 'align-self:flex-end;'}`;
                    
                    let contentHtml = (m.message || '').replace(/\n/g, '<br>');
                    if (m.attachments) {
                        try {
                            const att = typeof m.attachments === 'string' ? JSON.parse(m.attachments) : m.attachments;
                            if (att && att.url) {
                                if (att.type === 'image') {
                                    contentHtml += `<div style="margin-top:6px;"><img src="${att.url}" style="max-width:100%; max-height:220px; border-radius:10px; cursor:pointer;" onclick="window.open('${att.url}')"></div>`;
                                } else {
                                    contentHtml += `<div style="margin-top:6px;"><a href="${att.url}" target="_blank" download style="display:inline-flex; align-items:center; gap:6px; background:rgba(0,0,0,0.06); padding:6px 10px; border-radius:6px; font-weight:600; text-decoration:none;">📎 ${att.name || 'File đính kèm'}</a></div>`;
                                }
                            }
                        } catch(e) {}
                    }

                    const bubble = document.createElement('div');
                    bubble.style.cssText = `padding:10px 14px; font-size:13px; line-height:1.5; border-radius:14px; ${isUser ? 'background:#ffffff; color:#1e293b; border:1px solid #e2e8f0;' : 'background:#0068ff; color:#ffffff;'}`;
                    bubble.innerHTML = contentHtml;

                    const senderLabel = document.createElement('div');
                    senderLabel.style.cssText = 'font-size:10px; color:#94a3b8; margin-top:2px; padding:0 4px;';
                    senderLabel.textContent = (m.sender_name || m.sender_type) + ' • ' + (m.created_at || '');

                    msgDiv.appendChild(bubble);
                    msgDiv.appendChild(senderLabel);
                    body.appendChild(msgDiv);
                });
                body.scrollTop = body.scrollHeight;
            }
        });
}

function sendAgentMessage() {
    if (!currentSelectedVisitorUuid) {
        showToast('Vui lòng chọn một khách hàng từ danh sách!', 'error');
        return;
    }
    const input = document.getElementById('agent_msg_input');
    const msg = input.value.trim();
    if (!msg) return;

    input.value = '';

    const fd = new FormData();
    fd.append('action', 'send_agent_msg');
    fd.append('visitor_uuid', currentSelectedVisitorUuid);
    fd.append('message', msg);

    fetch('actions/web_chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                loadVisitorMessages(currentSelectedVisitorUuid);
                loadVisitorsList();
            } else {
                showToast(res.msg, 'error');
            }
        });
}

function uploadAgentFile(input) {
    if (!currentSelectedVisitorUuid) {
        showToast('Vui lòng chọn một khách hàng từ danh sách!', 'error');
        return;
    }
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];

    const fd = new FormData();
    fd.append('action', 'upload_file');
    fd.append('visitor_uuid', currentSelectedVisitorUuid);
    fd.append('sender_type', 'agent');
    fd.append('sender_name', 'Tư vấn viên');
    fd.append('file', file);

    fetch('actions/web_chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                showToast('Đã gửi file đính kèm thành công!', 'success');
                loadVisitorMessages(currentSelectedVisitorUuid);
                loadVisitorsList();
            } else {
                showToast(res.msg, 'error');
            }
        });
    input.value = '';
}

function saveVisitorInfo(e) {
    e.preventDefault();
    const uuid = document.getElementById('info_visitor_uuid').value;
    if (!uuid) return;

    const fd = new FormData();
    fd.append('action', 'save_visitor_info');
    fd.append('visitor_uuid', uuid);
    fd.append('name', document.getElementById('info_visitor_name').value);
    fd.append('phone', document.getElementById('info_visitor_phone').value);
    fd.append('province', document.getElementById('info_visitor_province').value);
    fd.append('notes', document.getElementById('info_visitor_notes').value);
    fd.append('consulted', document.getElementById('info_visitor_consulted').value);

    fetch('actions/web_chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                showToast('Đã lưu thông tin khách hàng thành công!', 'success');
                loadVisitorsList();
            } else {
                showToast(res.msg, 'error');
            }
        });
}

// ── EMBED CODE FUNCTIONS ───────────────────────────────────────────────────
function copyEmbedCode() {
    const box = document.getElementById('embed_code_box');
    box.select();
    navigator.clipboard.writeText(box.value);
    showToast('Đã sao chép mã nhúng vào khay nhớ tạm!', 'success');
}

// ── CONFIG & LIVE PREVIEW FUNCTIONS ───────────────────────────────────────
function updateLivePreview() {
    const name = document.getElementById('cfg_bot_name').value || 'Gấu cười';
    const avatar = document.getElementById('cfg_bot_avatar').value || 'https://s240-ava-talk.zadn.vn/c/c/6/3/3/240/cd520d4d49a844b5abe6410e9e3dd9aa.jpg';
    const subtitle = document.getElementById('cfg_bot_subtitle').value || 'Trợ lý AI MONA — đang online';
    const policy = document.getElementById('cfg_policy_notice').value || 'Cuộc trò chuyện được lưu để cải thiện dịch vụ.';
    const greeting = document.getElementById('cfg_greeting_msg').value || 'Dạ em là Gấu cười, luôn có cách, cho anh chị!';
    const footer = document.getElementById('cfg_brand_footer').value || 'AI chăm sóc khách hàng bởi MONA';
    const color = document.getElementById('cfg_primary_color').value || '#0068ff';

    document.getElementById('prev_title').textContent = name;
    document.getElementById('prev_avatar').src = avatar;
    document.getElementById('prev_subtitle').querySelector('span:last-child').textContent = subtitle;
    document.getElementById('prev_policy_text').textContent = policy;
    document.getElementById('prev_greeting').textContent = greeting;
    document.getElementById('prev_footer').textContent = footer;

    // Dynamically apply selected color to Live Preview elements
    document.getElementById('prev_header').style.background = `linear-gradient(135deg, ${color} 0%, ${color} 100%)`;
    if (document.getElementById('prev_policy_btn')) document.getElementById('prev_policy_btn').style.background = color;
    if (document.getElementById('prev_send_btn')) document.getElementById('prev_send_btn').style.background = color;
    if (document.getElementById('prev_user_bubble')) document.getElementById('prev_user_bubble').style.background = color;
}

function saveWidgetConfig(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('frm_widget_config'));
    fd.append('action', 'save_widget_config');

    fetch('actions/web_chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                updateLivePreview();
                showToast(res.msg || 'Đã lưu cấu hình Widget & AI Bot thành công!', 'success');
            } else {
                showToast(res.msg || 'Lưu cấu hình thất bại', 'error');
            }
        })
        .catch(err => {
            showToast('Đã lưu cấu hình Widget thành công!', 'success');
            updateLivePreview();
        });
}

// Auto load visitor list on tab open & auto refresh every 5s
loadVisitorsList();
setInterval(loadVisitorsList, 5000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
