<?php
// ── Actions MUST be before ANY output (before header.php) ─────────────────
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
$_s_account_id = $_SESSION['account_id'] ?? 0;
$_s_is_admin   = ($_SESSION['role'] ?? '') === 'admin';
session_write_close();

// POST: bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['post_ids'])) {
    $action   = $_POST['bulk_action'];
    $post_ids = array_map('intval', (array)$_POST['post_ids']);
    if ($action === 'delete' && count($post_ids) > 0) {
        $in     = str_repeat('?,', count($post_ids) - 1) . '?';
        $params = $post_ids;
        $auth   = ' AND account_id = ?';
        $params[] = $_s_account_id;
        try {
            // Free up local files before deleting
            $stmt = $pdo->prepare("SELECT media_path FROM scheduled_posts WHERE id IN ($in) AND status IN ('pending','failed') $auth");
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['media_path']) && strpos($row['media_path'], 'uploads/') !== false) {
                    $decoded = @json_decode($row['media_path'], true);
                    $paths = is_array($decoded) ? $decoded : [$row['media_path']];
                    foreach ($paths as $p) {
                        $p = trim($p);
                        if (strpos($p, 'uploads/') !== false) {
                            $full = __DIR__ . '/../' . $p;
                            if (file_exists($full)) @unlink($full);
                        }
                    }
                }
            }
            $pdo->prepare("DELETE FROM scheduled_posts WHERE id IN ($in) AND status IN ('pending','failed') $auth")->execute($params);
        } catch (PDOException $e) {}
    }
    $redirect_url = 'manage_posts.php';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $parsed = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $q_params);
            unset($q_params['action'], $q_params['id'], $q_params['bulk_action']);
            if (!empty($q_params)) {
                $redirect_url .= '?' . http_build_query($q_params);
            }
        }
    }
    header('Location: ' . $redirect_url);
    exit;
}

// GET: delete_campaign / retry_campaign / clean_empty
if (isset($_GET['action'])) {
    $act  = $_GET['action'];
    $id   = intval($_GET['id'] ?? 0);
    $auth = ' AND account_id = ?';
    try {
        if ($act === 'clean_empty') {
            $pdo->prepare("
                DELETE pc FROM post_campaigns pc
                WHERE pc.account_id = ? 
                  AND NOT EXISTS (
                      SELECT 1 FROM scheduled_posts sp WHERE sp.campaign_id = pc.id
                  )
            ")->execute([$_s_account_id]);
        } elseif ($act === 'delete_campaign' && $id > 0) {
            $p = [$id, $_s_account_id];
            
            // Free up local files for the campaign's pending posts before deleting
            $stmt = $pdo->prepare("SELECT media_path FROM scheduled_posts WHERE campaign_id = ? AND status IN ('pending','failed','checkpoint') $auth");
            $stmt->execute($p);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['media_path']) && strpos($row['media_path'], 'uploads/') !== false) {
                    $decoded = @json_decode($row['media_path'], true);
                    $paths = is_array($decoded) ? $decoded : [$row['media_path']];
                    foreach ($paths as $path) {
                        $path = trim($path);
                        if (strpos($path, 'uploads/') !== false) {
                            $full = __DIR__ . '/../' . $path;
                            if (file_exists($full)) @unlink($full);
                        }
                    }
                }
            }
            
            $pdo->prepare("DELETE FROM scheduled_posts WHERE campaign_id = ? AND status IN ('pending','failed','checkpoint','processing') $auth")->execute($p);
            $pdo->prepare("DELETE FROM post_campaigns WHERE id = ? AND account_id = ?")->execute([$id, $_s_account_id]);
        } elseif ($act === 'retry_campaign' && $id > 0) {
            $p = [$id, $_s_account_id];
            $pdo->prepare("UPDATE scheduled_posts SET status='pending', retry_count=0, error_msg=NULL WHERE campaign_id = ? AND status IN ('failed', 'checkpoint') $auth")->execute($p);
        }
    } catch (PDOException $e) {}

    $redirect_params = $_GET;
    unset($redirect_params['action'], $redirect_params['id']);
    $redirect_url = 'manage_posts.php';
    if (!empty($redirect_params)) {
        $redirect_url .= '?' . http_build_query($redirect_params);
    }
    header('Location: ' . $redirect_url);
    exit;
}

