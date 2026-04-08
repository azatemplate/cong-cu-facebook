<?php
$current_page = 'insights';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Fetch all users (owners of available pages)
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.name 
    FROM users u 
    LEFT JOIN pages p ON u.id = p.user_id 
    LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
    WHERE u.account_id = :aid OR ps.shared_with_account_id = :aid2
    ORDER BY u.name ASC
");
$stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch pages for JS dropdown — NO access_token (security: don't expose tokens to frontend)
$stmt2 = $pdo->prepare("
    (SELECT p.id, p.page_id, p.name, p.user_id
     FROM pages p JOIN users u ON p.user_id = u.id
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.id, p.page_id, p.name, u.id as user_id
     FROM pages p
     JOIN page_shares ps ON p.page_id = ps.page_id
     JOIN users u ON p.user_id = u.id
     WHERE ps.shared_with_account_id = :aid2)
    ORDER BY name ASC
");
$stmt2->bindValue(':aid',  $account_id, PDO::PARAM_INT);
$stmt2->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt2->execute();
$pages = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// pages_json for JS — safe, no tokens
$pages_json = json_encode($pages);

$selected_page_id = isset($_GET['page_id']) ? $_GET['page_id'] : '';
$insights_data = null;
$error_msg = null;

// Date Filter Logic - Fixed to days_28 as per index.php config
$period = 'days_28';

$end_date = date('Y-m-d');
if ($period === 'day') {
    $start_date = date('Y-m-d', strtotime('-1 days'));
} elseif ($period === 'week') {
    $start_date = date('Y-m-d', strtotime('-7 days'));
} elseif ($period === 'days_28' || $period === 'month') {
    $start_date = date('Y-m-d', strtotime('-30 days'));
} elseif ($period === 'lifetime') {
    $start_date = date('Y-m-d', strtotime('-90 days')); // Graph API allows up to 93 days normally for insights if no lifetime metric is specific
}

if ($selected_page_id) {
    // Query token directly for this page only (not from JS pages array — security)
    $page_token = '';
    $page_name  = '';
    $t_stmt = $pdo->prepare("
        SELECT p.page_id, p.name, p.access_token FROM pages p
        JOIN users u ON p.user_id = u.id
        WHERE p.page_id = ?
        LIMIT 1
    ");
    $t_stmt->execute([$selected_page_id]);
    $t_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
    if ($t_row) {
        $page_token = decryptData($t_row['access_token']);
        $page_name  = $t_row['name'];
    }

    if ($page_token) {
        // Session cache: avoid hitting FB API on every page load (30 min TTL)
        $cache_key    = "insights_{$selected_page_id}_{$period}";
        $cache_key_engagement = "engagement_{$selected_page_id}_{$period}";
        $insights_data = null;
        $engagement_data = null;

        // Clear cache if refresh requested
        if (isset($_GET['nocache'])) {
            unset($_SESSION[$cache_key]);
            unset($_SESSION[$cache_key_engagement]);
        }

        if (isset($_SESSION[$cache_key]) && $_SESSION[$cache_key]['expires'] > time()) {
            $insights_data = $_SESSION[$cache_key]['data'];
        } else {
            $api_response = get_fb_page_insights($selected_page_id, $page_token, $period, $start_date, $end_date);
            if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
                $insights_data = $api_response['data']['data'];
                $_SESSION[$cache_key] = ['data' => $insights_data, 'expires' => time() + 1800];
            } else {
                $error_msg = "Error #" . ($api_response['data']['error']['code'] ?? 'Unknown') . ": "
                           . ($api_response['data']['error']['message'] ?? 'API Failed')
                           . " | Raw: " . json_encode($api_response['data']);
            }
        }

        // Fetch engagement data (likes + comments) from posts feed
        if (isset($_SESSION[$cache_key_engagement]) && $_SESSION[$cache_key_engagement]['expires'] > time()) {
            $engagement_data = $_SESSION[$cache_key_engagement]['data'];
        } else {
            $engagement_data = ['daily_likes' => [], 'daily_comments' => [], 'total_likes' => 0, 'total_comments' => 0];
            $feed_url = "https://graph.facebook.com/v25.0/{$selected_page_id}/published_posts"
                      . "?fields=" . urlencode('created_time,reactions.summary(true).limit(0),comments.summary(true).limit(0)')
                      . "&since={$start_date}&until={$end_date}"
                      . "&limit=100"
                      . "&access_token=" . urlencode($page_token);

            $all_posts_data = [];
            $fetch_url = $feed_url;
            $fetch_attempts = 0;
            while ($fetch_url && $fetch_attempts < 5) {
                $ch = curl_init($fetch_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $raw = curl_exec($ch);
                curl_close($ch);
                $json = $raw ? json_decode($raw, true) : [];
                if (!empty($json['data'])) {
                    $all_posts_data = array_merge($all_posts_data, $json['data']);
                }
                $fetch_url = $json['paging']['next'] ?? null;
                $fetch_attempts++;
            }

            // Aggregate by day
            foreach ($all_posts_data as $p) {
                $day = date('Y-m-d', strtotime($p['created_time']));
                $likes = (int)($p['reactions']['summary']['total_count'] ?? 0);
                $comments = (int)($p['comments']['summary']['total_count'] ?? 0);
                if (!isset($engagement_data['daily_likes'][$day])) $engagement_data['daily_likes'][$day] = 0;
                if (!isset($engagement_data['daily_comments'][$day])) $engagement_data['daily_comments'][$day] = 0;
                $engagement_data['daily_likes'][$day] += $likes;
                $engagement_data['daily_comments'][$day] += $comments;
                $engagement_data['total_likes'] += $likes;
                $engagement_data['total_comments'] += $comments;
            }
            $_SESSION[$cache_key_engagement] = ['data' => $engagement_data, 'expires' => time() + 1800];
        }
    } else {
        $error_msg = "Không tìm thấy Token hoặc bạn không có quyền sở hữu Fanpage này.";
    }
} else {
    // ---- Display Growth For ALL Pages ----
    // Data is now loaded async via JS calling actions/ajax_insights_all.php
}
?>

    <div class="page-title" style="margin-bottom: 20px;">Page Insights</div>

<?php if ($selected_page_id): ?>
    <div style="margin-bottom: 20px;">
        <a href="insights.php" class="btn" style="background: white; border: 1px solid var(--border-color); color: var(--text-main); font-weight: 500; font-size: 13px; text-decoration: none;"><span style="margin-right: 5px;">←</span> Quay Lại Bảng Tăng Trưởng</a>
    </div>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
<?php endif; ?>

<?php if ($insights_data): ?>
    <h3 style="margin-bottom: 20px;">Kết quả Insights: <?php echo htmlspecialchars($page_name); ?></h3>
    <?php
    $chart_labels = [];
    $chart_views = [];
    $chart_likes = [];
    $chart_comments = [];
    
    $total_views = 0;
    $total_likes = $engagement_data['total_likes'] ?? 0;
    $total_comments = $engagement_data['total_comments'] ?? 0;
    $daily_likes = $engagement_data['daily_likes'] ?? [];
    $daily_comments = $engagement_data['daily_comments'] ?? [];
    
    foreach ($insights_data as $metric) {
        if ($metric['name'] === 'page_media_view') {
            if (isset($metric['values']) && is_array($metric['values']) && count($metric['values']) > 0) {
                $latest_val = end($metric['values']);
                $total_views += isset($latest_val['value']) ? intval($latest_val['value']) : 0;
                
                foreach ($metric['values'] as $v) {
                    if (isset($v['end_time'])) {
                        $date_key = date('Y-m-d', strtotime($v['end_time']));
                        $date_label = date('m-d', strtotime($v['end_time']));
                        if (!in_array($date_label, $chart_labels)) {
                            $chart_labels[] = $date_label;
                        }
                        $chart_views[] = isset($v['value']) ? intval($v['value']) : 0;
                        $chart_likes[] = $daily_likes[$date_key] ?? 0;
                        $chart_comments[] = $daily_comments[$date_key] ?? 0;
                    }
                }
            }
        }
    }
    
    // Fallback if empty
    if (empty($chart_labels)) {
        for ($i=6; $i>=0; $i--) {
            $chart_labels[] = date('m-d', strtotime("-$i days"));
            $chart_views[] = 0;
            $chart_likes[] = 0;
            $chart_comments[] = 0;
        }
    }
    ?>

    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="stat-card">
            <div class="stat-title">Total Media Views</div>
            <div class="stat-value color-primary" style="display:flex; align-items:center;">
                <span class="icon" style="margin-right:10px;">▶</span> <?php echo number_format($total_views); ?>
            </div>
            <div class="stat-subtitle">Tổng lượt xem media</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-title">Total Likes</div>
            <div class="stat-value" style="display:flex; align-items:center; color:#3b82f6;">
                <span class="icon" style="margin-right:10px;">👍</span> <?php echo number_format($total_likes); ?>
            </div>
            <div class="stat-subtitle">Tổng lượt thích (reactions)</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-title">Total Comments</div>
            <div class="stat-value" style="display:flex; align-items:center; color:#10b981;">
                <span class="icon" style="margin-right:10px;">💬</span> <?php echo number_format($total_comments); ?>
            </div>
            <div class="stat-subtitle">Tổng lượt bình luận</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-title">Fanpage</div>
            <div class="stat-value color-green" style="display:flex; align-items:center; font-size: 16px;">
                <span class="icon" style="margin-right:10px;">✅</span> Online
            </div>
            <div class="stat-subtitle"><?php echo htmlspecialchars($page_name); ?></div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 25px;">
        <h3 style="margin-bottom: 5px;">Insights Growth Chart (<?php echo htmlspecialchars($period); ?>)</h3>
        <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 20px;">Dữ liệu biểu đồ được truy xuất trực tiếp từ Facebook Graph API. Views = Page Insights, Likes/Comments = Tổng hợp từ bài đăng.</div>
        
        <div style="height: 400px; position: relative; width: 100%;">
            <canvas id="insightsChart"></canvas>
        </div>
        <div style="display:flex; justify-content:center; gap:20px; margin-top: 15px; font-size: 13px; font-weight: 500; flex-wrap:wrap;">
            <span style="color: #ef4444; cursor:pointer;" onclick="toggleDataset(0)">● Media Views</span>
            <span style="color: #3b82f6; cursor:pointer;" onclick="toggleDataset(1)">● Likes</span>
            <span style="color: #10b981; cursor:pointer;" onclick="toggleDataset(2)">● Comments</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    let insightsChartInstance = null;
    function toggleDataset(idx) {
        if (!insightsChartInstance) return;
        const meta = insightsChartInstance.getDatasetMeta(idx);
        meta.hidden = !meta.hidden;
        insightsChartInstance.update();
    }
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('insightsChart').getContext('2d');
        
        const gradientViews = ctx.createLinearGradient(0, 0, 0, 400);
        gradientViews.addColorStop(0, 'rgba(239, 68, 68, 0.25)');
        gradientViews.addColorStop(1, 'rgba(239, 68, 68, 0)');

        const gradientLikes = ctx.createLinearGradient(0, 0, 0, 400);
        gradientLikes.addColorStop(0, 'rgba(59, 130, 246, 0.2)');
        gradientLikes.addColorStop(1, 'rgba(59, 130, 246, 0)');

        const gradientComments = ctx.createLinearGradient(0, 0, 0, 400);
        gradientComments.addColorStop(0, 'rgba(16, 185, 129, 0.2)');
        gradientComments.addColorStop(1, 'rgba(16, 185, 129, 0)');

        insightsChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    {
                        label: 'Media Views',
                        data: <?php echo json_encode($chart_views); ?>,
                        borderColor: '#ef4444',
                        backgroundColor: gradientViews,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#ef4444',
                        pointBorderColor: '#fff',
                        pointRadius: 3,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Likes',
                        data: <?php echo json_encode($chart_likes); ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: gradientLikes,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#fff',
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        yAxisID: 'y1'
                    },
                    {
                        label: 'Comments',
                        data: <?php echo json_encode($chart_comments); ?>,
                        borderColor: '#10b981',
                        backgroundColor: gradientComments,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#fff',
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(15,23,42,0.9)',
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                let val = ctx.parsed.y;
                                if (val >= 1000000) val = (val/1000000).toFixed(1) + 'M';
                                else if (val >= 1000) val = (val/1000).toFixed(1) + 'k';
                                return ' ' + ctx.dataset.label + ': ' + val;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(0,0,0,0)', drawBorder: false },
                        ticks: { color: '#6b7280', font: { size: 11 }, maxTicksLimit: 14 }
                    },
                    y: {
                        type: 'linear',
                        position: 'left',
                        grid: { color: '#e5e7eb', drawBorder: false, borderDash: [5, 5] },
                        title: { display: true, text: 'Views', color: '#ef4444', font: { size: 12, weight: 'bold' } },
                        ticks: { 
                            color: '#ef4444', 
                            font: { size: 11 },
                            callback: function(value) {
                                if (value >= 1000000) return value / 1000000 + 'M';
                                if (value >= 1000) return value / 1000 + 'k';
                                return value;
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'Likes / Comments', color: '#3b82f6', font: { size: 12, weight: 'bold' } },
                        ticks: { 
                            color: '#3b82f6', 
                            font: { size: 11 },
                            callback: function(value) {
                                if (value >= 1000000) return value / 1000000 + 'M';
                                if (value >= 1000) return value / 1000 + 'k';
                                return value;
                            }
                        }
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                }
            }
        });
    });
    </script>

