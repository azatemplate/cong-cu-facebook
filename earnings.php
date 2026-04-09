<?php
$current_page = 'earnings';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Fetch pages for JS dropdown
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

    <!-- Page Selector Box (matches screenshot) -->
    <div class="card" style="margin-bottom: 20px; padding: 15px 20px; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
        <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:8px;">Chọn pages để track earnings:</label>
        <div style="display:flex; gap:10px;">
            <select id="earnings_page_selector" class="form-control" style="flex:1; border:1px solid #d1d5db; border-radius:6px; padding:8px 12px; color:#4b5563;">
                <option value="">Tìm và chọn pages...</option>
                <?php foreach ($pages as $p): ?>
                    <option value="<?php echo htmlspecialchars($p['page_id']); ?>">
                        <?php echo htmlspecialchars($p['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button onclick="fetchEarningsData()" class="btn btn-primary" style="padding: 8px 16px; background:#f3f4f6; color:#9ca3af; border:1px solid #e5e7eb; cursor:not-allowed;" id="btn_fetch_earnings">Fetch Earnings</button>
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

    document.getElementById('earnings_page_selector').addEventListener('change', function() {
        const btn = document.getElementById('btn_fetch_earnings');
        if (this.value) {
            btn.style.background = '#10b981';
            btn.style.color = 'white';
            btn.style.border = '1px solid #059669';
            btn.style.cursor = 'pointer';
            fetchEarningsData();
        } else {
            btn.style.background = '#f3f4f6';
            btn.style.color = '#9ca3af';
            btn.style.border = '1px solid #e5e7eb';
            btn.style.cursor = 'not-allowed';
        }
    });

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