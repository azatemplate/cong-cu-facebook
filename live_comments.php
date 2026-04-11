<?php
$current_page = 'live_comments';
require_once __DIR__ . '/includes/db.php';

ob_start();
require_once __DIR__ . '/setup_live_comments.php'; // auto run setup for DB table
ob_end_clean(); // Clean output entirely so no random text is printed

require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin   = ($_SESSION['role'] === 'admin');

// Fetch all pages for sidebar
$stmt2 = $pdo->prepare("
    (SELECT p.id, p.page_id, p.name, p.user_id, u.name AS user_name
     FROM pages p JOIN users u ON p.user_id = u.id
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.id, p.page_id, p.name, p.user_id, u.name AS user_name
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

$selected_post_id = $_GET['post_id'] ?? '';
$selected_page_id = $_GET['page_id'] ?? '';

// Silently ensure columns exist if user forgot to run migrate script
try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN auto_reply_enabled TINYINT DEFAULT 0");
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN auto_reply_text TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN auto_inbox_enabled TINYINT DEFAULT 0");
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN auto_inbox_text TEXT DEFAULT NULL");
} catch (Exception $e) {}

// Fetch account auto-reply config
$stmt_acc = $pdo->prepare("SELECT auto_reply_enabled, auto_reply_text, auto_inbox_enabled, auto_inbox_text FROM system_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$acc_setup = $stmt_acc->fetch(PDO::FETCH_ASSOC);
$auto_reply_enabled = (int)($acc_setup['auto_reply_enabled'] ?? 0);
$auto_reply_text = $acc_setup['auto_reply_text'] ?? '';
$auto_inbox_enabled = (int)($acc_setup['auto_inbox_enabled'] ?? 0);
$auto_inbox_text = $acc_setup['auto_inbox_text'] ?? '';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
    <div class="page-title" style="margin-bottom:0;">📝 Live Comments (Quản lý Bình luận)</div>
    <button onclick="document.getElementById('autoReplyModal').style.display='flex';" class="btn btn-secondary" style="background:#f59e0b; color:#fff; border:none; display:flex; align-items:center; gap:5px; font-weight:600;"><span style="font-size:16px;">⚙️</span> Cài đặt Bot Tự Động</button>
</div>

<!-- Modal Cài đặt Tự động -->
<div id="autoReplyModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:25px; border-radius:10px; width:100%; max-width:500px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; border-bottom:1px solid #e5e7eb; padding-bottom:10px; color:#1f2937;">🤖 Cài đặt Bot Phản Hồi Tự Động</h3>
        <p style="font-size:12px; color:#6b7280; margin-bottom:15px;">Bot sẽ tự động hoạt động NGAY LẬP TỨC khi có khách bình luận mới (Áp dụng cho mọi Fanpage).</p>
        <form id="frm_auto_setup" onsubmit="saveAutoSetup(event)">
            <!-- 1. Trả lời bình luận -->
            <div style="margin-bottom: 20px; padding:15px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
                <label style="display:flex; align-items:center; gap:8px; font-weight:bold; font-size:14px; cursor:pointer; color:#0284c7;">
                    <input type="checkbox" id="chk_auto_reply" style="width:16px;height:16px;" <?php echo $auto_reply_enabled ? 'checked' : ''; ?>> Bật Tự động Trả Lời Bình Luận
                </label>
                <div style="margin-top:10px;">
                    <textarea id="txt_auto_reply" rows="3" placeholder="Ví dụ: Chào {name}, kiểm tra tin nhắn em nhé..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; resize:vertical;"><?php echo htmlspecialchars($auto_reply_text); ?></textarea>
                    <div style="font-size:11px; color:#6b7280; margin-top:4px;">Hỗ trợ biến: <b style="color:#000;">{name}</b> (Tên khách). Phản hồi công khai ngay dưới bình luận.</div>
                </div>
            </div>

            <!-- 2. Nhắn tin Inbox -->
            <div style="margin-bottom: 20px; padding:15px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
                <label style="display:flex; align-items:center; gap:8px; font-weight:bold; font-size:14px; cursor:pointer; color:#10b981;">
                    <input type="checkbox" id="chk_auto_inbox" style="width:16px;height:16px;" <?php echo $auto_inbox_enabled ? 'checked' : ''; ?>> Bật Tự động Inbox riêng (Private Reply)
                </label>
                <div style="margin-top:10px;">
                    <textarea id="txt_auto_inbox" rows="3" placeholder="Ví dụ: Chào {name}, em thấy anh/chị vừa bình luận..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; resize:vertical;"><?php echo htmlspecialchars($auto_inbox_text); ?></textarea>
                    <div style="font-size:11px; color:#6b7280; margin-top:4px;">Thông báo sẽ gửi vào tin nhắn riêng. Lưu ý: Page cần có quyền gửi tin nhắn để tránh bị lỗi.</div>
                </div>
            </div>

            <div style="text-align: right;">
                <button type="button" onclick="document.getElementById('autoReplyModal').style.display='none';" class="btn" style="background:#f3f4f6; color:#374151; margin-right:10px;">Hủy</button>
                <button type="submit" class="btn btn-primary" id="btn_save_setup">Lưu Cấu Hình</button>
            </div>
        </form>
    </div>
</div>

<script>
function saveAutoSetup(e) {
    e.preventDefault();
    const btn = document.getElementById('btn_save_setup');
    btn.disabled = true;
    btn.innerText = 'Đang lưu...';

    const fd = new FormData();
    fd.append('auto_reply_enabled', document.getElementById('chk_auto_reply').checked ? 1 : 0);
    fd.append('auto_reply_text', document.getElementById('txt_auto_reply').value);
    fd.append('auto_inbox_enabled', document.getElementById('chk_auto_inbox').checked ? 1 : 0);
    fd.append('auto_inbox_text', document.getElementById('txt_auto_inbox').value);

    fetch('actions/save_auto_reply.php', {
        method: 'POST',
        body: fd
    }).then(r => r.json()).then(res => {
        if (res.status === 'success') {
            document.getElementById('autoReplyModal').style.display = 'none';
            showInlineAlert('alert-success', res.msg);
        } else {
            showInlineAlert('alert-danger', res.msg);
        }
    }).catch(() => {
        showInlineAlert('alert-danger', 'Lỗi kết nối mạng hoặc máy chủ.');
        btn.disabled = false;
        btn.innerText = 'Lưu Cấu Hình';
    });
}

function showInlineAlert(typeClass, msg) {
    let alertBox = document.getElementById('temp_inline_alert');
    if (!alertBox) {
        alertBox = document.createElement('div');
        alertBox.id = 'temp_inline_alert';
        // Theo chuẩn class 'alert alert-success/danger' của hệ thống
        const titleRow = document.querySelector('.page-title').parentElement;
        titleRow.parentNode.insertBefore(alertBox, titleRow.nextSibling);
    }
    alertBox.className = `alert ${typeClass}`;
    alertBox.innerHTML = msg;
    alertBox.style.display = 'block';
    
    // Tự động tắt sau 3 giây
    setTimeout(() => {
        alertBox.style.display = 'none';
    }, 3000);
}
</script>

<div class="livechat-container" id="livechatContainer" style="display:flex;gap:0;height:calc(100vh - 140px);min-height:520px;">

    <!-- ── Sidebar: Fanpage List ─────────────────────────────────────────── -->
    <div class="lc-sidebar" style="width:200px;flex-shrink:0;background:var(--card-bg);border:1px solid var(--border-color);border-radius:10px 0 0 10px;display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:10px 14px;font-weight:700;font-size:13px;border-bottom:1px solid var(--border-color);background:#f9fafb;letter-spacing:.3px;">
            📄 Fanpages
        </div>
        <div style="padding:8px 10px;border-bottom:1px solid var(--border-color);background:#f9fafb;">
            <input type="text" id="page_search" placeholder="🔍 Tìm tên page..." style="width:100%;padding:6px 10px;margin-bottom:8px;border:1px solid var(--border-color);border-radius:6px;font-size:12px;box-sizing:border-box;outline:none;">
        </div>
        <div style="padding:10px 14px;border-bottom:1px solid var(--border-color);background:#eff6ff;">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#0284c7;cursor:pointer;">
                <input type="checkbox" id="chk_merge_all"> Gộp tất cả Fanpage
            </label>
        </div>
        <div id="page_sidebar" style="flex:1;overflow-y:auto;">
            <?php if (empty($pages)): ?>
            <div style="padding:16px;font-size:12px;color:var(--text-muted);text-align:center;">Chưa có fanpage nào</div>
            <?php else: ?>
            <?php foreach ($pages as $p): ?>
            <div class="page-tab" data-page-id="<?php echo htmlspecialchars($p['page_id']); ?>"
                 data-user-id="<?php echo (int)$p['user_id']; ?>"
                 title="<?php echo htmlspecialchars($p['name']); ?> — <?php echo htmlspecialchars($p['user_name']); ?>"
                 style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);transition:background .15s;">
                <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($p['name']); ?></div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($p['user_name']); ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- ── Posts List ────────────────────────────────────────────── -->
    <div class="lc-conversations" style="width:280px;flex-shrink:0;background:var(--card-bg);border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:10px 14px;border-bottom:1px solid var(--border-color);background:#f9fafb;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:13px;">Bài viết</span>
        </div>
        <div id="conversation_list" style="flex:1;overflow-y:auto;">
            <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px;">Chọn fanpage để tải bài viết.</div>
        </div>
    </div>

    <!-- ── Comments Box ──────────────────────────────────────────────────────── -->
    <div class="lc-chatbox" style="flex:1;min-width:0;background:var(--card-bg);border:1px solid var(--border-color);border-radius:0 10px 10px 0;display:flex;flex-direction:column;overflow:hidden;">
        <div id="chat_header" style="padding:14px 16px;border-bottom:1px solid var(--border-color);font-weight:600;font-size:14px;background:#fff;display:flex;align-items:center;gap:10px;">
            <button id="btn_back_mobile" onclick="mobileBackToList()" style="display:none;background:none;border:none;font-size:18px;cursor:pointer;padding:0;">←</button>
            <span id="header_title_text">Chọn bài viết để xem bình luận</span>
        </div>
        <div id="chat_messages" style="flex:1;overflow-y:auto;padding:15px;background:#f0f2f5;"></div>
        <div style="padding:12px 14px;border-top:1px solid var(--border-color);background:#fff;">
            
            <div id="reply_indicator" style="display:none; margin-bottom:8px; padding:8px 12px; background:#e0f2fe; border-left:4px solid #0284c7; font-size:12px; position:relative;">
                <span id="replying_to_text">Đang trả lời: </span>
                <button type="button" onclick="cancelReply()" style="position:absolute; right:8px; top:8px; background:none; border:none; cursor:pointer; color:#ef4444; font-weight:bold;">✕</button>
            </div>

            <div id="file_preview_container" style="display:none;margin-bottom:8px;padding:8px 12px;background:#f3f4f6;border-radius:8px;font-size:13px;">
                <span id="file_preview_name"></span>
                <button type="button" id="btn_remove_file" style="margin-left:10px;color:red;border:none;background:none;cursor:pointer;font-weight:bold;">✕ Xóa tệp</button>
            </div>

            <form id="reply_form" style="display:flex;gap:8px;align-items:center;position:relative;">
                <input type="hidden" id="active_target_id" value=""> <!-- có thể là post_id hoặc comment_id khi reply nhánh -->
                <input type="hidden" id="active_page_id" value="">
                <input type="hidden" id="active_user_id" value="">
                <input type="hidden" id="reply_action_type" value="comment"> <!-- 'comment' or 'private' -->
                <input type="hidden" id="is_replying_comment" value="0">
                <input type="file" id="reply_file" style="display:none;" accept="image/*,video/*">

                <button type="button" id="btn_attach" class="btn btn-secondary" style="border-radius:50%;width:38px;height:38px;padding:0;flex-shrink:0;" title="Đính kèm ảnh" disabled>📎</button>
                <input type="text" id="reply_text" style="flex:1;padding:9px 14px;border:1px solid var(--border-color);border-radius:20px;font-size:14px;" placeholder="Nhập bình luận..." disabled>
                <button type="submit" id="btn_send" class="btn btn-primary" style="border-radius:50%;width:40px;height:40px;padding:0;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow: 0 4px 6px -1px rgba(0, 132, 255, 0.4);border:none;" disabled>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" style="margin-left: -2px; margin-top: 1px;">
                      <path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576zm6.787-8.201L1.591 6.602l4.339 2.76z"/>
                    </svg>
                </button>
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
.conv-unread { background: #eff6ff; font-weight: 700; border-left: 3px solid transparent; }
.unread-dot { display:inline-block;width:8px;height:8px;background:#3b82f6;border-radius:50%;margin-left:4px;vertical-align:middle;box-shadow:0 0 2px rgba(59,130,246,0.5); }

/* Messages/Comments */
.msg-bubble { max-width:85%;padding:9px 14px;border-radius:18px;margin-bottom:8px;font-size:14px;line-height:1.45;clear:both; }
.msg-received { background:#fff;color:#000;float:left;border:1px solid #e5e7eb;border-bottom-left-radius:4px; }
.msg-sent { background:#0084ff;color:#fff;float:right;border-bottom-right-radius:4px; }
.msg-time { font-size:11px;color:#9ca3af;margin-bottom:12px;text-align:left;clear:both; }

.comment-wrapper { margin-bottom: 20px; clear:both; }
.reply-block { margin-left: 40px; margin-top: 10px; border-left: 2px solid #e5e7eb; padding-left: 10px; }

/* Mobile Live Chat */
@media (max-width: 768px) {
    .livechat-container { flex-direction: column !important; height: auto !important; min-height: auto !important; }
    .livechat-container .lc-sidebar { width: 100% !important; border-radius: 10px 10px 0 0 !important; max-height: 180px; }
    .livechat-container .lc-conversations { width: 100% !important; max-height: 300px; }
    .livechat-container .lc-chatbox { border-radius: 0 0 10px 10px !important; min-height: 400px; }
    .livechat-container.chat-active { position: fixed !important; top: 60px; left: 0; right: 0; bottom: 0px; height: calc(100dvh - 60px) !important; z-index: 9999; background: var(--bg-color); padding: 10px; box-sizing: border-box; }
    .livechat-container.chat-active .lc-sidebar, .livechat-container.chat-active .lc-conversations { display: none !important; }
    .livechat-container.chat-active .lc-chatbox { height: 100% !important; min-height: 0 !important; border-radius: 10px !important; flex: 1 !important; }
    #btn_back_mobile { display: inline-block !important; }
}

@keyframes spin { 100% { transform: rotate(360deg); } }
.spinner { width:30px; height:30px; border:3px solid #f3f3f3; border-top:3px solid #0084ff; border-radius:50%; animation:spin 1s linear infinite; margin:0 auto; }
</style>

<script>
const convList    = document.getElementById('conversation_list');
const chatHeader  = document.getElementById('header_title_text');
const chatMessages= document.getElementById('chat_messages');
const replyText   = document.getElementById('reply_text');
const btnSend     = document.getElementById('btn_send');
const activeTarget= document.getElementById('active_target_id'); // can be post_id or comment_id depending on reply branch
const activePageIdEl=document.getElementById('active_page_id');
const activeUserIdEl=document.getElementById('active_user_id');
let currentPosts  = [];
let convCursor    = '';

let currentPageId = '<?php echo $selected_page_id; ?>';
let currentUserId = '';
let selectedPostId= '<?php echo $selected_post_id; ?>'; // Khởi tạo bằng param
let originalPostId= '';

// ── Mobile Back to List ───────────────────────────────────────────────────
function mobileBackToList() {
    document.getElementById('livechatContainer').classList.remove('chat-active');
    activeTarget.value = '';
    chatHeader.innerHTML = 'Chọn bài viết để xem bình luận';
    chatMessages.innerHTML = '';
}

function setChatEnabled(enabled) {
    replyText.disabled = !enabled;
    btnSend.disabled   = !enabled;
    document.getElementById('btn_attach').disabled = !enabled;
}

// ── Search & Filter Pages ─────────────────────────────────────────────────
document.getElementById('page_search').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.page-tab').forEach(tab => {
        const name = tab.querySelector('div')?.textContent?.toLowerCase() || '';
        tab.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
});

let isMergeAll = false;

document.getElementById('chk_merge_all').addEventListener('change', function() {
    isMergeAll = this.checked;
    if (this.checked) {
        document.getElementById('page_sidebar').style.opacity = '0.5';
        document.getElementById('page_sidebar').style.pointerEvents = 'none';
        document.querySelectorAll('.page-tab').forEach(el => el.classList.remove('active'));
        currentPageId = 'ALL';
        currentUserId = 'ALL';
        loadPosts();
    } else {
        document.getElementById('page_sidebar').style.opacity = '1';
        document.getElementById('page_sidebar').style.pointerEvents = 'auto';
        currentPageId = '';
        currentUserId = '';
        convList.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px;">Chọn fanpage để tải bài viết.</div>';
    }
});

document.querySelectorAll('.page-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        if (isMergeAll) return;
        document.querySelectorAll('.page-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        currentPageId = this.dataset.pageId;
        currentUserId = this.dataset.userId;
        localStorage.setItem('lc_last_page_id', currentPageId);
        localStorage.setItem('lc_last_user_id', currentUserId);
        loadPosts();
    });
});

(function restoreLastPage() {
    let lastPage = currentPageId || localStorage.getItem('lc_last_page_id');
    let lastUser = localStorage.getItem('lc_last_user_id');
    
    // Nếu có query param page_id, focus thẳng vòng đó để lấy user_id luôn (bỏ qua cache)
    if (currentPageId) {
        lastPage = currentPageId;
        const tab = document.querySelector(`.page-tab[data-page-id="${lastPage}"]`);
        if (tab) lastUser = tab.dataset.userId;
    }

    if (lastPage && lastUser) {
        const tab = document.querySelector(`.page-tab[data-page-id="${lastPage}"]`);
        if (tab) {
            tab.click();
            tab.scrollIntoView({ block:'nearest' });
        }
    }
})();

// ── Webhook Subscription (Auto) ──────────────────────────────────────────────
if (!sessionStorage.getItem('webhook_subscribed_v2')) {
    fetch('actions/subscribe_webhook.php', { method: 'POST' }).then(() => {
        sessionStorage.setItem('webhook_subscribed_v2', '1');
    }).catch(e => console.error(e));
}

// ── Load Posts ────────────────────────────────────────────────────
function loadPosts(append = false, cursorOverride = null) {
    if (!currentPageId || !currentUserId) return;
    
    if (!append) {
        convList.innerHTML = '<div style="padding:40px 20px;text-align:center;"><div class="spinner"></div><div style="color:#6b7280;font-size:13px;margin-top:10px;">Đang tải bài viết...</div></div>';
        chatMessages.innerHTML = '';
        chatHeader.innerText = 'Chọn bài viết để xem bình luận';
        setChatEnabled(false);
        convCursor = '';
    }
    
    let activeCursor = append ? (cursorOverride !== null ? cursorOverride : convCursor) : '';
    let mergeAll = isMergeAll ? 1 : 0;
    
    // Nv1: Khai báo tham số target_post_id nếu có
    let targetParam = (selectedPostId && !append) ? '&target_post_id=' + selectedPostId : '';

    fetch('actions/get_posts_with_comments.php?page_id=' + currentPageId + '&user_id=' + currentUserId + '&merge_all=' + mergeAll + targetParam + (append ? '&after=' + activeCursor + '&append=1' : ''))
        .then(r => r.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error("Lỗi JSON:", text);
                convList.innerHTML = '<div style="padding:16px;color:red;font-size:12px;white-space:pre-wrap;word-break:break-all;"><b>Lỗi hệ thống:</b><br>' + text + '</div>';
                return;
            }
            if (data.status === 'success') {
                if (append) currentPosts = currentPosts.concat(data.data);
                else currentPosts = data.data;
                convCursor = data.next_cursor || '';
                renderPosts();

                // Nv1: Auto-open if redirected via query params
                if (selectedPostId && !append) {
                    const postTab = Array.from(document.querySelectorAll('.conv-item')).find(el => 
                        el.dataset.id === selectedPostId || el.dataset.id.endsWith('_' + selectedPostId)
                    );
                    if (postTab) {
                        postTab.click();
                        postTab.scrollIntoView({ block:'nearest' });
                    }
                    selectedPostId = ''; // Reset flag
                }
            } else {
                if (!append) convList.innerHTML = '<div style="padding:16px;color:red;font-size:12px;">' + (data.msg||'Lỗi tải') + '</div>';
            }
        })
        .catch(err => {
            console.error("Lỗi mạng:", err);
            if (!append) convList.innerHTML = '<div style="padding:16px;color:red;font-size:12px;">Lỗi kết nối máy chủ Mạng.</div>';
        });
}

function renderPosts() {
    convList.innerHTML = '';
    if (!currentPosts.length) {
        convList.innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-muted);">Không có bài viết nào.</div>';
        return;
    }
    
    currentPosts.forEach(post => {
        const div = document.createElement('div');
        div.className = 'conv-item' + (post.is_unread ? ' conv-unread' : '');
        div.dataset.id = post.id;
        
        const hasComments = post.has_comments;
        const isUnread = post.is_unread;
        const msgStr = (post.message && post.message.length > 50) ? post.message.substring(0, 50) + '...' : post.message;
        
        let thumbHtml = '';
        if (post.picture) {
            thumbHtml = `<img src="${post.picture}" style="width:40px;height:40px;border-radius:6px;object-fit:cover;margin-right:10px;flex-shrink:0;">`;
        } else {
            thumbHtml = `<div style="width:40px;height:40px;border-radius:6px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;margin-right:10px;flex-shrink:0;color:#9ca3af;font-size:18px;">📝</div>`;
        }

        let dotHtml = isUnread ? `<span class="unread-dot"></span>` : '';
        let unreadBold = isUnread ? '700' : (hasComments ? '600' : 'normal');

        div.innerHTML = `
            <div style="display:flex;align-items:flex-start;margin-bottom:6px;">
                ${thumbHtml}
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:${unreadBold};display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis;line-height:1.4;margin-bottom:4px;" class="msg-txt">
                        ${msgStr || '<i>(Bài viết/Ảnh không có chữ)</i>'}${dotHtml}
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                        <div style="font-size:10px;color:#9ca3af;">${new Date(post.created_time).toLocaleString('vi-VN')}</div>
                        <div style="font-size:11px; padding:2px 8px; background:${hasComments?'#e0f2fe':'#f3f4f6'}; color:${hasComments?'#0284c7':'#9ca3af'}; border-radius:10px; font-weight:600;">
                            ${post.comment_count} Bình luận
                        </div>
                    </div>
                </div>
            </div>
        `;
        div.addEventListener('click', function() {
            if (post.is_unread) {
                // Chỉ xóa indicator trong UI cục bộ, KHÔNG gọi read_notification.php
                // Việc mark-as-read thực sự chỉ xảy ra khi user click từ chuông thông báo
                this.classList.remove('conv-unread');
                let dot = this.querySelector('.unread-dot'); if (dot) dot.remove();
                let txt = this.querySelector('.msg-txt'); if (txt) txt.style.fontWeight = hasComments ? '600' : 'normal';
                post.is_unread = false;
            }
            
            document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
            this.classList.add('active');
            
            let headerThumb = post.picture ? `<img src="${post.picture}" style="width:34px;height:34px;border-radius:4px;object-fit:cover;">` : '<span style="font-size:24px;">📝</span>';
            chatHeader.innerHTML = `<div style="display:flex;align-items:center;gap:10px;">${headerThumb} <div><div style="font-size:13px;line-height:1.2;">Đang xem bình luận</div><div style="font-size:11px;color:#6b7280;font-weight:normal;max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${msgStr || 'Bài viết/Ảnh'}</div></div></div>`;
            
            activeTarget.value = post.id;
            originalPostId = post.id;
            activePageIdEl.value = currentPageId;
            activeUserIdEl.value = currentUserId;
            
            cancelReply(); // Reset state
            loadComments(post.id);
            
            if (window.innerWidth <= 768) {
                document.getElementById('livechatContainer').classList.add('chat-active');
            }
        });
        convList.appendChild(div);
    });

    // Nút "Tải thêm" — hiện khi còn cursor (kể cả khi không cần scroll)
    if (convCursor) {
        const loadMoreBtn = document.createElement('div');
        loadMoreBtn.id = 'load-more-posts-btn';
        loadMoreBtn.style.cssText = 'padding:12px;text-align:center;cursor:pointer;font-size:13px;color:#0284c7;font-weight:600;border-top:1px solid var(--border-color);transition:background .15s;';
        loadMoreBtn.textContent = '⬇️ Tải thêm bài viết';
        loadMoreBtn.onmouseover = () => loadMoreBtn.style.background = '#f0f9ff';
        loadMoreBtn.onmouseout  = () => loadMoreBtn.style.background = '';
        loadMoreBtn.onclick = () => {
            if (!convCursor) return;
            let tempCursor = convCursor;
            convCursor = '';
            loadMoreBtn.textContent = '⏳ Đang tải...';
            loadMoreBtn.style.pointerEvents = 'none';
            loadPosts(true, tempCursor);
        };
        convList.appendChild(loadMoreBtn);
    }
}

convList.addEventListener('scroll', function() {
    // Tăng threshold lên 120px để dễ trigger hơn
    if (this.scrollHeight - this.scrollTop <= this.clientHeight + 120 && convCursor) {
        let tempCursor = convCursor;
        convCursor = ''; // prevent multiple requests
        loadPosts(true, tempCursor);
    }
});

// ── Load & Render Comments ────────────────────────────────────────────────
function loadComments(postId) {
    chatMessages.innerHTML = '<div style="padding:40px 20px;text-align:center;"><div class="spinner"></div><div style="color:#6b7280;font-size:13px;margin-top:10px;">Đang tải bình luận...</div></div>';
    setChatEnabled(false);
    
    fetch('actions/get_post_comments.php?page_id=' + currentPageId + '&user_id=' + currentUserId + '&post_id=' + postId)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                renderComments(data.data);
                setChatEnabled(true);
            } else {
                chatMessages.innerHTML = '<div style="color:red;text-align:center;padding:16px;">' + data.msg + '</div>';
            }
        });
}