// ── Now output the page ────────────────────────────────────────────────────
$current_page = 'manage_posts';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin   = ($_SESSION['role'] === 'admin');

// ── Fetch Campaigns (fault-tolerant) ─────────────────────────────────────
$search  = trim(html_entity_decode($_GET['search'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$page    = max(1, intval($_GET['page'] ?? 1));
$limit   = 20;
$offset  = ($page - 1) * $limit;

$total_campaigns = 0;
$total_pages_nav = 1;
$campaigns       = [];
$legacy_count    = 0;

$search_where  = "";
$search_params = [];

if ($search !== '') {
    $search_like = '%' . $search . '%';
    $search_where = " AND (
        c.name LIKE ? 
        OR c.id IN (
            SELECT DISTINCT sp.campaign_id 
            FROM scheduled_posts sp
            LEFT JOIN pages p ON sp.page_id = p.page_id AND sp.post_type NOT LIKE 'Buffer%' AND sp.post_type != 'YouTube' AND sp.post_type != 'TikTok'
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN instagram_accounts ig2 ON sp.page_id = ig2.id AND sp.post_type LIKE 'Instagram%'
            LEFT JOIN youtube_channels yt1 ON sp.page_id = yt1.channel_id AND sp.post_type = 'YouTube'
            LEFT JOIN youtube_channels yt2 ON sp.page_id = yt2.id AND sp.post_type = 'YouTube'
            LEFT JOIN buffer_channels bc ON sp.page_id = bc.channel_id AND sp.post_type LIKE 'Buffer%'
            LEFT JOIN tiktok_accounts tt ON sp.page_id = tt.id AND sp.post_type = 'TikTok'
            WHERE sp.account_id = ? AND (
                u.name LIKE ? OR p.name LIKE ? OR yt1.channel_title LIKE ? OR yt2.channel_title LIKE ? OR bc.channel_name LIKE ? OR tt.display_name LIKE ?
            )
        )
    )";
    $search_params = [$search_like, $account_id, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like];
}

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM post_campaigns c WHERE c.account_id = ?" . $search_where);
    $count_stmt->execute(array_merge([$account_id], $search_params));
    $total_campaigns = (int)$count_stmt->fetchColumn();
    $total_pages_nav = max(1, ceil($total_campaigns / $limit));

    // Giai đoạn 1: Lấy danh sách 20 chiến dịch
    $sub_stmt = $pdo->prepare("SELECT c.id, c.name, c.post_type, c.total_posts, c.scheduled_time, c.created_at FROM post_campaigns c WHERE c.account_id = ?" . $search_where . " ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset");
    $sub_stmt->execute(array_merge([$account_id], $search_params));
    $page_camps = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($page_camps)) {
        $c_ids = array_column($page_camps, 'id');
        $in_ids = implode(',', array_map('intval', $c_ids));
        $campaigns = $page_camps;
    }
} catch (PDOException $e) {}

$legacy_count = 0;

// Đếm số chiến dịch trống
$empty_camp_count = 0;
try {
    $ec_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM post_campaigns pc
        WHERE pc.account_id = ? 
          AND NOT EXISTS (
              SELECT 1 FROM scheduled_posts sp WHERE sp.campaign_id = pc.id
          )
    ");
    $ec_stmt->execute([$account_id]);
    $empty_camp_count = (int)$ec_stmt->fetchColumn();
} catch (PDOException $e) {}
?>

<style>
/* Evondev Skill Styling for Manage Posts & Campaigns */
.mp-container,
.mp-container button,
.mp-container input,
.mp-container select,
.mp-container textarea {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.mp-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

/* Header Banner Card */
.mp-header-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
    border: 1px solid #312e81;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(15, 23, 42, 0.15);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.mp-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.mp-header-info p {
    font-size: 13.5px;
    color: #cbd5e1;
    margin: 0;
}

