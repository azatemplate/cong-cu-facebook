<?php
$current_page = 'comment_posts';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];

// Silently ensure table exists (run once)
$cp_flag = sys_get_temp_dir() . '/fetched_posts_tbl_v2.done';
if (!file_exists($cp_flag)) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fetched_fanpage_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            page_id VARCHAR(100) NOT NULL,
            fb_post_id VARCHAR(100) UNIQUE NOT NULL,
            message TEXT NULL,
            picture TEXT NULL,
            permalink_url TEXT NULL,
            likes_count INT DEFAULT 0,
            comments_count INT DEFAULT 0,
            views_count INT DEFAULT 0,
            post_created_at DATETIME NOT NULL,
            synced_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_acc_page (account_id, page_id),
            INDEX idx_stats (likes_count, comments_count, post_created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        @touch($cp_flag);
    } catch (Exception $e) {}
}

// Fetch pages for filter dropdown
$stmt_p = $pdo->prepare("
    (SELECT p.page_id, p.name, p.avatar FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = :aid)
    UNION
    (SELECT p.page_id, p.name, p.avatar FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id WHERE ps.shared_with_account_id = :aid2)
    ORDER BY name ASC
");
$stmt_p->execute([':aid' => $account_id, ':aid2' => $account_id]);
$pages = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

// Filters
$raw_selected_pages = $_GET['page_ids'] ?? ($_GET['page_id'] ?? null);
if (is_array($raw_selected_pages)) {
    $filter_page_ids = array_values(array_filter(array_map('strval', $raw_selected_pages)));
} elseif (is_string($raw_selected_pages) && strpos($raw_selected_pages, '[') !== false) {
    $decoded = @json_decode($raw_selected_pages, true);
    $filter_page_ids = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [$raw_selected_pages];
} elseif ($raw_selected_pages === 'ALL') {
    $filter_page_ids = ['ALL'];
} elseif (!empty($raw_selected_pages)) {
    $filter_page_ids = [(string)$raw_selected_pages];
} else {
    $filter_page_ids = [];
}

$filter_sort    = $_GET['sort'] ?? 'newest';
$filter_keyword = trim($_GET['keyword'] ?? '');

// Build Query
$where_clauses = ["f.account_id = :aid"];
$params = [':aid' => $account_id];

if (!in_array('ALL', $filter_page_ids, true) && !empty($filter_page_ids)) {
    $in_sql = implode(',', array_fill(0, count($filter_page_ids), '?'));
    $where_clauses[] = "f.page_id IN ($in_sql)";
    foreach ($filter_page_ids as $pid) {
        $params[] = $pid;
    }
}

if ($filter_keyword !== '') {
    $where_clauses[] = "f.message LIKE ?";
    $params[] = "%{$filter_keyword}%";
}

$where_sql = implode(' AND ', $where_clauses);

// Sorting logic
$order_sql = "f.post_created_at DESC";
switch ($filter_sort) {
    case 'likes_desc':
        $order_sql = "f.likes_count DESC, f.post_created_at DESC";
        break;
    case 'likes_asc':
        $order_sql = "f.likes_count ASC, f.post_created_at DESC";
        break;
    case 'comments_desc':
        $order_sql = "f.comments_count DESC, f.post_created_at DESC";
        break;
    case 'comments_asc':
        $order_sql = "f.comments_count ASC, f.post_created_at DESC";
        break;
    case 'no_comments':
        $where_sql .= " AND f.comments_count = 0";
        $order_sql = "f.post_created_at DESC";
        break;
    case 'oldest':
        $order_sql = "f.post_created_at ASC";
        break;
    default:
        $order_sql = "f.post_created_at DESC";
        break;
}

$sql = "
    SELECT f.*, p.name AS page_name, p.avatar AS page_avatar,
           (SELECT COUNT(*) FROM scheduled_posts sp WHERE sp.fb_post_id = f.fb_post_id AND sp.account_id = f.account_id AND sp.comment_lines IS NOT NULL) AS has_scheduled_cmt
    FROM fetched_fanpage_posts f
    LEFT JOIN pages p ON f.page_id = p.page_id
    WHERE {$where_sql}
    ORDER BY {$order_sql}
    LIMIT 1000
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notice_msg  = $_SESSION['notice_msg'] ?? $_GET['msg'] ?? '';
$notice_type = $_SESSION['notice_type'] ?? $_GET['msg_type'] ?? 'success';
unset($_SESSION['notice_msg'], $_SESSION['notice_type']);
?>

<style>
    /* evondev UI/UX Design System - Light & Dark Theme Dual-Compatible */
    .container, button, input, select, textarea {
        font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    }

    .cp-hero {
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
        border-radius: 16px;
        padding: 24px 28px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 20px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 25px -5px rgba(49, 46, 129, 0.4);
    }

    .cp-hero-text h2 {
        font-size: 22px;
        font-weight: 700;
        color: #ffffff !important;
        margin: 0 0 4px;
        letter-spacing: -0.02em;
    }

    .cp-hero-text p {
        font-size: 13px;
        color: #c7d2fe !important;
        margin: 0;
    }

    .cp-action-group {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    .cp-compact-box {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        padding: 6px 12px;
        border-radius: 10px;
        border: 1px solid #cbd5e1;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .cp-compact-box label {
        font-size: 12px;
        font-weight: 600;
        color: #475569 !important;
    }

    .cp-compact-box input[type="number"] {
        width: 55px;
        padding: 4px 6px;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        color: #0f172a !important;
        font-weight: 700;
        font-size: 12px;
        text-align: center;
    }

    .btn-evon-primary {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: #ffffff !important;
        font-weight: 600;
        border: none;
        border-radius: 10px;
        padding: 9px 18px;
        font-size: 13px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: transform .15s ease, box-shadow .15s ease, opacity .15s;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);
    }

    .btn-evon-primary:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
    }

    .btn-evon-primary:disabled {
        opacity: 0.55;
        cursor: not-allowed;
    }

    .btn-evon-secondary {
        background: #e0f2fe;
        color: #0369a1 !important;
        border: 1px solid #bae6fd;
        font-weight: 600;
        border-radius: 10px;
        padding: 8px 14px;
        font-size: 13px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }

    .btn-evon-secondary:hover:not(:disabled) {
        background: #bae6fd;
    }

    .filter-card {
        background: var(--card-bg, #ffffff);
        padding: 20px;
        border-radius: 14px;
        border: 1px solid var(--border-color, #e2e8f0);
        margin-bottom: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .table-container-card {
        background: var(--card-bg, #ffffff);
        border-radius: 14px;
        border: 1px solid var(--border-color, #e2e8f0);
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .evon-input, .evon-select {
        width: 100%;
        padding: 9px 12px;
        border-radius: 10px;
        border: 1px solid var(--border-color, #cbd5e1);
        font-size: 13px;
        background: var(--card-bg, #ffffff);
        color: var(--text-main, #1e293b) !important;
        outline: none;
        box-sizing: border-box;
    }

    .evon-input:focus, .evon-select:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
    }

    body.dark-mode .cp-compact-box {
        background: #1e293b;
        border-color: #334155;
    }
    body.dark-mode .cp-compact-box label {
        color: #94a3b8 !important;
    }
    body.dark-mode .cp-compact-box input[type="number"] {
        background: #0f172a;
        color: #f8fafc !important;
        border-color: #334155;
    }
</style>

<div class="cp-hero">
    <div class="cp-hero-text">
        <h2>💬 Comment Post (Quản lý Bài viết & Seeding)</h2>
        <p>Quét bài viết từ Fanpage, lọc tương tác và tự động khởi tạo chiến dịch seeding bình luận.</p>
    </div>
    
    <div class="cp-action-group">
        <!-- Compact Scan Box -->
        <div class="cp-compact-box">
            <label for="sync_limit">Limit/page:</label>
            <input type="number" id="sync_limit" value="10" min="1" max="1000">
            
            <label style="display:flex; align-items:center; gap:4px; cursor:pointer; padding-left:6px; border-left:1px solid #e2e8f0; color:#334155 !important;">
                <input type="checkbox" id="chk_only_has_text" checked style="width:14px; height:14px; cursor:pointer;">
                <span style="color:#334155 !important; font-weight:600;">Chỉ bài có chữ</span>
            </label>

            <button onclick="syncPosts()" id="btn_sync" class="btn-evon-secondary">
                <span>🔄</span> <span>Quét Bài Viết</span>
            </button>
        </div>

        <!-- Primary Action: Campaign Button -->
        <button onclick="openCampaignModal()" id="btn_campaign" class="btn-evon-primary" disabled>
            <span>🚀</span> <span>Tạo Chiến Dịch (<span id="sel_cnt">0</span>)</span>
        </button>

        <!-- Dropdown Menu: Manage / Delete Actions -->
        <div style="position:relative; display:inline-block;">
            <button type="button" id="btn_action_menu" onclick="toggleActionMenu(event)" style="background:#ffffff; color:#1e293b !important; border:1px solid #cbd5e1; font-weight:600; display:flex; align-items:center; gap:6px; padding:9px 14px; font-size:13px; border-radius:10px; cursor:pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <span style="color:#1e293b !important;">⚙️ Quản lý bài</span> <span style="font-size:10px; color:#64748b;">▼</span>
            </button>

            <div id="action_dropdown_menu" style="display:none; position:absolute; right:0; top:100%; min-width:280px; background:var(--card-bg, #ffffff); border:1px solid var(--border-color, #cbd5e1); border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.15); z-index:999; padding:6px 0; margin-top:6px;">
                <button type="button" id="btn_delete_selected" onclick="deleteSelectedPosts()" disabled style="width:100%; text-align:left; background:transparent; border:none; padding:10px 16px; font-size:13px; font-weight:600; color:#dc2626; display:flex; align-items:center; gap:8px; cursor:pointer; transition:background 0.15s;" onmouseover="if(!this.disabled) this.style.background='var(--bg-color, #fef2f2)'" onmouseout="this.style.background='transparent'">
                    <span>🗑️</span> <span>Xóa bài đã chọn khỏi Fanpage (<span id="del_sel_cnt">0</span>)</span>
                </button>
                <div style="border-top:1px solid var(--border-color, #f1f5f9); margin:4px 0;"></div>
                <button type="button" onclick="scanAndDeleteEmptyPosts()" style="width:100%; text-align:left; background:transparent; border:none; padding:10px 16px; font-size:13px; font-weight:600; color:#ea580c; display:flex; align-items:center; gap:8px; cursor:pointer; transition:background 0.15s;" onmouseover="this.style.background='var(--bg-color, #fff7ed)'" onmouseout="this.style.background='transparent'">
                    <span>🧹</span> <span>Quét & Xóa bài KHÔNG chữ khỏi Fanpage</span>
                </button>
                <div style="border-top:1px solid var(--border-color, #f1f5f9); margin:4px 0;"></div>
                <button type="button" onclick="clearFetchedPosts()" style="width:100%; text-align:left; background:transparent; border:none; padding:10px 16px; font-size:13px; font-weight:500; color:var(--text-muted, #64748b); display:flex; align-items:center; gap:8px; cursor:pointer; transition:background 0.15s;" onmouseover="this.style.background='var(--bg-color, #f8fafc)'" onmouseout="this.style.background='transparent'">
                    <span>🗑️</span> <span>Xóa danh sách bài đã quét tạm</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Thẻ thông báo trên giao diện PHP -->
<div id="notice_banner" style="<?php echo !empty($notice_msg) ? 'display:block;' : 'display:none;'; ?> margin-bottom:20px; padding:14px 18px; border-radius:10px; font-size:14px; font-weight:500; box-shadow:0 2px 4px rgba(0,0,0,0.05); <?php echo ($notice_type === 'success') ? 'background:#dcfce7; color:#15803d; border:1px solid #86efac;' : 'background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;'; ?>">
    <?php echo htmlspecialchars($notice_msg); ?>
</div>

<!-- Bộ lọc tìm kiếm -->
<div class="filter-card">
    <form method="GET" action="comment_posts.php" id="frm_filter" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        
        <!-- Multi-select Fanpage Dropdown -->
        <div style="position:relative; flex:1; min-width:240px;">
            <label style="display:block; font-size:12px; font-weight:700; margin-bottom:6px; color:var(--text-muted, #64748b); text-transform:uppercase; letter-spacing:0.5px;">Fanpage được chọn</label>
            <button type="button" id="btn_page_dropdown" onclick="togglePageDropdown(event)" class="evon-select" style="text-align:left; display:flex; justify-content:space-between; align-items:center; cursor:pointer; color:var(--text-main, #1e293b);">
                <span id="txt_page_selected_summary" style="color:var(--text-main, #1e293b); font-weight:500;">-- Chọn Fanpage --</span>
                <span style="font-size:10px; color:var(--text-muted, #6b7280);">▼</span>
            </button>
            
            <!-- Menu xổ xuống chứa Checkbox danh sách Fanpage -->
            <div id="menu_page_dropdown" style="display:none; position:absolute; top:100%; left:0; width:100%; min-width:280px; max-height:320px; overflow-y:auto; background:var(--card-bg, #ffffff); border:1px solid var(--border-color, #cbd5e1); border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.15); z-index:999; padding:0; margin-top:6px;">
                <!-- Ô tìm kiếm Fanpage -->
                <div style="padding:10px 12px; border-bottom:1px solid var(--border-color, #f1f5f9); background:var(--card-bg, #ffffff); position:sticky; top:0; z-index:10;">
                    <input type="text" id="search_fanpage_input" onkeyup="filterFanpageList()" placeholder="🔍 Tìm kiếm tên Fanpage..." class="evon-input" style="padding:6px 10px; font-size:12px;">
                </div>

                <label style="display:flex; align-items:center; gap:8px; padding:10px 14px; font-weight:600; cursor:pointer; color:#0284c7; font-size:13px; border-bottom:1px solid var(--border-color, #f1f5f9); background:var(--bg-color, #f8fafc);">
                    <input type="checkbox" id="chk_page_all" onchange="toggleAllPageCbs(this)" <?php echo in_array('ALL', $filter_page_ids, true) ? 'checked' : ''; ?> style="width:16px; height:16px; margin:0;">
                    <span>Tất cả Fanpage</span>
                </label>
                <div id="fanpage_list_container" style="padding:4px 0;">
                    <?php if(!empty($pages)): foreach($pages as $p): 
                        $is_checked = !empty($filter_page_ids) && (in_array('ALL', $filter_page_ids, true) || in_array((string)$p['page_id'], $filter_page_ids, true));
                    ?>
                        <label class="fanpage-item" style="display:flex; align-items:center; gap:8px; padding:8px 14px; cursor:pointer; font-size:13px; transition:background 0.15s; color:var(--text-main, #1e293b);" onmouseover="this.style.background='var(--bg-color, #f1f5f9)'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" name="page_ids[]" class="filter_page_cb" value="<?php echo htmlspecialchars($p['page_id']); ?>" onchange="updatePageSelectText()" <?php echo $is_checked ? 'checked' : ''; ?> style="width:16px; height:16px; margin:0;">
                            <img src="<?php echo htmlspecialchars($p['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($p['name']).'&background=random'); ?>" style="width:22px; height:22px; border-radius:50%; object-fit:cover;">
                            <span class="fanpage-name" style="color:var(--text-main, #1e293b) !important; font-weight:500;"><?php echo htmlspecialchars($p['name']); ?></span>
                        </label>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div style="flex:1; min-width:200px;">
            <label style="display:block; font-size:12px; font-weight:700; margin-bottom:6px; color:var(--text-muted, #64748b); text-transform:uppercase; letter-spacing:0.5px;">Sắp xếp & Tương tác</label>
            <select name="sort" onchange="this.form.submit()" class="evon-select">
                <option value="newest" <?php echo ($filter_sort === 'newest') ? 'selected' : ''; ?>>📅 Mới nhất xếp trước</option>
                <option value="oldest" <?php echo ($filter_sort === 'oldest') ? 'selected' : ''; ?>>📅 Cũ nhất xếp trước</option>
                <option value="likes_desc" <?php echo ($filter_sort === 'likes_desc') ? 'selected' : ''; ?>>👍 Lượt Thích cao nhất</option>
                <option value="likes_asc" <?php echo ($filter_sort === 'likes_asc') ? 'selected' : ''; ?>>👍 Lượt Thích thấp nhất</option>
                <option value="comments_desc" <?php echo ($filter_sort === 'comments_desc') ? 'selected' : ''; ?>>💬 Bình luận nhiều nhất</option>
                <option value="comments_asc" <?php echo ($filter_sort === 'comments_asc') ? 'selected' : ''; ?>>💬 Bình luận ít nhất</option>
                <option value="no_comments" <?php echo ($filter_sort === 'no_comments') ? 'selected' : ''; ?>>🚫 Chưa có bình luận nào</option>
            </select>
        </div>

        <div style="flex:1.5; min-width:200px;">
            <label style="display:block; font-size:12px; font-weight:700; margin-bottom:6px; color:var(--text-muted, #64748b); text-transform:uppercase; letter-spacing:0.5px;">Tìm bài viết</label>
            <input type="text" name="keyword" value="<?php echo htmlspecialchars($filter_keyword); ?>" placeholder="Nhập từ khóa nội dung..." class="evon-input">
        </div>

        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn-evon-primary">Lọc bài viết</button>
            <?php if(!in_array('ALL', $filter_page_ids, true) || $filter_sort !== 'newest' || $filter_keyword !== ''): ?>
                <a href="comment_posts.php" class="btn-evon-secondary" style="text-decoration:none;">Xóa lọc</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Danh sách bài viết -->
<div class="table-container-card">
    <div style="padding:14px 20px; background:var(--bg-color, #f8fafc); border-bottom:1px solid var(--border-color, #e5e7eb); display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:13px; font-weight:700; color:var(--text-main, #374151);">Danh sách bài viết (Hiển thị tối đa <?php echo count($posts); ?> bài)</span>
        <label style="font-size:13px; font-weight:600; color:#4f46e5; cursor:pointer; display:flex; align-items:center; gap:8px;">
            <input type="checkbox" id="chk_select_all" onchange="toggleSelectAll(this)" style="width:16px; height:16px;"> Chọn tất cả bài viết trên trang
        </label>
    </div>

    <?php if(empty($posts)): ?>
        <div style="text-align:center; padding:60px 20px; color:var(--text-muted, #64748b);">
            <span style="font-size:48px; display:block; margin-bottom:12px;">📭</span>
            <p style="margin:0 0 16px 0; font-size:14px; color:var(--text-muted, #64748b);">Chưa có bài viết nào được quét hoặc không tìm thấy bài khớp bộ lọc.</p>
            <button onclick="syncPosts()" class="btn-evon-primary" style="margin:0 auto;">Bấm vào đây để quét bài viết từ các Fanpage đã chọn</button>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">
                <thead>
                    <tr style="background:var(--bg-color, #f9fafb); border-bottom:1px solid var(--border-color, #e5e7eb); color:var(--text-muted, #6b7280); font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:0.5px;">
                        <th style="padding:12px 14px; width:40px; text-align:center;"></th>
                        <th style="padding:12px 14px; width:180px;">Fanpage</th>
                        <th style="padding:12px 14px;">Nội dung Bài viết</th>
                        <th style="padding:12px 14px; width:90px; text-align:center;">👍 Thích</th>
                        <th style="padding:12px 14px; width:90px; text-align:center;">💬 Cmt</th>
                        <th style="padding:12px 14px; width:140px;">📅 Ngày đăng</th>
                        <th style="padding:12px 14px; width:120px; text-align:center;">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($posts as $p): ?>
                        <tr style="border-bottom:1px solid var(--border-color, #f3f4f6); transition:background 0.15s;" onmouseover="this.style.background='rgba(99,102,241,0.04)'" onmouseout="this.style.background='transparent'">
                            <td style="padding:12px 14px; text-align:center;">
                                <input type="checkbox" class="post_cb" value="<?php echo htmlspecialchars($p['fb_post_id']); ?>" onchange="updateSelectedCount()" style="width:16px; height:16px; cursor:pointer;">
                            </td>
                            <td style="padding:12px 14px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <img src="<?php echo htmlspecialchars($p['page_avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($p['page_name'] ?: 'P')); ?>" style="width:30px; height:30px; border-radius:50%; object-fit:cover; border:1px solid #e5e7eb;">
                                    <span style="font-weight:600; color:var(--text-main, #1f2937); line-height:1.2; max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($p['page_name']); ?>">
                                        <?php echo htmlspecialchars($p['page_name'] ?: $p['page_id']); ?>
                                    </span>
                                </div>
                            </td>
                            <td style="padding:12px 14px;">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <?php if(!empty($p['picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($p['picture']); ?>" style="width:52px; height:52px; border-radius:8px; object-fit:cover; border:1px solid #e5e7eb; flex-shrink:0;">
                                    <?php endif; ?>
                                    <div style="flex:1;">
                                        <div style="color:var(--text-main, #374151); font-size:13px; line-height:1.4; max-height:42px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">
                                            <?php echo htmlspecialchars($p['message'] ?: '[Không có nội dung văn bản]'); ?>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($p['permalink_url'] ?: "https://facebook.com/{$p['fb_post_id']}"); ?>" target="_blank" style="font-size:11px; color:#0284c7; text-decoration:none; display:inline-flex; align-items:center; gap:3px; margin-top:4px; font-weight:600;">
                                            <span>Xem trên Facebook</span> <span>↗</span>
                                        </a>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:12px 14px; text-align:center; font-weight:600; color:#1d4ed8;">
                                <?php echo number_format($p['likes_count']); ?>
                            </td>
                            <td style="padding:12px 14px; text-align:center; font-weight:600; color:#059669;">
                                <?php echo number_format($p['comments_count']); ?>
                            </td>
                            <td style="padding:12px 14px; font-size:12px; color:var(--text-muted, #6b7280); white-space:nowrap;">
                                <?php echo date('d/m/Y H:i', strtotime($p['post_created_at'])); ?>
                            </td>
                            <td style="padding:12px 14px; text-align:center;">
                                <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                                    <?php if($p['has_scheduled_cmt'] > 0): ?>
                                        <span style="font-size:11px; padding:4px 10px; background:#dcfce7; color:#15803d; border:1px solid #86efac; border-radius:12px; font-weight:600;">Đã có lịch Cmt</span>
                                    <?php else: ?>
                                        <span style="font-size:11px; padding:4px 10px; background:#f3f4f6; color:#6b7280; border-radius:12px;">Chưa cmt</span>
                                    <?php endif; ?>
                                    <button type="button" onclick="deleteSinglePost('<?php echo htmlspecialchars($p['fb_post_id']); ?>')" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-size:11px; font-weight:600; padding:3px 8px; border-radius:6px; cursor:pointer;" title="Xóa bài viết này trực tiếp khỏi Fanpage">🗑️ Xóa bài</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Tạo Chiến Dịch Bình Luận -->
<div id="campaignModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:var(--card-bg, #ffffff); padding:28px; border-radius:16px; width:100%; max-width:650px; max-height:90vh; overflow-y:auto; border:1px solid var(--border-color, #e5e7eb); box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <h3 style="margin-top:0; border-bottom:1px solid var(--border-color, #e5e7eb); padding-bottom:14px; color:var(--text-main, #1f2937); font-size:18px; font-weight:700; display:flex; justify-content:space-between; align-items:center;">
            <span>🚀 Tạo Chiến Dịch Bình Luận Seeding</span>
            <span style="font-size:12px; background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; padding:4px 12px; border-radius:12px; font-weight:600;" id="modal_sel_badge">0 bài viết</span>
        </h3>
        
        <form id="frm_campaign" onsubmit="submitCampaign(event)">
            <div style="margin-bottom:18px;">
                <label style="display:block; font-weight:700; font-size:12px; margin-bottom:8px; color:var(--text-muted, #4b5563); text-transform:uppercase; letter-spacing:0.5px;">Nội dung bình luận mẫu</label>
                <textarea id="txt_comment_lines" rows="6" placeholder="Nhập mẫu bình luận (mỗi dòng một câu)...
Ví dụ:
Chào shop, sản phẩm này còn hàng không ạ?
Em muốn tư vấn mẫu này với ạ!
{Chào|Xin chào} shop, check inbox giúp mình nhé!" class="evon-textarea" required></textarea>
                <div style="font-size:11px; color:var(--text-muted, #6b7280); margin-top:6px;">Nhập <b>mỗi dòng một câu</b> để hệ thống chọn ngẫu nhiên. Hỗ trợ cú pháp tráo câu Spintax: <code>{Nội dung 1|Nội dung 2}</code>.</div>
            </div>

            <div style="display:flex; gap:16px; margin-bottom:24px; flex-wrap:wrap;">
                <div style="flex:1; min-width:200px;">
                    <label style="display:block; font-weight:700; font-size:12px; margin-bottom:8px; color:var(--text-muted, #4b5563); text-transform:uppercase; letter-spacing:0.5px;">Giãn cách giữa các bài (phút)</label>
                    <input type="number" id="num_delay_minutes" value="5" min="0" max="1440" class="evon-input">
                    <div style="font-size:11px; color:var(--text-muted, #6b7280); margin-top:6px;">Giúp các bài viết không bị cmt dồn dập cùng lúc.</div>
                </div>

                <div style="flex:1; min-width:200px;">
                    <label style="display:block; font-weight:700; font-size:12px; margin-bottom:8px; color:var(--text-muted, #4b5563); text-transform:uppercase; letter-spacing:0.5px;">Thời gian bắt đầu</label>
                    <input type="datetime-local" id="dt_start_time" class="evon-input">
                    <div style="font-size:11px; color:var(--text-muted, #6b7280); margin-top:6px;">Để trống nếu muốn hệ thống kích hoạt ngay.</div>
                </div>
            </div>

            <div style="text-align:right; border-top:1px solid var(--border-color, #e5e7eb); padding-top:18px; display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="document.getElementById('campaignModal').style.display='none';" class="btn-evon-secondary">Hủy</button>
                <button type="submit" class="btn-evon-primary" id="btn_submit_campaign">Lưu & Kích Hoạt Seeding</button>
            </div>
        </form>
    </div>
</div>

<!-- Custom Confirmation Modal -->
<div id="customConfirmModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:var(--card-bg, #ffffff); padding:28px; border-radius:16px; max-width:440px; width:90%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid var(--border-color, #e5e7eb); text-align:center;">
        <div style="font-size:42px; margin-bottom:12px;" id="confirm_modal_icon">⚠️</div>
        <h4 style="margin:0 0 10px 0; font-size:18px; font-weight:700; color:var(--text-main, #0f172a);" id="confirm_modal_title">Xác nhận thao tác</h4>
        <p style="margin:0 0 24px 0; font-size:13px; color:var(--text-muted, #475569); line-height:1.5;" id="confirm_modal_msg">Bạn có chắc chắn muốn thực hiện thao tác này không?</p>
        <div style="display:flex; justify-content:center; gap:12px;">
            <button type="button" onclick="closeCustomConfirm()" class="btn-evon-secondary">Hủy bỏ</button>
            <button type="button" onclick="executeCustomConfirm()" style="background:#dc2626; color:#fff !important; font-weight:600; padding:10px 22px; border-radius:10px; border:none; cursor:pointer; box-shadow:0 4px 14px rgba(220,38,38,0.35);">Xác nhận xóa</button>
        </div>
    </div>
</div>

<!-- Modal Loading Quét Bài Viết -->
<div id="syncLoadingModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:var(--card-bg, #ffffff); padding:32px 40px; border-radius:16px; text-align:center; max-width:440px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid var(--border-color, #e5e7eb);">
        <div style="display:inline-block; width:48px; height:48px; border:4px solid #cbd5e1; border-top-color:#6366f1; border-radius:50%; animation:spin_loader 0.8s linear infinite; margin-bottom:16px;"></div>
        <h4 id="sync_loading_title" style="margin:0 0 8px 0; font-size:18px; font-weight:700; color:var(--text-main, #0f172a);">Đang quét bài viết từ Fanpage...</h4>
        <p id="sync_loading_desc" style="margin:0; font-size:13px; color:var(--text-muted, #64748b); line-height:1.5;">Hệ thống đang kết nối với Facebook Graph API để tải bài viết mới nhất. Vui lòng giữ màn hình và chờ trong giây lát...</p>
    </div>
</div>

<style>
@keyframes spin_loader {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
function togglePageDropdown(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('menu_page_dropdown');
    if (menu) {
        menu.style.display = (menu.style.display === 'none' || !menu.style.display) ? 'block' : 'none';
        if (menu.style.display === 'block') {
            const searchInput = document.getElementById('search_fanpage_input');
            if (searchInput) searchInput.focus();
        }
    }
}

function toggleActionMenu(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('action_dropdown_menu');
    if (menu) {
        menu.style.display = (menu.style.display === 'none' || !menu.style.display) ? 'block' : 'none';
    }
}

function filterFanpageList() {
    const input = document.getElementById('search_fanpage_input');
    if (!input) return;
    const filter = input.value.toLowerCase().trim();
    const items = document.querySelectorAll('.fanpage-item');
    items.forEach(item => {
        const nameEl = item.querySelector('.fanpage-name');
        const text = nameEl ? nameEl.innerText.toLowerCase() : '';
        if (text.includes(filter)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

document.addEventListener('click', function(e) {
    const btnPage = document.getElementById('btn_page_dropdown');
    const menuPage = document.getElementById('menu_page_dropdown');
    if (menuPage && btnPage && !btnPage.contains(e.target) && !menuPage.contains(e.target)) {
        menuPage.style.display = 'none';
    }

    const btnAction = document.getElementById('btn_action_menu');
    const menuAction = document.getElementById('action_dropdown_menu');
    if (menuAction && btnAction && !btnAction.contains(e.target) && !menuAction.contains(e.target)) {
        menuAction.style.display = 'none';
    }
});

function toggleAllPageCbs(el) {
    const cbs = document.querySelectorAll('.filter_page_cb');
    cbs.forEach(cb => cb.checked = el.checked);
    updatePageSelectText();
}

function updatePageSelectText() {
    const cbs = document.querySelectorAll('.filter_page_cb');
    const checked = document.querySelectorAll('.filter_page_cb:checked');
    const allChk = document.getElementById('chk_page_all');
    const summary = document.getElementById('txt_page_selected_summary');

    if (!summary) return;

    if (cbs.length > 0 && checked.length === cbs.length) {
        if (allChk) allChk.checked = true;
        summary.innerText = `Tất cả Fanpage (${cbs.length} trang)`;
    } else if (checked.length === 0) {
        if (allChk) allChk.checked = false;
        summary.innerText = `-- Chọn Fanpage --`;
    } else if (checked.length === 1) {
        if (allChk) allChk.checked = false;
        const pageName = checked[0].closest('label').querySelector('span').innerText;
        summary.innerText = pageName;
    } else {
        if (allChk) allChk.checked = false;
        summary.innerText = `Đã chọn ${checked.length} Fanpage`;
    }
}
document.addEventListener('DOMContentLoaded', updatePageSelectText);

function showNotice(msg, type = 'success') {
    const banner = document.getElementById('notice_banner');
    if (!banner) return;
    banner.style.display = 'block';
    if (type === 'success') {
        banner.style.background = '#dcfce7';
        banner.style.color = '#15803d';
        banner.style.border = '1px solid #86efac';
    } else {
        banner.style.background = '#fee2e2';
        banner.style.color = '#b91c1c';
        banner.style.border = '1px solid #fca5a5';
    }
    banner.innerHTML = msg;
    banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function safeFetchJson(url, options) {
    return fetch(url, options)
    .then(r => r.text())
    .then(text => {
        try {
            return JSON.parse(text);
        } catch(e) {
            let cleanText = text.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
            if (cleanText.length > 250) cleanText = cleanText.substring(0, 250) + '...';
            return { status: 'error', msg: 'Phản hồi máy chủ: ' + (cleanText || 'Lỗi từ phía máy chủ PHP') };
        }
    });
}

function syncPosts() {
    const checkedPageCbs = Array.from(document.querySelectorAll('.filter_page_cb:checked')).map(el => el.value);
    const allChk = document.getElementById('chk_page_all');
    
    let targetPages = checkedPageCbs;
    if ((allChk && allChk.checked) || checkedPageCbs.length === 0) {
        targetPages = ['ALL'];
    }

    const limitEl = document.getElementById('sync_limit');
    const limit   = limitEl ? parseInt(limitEl.value) : 10;

    const onlyTextEl = document.getElementById('chk_only_has_text');
    const onlyHasText = (onlyTextEl && onlyTextEl.checked) ? 1 : 0;

    const btn = document.getElementById('btn_sync');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span>⏳</span> <span>Đang quét bài viết...</span>'; }

    const loadingModal = document.getElementById('syncLoadingModal');
    const loadingTitle = document.getElementById('sync_loading_title');
    const loadingDesc = document.getElementById('sync_loading_desc');
    if (loadingModal) {
        if (loadingTitle) loadingTitle.innerText = 'Đang quét bài viết từ Fanpage...';
        if (loadingDesc) loadingDesc.innerText = `Hệ thống đang khởi tạo kết nối Facebook API (Giới hạn ${limit} bài)...`;
        loadingModal.style.display = 'flex';
    }

    runSyncChunk(targetPages, limit, onlyHasText, 1, 0, '');
}

function runSyncChunk(targetPages, limit, onlyHasText, isFirst, accumulatedSynced, nextCursors) {
    const fd = new FormData();
    fd.append('page_ids', JSON.stringify(targetPages));
    fd.append('limit', limit);
    fd.append('only_has_text', onlyHasText);
    fd.append('is_first', isFirst);
    fd.append('accumulated_synced', accumulatedSynced);
    if (nextCursors) fd.append('next_cursors', nextCursors);

    safeFetchJson('actions/sync_fanpage_posts.php', { method: 'POST', body: fd })
    .then(res => {
        const btn = document.getElementById('btn_sync');
        const loadingModal = document.getElementById('syncLoadingModal');
        const loadingDesc = document.getElementById('sync_loading_desc');

        if (res.status === 'success') {
            if (loadingDesc) loadingDesc.innerText = res.msg;

            if (res.finished) {
                if (btn) { btn.disabled = false; btn.innerHTML = '<span>🔄</span> <span>Quét Bài Viết Fanpage</span>'; }
                if (loadingModal) loadingModal.style.display = 'none';
                showNotice(res.msg, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                runSyncChunk(targetPages, limit, onlyHasText, 0, res.total_synced, res.next_cursors);
            }
        } else {
            if (btn) { btn.disabled = false; btn.innerHTML = '<span>🔄</span> <span>Quét Bài Viết Fanpage</span>'; }
            if (loadingModal) loadingModal.style.display = 'none';
            showNotice('Lỗi: ' + res.msg, 'error');
        }
    })
    .catch(err => {
        const btn = document.getElementById('btn_sync');
        const loadingModal = document.getElementById('syncLoadingModal');
        if (btn) { btn.disabled = false; btn.innerHTML = '<span>🔄</span> <span>Quét Bài Viết Fanpage</span>'; }
        if (loadingModal) loadingModal.style.display = 'none';
        showNotice('Lỗi kết nối máy chủ: ' + err, 'error');
    });
}

let pendingConfirmCallback = null;

function openCustomConfirm(title, message, icon, callback) {
    const iconEl = document.getElementById('confirm_modal_icon'); if (iconEl) iconEl.innerText = icon || '⚠️';
    const titleEl = document.getElementById('confirm_modal_title'); if (titleEl) titleEl.innerText = title || 'Xác nhận thao tác';
    const msgEl = document.getElementById('confirm_modal_msg'); if (msgEl) msgEl.innerText = message || 'Bạn có chắc chắn muốn thực hiện thao tác này không?';
    pendingConfirmCallback = callback;
    const modal = document.getElementById('customConfirmModal');
    if (modal) modal.style.display = 'flex';
}

function closeCustomConfirm() {
    const modal = document.getElementById('customConfirmModal');
    if (modal) modal.style.display = 'none';
    pendingConfirmCallback = null;
}

function executeCustomConfirm() {
    const modal = document.getElementById('customConfirmModal');
    if (modal) modal.style.display = 'none';
    if (typeof pendingConfirmCallback === 'function') {
        const cb = pendingConfirmCallback;
        pendingConfirmCallback = null;
        cb();
    }
}

function clearFetchedPosts() {
    openCustomConfirm(
        'Xóa danh sách bài quét tạm',
        'Bạn có chắc chắn muốn xóa toàn bộ danh sách bài viết đã quét tạm thời khỏi cơ sở dữ liệu không?',
        '🗑️',
        function() {
            safeFetchJson('actions/clear_fetched_posts.php', { method: 'POST' })
            .then(res => {
                if (res.status === 'success') {
                    showNotice(res.msg, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotice('Lỗi: ' + res.msg, 'error');
                }
            })
            .catch(err => {
                showNotice('Lỗi kết nối máy chủ: ' + err, 'error');
            });
        }
    );
}

function toggleSelectAll(el) {
    const cbs = document.querySelectorAll('.post_cb');
    cbs.forEach(cb => cb.checked = el.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkedVals = Array.from(document.querySelectorAll('.post_cb:checked')).map(el => el.value);
    const count = checkedVals.length;
    const selCnt = document.getElementById('sel_cnt'); if (selCnt) selCnt.innerText = count;
    const delSelCnt = document.getElementById('del_sel_cnt'); if (delSelCnt) delSelCnt.innerText = count;
    const badge = document.getElementById('modal_sel_badge'); if (badge) badge.innerText = count + ' bài viết';
    
    const btnCampaign = document.getElementById('btn_campaign'); if (btnCampaign) btnCampaign.disabled = (count === 0);
    const btnDelSel = document.getElementById('btn_delete_selected'); if (btnDelSel) btnDelSel.disabled = (count === 0);
}

function deleteSelectedPosts() {
    const checkedVals = Array.from(document.querySelectorAll('.post_cb:checked')).map(el => el.value);
    if (checkedVals.length === 0) {
        showNotice('Vui lòng tích chọn ít nhất 1 bài viết để xóa!', 'error');
        return;
    }

    openCustomConfirm(
        'Xác nhận XÓA BÀI VIẾT khỏi Fanpage',
        `Bạn có chắc chắn muốn XÓA VĨNH VIỄN ${checkedVals.length} BÀI VIẾT ĐÃ CHỌN khỏi Fanpage qua Facebook API không? Hành động này không thể hoàn tác!`,
        '🗑️',
        function() {
            const btn = document.getElementById('btn_delete_selected');
            if (btn) { btn.disabled = true; btn.innerHTML = '<span>⏳</span> <span>Đang xóa bài...</span>'; }

            const loadingModal = document.getElementById('syncLoadingModal');
            const loadingTitle = document.getElementById('sync_loading_title');
            const loadingDesc = document.getElementById('sync_loading_desc');
            if (loadingModal) {
                if (loadingTitle) loadingTitle.innerText = 'Đang xóa bài viết khỏi Fanpage...';
                if (loadingDesc) loadingDesc.innerText = 'Hệ thống đang gửi yêu cầu xóa bài viết trực tiếp lên Facebook Graph API. Vui lòng chờ trong giây lát...';
                loadingModal.style.display = 'flex';
            }

            const fd = new FormData();
            fd.append('post_ids', JSON.stringify(checkedVals));

            safeFetchJson('actions/delete_fanpage_posts.php', { method: 'POST', body: fd })
            .then(res => {
                if (btn) { btn.disabled = false; btn.innerHTML = '<span>🗑️</span> <span>Xóa bài đã chọn khỏi Fanpage (<span id="del_sel_cnt">0</span>)</span>'; }
                if (loadingModal) loadingModal.style.display = 'none';

                if (res.status === 'success') {
                    showNotice(res.msg, 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showNotice('Lỗi: ' + res.msg, 'error');
                }
            })
            .catch(err => {
                if (btn) { btn.disabled = false; btn.innerHTML = '<span>🗑️</span> <span>Xóa bài đã chọn khỏi Fanpage (<span id="del_sel_cnt">0</span>)</span>'; }
                if (loadingModal) loadingModal.style.display = 'none';
                showNotice('Lỗi kết nối máy chủ: ' + err, 'error');
            });
        }
    );
}

function deleteSinglePost(postId) {
    openCustomConfirm(
        'Xác nhận Xóa Bài Viết khỏi Fanpage',
        'Bạn có chắc chắn muốn XÓA VĨNH VIỄN bài viết này khỏi Fanpage qua Facebook API không? Hành động này không thể hoàn tác!',
        '🗑️',
        function() {
            const loadingModal = document.getElementById('syncLoadingModal');
            const loadingTitle = document.getElementById('sync_loading_title');
            const loadingDesc = document.getElementById('sync_loading_desc');
            if (loadingModal) {
                if (loadingTitle) loadingTitle.innerText = 'Đang xóa bài viết khỏi Fanpage...';
                if (loadingDesc) loadingDesc.innerText = 'Hệ thống đang gửi yêu cầu xóa bài viết lên Facebook API...';
                loadingModal.style.display = 'flex';
            }

            const fd = new FormData();
            fd.append('post_ids', JSON.stringify([postId]));

            safeFetchJson('actions/delete_fanpage_posts.php', { method: 'POST', body: fd })
            .then(res => {
                if (loadingModal) loadingModal.style.display = 'none';
                if (res.status === 'success') {
                    showNotice(res.msg, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotice('Lỗi: ' + res.msg, 'error');
                }
            })
            .catch(err => {
                if (loadingModal) loadingModal.style.display = 'none';
                showNotice('Lỗi kết nối máy chủ: ' + err, 'error');
            });
        }
    );
}

function scanAndDeleteEmptyPosts() {
    const checkedPageCbs = Array.from(document.querySelectorAll('.filter_page_cb:checked')).map(el => el.value);
    const allChk = document.getElementById('chk_page_all');
    
    let targetPages = checkedPageCbs;
    if ((allChk && allChk.checked) || checkedPageCbs.length === 0) {
        targetPages = ['ALL'];
    }

    const limitEl = document.getElementById('sync_limit');
    const limit   = limitEl ? parseInt(limitEl.value) : 100;

    openCustomConfirm(
        'Xác nhận Quét & Xóa bài KHÔNG chữ',
        `Bạn có chắc chắn muốn QUÉT VÀ XÓA TẤT CẢ BÀI VIẾT KHÔNG CÓ NỘI DUNG VĂN BẢN (Giới hạn ${limit} bài/page)? Các bài không có chữ sẽ bị XÓA VĨNH VIỄN khỏi Fanpage qua Facebook API!`,
        '🧹',
        function() {
            const loadingModal = document.getElementById('syncLoadingModal');
            const loadingTitle = document.getElementById('sync_loading_title');
            const loadingDesc = document.getElementById('sync_loading_desc');
            if (loadingModal) {
                if (loadingTitle) loadingTitle.innerText = 'Đang quét & xóa bài không chữ...';
                if (loadingDesc) loadingDesc.innerText = `Hệ thống đang bắt đầu duyệt Fanpage (giới hạn ${limit} bài)...`;
                loadingModal.style.display = 'flex';
            }

            runScanDeleteChunk(targetPages, limit, 0, 0, '');
        }
    );
}

function runScanDeleteChunk(targetPages, limit, accumulatedScanned, accumulatedDeleted, nextCursors) {
    const fd = new FormData();
    fd.append('page_ids', JSON.stringify(targetPages));
    fd.append('limit', limit);
    fd.append('accumulated_scanned', accumulatedScanned);
    fd.append('accumulated_deleted', accumulatedDeleted);
    if (nextCursors) fd.append('next_cursors', nextCursors);

    safeFetchJson('actions/scan_and_delete_empty_posts.php', { method: 'POST', body: fd })
    .then(res => {
        const loadingModal = document.getElementById('syncLoadingModal');
        const loadingDesc = document.getElementById('sync_loading_desc');

        if (res.status === 'success') {
            if (loadingDesc) loadingDesc.innerText = res.msg;

            if (res.finished) {
                if (loadingModal) loadingModal.style.display = 'none';
                showNotice(res.msg, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                runScanDeleteChunk(targetPages, limit, res.total_scanned, res.total_deleted, res.next_cursors);
            }
        } else {
            if (loadingModal) loadingModal.style.display = 'none';
            showNotice('Lỗi: ' + res.msg, 'error');
        }
    })
    .catch(err => {
        const loadingModal = document.getElementById('syncLoadingModal');
        if (loadingModal) loadingModal.style.display = 'none';
        showNotice('Lỗi kết nối máy chủ: ' + err, 'error');
    });
}

function openCampaignModal() {
    const checkedVals = Array.from(document.querySelectorAll('.post_cb:checked')).map(el => el.value);
    if (checkedVals.length === 0) {
        showNotice('Vui lòng tích chọn ít nhất 1 bài viết!', 'error');
        return;
    }
    document.getElementById('campaignModal').style.display = 'flex';
}

function submitCampaign(e) {
    e.preventDefault();
    const checkedVals = Array.from(document.querySelectorAll('.post_cb:checked')).map(el => el.value);
    const commentLines = document.getElementById('txt_comment_lines').value.trim();
    const delayMinutes = document.getElementById('num_delay_minutes').value;
    const startTime = document.getElementById('dt_start_time').value;
    const btn = document.getElementById('btn_submit_campaign');

    if (checkedVals.length === 0) {
        showNotice('Vui lòng chọn bài viết!', 'error');
        return;
    }

    if (!commentLines) {
        showNotice('Vui lòng nhập nội dung bình luận mẫu!', 'error');
        return;
    }

    btn.disabled = true;
    btn.innerText = 'Đang khởi tạo...';

    const fd = new FormData();
    fd.append('post_ids', JSON.stringify(checkedVals));
    fd.append('comment_lines', commentLines);
    fd.append('delay_minutes', delayMinutes);
    fd.append('start_time', startTime);

    safeFetchJson('actions/create_comment_campaign.php', {
        method: 'POST',
        body: fd
    })
    .then(res => {
        btn.disabled = false;
        btn.innerText = 'Lưu & Kích Hoạt Seeding';
        if (res.status === 'success') {
            document.getElementById('campaignModal').style.display = 'none';
            window.location.href = res.redirect || 'manage_posts.php';
        } else {
            showNotice('Lỗi: ' + res.msg, 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerText = 'Lưu & Kích Hoạt Seeding';
        showNotice('Lỗi kết nối máy chủ: ' + err, 'error');
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
