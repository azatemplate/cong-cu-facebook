<?php
// ── Actions MUST be before ANY output (before header.php) ─────────────────
require_once __DIR__ . '/includes/db.php';

// Need session to check is_admin
if (session_status() === PHP_SESSION_NONE) @session_start();
$_s_account_id = $_SESSION['account_id'] ?? 0;
$_s_is_admin   = ($_SESSION['role'] ?? '') === 'admin';

if (isset($_GET['action'], $_GET['id'])) {
    $act  = $_GET['action'];
    $id   = intval($_GET['id']);
    $auth = $_s_is_admin ? '' : ' AND account_id = ?';
    $p    = [$id];
    if (!$_s_is_admin) $p[] = $_s_account_id;
    try {
        if ($act === 'delete') {
            $pdo->prepare("DELETE FROM scheduled_posts WHERE id = ? AND status IN ('pending','failed') $auth")->execute($p);
        } elseif ($act === 'retry') {
            $pdo->prepare("UPDATE scheduled_posts SET status='pending', error_msg=NULL WHERE id = ? AND status='failed' $auth")->execute($p);
        }
    } catch (PDOException $e) {}
    header('Location: manage_legacy.php' . (isset($_GET['filter']) ? '?filter='.urlencode($_GET['filter']) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['post_ids'])) {
    $ids = array_map('intval', (array)$_POST['post_ids']);
    if (!empty($ids) && $_POST['bulk_action'] === 'delete') {
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $p    = $ids;
        $auth = $_s_is_admin ? '' : ' AND account_id = ?';
        if (!$_s_is_admin) $p[] = $_s_account_id;
        try {
            $pdo->prepare("DELETE FROM scheduled_posts WHERE id IN ($in) AND status IN ('pending','failed') $auth")->execute($p);
        } catch (PDOException $e) {}
    }
    header('Location: manage_legacy.php');
    exit;
}

// ── Now load page normally ─────────────────────────────────────────────────
$current_page = 'manage_posts';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin   = ($_SESSION['role'] === 'admin');


// ── Filters & Pagination ──────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$page   = max(1, intval($_GET['page'] ?? 1));
$limit  = 30;
$offset = ($page - 1) * $limit;

// campaign_id IS NULL means "legacy" (no campaign)
// Fault-tolerant: if campaign_id column doesn't exist, show all posts
$has_campaign_col = false;
try {
    $pdo->query("SELECT campaign_id FROM scheduled_posts LIMIT 1");
    $has_campaign_col = true;
} catch (PDOException $e) {}

$legacy_clause = $has_campaign_col ? 'AND campaign_id IS NULL' : '';

if ($filter === 'published') {
    $status_clause = "AND sp.status = 'published'";
} elseif ($filter === 'pending') {
    $status_clause = "AND sp.status IN ('pending','processing')";
} elseif ($filter === 'failed') {
    $status_clause = "AND sp.status = 'failed'";
} else {
    $status_clause = '';
}

// For count/stats (no join) use unqualified
if ($filter === 'published') {
    $status_clause_plain = "AND status = 'published'";
} elseif ($filter === 'pending') {
    $status_clause_plain = "AND status IN ('pending','processing')";
} elseif ($filter === 'failed') {
    $status_clause_plain = "AND status = 'failed'";
} else {
    $status_clause_plain = '';
}

$legacy_clause_plain = $has_campaign_col ? 'AND campaign_id IS NULL' : '';
$legacy_clause_join  = $has_campaign_col ? 'AND sp.campaign_id IS NULL' : '';

$auth_where = $is_admin ? '' : ' AND account_id = ?';
$auth_where_join = $is_admin ? '' : ' AND sp.account_id = ?';
$auth_val   = $is_admin ? [] : [$account_id];

// Count (for pagination) — no join needed
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE 1=1 $legacy_clause_plain $status_clause_plain $auth_where");
    $count_stmt->execute($auth_val);
    $total = (int)$count_stmt->fetchColumn();
} catch (PDOException $e) { $total = 0; }