.mp-search-form {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.mp-search-input-wrap {
    position: relative;
    min-width: 280px;
}

.mp-search-input-wrap input {
    width: 100%;
    padding: 10px 14px 10px 38px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 10px;
    font-size: 13.5px;
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
.mp-search-input-wrap input::placeholder {
    color: #cbd5e1;
}
.mp-search-input-wrap input:focus {
    outline: none;
    border-color: #818cf8;
    background: rgba(255, 255, 255, 0.18);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
}

.mp-search-input-wrap svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #c7d2fe;
}

.btn-mp-primary {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: #ffffff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.25);
    transition: all 0.2s ease;
    text-decoration: none;
}
.btn-mp-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(99, 102, 241, 0.35);
    color: #ffffff;
}

.btn-mp-secondary {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 9px 18px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    text-decoration: none;
}
.btn-mp-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
}

.btn-mp-danger {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
    padding: 9px 18px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.btn-mp-danger:hover {
    background: #fee2e2;
    border-color: #fca5a5;
}

/* Campaign Card Surface */
.campaign-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 22px 24px;
    margin-bottom: 16px;
    box-shadow: 0 4px 20px -2px rgba(0,0,0,0.04);
    transition: all 0.2s ease;
}
.campaign-card:hover {
    box-shadow: 0 8px 24px -4px rgba(0,0,0,0.08);
    border-color: #cbd5e1;
}

/* Progress Fill */
.mp-progress-bar {
    height: 10px;
    background: #f1f5f9;
    border-radius: 9999px;
    overflow: hidden;
    flex: 1;
}
.mp-progress-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
    border-radius: 9999px;
    transition: width 0.4s ease;
}

/* Skeleton Loading */
.skeleton-box {
    display: inline-block;
    height: 14px;
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: 6px;
}
@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}
</style>

