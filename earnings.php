<?php
$current_page = 'earnings';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Fetch pages for JS dropdown
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
$pages_json = json_encode($pages);
?>

    <div class="page-title" style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="color:#10b981; font-size:24px;">💰</span> 
            <span style="font-size:20px; font-weight:700;">Earnings CM</span>
        </div>
        <button onclick="fetchEarningsData()" class="btn" style="background: white; border: 1px solid var(--border-color); color: var(--text-main); font-weight: 500; font-size: 13px; text-decoration: none; cursor:pointer;">
            <span style="margin-right: 5px;">🔄</span> Refresh All
        </button>
    </div>

    <!-- Page Selector Box (Custom with avatars) -->
    <div class="card" style="margin-bottom: 20px; padding: 15px 20px; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); overflow: visible !important;">
        <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:12px;">Chọn Fanpage để xem thu nhập:</label>
        <div style="display:flex; gap:10px; align-items: flex-start;">
            <input type="hidden" id="earnings_page_selector" value="">
            <div style="flex:1; position:relative;" id="customSelectWrap">
                <div id="customSelectBtn" style="display:flex;align-items:center;padding:8px 14px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;background:#fff;min-height:40px;box-shadow: 0 1px 2px rgba(0,0,0,0.05);" onclick="togglePageDropdown()">
                    <span id="sel_avatar_wrap" style="display:none;margin-right:10px;"></span>
                    <span id="sel_name_text" style="flex:1;font-size:14px;color:#9ca3af;">Tìm và chọn pages...</span>
                    <span style="color:#9ca3af;font-size:12px;">▼</span>
                </div>
                <!-- Dropdown list -->
                <div id="customSelectDropdown" style="display:none;position:absolute;left:0;right:0;top:100%;margin-top:6px;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.1);z-index:999;max-height:360px;overflow:hidden;">
                    <div style="padding:10px;border-bottom:1px solid #e5e7eb;background:#f9fafb;">
                        <input type="text" id="pageSearchInput" placeholder="🔍 Tìm Fanpage..." style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box;outline:none;">
                    </div>
                    <div id="pageOptionsList" style="overflow-y:auto;max-height:280px;">
                        <?php foreach ($pages as $p): ?>
                        <div class="page-option" data-page-id="<?php echo htmlspecialchars($p['page_id']); ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-avatar="<?php echo htmlspecialchars($p['avatar'] ?? ''); ?>" style="display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer;transition:all 0.1s;border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                            <?php if (!empty($p['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($p['avatar']); ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                            <?php else: ?>
                                <div style="width:32px;height:32px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:13px;color:#64748b;font-weight:bold;flex-shrink:0;"><?php echo mb_strtoupper(mb_substr($p['name'], 0, 1)); ?></div>
                            <?php endif; ?>
                            <span style="font-size:13px;font-weight:500;color:#374151;"><?php echo htmlspecialchars($p['name']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <button onclick="fetchEarningsData()" class="btn btn-primary" style="padding: 10px 18px; background:#f3f4f6; color:#9ca3af; border:1px solid #e5e7eb; cursor:not-allowed; border-radius:6px; font-weight:600; font-size:13px;" id="btn_fetch_earnings">Fetch Earnings</button>
        </div>
    </div>

    <!-- Earnings Summary Stats -->
    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap:15px; margin-bottom: 20px;">
        <div class="stat-card" style="background:white; border:1px solid #e5e7eb; padding:15px 20px; border-radius:8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
            <div class="stat-title" style="color:#6b7280; font-size:12px; font-weight:500; margin-bottom:8px;">Total 28 ngày</div>
            <div class="stat-value" id="stat_total_28" style="color:#10b981; font-size:24px; font-weight:700;">$ 0.00</div>
        </div>
        
        <div class="stat-card" style="background:white; border:1px solid #e5e7eb; padding:15px 20px; border-radius:8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
            <div class="stat-title" style="color:#6b7280; font-size:12px; font-weight:500; margin-bottom:8px;">Total 7 ngày</div>
            <div class="stat-value" id="stat_total_7" style="color:#3b82f6; font-size:24px; font-weight:700;">$ 0.00</div>
        </div>
        
        <div class="stat-card" style="background:white; border:1px solid #e5e7eb; padding:15px 20px; border-radius:8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
            <div class="stat-title" style="color:#6b7280; font-size:12px; font-weight:500; margin-bottom:8px;">Trung bình/ngày</div>
            <div class="stat-value" id="stat_avg_day" style="color:#1f2937; font-size:24px; font-weight:700;">$ 0.00</div>
        </div>
        
        <div class="stat-card" style="background:white; border:1px solid #e5e7eb; padding:15px 20px; border-radius:8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
            <div class="stat-title" style="color:#6b7280; font-size:12px; font-weight:500; margin-bottom:8px;">Growth 7d</div>
            <div class="stat-value" id="stat_growth" style="color:#10b981; font-size:24px; font-weight:700;">0.0 %</div>
        </div>
    </div>

    <!-- Bar Chart Box -->
    <div class="card" style="border:1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding:0; overflow:hidden;">
        <div style="padding:15px 20px; border-bottom:1px solid #e5e7eb;">
            <h3 style="margin:0; font-size:14px; color:#374151; font-weight:600; display:flex; align-items:center; gap:6px;">
                <span>📈</span> Daily Earnings (28 ngày)
            </h3>
        </div>
        <div style="padding:20px; height: 350px; position: relative;">
            <div id="earnings_loader" style="display:none; position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.8); z-index:10; justify-content:center; align-items:center; flex-direction:column;">
                <span style="display:inline-block; width:30px; height:30px; border:3px solid #e5e7eb; border-top-color:#10b981; border-radius:50%; animation: spin 1s linear infinite; margin-bottom:10px;"></span>
                <span id="earnings_loader_text" style="color:#6b7280; font-size:13px; font-weight:500;">Đang lấy dữ liệu từ Facebook Ads... (Gồm 30 video gần nhất)</span>
            </div>
            <canvas id="earningsChart"></canvas>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    let earningsChartInstance = null;

    // Custom Dropdown Logic
    (function(){
        const dropdown = document.getElementById('customSelectDropdown');
        const searchInput = document.getElementById('pageSearchInput');
        const hiddenSelector = document.getElementById('earnings_page_selector');
        const selNameText = document.getElementById('sel_name_text');
        const selAvatarWrap = document.getElementById('sel_avatar_wrap');
        const fetchBtn = document.getElementById('btn_fetch_earnings');
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

        function filterOptions(q) {
            document.querySelectorAll('.page-option').forEach(opt => {
                const name = (opt.getAttribute('data-name') || '').toLowerCase();
                opt.style.display = (!q || name.includes(q)) ? 'flex' : 'none';
            });
        }

        searchInput.addEventListener('input', function() { filterOptions(this.value.trim().toLowerCase()); });
        searchInput.addEventListener('click', function(e) { e.stopPropagation(); });

        document.querySelectorAll('.page-option').forEach(opt => {
            opt.addEventListener('click', function(e) {
                e.stopPropagation();
                const pageId = this.getAttribute('data-page-id');
                const name = this.getAttribute('data-name');
                const avatar = this.getAttribute('data-avatar');

                hiddenSelector.value = pageId;
                selNameText.innerText = name;
                selNameText.style.color = '#1f2937';

                if (avatar) {
                    selAvatarWrap.innerHTML = `<img src="${avatar}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">`;
                    selAvatarWrap.style.display = 'block';
                } else {
                    selAvatarWrap.innerHTML = `<div style="width:28px;height:28px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#64748b;font-weight:bold;">${name.charAt(0).toUpperCase()}</div>`;
                    selAvatarWrap.style.display = 'block';
                }

                // Update Fetch Button Style
                fetchBtn.style.background = '#10b981';
                fetchBtn.style.color = 'white';
                fetchBtn.style.border = '1px solid #059669';
                fetchBtn.style.cursor = 'pointer';

                dropdown.style.display = 'none';
                isOpen = false;
                
                fetchEarningsData();
            });
        });

        document.addEventListener('click', function(e) {
            if (!document.getElementById('customSelectWrap').contains(e.target)) {
                dropdown.style.display = 'none';
                isOpen = false;
            }
        });
    })();

    function initChart() {
        const ctx = document.getElementById('earningsChart').getContext('2d');
        earningsChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'Earnings ($)',
                    data: [],
                    backgroundColor: '#34d399',
                    borderWidth: 0,
                    borderRadius: 2,
                    barPercentage: 0.9,
                    categoryPercentage: 0.9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.9)',
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 14 },
                        padding: 12,
                        cornerRadius: 6,
                        callbacks: {
                            label: function(ctx) {
                                return ' $' + ctx.parsed.y.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6b7280', font: { size: 10 } }
                    },
                    y: {
                        display: false,
                        beginAtZero: true
                    }
                }
            }
        });
    }

    function fetchEarningsData() {
        const pageId = document.getElementById('earnings_page_selector').value;
        if (!pageId) return;

        document.getElementById('earnings_loader').style.display = 'flex';
        
        fetch('actions/ajax_fetch_earnings.php?page_id=' + encodeURIComponent(pageId))
            .then(res => res.json())
            .then(data => {
                document.getElementById('earnings_loader').style.display = 'none';
                
                if (data.status === 'success') {
                    document.getElementById('stat_total_28').innerText = '$ ' + data.stats.total_28.toFixed(2);
                    document.getElementById('stat_total_7').innerText = '$ ' + data.stats.total_7.toFixed(2);
                    document.getElementById('stat_avg_day').innerText = '$ ' + data.stats.avg_day.toFixed(2);
                    
                    const growthEl = document.getElementById('stat_growth');
                    if (data.stats.growth >= 0) {
                        growthEl.innerText = '↗ +' + data.stats.growth.toFixed(1) + ' %';
                        growthEl.style.color = '#10b981';
                    } else {
                        growthEl.innerText = '↘ ' + data.stats.growth.toFixed(1) + ' %';
                        growthEl.style.color = '#ef4444';
                    }

                    earningsChartInstance.data.labels = data.chart.labels;
                    earningsChartInstance.data.datasets[0].data = data.chart.values;
                    earningsChartInstance.update();
                } else {
                    alert(data.msg || 'Không thể lấy dữ liệu thu nhập.');
                }
            })
            .catch(err => {
                document.getElementById('earnings_loader').style.display = 'none';
                alert('Lỗi kết nối đến máy chủ.');
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        initChart();
    });
    </script>
    <style>
    @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>

<?php include 'includes/footer.php'; ?>