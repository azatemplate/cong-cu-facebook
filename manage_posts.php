<?php
// ── Actions MUST be before ANY output (before header.php) ─────────────────
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
$_s_account_id = $_SESSION['account_id'] ?? 0;
$_s_is_admin   = ($_SESSION['role'] ?? '') === 'admin';

// POST: bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['post_ids'])) {
    $action   = $_POST['bulk_action'];
    $post_ids = array_map('intval', (array)$_POST['post_ids']);
    if ($action === 'delete' && count($post_ids) > 0) {
        $in     = str_repeat('?,', count($post_ids) - 1) . '?';
        $params = $post_ids;
        $auth   = ' AND account_id = ?';
        $params[] = $_s_account_id;
        try { $pdo->prepare("DELETE FROM scheduled_posts WHERE id IN ($in) AND status IN ('pending','failed') $auth")->execute($params); } catch (PDOException $e) {}
    }
    header('Location: manage_posts.php');
    exit;
}

// GET: delete_campaign / retry_campaign
if (isset($_GET['action'], $_GET['id'])) {
    $act  = $_GET['action'];
    $id   = intval($_GET['id']);
    $auth = ' AND account_id = ?';
    try {
        if ($act === 'delete_campaign') {
            $p = [$id, $_s_account_id];
            $pdo->prepare("DELETE FROM scheduled_posts WHERE campaign_id = ? AND status IN ('pending','failed') $auth")->execute($p);
            $pdo->prepare("DELETE FROM post_campaigns WHERE id = ? AND account_id = ?")->execute([$id, $_s_account_id]);
        } elseif ($act === 'retry_campaign') {
            $p = [$id, $_s_account_id];
            $pdo->prepare("UPDATE scheduled_posts SET status='pending', error_msg=NULL WHERE campaign_id = ? AND status='failed' $auth")->execute($p);
        }
    } catch (PDOException $e) {}
    header('Location: manage_posts.php');
    exit;
}

// ── Now output the page ────────────────────────────────────────────────────
$current_page = 'manage_posts';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin   = ($_SESSION['role'] === 'admin');


// ── Fetch Campaigns (fault-tolerant) ─────────────────────────────────────
$page    = max(1, intval($_GET['page'] ?? 1));
$limit   = 20;
$offset  = ($page - 1) * $limit;

$auth_where  = ' AND c.account_id = ?';
$auth_params = [$account_id];

$total_campaigns = 0;
$total_pages_nav = 1;
$campaigns       = [];
$legacy_count    = 0;

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM post_campaigns c WHERE 1=1 $auth_where");
    $count_stmt->execute($auth_params);
    $total_campaigns = (int)$count_stmt->fetchColumn();
    $total_pages_nav = max(1, ceil($total_campaigns / $limit));

    $list_stmt = $pdo->prepare("
        SELECT
            c.id, c.name, c.post_type, c.total_posts, c.scheduled_time, c.created_at,
            SUM(CASE WHEN sp.status='published'  THEN 1 ELSE 0 END) AS cnt_published,
            SUM(CASE WHEN sp.status='pending'    THEN 1 ELSE 0 END) AS cnt_pending,
            SUM(CASE WHEN sp.status='processing' THEN 1 ELSE 0 END) AS cnt_processing,
            SUM(CASE WHEN sp.status='failed'     THEN 1 ELSE 0 END) AS cnt_failed,
            COUNT(sp.id) AS cnt_total
        FROM post_campaigns c
        LEFT JOIN scheduled_posts sp ON sp.campaign_id = c.id
        WHERE 1=1 $auth_where
        GROUP BY c.id, c.name, c.post_type, c.total_posts, c.scheduled_time, c.created_at
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $list_stmt->execute($auth_params);
    $campaigns = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // post_campaigns table may not exist yet — show empty state
}