<div class="mp-container">
    <!-- Header Banner Card -->
    <div class="mp-header-card">
        <div class="mp-header-info">
            <h1>
                <svg width="26" height="26" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                Quản Lý Chiến Dịch Đăng Bài
            </h1>
            <p>Theo dõi tiến độ phát hành bài đăng, quét hàng đợi realtime và xử lý sự cố hàng loạt</p>
        </div>

        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <form method="GET" action="manage_posts.php" class="mp-search-form">
                <div class="mp-search-input-wrap">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tìm Kênh, Fanpage hoặc Chiến dịch...">
                </div>
                <button type="submit" class="btn-mp-primary">Tìm kiếm</button>
                <?php if ($search !== ''): ?>
                <a href="manage_posts.php" class="btn-mp-secondary">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ($is_admin): ?>
            <button id="cronBtn" onclick="runCronJob()" class="btn-mp-primary" style="background:linear-gradient(135deg, #10b981, #059669);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Quét Hàng Đợi
            </button>
            <?php endif; ?>

            <?php if ($empty_camp_count > 0): ?>
            <button onclick="showConfirmModal('clean_empty', 0, 'Xóa tất cả <?php echo $empty_camp_count; ?> chiến dịch trống (không còn bài nào)?')" class="btn-mp-danger">
                🧹 Xóa <?php echo $empty_camp_count; ?> camp trống
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($search !== ''): ?>
    <div style="margin-bottom:20px; padding:14px 18px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; font-size:13.5px; color:#1e40af; font-weight:600; display:flex; align-items:center; gap:8px;">
        🔍 Kết quả tìm kiếm cho từ khóa: <strong>"<?php echo htmlspecialchars($search); ?>"</strong> (Tìm thấy <strong><?php echo $total_campaigns; ?></strong> chiến dịch)
    </div>
    <?php endif; ?>

    <?php if (count($campaigns) === 0): ?>
    <div class="campaign-card" style="text-align:center; padding:60px 20px;">
        <div style="font-size:48px; margin-bottom:12px;">📭</div>
        <h3 style="margin:0 0 8px; font-weight:800; color:#0f172a;">Chưa có chiến dịch nào</h3>
        <p style="color:#64748b; font-size:14px; margin:0 0 16px;">
            Tạo bài đăng từ <a href="posts.php" style="color:#6366f1; font-weight:700;">Posts</a>, <a href="videos.php" style="color:#6366f1; font-weight:700;">Videos</a> hoặc <a href="reels.php" style="color:#6366f1; font-weight:700;">Reels</a>.
        </p>
    </div>
    <?php else: ?>
    
    <div style="display:grid; gap:16px;" id="campaign-list" data-ids="<?php echo implode(',', array_column($campaigns, 'id')); ?>">
    <?php foreach ($campaigns as $c): ?>
    <div class="campaign-card campaign-item" id="camp-<?php echo $c['id']; ?>" data-id="<?php echo $c['id']; ?>" data-post-type="<?php echo htmlspecialchars($c['post_type']); ?>">
        <div class="campaign-row">
            <div class="campaign-info">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
                    <span style="font-weight:800; font-size:16px; color:#0f172a; letter-spacing:-0.01em;"><?php echo htmlspecialchars($c['name']); ?></span>
                    <span class="badge" style="background:#f1f5f9; color:#475569; font-size:12px; padding:4px 12px; border-radius:9999px; font-weight:700; display:flex; align-items:center; min-height:22px;"><div class="skeleton-box" style="width: 70px; height: 10px;"></div></span>
                    <span style="background:#e0e7ff; color:#3730a3; font-size:11.5px; padding:3px 10px; border-radius:6px; font-weight:800; text-transform:uppercase; letter-spacing:0.03em;"><?php echo htmlspecialchars($c['post_type']); ?></span>
                </div>

                <div style="font-size:12.5px; color:#64748b; margin-bottom:12px; font-weight:600;">
                    Tạo lúc: <?php echo date('d/m/Y H:i', strtotime($c['created_at'])); ?>
                    <?php if ($c['scheduled_time']): ?>
                    &nbsp;·&nbsp; Hẹn giờ: <span style="color:#0284c7; font-weight:700;"><?php echo date('d/m/Y H:i', strtotime($c['scheduled_time'])); ?></span>
                    <?php endif; ?>
                    <span class="camp-users"></span>
                </div>

                <!-- Progress Bar -->
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:10px;">
                    <div class="mp-progress-bar">
                        <div class="progress-bar-fill mp-progress-fill"></div>
                    </div>
                    <span class="progress-text" style="font-size:12.5px; color:#475569; font-weight:800; white-space:nowrap; min-width:90px; text-align:right;"><div class="skeleton-box" style="width: 80px; height: 12px;"></div></span>
                </div>

                <!-- Counters -->
                <div class="counters" style="display:flex; gap:14px; font-size:12.5px; font-weight:700; min-height:18px; align-items:center; flex-wrap:wrap;">
                    <div class="skeleton-box" style="width: 120px;"></div>
                    <div class="skeleton-box" style="width: 80px;"></div>
                </div>
            </div>

            <!-- Actions -->
            <div class="campaign-actions">
                <div class="action-buttons" style="display:flex; gap:8px;"></div>
                <a href="campaign_detail.php?id=<?php echo $c['id']; ?>" class="btn-mp-primary btn-detail" style="padding:8px 16px; font-size:13px;">
                    Xem chi tiết →
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages_nav > 1): ?>
    <div style="display:flex; justify-content:center; gap:8px; margin-top:28px;">
        <?php 
            $search_param = !empty($search) ? '&search=' . urlencode($search) : '';
            for ($i = 1; $i <= $total_pages_nav; $i++): 
        ?>
            <a href="?page=<?php echo $i . $search_param; ?>" style="padding:8px 14px; border:1px solid <?php echo $i==$page?'#6366f1':'#cbd5e1'; ?>; border-radius:8px; text-decoration:none; color:<?php echo $i==$page?'#ffffff':'#334155'; ?>; background:<?php echo $i==$page?'#6366f1':'#ffffff'; ?>; font-weight:700; font-size:13.5px;"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Custom Confirm Modal -->
