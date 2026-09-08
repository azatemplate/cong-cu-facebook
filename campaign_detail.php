<?php
// ── Actions MUST be processed before ANY output (before header.php) ────────
require_once __DIR__ . '/includes/db.php';

$campaign_id = intval($_GET['id'] ?? 0);

function trigger_campaign_publisher_worker($pdo, $campaign_id) {
    try {
        $disabled_funcs = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
        $exec_enabled = function_exists('exec') && !in_array('exec', $disabled_funcs);
        
        if ($exec_enabled) {
            if (!function_exists('get_php_cli_bin')) @include_once __DIR__ . '/includes/php_cli.php';
            if (function_exists('get_php_cli_bin')) {
                $php_bin = get_php_cli_bin();
                $script = __DIR__ . '/cron/start_publish.php';
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') @pclose(@popen("start /B \"\" \"$php_bin\" \"$script\"", "r"));
                else @exec("nohup \"$php_bin\" \"$script\" > /dev/null 2>&1 &");
            }
        }

        // Local HTTP cURL fallback launcher
        $base_url = '';
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
            $root_web_path = rtrim(str_replace('\\', '/', str_replace($doc_root, '', dirname(__DIR__))), '/');
            $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $root_web_path;
        } else {
            try {
                $stmt_u = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'base_site_url'");
                $base_url = $stmt_u ? trim($stmt_u->fetchColumn() ?: '') : '';
            } catch (Exception $e) {}
        }

        if (!empty($base_url) && $campaign_id) {
            $stmt_c = $pdo->prepare("SELECT DISTINCT page_id, post_type FROM scheduled_posts WHERE campaign_id = ? AND status IN ('pending', 'failed', 'processing')");
            $stmt_c->execute([$campaign_id]);
            $chans = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
            foreach ($chans as $ch_row) {
                $p_id = $ch_row['page_id'];
                $uid = (strpos($ch_row['post_type'], 'Instagram') !== false) ? 'ig_' . $p_id : $p_id;
                $url = rtrim($base_url, '/') . "/run_worker.php?type=publish&page_id=" . urlencode($p_id) . "&user_id=" . urlencode($uid);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
                curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                @curl_exec($ch);
                @curl_close($ch);
            }
        }
    } catch (Exception $e) {}
}

if ($campaign_id) {
    // Single row actions (GET)
    if (isset($_GET['action'], $_GET['post_id'])) {
        $act     = $_GET['action'];
        $post_id = intval($_GET['post_id']);
        try {
            if ($act === 'delete') {
                $pdo->prepare("DELETE FROM scheduled_posts WHERE id = ? AND campaign_id = ? AND status IN ('pending','failed','checkpoint')")->execute([$post_id, $campaign_id]);
            } elseif ($act === 'retry') {
                $pdo->prepare("UPDATE scheduled_posts SET status='pending', retry_count=0, error_msg=NULL WHERE id = ? AND campaign_id = ? AND status IN ('failed','checkpoint','processing')")->execute([$post_id, $campaign_id]);
                trigger_campaign_publisher_worker($pdo, $campaign_id);
            }
        } catch (PDOException $e) { /* ignore */ }
        header("Location: campaign_detail.php?id=$campaign_id" . (isset($_GET['filter']) ? '&filter='.$_GET['filter'] : ''));
        exit;
    }

    // Retry all or Force Run (GET)
    if (isset($_GET['action'])) {
        $act = $_GET['action'];
        if ($act === 'retry_all' || $act === 'run_now') {
            try {
                $pdo->prepare("UPDATE scheduled_posts SET status='pending', retry_count=0, error_msg=NULL WHERE campaign_id = ? AND status IN ('failed','checkpoint','processing','pending')")->execute([$campaign_id]);
                trigger_campaign_publisher_worker($pdo, $campaign_id);
            } catch (PDOException $e) { /* ignore */ }
            header("Location: campaign_detail.php?id=$campaign_id");
            exit;
        }
    }

    // Bulk delete (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['post_ids'])) {
        $ids = array_map('intval', (array)$_POST['post_ids']);
        if (!empty($ids) && $_POST['bulk_action'] === 'delete') {
            $in = implode(',', array_fill(0, count($ids), '?'));
            try {
                $params = array_merge($ids, [$campaign_id]);
                $pdo->prepare("DELETE FROM scheduled_posts WHERE id IN ($in) AND campaign_id = ? AND status IN ('pending','failed','checkpoint','processing')")->execute($params);
            } catch (PDOException $e) { /* ignore */ }
        }
        header("Location: campaign_detail.php?id=$campaign_id");
        exit;
    }
}

