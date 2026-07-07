<?php
// customers.php
$current_page = 'customers';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];

$sync_lock_active = false;
$sync_lock_file = __DIR__ . '/locks/sync_' . intval($account_id) . '.lock';
if (file_exists($sync_lock_file) && (time() - filemtime($sync_lock_file) < 900)) {
    $sync_lock_active = true;
}

$scan_lock_active = false;
$scan_lock_file = __DIR__ . '/locks/scan_' . intval($account_id) . '.lock';
if (file_exists($scan_lock_file) && (time() - filemtime($scan_lock_file) < 180)) {
    $scan_lock_active = true;
}

// ── FETCH ALL CUSTOMERS FROM BOTH PLATFORMS ─────────────────────────────────
$customers = [];
$provinces = [];

try {
    // 1. Zalo customers
    $stmt_z = $pdo->prepare("
        SELECT zc.name, zc.phone, zc.province, zc.notes, zc.sales_phone, zc.sales_notes, 
               zc.updated_at, zc.oa_id, zc.sender_id, zo.name AS oa_name,
               'Zalo' AS platform, 0 AS is_ads, NULL AS ad_title
        FROM zalo_customers zc
        JOIN zalo_oas zo ON zc.oa_id = zo.oa_id
        WHERE zo.account_id = ?
    ");
    $stmt_z->execute([$account_id]);
    $zalo_custs = $stmt_z->fetchAll(PDO::FETCH_ASSOC);
    foreach ($zalo_custs as $c) {
        $customers[] = $c;
        if (!empty($c['province']) && !in_array($c['province'], $provinces)) {
            $provinces[] = $c['province'];
        }
    }
} catch (Exception $e) {}

// 2. Facebook customers
try {
    $stmt_f = $pdo->prepare("
        SELECT fc.name, fc.phone, fc.province, fc.notes, fc.sales_phone, fc.sales_notes,
               fc.updated_at, fc.page_id AS oa_id, fc.sender_id, p.name AS oa_name,
               'Facebook' AS platform, fc.is_ads, fc.ad_title
        FROM fb_customers fc
        JOIN pages p ON fc.page_id = p.page_id
        LEFT JOIN users u ON p.user_id = u.id
        WHERE u.account_id = ? OR EXISTS (
            SELECT 1 FROM page_shares ps 
            WHERE ps.page_id = fc.page_id 
              AND ps.shared_with_account_id = ?
        )
    ");
    $stmt_f->execute([$account_id, $account_id]);
    $fb_custs = $stmt_f->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fb_custs as $c) {
        $customers[] = $c;
        if (!empty($c['province']) && !in_array($c['province'], $provinces)) {
            $provinces[] = $c['province'];
        }
    }
} catch (Exception $e) {}

// Sort by updated_at desc
usort($customers, function($a, $b) {
    return strtotime($b['updated_at'] ?? '2000-01-01') - strtotime($a['updated_at'] ?? '2000-01-01');
});

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
        word-break: break-word;
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
        padding: 6px 10px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 12px;
        background: #fff;
        outline: none;
        transition: border-color 0.2s;
        height: 34px;
        box-sizing: border-box;
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
        flex: 1;
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
    
    /* Pagination Styles */
    .pagin-btn {
        border: 1px solid #d1d5db;
        background: #fff;
        color: #374151;
        padding: 6px 12px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s;
    }
    .pagin-btn:hover:not(.disabled) {
        border-color: #cbd5e1;
        background: #f8fafc;
    }
    .pagin-btn.active {
        border-color: var(--primary-color);
        background: var(--primary-color);
        color: #fff;
        font-weight: 600;
    }
    .pagin-btn.disabled {
        opacity: 0.5;
        cursor: not-allowed;
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

<!-- Page Title & Actions -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div class="page-title" style="margin-bottom:0;">👥 Danh Sách Khách Hàng</div>
    <div style="display:flex; gap:10px;">
        <button id="btn_sync_all_history" onclick="triggerSyncAllHistory()" class="btn" style="background:#0284c7; color:#fff; border:none; border-radius:8px; padding:8px 16px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; transition: background 0.2s;" <?php echo $sync_lock_active ? 'disabled' : ''; ?>>
            <span>🔄</span> <?php echo $sync_lock_active ? 'Đang đồng bộ ngầm...' : 'Đồng bộ lịch sử Facebook'; ?>
        </button>
        <button id="btn_scan_old_phones" onclick="triggerScanOldPhones()" class="btn" style="background:#10b981; color:#fff; border:none; border-radius:8px; padding:8px 16px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; transition: background 0.2s;" <?php echo $scan_lock_active ? 'disabled' : ''; ?>>
            <span>🔍</span> <?php echo $scan_lock_active ? 'Đang quét ngầm...' : 'Quét SĐT tin cũ'; ?>
        </button>
        <button onclick="exportCSV()" class="export-btn">
            <span>📥</span> Xuất Excel (CSV)
        </button>
    </div>
</div>

<!-- Stats Bar (Calculated in Realtime) -->
<div class="stats-bar">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);">📋</div>
        <div>
            <div class="stat-value" id="stat_total">0</div>
            <div class="stat-label">Tổng khách hàng</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#e0f2fe,#bae6fd);">💬</div>
        <div>
            <div class="stat-value" id="stat_zalo">0</div>
            <div class="stat-label">Từ Zalo</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ede9fe,#ddd6fe);">📘</div>
        <div>
            <div class="stat-value" id="stat_fb">0</div>
            <div class="stat-label">Từ Facebook</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ffe4e6,#fecdd3);">📢</div>
        <div>
            <div class="stat-value" id="stat_ads">0</div>
            <div class="stat-label">Từ Quảng cáo</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#fef3c7,#fde68a);">📞</div>
        <div>
            <div class="stat-value" id="stat_phone">0</div>
            <div class="stat-label">Có số điện thoại</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);">📍</div>
        <div>
            <div class="stat-value" id="stat_province">0</div>
            <div class="stat-label">Có tỉnh thành</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <input type="text" id="searchInput" placeholder="🔍 Tìm tên, SĐT, ghi chú..." style="width:200px;" oninput="onFilterChange()">
    
    <select id="filterPlatform" onchange="onFilterChange()" style="width:120px;">
        <option value="">Nền tảng</option>
        <option value="Zalo">💬 Zalo</option>
        <option value="Facebook">📘 Facebook</option>
    </select>

    <select id="filterSource" onchange="onFilterChange()" style="width:120px;">
        <option value="">Nguồn khách</option>
        <option value="ads">📢 Từ Ads</option>
        <option value="free">🆓 Miễn phí</option>
    </select>
    
    <select id="filterPhone" onchange="onFilterChange()" style="width:130px;">
        <option value="">Số điện thoại</option>
        <option value="has_phone">📞 Có SĐT</option>
        <option value="no_phone">❌ Chưa có SĐT</option>
    </select>
    
    <select id="filterProvince" onchange="onFilterChange()" style="width:125px;">
        <option value="">Tỉnh thành</option>
        <?php foreach ($provinces as $p): ?>
            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
        <?php endforeach; ?>
    </select>

    <select id="filterDateRange" onchange="onDateRangeChange()" style="width:120px;">
        <option value="all">Thời gian</option>
        <option value="this_week">Tuần này</option>
        <option value="this_month">Tháng này</option>
        <option value="last_30_days">30 ngày qua</option>
        <option value="custom">Tùy chỉnh...</option>
    </select>
    
    <div id="customDateContainer" style="display:none; align-items:center; gap:8px;">
        <input type="date" id="filterDateStart" onchange="onFilterChange()" style="padding:6px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px; color:#334155; height:34px;">
        <span style="font-size:12px; color:#6b7280;">đến</span>
        <input type="date" id="filterDateEnd" onchange="onFilterChange()" style="padding:6px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px; color:#334155; height:34px;">
    </div>

    <select id="filterLimit" onchange="onLimitChange()" style="width:95px;">
        <option value="50">50 / trang</option>
        <option value="100">100 / trang</option>
        <option value="200">200 / trang</option>
        <option value="500">500 / trang</option>
    </select>
    
    <span id="filterCount" style="font-size:12px; color:#9ca3af; margin-left:auto;">Hiển thị 0 / 0 khách hàng</span>
