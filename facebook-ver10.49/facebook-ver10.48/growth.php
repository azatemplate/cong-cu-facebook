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

    <div class="page-title" style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:22px;">📈</span>
            <span style="font-size:20px; font-weight:700;">Page Growth</span>
        </div>
        <a href="?nocache=1" class="btn" style="background: white; border: 1px solid var(--border-color); color: var(--text-main); font-weight: 500; font-size: 13px; text-decoration: none;">
            <span style="margin-right: 5px;">🔄</span> Refresh All
        </a>
    </div>

    <div style="font-size:12px; color:var(--text-muted); margin-bottom:16px; line-height:1.6; padding:12px 16px; background:#f0fdf4; border-radius:8px; border:1px solid #bbf7d0;">
        🌱 <strong>Page Growth</strong> — Theo dõi tăng trưởng kênh: Followers, Reach (người tiếp cận), Views tổng hợp, và tốc độ phát triển của từng Fanpage.
    </div>

    <!-- All Pages Growth Table -->
    <div class="card" style="margin-bottom:25px;">
        <h3 style="margin-bottom: 5px;">🏆 Tăng Trưởng Các Fanpage (<?php echo htmlspecialchars($period); ?>)</h3>
        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
            So sánh hiệu suất Reach, Views và Followers giữa các Fanpage. Nhấp vào tên Fanpage để xem chi tiết biểu đồ từng page tại Insights.
        </div>
        
        <div style="overflow-x:auto;">
        <table style="min-width:750px;">
            <thead>
                <tr>
                    <th>Tên Fanpage</th>
                    <th>Followers</th>
                    <th>Tăng/Giảm</th>
                    <th>Total Reach (<?php echo htmlspecialchars($period); ?>)</th>
                    <th>Total Views (<?php echo htmlspecialchars($period); ?>)</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody id="growth-table-body">
                <tr><td colspan="6" style="text-align:center; color:#6b7280; padding: 30px;">
                    <div style="display: flex; justify-content: center; align-items: center; gap: 10px;">
                        <span style="display:inline-block; width:16px; height:16px; border:2px solid var(--border-color); border-top-color:#10b981; border-radius:50%; animation: spin 1s linear infinite;"></span>
                        Đang tải dữ liệu tăng trưởng...
                    </div>
                </td></tr>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Followers Summary Cards -->
    <div class="card" style="margin-bottom:25px;">
        <h3 style="margin-bottom:15px;">👥 Tổng quan Followers</h3>
        <div id="followers-cards" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:12px;">
            <?php foreach ($pages as $p):
                $diff = $p['followers_diff'] ?? null;
                $diff_color = '#6b7280';
                $diff_text = '—';
                if ($diff !== null) {
                    if ($diff > 0) { $diff_color = '#10b981'; $diff_text = '+' . number_format($diff); }
                    elseif ($diff < 0) { $diff_color = '#ef4444'; $diff_text = number_format($diff); }
                    else { $diff_text = '0'; }
                }
            ?>
            <div style="padding:14px; border:1px solid #e5e7eb; border-radius:8px; background:#fafafa;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <?php if (!empty($p['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($p['avatar']); ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                    <?php else: ?>
                        <div style="width:28px;height:28px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#64748b;font-weight:bold;flex-shrink:0;"><?php echo mb_strtoupper(mb_substr($p['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                    <div style="font-size:13px; font-weight:600; color:#374151; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1;" title="<?php echo htmlspecialchars($p['name']); ?>">
                        <?php echo htmlspecialchars($p['name']); ?>
                    </div>
                </div>
                <div style="display:flex; align-items:baseline; gap:8px;">
                    <span style="font-size:20px; font-weight:700; color:#1e40af;"><?php echo number_format($p['followers_count'] ?? 0); ?></span>
                    <span style="font-size:13px; font-weight:600; color:<?php echo $diff_color; ?>;"><?php echo $diff_text; ?></span>
                </div>
                <div style="font-size:11px; color:#9ca3af; margin-top:4px;">followers</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Growth Chart: Reach & Views for selected page -->
    <div class="card">
        <h3 style="margin-bottom:10px;">📊 So sánh Reach & Views (chọn từ bảng trên)</h3>
        <p style="font-size:12px; color:var(--text-muted); margin-bottom:20px;">Nhấp vào <strong>"Xem Biểu Đồ"</strong> ở bảng phía trên để xem biểu đồ chi tiết tại trang <a href="insights.php" style="color:var(--primary-color);">Insights</a>.</p>
        
        <div id="growth-chart-area" style="height: 350px; display:flex; justify-content:center; align-items:center;">
            <canvas id="growthChart" style="display:none;"></canvas>
            <div id="growth-chart-placeholder" style="text-align:center; color:#9ca3af;">
                <div style="font-size:48px; margin-bottom:10px;">📈</div>
                <div>Chọn Fanpage ở bảng trên hoặc sang trang Insights để xem biểu đồ chi tiết.</div>
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
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#ef4444; padding: 30px;">Đã xảy ra lỗi khi tải dữ liệu.</td></tr>';
                    return;
                }
                const data = res.data;
                const keys = Object.keys(data);
                
                if (keys.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#6b7280; padding: 30px;">Chưa có Fanpage nào.</td></tr>';
                    return;
                }
                
                // Tính tổng reach, views
                let totalReach = 0, totalViews = 0, totalFollowers = 0;
                keys.forEach(k => { totalReach += data[k].reach; totalViews += data[k].views; totalFollowers += data[k].followers; });
                
                let html = '';
                keys.forEach(p_id => {
                    const row = data[p_id];
                    const reachPct = totalReach > 0 ? ((row.reach / totalReach) * 100).toFixed(1) : 0;
                    html += `
                        <tr>
                            <td style="font-weight: 500; color: var(--text-main);">
                                <span style="display:flex; align-items:center; gap:8px;">
                                    ${avatarMap[p_id] ? `<img src="${avatarMap[p_id]}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;">` : `<div style="width:28px;height:28px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#64748b;font-weight:bold;flex-shrink:0;">${row.name.charAt(0).toUpperCase()}</div>`}
                                    <a href="insights.php?page_id=${p_id}" target="_blank" style="text-decoration: none; color: inherit;">${row.name}</a>
                                </span>
                            </td>
                            <td style="font-weight: 600; color: #1e40af;">👥 ${Number(row.followers).toLocaleString()}</td>
                            <td style="font-weight: 600; color: #9ca3af;">—</td>
                            <td>
                                <div style="font-weight:600; color:#ef4444;">👁️ ${Number(row.reach).toLocaleString()}</div>
                                <div style="font-size:11px; color:#9ca3af;">${reachPct}% tổng</div>
                            </td>
                            <td style="color: #8b5cf6; font-weight: 600;">▶ ${Number(row.views).toLocaleString()}</td>
                            <td>
                                <a href="insights.php?page_id=${p_id}" target="_blank" class="btn" style="padding: 6px 12px; font-size: 12px; border: 1px solid var(--border-color); color: var(--text-main); background: var(--bg-color);">Xem Biểu Đồ</a>
                            </td>
                        </tr>
                    `;
                });
                
                // Tổng hàng
                html += `
                    <tr style="background:#f8fafc; font-weight:700;">
                        <td>📊 TỔNG (${keys.length} pages)</td>
                        <td style="color:#1e40af;">👥 ${totalFollowers.toLocaleString()}</td>
                        <td>—</td>
                        <td style="color:#ef4444;">👁️ ${totalReach.toLocaleString()}</td>
                        <td style="color:#8b5cf6;">▶ ${totalViews.toLocaleString()}</td>
                        <td></td>
                    </tr>
                `;
                tbody.innerHTML = html;
            })
            .catch(err => {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#ef4444; padding: 30px;">Không kết nối được server.</td></tr>';
            });
    });
    </script>
    <style>
    @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>

<?php include 'includes/footer.php'; ?>