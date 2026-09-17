<?php
$current_page = 'live_chat';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin   = ($_SESSION['role'] === 'admin');

ob_start();
require_once __DIR__ . '/setup_live_chat.php'; // auto run setup for DB table
ob_end_clean(); // Clean output entirely so no random text is printed

// Fetch system_accounts phone request config & live chat status
$stmt_acc = $pdo->prepare("SELECT phone_request_enabled, phone_request_hours, phone_request_text, province_request_text, product_request_text, followup_request_enabled, followup_request_hours, followup_request_text, sales_list, phone_request_limit, enable_live_chat, enable_live_chat_oa, enable_live_chat_tiktok, enable_website, enable_customers FROM system_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$acc_setup = $stmt_acc->fetch(PDO::FETCH_ASSOC) ?: [];
$phone_request_enabled = (int)($acc_setup['phone_request_enabled'] ?? 0);
$phone_request_hours = (int)($acc_setup['phone_request_hours'] ?? 1);
$phone_request_text = $acc_setup['phone_request_text'] ?? '';
$province_request_text = $acc_setup['province_request_text'] ?? '';
$product_request_text = $acc_setup['product_request_text'] ?? '';
$phone_request_limit = (int)($acc_setup['phone_request_limit'] ?? 3);
$followup_request_enabled = (int)($acc_setup['followup_request_enabled'] ?? 0);
$followup_request_hours = (int)($acc_setup['followup_request_hours'] ?? 12);
$followup_request_text = $acc_setup['followup_request_text'] ?? '';
$sales_list = $acc_setup['sales_list'] ?? '';

$enable_live_chat = $is_admin ? 1 : (int)($acc_setup['enable_live_chat'] ?? 1);
$enable_live_chat_oa = $is_admin ? 1 : (int)($acc_setup['enable_live_chat_oa'] ?? 1);
$enable_live_chat_tiktok = $is_admin ? 1 : (int)($acc_setup['enable_live_chat_tiktok'] ?? 1);
$enable_website = $is_admin ? 1 : (int)($acc_setup['enable_website'] ?? 1);
$enable_customers = $is_admin ? 1 : (int)($acc_setup['enable_customers'] ?? 1);

if (!$enable_live_chat) {
    echo '<div class="page-title">Truy cập bị từ chối</div>';
    echo '<div class="card" style="border-left: 4px solid #ef4444; padding: 20px;">';
    echo '  <h3 style="margin-top:0; color:#ef4444;">⚠️ Tính Năng Đã Bị Tắt</h3>';
    echo '  <p style="color:#4b5563; font-size:14px; margin-bottom:0;">Tính năng Live Chat Facebook Fanpage đã bị tắt cho tài khoản của bạn. Vui lòng liên hệ Admin để kích hoạt lại.</p>';
    echo '</div>';
    include 'includes/footer.php';
    exit;
}

// Fetch all pages for sidebar (owned + shared)
$stmt2 = $pdo->prepare("
    (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id, u.name AS user_name, p.capi_pixel_id, p.capi_token, p.auto_send_capi
     FROM pages p JOIN users u ON p.user_id = u.id
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id, u.name AS user_name, p.capi_pixel_id, p.capi_token, p.auto_send_capi
     FROM pages p
     JOIN page_shares ps ON p.page_id = ps.page_id
     JOIN users u ON p.user_id = u.id
     WHERE ps.shared_with_account_id = :aid2)
    ORDER BY name ASC
");
$stmt2->bindValue(':aid',  $account_id, PDO::PARAM_INT);
$stmt2->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt2->execute();
$pages      = $stmt2->fetchAll(PDO::FETCH_ASSOC);
$pages_json = json_encode($pages);

$selected_page_id = $_GET['page_id'] ?? '';
$selected_conv_id = $_GET['conv_id'] ?? '';
$selected_sender_id = $_GET['sender_id'] ?? '';
?>
<style>
    .platform-tab-btn:hover {
        color: #0068ff !important;
        border-bottom-color: #cbd5e1 !important;
    }
    .platform-tab-btn.active:hover {
        border-bottom-color: #0068ff !important;
    }
    
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
</style>

<!-- Platform Switcher Tabs -->
<div class="platform-tabs" style="display: flex; gap: 20px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; padding-bottom: 0;">
    <a href="live_chat.php" class="platform-tab-btn <?php echo ($current_page === 'live_chat') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'live_chat') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'live_chat') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>📘</span> Facebook Fanpage
    </a>
    <a href="live-chat-oa.php" class="platform-tab-btn <?php echo ($current_page === 'live_chat_zalo') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'live_chat_zalo') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'live_chat_zalo') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>💬</span> Zalo Official Account
    </a>
    <a href="live-chat-tiktok.php" class="platform-tab-btn <?php echo ($current_page === 'live_chat_tiktok') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'live_chat_tiktok') ? '#fe2c55' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'live_chat_tiktok') ? '#fe2c55' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🎵</span> TikTok
    </a>
    <a href="website.php" class="platform-tab-btn <?php echo ($current_page === 'website') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'website') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'website') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🌐</span> Live Chat Website
    </a>
    <a href="customers.php" class="platform-tab-btn <?php echo ($current_page === 'customers') ? 'active' : ''; ?>" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: <?php echo ($current_page === 'customers') ? '#0068ff' : '#4b5563'; ?>; border-bottom: 3px solid <?php echo ($current_page === 'customers') ? '#0068ff' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>👥</span> Khách Hàng
    </a>
</div>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
    <div class="page-title" style="margin-bottom:0;">💬 Live Chat & Tin Nhắn</div>
    <button onclick="openBotSettings()" class="btn btn-secondary" style="background:#f59e0b; color:#fff; border:none; display:flex; align-items:center; gap:5px; font-weight:600;"><span style="font-size:16px;">⚙️</span> Cài đặt Bot Tự Động</button>
</div>

<!-- Tabs Navigation -->
<div class="zalo-tabs">
    <button class="zalo-tab-btn active" data-target="tab-livechat">
        <span>💬</span> Live Chat
    </button>
    <button class="zalo-tab-btn" data-target="tab-bot-settings">
        <span>🤖</span> Bot Tự Động
    </button>
    <button class="zalo-tab-btn" data-target="tab-capi-settings">
        <span>🔌</span> Cấu hình CAPI
    </button>
</div>

<!-- ==================== TAB 2: BOT TỰ ĐỘNG ==================== -->
<div id="tab-bot-settings" class="zalo-tab-content">
    <div class="card" style="max-width:800px; margin:0 auto; background:#fff; padding:25px; border-radius:10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); border: 1px solid var(--border-color);">
        <div style="font-weight:600; font-size:17px; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:20px;">
            🤖 Cài đặt Bot Tự Động (Inbox)
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
            <p style="font-size:13px; color:var(--text-muted); margin-top:0;">Tin nhắn sẽ tự động gửi khi khách hàng gửi tin nhắn đầu tiên hoặc bấm nút Bắt Đầu.</p>
            <div id="welcome_rules_list" style="margin-bottom:15px; max-height: 250px; overflow-y:auto;">
                <div style="text-align:center; padding:10px; color:var(--text-muted); font-size:13px;">Đang tải...</div>
            </div>
            <button onclick="openRuleForm('welcome')" class="btn btn-secondary" style="width:100%; text-align:center; display:block; padding:8px; border:1px dashed var(--border-color); background:var(--bg-color); color:var(--text-main); border-radius:6px; font-weight:600;">+ Thêm nội dung chào mừng mới</button>
        </div>

        <!-- Nội dung Tab 2: Tin nhắn theo từ khóa -->
        <div id="content_keyword" style="display:none;">
            <p style="font-size:13px; color:var(--text-muted); margin-top:0;">Tin nhắn sẽ tự động gửi nếu câu nói của khách hàng có chứa các từ khóa dưới đây.</p>
            <div id="keyword_rules_list" style="margin-bottom:15px; max-height: 250px; overflow-y:auto;">
                <div style="text-align:center; padding:10px; color:var(--text-muted); font-size:13px;">Đang tải...</div>
            </div>
            <button onclick="openRuleForm('keyword')" class="btn btn-secondary" style="width:100%; text-align:center; display:block; padding:8px; border:1px dashed var(--border-color); background:var(--bg-color); color:var(--text-main); border-radius:6px; font-weight:600;">+ Thêm cấu hình từ khóa mới</button>
        </div>

        <!-- Nội dung Tab 3: Chat bot AI tự trả lời -->
        <div id="content_ai_reply" style="display:none;">
            <p style="font-size:13px; color:var(--text-muted); margin-top:0;">Bot sẽ sử dụng AI để tự động trả lời khách hàng dựa trên Prompt bạn cấu hình. <b>Lưu ý:</b> Cần cài đặt API Key AI ở trang Cài đặt AI.</p>
            <div id="ai_reply_rules_list" style="margin-bottom:15px; max-height: 250px; overflow-y:auto;">
                <div style="text-align:center; padding:10px; color:var(--text-muted); font-size:13px;">Đang tải...</div>
            </div>
            <button onclick="openRuleForm('ai_reply')" class="btn btn-secondary" style="width:100%; text-align:center; display:block; padding:8px; border:1px dashed var(--border-color); background:var(--bg-color); color:var(--text-main); border-radius:6px; font-weight:600;">+ Thêm cấu hình Bot AI mới</button>
        </div>

        <!-- Nội dung Tab 4: Tự động xin thông tin (SĐT -> Tỉnh -> Nhu cầu) -->
        <div id="content_phone_request" style="display:none;">
            <p style="font-size:13px; color:var(--text-muted); margin-top:0;">Hệ thống sẽ tự động quét và gửi tin nhắn xin các thông tin còn thiếu của khách hàng theo thứ tự ưu tiên (SĐT -> Tỉnh thành -> Nhu cầu/Sản phẩm) sau X giờ kể từ tin nhắn cuối cùng của họ (tối đa 24 giờ).</p>
            
            <form id="frm_phone_request" onsubmit="savePhoneRequestSettings(event)" style="display:flex; flex-direction:column; gap:15px;">
                <div style="display:flex; align-items:center; justify-content:space-between; background:var(--bg-color); padding:10px; border-radius:6px; border:1px solid var(--border-color);">
                    <label style="font-weight:600; font-size:14px; color:var(--text-main); cursor:pointer; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" id="phone_request_enabled" name="phone_request_enabled" value="1" <?php echo $phone_request_enabled ? 'checked' : ''; ?> style="width:18px; height:18px; cursor:pointer;">
                        Kích hoạt tự động xin thông tin
                    </label>
                </div>

                <div style="display:flex; gap:15px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:200px; display:flex; flex-direction:column; gap:5px;">
                        <label style="font-weight:600; font-size:13px; color:var(--text-main);">Thời gian chờ gửi tin nhắn (giờ)</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="number" id="phone_request_hours" name="phone_request_hours" min="1" max="24" value="<?php echo $phone_request_hours; ?>" style="width:80px; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; background:var(--card-bg); color:var(--text-main);">
                            <span style="font-size:13px; color:var(--text-muted);">giờ (từ 1 đến 24 giờ. Khuyến nghị: 1-2 giờ)</span>
                        </div>
                    </div>
                    <div style="flex:1; min-width:200px; display:flex; flex-direction:column; gap:5px;">
                        <label style="font-weight:600; font-size:13px; color:var(--text-main);">Số lần xin tối đa</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="number" id="phone_request_limit" name="phone_request_limit" min="1" max="10" value="<?php echo $phone_request_limit; ?>" style="width:80px; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; background:var(--card-bg); color:var(--text-main);">
                            <span style="font-size:13px; color:var(--text-muted);">lần (tối đa 10 lần. Mặc định: 3 lần)</span>
                        </div>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main); display:flex; align-items:center; gap:5px;">
                        📞 Mẫu tin nhắn xin Số điện thoại
                    </label>
                    <textarea id="phone_request_text" name="phone_request_text" rows="2" placeholder="Ví dụ: Dạ {name} cho em xin số điện thoại để tiện liên hệ tư vấn ạ!" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; resize:vertical; background:var(--card-bg); color:var(--text-main);"><?php echo htmlspecialchars($phone_request_text); ?></textarea>
                    <span style="font-size:11px; color:var(--text-muted);">Dùng {name} để gọi tên khách. Để trống nếu không muốn tự động xin SĐT.</span>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main); display:flex; align-items:center; gap:5px;">
                        📍 Mẫu tin nhắn xin Tỉnh thành
                    </label>
                    <textarea id="province_request_text" name="province_request_text" rows="2" placeholder="Ví dụ: Dạ hiện tại {name} đang ở tỉnh thành nào để em báo phí ship cho mình ạ?" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; resize:vertical; background:var(--card-bg); color:var(--text-main);"><?php echo htmlspecialchars($province_request_text); ?></textarea>
                    <span style="font-size:11px; color:var(--text-muted);">Gửi khi đã có SĐT nhưng chưa có Tỉnh thành. Để trống để bỏ qua bước này.</span>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main); display:flex; align-items:center; gap:5px;">
                        🛍️ Mẫu tin nhắn xin Nhu cầu / Sản phẩm quan tâm
                    </label>
                    <textarea id="product_request_text" name="product_request_text" rows="2" placeholder="Ví dụ: Dạ {name} đang quan tâm đến dòng sản phẩm nào bên em để em gửi thông tin chi tiết ạ?" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; resize:vertical; background:var(--card-bg); color:var(--text-main);"><?php echo htmlspecialchars($product_request_text); ?></textarea>
                    <span style="font-size:11px; color:var(--text-muted);">Gửi khi đã có SĐT và Tỉnh thành nhưng chưa có ghi chú/nhu cầu. Để trống để bỏ qua bước này.</span>
                </div>

                <?php
                $followup_val = $followup_request_hours;
                $followup_unit = 'hours';
                if ($followup_request_hours >= 24 && $followup_request_hours % 24 === 0) {
                    $followup_val = $followup_request_hours / 24;
                    $followup_unit = 'days';
                }
                ?>

                <hr style="border:0; border-top:1px dashed var(--border-color); margin:10px 0;">

                <div style="display:flex; align-items:center; justify-content:space-between; background:var(--bg-color); padding:10px; border-radius:6px; border:1px solid var(--border-color);">
                    <label style="font-weight:600; font-size:14px; color:var(--text-main); cursor:pointer; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" id="followup_request_enabled" name="followup_request_enabled" value="1" <?php echo $followup_request_enabled ? 'checked' : ''; ?> style="width:18px; height:18px; cursor:pointer;">
                        Kích hoạt gửi tin CSKH/Follow-up sau khi đủ thông tin
                    </label>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main);">Thời gian chờ gửi tin</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="number" id="followup_request_val" min="1" max="720" value="<?php echo $followup_val; ?>" style="width:80px; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; background:var(--card-bg); color:var(--text-main);">
                        <select id="followup_request_unit" style="padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:14px; background:var(--card-bg); color:var(--text-main);">
                            <option value="hours" <?php echo $followup_unit === 'hours' ? 'selected' : ''; ?>>Giờ</option>
                            <option value="days" <?php echo $followup_unit === 'days' ? 'selected' : ''; ?>>Ngày</option>
                        </select>
                        <span style="font-size:13px; color:var(--text-muted);">(Lưu ý: FB giới hạn phản hồi trong 24 giờ. Cấu hình > 24h/1 ngày có thể không gửi được trên FB)</span>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-weight:600; font-size:13px; color:var(--text-main); display:flex; align-items:center; gap:5px;">
                        ✉️ Mẫu tin nhắn CSKH/Follow-up
                    </label>
                    <textarea id="followup_request_text" name="followup_request_text" rows="2" placeholder="Ví dụ: Dạ {name} đã nhận được báo giá bên em chưa ạ?" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; resize:vertical; background:var(--card-bg); color:var(--text-main);"><?php echo htmlspecialchars($followup_request_text); ?></textarea>
                    <span style="font-size:11px; color:var(--text-muted);">Gửi sau khi khách hàng đã cung cấp đủ thông tin (không còn thông tin nào cần xin). Dùng {name} để gọi tên.</span>
                </div>

                <div style="text-align:right; margin-top:5px;">
                    <button type="submit" class="btn btn-primary" style="background:#0068ff; color:#fff; border:none; padding:8px 20px; font-weight:600; border-radius:6px; cursor:pointer;">Lưu cấu hình</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form Thêm/Sửa Quy tắc Bot -->