function renderComments(commentsList) {
    let html = '';

    // Render the original post at the top
    const post = currentPosts.find(p => p.id === activeTarget.value || p.id === originalPostId);
    if (post) {
        let postMedia = '';
        if (post.picture) {
            postMedia = `<img src="${post.picture}" style="max-width:100%; max-height:400px; object-fit:contain; display:block; margin:0 auto;">`;
        }
        let msgHtml = post.message ? post.message.replace(/\n/g, '<br>') : '';
        let pageName = document.querySelector('.page-tab.active div') ? document.querySelector('.page-tab.active div').innerText : 'Page';
        
        let pageAvatar = `https://ui-avatars.com/api/?name=${encodeURIComponent(pageName)}&background=random&size=100`;

        html += `
        <div style="background:#fff; margin-bottom:10px; border-bottom:1px solid #ced0d4;">
            <div style="display:flex; align-items:center; gap:8px; padding:12px 16px 8px;">
                <img src="${pageAvatar}" style="width:40px; height:40px; border-radius:50%; object-fit:cover;">
                <div>
                    <div style="font-weight:600; font-size:15px; color:#050505;">${pageName}</div>
                    <div style="font-size:13px; color:#65676b; margin-top:1px;">${new Date(post.created_time).toLocaleString('vi-VN')}</div>
                </div>
            </div>
            <div style="font-size:15px; line-height:1.4; color:#050505; padding:0 16px 12px; word-break:break-word;">
                ${msgHtml}
            </div>
            ${postMedia ? `<div style="background:#f0f2f5;text-align:center;">${postMedia}</div>` : ''}
            
            <div style="padding:10px 16px; display:flex; justify-content:space-between; align-items:center; font-size:15px; color:#65676b;">
                <div>👍 ❤️ 😆</div>
                <div>${post.comment_count} bình luận</div>
            </div>
            
            <div style="margin:0 16px; padding:4px 0; border-top:1px solid #ced0d4; border-bottom:1px solid #ced0d4; display:flex; justify-content:space-around; font-size:15px; font-weight:600; color:#65676b;">
                <div style="padding:6px 12px; cursor:default; display:flex; gap:6px; align-items:center;">👍 Thích</div>
                <div style="padding:6px 12px; cursor:default; display:flex; gap:6px; align-items:center;">💬 Bình luận</div>
                <div style="padding:6px 12px; cursor:default; display:flex; gap:6px; align-items:center;">↗️ Chia sẻ</div>
            </div>
            <div style="padding:12px 16px 0; font-weight:600; font-size:14px; color:#65676b;">Mới nhất ▼</div>
        </div>
        `;
    }

    if (!commentsList || commentsList.length === 0) {
        html += '<div style="padding:20px;text-align:center;font-size:13px;color:#9ca3af;">Chưa có bình luận nào. Bạn có thể bình luận đầu tiên!</div>';
        chatMessages.innerHTML = html;
        return;
    }
    
    // API returns reverse_chronological, so we reverse it again so oldest is at top like a typical post
    commentsList = commentsList.reverse();
    
    commentsList.forEach(c => {
        html += buildCommentHTML(c);
    });
    
    chatMessages.innerHTML = html;
    
    // Scroll handling: if replying, scroll to bottom to see latest. If first load, scroll to top of comments?
    // Let's scroll to bottom so user sees the newest comments.
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function buildCommentHTML(c, isReply = false) {
    const isSent = (c.from && c.from.id === currentPageId);
    let authorNameStr = c.from ? c.from.name : 'Unknown';
    let nameHtml = `<a href="#" style="font-weight:600; font-size:13px; color:#050505; text-decoration:none; display:inline-block; margin-bottom:2px;">${authorNameStr}</a>`;
    
    let content = c.message ? c.message.replace(/\n/g, '<br>') : '';
    if (c.attachment) {
        if (c.attachment.media && c.attachment.media.image) {
            content += `<br><img src="${c.attachment.media.image.src}" style="max-width:200px;border-radius:12px;margin-top:4px;display:block;">`;
        }
    }
    
    let authorAvatar = `https://ui-avatars.com/api/?name=${encodeURIComponent(authorNameStr)}&background=random&size=64`;
    if (isSent) {
        authorAvatar = 'https://ui-avatars.com/api/?name=P&background=0284c7&color=fff&size=64';
    }

    let timeStr = '';
    let now = new Date();
    let cTime = new Date(c.created_time);
    let diffDays = Math.floor((now - cTime) / (1000 * 60 * 60 * 24));
    if (diffDays === 0) {
        timeStr = cTime.toLocaleTimeString('vi-VN', {hour: '2-digit', minute:'2-digit'});
    } else if (diffDays < 7) {
        timeStr = diffDays + ' ngày';
    } else {
        timeStr = cTime.toLocaleDateString('vi-VN');
    }

    let actionsHtml = `
        <div style="display:flex; align-items:center; gap:12px; font-size:12px; font-weight:600; color:#65676b; margin-top:2px; margin-left:12px;">
            <span style="font-weight:normal;">${timeStr}</span>
            <span style="cursor:pointer;">Thích</span>
            ${!isSent ? `<span style="cursor:pointer;" onclick="prepareReply('${c.id}', '${authorNameStr.replace(/'/g, "\\'")}')">Trả lời</span>` : ''}
            ${!isSent ? `<span style="cursor:pointer; color:#0866ff;" onclick="preparePrivateReply('${c.id}', '${authorNameStr.replace(/'/g, "\\'")}')">Gửi tin nhắn</span>` : ''}
            <span style="cursor:pointer;">Ẩn</span>
        </div>
    `;

    let html = `
    <div class="comment-wrapper" id="comment_${c.id}" style="display:flex; gap:8px; margin-top:12px; ${!isReply ? 'padding:0 16px;' : ''}">
        <img src="${authorAvatar}" style="width:32px; height:32px; border-radius:50%; flex-shrink:0; object-fit:cover;">
        <div style="min-width:0;">
            <div style="background:#f0f2f5; border-radius:18px; padding:8px 12px; display:inline-block; max-width:100%;">
                ${nameHtml}<br>
                <div style="font-size:15px; color:#050505; word-wrap:break-word;">${content}</div>
            </div>
            ${actionsHtml}
    `;
    
    if (c.comments && c.comments.data && c.comments.data.length > 0) {
        html += '<div class="reply-block" style="margin-top:4px;">';
        let replies = c.comments.data.reverse(); 
        replies.forEach(rc => {
            html += buildCommentHTML(rc, true);
        });
        html += '</div>';
    }
    
    html += `
        </div>
    </div>
    `;
    return html;
}

// ── Send ────────────────────────────────────────────────────────
const replyForm = document.getElementById('reply_form');

function prepareReply(commentId, authorName) {
    activeTarget.value = commentId;
    document.getElementById('reply_action_type').value = 'comment';
    document.getElementById('is_replying_comment').value = '1';
    document.getElementById('reply_indicator').style.display = 'block';
    document.getElementById('reply_indicator').style.borderLeftColor = '#0284c7';
    document.getElementById('reply_indicator').style.backgroundColor = '#e0f2fe';
    document.getElementById('replying_to_text').innerText = 'Đang bình luận công khai cho: ' + authorName;
    document.getElementById('btn_attach').style.display = 'block';
    replyText.placeholder = 'Nhập bình luận...';
    replyText.focus();
}

function preparePrivateReply(commentId, authorName) {
    activeTarget.value = commentId;
    document.getElementById('reply_action_type').value = 'private';
    document.getElementById('is_replying_comment').value = '1';
    document.getElementById('reply_indicator').style.display = 'block';
    document.getElementById('reply_indicator').style.borderLeftColor = '#10b981';
    document.getElementById('reply_indicator').style.backgroundColor = '#d1fae5';
    document.getElementById('replying_to_text').innerHTML = 'Đang gửi <b style="color:#047857;">Tin nhắn riêng (Inbox)</b> cho: ' + authorName;
    document.getElementById('btn_attach').style.display = 'none'; // Private reply via comment endpoint only supports text reliably
    document.getElementById('file_preview_container').style.display = 'none';
    document.getElementById('reply_file').value = '';
    replyText.placeholder = 'Nhắn tin qua Inbox Facebook...';
    replyText.focus();
}

// Hủy reply comment -> quay về comment vào post
window.cancelReply = function() {
    activeTarget.value = originalPostId;
    document.getElementById('reply_action_type').value = 'comment';
    document.getElementById('is_replying_comment').value = '0';
    document.getElementById('reply_indicator').style.display = 'none';
    document.getElementById('btn_attach').style.display = 'block';
    replyText.placeholder = 'Nhập bình luận...';
}

replyForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const text = replyText.value.trim();
    const file = document.getElementById('reply_file').files[0];
    const targetId = activeTarget.value;
    
    if (!targetId || (!text && !file)) return;
    
    setChatEnabled(false);
    const fd = new FormData();
    fd.append('message', text);
    if (file) fd.append('filedata', file);
    fd.append('target_id', targetId);
    fd.append('page_id', activePageIdEl.value);
    fd.append('user_id', activeUserIdEl.value);
    
    const actionType = document.getElementById('reply_action_type').value;
    const endpoint = actionType === 'private' ? 'actions/send_private_reply.php' : 'actions/send_comment_reply.php';
    
    fetch(endpoint, { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                if (actionType === 'private') {
                    alert('Đã gửi tin nhắn (Inbox) thành công cho khách hàng.');
                }
                // Refresh comments instantly to show new comment
                replyText.value = ''; 
                document.getElementById('reply_file').value = ''; 
                document.getElementById('file_preview_container').style.display = 'none';
                cancelReply();
                loadComments(originalPostId);
            } else {
                alert('Gửi thất bại: ' + data.msg);
                setChatEnabled(true);
            }
        }).catch(() => { alert('Lỗi mạng'); setChatEnabled(true); });
});

// File attach
document.getElementById('btn_attach').addEventListener('click', () => { if(!replyText.disabled) document.getElementById('reply_file').click(); });
document.getElementById('reply_file').addEventListener('change', function() {
    if (this.files?.[0]) { 
        document.getElementById('file_preview_name').innerText = '📎 ' + this.files[0].name; 
        document.getElementById('file_preview_container').style.display = 'block'; 
    }
});
document.getElementById('btn_remove_file').addEventListener('click', () => { 
    document.getElementById('reply_file').value=''; 
    document.getElementById('file_preview_container').style.display='none'; 
});

// ── Auto Refresh ──────────────────────────────
setInterval(() => {
    if (originalPostId && activeTarget.value === originalPostId) {
        // Chỉ auto refresh nếu đang xem comment
        fetch('actions/get_post_comments.php?page_id=' + currentPageId + '&user_id=' + currentUserId + '&post_id=' + originalPostId)
            .then(r => r.json())
            .then(data => { if (data.status === 'success') renderComments(data.data); })
            .catch(()=>{});
    }
}, 15000);

</script>

<?php include 'includes/footer.php'; ?>
