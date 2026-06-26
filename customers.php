<?php
// customers.php
$current_page = 'customers';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];

// ── Fetch all customers with phone from both platforms ─────────────────────
$customers = [];

// 1. Zalo customers
try {
    $stmt_z = $pdo->prepare("
        SELECT zc.name, zc.phone, zc.province, zc.notes, zc.sales_phone, zc.sales_notes, 
               zc.updated_at, zc.oa_id, zc.sender_id, zo.name AS oa_name,
               'Zalo' AS platform
        FROM zalo_customers zc
        JOIN zalo_oas zo ON zc.oa_id = zo.oa_id
        WHERE zo.account_id = ?
          AND zc.phone IS NOT NULL AND zc.phone != ''
        ORDER BY zc.updated_at DESC
    ");
    $stmt_z->execute([$account_id]);
    $zalo_custs = $stmt_z->fetchAll(PDO::FETCH_ASSOC);
    foreach ($zalo_custs as $c) {
        $customers[] = $c;
    }
} catch (Exception $e) {}

// 2. Facebook customers
try {
    $stmt_f = $pdo->prepare("
        SELECT fc.name, fc.phone, fc.province, fc.notes, fc.sales_phone, fc.sales_notes,
               fc.updated_at, fc.page_id AS oa_id, fc.sender_id, p.name AS oa_name,
               'Facebook' AS platform
        FROM fb_customers fc
        JOIN pages p ON fc.page_id = p.page_id
        LEFT JOIN users u ON p.user_id = u.id
        WHERE (u.account_id = ? OR EXISTS (
            SELECT 1 FROM page_shares ps 
            WHERE ps.page_id = fc.page_id 
              AND ps.shared_with_account_id = ?
        ))
          AND fc.phone IS NOT NULL AND fc.phone != ''
        ORDER BY fc.updated_at DESC
    ");
    $stmt_f->execute([$account_id, $account_id]);
    $fb_custs = $stmt_f->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fb_custs as $c) {
        $customers[] = $c;
    }
} catch (Exception $e) {}

// Sort by updated_at desc
usort($customers, function($a, $b) {
    return strtotime($b['updated_at'] ?? '2000-01-01') - strtotime($a['updated_at'] ?? '2000-01-01');
});

// Get unique provinces for filter
$provinces = [];
foreach ($customers as $c) {
    if (!empty($c['province']) && !in_array($c['province'], $provinces)) {
        $provinces[] = $c['province'];
    }
}
sort($provinces);

$total_customers = count($customers);
?>

<style>
    .platform-tab-btn:hover {
        color: #0068ff !important;
        border-bottom-color: #cbd5e1 !important;
    }
    .platform-tab-btn.active:hover {
        border-bottom-color: #0068ff !important;
    }
    .cust-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 13px;
    }
    .cust-table thead th {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        padding: 12px 14px;
        text-align: left;
        font-weight: 700;
        color: #374151;
        border-bottom: 2px solid #e2e8f0;
        position: sticky;
        top: 0;
        z-index: 2;
        white-space: nowrap;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .cust-table tbody tr {
        transition: background 0.15s;
    }
    .cust-table tbody tr:hover {
        background: #f0f7ff;
    }
    .cust-table tbody td {
        padding: 10px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: top;
        color: #374151;
    }
    .cust-table tbody tr:last-child td {
        border-bottom: none;
    }
    .badge-platform {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 50px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.3px;
    }
    .badge-zalo {
        background: #e0f2fe;
        color: #0068ff;
    }
    .badge-fb {
        background: #ede9fe;
        color: #6d28d9;
    }
    .phone-link {
        font-weight: 600;
        color: #059669;
        text-decoration: none;
        font-family: 'Courier New', monospace;
        font-size: 13px;
    }
    .phone-link:hover {
        text-decoration: underline;
    }
    .notes-cell {
        max-width: 300px;
        font-size: 12px;
        color: #6b7280;
        line-height: 1.5;
    }
    .notes-cell .sales-info {
        margin-top: 4px;
        padding-top: 4px;
        border-top: 1px dashed #e5e7eb;
        color: #d97706;
        font-size: 11px;
    }
    .filter-bar {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .filter-bar input, .filter-bar select {
        padding: 8px 14px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 13px;
        background: #fff;
        outline: none;
        transition: border-color 0.2s;
    }
    .filter-bar input:focus, .filter-bar select:focus {
        border-color: #0068ff;
        box-shadow: 0 0 0 3px rgba(0,104,255,0.1);
    }
    .stats-bar {
        display: flex;
        gap: 16px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    .stat-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 160px;
    }
    .stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    .stat-value {
        font-size: 24px;
        font-weight: 800;
        color: #1e293b;
        line-height: 1;
    }
    .stat-label {
        font-size: 12px;
        color: #9ca3af;
        margin-top: 2px;
    }
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #9ca3af;
    }
    .empty-state span {
        font-size: 48px;
        display: block;
        margin-bottom: 12px;
    }
    .province-tag {
        display: inline-block;
        padding: 2px 8px;
        background: #f0fdf4;
        color: #15803d;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 500;
    }
    .export-btn {
        padding: 8px 16px;
        background: #059669;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: background 0.2s;
    }
    .export-btn:hover {
        background: #047857;
    }
</style>

<!-- Platform Switcher Tabs -->
<div class="platform-tabs" style="display: flex; gap: 20px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; padding-bottom: 0;">
    <a href="live_chat.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>📘</span> Facebook Fanpage
    </a>
    <a href="live-chat-oa.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>💬</span> Zalo Official Account
    </a>
    <a href="customers.php" class="platform-tab-btn active" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #0068ff; border-bottom: 3px solid #0068ff; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>👥</span> Khách Hàng
    </a>
</div>

<!-- Page Title -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
    <div class="page-title" style="margin-bottom:0;">👥 Danh Sách Khách Hàng</div>
    <button onclick="exportCSV()" class="export-btn">
        <span>📥</span> Xuất Excel (CSV)
    </button>
</div>

<!-- Stats Bar -->
<?php
$count_zalo = count(array_filter($customers, fn($c) => $c['platform'] === 'Zalo'));
$count_fb = count(array_filter($customers, fn($c) => $c['platform'] === 'Facebook'));
$count_province = count(array_filter($customers, fn($c) => !empty($c['province'])));
?>
<div class="stats-bar">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);">📋</div>
        <div>
            <div class="stat-value"><?php echo $total_customers; ?></div>
            <div class="stat-label">Tổng khách hàng</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#e0f2fe,#bae6fd);">💬</div>
        <div>
            <div class="stat-value"><?php echo $count_zalo; ?></div>
            <div class="stat-label">Từ Zalo</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ede9fe,#ddd6fe);">📘</div>
        <div>
            <div class="stat-value"><?php echo $count_fb; ?></div>
            <div class="stat-label">Từ Facebook</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);">📍</div>
        <div>
            <div class="stat-value"><?php echo $count_province; ?></div>
            <div class="stat-label">Có tỉnh thành</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <input type="text" id="searchInput" placeholder="🔍 Tìm tên, SĐT, ghi chú..." style="min-width:260px;" oninput="filterTable()">
    <select id="filterPlatform" onchange="filterTable()">
        <option value="">Tất cả nền tảng</option>
        <option value="Zalo">💬 Zalo</option>
        <option value="Facebook">📘 Facebook</option>
    </select>
    <select id="filterProvince" onchange="filterTable()">
        <option value="">Tất cả tỉnh thành</option>
        <?php foreach ($provinces as $p): ?>
            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
        <?php endforeach; ?>
    </select>
    <span id="filterCount" style="font-size:12px; color:#9ca3af; margin-left:auto;"></span>