<div id="botRuleFormModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:20px; border-radius:10px; width:100%; max-width:450px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h4 id="rule_form_title" style="margin-top:0; border-bottom:1px solid #e5e7eb; padding-bottom:10px;">Thêm quy tắc mới</h4>
        <form id="frm_bot_rule" onsubmit="saveBotRule(event)">
            <input type="hidden" id="rule_id" value="0">
            <input type="hidden" id="rule_type" value="">
            
            <div id="rule_keyword_group" style="margin-bottom:15px; display:none;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px;">Từ khóa (Cách nhau bằng dấu phẩy)</label>
                <input type="text" id="rule_keywords" placeholder="VD: inbox, giá, bao nhiêu" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
            </div>

            <div id="rule_delay_group" style="margin-bottom:15px; display:none;">
                <div style="display:flex; gap:15px;">
                    <div style="flex:1;">
                        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px;">Thời gian chờ (giây)</label>
                        <input type="number" id="rule_delay_seconds" min="0" max="60" value="10" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                        <div style="font-size:11px; color:#6b7280; margin-top:4px;">Chờ X giây để gom nhiều tin nhắn. Mặc định 10s. Để 0 để trả lời ngay.</div>
                    </div>
                    <div style="flex:1;">
                        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px;">Lịch sử cuộc gọi (Tin nhắn)</label>
                        <input type="number" id="rule_history_count" min="0" max="20" value="6" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                        <div style="font-size:11px; color:#6b7280; margin-top:4px;">Lấy X tin nhắn gần nhất làm ngữ cảnh. Mặc định 6 tin.</div>
                    </div>
                </div>
            </div>

            <div style="margin-bottom:15px;">
                <label id="lbl_rule_message" style="display:block; font-size:13px; font-weight:600; margin-bottom:5px;">Nội dung phản hồi <span style="color:red;">*</span></label>
                <textarea id="rule_message" rows="4" required placeholder="Nhập tin nhắn..." style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box; resize:vertical;"></textarea>
                <div id="hint_rule_message" style="font-size:11px; color:#6b7280; margin-top:4px;">Bạn có thể dùng {name} để gọi tên khách. Mỗi dòng 1 mẫu câu để chọn ngẫu nhiên.</div>
            </div>

            <!-- Active Hours Settings -->
            <div style="margin-bottom:15px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px;">Thời gian hoạt động</label>
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

            <div style="margin-bottom:15px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:5px;">
                    <label style="display:block; font-size:13px; font-weight:600;">Áp dụng cho Fanpage</label>
                    <?php
                        $unique_users = [];
                        if(!empty($pages)){
                            foreach($pages as $p) $unique_users[$p['user_name']] = true;
                        }
                        $unique_users = array_keys($unique_users);
                        sort($unique_users);
                    ?>
                    <select id="filter_user" onchange="filterPagesByUser()" style="padding:4px 8px; font-size:12px; border-radius:4px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer;">
                        <option value="ALL">-- Tất cả người quản lý --</option>
                        <?php foreach($unique_users as $u): ?>
                            <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="max-height:150px; overflow-y:auto; border:1px solid #d1d5db; border-radius:6px; padding:10px; background:#f9fafb;">
                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-weight:600; cursor:pointer; color:#0284c7; font-size:13px;">
                        <input type="checkbox" id="rule_pages_all" onchange="toggleAllPages(this)" checked> Chọn tất cả (theo bộ lọc)
                    </label>
                    <div id="rule_pages_list">
                        <?php if(!empty($pages)): foreach($pages as $p): ?>
                            <label class="page_item_label" data-user="<?php echo htmlspecialchars($p['user_name']); ?>" style="display:flex; align-items:center; gap:10px; margin-bottom:8px; cursor:pointer; font-size:13px; background:#fff; padding:8px 12px; border:1px solid #e5e7eb; border-radius:6px; transition:all 0.2s;">
                                <input type="checkbox" class="rule_page_cb" value="<?php echo htmlspecialchars($p['page_id']); ?>" onchange="checkSelectAll()" checked style="margin:0; width:16px; height:16px;">
                                <img src="<?php echo htmlspecialchars($p['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($p['name']).'&background=random'); ?>" style="width:28px; height:28px; border-radius:50%; object-fit:cover; border:1px solid #f3f4f6;">
                                <div style="display:flex; flex-direction:column;">
                                    <span style="font-weight:600; color:#1f2937; line-height:1.2;"><?php echo htmlspecialchars($p['name']); ?></span>
                                    <span style="font-size:11px; color:#6b7280; margin-top:2px;">👤 <?php echo htmlspecialchars($p['user_name']); ?></span>
                                </div>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <div style="display:flex; align-items:center; gap:8px; margin-bottom:20px;">
                <input type="checkbox" id="rule_is_active" checked style="width:16px;height:16px;cursor:pointer;">
                <label for="rule_is_active" style="font-size:13px; font-weight:600; cursor:pointer;">Đang bật (Active)</label>
            </div>

            <div style="text-align: right; display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('botRuleFormModal').style.display='none';" class="btn" style="background:#f3f4f6; color:#374151;">Hủy</button>
                <button type="submit" class="btn btn-primary" id="btn_save_rule">Lưu</button>
            </div>
        </form>
    </div>
</div>

<script>
let botRules = [];

function openBotSettings() {
    const tabBtn = document.querySelector('[data-target="tab-bot-settings"]');
    if (tabBtn) {
        tabBtn.click();
    }
}

function switchBotTab(tab) {
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
}

function loadBotRules() {
    fetch('actions/manage_bot_rules.php?action=list')
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                botRules = res.data;
                renderBotRules('welcome');
                renderBotRules('keyword');
                renderBotRules('ai_reply');
            } else {
                showToast('Lỗi tải cấu hình: ' + res.msg, 'error');
            }
        }).catch(e => console.error(e));
}