<?php
// ── Fetch today's posts with video views ───────────────────────────────────
$today_posts = [];
$today_err   = '';
$today_str   = date('Y-m-d');           // e.g. 2026-03-25
$tomorrow_str = date('Y-m-d', strtotime('+1 day'));

if ($page_token) {
    $fields = 'created_time,message,attachments{media_type,url,media},reactions.summary(true).limit(0),comments.summary(true).limit(0),insights.metric(post_video_views,post_impressions_unique)';
    $tp_url = "https://graph.facebook.com/v25.0/{$selected_page_id}/posts"
            . "?fields=" . urlencode($fields)
            . "&since={$today_str}&until={$tomorrow_str}"
            . "&limit=50"
            . "&access_token=" . urlencode($page_token);

    $ch = curl_init($tp_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $raw = curl_exec($ch);
    curl_close($ch);

    $tp_json = $raw ? json_decode($raw, true) : [];
    if (isset($tp_json['data'])) {
        $today_posts = $tp_json['data'];
    } elseif (isset($tp_json['error'])) {
        $today_err = $tp_json['error']['message'] ?? 'API error';
    }
}

// Helper: get metric value from post insights
function get_post_metric($post, $metric_name) {
    if (!isset($post['insights']['data'])) return null;
    foreach ($post['insights']['data'] as $m) {
        if ($m['name'] === $metric_name) {
            return $m['values'][0]['value'] ?? null;
        }
    }
    return null;
}
?>

<!-- Today's posts section -->
<div class="card" style="margin-top:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
        <div>
            <h3 style="margin:0; font-size:16px;">📅 Bài đăng hôm nay</h3>
            <div style="font-size:12px; color:var(--text-muted); margin-top:3px;"><?php echo $today_str; ?> · <?php echo count($today_posts); ?> bài</div>
        </div>
        <a href="?page_id=<?php echo htmlspecialchars($selected_page_id); ?>&nocache=1" style="font-size:12px; color:var(--primary-color); text-decoration:none;">🔄 Làm mới</a>
    </div>

    <?php if ($today_err): ?>
        <div style="color:#dc2626; font-size:13px; padding:10px; background:#fee2e2; border-radius:6px;"><?php echo htmlspecialchars($today_err); ?></div>
    <?php elseif (empty($today_posts)): ?>
        <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:13px;">Chưa có bài đăng nào hôm nay.</div>
    <?php else: ?>
    <div style="display:flex; flex-direction:column; gap:10px;">
    <?php foreach ($today_posts as $tp):
        $created  = date('H:i', strtotime($tp['created_time']));
        $msg      = trim($tp['message'] ?? '');
        $msg_short = mb_strimwidth($msg, 0, 120, '…');
        $media_type = $tp['attachments']['data'][0]['media_type'] ?? '';
        $thumb_url  = $tp['attachments']['data'][0]['media']['image']['src'] ?? '';
        $post_url   = $tp['attachments']['data'][0]['url'] ?? "https://facebook.com/{$tp['id']}";
        $views      = get_post_metric($tp, 'post_video_views');
        $reach      = get_post_metric($tp, 'post_impressions_unique');
        $post_likes = (int)($tp['reactions']['summary']['total_count'] ?? 0);
        $post_comments = (int)($tp['comments']['summary']['total_count'] ?? 0);

        $is_video = in_array($media_type, ['video', 'reel']);
        $type_icon = $is_video ? '🎬' : ($media_type === 'photo' ? '🖼️' : '📝');
    ?>
    <div style="display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border:1px solid var(--border-color); border-radius:8px; background:var(--card-bg);">
        <?php if ($thumb_url): ?>
        <a href="<?php echo htmlspecialchars($post_url); ?>" target="_blank" style="flex-shrink:0;">
            <img src="<?php echo htmlspecialchars($thumb_url); ?>" alt="thumb"
                 style="width:72px; height:72px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
        </a>
        <?php endif; ?>
        <div style="flex:1; min-width:0;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                <span style="font-size:11px; background:#f3f4f6; color:#374151; padding:2px 8px; border-radius:4px;"><?php echo $type_icon . ' ' . htmlspecialchars($media_type ?: 'status'); ?></span>
                <span style="font-size:12px; color:var(--text-muted);"><?php echo $created; ?></span>
            </div>
            <div style="font-size:13px; color:var(--text-main); line-height:1.5; margin-bottom:8px;">
                <?php echo htmlspecialchars($msg_short) ?: '<em style="color:var(--text-muted);">Không có nội dung text</em>'; ?>
            </div>
            <div style="display:flex; gap:14px; font-size:12px; flex-wrap:wrap;">
                <?php if ($views !== null): ?>
                <span style="color:#8b5cf6; font-weight:600;">▶ <?php echo number_format($views); ?> views</span>
                <?php endif; ?>
                <?php if ($reach !== null): ?>
                <span style="color:#ef4444; font-weight:600;">👁 <?php echo number_format($reach); ?> reach</span>
                <?php endif; ?>
                <span style="color:#3b82f6; font-weight:600;">👍 <?php echo number_format($post_likes); ?> likes</span>
                <span style="color:#10b981; font-weight:600;">💬 <?php echo number_format($post_comments); ?> comments</span>
                <a href="<?php echo htmlspecialchars($post_url); ?>" target="_blank"
                   style="color:var(--primary-color); text-decoration:none; margin-left:auto;">Xem trên FB →</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($selected_page_id && !$error_msg): ?>
    <div class="alert alert-warning">Page này chưa có dữ liệu Insights trong biểu đồ.</div>
<?php elseif (!$selected_page_id): ?>
    <!-- Bảng tổng quan tốc độ tăng trưởng của từng page khi chưa chọn page cụ thể -->
    <div class="card">
        <h3 style="margin-bottom: 5px;">Tăng Trưởng Các Fanpage (<?php echo htmlspecialchars($period); ?>)</h3>
        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
            Hiển thị tổng lượt Reach và View của từng Fanpage trong khoảng thời gian đã chọn, sắp xếp theo tỷ lệ tương tác cao nhất. Nhấp vào tên Fanpage để xem biểu đồ chi tiết.
        </div>
        
        <table style="min-width:650px;">
            <thead>
                <tr>
                    <th>Tên Fanpage</th>
                    <th>Người theo dõi</th>
                    <th>Total Reach (<?php echo htmlspecialchars($period); ?>)</th>
                    <th>Total Views (<?php echo htmlspecialchars($period); ?>)</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody id="insights-growth-body">
                <tr><td colspan="5" style="text-align:center; color:#6b7280; padding: 30px;">
                    <div style="display: flex; justify-content: center; align-items: center; gap: 10px;">
                        <span style="display:inline-block; width:16px; height:16px; border:2px solid var(--border-color); border-top-color:var(--primary-color); border-radius:50%; animation: spin 1s linear infinite;"></span>
                        Đang lấy dữ liệu từ hệ thống...
                    </div>
                </td></tr>
            </tbody>
        </table>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tbody = document.getElementById('insights-growth-body');
        fetch('actions/ajax_insights_all.php')
            .then(res => res.json())
            .then(res => {
                if(res.status !== 'success') {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#ef4444; padding: 30px;">Đã xảy ra lỗi khi tải dữ liệu.</td></tr>';
                    return;
                }
                const data = res.data;
                const keys = Object.keys(data);
                
                if(keys.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#6b7280; padding: 30px;">Chưa có Fanpage nào để hiển thị dữ liệu tăng trưởng.</td></tr>';
                    return;
                }
                
                let html = '';
                keys.forEach(p_id => {
                    const row = data[p_id];
                    html += `
                        <tr>
                            <td style="font-weight: 500; color: var(--text-main);">
                                <span style="display:flex; align-items:center; gap:8px;">
                                    <span class="icon" style="color:var(--primary-color);">f</span>
                                    <a href="?page_id=${p_id}" target="_blank" style="text-decoration: none; color: inherit;">
                                        ${row.name}
                                    </a>
                                </span>
                            </td>
                            <td style="font-weight: 600; color: var(--secondary-color);">👍 ${Number(row.followers).toLocaleString()}</td>
                            <td style="color: #ef4444; font-weight: 600;">👁️ ${Number(row.reach).toLocaleString()}</td>
                            <td style="color: #8b5cf6; font-weight: 600;">▶ ${Number(row.views).toLocaleString()}</td>
                            <td>
                                <a href="?page_id=${p_id}" target="_blank" class="btn" style="padding: 6px 12px; font-size: 12px; border: 1px solid var(--border-color); color: var(--text-main); background: var(--bg-color);">Xem Biểu Đồ</a>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            })
            .catch(err => {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#ef4444; padding: 30px;">Không kết nối được server.</td></tr>';
            });
    });
    </script>
    <style>
    @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>