</div>

<!-- Customer Table Card -->
<div class="card" style="padding:0; overflow:hidden;">
    <div style="overflow-x:auto; max-height:75vh; overflow-y:auto;">
        <table class="cust-table" id="customerTable">
            <thead>
                <tr>
                    <th style="width:50px; text-align:center;">STT</th>
                    <th style="width:220px;">Tên khách hàng</th>
                    <th style="width:140px;">Số điện thoại</th>
                    <th style="width:130px;">Nền tảng</th>
                    <th style="width:130px;">Tỉnh thành</th>
                    <th>Yêu cầu / Ghi chú tích lũy</th>
                    <th style="width:120px;">Cập nhật</th>
                </tr>
            </thead>
            <tbody id="customerBody">
                <!-- Javascript will render rows here -->
            </tbody>
        </table>
        
        <div id="table_empty_state" class="empty-state" style="display:none;">
            <span>📭</span>
            <h3 style="margin:0 0 6px 0; color:#6b7280;">Không tìm thấy khách hàng nào</h3>
            <p style="margin:0; font-size:13px;">Hãy thử thay đổi điều kiện tìm kiếm hoặc đồng bộ lại lịch sử.</p>
        </div>
    </div>
    
    <!-- Client Pagination Controls -->
    <div id="pagination_container" style="display:flex; justify-content:center; align-items:center; gap:8px; padding:20px; border-top:1px solid #f1f5f9; background:#fafafa;">
        <!-- Javascript will render pagination here -->
    </div>