function renderBotRules(type) {
    const listDiv = document.getElementById(type + '_rules_list');
    const rules = botRules.filter(r => r.rule_type === type);
    
    if (rules.length === 0) {
        listDiv.innerHTML = '<div style="padding:15px; text-align:center; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; color:#6b7280; font-size:13px;">Chưa có cấu hình nào.</div>';
        return;
    }
    
    let html = '';
    rules.forEach(r => {
        let pageStr = r.pages_scope === 'ALL' ? '<span style="color:#0284c7;font-weight:600;">Tất cả Page</span>' : '<span style="color:#10b981;font-weight:600;">Một số Fanpage</span>';
        if(r.pages_scope !== 'ALL') {
            try {
                const scopeArr = JSON.parse(r.pages_scope);
                if (Array.isArray(scopeArr)) {
                    let pNames = scopeArr.map(id => {
                        const p = allPages.find(x => x.page_id == id);
                        return p ? p.name : id;
                    });
                    if (scopeArr.length === 1) {
                        pageStr = '<span style="color:#10b981;font-weight:600;" title="'+pNames[0]+'">1 Fanpage</span>';
                    } else {
                        pageStr = '<span style="color:#10b981;font-weight:600; cursor:help;" title="'+pNames.join(', ')+'">'+scopeArr.length+' Fanpages</span>';
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
        
        html += `<div style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px; margin-bottom:10px; position:relative;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div style="flex:1; min-width:0;">
                    ${activeStr} ${timeStr} <span style="font-size:11px; color:#6b7280; margin-left:5px;">Áp dụng: ${pageStr}</span>
                    <div style="margin-top:6px;">
                        ${kwHtml}
                        <div style="font-size:13px; color:#374151; white-space:pre-wrap; background:#f3f4f6; padding:8px; border-radius:4px;">${r.message}</div>
                    </div>
                </div>
                <div style="display:flex; gap:6px; margin-left:10px;">
                    <button onclick="editBotRule(${r.id})" style="background:none; border:none; font-size:14px; cursor:pointer; color:#0284c7;" title="Sửa">✏️</button>
                    <button onclick="deleteBotRule(${r.id})" style="background:none; border:none; font-size:14px; cursor:pointer; color:#ef4444;" title="Xóa">🗑️</button>
                </div>
            </div>
        </div>`;
    });
    listDiv.innerHTML = html;
}

function openRuleForm(type, rule = null) {
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
        document.getElementById('rule_message').placeholder = 'Ví dụ: Bạn là một chuyên gia tư vấn thời trang. Hãy trả lời ngắn gọn, lịch sự...';
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

function toggleActiveTimeFields() {
    const val = document.getElementById('rule_active_time_type').value;
    document.getElementById('rule_active_time_range').style.display = (val === 'CUSTOM') ? 'flex' : 'none';
}

function editBotRule(id) {
    const r = botRules.find(x => x.id == id);
    if(r) openRuleForm(r.rule_type, r);
}

function deleteBotRule(id) {
    if(!confirm('Bạn có chắc muốn xóa cấu hình này?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fetch('actions/manage_bot_rules.php', { method:'POST', body:fd })
        .then(r=>r.json()).then(res=>{
            if(res.status==='success') {
                loadBotRules();
                showToast('Đã xóa cấu hình thành công.', 'success');
            } else {
                showToast(res.msg, 'error');
            }
        });
}

function filterPagesByUser() {
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

function toggleAllPages(cb) {
    const labels = document.querySelectorAll('.page_item_label');
    labels.forEach(lbl => {
        if (lbl.style.display !== 'none') {
            lbl.querySelector('.rule_page_cb').checked = cb.checked;
        }
    });
    checkSelectAll();
}

function checkSelectAll() {
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

function saveBotRule(e) {
    e.preventDefault();
    const btn = document.getElementById('btn_save_rule');
    
    const totalCbs = document.querySelectorAll('.rule_page_cb');
    const checkedCbs = document.querySelectorAll('.rule_page_cb:checked');
    
    let pagesScope = 'ALL';
    if (totalCbs.length !== checkedCbs.length) {
        const checkedVals = Array.from(checkedCbs).map(el => el.value);
        pagesScope = JSON.stringify(checkedVals);
        if (checkedVals.length === 0) {
            showToast('Vui lòng chọn ít nhất 1 Fanpage!', 'error');
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

function savePhoneRequestSettings(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerText = 'Đang lưu...';
    
    const fd = new FormData();
    fd.append('phone_request_enabled', document.getElementById('phone_request_enabled').checked ? 1 : 0);
    fd.append('phone_request_hours', document.getElementById('phone_request_hours').value);
    fd.append('phone_request_text', document.getElementById('phone_request_text').value);
    fd.append('province_request_text', document.getElementById('province_request_text').value);
    fd.append('product_request_text', document.getElementById('product_request_text').value);
    fd.append('phone_request_limit', document.getElementById('phone_request_limit').value);
    
    const followupVal = parseInt(document.getElementById('followup_request_val').value) || 12;
    const followupUnit = document.getElementById('followup_request_unit').value;
    const followupHours = followupUnit === 'days' ? followupVal * 24 : followupVal;
    
    fd.append('followup_request_enabled', document.getElementById('followup_request_enabled').checked ? 1 : 0);
    fd.append('followup_request_hours', followupHours);
    fd.append('followup_request_text', document.getElementById('followup_request_text').value);
    
    fetch('actions/save_auto_reply.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            showToast('Đã lưu cấu hình tự động xin thông tin thành công!', 'success');
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
</script>

<!-- ==================== TAB 3: CẤU HÌNH CAPI ==================== -->
<div id="tab-capi-settings" class="zalo-tab-content">
    <div class="card" style="max-width:800px; margin:0 auto; background:#fff; padding:25px; border-radius:10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); border: 1px solid var(--border-color);">
        <div style="font-weight:600; font-size:17px; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:20px;">
            🔌 Cấu hình Facebook Conversions API (CAPI)
        </div>
        <p style="font-size:13px; color:var(--text-muted); margin-top:0; margin-bottom:20px;">
            Kết nối Facebook CAPI để tự động gửi sự kiện <strong>Lead</strong> khi khách hàng cung cấp Số điện thoại. Hệ thống sẽ mã hóa SHA-256 dữ liệu trước khi gửi theo tiêu chuẩn bảo mật của Facebook.
        </p>

        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Chọn Fanpage để cấu hình (Có thể chọn nhiều)</label>
            <div style="display:flex; gap:0px; border:1px solid var(--border-color); border-radius:6px 6px 0 0; background:#fff; overflow:hidden;">
                <input type="text" id="capi_page_search" oninput="filterCapiPages()" placeholder="🔍 Tìm nhanh tên hoặc ID Fanpage..." style="flex:1;min-width:0;padding:9px 12px;border:none;font-size:13px;box-sizing:border-box;outline:none;">
                <select id="capi_filter_status" onchange="filterCapiPages()" style="width:180px;flex-shrink:0;padding:9px 12px;border:none;border-left:1px solid var(--border-color);font-size:13px;box-sizing:border-box;outline:none;background:#f9fafb;color:#374151;cursor:pointer;font-weight:600;">
                    <option value="all">Tất cả trang</option>
                    <option value="configured">Đã cấu hình CAPI</option>
                    <option value="unconfigured">Chưa cấu hình</option>
                </select>
            </div>
            
            <div style="max-height:180px; overflow-y:auto; border:1px solid var(--border-color); border-radius:0 0 6px 6px; padding:10px; background:#fafafa; box-sizing:border-box;">
                <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #e5e7eb; font-size:13px; font-weight:600; cursor:pointer; user-select:none;">
                    <input type="checkbox" id="capi_select_all_pages" onchange="toggleCapiSelectAll(this)"> <span>Chọn tất cả trang đang hiển thị</span>
                </label>
                <div id="capi_pages_list" style="display:flex; flex-direction:column; gap:8px;">
                    <!-- Checkboxes được render động bằng JavaScript -->
                </div>
            </div>
        </div>

        <div id="capi_form_area" style="display:none;">
            <form id="frm_capi_settings" onsubmit="saveCapiSettings(event)" style="display:flex;flex-direction:column;gap:14px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Pixel ID (Dataset ID)</label>
                    <input type="text" id="capi_pixel_id" placeholder="VD: 123456789012345" style="width:100%;padding:10px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;">
                    <p style="font-size:11px;color:#9ca3af;margin:4px 0 0;">Lấy từ Facebook Events Manager → Data Sources → Pixel ID hoặc Dataset ID.</p>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">CAPI Access Token</label>
                    <input type="text" id="capi_token" placeholder="Token được tạo từ Events Manager" style="width:100%;padding:10px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;">
                    <p style="font-size:11px;color:#9ca3af;margin:4px 0 0;">Vào Events Manager → Settings → Generate Access Token (hoặc tạo qua Business Manager System User).</p>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Mã sự kiện Test (Tùy chọn - Nhập để xem trực tiếp trong tab Kiểm tra sự kiện)</label>
                    <input type="text" id="capi_test_code" placeholder="VD: TEST12345" style="width:100%;padding:10px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;">
                    <p style="font-size:11px;color:#9ca3af;margin:4px 0 0;">Lấy từ tab "Kiểm tra sự kiện" (Test Events) của Pixel trên Trình quản lý sự kiện Facebook.</p>
                </div>
                <div style="display:flex; align-items:center; gap:8px; margin: 4px 0;">
                    <input type="checkbox" id="auto_send_capi" style="width:16px; height:16px; cursor:pointer;" checked>
                    <label for="auto_send_capi" style="font-size:13px; font-weight:600; color:#374151; cursor:pointer;">Tự động gửi CAPI khi khách cho SĐT</label>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 20px;font-weight:600;border:none;border-radius:6px;background:#0284c7;color:#fff;cursor:pointer;">💾 Lưu cấu hình</button>
                    <button type="button" onclick="testCapiEvent()" class="btn btn-secondary" style="padding:10px 20px;font-weight:600;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb;color:#374151;cursor:pointer;">🧪 Gửi sự kiện Test</button>
                </div>
                <div id="capi_status" style="display:none; font-size:13px; font-weight:600; padding:10px; border-radius:6px; margin-top:4px;"></div>
            </form>

            <div style="margin-top:20px; padding:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; font-size:12px; color:#166534;">
                <div style="font-weight:700; margin-bottom:6px;">📋 Hướng dẫn nhanh:</div>
                <ol style="margin:0; padding-left:18px; line-height:1.8;">
                    <li>Vào <a href="https://business.facebook.com/events_manager2" target="_blank" style="color:#0284c7;font-weight:600;">Facebook Events Manager</a></li>
                    <li>Chọn Pixel/Dataset của bạn → vào tab <strong>Settings</strong></li>
                    <li>Cuộn xuống mục <strong>Conversions API</strong> → Nhấn <strong>Generate Access Token</strong></li>
                    <li>Copy <strong>Pixel ID</strong> và <strong>Access Token</strong> dán vào form trên</li>
                    <li>Nhấn <strong>Gửi sự kiện Test</strong> để kiểm tra kết nối</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- ==================== TAB 1: LIVE CHAT ==================== -->
<div id="tab-livechat" class="zalo-tab-content active">
    <div class="livechat-container" id="livechatContainer" style="display:flex;gap:0;height:calc(100vh - 140px);min-height:520px;">

    <!-- ── Sidebar: Fanpage List ─────────────────────────────────────────── -->
    <div class="lc-sidebar" style="width:200px;flex-shrink:0;background:var(--card-bg);border:1px solid var(--border-color);border-radius:10px 0 0 10px;display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:10px 14px;font-weight:700;font-size:13px;border-bottom:1px solid var(--border-color);background:#f9fafb;letter-spacing:.3px;">
            📄 Fanpages
        </div>
        <div style="padding:8px 10px;border-bottom:1px solid var(--border-color);background:#f9fafb;">
            <input type="text" id="page_search" placeholder="🔍 Tìm tên page..." style="width:100%;padding:6px 10px;margin-bottom:8px;border:1px solid var(--border-color);border-radius:6px;font-size:12px;box-sizing:border-box;outline:none;">
            <label style="font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; user-select: none;">
                <input type="checkbox" id="chk_merge_all"> <b style="color: #0284c7;">Gộp tất cả Fanpage</b>
            </label>
        </div>
        <div id="page_sidebar" style="flex:1;overflow-y:auto;">
            <?php if (empty($pages)): ?>
            <div style="padding:16px;font-size:12px;color:var(--text-muted);text-align:center;">Chưa có fanpage nào</div>
            <?php else: foreach ($pages as $p): ?>
            <div class="page-tab" data-page-id="<?php echo htmlspecialchars($p['page_id']); ?>"
                 data-user-id="<?php echo (int)$p['user_id']; ?>"
                 title="<?php echo htmlspecialchars($p['name']); ?> — <?php echo htmlspecialchars($p['user_name']); ?>"
                 style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);transition:background .15s;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <?php if (!empty($p['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($p['avatar']); ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                    <?php else: ?>
                        <div style="width:28px;height:28px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#64748b;font-weight:bold;flex-shrink:0;"><?php echo mb_strtoupper(mb_substr($p['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                    <div style="min-width:0;flex:1;">
                        <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($p['user_name']); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- ── Conversations List ────────────────────────────────────────────── -->
    <div class="lc-conversations" style="width:280px;flex-shrink:0;background:var(--card-bg);border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:10px 14px;border-bottom:1px solid var(--border-color);background:#f9fafb;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:13px;">Hội thoại</span>
            <button id="btn_read_all" style="display:none;font-size:11px;padding:3px 8px;border:1px solid var(--border-color);border-radius:4px;background:#fff;cursor:pointer;color:var(--text-muted);">Đọc tất cả</button>
        </div>
        <!-- Filter Tabs -->
        <div style="display:flex;gap:4px;padding:8px 10px;border-bottom:1px solid var(--border-color);background:#fff;flex-wrap:wrap;">
            <button class="filter-btn active" data-filter="all">Tất cả</button>
            <button class="filter-btn" data-filter="unread">Chưa đọc</button>
            <button class="filter-btn" data-filter="return">🔄 Quay lại</button>
            <button class="filter-btn" data-filter="phone">SĐT</button>
        </div>
        <!-- Search Input -->
        <div style="padding:8px 10px;border-bottom:1px solid var(--border-color);background:#f9fafb;">
            <input type="text" id="conv_search" placeholder="🔍 Tìm tên khách hàng..." style="width:100%;padding:6px 10px;border:1px solid var(--border-color);border-radius:6px;font-size:12px;box-sizing:border-box;outline:none;">
        </div>
        <div id="conversation_list" style="flex:1;overflow-y:auto;">
            <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px;">Chọn fanpage để xem hội thoại.</div>
        </div>
    </div>

    <!-- ── Chat Box ──────────────────────────────────────────────────────── -->
    <div class="lc-chatbox" style="flex:1;min-width:0;background:var(--card-bg);border:1px solid var(--border-color);border-radius:0 10px 10px 0;display:flex;flex-direction:column;overflow:hidden;">
        <div id="chat_header" style="padding:14px 16px;border-bottom:1px solid var(--border-color);font-weight:600;font-size:14px;background:#fff;display:flex;align-items:center;gap:10px;">
            <button id="btn_back_mobile" onclick="mobileBackToList()" style="display:none;background:none;border:none;font-size:18px;cursor:pointer;padding:0;">←</button>
            <span>Chọn một cuộc hội thoại để xem</span>
        </div>
        <div id="chat_messages" style="flex:1;overflow-y:auto;padding:15px;background:#f0f2f5;"></div>
        <div style="padding:12px 14px;border-top:1px solid var(--border-color);background:#fff;">
            <div id="chat_labels" class="chat-tags"></div>
            <div id="file_preview_container" style="display:none;margin-bottom:8px;padding:8px 12px;background:#f3f4f6;border-radius:8px;font-size:13px;">
                <span id="file_preview_name"></span>
                <button type="button" id="btn_remove_file" style="margin-left:10px;color:red;border:none;background:none;cursor:pointer;font-weight:bold;">✕ Xóa tệp</button>
            </div>
            <div id="policy_24h_banner" style="display:none;margin-bottom:10px;padding:12px 16px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;align-items:flex-start;gap:10px;line-height:1.5;font-family:system-ui, -apple-system, sans-serif;">
                <span style="color:#2563eb;font-size:16px;font-weight:bold;margin-top:1px;flex-shrink:0;">ℹ️</span>
                <span>Do chính sách của Facebook, khi tin nhắn cuối cùng của khách hàng cách thời điểm hiện tại quá 24h nên bạn không thể tiếp tục gửi tin nhắn cho đến khi khách hàng phản hồi lại.</span>
            </div>
            <form id="reply_form" style="display:flex;gap:8px;align-items:center;position:relative;">
                <input type="hidden" id="active_conversation_id" value="">
                <input type="hidden" id="active_recipient_id" value="">
                <input type="hidden" id="active_page_id" value="">
                <input type="hidden" id="active_user_id" value="">
                <input type="file" id="reply_file" style="display:none;" accept="image/*,video/*,.pdf,.doc,.docx">

                <button type="button" id="btn_attach" class="btn btn-secondary" style="border-radius:50%;width:38px;height:38px;padding:0;flex-shrink:0;" title="Đính kèm tệp" disabled>📎</button>

                <div style="position:relative;flex-shrink:0;">
                    <button type="button" id="btn_add_tag" class="btn btn-secondary" style="border-radius:50%;width:38px;height:38px;padding:0;" title="Gắn nhãn" disabled>🏷️</button>
                    <div id="tag_dropdown" onclick="event.stopPropagation()" style="display:none;position:absolute;bottom:46px;left:0;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);width:220px;max-height:260px;overflow-y:auto;z-index:20;">
                        <div style="padding:8px 12px;font-weight:600;border-bottom:1px solid #f1f5f9;font-size:11px;color:#64748b;text-transform:uppercase;">Nhãn Fanpage</div>
                        <div id="input_tag_list_container" style="padding:4px 0;"></div>
                    </div>
                </div>

                <div style="position:relative;flex-shrink:0;">
                    <button type="button" id="btn_saved_reply" class="btn btn-secondary" style="border-radius:50%;width:38px;height:38px;padding:0;" title="Tin mẫu" disabled>💬</button>
                    <div id="reply_dropdown" style="display:none;position:absolute;bottom:46px;left:0;background:#fff;border:1px solid #ccc;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.12);width:260px;z-index:20;">
                        <div style="padding:9px 12px;font-weight:600;border-bottom:1px solid #eee;font-size:12px;display:flex;justify-content:space-between;align-items:center;">
                            Tin mẫu
                            <button type="button" id="btn_open_add_reply" style="background:none;border:none;color:#0084ff;cursor:pointer;font-size:12px;">+ Thêm</button>
                        </div>
                        <div id="reply_list_container" style="max-height:200px;overflow-y:auto;"></div>
                    </div>
                </div>

                <input type="text" id="reply_text" style="flex:1;padding:9px 14px;border:1px solid var(--border-color);border-radius:20px;font-size:14px;" placeholder="Nhập tin nhắn..." disabled>
                <button type="submit" id="btn_send" class="btn btn-primary" style="border-radius:50%;width:40px;height:40px;padding:0;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow: 0 4px 6px -1px rgba(0, 132, 255, 0.4);border:none;transition: transform 0.15s ease;" onmouseover="if(!this.disabled) this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'" title="Gửi" disabled>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" style="margin-left: -2px; margin-top: 1px;">
                      <path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576zm6.787-8.201L1.591 6.602l4.339 2.76z"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <!-- ── Customer Info Panel ────────────────────────────────────────── -->
    <div id="customer_info_panel" style="width:280px;flex-shrink:0;background:#fff;border:1px solid var(--border-color);border-radius:0 10px 10px 0;display:none;flex-direction:column;overflow:hidden;border-left:none;">
        <div style="padding:10px 14px;font-weight:700;font-size:13px;border-bottom:1px solid var(--border-color);background:#f9fafb;letter-spacing:.3px;display:flex;justify-content:space-between;align-items:center;">
            <span>👤 Thông tin khách hàng</span>
            <button onclick="toggleInfoPanel()" style="background:none;border:none;cursor:pointer;font-size:14px;color:#6b7280;">✕</button>
        </div>
        <div style="padding:15px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:12px;">
            <div style="text-align:center;margin-bottom:10px;">
                <img id="info_avatar" src="https://ui-avatars.com/api/?name=KH&background=random" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;margin-bottom:8px;" onerror="this.src='https://ui-avatars.com/api/?name='+encodeURIComponent(document.getElementById('info_name_display').innerText)+'&background=random'">
                <div id="info_name_display" style="font-weight:700;font-size:15px;color:#1f2937;">Khách hàng</div>
                <div id="info_id_display" style="font-size:11px;color:#9ca3af;margin-top:2px;">ID: -</div>
                <div id="info_ad_badge" style="margin-top:6px;"></div>
                <div id="info_comments_section" style="margin-top:6px;"></div>
            </div>
            
            <form id="frm_customer_info" onsubmit="saveCustomerInfo(event)" style="display:flex;flex-direction:column;gap:12px;">
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Họ và tên</label>
                    <input type="text" id="info_name" style="width:100%;padding:8px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Số điện thoại</label>
                    <input type="text" id="info_phone" style="width:100%;padding:8px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;" placeholder="Chưa phát hiện được SĐT">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Tỉnh thành</label>
                    <input type="text" id="info_province" style="width:100%;padding:8px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;" placeholder="Chưa phát hiện được tỉnh thành">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Yêu cầu / Ghi chú</label>
                    <textarea id="info_notes" rows="4" style="width:100%;padding:8px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;resize:vertical;" placeholder="Nhập yêu cầu hoặc ghi chú của khách..."></textarea>
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Trạng thái tư vấn</label>
                    <select id="info_consulted" style="width:100%;padding:8px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;background:#fff;cursor:pointer;">
                        <option value="0">🆕 Chưa tư vấn</option>
                        <option value="4">⏳ Chờ xử lý</option>
                        <option value="1">✅ Đã tư vấn</option>
                        <option value="2">🔄 Khách quay lại</option>
                        <option value="3">⛔ Dừng tư vấn</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Số điện thoại Sales</label>
                    <input type="text" id="info_sales_phone" list="sales_phone_list" style="width:100%;padding:8px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;" placeholder="Nhập SĐT Sales...">
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
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;">Ghi chú Sales</label>
                    <textarea id="info_sales_notes" rows="3" style="width:100%;padding:8px 12px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;box-sizing:border-box;resize:vertical;" placeholder="Nhập ghi chú của Sales..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;padding:9px;font-weight:600;border:none;border-radius:6px;background:#0284c7;color:#fff;cursor:pointer;margin-top:5px;box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.4);">Cập nhật thông tin</button>
                <div id="customer_info_status" style="display:none; text-align:center; font-size:12px; font-weight:600; padding:8px; border-radius:6px; margin-top:8px;"></div>
            </form>
            <div id="capi_push_area" style="margin-top:10px; display:none;">
                <button id="btn_push_capi" onclick="pushToCapi()" style="width:100%;padding:9px;font-weight:600;border:none;border-radius:6px;background:#7c3aed;color:#fff;cursor:pointer;box-shadow:0 4px 6px -1px rgba(124,58,237,0.4);display:flex;align-items:center;justify-content:center;gap:6px;">
                    🔌 Đẩy lên Facebook CAPI
                </button>
                <div id="capi_push_status" style="display:none; text-align:center; font-size:11px; font-weight:600; padding:6px; border-radius:6px; margin-top:6px;"></div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal Thêm Tin Mẫu -->
<div id="addReplyModalCustom" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;justify-content:center;align-items:center;">
    <div style="background:#fff;width:400px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);overflow:hidden;">
        <div style="padding:14px 18px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-weight:600;">
            Thêm Tin Trả Lời Mẫu
            <button type="button" id="btn_close_add_reply" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:18px;">
            <form id="form_add_reply">
                <div style="margin-bottom:12px;">
                    <label style="display:block;margin-bottom:4px;font-size:13px;font-weight:500;">Tiêu đề (tuỳ chọn)</label>
                    <input type="text" id="reply_title_input" placeholder="VD: Lời chào, Báo giá..." style="width:100%;padding:9px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;font-size:13px;">
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block;margin-bottom:4px;font-size:13px;font-weight:500;">Nội dung <span style="color:red;">*</span></label>
                    <textarea id="reply_content_input" rows="4" required style="width:100%;padding:9px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;font-size:13px;resize:vertical;"></textarea>
                </div>
                <button type="submit" style="background:#0084ff;color:#fff;border:none;padding:10px;border-radius:6px;cursor:pointer;font-weight:600;width:100%;font-size:14px;">Lưu lại</button>
            </form>
        </div>
    </div>
</div>

<style>
/* Page Sidebar */
.page-tab:hover { background: var(--hover-bg, #f3f4f6); }
.page-tab.active { background: #e0f2fe; border-left: 3px solid #0284c7; }

/* Conversation items */
.conv-item { padding:12px 14px;border-bottom:1px solid var(--border-color);cursor:pointer;transition:background .15s; }
.conv-item:hover { background: #f3f4f6; }
.conv-item.active { background: #e0f2fe; border-left: 3px solid #0284c7; }
.conv-unread { font-weight: 700; }
.unread-dot { display:inline-block;width:7px;height:7px;background:#0084ff;border-radius:50%;margin-left:4px;vertical-align:middle; }

/* Messages */
.msg-bubble { max-width:70%;padding:9px 14px;border-radius:18px;margin-bottom:8px;font-size:14px;line-height:1.45;clear:both; }
.msg-received { background:#fff;color:#000;float:left;border:1px solid #e5e7eb;border-bottom-left-radius:4px; }
.msg-sent { background:#0084ff;color:#fff;float:right;border-bottom-right-radius:4px; }
.msg-time { font-size:11px;color:#9ca3af;margin-bottom:12px;text-align:center;clear:both; }

/* Filter buttons */
.filter-btn { padding:4px 9px;font-size:11px;border:1px solid #ddd;background:#f9fafb;border-radius:10px;cursor:pointer;transition:all .15s;color:#555; }
.filter-btn:hover { background:#e5e7eb; }
.filter-btn.active { background:#0084ff;color:#fff;border-color:#0084ff; }

/* Labels */
.chat-tags { display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px; }
.chat-tag { padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px; }
.chat-tag button { background:none;border:none;cursor:pointer;padding:0;font-size:11px;opacity:.6;line-height:1; }
.chat-tag button:hover { opacity:1; }
.tag-intake    { background:#f1f5f9;color:#334155; }
.tag-converted { background:#fee2e2;color:#ef4444; }
.tag-qualified { background:#ffedd5;color:#f97316; }
.tag-accepted  { background:#fef3c7;color:#d97706; }
.tag-hot       { background:#fce7f3;color:#be185d; }
.tag-phone     { background:#dbeafe;color:#1e40af; }
.tag-default   { background:#ccfbf1;color:#0f766e; }
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

/* Dropdown */
.dropdown-item { padding:8px 12px;font-size:13px;cursor:pointer;border-bottom:1px solid #f3f4f6;color:#333; }
.dropdown-item:hover { background:#f3f4f6; }
.dropdown-item:last-child { border-bottom:none; }

/* Mobile Live Chat */
@media (max-width: 768px) {
    .livechat-container {
        flex-direction: column !important;
        height: auto !important;
        min-height: auto !important;
    }
    .livechat-container .lc-sidebar {
        width: 100% !important;
        border-radius: 10px 10px 0 0 !important;
        max-height: 180px;
    }
    .livechat-container .lc-conversations {
        width: 100% !important;
        max-height: 300px;
    }
    .livechat-container .lc-chatbox {
        border-radius: 0 0 10px 10px !important;
        min-height: 400px;
    }
    .livechat-container.chat-active {
        position: fixed !important;
        top: 60px; /* Header height */
        left: 0;
        right: 0;
        bottom: 0px;
        height: calc(100dvh - 60px) !important;
        z-index: 9999;
        background: var(--bg-color);
        padding: 10px;
        box-sizing: border-box;
    }
    .livechat-container.chat-active .lc-sidebar,
    .livechat-container.chat-active .lc-conversations,
    .livechat-container.chat-active #customer_info_panel {
        display: none !important;
    }
    .livechat-container.chat-active .lc-chatbox {
        height: 100% !important;
        min-height: 0 !important;
        border-radius: 10px !important;
        flex: 1 !important;
    }
    #btn_back_mobile {
        display: inline-block !important;
    }
}
</style>

<script>
const allPages    = <?php echo $pages_json; ?>;
const convList    = document.getElementById('conversation_list');
const chatHeader  = document.getElementById('chat_header');
const chatMessages= document.getElementById('chat_messages');
const replyText   = document.getElementById('reply_text');
const btnSend     = document.getElementById('btn_send');
const activeConvId= document.getElementById('active_conversation_id');
const activeRecipId=document.getElementById('active_recipient_id');
const activePageIdEl=document.getElementById('active_page_id');
const activeUserIdEl=document.getElementById('active_user_id');
const labelsDiv   = document.getElementById('chat_labels');
const btnReadAll  = document.getElementById('btn_read_all');

let currentConversations = [];
let currentFilter  = 'all';
let convCursor     = '';
let isConvLoading  = false;
let currentCursor  = '';
let isLoadingMore  = false;
let locallyReadConvs = JSON.parse(localStorage.getItem('fb_read_cache') || '{}');
const phoneRegex   = /(03|05|07|08|09)+([0-9]{8})\b/;
let activeConvOver24h = false;

function showToast(message, type = 'error') {
    let toast = document.getElementById('chat_toast_notification');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'chat_toast_notification';
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 24px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            font-size: 14px;
            z-index: 99999;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            transform: translateY(20px);
            opacity: 0;
            font-family: system-ui, -apple-system, sans-serif;
        `;
        document.body.appendChild(toast);
    }
    toast.style.background = type === 'success' ? '#10b981' : '#ef4444';
    toast.innerText = message;
    toast.style.display = 'block';
    
    // Trigger animation
    setTimeout(() => {
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';
    }, 50);
    
    // Hide after 4 seconds
    setTimeout(() => {
        toast.style.transform = 'translateY(20px)';
        toast.style.opacity = '0';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 300);
    }, 4000);
}

// active state
let currentPageId = '<?php echo $selected_page_id; ?>';
let currentUserId = '';
let selectedConvId = '<?php echo $selected_conv_id; ?>';
let selectedSenderId = '<?php echo $selected_sender_id; ?>';
let isMergedChat = false;
let currentActiveSenderId = '';
let currentActivePageId = '';

// ── Tag class helper ──────────────────────────────────────────────────────
function tagClass(name) {
    const n = name.toLowerCase();
    if (n.includes('intake'))     return 'tag-intake';
    if (n.includes('chuyển đổi'))return 'tag-converted';
    if (n.includes('qualified'))  return 'tag-qualified';
    if (n.includes('tiếp nhận')) return 'tag-accepted';
    if (n.includes('hot'))        return 'tag-hot';
    if (n.includes('số điện thoại')) return 'tag-phone';
    return 'tag-default';
}

// ── Mobile Back to List ───────────────────────────────────────────────────
function mobileBackToList() {
    document.getElementById('livechatContainer').classList.remove('chat-active');
    activeConvId.value = '';
    chatHeader.innerHTML = 'Chọn một cuộc hội thoại để xem';
    chatMessages.innerHTML = '';
}

// ── Enable / disable chat controls ───────────────────────────────────────
function setChatEnabled(enabled) {
    if (enabled && activeConvOver24h) {
        replyText.disabled = true;
        btnSend.disabled   = true;
        ['btn_attach','btn_add_tag','btn_saved_reply'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = true;
        });
        return;
    }
    replyText.disabled = !enabled;
    btnSend.disabled   = !enabled;
    ['btn_attach','btn_add_tag','btn_saved_reply'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = !enabled;
    });
}

// ── Page Search Filter ────────────────────────────────────────────────────
document.getElementById('page_search').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.page-tab').forEach(tab => {
        const name = tab.querySelector('div')?.textContent?.toLowerCase() || '';
        tab.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
});

// ── Sidebar page selection ────────────────────────────────────────────────

document.getElementById('chk_merge_all').addEventListener('change', function() {
    isMergedChat = this.checked;
    localStorage.setItem('merge_all', isMergedChat ? '1' : '0');
    if (isMergedChat) {
        document.querySelectorAll('.page-tab').forEach(t => t.style.opacity = '0.5');
        document.querySelectorAll('.page-tab.active').forEach(t => t.classList.remove('active'));
        currentPageId = 'merge';
        currentUserId = 'merge';
        loadConversations();
    } else {
        document.querySelectorAll('.page-tab').forEach(t => t.style.opacity = '1');
        const lastPage = localStorage.getItem('last_page_id');
        const lastUser = localStorage.getItem('last_user_id');
        if (lastPage && lastUser) {
            const tab = document.querySelector(`.page-tab[data-page-id="${lastPage}"]`);
            if (tab) tab.click();
        } else {
            convList.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px;">Chọn fanpage để xem hội thoại.</div>';
            chatMessages.innerHTML = '';
            chatHeader.innerText = 'Chọn một cuộc hội thoại để xem';
            setChatEnabled(false);
        }
    }
});

document.querySelectorAll('.page-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        if (isMergedChat) {
            document.getElementById('chk_merge_all').checked = false;
            document.getElementById('chk_merge_all').dispatchEvent(new Event('change'));
        }
        document.querySelectorAll('.page-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        currentPageId = this.dataset.pageId;
        currentUserId = this.dataset.userId;
        localStorage.setItem('last_page_id', currentPageId);
        localStorage.setItem('last_user_id', currentUserId);
        loadConversations();
    });
});

// Restore last selected page on load
(function restoreLastPage() {
    let lastPage = currentPageId || localStorage.getItem('last_page_id');
    let lastUser = localStorage.getItem('last_user_id');
    
    // Nếu có query param page_id, focus thẳng vòng đó để lấy user_id luôn (bỏ qua cache)
    if (currentPageId) {
        lastPage = currentPageId;
        const tab = document.querySelector(`.page-tab[data-page-id="${lastPage}"]`);
        if (tab) lastUser = tab.dataset.userId;
    }

    const isMergeSaved = localStorage.getItem('merge_all') === '1';
    if (!currentPageId && isMergeSaved) {
        document.getElementById('chk_merge_all').checked = true;
        document.getElementById('chk_merge_all').dispatchEvent(new Event('change'));
    } else {
        if (lastPage && lastUser) {
            const tab = document.querySelector(`.page-tab[data-page-id="${lastPage}"]`);
            if (tab) {
                tab.click();
                tab.scrollIntoView({ block:'nearest' });
            }
        }
    }
})();

// ── Load Conversations ────────────────────────────────────────────────────
function loadConversations(append = false, silent = false) {
    if (!currentPageId || !currentUserId) return;

    // Kích hoạt đồng bộ cuộc hội thoại ngầm từ Facebook API về local DB
    if (!append && !silent) {
        const syncUrl = `actions/sync_fb_conversations.php?page_id=${currentPageId}&user_id=${currentUserId}${isMergedChat ? '&merge_all=1' : ''}`;
        fetch(syncUrl)
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success' && res.synced > 0) {
                    // Nếu có cuộc hội thoại mới được đồng bộ về, reload lại trang 1 âm thầm
                    loadConversations(false, true);
                }
            })
            .catch(err => console.error('Sync error:', err));
    }

    const spinnerSvg = `<svg style="animation: spin 1s linear infinite; width: 24px; height: 24px; margin-bottom: 8px; color: #0284c7;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity:0.25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>`;

    if (!append && !silent) {
        convList.innerHTML = `<div style="padding:40px 20px;text-align:center;color:#6b7280;font-size:13px;">${spinnerSvg}<br>Đang tải dữ liệu...</div>`;
        chatMessages.innerHTML = '';
        chatHeader.innerText = 'Chọn một cuộc hội thoại để xem';
        labelsDiv.innerHTML = '';
        setChatEnabled(false);
        convCursor = '';
        activeConvId.value = '';
    } else if (append) {
        if (!document.getElementById('conv_more_loader')) {
            convList.insertAdjacentHTML('beforeend', `<div id="conv_more_loader" style="text-align:center;padding:12px;font-size:12px;color:#6b7280;"><svg style="animation: spin 1s linear infinite; width: 16px; height: 16px; vertical-align: middle; margin-right: 6px; color: #0284c7;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity:0.25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Đang tải thêm...</div>`);
            convList.scrollTop = convList.scrollHeight;
        }
    }

    let targetParams = '';
    if (!append) {
        if (selectedConvId) targetParams += '&target_conv_id=' + selectedConvId;
        if (selectedSenderId) targetParams += '&target_sender_id=' + selectedSenderId;
    }

    let url = 'actions/get_conversations.php?page_id=' + currentPageId + '&user_id=' + currentUserId + targetParams;
    if (isMergedChat) {
        url = 'actions/get_conversations.php?merge_all=1' + targetParams;
        if (append && convCursor) url += '&after=' + convCursor;
    } else if (append && convCursor) {
        url += '&after=' + convCursor;
    }

    if (currentFilter) {
        url += '&filter=' + encodeURIComponent(currentFilter);
    }

    const searchInputEl = document.getElementById('conv_search');
    const searchVal = searchInputEl ? searchInputEl.value.trim() : '';
    if (searchVal) {
        url += '&search=' + encodeURIComponent(searchVal);
    }

    fetch(url)
        .then(r => r.json())
        .then(data => {
            isConvLoading = false;
            document.getElementById('conv_more_loader')?.remove();
            if (data.status === 'success') {
                if (append) {
                    currentConversations = currentConversations.concat(data.data);
                } else if (silent) {
                    mergeConversations(data.data);
                } else {
                    currentConversations = data.data;
                }
                convCursor = data.next_cursor || '';
                renderConversations();
                
                // Auto-open if redirected via query params
                if ((selectedConvId || selectedSenderId) && !append) {
                    let convTab;
                    if (selectedConvId) {
                        convTab = Array.from(document.querySelectorAll('.conv-item')).find(el => el.dataset.id === selectedConvId);
                    }
                    if (!convTab && selectedSenderId) {
                        convTab = Array.from(document.querySelectorAll('.conv-item')).find(el => el.dataset.senderId === selectedSenderId);
                    }
                    if (convTab) {
                        convTab.click();
                        convTab.scrollIntoView({ block:'nearest' });
                    }
                    selectedConvId = ''; // Reset flag
                    selectedSenderId = '';
                }
            } else {
                if (!append) convList.innerHTML = '<div style="padding:16px;color:red;font-size:12px;">' + (data.msg||'Lỗi tải') + '</div>';
            }
        }).catch(() => {
            isConvLoading = false;
            document.getElementById('conv_more_loader')?.remove();
            if (!append) convList.innerHTML = '<div style="padding:16px;color:red;font-size:12px;">Lỗi mạng</div>';
        });
}

// ── Infinite scroll conversations ─────────────────────────────────────────
convList.addEventListener('scroll', function() {
    if (this.scrollHeight - this.scrollTop <= this.clientHeight + 60 && convCursor && !isConvLoading) {
        isConvLoading = true;
        loadConversations(true);
        setTimeout(() => { isConvLoading = false; }, 1500);
    }
});

// ── Read All ──────────────────────────────────────────────────────────────
btnReadAll.addEventListener('click', function() {
    const unread = currentConversations.filter(c => c.unread_count > 0);
    if (!unread.length) return;
    
    if (isMergedChat) {
        showToast('Không hỗ trợ "Đọc tất cả" khi đang Gộp Fanpage. Xin vui lòng chọn từng Fanpage.', 'error');
        return;
    }

    const recipientIds = unread.map(c => {
        const p = c.participants.data.find(x => x.id !== currentPageId);
        return p ? p.id : null;
    }).filter(Boolean);
    const convIds = unread.map(c => c.id);
    this.disabled = true;
    const fd = new FormData();
    fd.append('user_id', currentUserId);
    fd.append('page_id', currentPageId);
    fd.append('recipient_ids', JSON.stringify(recipientIds));
    fetch('actions/mark_read.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                convIds.forEach(id => {
                    const c = currentConversations.find(x => x.id === id);
                    if (c) { locallyReadConvs[id] = new Date(c.updated_time).getTime(); c.unread_count = 0; }
                });
                localStorage.setItem('fb_read_cache', JSON.stringify(locallyReadConvs));
                renderConversations();
            }
        }).finally(() => { this.disabled = false; });
});

// ── Merge Conversations Silently ──────────────────────────────────────────
function mergeConversations(newItems) {
    if (!newItems || !newItems.length) return;
    let updatedList = [...currentConversations];
    newItems.forEach(newItem => {
        const index = updatedList.findIndex(c => c.id === newItem.id);
        if (index > -1) {
            // Cập nhật thông tin mới
            updatedList[index] = Object.assign({}, updatedList[index], newItem);
        } else {
            // Thêm mới lên đầu
            updatedList.push(newItem);
        }
    });
    // Sắp xếp lại theo thời gian mới nhất lên đầu
    updatedList.sort((a, b) => new Date(b.updated_time) - new Date(a.updated_time));
    currentConversations = updatedList;
    renderConversations();
}

// ── Render Conversations ──────────────────────────────────────────────────
function renderConversations() {
    const scrollTop = convList.scrollTop;
    convList.innerHTML = '';

    let filtered = currentConversations;
    if (currentFilter === 'unread') {
        filtered = filtered.filter(c => {
            let isUnread = c.unread_count > 0;
            if (isUnread && locallyReadConvs[c.id]) {
                if (new Date(c.updated_time).getTime() <= locallyReadConvs[c.id]) isUnread = false;
            }
            return isUnread;
        });
    } else if (currentFilter === 'phone') {
        filtered = filtered.filter(c => c.has_phone === true);
    } else if (currentFilter === 'return') {
        filtered = filtered.filter(c => parseInt(c.consulted || 0) === 2);
    } else if (currentFilter === 'spam') {
        filtered = filtered.filter(c => c.folder === 'spam');
    }

    // Lọc theo từ khóa tìm kiếm (tên khách hàng, SĐT, ID, nội dung)
    const searchQuery = document.getElementById('conv_search')?.value.trim().toLowerCase() || '';
    if (searchQuery) {
        const searchClean = searchQuery.replace(/[\s\.\-\(\)]/g, '');
        filtered = filtered.filter(c => {
            const itemPageId = isMergedChat ? c._page_id : currentPageId;
            let senderName = '';
            let senderId = '';
            if (c.participants && c.participants.data) {
                const participants = c.participants.data.filter(p => p.id !== itemPageId);
                senderName = (participants[0]?.name || '').toLowerCase();
                senderId = (participants[0]?.id || '').toLowerCase();
            }
            const custName = (c.cust_name || '').toLowerCase();
            const phone = (c.phone_number || '').toLowerCase();
            const phoneClean = phone.replace(/[\s\.\-\(\)]/g, '');
            const snippet = (c.messages?.data?.[0]?.message || '').toLowerCase();
            
            const matchName = senderName.includes(searchQuery) || custName.includes(searchQuery);
            const matchId = senderId.includes(searchQuery);
            const matchSnippet = snippet.includes(searchQuery);
            const matchPhone = phone.includes(searchQuery) || (searchClean.length >= 3 && phoneClean.includes(searchClean));

            return matchName || matchId || matchSnippet || matchPhone;
        });
    }

    // Lọc trùng lặp hội thoại trong danh sách
    const uniqueSeen = new Set();
    filtered = filtered.filter(c => {
        const itemPageId = isMergedChat ? (c._page_id || '') : currentPageId;
        const key = (c.id || (c.sender_id || '')) + '_' + itemPageId;
        if (!key || key === '_') return true;
        if (uniqueSeen.has(key)) return false;
        uniqueSeen.add(key);
        return true;
    });

    const hasUnread = currentConversations.some(c => {
        let isUnread = c.unread_count > 0;
        if (isUnread && locallyReadConvs[c.id]) {
            if (new Date(c.updated_time).getTime() <= locallyReadConvs[c.id]) isUnread = false;
        }
        return isUnread;
    });
    btnReadAll.style.display = hasUnread ? 'block' : 'none';

    if (!filtered.length) {
        convList.innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-muted);">Không có hội thoại nào.</div>';
        return;
    }

    filtered.forEach(conv => {
        const itemPageId = isMergedChat ? conv._page_id : currentPageId;
        const itemUserId = isMergedChat ? conv._user_id : currentUserId;
        const pagePrefix = isMergedChat && conv._page_name ? `<span style="color:#0284c7;font-weight:bold;">[${conv._page_name}]</span> ` : '';

        const participants = conv.participants.data.filter(p => p.id !== itemPageId);
        const senderName = participants[0]?.name || 'Unknown';
        const senderId   = participants[0]?.id   || '';
        const snippet    = conv.messages?.data?.[0]?.message || '';
        const updTime    = new Date(conv.updated_time).toLocaleString('vi-VN');

        let isUnread = conv.unread_count > 0;
        if (isUnread && locallyReadConvs[conv.id]) {
            if (new Date(conv.updated_time).getTime() <= locallyReadConvs[conv.id]) isUnread = false;
        }

        let adBadgeHtml = '';
        if (conv.is_ads === 1) {
            let adSnippet = conv.ad_title ? `: ${conv.ad_title}` : '';
            if (adSnippet.length > 25) adSnippet = adSnippet.substring(0, 25) + '...';
            adBadgeHtml = `<span class="badge-ads" style="background-color:#ffe4e6;color:#e11d48;border:1px solid #fda4af;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;gap:3px;margin-top:4px;margin-right:4px;">📢 Ads${adSnippet}</span>`;
        } else {
            adBadgeHtml = `<span class="badge-free" style="background-color:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;gap:3px;margin-top:4px;margin-right:4px;">🆓 Miễn phí</span>`;
        }

        let consultedBadgeHtml = '';
        const consulted = parseInt(conv.consulted || 0);
        if (consulted === 0) {
            consultedBadgeHtml = `<span style="background-color:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;margin-top:4px;margin-right:4px;">🆕 Chưa tư vấn</span>`;
        } else if (consulted === 4) {
            consultedBadgeHtml = `<span style="background-color:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;margin-top:4px;margin-right:4px;">⏳ Chờ xử lý</span>`;
        } else if (consulted === 1) {
            consultedBadgeHtml = `<span style="background-color:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;margin-top:4px;margin-right:4px;">✅ Đã tư vấn</span>`;
        } else if (consulted === 2) {
            consultedBadgeHtml = `<span class="badge-pulse-custom" style="background-color:#fff7ed;color:#9a3412;border:1px solid #fed7aa;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;margin-top:4px;margin-right:4px;">🔄 Khách quay lại</span>`;
        } else if (consulted === 3) {
            consultedBadgeHtml = `<span style="background-color:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;margin-top:4px;margin-right:4px;">⛔ Dừng tư vấn</span>`;
        }

        const div = document.createElement('div');
        div.className = 'conv-item' + (isUnread ? ' conv-unread' : '');
        if (activeConvId.value === conv.id) div.classList.add('active');
        div.dataset.id = conv.id;
        div.dataset.senderId = senderId;
        div.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;">
                <img src="avatar.php?id=${senderId}&page_id=${itemPageId}&name=${encodeURIComponent(senderName)}" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;" onerror="this.src='https://ui-avatars.com/api/?name='+encodeURIComponent('${senderName}')+'&background=random'">
                <div style="min-width:0;flex:1;">
                    <div style="font-size:13px;margin-bottom:2px;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
                        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${pagePrefix}${senderName}</span>
                        ${isUnread ? '<span class="unread-dot"></span>': ''}
                    </div>
                    <div style="font-size:11px;color:${isUnread?'#111':'#6b7280'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${snippet}</div>
                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;">${updTime}</div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                        ${consultedBadgeHtml}
                        ${adBadgeHtml}
                        ${conv.has_phone && conv.phone_number ? `<span class="badge-phone">📞 ${conv.phone_number}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
        div.addEventListener('click', function() {
            document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
            this.classList.add('active');
            this.classList.remove('conv-unread');
            const dot = this.querySelector('.unread-dot'); if (dot) dot.remove();
            locallyReadConvs[conv.id] = new Date(conv.updated_time).getTime();
            localStorage.setItem('fb_read_cache', JSON.stringify(locallyReadConvs));
            
            // Save last active conversation
            localStorage.setItem('last_active_conv_id_' + currentPageId, conv.id);
            
            if (conv.unread_count > 0) {
                const fd = new FormData();
                fd.append('user_id', itemUserId);
                fd.append('page_id', itemPageId);
                fd.append('recipient_ids', JSON.stringify([senderId]));
                fetch('actions/mark_read.php', { method:'POST', body:fd });
                conv.unread_count = 0;
            }
            loadMessages(conv.id, senderName, senderId, itemPageId, itemUserId);
            // Mobile: switch to chat view
            if (window.innerWidth <= 768) {
                document.getElementById('livechatContainer').classList.add('chat-active');
            }
        });
        convList.appendChild(div);
    });
    convList.scrollTop = scrollTop;

    // Auto-select last active conversation on load or tab switch
    if (!activeConvId.value) {
        const savedConvId = localStorage.getItem('last_active_conv_id_' + currentPageId);
        if (savedConvId) {
            const itemToClick = Array.from(convList.querySelectorAll('.conv-item')).find(el => el.dataset.id === savedConvId);
            if (itemToClick) {
                itemToClick.click();
            }
        }
    }
}

// ── Filter buttons ────────────────────────────────────────────────────────
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        currentFilter = this.dataset.filter;
        loadConversations();
    });
});

// ── Load Messages ─────────────────────────────────────────────────────────
window.messagesCacheByConv = window.messagesCacheByConv || {};

function loadMessages(convId, senderName, senderId, activePageIdToUse = currentPageId, activeUserIdToUse = currentUserId, isAutoRefresh = false) {
    if (!isAutoRefresh) {
        activeConvOver24h = false;
        const banner = document.getElementById('policy_24h_banner');
        if (banner) banner.style.display = 'none';
        chatHeader.innerHTML = `
            <button id="btn_back_mobile" onclick="mobileBackToList()" style="display:none;background:none;border:none;font-size:18px;cursor:pointer;padding:0;">←</button>
            <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;">
                <img src="avatar.php?id=${senderId}&page_id=${activePageIdToUse}&name=${encodeURIComponent(senderName)}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;" onerror="this.src='https://ui-avatars.com/api/?name='+encodeURIComponent('${senderName}')+'&background=random'">
                <div style="min-width:0;flex:1;">
                    <div style="font-weight:600;font-size:14px;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${senderName}</div>
                    <div style="font-size:11px;color:#6b7280;">ID: ${senderId}</div>
                </div>
            </div>
            <button id="btn_toggle_bot" onclick="toggleBotLock()" class="btn" style="padding:6px 12px; font-size:12px; font-weight:600; border-radius:20px; border:1px solid #d1d5db; background:#fff; color:#374151; display:flex; align-items:center; gap:4px; cursor:pointer;" title="Tạm dừng hoặc Bật lại bot tự trả lời cho khách này">
                🤖 Bot: ON
            </button>
            <button onclick="toggleInfoPanel()" class="btn btn-secondary" style="border-radius:50%;width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;border:1px solid var(--border-color);background:#fff;" title="Thông tin khách hàng">ℹ️</button>
        `;
        if (window.innerWidth <= 768) {
            chatHeader.querySelector('#btn_back_mobile').style.display = 'inline-block';
        }

        activeConvId.value  = convId;
        activeRecipId.value = senderId;
        activePageIdEl.value= activePageIdToUse;
        activeUserIdEl.value= activeUserIdToUse;
        currentCursor = '';
        isLoadingMore = false;

        // Tải tức thì 0ms từ bộ nhớ đệm nếu đã từng mở
        if (window.messagesCacheByConv[convId]) {
            renderMessages(window.messagesCacheByConv[convId], true);
        } else {
            chatMessages.innerHTML = '<div style="text-align:center;padding:20px;font-size:12px;color:#6b7280;">⚡ Đang tải tin nhắn...</div>';
        }

        labelsDiv.innerHTML = '';
        fetch('actions/get_labels.php?conv_id=' + encodeURIComponent(convId) + '&page_id=' + encodeURIComponent(activePageIdToUse))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    renderLabels(data.data.map(l => l.label_name));
                }
            });
        loadCustomerInfo(senderId, activePageIdToUse, senderName);
    }

    fetch('actions/get_messages.php?conv_id=' + convId + '&page_id=' + activePageIdToUse + '&user_id=' + activeUserIdToUse)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                if (!isAutoRefresh) currentCursor = data.next_cursor || '';
                const isBottom = chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 50;
                
                const reversedMsgs = data.data.reverse();
                // Lưu vào bộ nhớ đệm 0ms
                window.messagesCacheByConv[convId] = reversedMsgs;

                renderMessages(reversedMsgs, !isAutoRefresh || isBottom);

                // Kiểm tra chính sách 24h của Facebook
                let isOver24h = false;
                let lastCustomerMsg = null;
                for (let i = reversedMsgs.length - 1; i >= 0; i--) {
                    if (reversedMsgs[i].from && reversedMsgs[i].from.id !== activePageIdToUse) {
                        lastCustomerMsg = reversedMsgs[i];
                        break;
                    }
                }
                
                if (lastCustomerMsg) {
                    const lastTime = new Date(lastCustomerMsg.created_time).getTime();
                    const now = new Date().getTime();
                    const diffHours = (now - lastTime) / (1000 * 60 * 60);
                    if (diffHours > 24) {
                        isOver24h = true;
                    }
                } else {
                    const conv = currentConversations.find(x => x.id === convId);
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
                if (banner) {
                    banner.style.display = isOver24h ? 'flex' : 'none';
                }
                
                setChatEnabled(true);
            } else if (!isAutoRefresh) {
                chatMessages.innerHTML = '<div style="color:red;text-align:center;padding:16px;">' + data.msg + '</div>';
            }
        });
}

// ── Render Labels ─────────────────────────────────────────────────────────
function renderLabels(names) {
    labelsDiv.innerHTML = '';
    names.forEach(name => {
        const span = document.createElement('span');
        span.className = 'chat-tag ' + tagClass(name);
        span.innerHTML = `${name} <button onclick="removeLabel(this,'${name.replace(/'/g,"\\'")}')">✕</button>`;
        labelsDiv.appendChild(span);
    });
}

function removeLabel(btn, labelName) {
    if (!activeConvId.value) return;
    const fd = new FormData();
    fd.append('conv_id',   activeConvId.value);
    fd.append('page_id',   activePageIdEl.value);
    fd.append('label_name',labelName);
    fetch('actions/remove_label.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') btn.closest('.chat-tag').remove();
        });
}

// ── Render Messages ───────────────────────────────────────────────────────
function renderMessages(messages, shouldScroll = true, isLoadMore = false) {
    const pageId = activePageIdEl.value;
    const oldScrollTop    = chatMessages.scrollTop;
    const oldScrollHeight = chatMessages.scrollHeight;

    if (!isLoadMore) chatMessages.innerHTML = '';

    let html = '';
    messages.forEach(msg => {
        const isSent = msg.from.id === pageId;
        let content = msg.message ? msg.message.replace(/\n/g, '<br>') : '';
        if (msg.attachments?.data) {
            msg.attachments.data.forEach(att => {
                if (att.image_data) {
                    const isSticker = att.name === 'sticker.png' || att.name?.startsWith('sticker-') || att.image_data.render_as_sticker || (att.image_data.width && att.image_data.width < 150);
                    const maxW = isSticker ? '100px' : '250px';
                    content += `<br><img src="${att.image_data.url}" style="max-width:${maxW};border-radius:8px;margin-top:4px;">`;
                } else if (att.mime_type && att.mime_type.startsWith('image/')) {
                    const isSticker = att.name === 'sticker.png' || att.name?.startsWith('sticker-');
                    const maxW = isSticker ? '100px' : '250px';
                    content += `<br><img src="${att.file_url}" style="max-width:${maxW};border-radius:8px;margin-top:4px;">`;
                } else if (att.video_data) {
                    content += `<br><video src="${att.video_data.url}" controls style="max-width:100%;border-radius:8px;margin-top:4px;"></video>`;
                } else if (att.name) {
                    content += `<br><a href="${att.file_url}" target="_blank">📎 ${att.name}</a>`;
                }
            });
        }
        if (content) {
            html += `<div>
                <div class="msg-bubble ${isSent?'msg-sent':'msg-received'}">${content}</div>
                <div class="msg-time">${new Date(msg.created_time).toLocaleString('vi-VN')}</div>
            </div>`;
        }
    });

    if (isLoadMore) {
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        while (tmp.lastChild) chatMessages.insertBefore(tmp.lastChild, chatMessages.firstChild);
        chatMessages.scrollTop = oldScrollTop + (chatMessages.scrollHeight - oldScrollHeight);
    } else {
        chatMessages.innerHTML = html;
        if (shouldScroll) chatMessages.scrollTop = chatMessages.scrollHeight;
    }
}

// ── Load older messages (scroll to top) ─────────────────────────────────
chatMessages.addEventListener('scroll', function() {
    if (this.scrollTop === 0 && currentCursor && !isLoadingMore && activeConvId.value) {
        isLoadingMore = true;
        const loader = document.createElement('div');
        loader.id = 'load_more_spinner';
        loader.style.cssText = 'text-align:center;padding:8px;color:#9ca3af;font-size:11px;clear:both;';
        loader.innerText = 'Đang tải...';
        chatMessages.insertBefore(loader, chatMessages.firstChild);
        fetch('actions/get_messages.php?conv_id=' + activeConvId.value + '&page_id=' + activePageIdEl.value + '&user_id=' + activeUserIdEl.value + '&before=' + currentCursor)
            .then(r => r.json())
            .then(data => {
                document.getElementById('load_more_spinner')?.remove();
                if (data.status === 'success' && data.data.length) {
                    currentCursor = data.next_cursor || '';
                    renderMessages(data.data.reverse(), false, true);
                } else { currentCursor = ''; }
            })
            .finally(() => { isLoadingMore = false; });
    }
});

// ── Dynamic Label Dropdown Logic ──────────────────────────────────────────
const btnAddTag    = document.getElementById('btn_add_tag');
const tagDropdown  = document.getElementById('tag_dropdown');
const btnSavedReply= document.getElementById('btn_saved_reply');
const replyDropdown= document.getElementById('reply_dropdown');
const customerLabelsDropdown = document.getElementById('customer_labels_dropdown_menu');

document.addEventListener('click', function(e) {
    if (btnAddTag && !btnAddTag.contains(e.target) && !tagDropdown.contains(e.target)) tagDropdown.style.display = 'none';
    if (btnSavedReply && !btnSavedReply.contains(e.target) && !replyDropdown.contains(e.target)) replyDropdown.style.display = 'none';
    if (customerLabelsDropdown && !customerLabelsDropdown.contains(e.target) && e.target.id !== 'btn_customer_labels_dropdown') {
        customerLabelsDropdown.style.display = 'none';
    }
});

btnAddTag?.addEventListener('click', e => {
    e.preventDefault();
    replyDropdown.style.display = 'none';
    const isShown = tagDropdown.style.display === 'block';
    tagDropdown.style.display = isShown ? 'none' : 'block';
    if (!isShown) {
        loadFanpageLabels();
    }
});

function toggleCustomerLabelsDropdown(e) {
    if (e) e.stopPropagation();
    if (tagDropdown) tagDropdown.style.display = 'none';
    if (replyDropdown) replyDropdown.style.display = 'none';
    if (!customerLabelsDropdown) return;
    const isShown = customerLabelsDropdown.style.display === 'block';
    customerLabelsDropdown.style.display = isShown ? 'none' : 'block';
    if (!isShown) {
        loadFanpageLabels();
    }
}

window.pageLabelsCache = {};
window.customerLabelsCache = {};

function loadFanpageLabels(forceRefresh = false) {
    const pageId = activePageIdEl.value || currentActivePageId;
    const recipientId = activeRecipId.value || currentActiveSenderId;
    if (!pageId) return;

    const listInput = document.getElementById('input_tag_list_container');
    const cacheKey = pageId + '_' + recipientId;

    // Fast Instant Cache Rendering (0ms)
    if (!forceRefresh && window.pageLabelsCache[pageId]) {
        const allLabels = window.pageLabelsCache[pageId];
        const assignedLabels = window.customerLabelsCache[cacheKey] || [];
        renderLabelsDropdownList(listInput, allLabels, assignedLabels);
    } else if (listInput && listInput.children.length === 0) {
        listInput.innerHTML = '<div style="padding:10px;text-align:center;font-size:12px;color:#94a3b8;">Đang tải nhãn...</div>';
    }

    fetch(`actions/get_page_labels.php?page_id=${encodeURIComponent(pageId)}&recipient_id=${encodeURIComponent(recipientId)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                const allLabels = res.all_labels || [];
                const assignedLabels = res.assigned_labels || [];
                window.pageLabelsCache[pageId] = allLabels;
                window.customerLabelsCache[cacheKey] = assignedLabels;
                renderLabelsDropdownList(listInput, allLabels, assignedLabels);
            }
        })
        .catch(err => console.error(err));
}

function renderLabelsDropdownList(container, allLabels, assignedLabels) {
    if (!container) return;
    container.innerHTML = '';
    if (!allLabels.length) {
        container.innerHTML = '<div style="padding:10px;text-align:center;font-size:12px;color:#94a3b8;">Chưa có nhãn nào.</div>';
        return;
    }

    allLabels.forEach(labelName => {
        const isAssigned = assignedLabels.includes(labelName);
        const div = document.createElement('div');
        div.style.cssText = 'padding:7px 12px;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;transition:background 0.15s;color:#334155;';
        div.onmouseover = function() { this.style.background = '#f1f5f9'; };
        div.onmouseout = function() { this.style.background = 'transparent'; };

        const nameSpan = document.createElement('span');
        nameSpan.style.cssText = 'display:flex;align-items:center;gap:6px;';
        if (labelName === 'Spam') {
            nameSpan.innerHTML = '<span style="color:#ef4444;font-weight:600;">🚫 Spam</span>';
        } else {
            nameSpan.innerText = labelName;
        }

        const checkSpan = document.createElement('span');
        checkSpan.style.cssText = isAssigned ? 'color:#16a34a;font-weight:bold;font-size:14px;' : 'color:#cbd5e1;font-size:14px;';
        checkSpan.innerText = isAssigned ? '✓' : '○';

        div.appendChild(nameSpan);
        div.appendChild(checkSpan);

        div.onclick = function(e) {
            e.stopPropagation();
            toggleLabelForCustomer(labelName, isAssigned);
        };

        container.appendChild(div);
    });
}

function toggleLabelForCustomer(labelName, isCurrentlyAssigned) {
    const pageId = activePageIdEl.value || currentActivePageId;
    const recipId = activeRecipId.value || currentActiveSenderId;
    const convId = activeConvId.value;
    const userId = activeUserIdEl.value || currentUserId || 1;

    if (!pageId || !recipId) {
        showToast('Vui lòng chọn cuộc hội thoại trước.', 'error');
        return;
    }

    const cacheKey = pageId + '_' + recipId;
    let currentAssigned = window.customerLabelsCache[cacheKey] || [];

    // Optimistic UI Update (0ms)
    if (isCurrentlyAssigned) {
        currentAssigned = currentAssigned.filter(n => n !== labelName);
        // Remove label tag badge from chat_labels
        const tagSpan = Array.from(labelsDiv.querySelectorAll('.chat-tag')).find(el => el.textContent.trim().replace('✕','').trim() === labelName);
        if (tagSpan) tagSpan.remove();
    } else {
        if (!currentAssigned.includes(labelName)) {
            currentAssigned.push(labelName);
        }
        // Add label tag badge to chat_labels
        const names = Array.from(labelsDiv.querySelectorAll('.chat-tag')).map(el => el.textContent.trim().replace('✕','').trim());
        if (!names.includes(labelName)) {
            const span = document.createElement('span');
            span.className = 'chat-tag ' + tagClass(labelName);
            span.innerHTML = `${labelName} <button onclick="removeLabel(this,'${labelName.replace(/'/g,"\\'")}')">✕</button>`;
            labelsDiv.appendChild(span);
        }
    }
    window.customerLabelsCache[cacheKey] = currentAssigned;

    const listInput = document.getElementById('input_tag_list_container');
    const allLabels = window.pageLabelsCache[pageId] || [];
    renderLabelsDropdownList(listInput, allLabels, currentAssigned);

    showToast(isCurrentlyAssigned ? `Đã bỏ nhãn "${labelName}"` : `Đã gắn nhãn "${labelName}"`, 'success');

    // Asynchronous background update (No waiting / No UI blocking)
    const actionUrl = isCurrentlyAssigned ? 'actions/remove_label.php' : 'actions/add_tag.php';
    const fd = new FormData();
    fd.append('page_id', pageId);
    fd.append('recipient_id', recipId);
    fd.append('conv_id', convId);
    fd.append('user_id', userId);
    if (isCurrentlyAssigned) {
        fd.append('label_name', labelName);
    } else {
        fd.append('tag_name', labelName);
    }

    fetch(actionUrl, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                // Refresh list silently without appending duplicate rows
                loadConversations(false, true);
            }
        })
        .catch(err => console.error(err));
}

// ── Saved Replies ─────────────────────────────────────────────────────────
btnSavedReply?.addEventListener('click', e => {
    e.preventDefault();
    tagDropdown.style.display = 'none';
    replyDropdown.style.display = replyDropdown.style.display === 'block' ? 'none' : 'block';
    if (replyDropdown.style.display === 'block') loadSavedReplies();
});

function loadSavedReplies() {
    const container = document.getElementById('reply_list_container');
    container.innerHTML = '<div style="padding:10px;text-align:center;font-size:12px;color:#666;">Đang tải...</div>';
    fetch('actions/manage_replies.php?action=list&user_id=' + activeUserIdEl.value)
        .then(r => r.json())
        .then(data => {
            container.innerHTML = '';
            if (data.status === 'success' && data.data.length) {
                data.data.forEach(reply => {
                    const div = document.createElement('div');
                    div.className = 'dropdown-item';
                    div.title = reply.content;
                    div.innerHTML = `<div style="font-weight:500;font-size:12px;">${reply.title || 'Không tiêu đề'}</div><div style="font-size:11px;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${reply.content}</div>`;
                    div.addEventListener('click', () => { replyText.value = reply.content; replyDropdown.style.display = 'none'; replyText.focus(); });
                    const del = document.createElement('span');
                    del.innerHTML = '🗑️'; del.style.cssText = 'float:right;font-size:11px;opacity:.5;cursor:pointer;';
                    del.onclick = e => { e.stopPropagation(); if(confirm('Xóa?')) { const fd=new FormData();fd.append('id',reply.id);fetch('actions/manage_replies.php?action=delete',{method:'POST',body:fd}).then(()=>loadSavedReplies()); } };
                    div.appendChild(del);
                    container.appendChild(div);
                });
            } else {
                container.innerHTML = '<div style="padding:10px;text-align:center;font-size:12px;color:#666;">Chưa có tin mẫu.</div>';
            }
        });
}

// ── File attach ───────────────────────────────────────────────────────────
const btnAttach    = document.getElementById('btn_attach');
const replyFile    = document.getElementById('reply_file');
const filePreview  = document.getElementById('file_preview_container');
const filePreviewNm= document.getElementById('file_preview_name');
btnAttach.addEventListener('click', () => { if (!replyText.disabled) replyFile.click(); });
replyFile.addEventListener('change', function() {
    if (this.files?.[0]) { filePreviewNm.innerText = '📎 ' + this.files[0].name; filePreview.style.display = 'block'; }
});
document.getElementById('btn_remove_file').addEventListener('click', () => { replyFile.value=''; filePreview.style.display='none'; });

// ── Send Message ──────────────────────────────────────────────────────────
document.getElementById('reply_form').addEventListener('submit', function(e) {
    e.preventDefault();
    const text   = replyText.value.trim();
    const file   = replyFile.files[0];
    const recipId= activeRecipId.value;
    if (!recipId || (!text && !file)) return;

    setChatEnabled(false);
    const fd = new FormData();
    if (text) fd.append('message', text);
    if (file) fd.append('filedata', file);
    fd.append('recipient_id', recipId);
    fd.append('page_id',      activePageIdEl.value);
    fd.append('user_id',      activeUserIdEl.value);

    fetch('actions/send_message.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                let html = text ? text.replace(/\n/g,'<br>') : '';
                if (file) html += (html?'<br>':'') + `<i>[Đã gửi: ${file.name}]</i>`;
                const div = document.createElement('div');
                div.innerHTML = `<div class="msg-bubble msg-sent">${html}</div><div class="msg-time">Vừa xong</div>`;
                chatMessages.appendChild(div);
                chatMessages.scrollTop = chatMessages.scrollHeight;
                replyText.value = ''; replyFile.value = ''; filePreview.style.display = 'none';
            } else {
                showToast(data.msg, 'error');
            }
        }).catch(() => showToast('Lỗi mạng', 'error'))
        .finally(() => { setChatEnabled(true); replyText.focus(); });
});

// ── Modal thêm tin mẫu ───────────────────────────────────────────────────
const customModal = document.getElementById('addReplyModalCustom');
document.getElementById('btn_open_add_reply').addEventListener('click', e => { e.preventDefault(); replyDropdown.style.display='none'; customModal.style.display='flex'; });
document.getElementById('btn_close_add_reply').addEventListener('click', () => { customModal.style.display='none'; });
document.getElementById('form_add_reply').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('user_id', activeUserIdEl.value);
    fd.append('title',   document.getElementById('reply_title_input').value);
    fd.append('content', document.getElementById('reply_content_input').value);
    fetch('actions/manage_replies.php?action=add', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(data => {
            if (data.status==='success') { customModal.style.display='none'; this.reset(); loadSavedReplies(); }
            else showToast('Lỗi: '+data.msg, 'error');
        });
});

// ── Auto Refresh Polling ──────────────────────────────────────────────────
setInterval(() => {
    if (currentPageId && currentUserId) {
        let url = 'actions/get_conversations.php?page_id=' + currentPageId + '&user_id=' + currentUserId;
        if (isMergedChat) url = 'actions/get_conversations.php?merge_all=1';
        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') { mergeConversations(data.data); }
            }).catch(()=>{});
    }
}, 4000);

setInterval(() => {
    const convId  = activeConvId.value;
    const recipId = activeRecipId.value;
    const isBottom= chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 50;
    if (convId && recipId && isBottom) {
        loadMessages(convId, chatHeader.innerText, recipId, activePageIdEl.value, activeUserIdEl.value, true);
    }
}, 4000);

// ── Customer Info Panel Logic ─────────────────────────────────────────────
function toggleInfoPanel() {
    const panel = document.getElementById('customer_info_panel');
    const chatbox = document.querySelector('.lc-chatbox');
    if (panel.style.display === 'none') {
        panel.style.display = 'flex';
        chatbox.style.borderRadius = '0';
        localStorage.setItem('show_info_panel', '1');
    } else {
        panel.style.display = 'none';
        chatbox.style.borderRadius = '0 10px 10px 0';
        localStorage.setItem('show_info_panel', '0');
    }
}

function loadCustomerInfo(senderId, pageId, senderName = '') {
    currentActiveSenderId = senderId;
    currentActivePageId = pageId;
    document.getElementById('info_id_display').innerText = 'ID: ' + senderId;
    const fallbackName = senderName || 'Khách hàng';
    document.getElementById('info_avatar').src = `avatar.php?id=${senderId}&page_id=${pageId}&name=${encodeURIComponent(fallbackName)}`;
    
    // Clear form inputs & ad badge
    document.getElementById('info_name').value = '';
    document.getElementById('info_phone').value = '';
    document.getElementById('info_province').value = '';
    document.getElementById('info_notes').value = '';
    document.getElementById('info_consulted').value = '0';
    document.getElementById('info_sales_phone').value = '';
    document.getElementById('info_sales_notes').value = '';
    document.getElementById('info_name_display').innerText = 'Đang tải...';
    
    const adBadgeEl = document.getElementById('info_ad_badge');
    if (adBadgeEl) {
        adBadgeEl.innerHTML = '';
    }
    
    const commentsSection = document.getElementById('info_comments_section');
    if (commentsSection) {
        commentsSection.innerHTML = '';
    }
    
    fetch(`actions/get_customer_info.php?sender_id=${encodeURIComponent(senderId)}&page_id=${encodeURIComponent(pageId)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                const data = res.data;
                const finalName = data.name || fallbackName;
                document.getElementById('info_name_display').innerText = finalName;
                document.getElementById('info_name').value = data.name || '';
                document.getElementById('info_phone').value = data.phone || '';
                document.getElementById('info_province').value = data.province || '';
                document.getElementById('info_notes').value = data.notes || '';
                document.getElementById('info_consulted').value = String(data.consulted || 0);
                document.getElementById('info_sales_phone').value = data.sales_phone || '';
                document.getElementById('info_sales_notes').value = data.sales_notes || '';
                
                // Update CAPI push button visibility
                const capiArea = document.getElementById('capi_push_area');
                const capiBtn = document.getElementById('btn_push_capi');
                const capiPushStatus = document.getElementById('capi_push_status');
                // Check if current page has CAPI configured
                const activePageId = pageId || currentPageId;
                const activePage = allPages.find(p => p.page_id === activePageId);
                if (activePage && activePage.capi_pixel_id && activePage.capi_token) {
                    capiArea.style.display = 'block';
                    if (parseInt(data.capi_pushed) === 1) {
                        capiBtn.style.background = '#d1fae5';
                        capiBtn.style.color = '#065f46';
                        capiBtn.innerHTML = '✅ Đã gửi CAPI';
                        capiBtn.disabled = true;
                        capiPushStatus.style.display = 'none';
                    } else {
                        capiBtn.style.background = '#7c3aed';
                        capiBtn.style.color = '#fff';
                        capiBtn.innerHTML = '🔌 Đẩy lên Facebook CAPI';
                        capiBtn.disabled = false;
                        capiPushStatus.style.display = 'none';
                    }
                } else {
                    capiArea.style.display = 'none';
                }
                
                // Update avatar with proper DB name
                document.getElementById('info_avatar').src = `avatar.php?id=${senderId}&page_id=${pageId}&name=${encodeURIComponent(finalName)}`;
                
                // Update Ad details badge on sidebar
                if (adBadgeEl) {
                    if (data.is_ads == 1) {
                        let adTitle = data.ad_title ? data.ad_title : 'Quảng cáo Facebook';
                        let photoHtml = data.ad_photo_url ? `<img src="${data.ad_photo_url}" style="width:100%;max-height:120px;object-fit:cover;border-radius:6px;margin-top:6px;border:1px solid #e2e8f0;">` : '';
                        adBadgeEl.innerHTML = `
                            <div style="background-color:#ffe4e6;color:#e11d48;border:1px solid #fda4af;padding:6px 10px;border-radius:6px;font-size:11px;text-align:left;margin-top:4px;box-sizing:border-box;">
                                <div style="font-weight:700;display:flex;align-items:center;gap:4px;">📢 Đến từ Ads (Trả phí)</div>
                                <div style="margin-top:3px;font-weight:500;word-break:break-word;">Bài viết: ${adTitle}</div>
                                ${data.ad_id ? `<div style="font-size:10px;color:#f43f5e;margin-top:2px;">Ad ID: ${data.ad_id}</div>` : ''}
                                ${photoHtml}
                            </div>
                        `;
                    } else {
                        adBadgeEl.innerHTML = `
                            <div style="background-color:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:6px 10px;border-radius:6px;font-size:11px;text-align:left;margin-top:4px;font-weight:700;box-sizing:border-box;">
                                🆓 Đến từ nguồn tự nhiên (Miễn phí)
                            </div>
                        `;
                    }
                }

                // Render recent comments on posts (attribution for free customers)
                if (commentsSection) {
                    if (data.recent_comments && data.recent_comments.length > 0) {
                        let html = `
                            <div style="background-color:#f8fafc;color:#334155;border:1px solid #e2e8f0;padding:8px 12px;border-radius:6px;font-size:11px;text-align:left;box-sizing:border-box;margin-top:4px;">
                                <div style="font-weight:700;display:flex;align-items:center;gap:4px;margin-bottom:6px;color:#0284c7;">💬 Bình luận gần đây</div>
                                <ul style="margin:0;padding-left:14px;display:flex;flex-direction:column;gap:8px;">
                        `;
                        data.recent_comments.forEach(cmt => {
                            let link = `https://facebook.com/${cmt.post_id}`;
                            if (cmt.post_id && cmt.post_id.includes('_')) {
                                let parts = cmt.post_id.split('_');
                                link = `https://facebook.com/${parts[0]}/posts/${parts[1]}`;
                            }
                            let commentLink = cmt.comment_id ? `${link}?comment_id=${cmt.comment_id}` : link;
                            let cmtTime = new Date(cmt.created_at).toLocaleString('vi-VN', {hour: '2-digit', minute:'2-digit', day:'2-digit', month:'2-digit'});
                            html += `
                                <li style="line-height:1.3;">
                                    <span style="font-weight:600;display:block;word-break:break-word;">"${cmt.snippet}"</span>
                                    <span style="color:#64748b;font-size:9px;">${cmtTime}</span>
                                    <span style="margin-left:4px;">
                                        <a href="${link}" target="_blank" style="color:#0284c7;text-decoration:none;font-weight:600;">[Xem Bài]</a>
                                        <a href="${commentLink}" target="_blank" style="color:#10b981;text-decoration:none;font-weight:600;margin-left:4px;">[Xem CMT]</a>
                                    </span>
                                </li>
                            `;
                        });
                        html += `
                                </ul>
                            </div>
                        `;
                        commentsSection.innerHTML = html;
                    } else {
                        commentsSection.innerHTML = '';
                    }
                }
                
                // Update bot toggle button status (header + panel)
                const isLocked = res.data.is_locked == 1;
                updateBotToggleUI(isLocked);
            } else {
                document.getElementById('info_name_display').innerText = fallbackName;
            }
        }).catch(() => {
            document.getElementById('info_name_display').innerText = fallbackName;
        });
}

function updateBotToggleUI(isLocked) {
    // Header button
    const btnHeader = document.getElementById('btn_toggle_bot');
    if (btnHeader) {
        if (isLocked) {
            btnHeader.innerHTML = '❌ Bot: OFF';
            btnHeader.style.background = '#fee2e2';
            btnHeader.style.color = '#991b1b';
            btnHeader.style.borderColor = '#fca5a5';
        } else {
            btnHeader.innerHTML = '🤖 Bot: ON';
            btnHeader.style.background = '#dcfce7';
            btnHeader.style.color = '#166534';
            btnHeader.style.borderColor = '#86efac';
        }
    }
    // Panel button
    const btnPanel = document.getElementById('btn_toggle_bot_panel');
    if (btnPanel) {
        if (isLocked) {
            btnPanel.innerHTML = '❌ Bot đang TẮT — Bạn đang tự trả lời';
            btnPanel.style.background = '#fee2e2';
            btnPanel.style.color = '#991b1b';
            btnPanel.style.borderColor = '#fca5a5';
        } else {
            btnPanel.innerHTML = '🤖 Bot đang BẬT — Tự động trả lời';
            btnPanel.style.background = '#dcfce7';
            btnPanel.style.color = '#166534';
            btnPanel.style.borderColor = '#86efac';
        }
    }
}

function toggleBotLock() {
    const senderId = activeRecipId.value;
    const pageId = activePageIdEl.value;
    if (!senderId || !pageId) return;

    const btnHeader = document.getElementById('btn_toggle_bot');
    const btnPanel = document.getElementById('btn_toggle_bot_panel');
    if (btnHeader) { btnHeader.disabled = true; btnHeader.innerHTML = '⏱️ ...'; }
    if (btnPanel) { btnPanel.disabled = true; btnPanel.innerHTML = '⏱️ Đang xử lý...'; }

    const fd = new FormData();
    fd.append('type', 'facebook');
    fd.append('sender_id', senderId);
    fd.append('channel_id', pageId);
    fd.append('action', 'toggle');

    fetch('actions/toggle_bot_lock.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            loadCustomerInfo(senderId, pageId);
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
        if (btnHeader) btnHeader.disabled = false;
        if (btnPanel) btnPanel.disabled = false;
    });
}

function saveCustomerInfo(e) {
    e.preventDefault();
    const senderId = activeRecipId.value;
    const pageId = activePageIdEl.value;
    if (!senderId || !pageId) return;
    
    const name = document.getElementById('info_name').value.trim();
    const phone = document.getElementById('info_phone').value.trim();
    const province = document.getElementById('info_province').value.trim();
    const notes = document.getElementById('info_notes').value.trim();
    const consulted = document.getElementById('info_consulted').value;
    const sales_phone = document.getElementById('info_sales_phone').value.trim();
    const sales_notes = document.getElementById('info_sales_notes').value.trim();
    
    const fd = new FormData();
    fd.append('sender_id', senderId);
    fd.append('page_id', pageId);
    fd.append('name', name);
    fd.append('phone', phone);
    fd.append('province', province);
    fd.append('notes', notes);
    fd.append('consulted', consulted);
    fd.append('sales_phone', sales_phone);
    fd.append('sales_notes', sales_notes);
    
    const btn = e.target.querySelector('button[type="submit"]');
    const oldText = btn.innerText;
    btn.disabled = true;
    btn.innerText = 'Đang lưu...';
    
    fetch('actions/save_customer_info.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            document.getElementById('info_name_display').innerText = name || 'Khách hàng';
            // Refresh conversation list to show name updates and label if phone was added
            if (currentPageId && currentUserId) {
                let url = 'actions/get_conversations.php?page_id=' + currentPageId + '&user_id=' + currentUserId;
                if (isMergedChat) url = 'actions/get_conversations.php?merge_all=1';
                fetch(url)
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') { currentConversations = data.data; renderConversations(); }
                    });
            }
            if (activeConvId.value) {
                fetch('actions/get_labels.php?conv_id=' + encodeURIComponent(activeConvId.value) + '&page_id=' + encodeURIComponent(pageId))
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            renderLabels(data.data.map(l => l.label_name));
                        }
                    });
            }
            showToast(res.msg, 'success');
        } else {
            showToast(res.msg, 'error');
        }
    })
    .catch(() => {
        showToast('Có lỗi xảy ra khi kết nối máy chủ!', 'error');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerText = oldText;
    });
}

// Restore side panel visibility state from localStorage
const showPanelState = localStorage.getItem('show_info_panel') !== '0';
document.getElementById('customer_info_panel').style.display = showPanelState ? 'flex' : 'none';
if (showPanelState) {
    document.querySelector('.lc-chatbox').style.borderRadius = '0';
}

// Tab switcher
document.addEventListener('DOMContentLoaded', function() {
    // Tìm kiếm cuộc hội thoại Facebook
    const searchInput = document.getElementById('conv_search');
    let searchTimeout = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            renderConversations(); // Phản hồi tức thì cho phần đã tải
            
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                loadConversations(false, true); // Quét toàn bộ DB qua AJAX
            }, 400);
        });
    }

    const tabs = document.querySelectorAll('.zalo-tab-btn');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            const targetId = tab.getAttribute('data-target');
            document.querySelectorAll('.zalo-tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(targetId).classList.add('active');
            
            // Load specific tab data
            if (targetId === 'tab-bot-settings') {
                switchBotTab('welcome');
                loadBotRules();
            } else if (targetId === 'tab-capi-settings') {
                renderCapiPagesList();
            }
        });
    });
});