try {
    $legacy_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM scheduled_posts WHERE campaign_id IS NULL AND account_id = ?"
    );
    $legacy_stmt->execute([$account_id]);
    $legacy_count = (int)$legacy_stmt->fetchColumn();
} catch (PDOException $e) {
    // campaign_id column may not exist yet
}
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
        <h3 style="margin:0;">Danh sách chiến dịch đăng bài</h3>
        <div style="display:flex; gap:10px; align-items:center;">
            <button id="cronBtn" onclick="runCronJob()" style="background:var(--primary-color);color:white;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:bold;display:flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Quét Hàng Đợi
            </button>
            <?php if ($legacy_count > 0): ?>
            <a href="manage_legacy.php" style="padding:8px 14px;border:1px solid var(--border-color);border-radius:6px;text-decoration:none;color:var(--text-main);font-size:13px;">
                Bài cũ (<?php echo $legacy_count; ?>)
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($campaigns) === 0): ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted);">
        <div style="font-size:48px;margin-bottom:16px;">📭</div>
        <div style="font-size:16px;font-weight:500;">Chưa có chiến dịch nào</div>
        <div style="font-size:13px;margin-top:8px;">Tạo bài đăng từ <a href="posts.php" style="color:var(--primary-color);">Posts</a>, <a href="videos.php" style="color:var(--primary-color);">Videos</a> hoặc <a href="reels.php" style="color:var(--primary-color);">Reels</a>.</div>
    </div>
    <?php else: ?>

    <div style="display:grid; gap:14px;">
    <?php foreach ($campaigns as $c):
        $total    = max(1, (int)$c['cnt_total']);
        $pub      = (int)$c['cnt_published'];
        $pend     = (int)$c['cnt_pending'];
        $proc     = (int)$c['cnt_processing'];
        $fail     = (int)$c['cnt_failed'];
        $progress = round($pub / $total * 100);

        // Badge — priority: processing > pending > failed > done > empty
        if ($proc > 0) {
            $badge_color = '#e0f2fe'; $badge_text_color = '#0369a1'; $badge_label = "🔄 Đang đăng";
        } elseif ($pend > 0) {
            $badge_color = '#fef3c7'; $badge_text_color = '#d97706'; $badge_label = "⏳ $pend chờ";
        } elseif ($fail > 0) {
            $badge_color = '#fee2e2'; $badge_text_color = '#dc2626'; $badge_label = "⚠️ $fail lỗi";
        } elseif ($pub >= $total && $total > 0) {
            $badge_color = '#d1fae5'; $badge_text_color = '#065f46'; $badge_label = "✅ Hoàn tất";
        } else {
            $badge_color = '#f3f4f6'; $badge_text_color = '#6b7280'; $badge_label = "—";
        }
    ?>
    <div style="border:1px solid var(--border-color);border-radius:10px;padding:18px 20px;background:var(--card-bg);transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'" onmouseout="this.style.boxShadow='none'">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                    <span style="font-weight:600;font-size:15px;color:var(--text-main);"><?php echo htmlspecialchars($c['name']); ?></span>
                    <span style="background:<?php echo $badge_color; ?>;color:<?php echo $badge_text_color; ?>;font-size:12px;padding:2px 10px;border-radius:20px;font-weight:500;white-space:nowrap;"><?php echo $badge_label; ?></span>
                    <span style="background:#f3f4f6;color:#374151;font-size:11px;padding:2px 8px;border-radius:4px;"><?php echo htmlspecialchars($c['post_type']); ?></span>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
                    Tạo lúc: <?php echo date('d/m/Y H:i', strtotime($c['created_at'])); ?>
                    <?php if ($c['scheduled_time']): ?>
                    &nbsp;·&nbsp; Hẹn giờ: <?php echo date('d/m/Y H:i', strtotime($c['scheduled_time'])); ?>
                    <?php endif; ?>
                </div>
                <!-- Progress Bar -->
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="flex:1;height:8px;background:#f3f4f6;border-radius:99px;overflow:hidden;">
                        <div style="height:100%;width:<?php echo $progress; ?>%;background:<?php echo $pub===$total && $total>0 ? '#10b981' : 'var(--primary-color)'; ?>;border-radius:99px;transition:width 0.3s;"></div>
                    </div>
                    <span style="font-size:12px;color:var(--text-muted);white-space:nowrap;"><?php echo $pub; ?>/<?php echo $total; ?> đã đăng</span>
                </div>
                <!-- Counters -->
                <div style="display:flex;gap:14px;margin-top:8px;font-size:12px;">
                    <?php if ($pend > 0): ?><span style="color:#d97706;">⏳ <?php echo $pend; ?> chờ</span><?php endif; ?>
                    <?php if ($proc > 0): ?><span style="color:#0369a1;">🔄 <?php echo $proc; ?> đang đăng</span><?php endif; ?>
                    <?php if ($fail > 0): ?><span style="color:#dc2626;">❌ <?php echo $fail; ?> lỗi</span><?php endif; ?>
                    <?php if ($pub > 0): ?><span style="color:#10b981;">✅ <?php echo $pub; ?> đã đăng</span><?php endif; ?>
                </div>
            </div>
            <!-- Actions -->
            <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
                <?php if ($fail > 0): ?>
                <a href="manage_posts.php?action=retry_campaign&id=<?php echo $c['id']; ?>" onclick="return confirm('Thử lại tất cả bài lỗi trong chiến dịch này?')" style="padding:7px 12px;background:#d1fae5;color:#065f46;border-radius:6px;text-decoration:none;font-size:13px;">
                    Retry
                </a>
                <?php endif; ?>
                <?php if ($pend > 0 || $fail > 0): ?>
                <a href="manage_posts.php?action=delete_campaign&id=<?php echo $c['id']; ?>" onclick="return confirm('Xóa toàn bộ bài pending/lỗi trong chiến dịch này?')" style="padding:7px 12px;background:#fee2e2;color:#dc2626;border-radius:6px;text-decoration:none;font-size:13px;">
                    Xóa
                </a>
                <?php endif; ?>
                <a href="campaign_detail.php?id=<?php echo $c['id']; ?>" style="padding:7px 14px;background:var(--primary-color);color:white;border-radius:6px;text-decoration:none;font-size:13px;font-weight:500;margin-left:auto;">
                    Xem chi tiết →
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages_nav > 1): ?>
    <div style="display:flex;justify-content:center;gap:8px;margin-top:24px;">
        <?php for ($i = 1; $i <= $total_pages_nav; $i++): ?>
            <a href="?page=<?php echo $i; ?>" style="padding:6px 12px;border:1px solid <?php echo $i==$page?'var(--primary-color)':'var(--border-color)'; ?>;border-radius:4px;text-decoration:none;color:<?php echo $i==$page?'var(--primary-color)':'var(--text-main)'; ?>;font-weight:<?php echo $i==$page?'bold':'normal'; ?>;"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