</div>

<script>
// Bắt toàn bộ lỗi JS và Promise để hiển thị Alert chẩn đoán
window.onerror = function(message, source, lineno, colno, error) {
    alert("Lỗi JavaScript: " + message + " tại " + source + ":" + lineno);
    return false;
};
window.onunhandledrejection = function(event) {
    alert("Lỗi Promise (Bất đồng bộ): " + event.reason);
};

// Load full data into memory from PHP
const allCustomers = <?php echo json_encode($customers); ?>;
let filteredCustomers = [...allCustomers];
let currentPage = 1;
let rowsPerPage = 50;

// Update global stats counters based on loaded data
function updateStats(list) {
    document.getElementById('stat_total').textContent = list.length;
    document.getElementById('stat_zalo').textContent = list.filter(c => c.platform === 'Zalo').length;
    document.getElementById('stat_fb').textContent = list.filter(c => c.platform === 'Facebook').length;
    document.getElementById('stat_ads').textContent = list.filter(c => c.platform === 'Facebook' && c.is_ads == 1).length;
    document.getElementById('stat_phone').textContent = list.filter(c => c.phone && c.phone.trim() !== '').length;
    document.getElementById('stat_province').textContent = list.filter(c => c.province && c.province.trim() !== '').length;
}

// Perform client-side filter and render list
function filterAndRender() {
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    const platform = document.getElementById('filterPlatform').value;
    const source = document.getElementById('filterSource').value;
    const phone = document.getElementById('filterPhone').value;
    const province = document.getElementById('filterProvince').value;
    const dateRange = document.getElementById('filterDateRange').value;

    // Filter in-memory array
    filteredCustomers = allCustomers.filter(c => {
        // Search text
        let matchSearch = true;
        if (search) {
            const name = (c.name || '').toLowerCase();
            const phoneVal = (c.phone || '').toLowerCase();
            const notes = (c.notes || '').toLowerCase();
            const prov = (c.province || '').toLowerCase();
            const oaName = (c.oa_name || '').toLowerCase();
            const salesPhone = (c.sales_phone || '').toLowerCase();
            const salesNotes = (c.sales_notes || '').toLowerCase();
            
            matchSearch = name.includes(search) || 
                          phoneVal.includes(search) || 
                          notes.includes(search) || 
                          prov.includes(search) || 
                          oaName.includes(search) || 
                          salesPhone.includes(search) || 
                          salesNotes.includes(search);
        }

        // Platform
        const matchPlatform = !platform || c.platform === platform;

        // Source (Ads vs Free)
        let matchSource = true;
        if (source === 'ads') {
            matchSource = c.platform === 'Facebook' && c.is_ads == 1;
        } else if (source === 'free') {
            matchSource = c.platform === 'Zalo' || c.is_ads != 1;
        }

        // Phone status
        const hasPhone = c.phone && c.phone.trim() !== '';
        const matchPhone = !phone || (phone === 'has_phone' && hasPhone) || (phone === 'no_phone' && !hasPhone);

        // Province
        const matchProvince = !province || c.province === province;

        // Date Range
        let matchDate = true;
        if (dateRange !== 'all') {
            const updatedAtStr = c.updated_at || '';
            if (updatedAtStr) {
                const date = new Date(updatedAtStr.replace(' ', 'T'));
                const now = new Date();
                const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                
                if (dateRange === 'this_week') {
                    // Monday of this week
                    const dayOfWeek = today.getDay();
                    const diff = today.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1);
                    const monday = new Date(today.setDate(diff));
                    monday.setHours(0,0,0,0);
                    
                    const sunday = new Date(monday);
                    sunday.setDate(monday.getDate() + 7);
                    sunday.setMilliseconds(-1);
                    
                    matchDate = date >= monday && date <= sunday;
                } else if (dateRange === 'this_month') {
                    matchDate = date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth();
                } else if (dateRange === 'last_30_days') {
                    const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
                    matchDate = date >= thirtyDaysAgo && date <= now;
                } else if (dateRange === 'custom') {
                    const startVal = document.getElementById('filterDateStart').value;
                    const endVal = document.getElementById('filterDateEnd').value;
                    if (startVal) {
                        const start = new Date(startVal + 'T00:00:00');
                        if (date < start) matchDate = false;
                    }
                    if (endVal && matchDate) {
                        const end = new Date(endVal + 'T23:59:59');
                        if (date > end) matchDate = false;
                    }
                }
            } else {
                matchDate = false;
            }
        }

        return matchSearch && matchPlatform && matchSource && matchPhone && matchProvince && matchDate;
    });

    // Update global dashboard stats cards dynamically based on the filtered list!
    updateStats(filteredCustomers);

    // Update filter count label
    document.getElementById('filterCount').textContent = `Hiển thị ${filteredCustomers.length} / ${allCustomers.length} khách hàng`;

    // Render table
    renderTable();
    // Render pagination
    renderPagination();
}

