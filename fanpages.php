<?php
$current_page = 'fanpages';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Regular user (and Admin) sees their own pages AND pages shared with their system account
// 1. Owned pages (indirectly via users table), plus who they shared them with
// 2. Shared pages

$stmt = $pdo->prepare("
    (SELECT pages.*, users.name as user_name,
           (SELECT GROUP_CONCAT(CONCAT(sa.username, ':', sa.id) SEPARATOR ', ')
            FROM page_shares ps
            JOIN system_accounts sa ON ps.shared_with_account_id = sa.id
            WHERE ps.page_id = pages.page_id) as shared_to_users
     FROM pages 
     JOIN users ON pages.user_id = users.id 
     WHERE users.account_id = :aid)
    UNION
    (SELECT p.*, 'Shared' as user_name, NULL as shared_to_users
     FROM pages p
     JOIN page_shares ps ON p.page_id = ps.page_id
     WHERE ps.shared_with_account_id = :aid2)
    ORDER BY created_at DESC
");
$stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt->execute();

$stmt_sys_u_2 = $pdo->prepare("SELECT id, username FROM system_accounts WHERE id != ? ORDER BY username ASC");
$stmt_sys_u_2->execute([$account_id]);
$sys_users = $stmt_sys_u_2->fetchAll(PDO::FETCH_ASSOC);

$stmt_u = $pdo->prepare("
    SELECT DISTINCT u.id, u.name 
    FROM users u 
    LEFT JOIN pages p ON u.id = p.user_id 
    LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
    WHERE u.account_id = :aid OR ps.shared_with_account_id = :aid2
    ORDER BY u.name ASC
");
$stmt_u->bindValue(':aid', $account_id, PDO::PARAM_INT);
$stmt_u->bindValue(':aid2', $account_id, PDO::PARAM_INT);
$stmt_u->execute();
$users = $stmt_u->fetchAll(PDO::FETCH_ASSOC);

$all_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-title">Quản lý Fanpage</div>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h3 style="margin: 0; margin-bottom: 10px;">Danh sách toàn bộ Fanpage</h3>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="text" id="searchName" placeholder="🔍 Tìm tên Fanpage..." style="padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 6px; width: 250px;">
                <select id="userFilter" style="padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 6px; min-width: 200px;">
                    <option value="">-- Lọc theo tài khoản quản lý --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo htmlspecialchars($user['name']); ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                    <?php
endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-danger" onclick="deleteSelectedPages()" style="height: 42px; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; display: flex; align-items: center; gap: 5px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                Xóa Page
            </button>
            <button class="btn btn-secondary" onclick="openShareModal()" style="height: 42px; background: #f8fafc; color: var(--primary-color); border: 1px solid var(--border-color); display: flex; align-items: center; gap: 5px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                Giao việc (Share)
            </button>
            <a href="token_management.php" class="btn btn-primary" style="height: 42px;">+ Thêm Fanpage Mới</a>
        </div>
    </div>

    <!-- Share Modal -->
    <div id="shareModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
        <div style="background: white; padding: 20px; border-radius: 8px; width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0;">Giao việc (Chia sẻ) Fanpage</h3>
            <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 15px;">Chọn nhân viên (Tài khoản hệ thống) để cấp quyền truy cập các Fanpage đã chọn.</p>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label>Chọn Tài khoản nhận Fanpage:</label>
                <select id="share_target_account" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    <option value="">-- Chọn Nhân Viên --</option>
                    <?php foreach ($sys_users as $s_user): ?>
                        <option value="<?php echo $s_user['id']; ?>"><?php echo htmlspecialchars($s_user['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-secondary" onclick="closeShareModal()">Hủy</button>
                <button class="btn btn-primary" onclick="submitShare()">Xác nhận Chia sẻ</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div id="deleteConfirmModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
        <div style="background: white; padding: 20px; border-radius: 8px; width: 450px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; color: #dc2626;">Xác nhận Xóa Fanpage</h3>
            <p id="deleteConfirmText" style="font-size: 15px; color: #334155; margin-bottom: 15px;">Bạn có chắc chắn muốn XÓA vĩnh viễn các Fanpage đã chọn?</p>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 20px; background: #fff1f2; padding: 10px; border-left: 4px solid #f43f5e;">⚠️ Mọi bài viết theo lịch và dữ liệu của các Fanpage này cũng sẽ bị xóa và không thể khôi phục.</p>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-secondary" onclick="closeDeleteModal()">Hủy</button>
                <button class="btn btn-danger" onclick="submitDelete()" style="background: #ef4444; color: white;">Vẫn Xóa</button>
            </div>
        </div>
    </div>

    <table style="min-width:850px;">
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
                                <input type="checkbox" name="page_ids[]" value="<?php echo htmlspecialchars($page['page_id']); ?>" class="page-checkbox">
                            <?php else: ?>
                                <span title="Bạn được chia sẻ Page này, không thể share tiếp"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></span>
                            <?php endif; ?>
                        </td>
                        <td style="color: var(--text-muted); font-size: 12px;"><a href="https://www.facebook.com/<?php echo htmlspecialchars($page['page_id']); ?>" target="_blank" style="color: var(--text-muted); text-decoration: none;" onmouseover="this.style.color='var(--primary-color)';this.style.textDecoration='underline'" onmouseout="this.style.color='var(--text-muted)';this.style.textDecoration='none'"><?php echo htmlspecialchars($page['page_id']); ?></a></td>
                        <td class="col-name" style="font-weight: 500; color: var(--primary-color);">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php if (!empty($page['avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($page['avatar']); ?>" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;" alt="Avatar">
                                <?php else: ?>
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #64748b; font-weight: bold; flex-shrink: 0;">
                                        <?php echo mb_strtoupper(mb_substr($page['name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <?php echo htmlspecialchars($page['name']); ?>
                                    <?php if ($page['user_name'] === 'Shared'): ?>
                                        <span style="font-size: 10px; background: #e0e7ff; color: #4338ca; padding: 2px 6px; border-radius: 10px; margin-left: 5px;">Được chia sẻ</span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($page['shared_to_users'])): ?>
                                        <div style="margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px;">
                                        <?php 
                                            $shared_users = explode(', ', $page['shared_to_users']);
                                            foreach ($shared_users as $su) {
                                                $parts = explode(':', $su);
                                                if (count($parts) === 2) {
                                                    $s_username = $parts[0];
                                                    $s_id = $parts[1];
                                                    echo '<span style="display: inline-flex; align-items: center; background: #f3f4f6; border: 1px solid #d1d5db; font-size: 11px; padding: 2px 6px; border-radius: 4px; color: #374151;">';
                                                    echo htmlspecialchars($s_username);
                                                    echo '<button onclick="unsharePage(\'' . addslashes($page['page_id']) . '\', ' . (int)$s_id . ', this)" style="background: none; border: none; font-size: 12px; color: #ef4444; margin-left: 4px; cursor: pointer; padding: 0 2px;" title="Xóa quyền">&times;</button>';
                                                    echo '</span>';
                                                }
                                            }
                                        ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="status-tag"><?php echo htmlspecialchars($page['category']); ?></span></td>
                        <td style="font-weight: 600;">
                            <?php
                            echo number_format($page['followers_count']);
                            $diff = isset($page['followers_diff']) ? $page['followers_diff'] : null;
                            if ($diff !== null && $diff != 0) {
                                if ($diff > 0) {
                                    echo ' <span style="font-size:12px;font-weight:600;color:#16a34a;white-space:nowrap;">▲ +' . number_format($diff) . '</span>';
                                } else {
                                    echo ' <span style="font-size:12px;font-weight:600;color:#dc2626;white-space:nowrap;">▼ ' . number_format($diff) . '</span>';
                                }
                            } elseif ($diff === 0) {
                                echo ' <span style="font-size:11px;color:#9ca3af;white-space:nowrap;">→ 0</span>';
                            }
                            ?>
                        </td>
                        <td class="col-user"><?php echo htmlspecialchars($page['user_name'] === 'Shared' ? 'Khác' : $page['user_name']); ?></td>
                        <td><span style="color: var(--secondary-color); font-size: 12px; font-weight: 500;">Hoạt động</span></td>
                    </tr>
                <?php
    endforeach; ?>
            <?php
else: ?>
                <tr>
                    <td colspan="6" style="text-align:center; color:#6b7280;">Chưa có Fanpage nào được tải. Vui lòng thêm Token.</td>
                </tr>
            <?php
endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchName');
    const userFilter = document.getElementById('userFilter');
    const rows = document.querySelectorAll('.page-row');

    function filterTable() {
        const searchTerm = searchInput.value.toLowerCase();
        const filterUser = userFilter.value;

        rows.forEach(row => {
            const name = row.querySelector('.col-name').textContent.toLowerCase();
            const user = row.querySelector('.col-user').textContent;

            const matchesSearch = name.includes(searchTerm);
            const matchesUser = filterUser === "" || user === filterUser;

            if (matchesSearch && matchesUser) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    searchInput.addEventListener('input', filterTable);
    userFilter.addEventListener('change', filterTable);

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

function openShareModal() {
    const checked = document.querySelectorAll('.page-checkbox:checked').length;
    if (checked === 0) {
        alert('Vui lòng tích chọn ít nhất 1 Fanpage để giao việc.');
        return;
    }
    document.getElementById('shareModal').style.display = 'flex';
}

function closeShareModal() {
    document.getElementById('shareModal').style.display = 'none';
    document.getElementById('share_target_account').value = '';
}

function submitShare() {
    const targetAccountId = document.getElementById('share_target_account').value;
    if (!targetAccountId) {
        alert('Vui lòng chọn một nhân viên để giao việc.');
        return;
    }
    
    const checkboxes = document.querySelectorAll('.page-checkbox:checked');
    let pageIds = [];
    checkboxes.forEach(cb => pageIds.push(cb.value));
    
    // Ajax request
    const formData = new FormData();
    formData.append('target_account_id', targetAccountId);
    formData.append('page_ids', JSON.stringify(pageIds));
    
    fetch('actions/share_pages.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            location.reload(); // Reload để hiện badge
        } else {
            alert('Lỗi: ' + res.msg);
        }
    })
    .catch(err => {
        alert('Đã xảy ra lỗi mạng.');
    });
}

function deleteSelectedPages() {
    const checked = document.querySelectorAll('.page-checkbox:checked');
    if (checked.length === 0) {
        alert('Vui lòng tích chọn ít nhất 1 Fanpage để xóa.');
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
    
    // Đóng modal xoá
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
            location.reload();
        } else {
            alert('Lỗi: ' + res.msg);
        }
    })
    .catch(err => {
        alert('Đã xảy ra lỗi mạng: ' + err.message);
    });
}


function unsharePage(pageId, targetAccountId, btnElement) {
    if (!confirm('Bạn có chắc chắn muốn XÓA quyền của tài khoản này khỏi Fanpage?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('page_id', pageId);
    formData.append('target_account_id', targetAccountId);
    
    fetch('actions/unshare_page.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            // Remove the badge from the UI
            const badge = btnElement.closest('span');
            if (badge) {
                badge.remove();
            }
        } else {
            alert('Lỗi: ' + res.msg);
        }
    })
    .catch(err => {
        alert('Đã xảy ra lỗi mạng: ' + err.message);
    });
}
</script>

<?php include 'includes/footer.php'; ?>
