<?php
$current_page = 'comment_posts';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];

// Silently ensure table exists
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
} catch (Exception $e) {}

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
    $filter_page_ids = []; // Default: Unchecked!
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
    LIMIT 200
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
    <div>
        <h2 style="margin:0; font-size:22px; font-weight:700; color:var(--text-main);">💬 Comment Post (Quản lý Bài viết & Seeding Bình luận)</h2>
        <p style="margin:4px 0 0 0; font-size:13px; color:#6b7280;">Quét danh sách bài viết từ Fanpage, lọc tương tác và tự động kích hoạt chiến dịch bình luận qua Cron.</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <div style="display:flex; align-items:center; gap:8px; background:var(--card-bg, #fff); padding:4px 10px; border-radius:8px; border:1px solid var(--border-color, #cbd5e1); box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <label style="font-size:12px; font-weight:600; color:#475569; white-space:nowrap;">Limit bài/page:</label>
            <input type="number" id="sync_limit" value="10" min="1" max="100" style="width:55px; padding:5px 6px; border-radius:6px; border:1px solid #cbd5e1; font-weight:700; font-size:13px; text-align:center;">
            
            <label style="display:flex; align-items:center; gap:4px; font-size:12px; font-weight:600; color:#475569; cursor:pointer; border-left:1px solid #cbd5e1; padding-left:8px; margin-left:4px;">
                <input type="checkbox" id="chk_only_has_text" checked style="width:15px; height:15px; cursor:pointer;">
                <span>Chỉ quét bài có nội dung</span>
            </label>

            <button onclick="syncPosts()" id="btn_sync" class="btn" style="background:#0284c7; color:#fff; font-weight:600; display:flex; align-items:center; gap:6px; padding:6px 14px; margin-left:4px;">
                <span>🔄</span> <span>Quét Bài Viết Fanpage</span>
            </button>
        </div>
        <button onclick="openCampaignModal()" id="btn_campaign" class="btn btn-primary" style="font-weight:600; display:flex; align-items:center; gap:6px; padding:9px 16px;" disabled>
            <span>🚀</span> <span>Tạo Chiến Dịch Bình Luận (<span id="sel_cnt">0</span>)</span>
        </button>
    </div>
</div>

<!-- Thẻ thông báo trên giao diện PHP -->
<div id="notice_banner" style="display:none; margin-bottom:20px; padding:14px 18px; border-radius:8px; font-size:14px; font-weight:500; box-shadow:0 2px 4px rgba(0,0,0,0.05);"></div>

