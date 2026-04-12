<?php
$current_page = 'live_chat';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin   = ($_SESSION['role'] === 'admin');

// Fetch all pages for sidebar (owned + shared)
$stmt2 = $pdo->prepare("
    (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id, u.name AS user_name
     FROM pages p JOIN users u ON p.user_id = u.id
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id, u.name AS user_name
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

<div class="page-title">💬 Live Chat & Tin Nhắn</div>

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
        <div style="display:flex;gap:4px;padding:8px 10px;border-bottom:1px solid var(--border-color);background:#fff;">
            <button class="filter-btn active" data-filter="all">Tất cả</button>
            <button class="filter-btn" data-filter="unread">Chưa đọc</button>
            <button class="filter-btn" data-filter="phone">SĐT</button>
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
            <form id="reply_form" style="display:flex;gap:8px;align-items:center;position:relative;">
                <input type="hidden" id="active_conversation_id" value="">
                <input type="hidden" id="active_recipient_id" value="">
                <input type="hidden" id="active_page_id" value="">
                <input type="hidden" id="active_user_id" value="">
                <input type="file" id="reply_file" style="display:none;" accept="image/*,video/*,.pdf,.doc,.docx">

                <button type="button" id="btn_attach" class="btn btn-secondary" style="border-radius:50%;width:38px;height:38px;padding:0;flex-shrink:0;" title="Đính kèm tệp" disabled>📎</button>

                <div style="position:relative;flex-shrink:0;">
                    <button type="button" id="btn_add_tag" class="btn btn-secondary" style="border-radius:50%;width:38px;height:38px;padding:0;" title="Gắn nhãn" disabled>🏷️</button>
                    <div id="tag_dropdown" style="display:none;position:absolute;bottom:46px;left:0;background:#fff;border:1px solid #ccc;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.12);width:160px;z-index:20;">
                        <div style="padding:9px 12px;font-weight:600;border-bottom:1px solid #eee;font-size:12px;">Thêm nhãn</div>
                        <div class="dropdown-item" data-val="Intake">Intake</div>
                        <div class="dropdown-item" data-val="Đã chuyển đổi">Đã chuyển đổi</div>
                        <div class="dropdown-item" data-val="Qualified">Qualified</div>
                        <div class="dropdown-item" data-val="Tiếp nhận">Tiếp nhận</div>
                        <div class="dropdown-item" data-val="Hot lead">Hot lead</div>
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
.tag-default   { background:#ccfbf1;color:#0f766e; }

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
    .livechat-container.chat-active .lc-conversations {
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

// active state
let currentPageId = '<?php echo $selected_page_id; ?>';
let currentUserId = '';
let selectedConvId = '<?php echo $selected_conv_id; ?>';
let selectedSenderId = '<?php echo $selected_sender_id; ?>';
let isMergedChat = false;

// ── Tag class helper ──────────────────────────────────────────────────────
function tagClass(name) {
    const n = name.toLowerCase();
    if (n.includes('intake'))     return 'tag-intake';
    if (n.includes('chuyển đổi'))return 'tag-converted';
    if (n.includes('qualified'))  return 'tag-qualified';
    if (n.includes('tiếp nhận')) return 'tag-accepted';
    if (n.includes('hot'))        return 'tag-hot';
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
function loadConversations(append = false) {
    if (!currentPageId || !currentUserId) return;

    const spinnerSvg = `<svg style="animation: spin 1s linear infinite; width: 24px; height: 24px; margin-bottom: 8px; color: #0284c7;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity:0.25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>`;

    if (!append) {
        convList.innerHTML = `<div style="padding:40px 20px;text-align:center;color:#6b7280;font-size:13px;">${spinnerSvg}<br>Đang tải dữ liệu...</div>`;
        chatMessages.innerHTML = '';
        chatHeader.innerText = 'Chọn một cuộc hội thoại để xem';
        labelsDiv.innerHTML = '';
        setChatEnabled(false);
        convCursor = '';
    } else {
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
        if (append) url += '&append=1';
    } else if (append && convCursor) {
        url += '&after=' + convCursor;
    }

    fetch(url)
        .then(r => r.json())
        .then(data => {
            document.getElementById('conv_more_loader')?.remove();
            if (data.status === 'success') {
                if (append) {
                    currentConversations = currentConversations.concat(data.data);
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
        alert('Không hỗ trợ "Đọc tất cả" khi đang Gộp Fanpage. Xin vui lòng chọn từng Fanpage.');
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

// ── Render Conversations ──────────────────────────────────────────────────
function renderConversations() {
    const scrollTop = convList.scrollTop;
    convList.innerHTML = '';

    let filtered = currentConversations;
    if (currentFilter === 'unread') filtered = filtered.filter(c => c.unread_count > 0);
    else if (currentFilter === 'phone') {
        filtered = filtered.filter(c => {
            if (!c.messages || !c.messages.data) return false;
            return c.messages.data.some(m => m.from && m.from.id !== currentPageId && phoneRegex.test(m.message));
        });
    }

    const hasUnread = currentConversations.some(c => c.unread_count > 0);
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

        const div = document.createElement('div');
        div.className = 'conv-item' + (isUnread ? ' conv-unread' : '');
        if (activeConvId.value === conv.id) div.classList.add('active');
        div.dataset.id = conv.id;
        div.dataset.senderId = senderId;
        div.innerHTML = `
            <div style="font-size:13px;margin-bottom:3px;">${pagePrefix}${senderName}${isUnread ? '<span class="unread-dot"></span>': ''}</div>
            <div style="font-size:11px;color:${isUnread?'#111':'#6b7280'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${snippet}</div>
            <div style="font-size:10px;color:#9ca3af;margin-top:2px;">${updTime}</div>
        `;
        div.addEventListener('click', function() {
            document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
            this.classList.add('active');
            this.classList.remove('conv-unread');
            const dot = this.querySelector('.unread-dot'); if (dot) dot.remove();
            locallyReadConvs[conv.id] = new Date(conv.updated_time).getTime();
            localStorage.setItem('fb_read_cache', JSON.stringify(locallyReadConvs));
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
}

// ── Filter buttons ────────────────────────────────────────────────────────
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        currentFilter = this.dataset.filter;
        renderConversations();
    });
});

// ── Load Messages ─────────────────────────────────────────────────────────
function loadMessages(convId, senderName, senderId, activePageIdToUse = currentPageId, activeUserIdToUse = currentUserId, isAutoRefresh = false) {
    if (!isAutoRefresh) {
        chatHeader.innerHTML = `<button id="btn_back_mobile" onclick="mobileBackToList()" style="display:none;background:none;border:none;font-size:18px;cursor:pointer;padding:0;">←</button><span>${senderName}</span>`;
        if (window.innerWidth <= 768) {
            chatHeader.querySelector('#btn_back_mobile').style.display = 'inline-block';
        }
        chatMessages.innerHTML = '<div style="text-align:center;padding:20px;font-size:12px;">Đang tải tin nhắn...</div>';
        activeConvId.value  = convId;
        activeRecipId.value = senderId;
        activePageIdEl.value= activePageIdToUse;
        activeUserIdEl.value= activeUserIdToUse;
        currentCursor = '';
        isLoadingMore = false;

        labelsDiv.innerHTML = '';
        fetch('actions/get_labels.php?conv_id=' + encodeURIComponent(convId) + '&page_id=' + encodeURIComponent(activePageIdToUse))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    renderLabels(data.data.map(l => l.label_name));
                }
            });
    }

    fetch('actions/get_messages.php?conv_id=' + convId + '&page_id=' + activePageIdToUse + '&user_id=' + activeUserIdToUse)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                if (!isAutoRefresh) currentCursor = data.next_cursor || '';
                const isBottom = chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 50;
                renderMessages(data.data.reverse(), !isAutoRefresh || isBottom);
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
                if (att.image_data) content += `<br><img src="${att.image_data.url}" style="max-width:100%;border-radius:8px;margin-top:4px;">`;
                else if (att.video_data) content += `<br><video src="${att.video_data.url}" controls style="max-width:100%;border-radius:8px;"></video>`;
                else if (att.name) content += `<br><a href="${att.file_url}" target="_blank">📎 ${att.name}</a>`;
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

// ── Tag Dropdown ──────────────────────────────────────────────────────────
const btnAddTag    = document.getElementById('btn_add_tag');
const tagDropdown  = document.getElementById('tag_dropdown');
const btnSavedReply= document.getElementById('btn_saved_reply');
const replyDropdown= document.getElementById('reply_dropdown');

document.addEventListener('click', function(e) {
    if (btnAddTag && !btnAddTag.contains(e.target) && !tagDropdown.contains(e.target)) tagDropdown.style.display = 'none';
    if (btnSavedReply && !btnSavedReply.contains(e.target) && !replyDropdown.contains(e.target)) replyDropdown.style.display = 'none';
});

btnAddTag?.addEventListener('click', e => {
    e.preventDefault();
    replyDropdown.style.display = 'none';
    tagDropdown.style.display = tagDropdown.style.display === 'block' ? 'none' : 'block';
});

tagDropdown.querySelectorAll('.dropdown-item').forEach(item => {
    item.addEventListener('click', function() {
        const tagVal = this.dataset.val;
        if (!activeConvId.value) { alert('Chưa chọn hội thoại nào.'); return; }

        // Optimistic UI
        const names = Array.from(labelsDiv.querySelectorAll('.chat-tag')).map(el => el.textContent.trim().replace('✕','').trim());
        if (!names.includes(tagVal)) {
            const span = document.createElement('span');
            span.className = 'chat-tag ' + tagClass(tagVal);
            span.innerHTML = `${tagVal} <button onclick="removeLabel(this,'${tagVal.replace(/'/g,"\\'")}')">✕</button>`;
            labelsDiv.appendChild(span);
        }

        const fd = new FormData();
        fd.append('user_id',      activeUserIdEl.value);
        fd.append('page_id',      activePageIdEl.value);
        fd.append('recipient_id', activeRecipId.value);
        fd.append('conv_id',      activeConvId.value);
        fd.append('tag_name',     tagVal);
        fetch('actions/add_tag.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') alert('Lỗi gắn nhãn: ' + data.msg);
            });
        tagDropdown.style.display = 'none';
    });
});

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
                alert('Gửi thất bại: ' + data.msg);
            }
        }).catch(() => alert('Lỗi mạng'))
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
            else alert('Lỗi: '+data.msg);
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
                if (data.status === 'success') { currentConversations = data.data; renderConversations(); }
            }).catch(()=>{});
    }
}, 15000);

setInterval(() => {
    const convId  = activeConvId.value;
    const recipId = activeRecipId.value;
    const isBottom= chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 50;
    if (convId && recipId && isBottom) {
        loadMessages(convId, chatHeader.innerText, recipId, activePageIdEl.value, activeUserIdEl.value, true);
    }
}, 10000);
</script>

<?php include 'includes/footer.php'; ?>