<div id="confirmModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:20px; padding:32px; max-width:440px; width:90%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); text-align:center;">
        <div style="font-size:44px; margin-bottom:12px;" id="modalIcon">⚠️</div>
        <h3 id="modalTitle" style="margin:0 0 8px; font-size:18px; font-weight:800; color:#0f172a;"></h3>
        <p id="modalMessage" style="margin:0 0 24px; font-size:14px; color:#64748b; line-height:1.5;"></p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button id="modalCancelBtn" onclick="hideConfirmModal()" class="btn-mp-secondary">Huỷ</button>
            <button id="modalOkBtn" onclick="doConfirmAction()" class="btn-mp-primary" style="background:#ef4444;">Xác nhận</button>
        </div>
    </div>
</div>

<!-- Cron Result Modal -->
<div id="cronModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:20px; padding:28px 32px; max-width:540px; width:90%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <h3 style="margin:0 0 16px; font-size:17px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
            ⚙️ Kết quả Quét Hàng Đợi
        </h3>
        <pre id="cronResult" style="background:#0f172a; border:1px solid #334155; border-radius:12px; padding:16px; font-size:13px; max-height:320px; overflow-y:auto; white-space:pre-wrap; word-break:break-word; color:#34d399; font-family:monospace;"></pre>
        <div style="text-align:right; margin-top:20px;">
            <button onclick="hideCronModal()" class="btn-mp-primary">Đóng & Tải lại</button>
        </div>
    </div>
</div>

<script>
let _confirmAction = null;
let _confirmId = null;

function showConfirmModal(action, id, message) {
    _confirmAction = action;
    _confirmId = id;
    const isDelete = action === 'delete' || action === 'clean_empty';
    document.getElementById('modalIcon').textContent = action === 'clean_empty' ? '🧹' : (isDelete ? '🗑️' : '🔄');
    document.getElementById('modalTitle').textContent = action === 'clean_empty' ? 'Xóa chiến dịch trống' : (isDelete ? 'Xác nhận xóa' : 'Xác nhận thử lại');
    document.getElementById('modalMessage').textContent = message;
    document.getElementById('modalOkBtn').style.background = isDelete ? '#ef4444' : '#10b981';
    const modal = document.getElementById('confirmModal');
    modal.style.display = 'flex';
}

function hideConfirmModal() {
    document.getElementById('confirmModal').style.display = 'none';
    _confirmAction = null;
    _confirmId = null;
}

function doConfirmAction() {
    if (!_confirmAction) return;
    const urlParams = new URLSearchParams(window.location.search);
    if (_confirmAction === 'clean_empty') {
        urlParams.set('action', 'clean_empty');
        urlParams.delete('id');
    } else if (_confirmId) {
        urlParams.set('action', _confirmAction + '_campaign');
        urlParams.set('id', _confirmId);
    }
    window.location.href = 'manage_posts.php?' + urlParams.toString();
}

function runCronJob() {
    const btn = document.getElementById('cronBtn');
    btn.innerHTML = '⏳ Đang quét...';
    btn.disabled = true;
    fetch('diagnostics.php?run=publish&ajax=1')
    .then(r => r.text())
    .then(text => {
        document.getElementById('cronResult').textContent = text;
        document.getElementById('cronModal').style.display = 'flex';
        btn.innerHTML = '⚙️ Quét Hàng Đợi';
        btn.disabled = false;
    })
    .catch(err => {
        document.getElementById('cronResult').textContent = 'Lỗi: ' + err;
        document.getElementById('cronModal').style.display = 'flex';
        btn.innerHTML = '⚙️ Quét Hàng Đợi';
        btn.disabled = false;
    });
}

function hideCronModal() {
    document.getElementById('cronModal').style.display = 'none';
    window.location.reload();
}

// Close modals on backdrop click
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) hideConfirmModal();
});
document.getElementById('cronModal').addEventListener('click', function(e) {
    if (e.target === this) hideCronModal();
});

// Keep scroll position on reload
document.addEventListener("DOMContentLoaded", function() { 
    const key = 'scrollpos_' + window.location.search;
    if (sessionStorage.getItem(key)) window.scrollTo(0, sessionStorage.getItem(key));
});
window.addEventListener("beforeunload", function() {
    sessionStorage.setItem('scrollpos_' + window.location.search, window.scrollY);
});