// Helper to format date
function formatDate(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();
        const hours = String(d.getHours()).padStart(2, '0');
        const mins = String(d.getMinutes()).padStart(2, '0');
        return `${day}/${month}/${year}<br>${hours}:${mins}`;
    } catch(e) {
        return dateStr;
    }
}

// Render visible table page rows
function renderTable() {
    const tbody = document.getElementById('customerBody');
    const emptyState = document.getElementById('table_empty_state');
    tbody.innerHTML = '';

    if (filteredCustomers.length === 0) {
        emptyState.style.display = 'block';
        return;
    }
    emptyState.style.display = 'none';

    // Calculate slice bounds
    const startOffset = (currentPage - 1) * rowsPerPage;
    const endOffset = startOffset + rowsPerPage;
    const pageData = filteredCustomers.slice(startOffset, endOffset);

    pageData.forEach((c, idx) => {
        const stt = startOffset + idx + 1;
        const row = document.createElement('tr');
        
        // Sender Cell
        const nameText = c.name || 'Không tên';
        const oaNameText = c.oa_name || '';
        
        // Phone Cell
        let phoneHtml = '<span style="color:#9ca3af; font-style:italic; font-size:12px;">Chưa có SĐT</span>';
        if (c.phone) {
            phoneHtml = `<a href="tel:${c.phone}" class="phone-link">${c.phone}</a>`;
        }
        
        // Platform Badge
        let badgeClass = c.platform === 'Zalo' ? 'badge-zalo' : 'badge-fb';
        let badgeIcon = c.platform === 'Zalo' ? '💬 Zalo' : '📘 Facebook';
        
        // Source Badge (Ads vs Free)
        let sourceBadgeHtml = '';
        if (c.platform === 'Facebook') {
            if (c.is_ads == 1) {
                let adTitleSnippet = c.ad_title ? `: ${c.ad_title}` : '';
                if (adTitleSnippet.length > 20) adTitleSnippet = adTitleSnippet.substring(0, 20) + '...';
                sourceBadgeHtml = `<div style="margin-top:5px;"><span style="background-color:#ffe4e6;color:#e11d48;border:1px solid #fda4af;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;gap:2px;" title="${c.ad_title || ''}">📢 Ads${adTitleSnippet}</span></div>`;
            } else {
                sourceBadgeHtml = `<div style="margin-top:5px;"><span style="background-color:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;gap:2px;">🆓 Miễn phí</span></div>`;
            }
        }
        
        // Province Badge
        let provinceHtml = '<span style="color:#d1d5db; font-size:12px;">—</span>';
        if (c.province) {
            provinceHtml = `<span class="province-tag">📍 ${c.province}</span>`;
        }
        
        // Notes Cell
        let notesContent = c.notes ? c.notes.replace(/\n/g, '<br>') : '<span style="color:#d1d5db;">—</span>';
        let salesHtml = '';
        if (c.sales_phone || c.sales_notes) {
            let salesPhoneText = c.sales_phone ? `📞 Sales: ${c.sales_phone}` : '';
            let salesNotesText = c.sales_notes ? `📝 ${c.sales_notes}` : '';
            salesHtml = `
                <div class="sales-info">
                    ${salesPhoneText}
                    ${salesPhoneText && salesNotesText ? '<br>' : ''}
                    ${salesNotesText}
                </div>
            `;
        }

        row.innerHTML = `
            <td style="text-align:center; color:#9ca3af; font-weight:600;">${stt}</td>
            <td>
                <div style="font-weight:600; color:#1e293b;">${nameText}</div>
                <div style="font-size:11px; color:#9ca3af; margin-top:2px;">${oaNameText}</div>
            </td>
            <td>${phoneHtml}</td>
            <td>
                <span class="badge-platform ${badgeClass}">${badgeIcon}</span>
                ${sourceBadgeHtml}
            </td>
            <td>${provinceHtml}</td>
            <td class="notes-cell">
                <div>${notesContent}</div>
                ${salesHtml}
            </td>
            <td style="font-size:11px; color:#9ca3af; white-space:nowrap;">${formatDate(c.updated_at)}</td>
        `;
        tbody.appendChild(row);
    });
}

