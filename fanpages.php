<?php
$current_page = 'fanpages';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');
session_write_close();

// Tự động dọn dẹp nick cá nhân (nếu trước đây bị lưu nhầm vào bảng pages) - chạy 1 lần/phiên
if (empty($_SESSION['fanpages_cleaned'])) {
    try {
        $pdo->exec("DELETE p FROM pages p JOIN users u ON p.page_id = u.fb_id");
        $_SESSION['fanpages_cleaned'] = true;
    } catch (Exception $e) {}
}

// Regular user (and Admin) sees their own pages AND pages shared with their system account
$stmt = $pdo->prepare("
    (SELECT pages.*, users.name as user_name
     FROM pages 
     JOIN users ON pages.user_id = users.id 
     WHERE users.account_id = :aid)
    UNION ALL
    (SELECT p.*, 'Shared' as user_name
     FROM pages p
     JOIN page_shares ps ON p.page_id = ps.page_id
     WHERE ps.shared_with_account_id = :aid2)
    ORDER BY created_at DESC
");
$stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt->execute();
$all_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch map shared_to_users in PHP memory (O(1) query instead of O(N) subqueries)
if (!empty($all_pages)) {
    $owned_page_ids = [];
    foreach ($all_pages as $p) {
        if (($p['user_name'] ?? '') !== 'Shared' && !empty($p['page_id'])) {
            $owned_page_ids[] = $p['page_id'];
        }
    }
    
    $shares_map = [];
    if (!empty($owned_page_ids)) {
        $in_placeholders = implode(',', array_fill(0, count($owned_page_ids), '?'));
        $stmt_shares = $pdo->prepare("
            SELECT ps.page_id, sa.username, sa.id
            FROM page_shares ps
            JOIN system_accounts sa ON ps.shared_with_account_id = sa.id
            WHERE ps.page_id IN ($in_placeholders)
        ");
        $stmt_shares->execute($owned_page_ids);
        while ($row = $stmt_shares->fetch(PDO::FETCH_ASSOC)) {
            $pid = $row['page_id'];
            if (!isset($shares_map[$pid])) $shares_map[$pid] = [];
            $shares_map[$pid][] = $row['username'] . ':' . $row['id'];
        }
    }
    
    foreach ($all_pages as &$p) {
        $pid = $p['page_id'] ?? '';
        $p['shared_to_users'] = isset($shares_map[$pid]) ? implode(', ', $shares_map[$pid]) : null;
    }
    unset($p);
}

$stmt_sys_u_2 = $pdo->prepare("SELECT id, username FROM system_accounts WHERE id != ? ORDER BY username ASC");
$stmt_sys_u_2->execute([$account_id]);
$sys_users = $stmt_sys_u_2->fetchAll(PDO::FETCH_ASSOC);

$stmt_u = $pdo->prepare("
    SELECT id, name 
    FROM users 
    WHERE account_id = :aid
    ORDER BY name ASC
");
$stmt_u->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt_u->execute();
$users = $stmt_u->fetchAll(PDO::FETCH_ASSOC);

// Fetch Page Groups & Mapping
$page_groups = [];
$page_group_map = [];
try {
    $stmt_pg = $pdo->prepare("SELECT id, name FROM page_groups WHERE account_id = ? ORDER BY name ASC");
    $stmt_pg->execute([$account_id]);
    $page_groups = $stmt_pg->fetchAll(PDO::FETCH_ASSOC);

    $stmt_pgi = $pdo->prepare("
        SELECT i.page_id, g.id as group_id, g.name as group_name
        FROM page_group_items i
        JOIN page_groups g ON i.group_id = g.id
        WHERE g.account_id = ?
    ");
    $stmt_pgi->execute([$account_id]);
    while ($r = $stmt_pgi->fetch(PDO::FETCH_ASSOC)) {
        $pid = $r['page_id'];
        if (!isset($page_group_map[$pid])) $page_group_map[$pid] = [];
        $page_group_map[$pid][] = [
            'id' => $r['group_id'],
            'name' => $r['group_name']
        ];
    }
} catch (Exception $e) {}
?>

<style>
/* Evondev Skill Styling for Fanpages Manager */
.fp-container,
.fp-container button,
.fp-container input,
.fp-container select,
.fp-container textarea {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.fp-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

/* Header Banner Card */
.fp-header-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
    border: 1px solid #312e81;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(15, 23, 42, 0.15);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.fp-header-left h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.fp-header-left p {
    font-size: 13.5px;
    color: #cbd5e1;
    margin: 0;
}

.fp-count-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 800;
    color: #c7d2fe;
}

/* Filter Bar Card */
.fp-filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px 22px;
    margin-bottom: 24px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.fp-filter-left {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    flex: 1;
    min-width: 280px;
}

.fp-input, .fp-select {
    padding: 10px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
.fp-input:focus, .fp-select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.fp-filter-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.fp-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 40px;
    padding: 0 16px;
    font-size: 13px;
    font-weight: 700;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
}

.fp-btn-pink {
    background: #fdf2f8;
    color: #be185d;
    border: 1px solid #fbcfe8;
}
.fp-btn-pink:hover {
    background: #fce7f3;
    transform: translateY(-1px);
}

.fp-btn-blue {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.fp-btn-blue:hover {
    background: #dbeafe;
    transform: translateY(-1px);
}

.fp-btn-danger {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}
.fp-btn-danger:hover {
    background: #fee2e2;
    transform: translateY(-1px);
}

.fp-btn-primary {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
}
.fp-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}

/* Fanpages Table Surface */
.fp-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
}

.fp-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
    text-align: left;
}
.fp-table th {
    background: #f8fafc;
    padding: 14px 18px;
    font-weight: 800;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase;
    font-size: 11.5px;
    letter-spacing: 0.05em;
}
.fp-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.fp-table tr:hover td {
    background: #fafafa;
}
.fp-table tr:last-child td {
    border-bottom: none;
}

/* Badges & Tags */
.col-page-id a {
    color: #64748b;
    font-family: monospace;
    font-size: 12.5px;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.15s;
}
.col-page-id a:hover {
    color: #4f46e5;
    text-decoration: underline;
}

.category-tag {
    display: inline-block;
    padding: 4px 10px;
    background: #f1f5f9;
    color: #475569;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 700;
}

.group-badge {
    font-size: 10.5px;
    background: #fdf2f8;
    color: #be185d;
    border: 1px solid #fbcfe8;
    padding: 3px 8px;
    border-radius: 9999px;
    font-weight: 700;
}

.shared-tag {
    font-size: 10.5px;
    background: #e0e7ff;
    color: #4338ca;
    padding: 3px 8px;
    border-radius: 9999px;
    font-weight: 700;
}

.status-active-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
    padding: 4px 12px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 800;
}

