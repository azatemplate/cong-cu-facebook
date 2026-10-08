<?php
$current_page = 'growth';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];

// Fetch pages for dropdown
$stmt2 = $pdo->prepare("
    (SELECT p.id, p.page_id, p.name, p.avatar, p.user_id, p.followers_count, p.followers_diff
     FROM pages p JOIN users u ON p.user_id = u.id
     WHERE u.account_id = :aid)
    UNION
    (SELECT p.id, p.page_id, p.name, p.avatar, u.id as user_id, p.followers_count, p.followers_diff
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

$period = 'days_28';
?>

<style>
/* ── Design Tokens & Refactored Styling for Page Growth ── */
:root {
    --gr-primary: #4f46e5;
    --gr-primary-hover: #4338ca;
    --gr-primary-glow: rgba(79, 70, 229, 0.15);
    --gr-surface: #ffffff;
    --gr-border: #e2e8f0;
    --gr-text-main: #0f172a;
    --gr-text-muted: #64748b;
    --gr-radius: 16px;
}

.gr-header-banner {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    border-radius: var(--gr-radius);
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
.gr-header-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, rgba(99, 102, 241, 0) 70%);
    pointer-events: none;
}
.gr-header-title { display: flex; align-items: center; gap: 16px; }
.gr-icon-badge {
    width: 50px; height: 50px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #a5b4fc; flex-shrink: 0;
}
.gr-header-text h2 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 24px; font-weight: 800;
    margin: 0 0 4px; color: #ffffff;
    letter-spacing: -0.01em;
}
.gr-header-text p { font-size: 13px; color: #cbd5e1; margin: 0; }

.gr-refresh-btn {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.22);
    backdrop-filter: blur(8px);
    padding: 10px 18px; border-radius: 12px;
    color: #ffffff; font-size: 13px; font-weight: 700;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.2s ease;
}
.gr-refresh-btn:hover { background: rgba(255, 255, 255, 0.25); color: #ffffff; text-decoration: none; transform: translateY(-1px); }

/* Callout Box */
.gr-callout {
    padding: 14px 18px; border-radius: 12px; font-size: 13px;
    margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500;
    background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; line-height: 1.5;
}

/* Cards */
.gr-card {
    background: var(--gr-surface);
    border: 1px solid var(--gr-border);
    border-radius: var(--gr-radius);
    padding: 26px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
    margin-bottom: 24px;
    transition: all 0.25s ease;
}
.gr-card:hover {
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
    border-color: #cbd5e1;
}

.gr-card-head {
    margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9;
}
.gr-card-head h3 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 18px; font-weight: 800; color: var(--gr-text-main); margin: 0 0 4px;
    display: flex; align-items: center; gap: 8px;
}
.gr-card-head p { font-size: 13px; color: var(--gr-text-muted); margin: 0; font-weight: 400; }

/* Modern Table */
.gr-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.gr-table th {
    background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px; padding: 14px 16px;
    border-bottom: 1.5px solid #e2e8f0; text-align: left;
}
.gr-table td {
    padding: 16px; border-bottom: 1px solid #f1f5f9; font-size: 13.5px;
    color: var(--gr-text-main); vertical-align: middle;
}
.gr-table tr:last-child td { border-bottom: none; }
.gr-table tr:hover td { background: #f8fafc; }

/* Follower Cards Grid */
.followers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 16px;
}
.follower-card-item {
    padding: 18px; border: 1px solid #e2e8f0; border-radius: 14px;
    background: #ffffff; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.02);
}
.follower-card-item:hover {
    border-color: var(--gr-primary); transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(79, 70, 229, 0.08);
}
.follower-avatar {
    width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0;
    border: 1px solid #cbd5e1;
}
.follower-avatar-fallback {
    width: 32px; height: 32px; border-radius: 50%; background: #e2e8f0;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; color: #64748b; font-weight: 800; flex-shrink: 0;
}

