<?php
$current_page = 'insights';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];

// Fetch pages for dropdown
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

$selected_page_id = isset($_GET['page_id']) ? $_GET['page_id'] : '';
$insights_data = null;
$engagement_data = null;
$error_msg = null;

$period = 'days_28';
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-30 days'));

if ($selected_page_id) {
    $page_token = '';
    $page_name  = '';
    $t_stmt = $pdo->prepare("SELECT p.page_id, p.name, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ? LIMIT 1");
    $t_stmt->execute([$selected_page_id]);
    $t_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
    if ($t_row) {
        $page_token = decryptData($t_row['access_token']);
        $page_name  = $t_row['name'];
    }

    if ($page_token) {
        $cache_key = "insights_v2_{$selected_page_id}_{$period}";
        if (isset($_GET['nocache'])) unset($_SESSION[$cache_key]);

        if (isset($_SESSION[$cache_key]) && $_SESSION[$cache_key]['expires'] > time()) {
            $insights_data = $_SESSION[$cache_key]['data'];
        } else {
            // Fetch Page Insights: media views
            $api_response = get_fb_page_insights($selected_page_id, $page_token, $period, $start_date, $end_date);
            if ($api_response['status_code'] === 200 && isset($api_response['data']['data'])) {
                $insights_data = $api_response['data']['data'];
                $_SESSION[$cache_key] = ['data' => $insights_data, 'expires' => time() + 1800];
            } else {
                $error_msg = "Error: " . ($api_response['data']['error']['message'] ?? 'API Failed');
            }
        }

        // Fetch engagement data (likes + comments) from posts feed
        $cache_key_eng = "engagement_v2_{$selected_page_id}_{$period}";
        if (isset($_GET['nocache'])) unset($_SESSION[$cache_key_eng]);
        
        if (isset($_SESSION[$cache_key_eng]) && $_SESSION[$cache_key_eng]['expires'] > time()) {
            $engagement_data = $_SESSION[$cache_key_eng]['data'];
        } else {
            $engagement_data = ['daily_likes' => [], 'daily_comments' => [], 'daily_shares' => [],
                                'total_likes' => 0, 'total_comments' => 0, 'total_shares' => 0];
            $feed_url = "https://graph.facebook.com/v25.0/{$selected_page_id}/published_posts"
                      . "?fields=" . urlencode('created_time,reactions.summary(true).limit(0),comments.summary(true).limit(0),shares')
                      . "&since={$start_date}&until={$end_date}"
                      . "&limit=100"
                      . "&access_token=" . urlencode($page_token);

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
                    foreach ($json['data'] as $p) {
                        $day = date('Y-m-d', strtotime($p['created_time']));
                        $likes = (int)($p['reactions']['summary']['total_count'] ?? 0);
                        $comments = (int)($p['comments']['summary']['total_count'] ?? 0);
                        $shares = (int)($p['shares']['count'] ?? 0);
                        if (!isset($engagement_data['daily_likes'][$day])) $engagement_data['daily_likes'][$day] = 0;
                        if (!isset($engagement_data['daily_comments'][$day])) $engagement_data['daily_comments'][$day] = 0;
                        if (!isset($engagement_data['daily_shares'][$day])) $engagement_data['daily_shares'][$day] = 0;
                        $engagement_data['daily_likes'][$day] += $likes;
                        $engagement_data['daily_comments'][$day] += $comments;
                        $engagement_data['daily_shares'][$day] += $shares;
                        $engagement_data['total_likes'] += $likes;
                        $engagement_data['total_comments'] += $comments;
                        $engagement_data['total_shares'] += $shares;
                    }
                }
                $fetch_url = $json['paging']['next'] ?? null;
                $fetch_attempts++;
            }
            $_SESSION[$cache_key_eng] = ['data' => $engagement_data, 'expires' => time() + 1800];
        }
    } else {
        $error_msg = "Không tìm thấy Token hoặc bạn không có quyền sở hữu Fanpage này.";
    }
}

// ── Build chart data ─────────────────────────────────────────────────────────
$chart_labels = [];
$chart_views = [];
$chart_likes = [];
$chart_comments = [];
$chart_shares = [];

$total_views = 0;
$total_likes = $engagement_data['total_likes'] ?? 0;
$total_comments = $engagement_data['total_comments'] ?? 0;
$total_shares = $engagement_data['total_shares'] ?? 0;
$daily_likes = $engagement_data['daily_likes'] ?? [];
$daily_comments = $engagement_data['daily_comments'] ?? [];
$daily_shares = $engagement_data['daily_shares'] ?? [];

if ($insights_data) {
    foreach ($insights_data as $metric) {
        if ($metric['name'] === 'page_media_view') {
            if (isset($metric['values']) && is_array($metric['values'])) {
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
                        $chart_shares[] = $daily_shares[$date_key] ?? 0;
                    }
                }
            }
        }
    }
}