$total_pages = max(1, ceil($total / $limit));

// Stats (no join — unqualified columns ok)
$stats = ['total' => 0, 'pub' => 0, 'pend' => 0, 'fail' => 0];
try {
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status='published'  THEN 1 ELSE 0 END) AS pub,
            SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS pend,
            SUM(CASE WHEN status='failed'     THEN 1 ELSE 0 END) AS fail
        FROM scheduled_posts WHERE 1=1 $legacy_clause_plain $auth_where
    ");
    $st->execute($auth_val);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $stats = $row;
} catch (PDOException $e) {}

// Fetch posts — LIMIT/OFFSET phải là integer thuần, không bond qua PDO string
$posts = [];
$_debug_err  = '';
$_debug_sql  = "SELECT sp.*, p.name AS page_name FROM scheduled_posts sp LEFT JOIN pages p ON sp.page_id = p.page_id WHERE 1=1 $legacy_clause_join $status_clause $auth_where_join ORDER BY sp.scheduled_time DESC LIMIT $limit OFFSET $offset";
$_debug_params = $auth_val;
try {
    $posts_stmt = $pdo->prepare($_debug_sql);
    $posts_stmt->execute($_debug_params);
    $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_debug_err = $e->getMessage();
}

// Status helpers (PHP 7 compatible)
function leg_status_bg($s) {
    if ($s === 'published')  return '#d1fae5';
    if ($s === 'pending')    return '#fef3c7';
    if ($s === 'processing') return '#e0f2fe';
    if ($s === 'failed')     return '#fee2e2';
    return '#f3f4f6';
}
function leg_status_tc($s) {
    if ($s === 'published')  return '#065f46';
    if ($s === 'pending')    return '#d97706';
    if ($s === 'processing') return '#0369a1';
    if ($s === 'failed')     return '#dc2626';
    return '#6b7280';
}
function leg_status_label($s) {
    if ($s === 'published')  return '✅ Đã đăng';
    if ($s === 'pending')    return '⏳ Chờ';
    if ($s === 'processing') return '🔄 Đang đăng';
    if ($s === 'failed')     return '❌ Lỗi';
    return htmlspecialchars($s);
}

// Check comment_status column
$chk = $pdo->query("SHOW COLUMNS FROM scheduled_posts LIKE 'comment_status'");
$has_comment_status_col = ($chk->rowCount() > 0);

?>

<div class="page-title">
    <a href="manage_posts.php" style="color:var(--text-muted);text-decoration:none;font-size:14px;font-weight:400;">← Campaigns</a>
    <span style="margin:0 8px;color:var(--text-muted);">/</span>
    Bài đăng cũ (không có Campaign)
</div>


<!-- Filter Tabs -->
<div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
    <?php foreach ([
        ['all',       'Tất cả',     (int)($stats['total'] ?? 0)],
        ['published', '✅ Đã đăng', (int)($stats['pub'] ?? 0)],
        ['pending',   '⏳ Chờ',     (int)($stats['pend'] ?? 0)],
        ['failed',    '❌ Lỗi',     (int)($stats['fail'] ?? 0)],
    ] as $tab):
        list($val, $lbl, $cnt) = $tab;
        $active = ($filter === $val);
    ?>
    <a href="manage_legacy.php?filter=<?php echo $val; ?>"
       style="padding:7px 14px;border-radius:6px;text-decoration:none;font-size:13px;
              border:1px solid <?php echo $active ? 'var(--primary-color)' : 'var(--border-color)'; ?>;
              color:<?php echo $active ? 'var(--primary-color)' : 'var(--text-muted)'; ?>;
              font-weight:<?php echo $active ? '600' : '400'; ?>;
              background:<?php echo $active ? 'var(--card-bg)' : 'transparent'; ?>;">
        <?php echo $lbl; ?>
        <span style="background:<?php echo $active ? 'var(--primary-color)' : '#e5e7eb'; ?>;
                     color:<?php echo $active ? 'white' : '#374151'; ?>;
                     padding:1px 7px;border-radius:99px;font-size:11px;"><?php echo $cnt; ?></span>
    </a>
    <?php endforeach; ?>
    </div>