// ── Now load the page ──────────────────────────────────────────────────────
$current_page = 'manage_posts';
require_once __DIR__ . '/includes/header.php';

$account_id  = $_SESSION['account_id'];
$is_admin    = ($_SESSION['role'] === 'admin');
$campaign_id = intval($_GET['id'] ?? 0);

if (!$campaign_id) {
    header('Location: manage_posts.php');
    exit;
}

// Load campaign info
$campaign = null;
try {
    $auth_where  = ' AND account_id = ?';
    $auth_params = [$campaign_id, $account_id];
    $c_stmt = $pdo->prepare("SELECT * FROM post_campaigns WHERE id = ? $auth_where");
    $c_stmt->execute($auth_params);
    $campaign = $c_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

if (!$campaign) {
    echo "<div class='page-title'>Không tìm thấy chiến dịch</div>";
    echo "<div class='card' style='text-align:center;padding:40px;'>Chiến dịch không tồn tại. <a href='manage_posts.php'>← Quay lại</a></div>";
    include 'includes/footer.php';
    exit;
}

// Filter
$filter = $_GET['filter'] ?? 'all';
if ($filter === 'published')     $filter_sql = "AND sp.status = 'published'";
elseif ($filter === 'pending')   $filter_sql = "AND sp.status IN ('pending','processing')";
elseif ($filter === 'failed')    $filter_sql = "AND sp.status IN ('failed','checkpoint')";
else                             $filter_sql = '';

// Pagination
$per_page = 50;
$current_pg = max(1, intval($_GET['pg'] ?? 1));
$offset = ($current_pg - 1) * $per_page;

// Count total for pagination
$total_filtered = 0;
try {
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts sp WHERE sp.campaign_id = ? $filter_sql");
    $cnt_stmt->execute([$campaign_id]);
    $total_filtered = (int)$cnt_stmt->fetchColumn();
} catch (PDOException $e) {}
$total_pgs = max(1, ceil($total_filtered / $per_page));

// Posts
$posts = [];
try {
    $posts_stmt = $pdo->prepare("
        SELECT sp.*, 
               p.name AS page_name,
               p.avatar AS page_avatar,
               COALESCE(yt1.channel_title, yt2.channel_title) AS yt_channel_name,
               COALESCE(yt1.channel_avatar, yt2.channel_avatar) AS yt_channel_avatar,
               bc.channel_name AS buffer_channel_name,
               bc.avatar AS buffer_avatar,
               bc.service AS buffer_service,
               tt.display_name AS tt_channel_name,
               tt.avatar AS tt_channel_avatar,
               ig.username AS ig_username,
               ig.name AS ig_name,
               ig.avatar AS ig_avatar
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id AND sp.post_type NOT LIKE 'Buffer%' AND sp.post_type != 'YouTube' AND sp.post_type != 'TikTok' AND sp.post_type NOT LIKE 'Instagram%'
        LEFT JOIN instagram_accounts ig ON (sp.page_id = ig.ig_user_id OR sp.page_id = ig.id) AND sp.post_type LIKE 'Instagram%'
        LEFT JOIN youtube_channels yt1 ON sp.page_id = yt1.channel_id AND sp.post_type = 'YouTube'
        LEFT JOIN youtube_channels yt2 ON sp.page_id = yt2.id AND sp.post_type = 'YouTube'
        LEFT JOIN buffer_channels bc ON sp.page_id = bc.channel_id AND sp.post_type LIKE 'Buffer%'
        LEFT JOIN tiktok_accounts tt ON sp.page_id = tt.id AND sp.post_type = 'TikTok'
        WHERE sp.campaign_id = ? $filter_sql
        ORDER BY sp.scheduled_time ASC, sp.id ASC
        LIMIT $per_page OFFSET $offset
    ");
    $posts_stmt->execute([$campaign_id]);
    $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $posts_stmt = $pdo->prepare("
            SELECT sp.*, p.name AS page_name, p.avatar AS page_avatar
            FROM scheduled_posts sp
            LEFT JOIN pages p ON sp.page_id = p.page_id
            WHERE sp.campaign_id = ? $filter_sql
            ORDER BY sp.scheduled_time ASC, sp.id ASC
            LIMIT $per_page OFFSET $offset
        ");
        $posts_stmt->execute([$campaign_id]);
        $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {}
}

// Detect if comment_status column exists (fault-tolerant, using fast query cache)
$has_comment_status_col = false;
try {
    $pdo->query("SELECT comment_status FROM scheduled_posts LIMIT 1");
    $has_comment_status_col = true;
} catch (Exception $e) {}


$stats = ['pub' => 0, 'pend' => 0, 'proc' => 0, 'fail' => 0, 'total' => 0];
try {
    $st = $pdo->prepare("SELECT
        SUM(CASE WHEN status='published'  THEN 1 ELSE 0 END) AS pub,
        SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) AS pend,
        SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS proc,
        SUM(CASE WHEN status='failed'     THEN 1 ELSE 0 END) AS fail,
        SUM(CASE WHEN status='checkpoint' THEN 1 ELSE 0 END) AS chk,
        COUNT(*) AS total
        FROM scheduled_posts WHERE campaign_id = ?");
    $st->execute([$campaign_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $stats = $row;
} catch (PDOException $e) {}

$progress = $stats['total'] > 0 ? round($stats['pub'] / $stats['total'] * 100) : 0;

function status_bg($s) {
    if ($s === 'published')  return '#d1fae5';
    if ($s === 'pending')    return '#fef3c7';
    if ($s === 'processing') return '#e0f2fe';
    if ($s === 'failed')     return '#fee2e2';
    if ($s === 'checkpoint') return '#fee2e2';
    return '#f3f4f6';
}
function status_tc($s) {
    if ($s === 'published')  return '#065f46';
    if ($s === 'pending')    return '#d97706';
    if ($s === 'processing') return '#0369a1';
    if ($s === 'failed')     return '#dc2626';
    if ($s === 'checkpoint') return '#991b1b';
    return '#6b7280';
}
function status_label($s) {
    if ($s === 'published')  return '✅ Đã đăng';
    if ($s === 'pending')    return '⏳ Chờ';
    if ($s === 'processing') return '🔄 Đang đăng';
    if ($s === 'failed')     return '❌ Lỗi';
    if ($s === 'checkpoint') return '🚫 Tài khoản bị checkpoint';
    return htmlspecialchars($s);
}
?>

<div class="page-title">
    <a href="manage_posts.php" style="color:var(--text-muted);text-decoration:none;font-size:14px;font-weight:400;">← Campaigns</a>
    <span style="margin:0 8px;color:var(--text-muted);">/</span>
    <?php echo htmlspecialchars($campaign['name']); ?>
</div>

<!-- Campaign Summary Card -->
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">
        <div style="flex:1;min-width:220px;">
            <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px;">Tạo lúc <?php echo date('H:i d/m/Y', strtotime($campaign['created_at'])); ?></div>
            <?php if (!empty($campaign['scheduled_time'])): ?>
            <div style="font-size:13px;color:var(--text-muted);">Hẹn giờ: <?php echo date('H:i d/m/Y', strtotime($campaign['scheduled_time'])); ?></div>
            <?php endif; ?>
            <div style="margin-top:14px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;">
                    <span>Tiến độ đăng bài</span>
                    <strong><?php echo (int)$stats['pub']; ?>/<?php echo (int)$stats['total']; ?></strong>
                </div>
                <div style="height:10px;background:#f3f4f6;border-radius:99px;overflow:hidden;">
                    <div style="height:100%;width:<?php echo $progress; ?>%;background:<?php echo $progress==100?'#10b981':'var(--primary-color)'; ?>;border-radius:99px;"></div>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <?php foreach ([
                ['Đã đăng',  (int)$stats['pub'],  '#d1fae5','#065f46'],
                ['Đang chờ', (int)$stats['pend']+(int)$stats['proc'], '#fef3c7','#d97706'],
                ['Checkpoint/Lỗi', (int)$stats['fail']+(int)($stats['chk'] ?? 0), '#fee2e2','#dc2626'],
            ] as $item):
                list($lbl, $cnt, $bg, $tc) = $item; ?>
            <div style="background:<?php echo $bg; ?>;padding:12px 20px;border-radius:8px;text-align:center;min-width:80px;">
                <div style="font-size:22px;font-weight:700;color:<?php echo $tc; ?>;"><?php echo $cnt; ?></div>
                <div style="font-size:11px;color:<?php echo $tc; ?>;margin-top:2px;"><?php echo $lbl; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;align-items:center;">
        <?php if ((int)$stats['fail'] > 0 || (int)($stats['chk'] ?? 0) > 0 || (int)$stats['proc'] > 0): ?>
        <button onclick="showCampaignModal('retry_all', <?php echo $campaign_id; ?>, 'Thử lại tất cả bài kẹt/lỗi trong chiến dịch này?', false)" style="padding:8px 16px;background:#3b82f6;color:white;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:500;">🔄 Reset/Thử Lại Tất Cả</button>
        <?php endif; ?>
        <?php if ((int)$stats['pend'] > 0 || (int)$stats['proc'] > 0 || (int)$stats['fail'] > 0 || (int)($stats['chk'] ?? 0) > 0): ?>
        <button onclick="showCampaignModal('delete_pending', <?php echo $campaign_id; ?>, 'Xóa toàn bộ bài chưa hoàn tất trong chiến dịch này?', true)" style="padding:8px 16px;background:#fee2e2;color:#dc2626;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:500;">🗑 Xóa bài chưa/lỗi</button>
        <?php endif; ?>
        <?php if ((int)$stats['total'] === 0): ?>
        <button onclick="showCampaignModal('delete_campaign_empty', <?php echo $campaign_id; ?>, 'Xóa chiến dịch trống này?', true)" style="padding:8px 16px;background:#fee2e2;color:#dc2626;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:500;">🗑 Xóa Campaign</button>
        <?php endif ?>
    </div>
</div>

<!-- Filter Tabs -->
<div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
    <?php foreach ([
        ['all',       'Tất cả',     (int)$stats['total']],
        ['published', '✅ Đã đăng', (int)$stats['pub']],
        ['pending',   '⏳ Đang chờ',(int)$stats['pend']+(int)$stats['proc']],
        ['failed',    '❌ Bị dừng/Lỗi', (int)$stats['fail']+(int)($stats['chk'] ?? 0)],
    ] as $tab):
        list($val, $lbl, $cnt) = $tab;
        $active = ($filter === $val); ?>
    <a href="campaign_detail.php?id=<?php echo $campaign_id; ?>&filter=<?php echo $val; ?>"
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

<!-- Posts Table with Bulk Delete -->
<div class="card" style="padding:0;overflow-x:auto;">
    <?php if (empty($posts)): ?>
    <div style="text-align:center;padding:40px;color:var(--text-muted);">Không có bài viết nào với bộ lọc này.</div>
    <?php else: ?>
    <form id="bulkDeleteForm" method="POST" action="campaign_detail.php?id=<?php echo $campaign_id; ?>">
        <input type="hidden" name="bulk_action" value="delete">
        <!-- Bulk toolbar -->
        <div style="padding:10px 16px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:10px;background:#fafafa;">
            <label style="font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                <input type="checkbox" id="selectAll" onchange="toggleAll(this)"> Chọn tất cả
            </label>
            <button type="submit" onclick="return confirmBulk()"
                    style="padding:5px 14px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:12px;">
                🗑 Xóa đã chọn
            </button>
        </div>
        <table style="width:100%;border-collapse:collapse;min-width:750px;">
            <thead>
                <tr style="background:var(--card-bg);border-bottom:2px solid var(--border-color);">
                    <th style="width:36px;padding:10px 12px;"></th>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;width:300px;max-width:300px;">Fanpage</th>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Loại</th>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Thời gian hẹn</th>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Trạng thái</th>
                    <?php if ($has_comment_status_col): ?>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">💬 Bình luận</th>
                    <?php endif; ?>
                    <th style="text-align:left;padding:10px 16px;font-size:13px;color:var(--text-muted);font-weight:500;">Hành động</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $post):
                $s = $post['status'];
                $content_data = @json_decode($post['content'], true);
                $desc = is_array($content_data) ? ($content_data['description'] ?? $content_data['text'] ?? '') : $post['content'];
                $desc_short = mb_strimwidth($desc, 0, 80, '…');
                $original_source = is_array($content_data) ? ($content_data['original_source'] ?? '') : '';
                if (empty($original_source) && !empty($post['media_path'])) {
                    $mp = $post['media_path'];
                    if (strpos($mp, 'tiktok:') === 0) {
                        $original_source = substr($mp, 7);
                    } elseif (strpos($mp, 'folder:') === 0) {
                        $original_source = 'Google Drive Folder';
                    } elseif (strpos($mp, 'drive:') === 0) {
                        $original_source = 'Google Drive File';
                    } elseif (strpos($mp, 'uploads/') !== false) {
                        $decoded_mp = @json_decode($mp, true);
                        if (is_array($decoded_mp)) {
                            $original_source = implode(', ', array_map('basename', $decoded_mp));
                        } else {
                            $original_source = basename($mp);
                        }
                    }
                }
                if (strpos($original_source, 'Google Drive Folder') === 0) {
                    $original_source = 'Google Drive Folder';
                } elseif (strpos($original_source, 'Google Drive File') === 0) {
                    $original_source = 'Google Drive File';
                }
            ?>
            <tr style="border-bottom:1px solid var(--border-color);">
                <td style="padding:10px 12px;text-align:center;">
                    <?php if (in_array($s, ['pending','failed','checkpoint'])): ?>
                    <input type="checkbox" name="post_ids[]" value="<?php echo $post['id']; ?>" class="row-check">
                    <?php endif; ?>
                </td>
                <td style="padding:10px 16px;width:300px;max-width:300px;">
                    <?php 
                        $is_buffer    = strpos($post['post_type'], 'Buffer') !== false;
                        $is_youtube   = strpos($post['post_type'], 'YouTube') !== false;
                        $is_tiktok    = strpos($post['post_type'], 'TikTok') !== false;
                        $is_instagram = strpos($post['post_type'], 'Instagram') !== false;
                        
                        if ($is_buffer) {
                            $disp_name = !empty($post['buffer_channel_name']) ? $post['buffer_channel_name'] : ($post['page_id'] ?? '—');
                            $disp_avatar = $post['buffer_avatar'] ?? null;
                        } elseif ($is_youtube) {
                            $disp_name = !empty($post['yt_channel_name']) ? $post['yt_channel_name'] : '—';
                            $disp_avatar = !empty($post['yt_channel_avatar']) ? $post['yt_channel_avatar'] : null;
                        } elseif ($is_tiktok) {
                            $disp_name = !empty($post['tt_channel_name']) ? $post['tt_channel_name'] : '—';
                            $disp_avatar = !empty($post['tt_channel_avatar']) ? $post['tt_channel_avatar'] : null;
                        } elseif ($is_instagram) {
                            $disp_name = !empty($post['ig_username']) ? ('@' . $post['ig_username']) : (!empty($post['page_name']) ? $post['page_name'] : '—');
                            $disp_avatar = !empty($post['ig_avatar']) ? $post['ig_avatar'] : ($post['page_avatar'] ?? null);
                        } else {
                            $disp_name = !empty($post['page_name']) ? $post['page_name'] : '—';
                            $disp_avatar = $post['page_avatar'] ?? null;
                        }
                        $disp_desc = !empty($original_source) ? $original_source : $desc;
                    ?>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <?php if (!empty($disp_avatar)): ?>
                            <img src="<?php echo htmlspecialchars($disp_avatar); ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">
                        <?php else: ?>
                            <div style="width:28px;height:28px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#64748b;font-weight:bold;flex-shrink:0;">
                                <?php echo mb_strtoupper(mb_substr($disp_name, 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div style="min-width:0;flex:1;">
                            <div style="font-weight:500;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($disp_name); ?>">
                                <?php echo htmlspecialchars($disp_name); ?>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;" title="<?php echo htmlspecialchars($disp_desc); ?>">
                                <?php 
                                    if (!empty($original_source)) {
                                        echo htmlspecialchars($original_source);
                                    } else {
                                        echo htmlspecialchars($desc_short);
                                    }
                                ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($s !== 'published' && !empty($post['error_msg'])): ?>
                    <div style="font-size:11px;color:#dc2626;margin-top:6px;background:#fee2e2;padding:6px 8px;border-radius:4px;word-break:break-all;line-height:1.4;">
                        <strong>Log lỗi:</strong> <?php echo htmlspecialchars($post['error_msg']); ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="padding:10px 16px;"><span style="background:#f3f4f6;color:#374151;font-size:12px;padding:2px 8px;border-radius:4px;"><?php echo htmlspecialchars($post['post_type']); ?></span></td>
                <td style="padding:10px 16px;font-size:12px;color:var(--text-muted);white-space:nowrap;"><?php echo date('H:i d/m/Y', strtotime($post['scheduled_time'])); ?></td>
                <td style="padding:10px 16px;">
                    <span style="background:<?php echo status_bg($s); ?>;color:<?php echo status_tc($s); ?>;font-size:12px;padding:3px 10px;border-radius:99px;font-weight:500;"><?php echo status_label($s); ?></span>
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
                    } elseif ($cs === 'expired_insights') {
                        $cs_bg = '#f3f4f6'; $cs_tc = '#6b7280'; $cs_label = '💬 Không đủ ĐK';
                    } elseif ($cs === 'pending') {
                        $cs_bg = '#fef3c7'; $cs_tc = '#d97706'; $cs_label = '⏳ Chờ BL';
                    } else {
                        // comment_lines set but not yet processed (post still pending/failed)
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
                            if (strpos($view_id, 'http://') === 0 || strpos($view_id, 'https://') === 0) {
                                $clean_u = explode('#', $view_id)[0];
                                $clean_u = explode('|', $clean_u)[0];
                                $view_url = htmlspecialchars($clean_u);
                            } elseif (strpos($post['post_type'], 'Buffer') !== false) {
                                $svc = strtolower($post['buffer_service'] ?? '');
                                $cname = ltrim(trim($post['buffer_channel_name'] ?? ''), '@');
                                
                                if (strpos($svc, 'instagram') !== false || strpos(strtolower($post['post_type']), 'instagram') !== false) {
                                    $view_url = !empty($cname) ? "https://www.instagram.com/" . htmlspecialchars($cname) . "/" : "https://www.instagram.com/";
                                } elseif (strpos($svc, 'tiktok') !== false) {
                                    $view_url = !empty($cname) ? "https://www.tiktok.com/@" . htmlspecialchars($cname) : "https://www.tiktok.com/";
                                } elseif (strpos($svc, 'threads') !== false) {
                                    $view_url = !empty($cname) ? "https://www.threads.net/@" . htmlspecialchars($cname) : "https://www.threads.net/";
                                } elseif (strpos($svc, 'pinterest') !== false) {
                                    $view_url = !empty($cname) ? "https://www.pinterest.com/" . htmlspecialchars($cname) . "/" : "https://www.pinterest.com/";
                                } elseif (strpos($svc, 'twitter') !== false || $svc === 'x') {
                                    $view_url = !empty($cname) ? "https://x.com/" . htmlspecialchars($cname) : "https://x.com/";
                                } elseif (strpos($svc, 'youtube') !== false) {
                                    $view_url = !empty($cname) ? "https://www.youtube.com/@" . htmlspecialchars($cname) : "https://www.youtube.com/";
                                } else {
                                    $view_url = "https://publish.buffer.com/channels/" . htmlspecialchars($post['page_id']) . "/schedule?tab=sent";
                                }
                            } elseif ($post['post_type'] === 'YouTube') {
                                $view_url = "https://youtube.com/watch?v=" . htmlspecialchars($view_id);
                            } elseif ($is_instagram) {
                                $ig_uname = !empty($post['ig_username']) ? ltrim(trim($post['ig_username']), '@') : '';
                                if (!empty($ig_uname)) {
                                    $view_url = "https://www.instagram.com/" . htmlspecialchars($ig_uname) . "/";
                                } else {
                                    $view_url = "https://www.instagram.com/";
                                }
                            } else {
                                $view_url = "https://facebook.com/" . htmlspecialchars($view_id);
                            }
                    ?>
                        <a href="<?php echo $view_url; ?>" target="_blank"
                           style="font-size:12px;color:var(--primary-color);text-decoration:none;padding:3px 9px;border:1px solid #c7d2fe;border-radius:4px;background:#eef2ff;">Xem</a>
                    <?php endif; ?>
                    <?php if (in_array($s, ['pending','failed','checkpoint'])): ?>
                        <a href="#"
                           onclick="showSingleDeleteModal(<?php echo $post['id']; ?>, '<?php echo $filter; ?>'); return false;"
                           style="font-size:12px;color:#dc2626;text-decoration:none;padding:3px 9px;border:1px solid #fca5a5;border-radius:4px;">Xóa</a>
                    <?php endif; ?>
                    <?php if (in_array($s, ['failed','checkpoint'])): ?>
                        <a href="campaign_detail.php?id=<?php echo $campaign_id; ?>&action=retry&post_id=<?php echo $post['id']; ?>&filter=<?php echo $filter; ?>"
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

<?php if ($total_pgs > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
    <?php
    $base_url = "campaign_detail.php?id=$campaign_id&filter=$filter";
    if ($current_pg > 1): ?>
        <a href="<?php echo $base_url . '&pg=' . ($current_pg - 1); ?>" style="padding:6px 12px;border:1px solid var(--border-color);border-radius:4px;text-decoration:none;color:var(--text-main);font-size:13px;">← Trước</a>
    <?php endif; ?>
    <?php
    $start_pg = max(1, $current_pg - 3);
    $end_pg = min($total_pgs, $current_pg + 3);
    for ($i = $start_pg; $i <= $end_pg; $i++): ?>
        <a href="<?php echo $base_url . '&pg=' . $i; ?>"
           style="padding:6px 12px;border:1px solid <?php echo $i==$current_pg?'var(--primary-color)':'var(--border-color)'; ?>;border-radius:4px;text-decoration:none;color:<?php echo $i==$current_pg?'var(--primary-color)':'var(--text-main)'; ?>;font-weight:<?php echo $i==$current_pg?'bold':'normal'; ?>;font-size:13px;"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($current_pg < $total_pgs): ?>
        <a href="<?php echo $base_url . '&pg=' . ($current_pg + 1); ?>" style="padding:6px 12px;border:1px solid var(--border-color);border-radius:4px;text-decoration:none;color:var(--text-main);font-size:13px;">Sau →</a>
    <?php endif; ?>
    <span style="padding:6px 8px;font-size:12px;color:var(--text-muted);">(<?php echo number_format($total_filtered); ?> bài · Trang <?php echo $current_pg; ?>/<?php echo $total_pgs; ?>)</span>
</div>
<?php endif; ?>

<!-- Confirm Modal -->
<div id="cdConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);text-align:center;">
        <div id="cdModalIcon" style="font-size:40px;margin-bottom:12px;">⚠️</div>
        <h3 id="cdModalTitle" style="margin:0 0 10px;font-size:17px;color:#111;"></h3>
        <p id="cdModalMsg" style="margin:0 0 24px;font-size:14px;color:#555;"></p>
        <div style="display:flex;gap:12px;justify-content:center;">
            <button onclick="hideCdModal()" style="padding:9px 24px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;cursor:pointer;font-size:14px;">Huỷ</button>
            <button id="cdModalOkBtn" onclick="doCdAction()" style="padding:9px 24px;border:none;border-radius:8px;background:#ef4444;color:#fff;cursor:pointer;font-size:14px;font-weight:600;">Xác nhận</button>
        </div>
    </div>
</div>

<script>
let _cdAction = null;
let _cdId = null;
let _cdExtra = null;

function showCampaignModal(action, id, msg, isDanger) {
    _cdAction = action;
    _cdId = id;
    _cdExtra = null;
    document.getElementById('cdModalIcon').textContent = isDanger ? '🗑️' : '🔄';
    document.getElementById('cdModalTitle').textContent = isDanger ? 'Xác nhận xóa' : 'Xác nhận thử lại';
    document.getElementById('cdModalMsg').textContent = msg;
    document.getElementById('cdModalOkBtn').style.background = isDanger ? '#ef4444' : '#10b981';
    document.getElementById('cdConfirmModal').style.display = 'flex';
}

function showSingleDeleteModal(postId, filter) {
    _cdAction = 'delete_single';
    _cdId = postId;
    _cdExtra = filter;
    document.getElementById('cdModalIcon').textContent = '🗑️';
    document.getElementById('cdModalTitle').textContent = 'Xác nhận xóa';
    document.getElementById('cdModalMsg').textContent = 'Xóa bài đăng này?';
    document.getElementById('cdModalOkBtn').style.background = '#ef4444';
    document.getElementById('cdConfirmModal').style.display = 'flex';
}

function showBulkDeleteModal(checked) {
    _cdAction = 'bulk_delete';
    _cdId = null;
    _cdExtra = checked;
    document.getElementById('cdModalIcon').textContent = '🗑️';
    document.getElementById('cdModalTitle').textContent = 'Xác nhận xóa';
    document.getElementById('cdModalMsg').textContent = 'Xóa ' + checked + ' bài đã chọn?';
    document.getElementById('cdModalOkBtn').style.background = '#ef4444';
    document.getElementById('cdConfirmModal').style.display = 'flex';
}

function hideCdModal() {
    document.getElementById('cdConfirmModal').style.display = 'none';
    _cdAction = null; _cdId = null; _cdExtra = null;
}

function doCdAction() {
    const cid = <?php echo $campaign_id; ?>;
    if (_cdAction === 'retry_all') {
        window.location.href = 'campaign_detail.php?id=' + cid + '&action=retry_all';
    } else if (_cdAction === 'delete_pending') {
        window.location.href = 'manage_posts.php?action=delete_campaign&id=' + cid;
    } else if (_cdAction === 'delete_campaign_empty') {
        window.location.href = 'manage_posts.php?action=delete_campaign&id=' + cid;
    } else if (_cdAction === 'delete_single') {
        window.location.href = 'campaign_detail.php?id=' + cid + '&action=delete&post_id=' + _cdId + '&filter=' + (_cdExtra || 'all');
    } else if (_cdAction === 'bulk_delete') {
        document.getElementById('cdConfirmModal').style.display = 'none';
        document.getElementById('bulkDeleteForm').submit();
    }
}

document.getElementById('cdConfirmModal').addEventListener('click', function(e) {
    if (e.target === this) hideCdModal();
});

function toggleAll(cb) {
    document.querySelectorAll('.row-check').forEach(function(el) { el.checked = cb.checked; });
}
function confirmBulk() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    if (checked === 0) { alert('Chưa chọn bài nào!'); return false; }
    showBulkDeleteModal(checked);
    return false;
}

// Keep scroll position on reload
document.addEventListener("DOMContentLoaded", function() { 
    const key = 'scrollpos_' + window.location.search;
    if (sessionStorage.getItem(key)) window.scrollTo(0, sessionStorage.getItem(key));
});
window.addEventListener("beforeunload", function() {
    sessionStorage.setItem('scrollpos_' + window.location.search, window.scrollY);
});

// Auto-reload if posts are pending/processing
<?php if (((int)$stats['pend'] + (int)$stats['proc']) > 0): ?>
setTimeout(function() { window.location.reload(); }, 15000);
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