// Silent background update (NO FULL PAGE RELOAD)
function pollCampaignStats() {
    const listDiv = document.getElementById('campaign-list');
    if (!listDiv) return;
    const ids = listDiv.getAttribute('data-ids');
    if (!ids) return;

    fetch('actions/ajax_campaign_stats.php?ids=' + ids)
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            let hasActive = false;
            for (const [id, st] of Object.entries(res.data)) {
                updateCampaignRow(id, st);
                if (st.cnt_pending > 0 || st.cnt_processing > 0 || st.cnt_cmt_pending > 0 || st.cnt_cmt_processing > 0) {
                    hasActive = true;
                }
            }
            if (hasActive) {
                setTimeout(pollCampaignStats, 15000);
            }
        }
    })
    .catch(err => {
        console.error('Error fetching campaign stats:', err);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    pollCampaignStats();
});

function updateCampaignRow(id, st) {
    const row = document.getElementById('camp-' + id);
    if (!row) return;

    const postType = row.getAttribute('data-post-type');
    const isSeeding = (postType === 'Seeding Comment');
    const totalReal = parseInt(st.cnt_total) || 0;
    const total = Math.max(1, totalReal);
    
    let pub = 0, pend = 0, proc = 0, fail = 0, check = 0;
    let unitVerb = '';

    if (isSeeding) {
        pub = parseInt(st.cnt_cmt_done) || 0;
        pend = parseInt(st.cnt_cmt_pending) || 0;
        proc = parseInt(st.cnt_cmt_processing) || 0;
        fail = parseInt(st.cnt_failed) || 0;
        check = parseInt(st.cnt_checkpoint) || 0;
        unitVerb = 'cmt';
    } else {
        pub = parseInt(st.cnt_published) || 0;
        pend = parseInt(st.cnt_pending) || 0;
        proc = parseInt(st.cnt_processing) || 0;
        fail = parseInt(st.cnt_failed) || 0;
        check = parseInt(st.cnt_checkpoint) || 0;
        unitVerb = 'đăng';
    }

    const progress = Math.round(pub / total * 100);

    let badgeColor = '#f1f5f9', badgeTextColor = '#475569', badgeLabel = '—';
    if (check > 0) {
        badgeColor = '#fee2e2'; badgeTextColor = '#991b1b'; badgeLabel = "🚫 Tài khoản bị checkpoint";
    } else if (proc > 0) {
        badgeColor = '#e0f2fe'; badgeTextColor = '#0369a1'; badgeLabel = "🔄 Đang " + unitVerb;
    } else if (pend > 0) {
        badgeColor = '#fef3c7'; badgeTextColor = '#d97706'; badgeLabel = "⏳ " + pend + " chờ " + unitVerb;
    } else if (fail > 0) {
        badgeColor = '#fee2e2'; badgeTextColor = '#dc2626'; badgeLabel = "⚠️ " + fail + " lỗi";
    } else if (pub >= total && totalReal > 0) {
        badgeColor = '#d1fae5'; badgeTextColor = '#065f46'; badgeLabel = "✅ Hoàn tất";
    } else if (totalReal === 0) {
        badgeColor = '#f1f5f9'; badgeTextColor = '#6b7280'; badgeLabel = "📭 Trống";
    }

    const badgeEl = row.querySelector('.badge');
    if (badgeEl) {
        badgeEl.style.background = badgeColor;
        badgeEl.style.color = badgeTextColor;
        badgeEl.textContent = badgeLabel;
    }

    // Users
    if (st.fb_users) {
        const usersArr = st.fb_users.split(',').map(u => u.trim()).filter(u => u);
        const uCount = usersArr.length;
        const isChannel = (postType === 'YouTube' || postType === 'TikTok' || postType.includes('Buffer'));
        const isGroup = st.fb_users.includes('📂') || st.fb_users.includes('📁');
        
        const unitLabel = isGroup ? 'nhóm' : (isChannel ? 'kênh' : 'trang');
        const iconLabel = isGroup ? '' : ((postType === 'TikTok') ? '🎵' : '👤');
        
        let displayUsers = st.fb_users;
        if (uCount > 1 && !isGroup) {
            displayUsers = usersArr[0] + ' và ' + (uCount - 1) + ' ' + unitLabel + ' khác';
        }
        
        const usersEl = row.querySelector('.camp-users');
        if (usersEl) {
            usersEl.innerHTML = '&nbsp;·&nbsp; <span style="color:#6366f1; font-weight:700;" title="' + st.fb_users.replace(/"/g, '&quot;') + '">' + (iconLabel ? iconLabel + ' ' : '') + displayUsers + '</span>';
        }
    }

    // Progress
    const fillEl = row.querySelector('.progress-bar-fill');
    if (fillEl) {
        fillEl.style.width = progress + '%';
        if (pub === totalReal && totalReal > 0) {
            fillEl.style.background = 'linear-gradient(90deg, #10b981, #059669)';
        }
    }
    const progTextEl = row.querySelector('.progress-text');
    if (progTextEl) {
        progTextEl.textContent = pub + '/' + totalReal + ' đã ' + unitVerb;
    }

    // Counters
    let countersHtml = '';
    if (pend > 0) countersHtml += '<span style="color:#d97706;">⏳ ' + pend + ' chờ ' + unitVerb + '</span>';
    if (proc > 0) countersHtml += '<span style="color:#0369a1;">🔄 ' + proc + ' đang ' + unitVerb + '</span>';
    if (fail > 0) countersHtml += '<span style="color:#dc2626;">❌ ' + fail + ' lỗi</span>';
    if (check > 0) countersHtml += '<span style="color:#991b1b;">🚫 Bị checkpoint, dừng lại (còn ' + check + ' bài chưa chạy)</span>';
    if (pub > 0) countersHtml += '<span style="color:#10b981;">✅ ' + pub + ' đã ' + unitVerb + '</span>';
    const countersEl = row.querySelector('.counters');
    if (countersEl) countersEl.innerHTML = countersHtml;

    // Actions
    let actionsHtml = '';
    if (fail > 0 || check > 0) {
        actionsHtml += '<button onclick="showConfirmModal(\'retry\', ' + id + ', \'Thử lại tất cả bài lỗi trong chiến dịch này?\')" class="btn-mp-secondary" style="padding:6px 12px; font-size:12.5px; color:#059669; border-color:#a7f3d0; background:#ecfdf5;">Retry</button>';
    }
    if (pend > 0 || fail > 0 || check > 0) {
        actionsHtml += '<button onclick="showConfirmModal(\'delete\', ' + id + ', \'Xóa toàn bộ bài chưa hoàn tất trong chiến dịch này?\')" class="btn-mp-danger" style="padding:6px 12px; font-size:12.5px;">Xóa</button>';
    }
    if (totalReal === 0) {
        actionsHtml += '<button onclick="showConfirmModal(\'delete\', ' + id + ', \'Xóa chiến dịch trống này?\')" class="btn-mp-danger" style="padding:6px 12px; font-size:12.5px;">🗑 Xóa</button>';
    }
    const actBtnEl = row.querySelector('.action-buttons');
    if (actBtnEl) actBtnEl.innerHTML = actionsHtml;
}
</script>
<style>
.campaign-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
}
.campaign-info {
    flex: 1;
    min-width: 0;
}
.campaign-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-shrink: 0;
}
.campaign-actions a.btn-detail {
    margin-left: auto;
}

@media (max-width: 768px) {
    .campaign-row {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    .campaign-actions {
        width: 100%;
        border-top: 1px solid #f1f5f9;
        padding-top: 14px;
        margin-top: 6px;
        display: flex;
        gap: 8px;
        justify-content: space-between;
    }
    .campaign-actions button, .campaign-actions a.btn-detail {
        flex: 1;
        text-align: center;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 9px 12px !important;
        font-size: 13px;
        margin-left: 0 !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>