/* Action Button */
.gr-action-btn {
    padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 700;
    border: 1px solid #c7d2fe; background: #eef2ff; color: #4f46e5;
    text-decoration: none; display: inline-flex; align-items: center; gap: 4px;
    transition: all 0.2s ease;
}
.gr-action-btn:hover { background: #4f46e5; color: #ffffff; text-decoration: none; }

/* Empty Chart State */
.chart-placeholder-box {
    text-align: center; color: #64748b; padding: 40px 20px;
}
.chart-placeholder-icon {
    width: 64px; height: 64px; border-radius: 20px; background: #eef2ff; color: #4f46e5;
    display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;
}
</style>

<!-- Banner Header -->
<div class="gr-header-banner">
    <div class="gr-header-title">
        <div class="gr-icon-badge">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
        </div>
        <div class="gr-header-text">
            <h2>Page Growth Analytics</h2>
            <p>Theo dõi chỉ số tăng trưởng Followers, lượt tiếp cận Reach và Views tổng hợp của các Fanpage</p>
        </div>
    </div>

    <a href="?nocache=1" class="gr-refresh-btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        <span>Làm Mới Dữ Liệu</span>
    </a>
</div>

<!-- Feature Description Callout -->
<div class="gr-callout">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
    <div>
        <strong>🌱 Page Growth Analytics:</strong> Giúp bạn theo dõi biến động Followers, hiệu suất tiếp cận người dùng (Reach), tổng số lượt xem (Views), và tốc độ phát triển tổng thể của hệ thống Fanpage.
    </div>
</div>

<!-- All Pages Growth Table Card -->
<div class="gr-card">
    <div class="gr-card-head">
        <h3>🏆 So Sánh Tăng Trưởng Các Fanpage (<?php echo htmlspecialchars($period); ?>)</h3>
        <p>So sánh chỉ số Reach, Views và Followers giữa các Fanpage. Nhấp vào tên trang để xem biểu đồ chi tiết tại Insights.</p>
    </div>
    
    <div style="overflow-x:auto;">
        <table class="gr-table">
            <thead>
                <tr>
                    <th>Tên Fanpage</th>
                    <th>Followers</th>
                    <th>Tăng/Giảm</th>
                    <th>Total Reach (<?php echo htmlspecialchars($period); ?>)</th>
                    <th>Total Views (<?php echo htmlspecialchars($period); ?>)</th>
                    <th>Thao Tác</th>
                </tr>
            </thead>
            <tbody id="growth-table-body">
                <tr>
                    <td colspan="6" style="text-align:center; color:#64748b; padding: 40px;">
                        <div style="display: flex; justify-content: center; align-items: center; gap: 10px; font-weight:600;">
                            <span style="display:inline-block; width:16px; height:16px; border:2px solid #cbd5e1; border-top-color:#4f46e5; border-radius:50%; animation: spin 1s linear infinite;"></span>
                            Đang tải dữ liệu tăng trưởng các Fanpage...
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Followers Summary Cards -->
<div class="gr-card">
    <div class="gr-card-head">
        <h3>👥 Tổng Quan Theo Dõi (Followers)</h3>
        <p>Thống kê số lượng Followers hiện tại và biến động tăng/giảm của từng Fanpage</p>
    </div>

    <div id="followers-cards" class="followers-grid">
        <?php foreach ($pages as $p):
            $diff = $p['followers_diff'] ?? null;
            $diff_color = '#64748b';
            $diff_badge_bg = '#f1f5f9';
            $diff_text = '—';
            if ($diff !== null) {
                if ($diff > 0) { 
                    $diff_color = '#047857'; 
                    $diff_badge_bg = '#ecfdf5';
                    $diff_text = '+' . number_format($diff); 
                } elseif ($diff < 0) { 
                    $diff_color = '#b91c1c'; 
                    $diff_badge_bg = '#fef2f2';
                    $diff_text = number_format($diff); 
                } else { 
                    $diff_text = '0'; 
                }
            }
        ?>
        <div class="follower-card-item">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                <?php if (!empty($p['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($p['avatar']); ?>" class="follower-avatar">
                <?php else: ?>
                    <div class="follower-avatar-fallback"><?php echo mb_strtoupper(mb_substr($p['name'], 0, 1)); ?></div>
                <?php endif; ?>
                <div style="font-size:13.5px; font-weight:700; color:var(--gr-text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1;" title="<?php echo htmlspecialchars($p['name']); ?>">
                    <?php echo htmlspecialchars($p['name']); ?>
                </div>
            </div>

            <div style="display:flex; align-items:baseline; justify-content:space-between;">
                <div>
                    <span style="font-size:22px; font-weight:800; color:#1e40af; font-family:'Be Vietnam Pro',sans-serif;"><?php echo number_format($p['followers_count'] ?? 0); ?></span>
                    <span style="font-size:11px; color:#64748b; font-weight:600; margin-left:4px;">followers</span>
                </div>

                <span style="font-size:12px; font-weight:700; color:<?php echo $diff_color; ?>; background:<?php echo $diff_badge_bg; ?>; padding:3px 8px; border-radius:6px;">
                    <?php echo $diff_text; ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Growth Chart Card -->
<div class="gr-card">
    <div class="gr-card-head">
        <h3>📊 So Sánh Reach &amp; Views Chi Tiết</h3>
        <p>Nhấp vào nút <strong>"Xem Biểu Đồ"</strong> ở bảng phía trên để mở biểu đồ phân tích sâu tại trang <a href="insights.php" style="color:var(--gr-primary); font-weight:700;">Insights</a>.</p>
    </div>
    
    <div id="growth-chart-area" style="height: 320px; display:flex; justify-content:center; align-items:center;">
        <canvas id="growthChart" style="display:none;"></canvas>
        <div id="growth-chart-placeholder" class="chart-placeholder-box">
            <div class="chart-placeholder-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            </div>
            <h4 style="font-size:16px; font-weight:700; color:#0f172a; margin:0 0 6px;">Chưa chọn Fanpage biểu đồ</h4>
            <p style="font-size:13px; color:#64748b; margin:0;">Chọn một Fanpage từ bảng xếp hạng phía trên để chuyển sang trang biểu đồ Insights tương tác.</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('growth-table-body');
    const nocache = new URLSearchParams(window.location.search).get('nocache');
    const url = 'actions/ajax_insights_all.php' + (nocache ? '?nocache=1' : '');
    const avatarMap = <?php echo json_encode(array_column($pages, 'avatar', 'page_id')); ?>;
    
    fetch(url)
        .then(res => res.json())
        .then(res => {
            if (res.status !== 'success') {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#dc2626; font-weight:600; padding: 30px;">Đã xảy ra lỗi khi tải dữ liệu từ máy chủ.</td></tr>';
                return;
            }
            const data = res.data;
            const keys = Object.keys(data);
            
            if (keys.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#64748b; padding: 30px;">Chưa có Fanpage nào được kết nối.</td></tr>';
                return;
            }
            
            let totalReach = 0, totalViews = 0, totalFollowers = 0;
            keys.forEach(k => { totalReach += data[k].reach; totalViews += data[k].views; totalFollowers += data[k].followers; });
            
            let html = '';
            keys.forEach(p_id => {
                const row = data[p_id];
                const reachPct = totalReach > 0 ? ((row.reach / totalReach) * 100).toFixed(1) : 0;
                const avatarImg = avatarMap[p_id] 
                    ? `<img src="${avatarMap[p_id]}" style="width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid #cbd5e1;">`
                    : `<div style="width:30px;height:30px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#64748b;font-weight:800;flex-shrink:0;">${row.name.charAt(0).toUpperCase()}</div>`;

                html += `
                    <tr>
                        <td style="font-weight: 700; color: var(--gr-text-main);">
                            <span style="display:flex; align-items:center; gap:10px;">
                                ${avatarImg}
                                <a href="insights.php?page_id=${p_id}" target="_blank" style="text-decoration: none; color: inherit; font-size:14px;">${row.name}</a>
                            </span>
                        </td>
                        <td style="font-weight: 800; color: #1e40af;">👥 ${Number(row.followers).toLocaleString()}</td>
                        <td style="font-weight: 600; color: #94a3b8;">—</td>
                        <td>
                            <div style="font-weight:800; color:#dc2626;">👁️ ${Number(row.reach).toLocaleString()}</div>
                            <div style="font-size:11px; color:#64748b; font-weight:600; margin-top:2px;">${reachPct}% tổng hệ thống</div>
                        </td>
                        <td style="color: #7c3aed; font-weight: 800;">▶ ${Number(row.views).toLocaleString()}</td>
                        <td>
                            <a href="insights.php?page_id=${p_id}" target="_blank" class="gr-action-btn">
                                <span>Xem Biểu Đồ</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                            </a>
                        </td>
                    </tr>
                `;
            });
            
            // Summary Total Row
            html += `
                <tr style="background:#f8fafc; font-weight:800; border-top:2px solid #e2e8f0;">
                    <td style="color:#0f172a; font-size:14px;">📊 TỔNG CỘNG (${keys.length} Fanpages)</td>
                    <td style="color:#1e40af; font-size:15px;">👥 ${totalFollowers.toLocaleString()}</td>
                    <td style="color:#64748b;">—</td>
                    <td style="color:#dc2626; font-size:15px;">👁️ ${totalReach.toLocaleString()}</td>
                    <td style="color:#7c3aed; font-size:15px;">▶ ${totalViews.toLocaleString()}</td>
                    <td></td>
                </tr>
            `;
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#dc2626; font-weight:600; padding: 30px;">Không kết nối được máy chủ AJAX.</td></tr>';
        });
});
</script>

<style>
@keyframes spin { 100% { transform: rotate(360deg); } }
</style>

<?php include 'includes/footer.php'; ?>