</div>

<!-- Posts Table -->
<div class="card" style="padding:0;overflow-x:auto;">
    <?php if (empty($posts)): ?>
    <div style="text-align:center;padding:50px;color:var(--text-muted);">
        <div style="font-size:40px;margin-bottom:12px;">📭</div>
        Không có bài viết nào.
    </div>
    <?php else: ?>
    <form method="POST" action="manage_legacy.php" id="bulkForm">
        <input type="hidden" name="bulk_action" value="delete">
        <div style="padding:12px 16px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:10px;">
            <label style="font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                <input type="checkbox" id="selectAll" onclick="toggleAll(this)"> Chọn tất cả
            </label>
            <button type="submit" onclick="return confirm('Xóa các bài đã chọn?')"
                    style="padding:5px 14px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:13px;">
                🗑 Xóa đã chọn
            </button>
        </div>
        <table style="width:100%;border-collapse:collapse;min-width:700px;">
            <thead>
                <tr style="background:var(--card-bg);border-bottom:2px solid var(--border-color);">
                    <th style="width:36px;padding:10px 12px;"></th>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Fanpage</th>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Loại</th>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Thời gian</th>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Trạng thái</th>
                    <?php if ($has_comment_status_col): ?>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">💬 Bình luận</th>
                    <?php endif; ?>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Hành động</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $post):
                $content_data = @json_decode($post['content'], true);
                $desc = is_array($content_data) ? (isset($content_data['description']) ? $content_data['description'] : '') : $post['content'];
                $desc_short = mb_strimwidth($desc, 0, 70, '…');
                $s = $post['status'];
            ?>
            <tr style="border-bottom:1px solid var(--border-color);">
                <td style="padding:10px 12px;text-align:center;">
                    <?php if (in_array($s, ['pending','failed'])): ?>
                    <input type="checkbox" name="post_ids[]" value="<?php echo $post['id']; ?>" class="row-check">
                    <?php endif; ?>
                </td>
                <td style="padding:10px 16px;">
                    <div style="font-weight:500;font-size:13px;"><?php echo htmlspecialchars($post['page_name'] ?? $post['page_id']); ?></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><?php echo htmlspecialchars($desc_short); ?></div>
                    <?php if ($s === 'failed' && !empty($post['error_msg'])): ?>
                    <div style="font-size:11px;color:#dc2626;margin-top:4px;background:#fee2e2;padding:2px 6px;border-radius:4px;"><?php echo htmlspecialchars(mb_strimwidth($post['error_msg'], 0, 100, '…')); ?></div>
                    <?php endif; ?>
                    <?php if ($s === 'published' && !empty($post['error_msg'])): ?>
                    <!-- Moved view link to action column -->
                    <?php endif; ?>
                </td>
                <td style="padding:10px 16px;">
                    <span style="background:#f3f4f6;color:#374151;font-size:12px;padding:2px 8px;border-radius:4px;"><?php echo htmlspecialchars($post['post_type']); ?></span>
                </td>
                <td style="padding:10px 16px;font-size:12px;color:var(--text-muted);white-space:nowrap;">
                    <?php echo date('H:i d/m/Y', strtotime($post['scheduled_time'])); ?>
                </td>
                <td style="padding:10px 16px;">
                    <span style="background:<?php echo leg_status_bg($s); ?>;color:<?php echo leg_status_tc($s); ?>;font-size:12px;padding:3px 10px;border-radius:99px;font-weight:500;">
                        <?php echo leg_status_label($s); ?>
                    </span>
                    <?php if (!empty($post['retry_count'])): ?>
                    <span style="font-size:11px;color:#9ca3af;"> ×<?php echo (int)$post['retry_count']; ?></span>
                    <?php endif; ?>
                </td>
                <?php if ($has_comment_status_col): 
                    $cs = $post['comment_status'] ?? null;
                    if (!isset($post['comment_lines']) || empty($post['comment_lines'])) {
                        $cs_bg = '#f3f4f6'; $cs_tc = '#6b7280'; $cs_label = '—';
                    } elseif ($cs === 'done') {
                        $cs_bg = '#d1fae5'; $cs_tc = '#065f46'; $cs_label = '✅ Đã BL';
                    } elseif ($cs === 'error') {
                        $cs_bg = '#fee2e2'; $cs_tc = '#dc2626'; $cs_label = '❌ Lỗi BL';
                    } elseif ($cs === 'pending') {
                        $cs_bg = '#fef3c7'; $cs_tc = '#d97706'; $cs_label = '⏳ Chờ BL';
                    } else {
                        // comment_lines set but not yet processed
                        $cs_bg = '#e0f2fe'; $cs_tc = '#0369a1'; $cs_label = '💬 Đã cài';
                    }
                ?>
                <td style="padding:10px 16px;">
                    <span style="background:<?php echo $cs_bg; ?>;color:<?php echo $cs_tc; ?>;font-size:12px;padding:3px 10px;border-radius:99px;font-weight:500;"><?php echo $cs_label; ?></span>
                </td>
                <?php endif; ?>
                <td style="padding:10px 16px;">
                    <div style="display:flex;gap:6px;">
                    <?php 
                        $view_id = !empty($post['fb_post_id']) ? $post['fb_post_id'] : (!empty($post['error_msg']) ? $post['error_msg'] : '');
                        if ($s === 'published' && $view_id): 
                    ?>
                        <a href="https://facebook.com/<?php echo htmlspecialchars($view_id); ?>" target="_blank"
                           style="font-size:12px;color:var(--primary-color);text-decoration:none;padding:3px 9px;border:1px solid #c7d2fe;border-radius:4px;background:#eef2ff;">Xem</a>
                    <?php endif; ?>
                    <?php if (in_array($s, ['pending','failed'])): ?>
                        <a href="manage_legacy.php?action=delete&id=<?php echo $post['id']; ?>&filter=<?php echo $filter; ?>"
                           onclick="return confirm('Xóa bài này?')"
                           style="font-size:12px;color:#dc2626;text-decoration:none;padding:3px 9px;border:1px solid #fca5a5;border-radius:4px;">Xóa</a>
                    <?php endif; ?>
                    <?php if ($s === 'failed'): ?>
                        <a href="manage_legacy.php?action=retry&id=<?php echo $post['id']; ?>&filter=<?php echo $filter; ?>"
                           style="font-size:12px;color:#059669;text-decoration:none;padding:3px 9px;border:1px solid #6ee7b7;border-radius:4px;">Retry</a>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </form>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:20px;flex-wrap:wrap;">
    <?php for ($i = 1; $i <= min($total_pages, 20); $i++): ?>
        <a href="manage_legacy.php?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>"
           style="padding:6px 12px;border:1px solid <?php echo $i==$page?'var(--primary-color)':'var(--border-color)'; ?>;border-radius:4px;text-decoration:none;
                  color:<?php echo $i==$page?'var(--primary-color)':'var(--text-main)'; ?>;
                  font-weight:<?php echo $i==$page?'bold':'normal'; ?>;font-size:13px;">
            <?php echo $i; ?>
        </a>
    <?php endfor; ?>
    <?php if ($total_pages > 20): ?>
        <span style="padding:6px 4px;color:var(--text-muted);font-size:13px;">… <?php echo $total_pages; ?> trang</span>
    <?php endif; ?>
</div>
<div style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:8px;">
    <?php echo $total; ?> bài · Trang <?php echo $page; ?>/<?php echo $total_pages; ?>
</div>
<?php endif; ?>

<script>
function toggleAll(cb) {
    document.querySelectorAll('.row-check').forEach(function(el) { el.checked = cb.checked; });
}
</script>

<?php include 'includes/footer.php'; ?>