// ==================== CAPI Functions ====================
function renderCapiPagesList() {
    const listDiv = document.getElementById('capi_pages_list');
    listDiv.innerHTML = '';
    
    // Sắp xếp: Page đã cấu hình hoặc đang được chọn lên đầu
    const sortedPages = [...allPages].sort((a, b) => {
        const aHas = a.capi_pixel_id ? 1 : 0;
        const bHas = b.capi_pixel_id ? 1 : 0;
        if (aHas !== bHas) return bHas - aHas;
        return a.name.localeCompare(b.name);
    });
    
    sortedPages.forEach(p => {
        const isConfigured = !!p.capi_pixel_id;
        const hasConfig = isConfigured ? ' <span style="font-size:10px; background:#d1fae5; color:#065f46; padding:1px 5px; border-radius:4px; font-weight:bold;">Đã cấu hình</span>' : '';
        const item = document.createElement('label');
        item.className = 'capi-page-item';
        item.style.display = 'flex';
        item.style.alignItems = 'center';
        item.style.gap = '8px';
        item.style.fontSize = '13px';
        item.style.cursor = 'pointer';
        item.style.userSelect = 'none';
        item.style.margin = '4px 0';
        item.dataset.name = p.name.toLowerCase();
        item.dataset.configured = isConfigured ? 'true' : 'false';
        
        // Tự động tích chọn nếu trang này đang hoạt động ở màn hình chat
        const isChecked = (currentPageId && currentPageId !== 'merge' && p.page_id == currentPageId) ? 'checked' : '';
        
        item.innerHTML = `
            <input type="checkbox" name="capi_pages[]" value="${p.page_id}" ${isChecked} onchange="handleCapiPageCheck()">
            <span style="flex:1;">${p.name.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;")} <span style="color:#64748b; font-size:11px;">(${p.page_id})</span>${hasConfig}</span>
            ${isConfigured ? `<a href="javascript:void(0)" onclick="quickEditCapi('${p.page_id}', event)" style="font-size:11px; color:#0284c7; text-decoration:none; font-weight:600; padding:2px 6px; border:1px solid #0284c7; border-radius:4px; background:#f0f9ff; margin-left:8px; display:inline-block; line-height:1;">✏️ Sửa nhanh</a>` : ''}
        `;
        listDiv.appendChild(item);
    });
    
    document.getElementById('capi_select_all_pages').checked = false;
    document.getElementById('capi_page_search').value = '';
    document.getElementById('capi_filter_status').value = 'all';
    
    // Gọi ngay handleCapiPageCheck để hiển thị form và tải cấu hình (nếu có tích chọn tự động)
    handleCapiPageCheck();
}