/* Glassmorphism Modals */
.fp-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(6px);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    padding: 20px;
}
.fp-modal-content {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    width: 100%;
    max-width: 540px;
    padding: 28px;
    box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
    animation: modalFadeIn 0.2s ease-out;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(10px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.fp-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
.fp-modal-header h3 {
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.fp-modal-close {
    background: transparent;
    border: none;
    font-size: 20px;
    color: #64748b;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
}
.fp-modal-close:hover { background: #f1f5f9; color: #0f172a; }

.fp-btn-modal-secondary {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 9px 18px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
}
.fp-btn-modal-secondary:hover { background: #f8fafc; border-color: #94a3b8; }
</style>

<div class="fp-container">
    <!-- Header Banner Card -->
    <div class="fp-header-card">
        <div class="fp-header-left">
            <h1>
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21a2 2 0 012 2v11a2 2 0 01-2 2H5a2 2 0 01-2-2zm0 0h18"/></svg>
                Quản Lý Danh Sách Fanpage
            </h1>
            <p>Theo dõi danh sách Fanpage sở hữu & chia sẻ, nhóm Fanpage và lượt tăng trưởng người theo dõi</p>
        </div>
        <div class="fp-count-pill">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            <span>Tổng: <?= count($all_pages) ?> Fanpage</span>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="fp-filter-card">
        <div class="fp-filter-left">
            <input type="text" id="searchName" placeholder="🔍 Tìm Fanpage hoặc Page ID..." class="fp-input" style="width: 200px;">
            <select id="userFilter" class="fp-select" style="max-width: 170px;">
                <option value="">-- Tất cả User --</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= htmlspecialchars($user['name']); ?>"><?= htmlspecialchars($user['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="groupFilter" class="fp-select" style="max-width: 170px;">
                <option value="">-- Tất cả Nhóm --</option>
                <?php foreach ($page_groups as $pg): ?>
                    <option value="<?= htmlspecialchars($pg['name']); ?>"><?= htmlspecialchars($pg['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fp-filter-actions">
            <button type="button" class="fp-btn fp-btn-pink" onclick="openAddToGroupModal()">
                📁 Thêm Vào Nhóm
            </button>
            <button type="button" class="fp-btn fp-btn-blue" onclick="openManageGroupsModal()">
                📂 Quản Lý Nhóm
            </button>
            <button type="button" class="fp-btn fp-btn-danger" onclick="deleteSelectedPages()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"></path><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                Xóa Page
            </button>
            <a href="token_management.php" class="fp-btn fp-btn-primary">+ Thêm Page Mới</a>
        </div>
    </div>

    <!-- Modals -->
    <!-- Modal 1: Confirm Delete -->
    <div id="deleteConfirmModal" class="fp-modal">
        <div class="fp-modal-content" style="max-width: 460px;">
            <div class="fp-modal-header">
                <h3 style="color: #dc2626;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Xác nhận Xóa Fanpage
                </h3>
                <button type="button" class="fp-modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <p id="deleteConfirmText" style="font-size: 14px; color: #334155; margin-bottom: 14px;">Bạn có chắc chắn muốn XÓA vĩnh viễn các Fanpage đã chọn?</p>
            <div style="font-size: 12.5px; color: #9f1239; margin-bottom: 20px; background: #fff1f2; padding: 12px; border-radius: 10px; border: 1px solid #fecdd3;">
                ⚠️ Mọi bài viết theo lịch và dữ liệu liên quan của các Fanpage này cũng sẽ bị xóa vĩnh viễn và không thể khôi phục.
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="fp-btn-modal-secondary" onclick="closeDeleteModal()">Hủy</button>
                <button type="button" class="fp-btn fp-btn-danger" style="height:38px;" onclick="submitDelete()">Xác nhận Xóa</button>
            </div>
        </div>
    </div>

    <!-- Modal 2: Add to Group -->
    <div id="addToGroupModal" class="fp-modal">
        <div class="fp-modal-content">
            <div class="fp-modal-header">
                <h3 style="color: #be185d;">
                    <span>📁</span> Thêm Fanpage Vào Nhóm
                </h3>
                <button type="button" class="fp-modal-close" onclick="closeAddToGroupModal()">&times;</button>
            </div>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;" id="addToGroupDesc">
                Chọn nhóm có sẵn hoặc nhập tên nhóm mới để gán các Fanpage đã tích chọn.
            </p>

            <div style="margin-bottom: 14px;">
                <label style="font-size: 13px; font-weight: 700; display: block; margin-bottom: 6px; color: #334155;">1. Chọn Nhóm Có Sẵn:</label>
                <select id="select_existing_group" class="fp-select" style="width: 100%;">
                    <option value="">-- Chọn Nhóm Fanpage --</option>
                    <?php foreach ($page_groups as $pg): ?>
                        <option value="<?= $pg['id']; ?>"><?= htmlspecialchars($pg['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="text-align: center; font-size: 11px; color: #94a3b8; margin: 12px 0; font-weight: 800;">--- HOẶC ---</div>

            <div style="margin-bottom: 22px;">
                <label style="font-size: 13px; font-weight: 700; display: block; margin-bottom: 6px; color: #334155;">2. Tạo Nhóm Mới:</label>
                <input type="text" id="new_group_name" placeholder="Ví dụ: Quần Áo, Gia Dụng, Mỹ Phẩm..." class="fp-input" style="width: 100%;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="fp-btn-modal-secondary" onclick="closeAddToGroupModal()">Hủy</button>
                <button type="button" class="fp-btn fp-btn-pink" onclick="submitAddToGroup()">Lưu Vào Nhóm</button>
            </div>
        </div>
    </div>

    <!-- Modal 3: Manage Groups -->
    <div id="manageGroupsModal" class="fp-modal">
        <div class="fp-modal-content" style="max-width: 650px; max-height: 85vh; display: flex; flex-direction: column; padding: 0; overflow: hidden;">
            <div style="padding: 18px 24px; background: #eff6ff; border-bottom: 1px solid #bfdbfe; display: flex; align-items: center; justify-content: space-between;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #1d4ed8; display: flex; align-items: center; gap: 8px;">
                    <span>📂</span> Quản Lý Danh Sách Nhóm Fanpage
                </h3>
                <button type="button" onclick="closeManageGroupsModal()" class="fp-modal-close" style="color: #1e40af;">&times;</button>
            </div>
            <div style="padding: 24px; overflow-y: auto; flex: 1;">
                <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                    <input type="text" id="quick_group_name" placeholder="Nhập tên Nhóm Fanpage mới..." class="fp-input" style="flex: 1;">
                    <button type="button" class="fp-btn fp-btn-primary" onclick="quickCreateGroup()" style="white-space: nowrap;">+ Tạo Nhóm Mới</button>
                </div>
                <div id="groups_list_wrap">
                    <div style="text-align: center; color: #64748b; padding: 20px;">Đang tải danh sách nhóm...</div>
                </div>
            </div>
            <div style="padding: 14px 24px; background: #fafafa; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
                <button type="button" class="fp-btn-modal-secondary" onclick="closeManageGroupsModal()">Đóng Window</button>
            </div>
        </div>
    </div>

    <!-- Fanpages Table Surface -->
    <div class="fp-table-card">
        <div style="overflow-x: auto;">
            <table class="fp-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="selectAllPages"></th>
                        <th>Page ID</th>
                        <th>Tên Fanpage</th>
                        <th>Danh mục</th>
                        <th>Người theo dõi</th>
                        <th>Tài khoản quản lý</th>
                        <th>Trạng thái Token</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($all_pages) > 0): ?>
                        <?php foreach ($all_pages as $page): ?>
                            <tr class="page-row">
                                <td>
                                    <?php if ($page['user_name'] !== 'Shared'): ?>
                                        <input type="checkbox" name="page_ids[]" value="<?= htmlspecialchars($page['page_id']); ?>" class="page-checkbox">
                                    <?php else: ?>
                                        <span title="Bạn được chia sẻ Page này, không thể share tiếp">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-page-id">
                                    <a href="https://www.facebook.com/<?= htmlspecialchars($page['page_id']); ?>" target="_blank">
                                        <?= htmlspecialchars($page['page_id']); ?>
                                    </a>
                                </td>
                                <td class="col-name">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <?php if (!empty($page['avatar'])): ?>
                                            <img src="<?= htmlspecialchars($page['avatar']); ?>" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid #e2e8f0;" alt="Avatar">
                                        <?php else: ?>
                                            <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%); display: flex; align-items: center; justify-content: center; font-size: 14px; color: #475569; font-weight: 800; flex-shrink: 0;">
                                                <?= mb_strtoupper(mb_substr($page['name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <span class="page-name-title" style="font-weight: 800; color: #0f172a; font-size: 14px;"><?= htmlspecialchars($page['name']); ?></span>
                                            <?php if ($page['user_name'] === 'Shared'): ?>
                                                <span class="shared-tag" style="margin-left: 4px;">Được chia sẻ</span>
                                            <?php endif; ?>

                                            <?php if (!empty($page['shared_to_users'])): ?>
                                                <div style="margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px;">
                                                <?php 
                                                    $shared_users = explode(', ', $page['shared_to_users']);
                                                    foreach ($shared_users as $su) {
                                                        $parts = explode(':', $su);
                                                        if (count($parts) === 2) {
                                                            $s_username = $parts[0];
                                                            echo '<span style="display: inline-flex; align-items: center; background: #f1f5f9; border: 1px solid #e2e8f0; font-size: 10.5px; padding: 2px 6px; border-radius: 4px; color: #475569; font-weight:600;">';
                                                            echo htmlspecialchars($s_username);
                                                            echo '</span>';
                                                        }
                                                    }
                                                ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($page_group_map[$page['page_id']])): ?>
                                                <div style="margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px;" class="col-group-badges">
                                                <?php foreach ($page_group_map[$page['page_id']] as $g_info): ?>
                                                    <span class="page-group-badge-name group-badge">
                                                        📁 <?= htmlspecialchars($g_info['name']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="category-tag"><?= htmlspecialchars($page['category']); ?></span></td>
                                <td style="font-weight: 800; color: #0f172a;">
                                    <?php
                                    echo number_format($page['followers_count']);
                                    $diff = isset($page['followers_diff']) ? $page['followers_diff'] : null;
                                    if ($diff !== null && $diff != 0) {
                                        if ($diff > 0) {
                                            echo ' <span style="font-size:11.5px;font-weight:800;color:#16a34a;white-space:nowrap;">▲ +' . number_format($diff) . '</span>';
                                        } else {
                                            echo ' <span style="font-size:11.5px;font-weight:800;color:#dc2626;white-space:nowrap;">▼ ' . number_format($diff) . '</span>';
                                        }
                                    } elseif ($diff === 0) {
                                        echo ' <span style="font-size:11px;color:#94a3b8;white-space:nowrap;">→ 0</span>';
                                    }
                                    ?>
                                </td>
                                <td class="col-user" style="font-weight: 700; color: #475569;">
                                    <?= htmlspecialchars($page['user_name'] === 'Shared' ? 'Khác' : $page['user_name']); ?>
                                </td>
                                <td>
                                    <span class="status-active-pill">
                                        <span style="width:6px;height:6px;border-radius:50%;background:#10b981;"></span>
                                        <span>Hoạt động</span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; color:#64748b; padding: 48px;">
                                <div style="font-size: 32px; margin-bottom: 8px;">🚩</div>
                                <div style="font-weight: 700; font-size: 15px; color: #1e293b; margin-bottom: 4px;">Chưa có Fanpage nào được tải</div>
                                <div style="font-size: 13px;">Vui lòng thêm Token tại trang Quản lý Token để tải danh sách Fanpage.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchName');
    const userFilter = document.getElementById('userFilter');
    const groupFilter = document.getElementById('groupFilter');
    const rows = document.querySelectorAll('.page-row');

    function filterTable() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const filterUser = userFilter ? userFilter.value.trim() : '';
        const filterGroup = groupFilter ? groupFilter.value.trim() : '';

        rows.forEach(row => {
            const nameEl = row.querySelector('.page-name-title');
            const pageIdEl = row.querySelector('.col-page-id');
            const name = nameEl ? nameEl.textContent.toLowerCase() : '';
            const pageId = pageIdEl ? pageIdEl.textContent.toLowerCase() : '';
            const user = row.querySelector('.col-user') ? row.querySelector('.col-user').textContent.trim() : '';
            const groupBadges = row.querySelectorAll('.page-group-badge-name');
            let groupNames = [];
            groupBadges.forEach(b => groupNames.push(b.textContent.replace('📁', '').trim()));

            const matchesSearch = !searchTerm || name.includes(searchTerm) || pageId.includes(searchTerm);
            const matchesUser = filterUser === "" || user === filterUser;
            const matchesGroup = filterGroup === "" || groupNames.includes(filterGroup);

            if (matchesSearch && matchesUser && matchesGroup) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterTable);
        searchInput.addEventListener('keyup', filterTable);
        searchInput.addEventListener('change', filterTable);
    }
    if (userFilter) userFilter.addEventListener('change', filterTable);
    if (groupFilter) groupFilter.addEventListener('change', filterTable);

    // Select All Logic
    const selectAllCheckbox = document.getElementById('selectAllPages');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.page-checkbox');
            checkboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = this.checked;
                }
            });
        });
    }
});

function openAddToGroupModal() {
    const checked = document.querySelectorAll('.page-checkbox:checked').length;
    if (checked === 0) {
        if (typeof window.showNotice === 'function') {
            window.showNotice('Vui lòng tích chọn ít nhất 1 Fanpage để thêm vào nhóm!', 'warning');
        } else {
            alert('Vui lòng tích chọn ít nhất 1 Fanpage để thêm vào nhóm!');
        }
        return;
    }
    document.getElementById('addToGroupDesc').textContent = `Đang chọn ${checked} Fanpage để gán vào Nhóm:`;
    document.getElementById('select_existing_group').value = '';
    document.getElementById('new_group_name').value = '';
    document.getElementById('addToGroupModal').style.display = 'flex';
}

function closeAddToGroupModal() {
    document.getElementById('addToGroupModal').style.display = 'none';
}

function submitAddToGroup() {
    const checked = document.querySelectorAll('.page-checkbox:checked');
    let pageIds = [];
    checked.forEach(cb => pageIds.push(cb.value));

    if (pageIds.length === 0) {
        if (typeof window.showNotice === 'function') {
            window.showNotice('Vui lòng tích chọn ít nhất 1 Fanpage!', 'warning');
        }
        return;
    }

    const existingGroupId = document.getElementById('select_existing_group').value;
    const newGroupName = document.getElementById('new_group_name').value.trim();

    if (!existingGroupId && !newGroupName) {
        if (typeof window.showNotice === 'function') {
            window.showNotice('Vui lòng chọn Nhóm có sẵn hoặc nhập tên Nhóm mới!', 'warning');
        }
        return;
    }

    const btnSubmit = document.querySelector('#addToGroupModal button[onclick="submitAddToGroup()"]');
    if (btnSubmit) {
        btnSubmit.disabled = true;
        btnSubmit.textContent = '⏳ Đang lưu...';
    }

    const formData = new FormData();
    if (newGroupName) {
        formData.append('action', 'create_group');
        formData.append('name', newGroupName);
        formData.append('page_ids', JSON.stringify(pageIds));
    } else {
        formData.append('action', 'add_pages_to_group');
        formData.append('group_id', existingGroupId);
        formData.append('page_ids', JSON.stringify(pageIds));
    }

    fetch('actions/manage_page_groups.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg, 'success');
            closeAddToGroupModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg, 'error');
            if (btnSubmit) {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Lưu Vào Nhóm';
            }
        }
    })
    .catch(err => {
        if (typeof window.showNotice === 'function') window.showNotice('Lỗi mạng hoặc CSDL khi lưu nhóm.', 'error');
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Lưu Vào Nhóm';
        }
    });
}

