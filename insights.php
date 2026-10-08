<?php
$current_page = 'insights';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];

// Fetch pages for dropdown
$stmt2 = $pdo->prepare("
    (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id
     FROM pages p JOIN users u ON p.user_id = u.id
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.id, p.page_id, p.name, p.avatar, u.id as user_id
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
    $fields = 'created_time,message,attachments{media_type,url,media},reactions.summary(true).limit(0),comments.summary(true).limit(0),shares,insights.metric(post_video_views,post_total_media_view_unique)';
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

<style>
/* ── Design Tokens & Refactored Styling for Content Insights ── */
:root {
    --cs-primary: #4f46e5;
    --cs-primary-hover: #4338ca;
    --cs-primary-glow: rgba(79, 70, 229, 0.15);
    --cs-surface: #ffffff;
    --cs-border: #e2e8f0;
    --cs-text-main: #0f172a;
    --cs-text-muted: #64748b;
    --cs-radius: 16px;
}

.cs-header-banner {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    border-radius: var(--cs-radius);
    padding: 26px 30px;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(30, 27, 75, 0.2);
    position: relative;
    overflow: hidden;
}
.cs-header-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, rgba(99, 102, 241, 0) 70%);
    pointer-events: none;
}
.cs-header-title { display: flex; align-items: center; gap: 16px; }
.cs-icon-badge {
    width: 50px; height: 50px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #a5b4fc; flex-shrink: 0;
}
.cs-header-text h2 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 24px; font-weight: 800;
    margin: 0 0 4px; color: #ffffff;
    letter-spacing: -0.01em;
}
.cs-header-text p { font-size: 13px; color: #cbd5e1; margin: 0; }

.cs-refresh-btn {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.22);
    backdrop-filter: blur(8px);
    padding: 10px 18px; border-radius: 12px;
    color: #ffffff; font-size: 13px; font-weight: 700;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.2s ease;
}
.cs-refresh-btn:hover { background: rgba(255, 255, 255, 0.25); color: #ffffff; text-decoration: none; transform: translateY(-1px); }

/* Callout Box */
.cs-callout {
    padding: 14px 18px; border-radius: 12px; font-size: 13px;
    margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500;
    background: #eef2ff; border: 1px solid #c7d2fe; color: #3730a3; line-height: 1.5;
}

/* Cards */
.cs-card {
    background: var(--cs-surface);
    border: 1px solid var(--cs-border);
    border-radius: var(--cs-radius);
    padding: 26px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
    margin-bottom: 24px;
    transition: all 0.25s ease;
}
.cs-card:hover {
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
    border-color: #cbd5e1;
}

.cs-card-head {
    margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9;
}
.cs-card-head h3 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 18px; font-weight: 800; color: var(--cs-text-main); margin: 0 0 4px;
    display: flex; align-items: center; gap: 8px;
}
.cs-card-head p { font-size: 13px; color: var(--cs-text-muted); margin: 0; font-weight: 400; }

/* Stats Grid */
.cs-stats-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px; margin-bottom: 24px;
}
.cs-stat-item {
    background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 14px;
    padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); transition: all 0.2s ease;
}
.cs-stat-item:hover { transform: translateY(-2px); border-color: var(--cs-primary); box-shadow: 0 8px 20px rgba(79, 70, 229, 0.08); }