<!-- Bộ lọc tìm kiếm -->
<div style="background:var(--card-bg, #fff); padding:16px; border-radius:10px; border:1px solid var(--border-color, #e5e7eb); margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
    <form method="GET" action="comment_posts.php" id="frm_filter" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        
        <!-- Multi-select Fanpage Dropdown -->
        <div style="position:relative; flex:1; min-width:240px;">
            <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#4b5563;">Fanpage được chọn</label>
            <button type="button" id="btn_page_dropdown" onclick="togglePageDropdown(event)" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #d1d5db; font-size:13px; background:#fff; text-align:left; display:flex; justify-content:space-between; align-items:center; cursor:pointer;">
                <span id="txt_page_selected_summary">-- Chọn Fanpage --</span>
                <span style="font-size:10px; color:#6b7280;">▼</span>
            </button>
            
            <!-- Menu xổ xuống chứa Checkbox danh sách Fanpage -->
            <div id="menu_page_dropdown" style="display:none; position:absolute; top:100%; left:0; width:100%; min-width:280px; max-height:300px; overflow-y:auto; background:#fff; border:1px solid #cbd5e1; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); z-index:999; padding:8px 0; margin-top:4px;">
                <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; font-weight:600; cursor:pointer; color:#0284c7; font-size:13px; border-bottom:1px solid #f1f5f9; background:#f8fafc;">
                    <input type="checkbox" id="chk_page_all" onchange="toggleAllPageCbs(this)" <?php echo in_array('ALL', $filter_page_ids, true) ? 'checked' : ''; ?> style="width:16px; height:16px; margin:0;">
                    <span>Tất cả Fanpage</span>
                </label>
                <div style="padding:4px 0;">
                    <?php if(!empty($pages)): foreach($pages as $p): 
                        $is_checked = !empty($filter_page_ids) && (in_array('ALL', $filter_page_ids, true) || in_array((string)$p['page_id'], $filter_page_ids, true));
                    ?>
                        <label style="display:flex; align-items:center; gap:8px; padding:6px 12px; cursor:pointer; font-size:13px; transition:background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" name="page_ids[]" class="filter_page_cb" value="<?php echo htmlspecialchars($p['page_id']); ?>" onchange="updatePageSelectText()" <?php echo $is_checked ? 'checked' : ''; ?> style="width:16px; height:16px; margin:0;">
                            <img src="<?php echo htmlspecialchars($p['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($p['name']).'&background=random'); ?>" style="width:20px; height:20px; border-radius:50%; object-fit:cover;">
                            <span style="color:#334155; font-weight:500;"><?php echo htmlspecialchars($p['name']); ?></span>
                        </label>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div style="flex:1; min-width:200px;">
            <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#4b5563;">Sắp xếp & Tương tác</label>
            <select name="sort" onchange="this.form.submit()" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #d1d5db; font-size:13px;">
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
            <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#4b5563;">Tìm bài viết</label>
            <input type="text" name="keyword" value="<?php echo htmlspecialchars($filter_keyword); ?>" placeholder="Nhập từ khóa nội dung..." style="width:100%; padding:7px 12px; border-radius:6px; border:1px solid #d1d5db; font-size:13px;">
        </div>

        <div>
            <button type="submit" class="btn btn-secondary" style="padding:8px 16px;">Lọc bài viết</button>
            <?php if(!in_array('ALL', $filter_page_ids, true) || $filter_sort !== 'newest' || $filter_keyword !== ''): ?>
                <a href="comment_posts.php" class="btn" style="background:#f3f4f6; color:#374151; padding:8px 12px; text-decoration:none;">Xóa lọc</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Danh sách bài viết -->