</div>

<!-- Customer Table -->
<div class="card" style="padding:0; overflow:hidden;">
    <?php if (empty($customers)): ?>
        <div class="empty-state">
            <span>📭</span>
            <h3 style="margin:0 0 6px 0; color:#6b7280;">Chưa có khách hàng nào</h3>
            <p style="margin:0; font-size:13px;">Khách hàng sẽ xuất hiện khi họ cung cấp số điện thoại qua Live Chat (Facebook/Zalo).</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto; max-height:75vh; overflow-y:auto;">
            <table class="cust-table" id="customerTable">
                <thead>
                    <tr>
                        <th style="width:50px; text-align:center;">STT</th>
                        <th>Tên khách hàng</th>
                        <th>Số điện thoại</th>
                        <th>Nền tảng</th>
                        <th>Tỉnh thành</th>
                        <th>Yêu cầu / Ghi chú tích lũy</th>
                        <th style="width:100px;">Cập nhật</th>
                    </tr>
                </thead>
                <tbody id="customerBody">
                    <?php foreach ($customers as $i => $c): ?>
                        <tr data-platform="<?php echo $c['platform']; ?>"
                            data-province="<?php echo htmlspecialchars($c['province'] ?? ''); ?>"
                            data-search="<?php echo htmlspecialchars(strtolower(($c['name'] ?? '') . ' ' . ($c['phone'] ?? '') . ' ' . ($c['notes'] ?? '') . ' ' . ($c['province'] ?? '') . ' ' . ($c['oa_name'] ?? '') . ' ' . ($c['sales_phone'] ?? '') . ' ' . ($c['sales_notes'] ?? ''))); ?>">
                            <td style="text-align:center; color:#9ca3af; font-weight:600;"><?php echo ($i + 1); ?></td>
                            <td>
                                <div style="font-weight:600; color:#1e293b;"><?php echo htmlspecialchars($c['name'] ?? 'Không tên'); ?></div>
                                <div style="font-size:11px; color:#9ca3af; margin-top:2px;"><?php echo htmlspecialchars($c['oa_name'] ?? ''); ?></div>
                            </td>
                            <td>
                                <a href="tel:<?php echo htmlspecialchars($c['phone']); ?>" class="phone-link"><?php echo htmlspecialchars($c['phone']); ?></a>
                            </td>
                            <td>
                                <?php if ($c['platform'] === 'Zalo'): ?>
                                    <span class="badge-platform badge-zalo">💬 Zalo</span>
                                <?php else: ?>
                                    <span class="badge-platform badge-fb">📘 Facebook</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['province'])): ?>
                                    <span class="province-tag">📍 <?php echo htmlspecialchars($c['province']); ?></span>
                                <?php else: ?>
                                    <span style="color:#d1d5db; font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="notes-cell">
                                <?php if (!empty($c['notes'])): ?>
                                    <div><?php echo nl2br(htmlspecialchars($c['notes'])); ?></div>
                                <?php else: ?>
                                    <span style="color:#d1d5db;">—</span>
                                <?php endif; ?>
                                <?php if (!empty($c['sales_phone']) || !empty($c['sales_notes'])): ?>
                                    <div class="sales-info">
                                        <?php if (!empty($c['sales_phone'])): ?>
                                            📞 Sales: <?php echo htmlspecialchars($c['sales_phone']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($c['sales_notes'])): ?>
                                            <br>📝 <?php echo htmlspecialchars($c['sales_notes']); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11px; color:#9ca3af; white-space:nowrap;">
                                <?php 
                                    if (!empty($c['updated_at'])) {
                                        echo date('d/m/Y', strtotime($c['updated_at']));
                                        echo '<br>' . date('H:i', strtotime($c['updated_at']));
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function filterTable() {
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    const platform = document.getElementById('filterPlatform').value;
    const province = document.getElementById('filterProvince').value;
    const rows = document.querySelectorAll('#customerBody tr');
    let visible = 0;
    let stt = 0;

    rows.forEach(row => {
        const matchSearch = !search || row.dataset.search.includes(search);
        const matchPlatform = !platform || row.dataset.platform === platform;
        const matchProvince = !province || row.dataset.province === province;

        if (matchSearch && matchPlatform && matchProvince) {
            row.style.display = '';
            stt++;
            row.cells[0].textContent = stt;
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('filterCount').textContent = `Hiển thị ${visible} / ${<?php echo $total_customers; ?>} khách hàng`;
}

function exportCSV() {
    const rows = document.querySelectorAll('#customerBody tr');
    let csv = '\uFEFF'; // BOM for Excel UTF-8
    csv += 'STT,Tên,Số Điện Thoại,Nền Tảng,Tỉnh Thành,Yêu Cầu / Ghi Chú,Cập Nhật\n';
    
    let stt = 0;
    rows.forEach(row => {
        if (row.style.display === 'none') return;
        stt++;
        const cells = row.querySelectorAll('td');
        const name = cells[1].querySelector('div').textContent.trim();
        const phone = cells[2].textContent.trim();
        const platform = cells[3].textContent.trim();
        const province = cells[4].textContent.trim().replace('📍 ', '');
        const notes = cells[5].textContent.trim().replace(/\n/g, ' ');
        const updated = cells[6].textContent.trim().replace(/\n/g, ' ');
        
        csv += `${stt},"${name}","${phone}","${platform}","${province}","${notes}","${updated}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `khach_hang_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// Show initial count
filterTable();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