.cs-stat-title { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
.cs-stat-value { font-family: 'Be Vietnam Pro', sans-serif; font-size: 26px; font-weight: 800; line-height: 1; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
.cs-stat-sub { font-size: 11px; color: #94a3b8; font-weight: 500; }

/* Legend Dataset Toggles */
.dataset-pills {
    display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 20px;
}
.dataset-pill {
    padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700;
    cursor: pointer; user-select: none; display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s ease; border: 1px solid transparent;
}

/* Today Post Card */
.post-item-card {
    display: flex; gap: 16px; align-items: flex-start; padding: 18px;
    border: 1.5px solid #e2e8f0; border-radius: 14px; background: #ffffff;
    margin-bottom: 14px; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.02);
}
.post-item-card:hover { border-color: var(--cs-primary); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06); }
.post-thumb { width: 80px; height: 80px; object-fit: cover; border-radius: 10px; border: 1px solid #cbd5e1; flex-shrink: 0; }

/* Alert */
.cs-alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 14px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; margin-bottom: 24px; }
</style>

<!-- Banner Header -->
<div class="cs-header-banner">
    <div class="cs-header-title">
        <div class="cs-icon-badge">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <div class="cs-header-text">
            <h2>Content Insights Analytics</h2>
            <p>Phân tích chi tiết hiệu suất media views, phản hồi tương tác và bài đăng trong ngày</p>
        </div>
    </div>

    <?php if ($selected_page_id): ?>
        <a href="?page_id=<?php echo htmlspecialchars($selected_page_id); ?>&nocache=1" class="cs-refresh-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            <span>Làm Mới Insights</span>
        </a>
    <?php endif; ?>
</div>

<!-- Feature Description Callout -->
<div class="cs-callout">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div>
        <strong>💡 Content Insights:</strong> Đo lường hiệu suất nội dung chuyên sâu — theo dõi lượt xem video/reels, tổng lượt tương tác (Reactions, Comments, Shares) và thống kê bài đăng hôm nay.
    </div>
</div>

<!-- Page Selector Form Card -->
<div class="cs-card" style="padding: 18px 24px; overflow: visible !important;">
    <form method="GET" action="insights.php" id="insightsForm" style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
        <label style="font-size:13px; font-weight:800; color:var(--cs-text-main); text-transform:uppercase; letter-spacing:0.5px;">Chọn Fanpage:</label>
        <input type="hidden" name="page_id" id="selectedPageId" value="<?php echo htmlspecialchars($selected_page_id); ?>">
        
        <div style="flex:1; min-width:280px; position:relative;" id="customSelectWrap">
            <div id="customSelectBtn" style="display:flex; align-items:center; gap:12px; padding:10px 16px; border:1.5px solid #cbd5e1; border-radius:12px; cursor:pointer; background:#ffffff; min-height:44px; transition:all 0.2s ease;" onclick="togglePageDropdown()">
                <?php
                $sel_name = '-- Chọn Fanpage Phân Tích --';
                $sel_avatar = '';
                foreach ($pages as $p) {
                    if ($selected_page_id === $p['page_id']) {
                        $sel_name = $p['name'];
                        $sel_avatar = $p['avatar'] ?? '';
                        break;
                    }
                }
                ?>
                <?php if ($sel_avatar): ?>
                    <img src="<?php echo htmlspecialchars($sel_avatar); ?>" style="width:28px; height:28px; border-radius:50%; object-fit:cover; flex-shrink:0; border:1px solid #cbd5e1;">
                <?php elseif ($selected_page_id): ?>
                    <div style="width:28px; height:28px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:12px; color:#64748b; font-weight:800; flex-shrink:0;"><?php echo mb_strtoupper(mb_substr($sel_name, 0, 1)); ?></div>
                <?php endif; ?>
                <span style="flex:1; font-size:14px; font-weight:700; color:<?php echo $selected_page_id ? 'var(--cs-text-main)' : '#94a3b8'; ?>;"><?php echo htmlspecialchars($sel_name); ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#64748b;"><polyline points="6 9 12 15 18 9"/></svg>
            </div>

            <!-- Dropdown List -->
            <div id="customSelectDropdown" style="display:none; position:absolute; left:0; right:0; top:100%; margin-top:8px; background:#ffffff; border:1.5px solid #cbd5e1; border-radius:14px; box-shadow:0 20px 40px rgba(15,23,42,0.12); z-index:999; max-height:360px; overflow:hidden;">
                <div style="padding:12px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
                    <input type="text" id="pageSearchInput" placeholder="🔍 Tìm kiếm Fanpage..." style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:500; box-sizing:border-box; outline:none;">
                </div>
                <div id="pageOptionsList" style="overflow-y:auto; max-height:280px;">
                    <?php foreach ($pages as $p): ?>
                    <div class="page-option" data-page-id="<?php echo htmlspecialchars($p['page_id']); ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" style="display:flex; align-items:center; gap:12px; padding:12px 16px; cursor:pointer; transition:all 0.15s ease; border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <?php if (!empty($p['avatar'])): ?>
                            <img src="<?php echo htmlspecialchars($p['avatar']); ?>" style="width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0; border:1px solid #cbd5e1;">
                        <?php else: ?>
                            <div style="width:32px; height:32px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:13px; color:#64748b; font-weight:800; flex-shrink:0;"><?php echo mb_strtoupper(mb_substr($p['name'], 0, 1)); ?></div>
                        <?php endif; ?>
                        <span style="font-size:13.5px; font-weight:600; color:var(--cs-text-main);"><?php echo htmlspecialchars($p['name']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function(){
    const dropdown = document.getElementById('customSelectDropdown');
    const searchInput = document.getElementById('pageSearchInput');
    const hiddenInput = document.getElementById('selectedPageId');
    const form = document.getElementById('insightsForm');
    let isOpen = false;

    window.togglePageDropdown = function() {
        isOpen = !isOpen;
        dropdown.style.display = isOpen ? 'block' : 'none';
        if (isOpen) {
            searchInput.value = '';
            filterOptions('');
            setTimeout(() => searchInput.focus(), 10);
        }
    };

    searchInput.addEventListener('input', function() { filterOptions(this.value.trim().toLowerCase()); });
    searchInput.addEventListener('click', function(e) { e.stopPropagation(); });

    function filterOptions(q) {
        document.querySelectorAll('.page-option').forEach(opt => {
            const name = (opt.getAttribute('data-name') || '').toLowerCase();
            opt.style.display = (!q || name.includes(q)) ? 'flex' : 'none';
        });
    }

    document.querySelectorAll('.page-option').forEach(opt => {
        opt.addEventListener('click', function(e) {
            e.stopPropagation();
            hiddenInput.value = this.getAttribute('data-page-id');
            form.submit();
        });
    });

    document.addEventListener('click', function(e) {
        if (!document.getElementById('customSelectWrap').contains(e.target)) {
            dropdown.style.display = 'none';
            isOpen = false;
        }
    });
})();
</script>

<?php if ($error_msg): ?>
    <div class="cs-alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
<?php endif; ?>

<?php if ($selected_page_id && $insights_data): ?>
    <!-- Summary Stats Grid -->
    <div class="cs-stats-grid">
        <div class="cs-stat-item">
            <div class="cs-stat-title">Media Views</div>
            <div class="cs-stat-value" style="color:#7c3aed;">
                <span>▶</span> <?php echo number_format($total_views); ?>
            </div>
            <div class="cs-stat-sub">Lượt xem media (28 ngày)</div>
        </div>
        <div class="cs-stat-item">
            <div class="cs-stat-title">Reactions</div>
            <div class="cs-stat-value" style="color:#2563eb;">
                <span>👍</span> <?php echo number_format($total_likes); ?>
            </div>
            <div class="cs-stat-sub">Tổng lượt phẫn nộ/thích</div>
        </div>
        <div class="cs-stat-item">
            <div class="cs-stat-title">Comments</div>
            <div class="cs-stat-value" style="color:#059669;">
                <span>💬</span> <?php echo number_format($total_comments); ?>
            </div>
            <div class="cs-stat-sub">Tổng lượt bình luận</div>
        </div>
        <div class="cs-stat-item">
            <div class="cs-stat-title">Shares</div>
            <div class="cs-stat-value" style="color:#d97706;">
                <span>🔗</span> <?php echo number_format($total_shares); ?>
            </div>
            <div class="cs-stat-sub">Tổng lượt chia sẻ</div>
        </div>
    </div>

    <!-- Chart Card -->
    <div class="cs-card">
        <div class="cs-card-head">
            <h3>📈 Biểu Đồ Hiệu Suất Nội Dung (<?php echo htmlspecialchars($period); ?>)</h3>
            <p>Views = Page Media, Reactions/Comments/Shares = Thống kê tổng hợp từ tất cả bài đăng</p>
        </div>
        
        <div style="height: 380px; position: relative; width: 100%;">
            <canvas id="insightsChart"></canvas>
        </div>

        <div class="dataset-pills">
            <span class="dataset-pill" style="background:#f3e8ff; color:#7c3aed; border-color:#e9d5ff;" onclick="toggleDataset(0)">● Media Views</span>
            <span class="dataset-pill" style="background:#eff6ff; color:#2563eb; border-color:#bfdbfe;" onclick="toggleDataset(1)">● Reactions</span>
            <span class="dataset-pill" style="background:#ecfdf5; color:#059669; border-color:#a7f3d0;" onclick="toggleDataset(2)">● Comments</span>
            <span class="dataset-pill" style="background:#fffbeb; color:#d97706; border-color:#fde68a;" onclick="toggleDataset(3)">● Shares</span>
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
                    { label: 'Media Views', data: <?php echo json_encode($chart_views); ?>, borderColor: '#7c3aed', backgroundColor: mkGrad(124,58,237), borderWidth: 2.5, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#7c3aed', pointBorderColor: '#fff' },
                    { label: 'Reactions', data: <?php echo json_encode($chart_likes); ?>, borderColor: '#2563eb', backgroundColor: mkGrad(37,99,235), borderWidth: 2.5, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#2563eb', pointBorderColor: '#fff', yAxisID: 'y1' },
                    { label: 'Comments', data: <?php echo json_encode($chart_comments); ?>, borderColor: '#059669', backgroundColor: mkGrad(5,150,105), borderWidth: 2.5, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#059669', pointBorderColor: '#fff', yAxisID: 'y1' },
                    { label: 'Shares', data: <?php echo json_encode($chart_shares); ?>, borderColor: '#d97706', backgroundColor: mkGrad(217,119,6), borderWidth: 2, fill: false, tension: 0.4, pointRadius: 2, pointHoverRadius: 5, pointBackgroundColor: '#d97706', pointBorderColor: '#fff', yAxisID: 'y1', borderDash: [5,5] }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, backgroundColor: 'rgba(15,23,42,0.9)', titleFont: { size: 13, weight: 'bold' }, bodyFont: { size: 12 }, padding: 12, cornerRadius: 8, callbacks: { label: function(ctx) { let v = ctx.parsed.y; if (v>=1000000) v=(v/1000000).toFixed(1)+'M'; else if(v>=1000) v=(v/1000).toFixed(1)+'k'; return ' '+ctx.dataset.label+': '+v; } } } },
                scales: {
                    x: { grid: { color:'rgba(0,0,0,0)', drawBorder:false }, ticks: { color:'#64748b', font:{size:11}, maxTicksLimit:14 } },
                    y: { type:'linear', position:'left', grid: { color:'#e2e8f0', drawBorder:false, borderDash:[5,5] }, title: { display:true, text:'Views', color:'#7c3aed', font:{size:12,weight:'bold'} }, ticks: { color:'#7c3aed', font:{size:11}, callback: v=>v>=1e6?v/1e6+'M':v>=1e3?v/1e3+'k':v } },
                    y1: { type:'linear', position:'right', grid: { drawOnChartArea:false }, title: { display:true, text:'Engagement', color:'#2563eb', font:{size:12,weight:'bold'} }, ticks: { color:'#2563eb', font:{size:11}, callback: v=>v>=1e6?v/1e6+'M':v>=1e3?v/1e3+'k':v } }
                },
                interaction: { mode: 'index', intersect: false }
            }
        });
    });
    </script>

    <!-- Today's Posts Card -->
    <div class="cs-card">
        <div class="cs-card-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div>
                <h3 style="margin:0;">📅 Bài Đăng Trong Ngày (Today)</h3>
                <p><?php echo $today_str; ?> · Tổng số: <strong><?php echo count($today_posts); ?></strong> bài viết</p>
            </div>
        </div>

        <?php if ($today_err): ?>
            <div class="cs-alert-danger"><?php echo htmlspecialchars($today_err); ?></div>
        <?php elseif (empty($today_posts)): ?>
            <div style="text-align:center; padding:40px 20px; color:#64748b; font-size:13.5px;">Chưa có bài đăng nào trên Fanpage trong ngày hôm nay.</div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
            <?php foreach ($today_posts as $tp):
                $created  = date('H:i', strtotime($tp['created_time']));
                $msg      = trim($tp['message'] ?? '');
                $msg_short = mb_strimwidth($msg, 0, 140, '…');
                $media_type = $tp['attachments']['data'][0]['media_type'] ?? '';
                $thumb_url  = $tp['attachments']['data'][0]['media']['image']['src'] ?? '';
                $post_url   = $tp['attachments']['data'][0]['url'] ?? "https://facebook.com/{$tp['id']}";
                $views      = get_post_metric($tp, 'post_video_views');
                $reach      = get_post_metric($tp, 'post_total_media_view_unique');
                $post_likes = (int)($tp['reactions']['summary']['total_count'] ?? 0);
                $post_comments = (int)($tp['comments']['summary']['total_count'] ?? 0);
                $post_shares = (int)($tp['shares']['count'] ?? 0);
                $is_video = in_array($media_type, ['video', 'reel']);
                $type_icon = $is_video ? '🎬' : ($media_type === 'photo' ? '🖼️' : '📝');
            ?>
            <div class="post-item-card">
                <?php if ($thumb_url): ?>
                <a href="<?php echo htmlspecialchars($post_url); ?>" target="_blank" style="flex-shrink:0;">
                    <img src="<?php echo htmlspecialchars($thumb_url); ?>" alt="thumb" class="post-thumb">
                </a>
                <?php endif; ?>
                <div style="flex:1; min-width:0;">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                        <span style="font-size:11px; font-weight:700; background:#f1f5f9; color:#334155; padding:3px 8px; border-radius:6px; text-transform:uppercase;">
                            <?php echo $type_icon . ' ' . htmlspecialchars($media_type ?: 'status'); ?>
                        </span>
                        <span style="font-size:12px; color:#64748b; font-weight:600;">🕒 <?php echo $created; ?></span>
                    </div>

                    <div style="font-size:13.5px; color:var(--cs-text-main); font-weight:500; line-height:1.5; margin-bottom:10px;">
                        <?php echo htmlspecialchars($msg_short) ?: '<em style="color:#94a3b8;">Nội dung phương tiện không có văn bản text</em>'; ?>
                    </div>

                    <div style="display:flex; gap:16px; font-size:12.5px; font-weight:700; flex-wrap:wrap; align-items:center;">
                        <?php if ($views !== null): ?>
                        <span style="color:#7c3aed;">▶ <?php echo number_format($views); ?> views</span>
                        <?php endif; ?>
                        <?php if ($reach !== null): ?>
                        <span style="color:#dc2626;">👁 <?php echo number_format($reach); ?> reach</span>
                        <?php endif; ?>
                        <span style="color:#2563eb;">👍 <?php echo number_format($post_likes); ?></span>
                        <span style="color:#059669;">💬 <?php echo number_format($post_comments); ?></span>
                        <span style="color:#d97706;">🔗 <?php echo number_format($post_shares); ?></span>
                        
                        <a href="<?php echo htmlspecialchars($post_url); ?>" target="_blank" style="color:var(--cs-primary); text-decoration:none; margin-left:auto; font-size:12px;">Xem bài viết →</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php elseif (!$selected_page_id): ?>
    <div class="cs-card" style="text-align:center; padding: 60px 20px;">
        <div style="width:64px; height:64px; border-radius:20px; background:#eef2ff; color:var(--cs-primary); display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <h3 style="font-size: 18px; font-weight:800; color:var(--cs-text-main); margin:0 0 6px;">Chọn Fanpage Để Xem Insights</h3>
        <p style="font-size: 13px; color:var(--cs-text-muted); margin:0;">Dữ liệu sẽ phân tích tổng thể: Views, Reactions, Comments, Shares và bài đăng hôm nay.</p>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>