<div style="background:var(--card-bg, #fff); border-radius:10px; border:1px solid var(--border-color, #e5e7eb); overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
    <div style="padding:12px 16px; background:#f9fafb; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:13px; font-weight:600; color:#374151;">Danh sách bài viết (Hiển thị tối đa <?php echo count($posts); ?> bài)</span>
        <label style="font-size:13px; font-weight:600; color:#0284c7; cursor:pointer; display:flex; align-items:center; gap:6px;">
            <input type="checkbox" id="chk_select_all" onchange="toggleSelectAll(this)" style="width:16px; height:16px;"> Chọn tất cả bài viết trên trang
        </label>
    </div>

    <?php if(empty($posts)): ?>
        <div style="text-align:center; padding:50px 20px; color:#9ca3af;">
            <span style="font-size:40px;">📭</span>
            <p style="margin-top:10px; font-size:14px;">Chưa có bài viết nào được quét hoặc không tìm thấy bài khớp bộ lọc.</p>
            <button onclick="syncPosts()" class="btn" style="background:#0284c7; color:#fff; margin-top:10px;">Bấm vào đây để quét bài viết từ các Fanpage đã chọn</button>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">
                <thead>
                    <tr style="background:#f9fafb; border-bottom:1px solid #e5e7eb; color:#6b7280; font-weight:600;">
                        <th style="padding:12px; width:40px; text-align:center;"></th>
                        <th style="padding:12px; width:180px;">Fanpage</th>
                        <th style="padding:12px;">Nội dung Bài viết</th>
                        <th style="padding:12px; width:90px; text-align:center;">👍 Thích</th>
                        <th style="padding:12px; width:90px; text-align:center;">💬 Cmt</th>
                        <th style="padding:12px; width:140px;">📅 Ngày đăng</th>
                        <th style="padding:12px; width:120px; text-align:center;">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($posts as $p): ?>
                        <tr style="border-bottom:1px solid #f3f4f6; transition:background 0.15s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='transparent'">
                            <td style="padding:12px; text-align:center;">
                                <input type="checkbox" class="post_cb" value="<?php echo htmlspecialchars($p['fb_post_id']); ?>" onchange="updateSelectedCount()" style="width:16px; height:16px; cursor:pointer;">
                            </td>
                            <td style="padding:12px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <img src="<?php echo htmlspecialchars($p['page_avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($p['page_name'] ?: 'P')); ?>" style="width:28px; height:28px; border-radius:50%; object-fit:cover; border:1px solid #e5e7eb;">
                                    <span style="font-weight:600; color:#1f2937; line-height:1.2; max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($p['page_name']); ?>">
                                        <?php echo htmlspecialchars($p['page_name'] ?: $p['page_id']); ?>
                                    </span>
                                </div>
                            </td>
                            <td style="padding:12px;">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <?php if(!empty($p['picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($p['picture']); ?>" style="width:50px; height:50px; border-radius:6px; object-fit:cover; border:1px solid #e5e7eb; flex-shrink:0;">
                                    <?php endif; ?>
                                    <div style="flex:1;">
                                        <div style="color:#374151; font-size:13px; line-height:1.4; max-height:42px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">
                                            <?php echo htmlspecialchars($p['message'] ?: '[Không có nội dung văn bản]'); ?>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($p['permalink_url'] ?: "https://facebook.com/{$p['fb_post_id']}"); ?>" target="_blank" style="font-size:11px; color:#0284c7; text-decoration:none; display:inline-flex; align-items:center; gap:3px; margin-top:3px;">
                                            <span>Xem trên Facebook</span> <span>↗</span>
                                        </a>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:12px; text-align:center; font-weight:600; color:#1d4ed8;">
                                <?php echo number_format($p['likes_count']); ?>
                            </td>
                            <td style="padding:12px; text-align:center; font-weight:600; color:#059669;">
                                <?php echo number_format($p['comments_count']); ?>
                            </td>
                            <td style="padding:12px; font-size:12px; color:#6b7280; white-space:nowrap;">
                                <?php echo date('d/m/Y H:i', strtotime($p['post_created_at'])); ?>
                            </td>
                            <td style="padding:12px; text-align:center;">
                                <?php if($p['has_scheduled_cmt'] > 0): ?>
                                    <span style="font-size:11px; padding:3px 8px; background:#dcfce7; color:#15803d; border-radius:12px; font-weight:600;">Đã có lịch Cmt</span>
                                <?php else: ?>
                                    <span style="font-size:11px; padding:3px 8px; background:#f3f4f6; color:#6b7280; border-radius:12px;">Chưa cmt</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Tạo Chiến Dịch Bình Luận -->
<div id="campaignModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:25px; border-radius:10px; width:100%; max-width:650px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; border-bottom:1px solid #e5e7eb; padding-bottom:10px; color:#1f2937; display:flex; justify-content:space-between; align-items:center;">
            <span>🚀 Tạo Chiến Dịch Bình Luận Seeding</span>
            <span style="font-size:13px; background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:12px; font-weight:600;" id="modal_sel_badge">0 bài viết</span>
        </h3>
        
        <form id="frm_campaign" onsubmit="submitCampaign(event)">
            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:bold; font-size:13px; margin-bottom:6px; color:#374151;">Nội dung bình luận mẫu</label>
                <textarea id="txt_comment_lines" rows="6" placeholder="Nhập mẫu bình luận (mỗi dòng một câu)...
Ví dụ:
Chào shop, sản phẩm này còn hàng không ạ?
Em muốn tư vấn mẫu này với ạ!
{Chào|Xin chào} shop, check inbox giúp mình nhé!" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; resize:vertical;" required></textarea>
                <div style="font-size:11px; color:#6b7280; margin-top:4px;">Nhập <b>mỗi dòng một câu</b> để hệ thống chọn ngẫu nhiên. Hỗ trợ cú pháp tráo câu Spintax: <code>{Nội dung 1|Nội dung 2}</code>.</div>
            </div>

            <div style="display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap;">
                <div style="flex:1; min-width:200px;">
                    <label style="display:block; font-weight:bold; font-size:13px; margin-bottom:6px; color:#374151;">Giãn cách giữa các bài (phút)</label>
                    <input type="number" id="num_delay_minutes" value="5" min="0" max="1440" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
                    <div style="font-size:11px; color:#6b7280; margin-top:4px;">Giúp các bài viết không bị cmt dồn dập cùng lúc.</div>
                </div>

                <div style="flex:1; min-width:200px;">
                    <label style="display:block; font-weight:bold; font-size:13px; margin-bottom:6px; color:#374151;">Thời gian bắt đầu</label>
                    <input type="datetime-local" id="dt_start_time" style="width:100%; padding:7px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
                    <div style="font-size:11px; color:#6b7280; margin-top:4px;">Để trống nếu muốn hệ thống kích hoạt ngay.</div>
                </div>
            </div>

            <div style="text-align:right; border-top:1px solid #e5e7eb; padding-top:15px;">
                <button type="button" onclick="document.getElementById('campaignModal').style.display='none';" class="btn" style="background:#f3f4f6; color:#374151; margin-right:10px;">Hủy</button>
                <button type="submit" class="btn btn-primary" id="btn_submit_campaign" style="font-weight:600;">Lưu & Kích Hoạt Seeding</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Loading Quét Bài Viết -->
<div id="syncLoadingModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:30px 40px; border-radius:16px; text-align:center; max-width:440px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid #e2e8f0;">
        <div style="display:inline-block; width:48px; height:48px; border:4px solid #e2e8f0; border-top-color:#0284c7; border-radius:50%; animation:spin_loader 0.8s linear infinite; margin-bottom:16px;"></div>
        <h4 style="margin:0 0 8px 0; font-size:18px; font-weight:700; color:#0f172a;">Đang quét bài viết từ Fanpage...</h4>
        <p style="margin:0; font-size:13px; color:#64748b; line-height:1.5;">Hệ thống đang kết nối với Facebook Graph API để tải bài viết mới nhất. Vui lòng giữ màn hình và chờ trong giây lát...</p>
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
    menu.style.display = (menu.style.display === 'none' || !menu.style.display) ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    const btn = document.getElementById('btn_page_dropdown');
    const menu = document.getElementById('menu_page_dropdown');
    if (menu && btn && !btn.contains(e.target) && !menu.contains(e.target)) {
        menu.style.display = 'none';
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

function syncPosts() {
    const checkedPageCbs = Array.from(document.querySelectorAll('.filter_page_cb:checked')).map(el => el.value);
    const allChk = document.getElementById('chk_page_all');
    
    let targetPages = checkedPageCbs;
    if (allChk.checked || checkedPageCbs.length === 0) {
        targetPages = ['ALL'];
    }

    const limitEl = document.getElementById('sync_limit');
    const limit   = limitEl ? limitEl.value : 10;

    const onlyTextEl = document.getElementById('chk_only_has_text');
    const onlyHasText = (onlyTextEl && onlyTextEl.checked) ? 1 : 0;

    const btn = document.getElementById('btn_sync');
    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> <span>Đang quét bài viết...</span>';

    const loadingModal = document.getElementById('syncLoadingModal');
    if (loadingModal) loadingModal.style.display = 'flex';

    const fd = new FormData();
    fd.append('page_ids', JSON.stringify(targetPages));
    fd.append('limit', limit);
    fd.append('only_has_text', onlyHasText);

    fetch('actions/sync_fanpage_posts.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<span>🔄</span> <span>Quét Bài Viết Fanpage</span>';
        if (loadingModal) loadingModal.style.display = 'none';

        if (res.status === 'success') {
            showNotice(res.msg, 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotice('Lỗi: ' + res.msg, 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<span>🔄</span> <span>Quét Bài Viết Fanpage</span>';
        if (loadingModal) loadingModal.style.display = 'none';
        showNotice('Lỗi kết nối máy chủ: ' + err, 'error');
    });
}

function toggleSelectAll(el) {
    const cbs = document.querySelectorAll('.post_cb');
    cbs.forEach(cb => cb.checked = el.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkedVals = Array.from(document.querySelectorAll('.post_cb:checked')).map(el => el.value);
    const count = checkedVals.length;
    document.getElementById('sel_cnt').innerText = count;
    document.getElementById('modal_sel_badge').innerText = count + ' bài viết';
    document.getElementById('btn_campaign').disabled = (count === 0);
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

    fetch('actions/create_comment_campaign.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
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
