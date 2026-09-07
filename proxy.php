<?php
// proxy.php
$current_page = 'proxy';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

$account_id = $_SESSION['account_id'];

// Auto check and create table proxies & column proxy_id in users
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proxies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            proxy_string VARCHAR(255) NOT NULL,
            ip VARCHAR(100) DEFAULT NULL,
            port VARCHAR(10) DEFAULT NULL,
            username VARCHAR(100) DEFAULT NULL,
            password VARCHAR(100) DEFAULT NULL,
            protocol VARCHAR(10) DEFAULT 'http',
            ip_type VARCHAR(10) DEFAULT 'IPv4',
            status VARCHAR(20) DEFAULT 'untested',
            latency INT DEFAULT 0,
            country VARCHAR(10) DEFAULT 'VN',
            assigned_user_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (account_id),
            INDEX (assigned_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $col_p = $pdo->query("SHOW COLUMNS FROM users LIKE 'proxy_id'");
    if ($col_p->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN proxy_id INT DEFAULT NULL");
    }
} catch (Exception $e) {}

// Fetch user's proxies with assigned user info
$stmt = $pdo->prepare("
    SELECT p.*, u.name as user_name, u.fb_id
    FROM proxies p
    LEFT JOIN users u ON p.assigned_user_id = u.id
    WHERE p.account_id = ?
    ORDER BY p.id DESC
");
$stmt->execute([$account_id]);
$proxies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user tokens for assignment dropdown
$stmt_u = $pdo->prepare("
    SELECT u.id, u.name, u.fb_id, u.proxy_id, p.proxy_string as assigned_proxy_str
    FROM users u
    LEFT JOIN proxies p ON u.proxy_id = p.id
    WHERE u.account_id = ?
    ORDER BY u.name ASC
");
$stmt_u->execute([$account_id]);
$user_tokens = $stmt_u->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$total_count = count($proxies);
$live_count = 0;
$dead_count = 0;
$untested_count = 0;

foreach ($proxies as $px) {
    if ($px['status'] === 'live') $live_count++;
    elseif ($px['status'] === 'dead') $dead_count++;
    else $untested_count++;
}
?>

<style>
.proxy-container {
    max-width: 1250px;
    margin: 0 auto;
}
.proxy-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 15px;
}
.proxy-header-title {
    font-size: 26px;
    font-weight: 800;
    color: var(--text-main, #1e293b);
    display: flex;
    align-items: center;
    gap: 10px;
}
.proxy-header-actions {
    display: flex;
    gap: 12px;
    align-items: center;
}
.proxy-btn-secondary {
    background: #ffffff;
    border: 1px solid var(--border-color, #cbd5e1);
    color: var(--text-main, #334155);
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.proxy-btn-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.proxy-btn-primary {
    background: #0284c7;
    color: #ffffff;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.3);
    transition: all 0.2s ease;
}
.proxy-btn-primary:hover {
    background: #0369a1;
}

/* Stat Cards */
.proxy-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}
@media (max-width: 768px) {
    .proxy-stats-grid {
        grid-template-columns: 1fr;
    }
}
.proxy-stat-card {
    background: #ffffff;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}
.proxy-stat-card.card-live {
    background: #f0fdf4;
    border-color: #bbf7d0;
}
.proxy-stat-card.card-dead {
    background: #fef2f2;
    border-color: #fecaca;
}
.proxy-stat-val {
    font-size: 32px;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 4px;
}
.card-total .proxy-stat-val { color: #1e293b; }
.card-live .proxy-stat-val { color: #166534; }
.card-dead .proxy-stat-val { color: #991b1b; }

.proxy-stat-lbl {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted, #64748b);
}
.card-live .proxy-stat-lbl { color: #15803d; }
.card-dead .proxy-stat-lbl { color: #dc2626; }

/* Table Styling */
.proxy-card-table {
    background: #ffffff;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
}
.proxy-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    text-align: left;
}
.proxy-table th {
    background: #f8fafc;
    padding: 14px 16px;
    font-weight: 700;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.5px;
}
.proxy-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.proxy-table tr:last-child td {
    border-bottom: none;
}
.proxy-str-cell {
    font-family: monospace;
    font-size: 13px;
    color: #334155;
    word-break: break-all;
}
.badge-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.badge-live {
    background: #dcfce7;
    color: #15803d;
}
.badge-dead {
    background: #fee2e2;
    color: #b91c1c;
}
.badge-untested {
    background: #f1f5f9;
    color: #64748b;
}

.latency-good { color: #16a34a; font-weight: 700; }
.latency-medium { color: #d97706; font-weight: 700; }
.latency-slow { color: #dc2626; font-weight: 700; }

.badge-iptype {
    background: #e0f2fe;
    color: #0369a1;
    font-size: 11px;
    font-weight: bold;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 4px;
}

.btn-icon-action {
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: 16px;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background 0.15s;
}
.btn-icon-action:hover {
    background: #f1f5f9;
}
.btn-icon-danger:hover {
    background: #fee2e2;
}

/* Modals */
.proxy-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    padding: 15px;
}
.proxy-modal-content {
    background: #ffffff;
    border-radius: 12px;
    width: 100%;
    max-width: 580px;
    padding: 25px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
}
</style>

<div class="proxy-container">
    <div class="proxy-header-row">
        <div class="proxy-header-title">
            <span>🌐</span> Kho Proxy
        </div>
        <div class="proxy-header-actions">
            <button class="proxy-btn-secondary" onclick="checkAllProxies()" id="btnCheckAll">
                <span>🔄</span> Kiểm tra tất cả
            </button>
            <button class="proxy-btn-secondary" onclick="openAssignModal()">
                <span>👤</span> Gán vào Người Dùng
            </button>
            <button class="proxy-btn-primary" onclick="openAddModal()">
                <span>+</span> Thêm nhiều
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="proxy-stats-grid">
        <div class="proxy-stat-card card-total">
            <div class="proxy-stat-val"><?php echo $total_count; ?></div>
            <div class="proxy-stat-lbl">Tổng số Proxy</div>
        </div>
        <div class="proxy-stat-card card-live">
            <div class="proxy-stat-val"><?php echo $live_count; ?> ✓</div>
            <div class="proxy-stat-lbl">Sống ✓</div>
        </div>
        <div class="proxy-stat-card card-dead">
            <div class="proxy-stat-val"><?php echo $dead_count; ?> ✗</div>
            <div class="proxy-stat-lbl">Chết ✗</div>
        </div>
    </div>

    <!-- Proxy List Table -->
    <div class="proxy-card-table">
        <div style="overflow-x: auto;">
            <table class="proxy-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>PROXY</th>
                        <th>IP</th>
                        <th>VỊ TRÍ</th>
                        <th>TỐC ĐỘ</th>
                        <th>TRẠNG THÁI</th>
                        <th>NGƯỜI DÙNG GÁN</th>
                        <th style="text-align: right;">THAO TÁC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($proxies)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 35px; color: var(--text-muted);">
                                Chưa có Proxy nào trong kho. Bấm nút <strong>"+ Thêm nhiều"</strong> ở trên để nhập danh sách Proxy.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($proxies as $idx => $px): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-size: 13px; font-weight: 600;">
                                    <?php echo $idx + 1; ?>
                                </td>
                                <td class="proxy-str-cell">
                                    <?php echo htmlspecialchars($px['proxy_string']); ?>
                                </td>
                                <td style="font-family: monospace; font-size: 13px; color: #475569;">
                                    <?php echo htmlspecialchars($px['ip']); ?>:<?php echo htmlspecialchars($px['port']); ?>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: #334155;">
                                        <?php echo htmlspecialchars($px['country'] ?: 'VN'); ?>
                                    </span>
                                    <span class="badge-iptype"><?php echo htmlspecialchars($px['ip_type'] ?: 'IPv4'); ?></span>
                                </td>
                                <td>
                                    <?php if ($px['status'] === 'live'): ?>
                                        <?php 
                                        $lat = (int)$px['latency'];
                                        $cls = ($lat < 300) ? 'latency-good' : (($lat < 800) ? 'latency-medium' : 'latency-slow');
                                        ?>
                                        <span class="<?php echo $cls; ?>"><?php echo $lat; ?>ms</span>
                                    <?php elseif ($px['status'] === 'dead'): ?>
                                        <span style="color: #dc2626; font-size: 12px;">--</span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">Chưa kiểm tra</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($px['status'] === 'live'): ?>
                                        <span class="badge-status badge-live">✓ Sống</span>
                                    <?php elseif ($px['status'] === 'dead'): ?>
                                        <span class="badge-status badge-dead">✗ Chết</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-untested">Chưa kiểm tra</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($px['user_name'])): ?>
                                        <span style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                            👤 <?php echo htmlspecialchars($px['user_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 13px; font-style: italic;">Chưa gán</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn-icon-action" onclick="checkSingleProxy(<?php echo $px['id']; ?>, this)" title="Kiểm tra trạng thái">🔍</button>
                                    <?php if (!empty($px['assigned_user_id'])): ?>
                                        <button type="button" class="btn-icon-action" onclick="unassignProxy(<?php echo $px['id']; ?>)" title="Hủy gán cho người dùng">🔗</button>
                                    <?php else: ?>
                                        <button type="button" class="btn-icon-action" onclick="openAssignSingleModal(<?php echo $px['id']; ?>)" title="Gán cho người dùng">➕</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-icon-action btn-icon-danger" onclick="deleteProxy(<?php echo $px['id']; ?>)" title="Xóa Proxy">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Thêm Nhiều Proxy -->
<div id="addProxyModal" class="proxy-modal">
    <div class="proxy-modal-content">
        <h3 style="margin-top: 0; margin-bottom: 10px; color: var(--text-main);">+ Thêm Danh Sách Proxy Hàng Loạt</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">
            Nhập danh sách Proxy (mỗi Proxy nằm trên 1 dòng). Hệ thống hỗ trợ cả <strong>IPv4</strong> và <strong>IPv6</strong>.
        </p>

        <form id="formAddProxy">
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px;">Danh Sách Proxy (Định dạng: `ip:port:user:pass` hoặc `ip:port`):</label>
                <textarea name="proxy_list" rows="8" class="form-control" placeholder="103.21.12.34:8080:username:password
2001:db8::1:3128:user:pass
103.22.45.67:8080
http://user:pass@103.23.1.2:8080" style="width: 100%; padding: 10px; border-radius: 8px; font-family: monospace; font-size: 13px; border: 1px solid var(--border-color);" required></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="proxy-btn-secondary" onclick="closeAddModal()">Hủy bỏ</button>
                <button type="submit" class="proxy-btn-primary" id="btnAddSubmit">Xác nhận Thêm</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Gán Proxy Cho Người Dùng -->
<div id="assignProxyModal" class="proxy-modal">
    <div class="proxy-modal-content">
        <h3 style="margin-top: 0; margin-bottom: 10px; color: var(--text-main);">👤 Gán Proxy Cho Người Dùng (Token)</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">
            Chọn Proxy và Người dùng Token Facebook tương ứng để định tuyến các kết nối qua Proxy đó.
        </p>

        <form id="formAssignProxy">
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px;">1. Chọn Proxy:</label>
                <select name="proxy_id" id="assign_proxy_id" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);" required>
                    <option value="">-- Chọn Proxy --</option>
                    <?php foreach ($proxies as $px): ?>
                        <option value="<?php echo $px['id']; ?>">
                            <?php echo htmlspecialchars($px['proxy_string']); ?> 
                            (<?php echo $px['status'] === 'live' ? 'Sống ✓' : ($px['status'] === 'dead' ? 'Chết ✗' : 'Chưa thử'); ?>)
                            <?php echo !empty($px['user_name']) ? ' - [Đã gán: ' . htmlspecialchars($px['user_name']) . ']' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px;">2. Chọn Người Dùng (Token Facebook):</label>
                <select name="user_id" id="assign_user_id" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);" required>
                    <option value="">-- Chọn Người Dùng Token --</option>
                    <?php foreach ($user_tokens as $ut): ?>
                        <option value="<?php echo $ut['id']; ?>">
                            👤 <?php echo htmlspecialchars($ut['name']); ?> (FB ID: <?php echo htmlspecialchars($ut['fb_id']); ?>)
                            <?php echo !empty($ut['assigned_proxy_str']) ? ' - [Đã có Proxy: ' . htmlspecialchars($ut['assigned_proxy_str']) . ']' : ' - [Chưa có Proxy]'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="proxy-btn-secondary" onclick="closeAssignModal()">Hủy bỏ</button>
                <button type="submit" class="proxy-btn-primary" id="btnAssignSubmit">Xác nhận Gán</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addProxyModal').style.display = 'flex';
}
function closeAddModal() {
    document.getElementById('addProxyModal').style.display = 'none';
    document.getElementById('formAddProxy').reset();
}

function openAssignModal() {
    document.getElementById('assignProxyModal').style.display = 'flex';
}
function openAssignSingleModal(proxyId) {
    document.getElementById('assign_proxy_id').value = proxyId;
    document.getElementById('assignProxyModal').style.display = 'flex';
}
function closeAssignModal() {
    document.getElementById('assignProxyModal').style.display = 'none';
    document.getElementById('formAssignProxy').reset();
}

// Add Proxies Bulk
document.getElementById('formAddProxy').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnAddSubmit');
    btn.disabled = true;
    btn.textContent = 'Đang xử lý...';

    const formData = new FormData(this);
    formData.append('action', 'add_proxies');

    fetch('actions/proxy_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.textContent = 'Xác nhận Thêm';
        }
    })
    .catch(err => {
        alert('❌ Lỗi kết nối: ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Xác nhận Thêm';
    });
});

// Assign Proxy to User
document.getElementById('formAssignProxy').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnAssignSubmit');
    btn.disabled = true;
    btn.textContent = 'Đang gán...';

    const formData = new FormData(this);
    formData.append('action', 'assign_proxy');

    fetch('actions/proxy_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.textContent = 'Xác nhận Gán';
        }
    })
    .catch(err => {
        alert('❌ Lỗi kết nối: ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Xác nhận Gán';
    });
});

// Check Single Proxy Status
function checkSingleProxy(proxyId, btn) {
    const origIcon = btn.innerText;
    btn.disabled = true;
    btn.innerText = '⏳';

    fetch(`actions/proxy_actions.php?action=check_proxy&proxy_id=${proxyId}`)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.innerText = origIcon;
        }
    })
    .catch(err => {
        alert('❌ Lỗi kiểm tra: ' + err.message);
        btn.disabled = false;
        btn.innerText = origIcon;
    });
}

// Check All Proxies
function checkAllProxies() {
    const btn = document.getElementById('btnCheckAll');
    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> Đang kiểm tra...';

    fetch('actions/proxy_actions.php?action=check_all_proxies')
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<span>🔄</span> Kiểm tra tất cả';
        }
    })
    .catch(err => {
        alert('❌ Lỗi kiểm tra: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '<span>🔄</span> Kiểm tra tất cả';
    });
}

// Unassign Proxy
function unassignProxy(proxyId) {
    if (!confirm('Bạn có chắc chắn muốn hủy gán Proxy này khỏi Người dùng?')) return;

    const formData = new FormData();
    formData.append('action', 'unassign_proxy');
    formData.append('proxy_id', proxyId);

    fetch('actions/proxy_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('❌ ' + data.message);
        }
    });
}

// Delete Proxy
function deleteProxy(proxyId) {
    if (!confirm('Bạn có chắc chắn muốn xóa Proxy này khỏi kho không?')) return;

    const formData = new FormData();
    formData.append('action', 'delete_proxy');
    formData.append('proxy_id', proxyId);

    fetch('actions/proxy_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('❌ ' + data.message);
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