function runCronJob() {
    if (!confirm('Hệ thống sẽ tiến hành duyệt các bài viết đã đến giờ hẹn. Vui lòng bấm OK và chờ.')) return;
    const btn = document.getElementById('cronBtn');
    btn.innerHTML = '<span class="loader" style="width:12px;height:12px;border:2px solid #fff;border-bottom-color:transparent;border-radius:50%;display:inline-block;animation:rotation 1s linear infinite;"></span> Đang chạy...';
    btn.disabled = true;
    fetch('cron/start_publish.php')
    .then(r => r.text())
    .then(text => { alert('HOÀN TẤT!\n\n' + text); window.location.reload(); })
    .catch(err => { alert('Lỗi: ' + err); window.location.reload(); });
}

// Keep scroll position on reload
document.addEventListener("DOMContentLoaded", function() { 
    const key = 'scrollpos_' + window.location.search;
    if (sessionStorage.getItem(key)) window.scrollTo(0, sessionStorage.getItem(key));
});
window.addEventListener("beforeunload", function() {
    sessionStorage.setItem('scrollpos_' + window.location.search, window.scrollY);
});

// Auto-refresh if any campaign has pending/processing
document.addEventListener('DOMContentLoaded', function() {
    const hasPending = document.querySelector('[style*="#fef3c7"]') || document.querySelector('[style*="#e0f2fe"]');
    if (hasPending) setTimeout(() => window.location.reload(), 20000);
});
</script>
<style>@keyframes rotation{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style>

<?php include 'includes/footer.php'; ?>