if (empty($chart_labels)) {
    for ($i=6; $i>=0; $i--) {
        $chart_labels[] = date('m-d', strtotime("-$i days"));
        $chart_views[] = 0;
        $chart_likes[] = 0;
        $chart_comments[] = 0;
        $chart_shares[] = 0;
    }
}

// ── Fetch today's posts ─────────────────────────────────────────────────────
$today_posts = [];
$today_err   = '';
$today_str   = date('Y-m-d');
$tomorrow_str = date('Y-m-d', strtotime('+1 day'));

if (!empty($page_token) && $selected_page_id) {
    $fields = 'created_time,message,attachments{media_type,url,media},reactions.summary(true).limit(0),comments.summary(true).limit(0),shares,insights.metric(post_video_views,post_impressions_unique)';
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

    <div class="page-title" style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:22px;">📊</span>
            <span style="font-size:20px; font-weight:700;">Content Insights</span>
        </div>
        <?php if ($selected_page_id): ?>
            <a href="?page_id=<?php echo htmlspecialchars($selected_page_id); ?>&nocache=1" class="btn" style="background: white; border: 1px solid var(--border-color); color: var(--text-main); font-weight: 500; font-size: 13px; text-decoration: none;">
                <span style="margin-right: 5px;">🔄</span> Refresh
            </a>
        <?php endif; ?>
    </div>

    <div style="font-size:12px; color:var(--text-muted); margin-bottom:16px; line-height:1.6; padding:12px 16px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
        💡 <strong>Content Insights</strong> — Đo lường hiệu suất nội dung: lượt xem video/reels, tương tác (reactions, comments, shares), và chi tiết từng bài đăng hôm nay.
    </div>

    <!-- Page Selector -->
    <div class="card" style="margin-bottom: 20px; padding: 15px 20px;">
        <form method="GET" action="insights.php" style="display:flex; align-items:center; gap:10px;">
            <label style="font-size:13px; font-weight:600; color:var(--text-main); white-space:nowrap;">Fanpage:</label>
            <select name="page_id" class="form-control" style="flex:1;" required onchange="this.form.submit()">
                <option value="">-- Chọn Fanpage --</option>
                <?php foreach ($pages as $p): ?>
                    <option value="<?php echo htmlspecialchars($p['page_id']); ?>" <?php echo ($selected_page_id === $p['page_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

<?php if ($error_msg): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
<?php endif; ?>

<?php if ($selected_page_id && $insights_data): ?>
    <!-- Summary Stats -->
    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom:20px;">
        <div class="stat-card">
            <div class="stat-title">Media Views</div>
            <div class="stat-value" style="color:#8b5cf6; display:flex; align-items:center;"><span style="margin-right:8px;">▶</span> <?php echo number_format($total_views); ?></div>
            <div class="stat-subtitle">Lượt xem media (28 ngày)</div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Reactions</div>
            <div class="stat-value" style="color:#3b82f6; display:flex; align-items:center;"><span style="margin-right:8px;">👍</span> <?php echo number_format($total_likes); ?></div>
            <div class="stat-subtitle">Tổng lượt reactions</div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Comments</div>
            <div class="stat-value" style="color:#10b981; display:flex; align-items:center;"><span style="margin-right:8px;">💬</span> <?php echo number_format($total_comments); ?></div>
            <div class="stat-subtitle">Tổng bình luận</div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Shares</div>
            <div class="stat-value" style="color:#f59e0b; display:flex; align-items:center;"><span style="margin-right:8px;">🔗</span> <?php echo number_format($total_shares); ?></div>
            <div class="stat-subtitle">Tổng chia sẻ</div>
        </div>
    </div>

    <!-- Chart: Views + Engagement -->
    <div class="card" style="margin-bottom: 25px;">
        <h3 style="margin-bottom: 5px;">📈 Biểu đồ hiệu suất nội dung (<?php echo htmlspecialchars($period); ?>)</h3>
        <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 20px;">Views = Page Media, Reactions/Comments/Shares = Tổng hợp từ bài đăng.</div>
        
        <div style="height: 380px; position: relative; width: 100%;">
            <canvas id="insightsChart"></canvas>
        </div>
        <div style="display:flex; justify-content:center; gap:20px; margin-top: 15px; font-size: 13px; font-weight: 500; flex-wrap:wrap;">
            <span style="color: #8b5cf6; cursor:pointer;" onclick="toggleDataset(0)">● Views</span>
            <span style="color: #3b82f6; cursor:pointer;" onclick="toggleDataset(1)">● Reactions</span>
            <span style="color: #10b981; cursor:pointer;" onclick="toggleDataset(2)">● Comments</span>
            <span style="color: #f59e0b; cursor:pointer;" onclick="toggleDataset(3)">● Shares</span>
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
        
        function mkGrad(r,g,b) { const gd = ctx.createLinearGradient(0,0,0,380); gd.addColorStop(0, `rgba(${r},${g},${b},0.25)`); gd.addColorStop(1, `rgba(${r},${g},${b},0)`); return gd; }
        
        insightsChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    { label: 'Media Views', data: <?php echo json_encode($chart_views); ?>, borderColor: '#8b5cf6', backgroundColor: mkGrad(139,92,246), borderWidth: 2.5, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#8b5cf6', pointBorderColor: '#fff' },
                    { label: 'Reactions', data: <?php echo json_encode($chart_likes); ?>, borderColor: '#3b82f6', backgroundColor: mkGrad(59,130,246), borderWidth: 2.5, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#3b82f6', pointBorderColor: '#fff', yAxisID: 'y1' },
                    { label: 'Comments', data: <?php echo json_encode($chart_comments); ?>, borderColor: '#10b981', backgroundColor: mkGrad(16,185,129), borderWidth: 2.5, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#10b981', pointBorderColor: '#fff', yAxisID: 'y1' },
                    { label: 'Shares', data: <?php echo json_encode($chart_shares); ?>, borderColor: '#f59e0b', backgroundColor: mkGrad(245,158,11), borderWidth: 2, fill: false, tension: 0.4, pointRadius: 2, pointHoverRadius: 5, pointBackgroundColor: '#f59e0b', pointBorderColor: '#fff', yAxisID: 'y1', borderDash: [5,5] }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, backgroundColor: 'rgba(15,23,42,0.9)', titleFont: { size: 13, weight: 'bold' }, bodyFont: { size: 12 }, padding: 12, cornerRadius: 8, callbacks: { label: function(ctx) { let v = ctx.parsed.y; if (v>=1000000) v=(v/1000000).toFixed(1)+'M'; else if(v>=1000) v=(v/1000).toFixed(1)+'k'; return ' '+ctx.dataset.label+': '+v; } } } },
                scales: {
                    x: { grid: { color:'rgba(0,0,0,0)', drawBorder:false }, ticks: { color:'#6b7280', font:{size:11}, maxTicksLimit:14 } },
                    y: { type:'linear', position:'left', grid: { color:'#e5e7eb', drawBorder:false, borderDash:[5,5] }, title: { display:true, text:'Views', color:'#8b5cf6', font:{size:12,weight:'bold'} }, ticks: { color:'#8b5cf6', font:{size:11}, callback: v=>v>=1e6?v/1e6+'M':v>=1e3?v/1e3+'k':v } },
                    y1: { type:'linear', position:'right', grid: { drawOnChartArea:false }, title: { display:true, text:'Engagement', color:'#3b82f6', font:{size:12,weight:'bold'} }, ticks: { color:'#3b82f6', font:{size:11}, callback: v=>v>=1e6?v/1e6+'M':v>=1e3?v/1e3+'k':v } }
                },
                interaction: { mode: 'index', intersect: false }
            }
        });
    });
    </script>

    <!-- Today's posts -->
    <div class="card" style="margin-top:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
            <div>
                <h3 style="margin:0; font-size:16px;">📅 Bài đăng hôm nay</h3>
                <div style="font-size:12px; color:var(--text-muted); margin-top:3px;"><?php echo $today_str; ?> · <?php echo count($today_posts); ?> bài</div>
            </div>
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
            $post_shares = (int)($tp['shares']['count'] ?? 0);
            $is_video = in_array($media_type, ['video', 'reel']);
            $type_icon = $is_video ? '🎬' : ($media_type === 'photo' ? '🖼️' : '📝');
        ?>
        <div style="display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border:1px solid var(--border-color); border-radius:8px; background:var(--card-bg);">
            <?php if ($thumb_url): ?>
            <a href="<?php echo htmlspecialchars($post_url); ?>" target="_blank" style="flex-shrink:0;">
                <img src="<?php echo htmlspecialchars($thumb_url); ?>" alt="thumb" style="width:72px; height:72px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
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
                    <span style="color:#3b82f6; font-weight:600;">👍 <?php echo number_format($post_likes); ?></span>
                    <span style="color:#10b981; font-weight:600;">💬 <?php echo number_format($post_comments); ?></span>
                    <span style="color:#f59e0b; font-weight:600;">🔗 <?php echo number_format($post_shares); ?></span>
                    <a href="<?php echo htmlspecialchars($post_url); ?>" target="_blank" style="color:var(--primary-color); text-decoration:none; margin-left:auto;">Xem trên FB →</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

<?php elseif (!$selected_page_id): ?>
    <div style="text-align:center; padding: 50px; color: var(--text-muted); background: white; border-radius: 8px; border: 1px dashed var(--border-color);">
        <div style="font-size: 40px; margin-bottom: 12px;">📊</div>
        <div style="font-size: 15px; font-weight:600; margin-bottom:6px;">Chọn Fanpage để xem Insights</div>
        <div style="font-size: 13px;">Dữ liệu sẽ hiển thị: Views, Reactions, Comments, Shares và bài đăng hôm nay.</div>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>