function filterCapiPages() {
    const q = document.getElementById('capi_page_search').value.toLowerCase().trim();
    const filterStatus = document.getElementById('capi_filter_status').value;
    const items = document.querySelectorAll('.capi-page-item');
    items.forEach(item => {
        const name = item.dataset.name;
        const pageId = item.querySelector('input').value;
        const isConfigured = item.dataset.configured === 'true';
        
        let matchText = name.includes(q) || pageId.includes(q);
        let matchStatus = true;
        if (filterStatus === 'configured') {
            matchStatus = isConfigured;
        } else if (filterStatus === 'unconfigured') {
            matchStatus = !isConfigured;
        }
        
        if (matchText && matchStatus) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function quickEditCapi(pageId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const checkboxes = document.querySelectorAll('input[name="capi_pages[]"]');
    checkboxes.forEach(cb => {
        cb.checked = (cb.value === pageId);
    });
    handleCapiPageCheck();
}

function toggleCapiSelectAll(allCb) {
    const items = document.querySelectorAll('.capi-page-item');
    items.forEach(item => {
        if (item.style.display !== 'none') {
            const cb = item.querySelector('input');
            cb.checked = allCb.checked;
        }
    });
    handleCapiPageCheck();
}

function handleCapiPageCheck() {
    const checkedBoxes = document.querySelectorAll('input[name="capi_pages[]"]:checked');
    const formArea = document.getElementById('capi_form_area');
    const statusEl = document.getElementById('capi_status');
    statusEl.style.display = 'none';
    
    if (checkedBoxes.length === 0) {
        formArea.style.display = 'none';
        return;
    }
    
    formArea.style.display = 'block';
    
    // Nếu chỉ tích chọn 1 hoặc có nhiều, ta lấy thông tin của trang đầu tiên tích chọn để hiển thị mẫu
    const firstPageId = checkedBoxes[0].value;
    fetchCapiSettingsForPage(firstPageId);
}

function fetchCapiSettingsForPage(pageId) {
    fetch(`actions/get_page_capi.php?page_id=${encodeURIComponent(pageId)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('capi_pixel_id').value = res.data.capi_pixel_id || '';
                document.getElementById('capi_token').value = res.data.capi_token || '';
                document.getElementById('auto_send_capi').checked = parseInt(res.data.auto_send_capi ?? 1) === 1;
            } else {
                showCapiStatus(res.msg, 'error');
            }
        })
        .catch(e => showCapiStatus('Lỗi kết nối máy chủ.', 'error'));
}

function saveCapiSettings(e) {
    e.preventDefault();
    const checkedBoxes = document.querySelectorAll('input[name="capi_pages[]"]:checked');
    if (checkedBoxes.length === 0) { showCapiStatus('Vui lòng chọn ít nhất một Fanpage.', 'error'); return; }
    
    const pageIds = Array.from(checkedBoxes).map(cb => cb.value);
    const pixelId = document.getElementById('capi_pixel_id').value.trim();
    const token = document.getElementById('capi_token').value.trim();
    const autoSendCapi = document.getElementById('auto_send_capi').checked ? 1 : 0;
    
    const fd = new FormData();
    fd.append('page_ids', JSON.stringify(pageIds));
    fd.append('capi_pixel_id', pixelId);
    fd.append('capi_token', token);
    fd.append('auto_send_capi', autoSendCapi);
    
    fetch('actions/save_page_capi.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                showCapiStatus(res.msg, 'success');
                // Cập nhật cấu hình in-memory
                pageIds.forEach(pid => {
                    const p = allPages.find(x => x.page_id === pid);
                    if (p) {
                        p.capi_pixel_id = pixelId;
                        p.capi_token = token ? 'encrypted' : '';
                        p.auto_send_capi = autoSendCapi;
                    }
                });
                
                // Vẽ lại list để hiển thị badge 'Đã cấu hình'
                renderCapiPagesList();
                // Tích chọn lại các trang trước đó
                pageIds.forEach(pid => {
                    const cb = document.querySelector(`input[name="capi_pages[]"][value="${pid}"]`);
                    if (cb) cb.checked = true;
                });
                document.getElementById('capi_form_area').style.display = 'block';
            } else {
                showCapiStatus(res.msg, 'error');
            }
        })
        .catch(e => showCapiStatus('Lỗi kết nối máy chủ.', 'error'));
}

function testCapiEvent() {
    const checkedBoxes = document.querySelectorAll('input[name="capi_pages[]"]:checked');
    if (checkedBoxes.length === 0) { showCapiStatus('Vui lòng chọn ít nhất một Fanpage.', 'error'); return; }
    
    const pageId = checkedBoxes[0].value;
    const pixelId = document.getElementById('capi_pixel_id').value.trim();
    const token = document.getElementById('capi_token').value.trim();
    if (!pixelId || !token) { showCapiStatus('Vui lòng nhập Pixel ID và Token trước.', 'error'); return; }
    
    showCapiStatus('Đang gửi sự kiện test...', 'info');
    
    const testCode = document.getElementById('capi_test_code').value.trim();
    const fd = new FormData();
    fd.append('page_id', pageId);
    fd.append('is_test', 1);
    if (testCode) {
        fd.append('test_event_code', testCode);
    }
    
    fetch('actions/push_to_capi.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            showCapiStatus(res.msg, res.status === 'success' ? 'success' : 'error');
        })
        .catch(e => showCapiStatus('Lỗi kết nối máy chủ.', 'error'));
}

function showCapiStatus(msg, type) {
    const el = document.getElementById('capi_status');
    el.style.display = 'block';
    el.innerText = msg;
    if (type === 'success') {
        el.style.background = '#d1fae5'; el.style.color = '#065f46'; el.style.border = '1px solid #6ee7b7';
    } else if (type === 'error') {
        el.style.background = '#fee2e2'; el.style.color = '#991b1b'; el.style.border = '1px solid #fca5a5';
    } else {
        el.style.background = '#dbeafe'; el.style.color = '#1e40af'; el.style.border = '1px solid #93c5fd';
    }
}

function pushToCapi() {
    if (!currentActiveSenderId || !currentActivePageId) {
        alert('Vui lòng chọn một khách hàng trước.');
        return;
    }
    const btn = document.getElementById('btn_push_capi');
    const statusEl = document.getElementById('capi_push_status');
    btn.disabled = true;
    btn.innerHTML = '⏳ Đang gửi...';
    
    const fd = new FormData();
    fd.append('page_id', currentActivePageId);
    fd.append('sender_id', currentActiveSenderId);
    
    fetch('actions/push_to_capi.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                btn.style.background = '#d1fae5';
                btn.style.color = '#065f46';
                btn.innerHTML = '✅ Đã gửi CAPI';
                statusEl.style.display = 'block';
                statusEl.innerText = res.msg;
                statusEl.style.background = '#d1fae5'; statusEl.style.color = '#065f46';
            } else {
                btn.disabled = false;
                btn.style.background = '#7c3aed';
                btn.style.color = '#fff';
                btn.innerHTML = '🔌 Đẩy lên Facebook CAPI';
                statusEl.style.display = 'block';
                statusEl.innerText = res.msg;
                statusEl.style.background = '#fee2e2'; statusEl.style.color = '#991b1b';
            }
        })
        .catch(e => {
            btn.disabled = false;
            btn.innerHTML = '🔌 Đẩy lên Facebook CAPI';
            statusEl.style.display = 'block';
            statusEl.innerText = 'Lỗi kết nối máy chủ.';
            statusEl.style.background = '#fee2e2'; statusEl.style.color = '#991b1b';
        });
}

// ── Moderation: Move conversation to SPAM folder via Meta Graph API ──
function toggleSpamStatus() {
    const pageId = activePageIdEl.value || currentActivePageId;
    const senderId = activeRecipId.value || currentActiveSenderId;
    const convId = activeConvId.value;
    
    if (!pageId || (!senderId && !convId)) {
        showToast('Vui lòng chọn cuộc hội thoại trước.', 'error');
        return;
    }
    
    const btn = document.getElementById('btn_spam_toggle');
    const isCurrentSpam = btn.getAttribute('data-is-spam') === '1';
    const targetFolder = isCurrentSpam ? 'inbox' : 'spam';
    
    btn.disabled = true;
    btn.innerText = '⏳ Đang xử lý...';
    
    const fd = new FormData();
    fd.append('page_id', pageId);
    fd.append('sender_id', senderId);
    fd.append('conversation_id', convId);
    fd.append('folder', targetFolder);
    
    fetch('actions/mark_spam.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            showToast(res.msg, 'success');
            updateSpamButtonUI(targetFolder === 'spam');
            loadConversations(true);
        } else {
            showToast(res.msg || 'Không thể chuyển Spam.', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Lỗi kết nối máy chủ!', 'error');
    })
    .finally(() => {
        btn.disabled = false;
    });
}

function updateSpamButtonUI(isSpam) {
    const btn = document.getElementById('btn_spam_toggle');
    if (!btn) return;
    if (isSpam) {
        btn.setAttribute('data-is-spam', '1');
        btn.style.background = '#dcfce7';
        btn.style.borderColor = '#86efac';
        btn.style.color = '#15803d';
        btn.innerHTML = '✅ Bỏ Spam';
        btn.title = 'Khôi phục cuộc trò chuyện về Hộp thư đến';
    } else {
        btn.setAttribute('data-is-spam', '0');
        btn.style.background = '#fee2e2';
        btn.style.borderColor = '#fca5a5';
        btn.style.color = '#dc2626';
        btn.innerHTML = '🚫 Spam';
        btn.title = 'Chuyển cuộc trò chuyện vào thư mục Spam trên Facebook & Instagram';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