// Render dynamic pagination controls
function renderPagination() {
    const container = document.getElementById('pagination_container');
    container.innerHTML = '';

    const totalPages = Math.ceil(filteredCustomers.length / rowsPerPage);
    if (totalPages <= 1) {
        container.style.display = 'none';
        return;
    }
    container.style.display = 'flex';

    // Previous Button
    const prevBtn = document.createElement('button');
    prevBtn.className = 'pagin-btn' + (currentPage === 1 ? ' disabled' : '');
    prevBtn.innerHTML = '« Trước';
    prevBtn.onclick = () => { if (currentPage > 1) goToPage(currentPage - 1); };
    container.appendChild(prevBtn);

    // Dynamic number rendering
    const startPage = Math.max(1, currentPage - 3);
    const endPage = Math.min(totalPages, currentPage + 3);

    if (startPage > 1) {
        const firstBtn = document.createElement('button');
        firstBtn.className = 'pagin-btn';
        firstBtn.textContent = '1';
        firstBtn.onclick = () => goToPage(1);
        container.appendChild(firstBtn);
        if (startPage > 2) {
            const dot = document.createElement('span');
            dot.style.color = '#9ca3af';
            dot.style.padding = '0 4px';
            dot.textContent = '...';
            container.appendChild(dot);
        }
    }

    for (let i = startPage; i <= endPage; i++) {
        const numBtn = document.createElement('button');
        numBtn.className = 'pagin-btn' + (i === currentPage ? ' active' : '');
        numBtn.textContent = i;
        numBtn.onclick = () => goToPage(i);
        container.appendChild(numBtn);
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const dot = document.createElement('span');
            dot.style.color = '#9ca3af';
            dot.style.padding = '0 4px';
            dot.textContent = '...';
            container.appendChild(dot);
        }
        const lastBtn = document.createElement('button');
        lastBtn.className = 'pagin-btn';
        lastBtn.textContent = totalPages;
        lastBtn.onclick = () => goToPage(totalPages);
        container.appendChild(lastBtn);
    }

    // Next Button
    const nextBtn = document.createElement('button');
    nextBtn.className = 'pagin-btn' + (currentPage === totalPages ? ' disabled' : '');
    nextBtn.innerHTML = 'Sau »';
    nextBtn.onclick = () => { if (currentPage < totalPages) goToPage(currentPage + 1); };
    container.appendChild(nextBtn);
}

