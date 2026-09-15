<?php
// customers.php
$current_page = 'customers';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin   = (($_SESSION['role'] ?? '') === 'admin');

// Check feature flags
$acc_setup = [];
try {
    $stmt_acc = $pdo->prepare("SELECT enable_live_chat, enable_live_chat_oa, enable_live_chat_tiktok, enable_website, enable_customers FROM system_accounts WHERE id = ?");
    $stmt_acc->execute([$account_id]);
    $acc_setup = $stmt_acc->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$enable_live_chat = $is_admin ? 1 : (int)($acc_setup['enable_live_chat'] ?? 1);
$enable_live_chat_oa = $is_admin ? 1 : (int)($acc_setup['enable_live_chat_oa'] ?? 1);
$enable_live_chat_tiktok = $is_admin ? 1 : (int)($acc_setup['enable_live_chat_tiktok'] ?? 1);
$enable_website = $is_admin ? 1 : (int)($acc_setup['enable_website'] ?? 1);
$enable_customers = $is_admin ? 1 : (int)($acc_setup['enable_customers'] ?? 1);

if (!$enable_live_chat) {
    echo '<div class="page-title">Truy cập bị từ chối</div>';
    echo '<div class="card" style="border-left: 4px solid #ef4444; padding: 20px;">';
    echo '  <h3 style="margin-top:0; color:#ef4444;">⚠️ Tính Năng Đã Bị Tắt</h3>';
    echo '  <p style="color:#4b5563; font-size:14px; margin-bottom:0;">Tính năng Live Chat đã bị tắt cho tài khoản của bạn. Vui lòng liên hệ Admin để kích hoạt lại.</p>';
    echo '</div>';
    include 'includes/footer.php';
    exit;
}

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
               zc.updated_at, zc.oa_id, zc.sender_id, zo.name AS oa_name, zc.consulted,
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

// 3. Facebook customers
try {
    $stmt_f = $pdo->prepare("
        SELECT fc.name, fc.phone, fc.province, fc.notes, fc.sales_phone, fc.sales_notes,
               fc.updated_at, fc.page_id AS oa_id, fc.sender_id, p.name AS oa_name, fc.consulted,
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

// 4. Website customers
try {
    $stmt_w = $pdo->prepare("
        SELECT name, phone, province, notes, NULL AS sales_phone, NULL AS sales_notes,
               updated_at, 'website' AS oa_id, visitor_uuid AS sender_id, 'Website Live Chat' AS oa_name, consulted,
               'Website' AS platform, 0 AS is_ads, NULL AS ad_title
        FROM web_visitors
        WHERE account_id = ?
    ");
    $stmt_w->execute([$account_id]);
    $web_custs = $stmt_w->fetchAll(PDO::FETCH_ASSOC);
    foreach ($web_custs as $c) {
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
    <?php if ($enable_live_chat): ?>
    <a href="live_chat.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>📘</span> Facebook Fanpage
    </a>
    <?php endif; ?>
    <?php if ($enable_live_chat_oa): ?>
    <a href="live-chat-oa.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>💬</span> Zalo Official Account
    </a>
    <?php endif; ?>
    <?php if ($enable_live_chat_tiktok): ?>
    <a href="live-chat-tiktok.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🎵</span> TikTok
    </a>
    <?php endif; ?>
    <?php if ($enable_website): ?>
    <a href="website.php" class="platform-tab-btn" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #4b5563; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>🌐</span> Live Chat Website
    </a>
    <?php endif; ?>
    <?php if ($enable_customers): ?>
    <a href="customers.php" class="platform-tab-btn active" style="padding: 10px 15px; font-size: 16px; font-weight: 600; text-decoration: none; color: #0068ff; border-bottom: 3px solid #0068ff; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 8px;">
        <span>👥</span> Khách Hàng
    </a>
    <?php endif; ?>
</div>

<!-- Page Title & Actions -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div class="page-title" style="margin-bottom:0;">👥 Danh Sách Khách Hàng</div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button id="btn_sync_all_history" onclick="triggerSyncAllHistory()" class="btn" style="background:#0284c7; color:#fff; border:none; border-radius:8px; padding:8px 16px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; transition: background 0.2s;" <?php echo $sync_lock_active ? 'disabled' : ''; ?>>
            <span>🔄</span> <?php echo $sync_lock_active ? 'Đang đồng bộ ngầm...' : 'Đồng bộ lịch sử Facebook'; ?>
        </button>
        <button id="btn_scan_old_phones" onclick="triggerScanOldPhones()" class="btn" style="background:#10b981; color:#fff; border:none; border-radius:8px; padding:8px 16px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; transition: background 0.2s;" <?php echo $scan_lock_active ? 'disabled' : ''; ?>>
            <span>🔍</span> <?php echo $scan_lock_active ? 'Đang quét ngầm...' : 'Quét SĐT tin cũ'; ?>
        </button>
        <button onclick="exportCSV()" class="export-btn">
            <span>📥</span> Xuất Excel (CSV)
        </button>
        <button onclick="openApiConfigModal()" class="btn" style="background:#8b5cf6; color:#fff; border:none; border-radius:8px; padding:8px 16px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; transition: background 0.2s;" title="Cấu hình API tự động đẩy Lead">
            <span>🔌</span> Cấu hình API
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
        <option value="Website">🌐 Website</option>
    </select>

    <select id="filterSource" onchange="onFilterChange()" style="width:120px;">
        <option value="">Nguồn khách</option>
        <option value="ads">📢 Từ Ads</option>
        <option value="free">🆓 Miễn phí</option>
    </select>
    
    <select id="filterPhone" onchange="onFilterChange()" style="width:215px;">
        <option value="">Số điện thoại</option>
        <option value="has_phone">📞 Có SĐT</option>
        <option value="no_phone">❌ Chưa có SĐT</option>
        <option value="has_phone_unconsulted">🔥 Có SĐT + Chưa tư vấn / Chờ xử lý</option>
    </select>
    
    <select id="filterProvince" onchange="onFilterChange()" style="width:125px;">
        <option value="">Tỉnh thành</option>
        <?php foreach ($provinces as $p): ?>
            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
        <?php endforeach; ?>
    </select>

    <select id="filterConsulted" onchange="onFilterChange()" style="width:150px;">
        <option value="">Trạng thái tư vấn</option>
        <option value="0">🆕 Chưa tư vấn</option>
        <option value="4">⏳ Chờ xử lý</option>
        <option value="1">✅ Đã tư vấn</option>
        <option value="2">🔄 Khách quay lại</option>
        <option value="3">⛔ Dừng tư vấn</option>
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
                    <th style="width:45px; text-align:center;">STT</th>
                    <th style="width:200px;">Tên khách hàng</th>
                    <th style="width:130px;">Số điện thoại</th>
                    <th style="width:110px;">Nền tảng</th>
                    <th style="width:110px;">Tỉnh thành</th>
                    <th style="width:140px;">Trạng thái tư vấn</th>
                    <th>Yêu cầu / Ghi chú tích lũy</th>
                    <th style="width:100px;">Cập nhật</th>
                    <th style="width:70px; text-align:center;">Hành động</th>
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
    if (document.getElementById('stat_zalo_p')) {
        document.getElementById('stat_zalo_p').textContent = list.filter(c => c.platform === 'Zalo Personal').length;
    }
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
    const consulted = document.getElementById('filterConsulted').value;
    const dateRange = document.getElementById('filterDateRange').value;

    // Filter in-memory array
    filteredCustomers = allCustomers.filter(c => {
        // Search text
        let matchSearch = true;
        if (search) {
            const searchClean = search.replace(/[\s\.\-\(\)]/g, '');
            const name = (c.name || '').toLowerCase();
            const phoneVal = (c.phone || '').toLowerCase();
            const phoneClean = phoneVal.replace(/[\s\.\-\(\)]/g, '');
            const notes = (c.notes || '').toLowerCase();
            const prov = (c.province || '').toLowerCase();
            const oaName = (c.oa_name || '').toLowerCase();
            const salesPhone = (c.sales_phone || '').toLowerCase();
            const salesPhoneClean = salesPhone.replace(/[\s\.\-\(\)]/g, '');
            const salesNotes = (c.sales_notes || '').toLowerCase();
            
            matchSearch = name.includes(search) || 
                          phoneVal.includes(search) || 
                          (searchClean.length >= 3 && phoneClean.includes(searchClean)) ||
                          notes.includes(search) || 
                          prov.includes(search) || 
                          oaName.includes(search) || 
                          salesPhone.includes(search) || 
                          (searchClean.length >= 3 && salesPhoneClean.includes(searchClean)) ||
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
        const isUnconsulted = String(c.consulted || 0) === '0' || String(c.consulted || 0) === '4';
        let matchPhone = true;
        if (phone === 'has_phone') {
            matchPhone = hasPhone;
        } else if (phone === 'no_phone') {
            matchPhone = !hasPhone;
        } else if (phone === 'has_phone_unconsulted') {
            matchPhone = hasPhone && isUnconsulted;
        }

        // Province
        const matchProvince = !province || c.province === province;

        // Consultation Status
        const matchConsulted = !consulted || String(c.consulted || 0) === consulted;

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
                    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
                    matchDate = date >= firstDay;
                } else if (dateRange === 'last_30_days') {
                    const thirtyDaysAgo = new Date(now.getTime() - (30 * 24 * 60 * 60 * 1000));
                    matchDate = date >= thirtyDaysAgo;
                } else if (dateRange === 'custom') {
                    const startVal = document.getElementById('filterDateStart').value;
                    const endVal = document.getElementById('filterDateEnd').value;
                    if (startVal) {
                        const startDate = new Date(startVal + 'T00:00:00');
                        if (date < startDate) matchDate = false;
                    }
                    if (endVal) {
                        const endDate = new Date(endVal + 'T23:59:59');
                        if (date > endDate) matchDate = false;
                    }
                }
            } else {
                matchDate = false;
            }
        }

        return matchSearch && matchPlatform && matchSource && matchPhone && matchProvince && matchConsulted && matchDate;
    });

    // Update global dashboard stats cards dynamically based on the filtered list!
    updateStats(filteredCustomers);

    // Update filter count label
    document.getElementById('filterCount').textContent = `Hiển thị ${filteredCustomers.length} / ${allCustomers.length} khách hàng`;

    // Reset pagination to page 1
    currentPage = 1;
    renderPagination();
    renderTable();
}

// Format relative date nicely
function formatDate(dateStr) {
    if (!dateStr) return '';
    try {
        const date = new Date(dateStr.replace(' ', 'T'));
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Vừa xong';
        if (diffMins < 60) return `${diffMins} phút trước`;
        if (diffHours < 24) return `${diffHours} giờ trước`;
        if (diffDays === 1) return 'Hôm qua';
        if (diffDays < 7) return `${diffDays} ngày trước`;
        return date.toLocaleDateString('vi-VN');
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
        let badgeClass = c.platform === 'Zalo' ? 'badge-zalo' : (c.platform === 'Website' ? 'badge-zalo' : 'badge-fb');
        let badgeIcon = c.platform === 'Zalo' ? '💬 Zalo OA' : (c.platform === 'Website' ? '🌐 Website' : '📘 Facebook');
        
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

        // Trạng thái tư vấn Badge
        let consultedHtml = '';
        const cVal = parseInt(c.consulted || 0);
        if (cVal === 1) {
            consultedHtml = '<span style="background-color:#dcfce7;color:#15803d;border:1px solid #bbf7d0;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700;display:inline-block;white-space:nowrap;">✅ Đã tư vấn</span>';
        } else if (cVal === 4) {
            consultedHtml = '<span style="background-color:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700;display:inline-block;white-space:nowrap;">⏳ Chờ xử lý</span>';
        } else if (cVal === 2) {
            consultedHtml = '<span style="background-color:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700;display:inline-block;white-space:nowrap;">🔄 Khách quay lại</span>';
        } else if (cVal === 3) {
            consultedHtml = '<span style="background-color:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700;display:inline-block;white-space:nowrap;">⛔ Dừng tư vấn</span>';
        } else {
            consultedHtml = '<span style="background-color:#fff7ed;color:#c2410c;border:1px solid #ffedd5;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700;display:inline-block;white-space:nowrap;">🆕 Chưa tư vấn</span>';
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

        const safeName = nameText.replace(/'/g, "\\'");
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
            <td>${consultedHtml}</td>
            <td class="notes-cell">
                <div>${notesContent}</div>
                ${salesHtml}
            </td>
            <td style="font-size:11px; color:#9ca3af; white-space:nowrap;">${formatDate(c.updated_at)}</td>
            <td style="text-align:center; white-space:nowrap;">
                <button onclick="pushSingleCustomerApi('${c.platform}', '${c.sender_id}', '${c.oa_id}')" style="background:#f5f3ff; color:#7e22ce; border:1px solid #ddd6fe; padding:4px 8px; border-radius:6px; font-weight:600; cursor:pointer; font-size:11px; margin-right:4px;" title="Gửi dữ liệu qua API ngay">🚀 API</button>
                <button onclick="deleteSingleCustomer('${c.platform}', '${c.sender_id}', '${c.oa_id}', '${safeName}')" style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; padding:4px 8px; border-radius:6px; font-weight:600; cursor:pointer; font-size:11px;" title="Xóa khách hàng này">🗑️ Xóa</button>
            </td>
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
    csv += 'STT,Tên,Số Điện Thoại,Nền Tảng,Tỉnh Thành,Trạng Thái Tư Vấn,Yêu Cầu / Ghi Chú,Cập Nhật\n';
    
    filteredCustomers.forEach((c, idx) => {
        const name = (c.name || 'Không tên').replace(/"/g, '""');
        const phone = c.phone || '';
        const platform = c.platform || '';
        const province = c.province || '';
        
        let consultedText = 'Chưa tư vấn';
        const cVal = parseInt(c.consulted || 0);
        if (cVal === 1) consultedText = 'Đã tư vấn';
        else if (cVal === 2) consultedText = 'Khách quay lại';
        else if (cVal === 3) consultedText = 'Dừng tư vấn';
        
        const notes = ((c.notes || '') + (c.sales_notes ? ' | Ghi chú sales: ' + c.sales_notes : '')).replace(/"/g, '""').replace(/\n/g, ' ');
        const updated = c.updated_at || '';
        
        csv += `${idx + 1},"${name}","${phone}","${platform}","${province}","${consultedText}","${notes}","${updated}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `khach_hang_all_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// Delete single customer
function deleteSingleCustomer(platform, senderId, oaId, name) {
    if (!confirm(`Bạn có chắc chắn muốn xóa khách hàng "${name}" không?\n\nToàn bộ lịch sử tin nhắn của khách hàng này sẽ bị xóa khỏi hệ thống.`)) {
        return;
    }
    const fd = new FormData();
    fd.append('platform', platform);
    fd.append('sender_id', senderId);
    fd.append('oa_id', oaId);

    fetch('actions/delete_customer.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                localShowToast(res.msg, 'success');
                // Remove from in-memory arrays
                const idx = allCustomers.findIndex(c => c.platform === platform && String(c.sender_id) === String(senderId));
                if (idx !== -1) {
                    allCustomers.splice(idx, 1);
                    filterAndRender();
                }
            } else {
                localShowToast(res.msg, 'error');
            }
        })
        .catch(err => localShowToast('Lỗi xóa khách hàng: ' + err.message, 'error'));
}

// Delete all Website test visitors
function deleteAllWebsiteTest() {
    if (!confirm('⚠️ BẠN CÓ CHẮC CHẮN MUỐN XÓA SẠCH TOÀN BỘ KHÁCH TEST WEBSITE KHÔNG?\n\nToàn bộ dữ liệu khách vãng lai và tin nhắn test trên Website sẽ bị xóa vĩnh viễn.')) {
        return;
    }
    const fd = new FormData();
    fd.append('action', 'delete_all_website_test');

    fetch('actions/delete_customer.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                localShowToast(res.msg, 'success');
                setTimeout(() => { window.location.reload(); }, 1200);
            } else {
                localShowToast(res.msg, 'error');
            }
        })
        .catch(err => localShowToast('Lỗi: ' + err.message, 'error'));
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

// ── API CONFIGURATION & PUSH FUNCTIONS ──────────────────────────────────────
function openApiConfigModal() {
    document.getElementById('apiConfigModal').style.display = 'flex';
    fetch('actions/customer_api_config.php?action=get_config')
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success' && res.config) {
                const cfg = res.config;
                document.getElementById('api_is_enabled').checked = (parseInt(cfg.is_enabled) === 1);
                document.getElementById('api_url').value = cfg.api_url || '';
                document.getElementById('api_token').value = cfg.api_token || '';
                document.getElementById('api_trigger_condition').value = cfg.trigger_condition || 'has_phone';
                document.getElementById('api_send_scope').value = cfg.send_scope || 'all';
                updateApiBadgeStatus();
            }
        });
}

function closeApiConfigModal() {
    document.getElementById('apiConfigModal').style.display = 'none';
    document.getElementById('api_test_result_box').style.display = 'none';
}

function updateApiBadgeStatus() {
    const isChecked = document.getElementById('api_is_enabled').checked;
    const badge = document.getElementById('api_status_badge');
    if (isChecked) {
        badge.textContent = '⚡ ĐANG BẬT';
        badge.style.background = '#dcfce7';
        badge.style.color = '#15803d';
    } else {
        badge.textContent = 'Đang tắt';
        badge.style.background = '#e9d5ff';
        badge.style.color = '#6b21a8';
    }
}

function saveApiConfig(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('frmApiConfig'));
    fd.append('action', 'save_config');

    fetch('actions/customer_api_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                localShowToast(res.msg, 'success');
                closeApiConfigModal();
            } else {
                localShowToast(res.msg, 'error');
            }
        })
        .catch(err => localShowToast('Lỗi lưu cấu hình API: ' + err.message, 'error'));
}

function testPushApi() {
    const url = document.getElementById('api_url').value.trim();
    if (!url) {
        localShowToast('Vui lòng nhập URL API Endpoint trước khi gửi thử!', 'error');
        return;
    }

    const box = document.getElementById('api_test_result_box');
    box.style.display = 'block';
    box.textContent = '⏳ Đang gửi dữ liệu mẫu qua API...';

    const fd = new FormData();
    fd.append('action', 'test_push');
    fd.append('api_url', url);
    fd.append('api_token', document.getElementById('api_token').value.trim());

    fetch('actions/customer_api_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                localShowToast(res.msg, 'success');
                box.textContent = '✅ Success:\n' + (res.response || res.msg);
            } else {
                localShowToast(res.msg, 'error');
                box.textContent = '❌ Error:\n' + (res.msg || '') + '\n' + (res.response || '');
            }
        })
        .catch(err => {
            box.textContent = '❌ Error: ' + err.message;
        });
}