function openManageGroupsModal() {
    document.getElementById('manageGroupsModal').style.display = 'flex';
    loadGroupsList();
}

function closeManageGroupsModal() {
    document.getElementById('manageGroupsModal').style.display = 'none';
}

function loadGroupsList() {
    const wrap = document.getElementById('groups_list_wrap');
    wrap.innerHTML = '<div style="text-align: center; color: #64748b; padding: 20px;">⌛ Đang tải danh sách...</div>';

    fetch('actions/manage_page_groups.php?action=get_groups')
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            const groups = res.groups || [];
            if (groups.length === 0) {
                wrap.innerHTML = '<div style="text-align: center; color: #94a3b8; padding: 30px;">Bạn chưa tạo Nhóm Fanpage nào. Nhập tên phía trên để tạo mới.</div>';
                return;
            }

            let html = '<div style="display: flex; flex-direction: column; gap: 10px;">';
            groups.forEach(g => {
                html += `
                <div id="group_item_${g.id}" style="padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <strong style="color: #1e293b; font-size: 14px;">📁 ${escapeHtml(g.name)}</strong>
                            <span style="font-size: 12px; color: #64748b; margin-left: 8px;">(${g.total_pages} Fanpage)</span>
                        </div>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" class="fp-btn-modal-secondary" style="padding: 4px 10px; font-size: 12px;" onclick="showRenameGroupForm(${g.id})">✏️ Đổi tên</button>
                            <button type="button" class="fp-btn fp-btn-danger" style="padding: 4px 10px; font-size: 12px; height:auto;" onclick="showDeleteGroupConfirm(${g.id})">🗑️ Xóa</button>
                        </div>
                    </div>

                    <div id="rename_box_${g.id}" style="display:none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="rename_input_${g.id}" value="${escapeHtml(g.name)}" class="fp-input" style="flex:1; padding: 6px 10px; font-size: 13px;">
                            <button type="button" class="fp-btn fp-btn-primary" style="padding: 4px 12px; font-size: 12px; height:auto;" onclick="submitRenameGroup(${g.id})">Lưu</button>
                            <button type="button" class="fp-btn-modal-secondary" style="padding: 4px 10px; font-size: 12px;" onclick="document.getElementById('rename_box_${g.id}').style.display='none'">Hủy</button>
                        </div>
                    </div>

                    <div id="delete_box_${g.id}" style="display:none; margin-top: 10px; padding: 10px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px;">
                        <p style="margin: 0 0 8px 0; font-size: 12.5px; color: #9f1239; font-weight: 700;">Bạn chắc chắn muốn xóa nhóm '${escapeHtml(g.name)}'? (Các Fanpage bên trong không bị xóa)</p>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="fp-btn fp-btn-danger" style="padding: 4px 12px; font-size: 12px; height:auto;" onclick="submitDeleteGroup(${g.id})">Xác nhận Xóa</button>
                            <button type="button" class="fp-btn-modal-secondary" style="padding: 4px 10px; font-size: 12px;" onclick="document.getElementById('delete_box_${g.id}').style.display='none'">Hủy</button>
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';
            wrap.innerHTML = html;
        } else {
            wrap.innerHTML = `<div style="text-align: center; color: #ef4444; padding: 20px;">${escapeHtml(res.msg)}</div>`;
        }
    })
    .catch(err => {
        wrap.innerHTML = `<div style="text-align: center; color: #ef4444; padding: 20px;">Lỗi mạng khi tải danh sách nhóm.</div>`;
    });
}

function quickCreateGroup() {
    const input = document.getElementById('quick_group_name');
    const name = input.value.trim();
    if (!name) {
        if (typeof window.showNotice === 'function') window.showNotice('Vui lòng nhập tên Nhóm!', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'create_group');
    formData.append('name', name);

    fetch('actions/manage_page_groups.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg, 'success');
            input.value = '';
            loadGroupsList();
        } else {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg, 'error');
        }
    })
    .catch(err => {
        if (typeof window.showNotice === 'function') window.showNotice('Lỗi mạng khi tạo nhóm mới.', 'error');
    });
}

function showRenameGroupForm(groupId) {
    const renameBox = document.getElementById('rename_box_' + groupId);
    const deleteBox = document.getElementById('delete_box_' + groupId);
    if (deleteBox) deleteBox.style.display = 'none';
    if (renameBox) renameBox.style.display = renameBox.style.display === 'none' ? 'block' : 'none';
}

function submitRenameGroup(groupId) {
    const input = document.getElementById('rename_input_' + groupId);
    const newName = input ? input.value.trim() : '';
    if (!newName) {
        if (typeof window.showNotice === 'function') window.showNotice('Vui lòng nhập tên nhóm mới!', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_group');
    formData.append('group_id', groupId);
    formData.append('name', newName);

    fetch('actions/manage_page_groups.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg, 'success');
            loadGroupsList();
        } else {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg, 'error');
        }
    })
    .catch(err => {
        if (typeof window.showNotice === 'function') window.showNotice('Lỗi mạng khi đổi tên nhóm.', 'error');
    });
}

function showDeleteGroupConfirm(groupId) {
    const renameBox = document.getElementById('rename_box_' + groupId);
    const deleteBox = document.getElementById('delete_box_' + groupId);
    if (renameBox) renameBox.style.display = 'none';
    if (deleteBox) deleteBox.style.display = deleteBox.style.display === 'none' ? 'block' : 'none';
}

function submitDeleteGroup(groupId) {
    const formData = new FormData();
    formData.append('action', 'delete_group');
    formData.append('group_id', groupId);

    fetch('actions/manage_page_groups.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg, 'success');
            loadGroupsList();
        } else {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg, 'error');
        }
    })
    .catch(err => {
        if (typeof window.showNotice === 'function') window.showNotice('Lỗi mạng khi xóa nhóm.', 'error');
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function deleteSelectedPages() {
    const checked = document.querySelectorAll('.page-checkbox:checked');
    if (checked.length === 0) {
        if (typeof window.showNotice === 'function') window.showNotice('Vui lòng tích chọn ít nhất 1 Fanpage để xóa!', 'warning');
        return;
    }
    
    document.getElementById('deleteConfirmText').innerText = `Bạn có chắc chắn muốn XÓA vĩnh viễn ${checked.length} Fanpage đã chọn ra khỏi hệ thống?`;
    document.getElementById('deleteConfirmModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteConfirmModal').style.display = 'none';
}

function submitDelete() {
    const checked = document.querySelectorAll('.page-checkbox:checked');
    let pageIds = [];
    checked.forEach(cb => pageIds.push(cb.value));
    
    closeDeleteModal();
    
    const formData = new FormData();
    formData.append('page_ids', JSON.stringify(pageIds));
    
    fetch('actions/delete_fb_pages.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg || 'Đã xóa Fanpage thành công!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            if (typeof window.showNotice === 'function') window.showNotice(res.msg || 'Lỗi khi xóa Fanpage.', 'error');
        }
    })
    .catch(err => {
        if (typeof window.showNotice === 'function') window.showNotice('Lỗi mạng: ' + (err.message || err), 'error');
    });
}
</script>

<?php include 'includes/footer.php'; ?>