// Go to target page
function goToPage(page) {
    currentPage = page;
    renderTable();
    renderPagination();
    
    // Smooth scroll to top of table
    document.getElementById('customerTable').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Handles filters inputs change
function onFilterChange() {
    currentPage = 1;
    filterAndRender();
}

// Handles date range option change
function onDateRangeChange() {
    const range = document.getElementById('filterDateRange').value;
    const container = document.getElementById('customDateContainer');
    if (range === 'custom') {
        container.style.display = 'inline-flex';
    } else {
        container.style.display = 'none';
        // Clear custom inputs
        document.getElementById('filterDateStart').value = '';
        document.getElementById('filterDateEnd').value = '';
    }
    onFilterChange();
}

// Handles limit select change
function onLimitChange() {
    rowsPerPage = parseInt(document.getElementById('filterLimit').value);
    currentPage = 1;
    filterAndRender();
}

// Exports entire filtered customer list
function exportCSV() {
    let csv = '\uFEFF'; // BOM for Excel UTF-8
    csv += 'STT,Tên,Số Điện Thoại,Nền Tảng,Tỉnh Thành,Yêu Cầu / Ghi Chú,Cập Nhật\n';
    
    filteredCustomers.forEach((c, idx) => {
        const name = (c.name || 'Không tên').replace(/"/g, '""');
        const phone = c.phone || '';
        const platform = c.platform || '';
        const province = c.province || '';
        const notes = ((c.notes || '') + (c.sales_notes ? ' | Ghi chú sales: ' + c.sales_notes : '')).replace(/"/g, '""').replace(/\n/g, ' ');
        const updated = c.updated_at || '';
        
        csv += `${idx + 1},"${name}","${phone}","${platform}","${province}","${notes}","${updated}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `khach_hang_all_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// Sequential Sync Loop
function triggerSyncAllHistory() {
    const btn = document.getElementById('btn_sync_all_history');
    const oldText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span>🔄</span> Đang kích hoạt...';
    
    localShowToast('Đang gửi lệnh kích hoạt tiến trình đồng bộ ngầm...', 'info');
    
    fetch('actions/run_sync_in_background.php')
        .then(r => {
            if (!r.ok) throw new Error('Không thể kết nối máy chủ (Lỗi HTTP ' + r.status + ')');
            return r.json();
        })
        .then(res => {
            if (res.status === 'success') {
                localShowToast(res.msg, 'success');
                // Tự động tải lại trang sau 8 giây để người dùng thấy dữ liệu bước đầu đổ về
                setTimeout(() => { window.location.reload(); }, 8000);
            } else {
                localShowToast('Có lỗi xảy ra: ' + res.msg, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            localShowToast('Lỗi kích hoạt tiến trình ngầm: ' + err.message, 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = oldText;
        });
}

function triggerScanOldPhones() {
    const btn = document.getElementById('btn_scan_old_phones');
    const oldText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span>🔄</span> Đang kích hoạt...';
    
    localShowToast('Đang gửi lệnh kích hoạt tiến trình quét SĐT ngầm...', 'info');

    fetch('actions/run_scan_in_background.php')
        .then(r => {
            if (!r.ok) throw new Error('Không thể kết nối máy chủ (Lỗi HTTP ' + r.status + ')');
            return r.json();
        })
        .then(data => {
            if (data.status === 'success') {
                localShowToast(data.msg, 'success');
                // Tự động tải lại trang sau 5 giây để người dùng thấy SĐT mới cập nhật
                setTimeout(() => { window.location.reload(); }, 5000);
            } else {
                localShowToast('Lỗi khi kích hoạt: ' + (data.msg || ''), 'error');
            }
        })
        .catch(err => {
            console.error(err);
            localShowToast('Lỗi kết nối kích hoạt quét ngầm: ' + err.message, 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = oldText;
        });
}

function localShowToast(msg, type = 'success') {
    // Nếu trang mẹ có hàm showToast chuẩn, hãy gọi nó
    if (typeof window.showToast === 'function' && window.showToast !== localShowToast) {
        try {
            window.showToast(msg, type);
            return;
        } catch (e) {}
    }
    
    // Nếu không có, tự dựng Toast UI nổi cực kỳ chuyên nghiệp
    let toastContainer = document.getElementById('local-toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'local-toast-container';
        toastContainer.style.position = 'fixed';
        toastContainer.style.top = '24px';
        toastContainer.style.right = '24px';
        toastContainer.style.zIndex = '999999';
        toastContainer.style.display = 'flex';
        toastContainer.style.flexDirection = 'column';
        toastContainer.style.gap = '10px';
        document.body.appendChild(toastContainer);
    }
    
    const toast = document.createElement('div');
    toast.style.padding = '12px 24px';
    toast.style.borderRadius = '8px';
    toast.style.color = '#fff';
    toast.style.fontSize = '13px';
    toast.style.fontWeight = '600';
    toast.style.boxShadow = '0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05)';
    toast.style.transition = 'all 0.3s ease';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-10px)';
    
    // Color schemes
    if (type === 'error') {
        toast.style.background = '#ef4444';
    } else if (type === 'info') {
        toast.style.background = '#0ea5e9';
    } else {
        toast.style.background = '#10b981'; // success
    }
    
    toast.textContent = msg;
    toastContainer.appendChild(toast);
    
    // Trigger CSS animation flow
    setTimeout(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    }, 10);
    
    // Fade out and remove
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Initial Run
updateStats(allCustomers);
filterAndRender();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