function pushSingleCustomerApi(platform, senderId, oaId) {
    localShowToast('Đang gửi dữ liệu qua API...', 'info');

    const fd = new FormData();
    fd.append('action', 'push_single_customer');
    fd.append('platform', platform);
    fd.append('sender_id', senderId);
    fd.append('oa_id', oaId);

    fetch('actions/customer_api_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                localShowToast(res.msg, 'success');
            } else {
                localShowToast(res.msg, 'error');
            }
        })
        .catch(err => localShowToast('Lỗi gửi API: ' + err.message, 'error'));
}
</script>

<!-- API Config Modal -->
<div id="apiConfigModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:#ffffff; border-radius:16px; max-width:560px; width:100%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); overflow:hidden; animation:modalFadeIn 0.2s ease;">
        <div style="background:linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); padding:18px 24px; color:#ffffff; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-weight:700; font-size:16px; display:flex; align-items:center; gap:8px;">
                🔌 Cấu Hình API Đẩy Dữ Liệu Khách Hàng
            </div>
            <button onclick="closeApiConfigModal()" style="background:none; border:none; color:#fff; font-size:20px; cursor:pointer;">✕</button>
        </div>

        <form id="frmApiConfig" onsubmit="saveApiConfig(event)" style="padding:24px; display:flex; flex-direction:column; gap:16px;">
            <!-- Checkbox Kích hoạt -->
            <div style="background:#f5f3ff; border:1px solid #ddd6fe; padding:12px 16px; border-radius:10px; display:flex; align-items:center; justify-content:space-between;">
                <label style="font-weight:700; font-size:14px; color:#5b21b6; cursor:pointer; display:flex; align-items:center; gap:10px; margin:0;">
                    <input type="checkbox" id="api_is_enabled" name="is_enabled" value="1" onchange="updateApiBadgeStatus()" style="width:20px; height:20px; cursor:pointer;">
                    ⚡ Kích hoạt Tự Động Đẩy API
                </label>
                <span id="api_status_badge" style="font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; background:#e9d5ff; color:#6b21a8;">Đang tắt</span>
            </div>

            <!-- URL API -->
            <div>
                <label style="font-size:13px; font-weight:700; color:#374151; display:block; margin-bottom:6px;">🌐 URL API Endpoint (POST)</label>
                <input type="url" id="api_url" name="api_url" placeholder="https://crm.yourdomain.com/api/v1/leads" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; box-sizing:border-box;">
                <span style="font-size:11px; color:#64748b; margin-top:4px; display:block;">Hệ thống sẽ gửi yêu cầu HTTP POST dữ liệu JSON tới đường dẫn này.</span>
            </div>

            <!-- Bearer Token -->
            <div>
                <label style="font-size:13px; font-weight:700; color:#374151; display:block; margin-bottom:6px;">🔑 Bearer Token / Secret Key (Tùy chọn)</label>
                <input type="text" id="api_token" name="api_token" placeholder="VD: token_sec_xxx123..." style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; font-family:monospace; box-sizing:border-box;">
                <span style="font-size:11px; color:#64748b; margin-top:4px; display:block;">Tự động gửi Header: Authorization: Bearer &lt;token&gt; và X-Api-Key: &lt;token&gt;</span>
            </div>

            <!-- 2 Menu xổ xuống cùng hàng cho gọn -->
            <div style="display:flex; gap:12px;">
                <div style="flex:1;">
                    <label style="font-size:13px; font-weight:700; color:#374151; display:block; margin-bottom:6px;">📋 Điều Kiện Đẩy API</label>
                    <select id="api_trigger_condition" name="trigger_condition" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; background:#fff; cursor:pointer;">
                        <option value="consulted">✅ Khách hàng Đã tư vấn</option>
                        <option value="has_phone">📞 Khách hàng có SDT</option>
                    </select>
                </div>
                <div style="flex:1;">
                    <label style="font-size:13px; font-weight:700; color:#374151; display:block; margin-bottom:6px;">📤 Cấu Hình Gửi</label>
                    <select id="api_send_scope" name="send_scope" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; background:#fff; cursor:pointer;">
                        <option value="all">🌐 Toàn bộ khách hàng</option>
                        <option value="new_only">🆕 Chỉ khách hàng mới</option>
                    </select>
                </div>
            </div>

            <!-- Response Result Box -->
            <div id="api_test_result_box" style="display:none; background:#0f172a; color:#38bdf8; padding:12px; border-radius:8px; font-family:monospace; font-size:12px; max-height:120px; overflow-y:auto; word-break:break-all;"></div>

            <!-- Action Buttons -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; gap:10px;">
                <button type="button" onclick="testPushApi()" class="btn" style="background:#f3e8ff; color:#7e22ce; border:1px solid #d8b4fe; padding:9px 16px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer;">
                    🚀 Gửi Thử Mẫu API
                </button>
                <div style="display:flex; gap:8px;">
                    <button type="button" onclick="closeApiConfigModal()" class="btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:9px 16px; border-radius:8px; font-weight:600; font-size:13px; cursor:pointer;">Đóng</button>
                    <button type="submit" class="btn" style="background:#8b5cf6; color:#ffffff; border:none; padding:9px 20px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer;">Lưu Cấu Hình API</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
