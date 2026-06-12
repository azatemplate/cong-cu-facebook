<?php
$current_page = 'accounts';
require_once __DIR__ . '/includes/header.php';

if ($_SESSION['role'] !== 'admin') {
    echo "<div class='page-title'>Truy cập bị từ chối</div>";
    echo "<div class='card'>Bạn không có quyền truy cập trang này.</div>";
    include 'includes/footer.php';
    exit;
}

// CSRF is now handled by the centralized includes/security.php

$alert_type = '';
$alert_message = '';

// Handle Create / Delete User
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        
        if (!empty($username) && !empty($password)) {
            $stmt = $pdo->prepare("SELECT id FROM system_accounts WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $alert_type = 'danger';
                $alert_message = 'Tên đăng nhập đã tồn tại.';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $i_stmt = $pdo->prepare("INSERT INTO system_accounts (username, password, role, expire_date, page_limit) VALUES (?, ?, 'user', DATE_ADD(NOW(), INTERVAL 7 DAY), 500)");
                $i_stmt->execute([$username, $hashed]);
                $alert_type = 'success';
                $alert_message = 'Tạo tài khoản thành công. (Mặc định: Hạn 7 ngày, 500 Bài/Ngày)';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit_limits') {
        $edit_id = intval($_POST['edit_id']);
        $new_expire = trim($_POST['expire_date']);
        $new_limit = intval($_POST['page_limit']);
        
        if ($new_limit < 0) $new_limit = 0;
        
        $upd_stmt = $pdo->prepare("UPDATE system_accounts SET expire_date = ?, page_limit = ? WHERE id = ?");
        $upd_stmt->execute([
            empty($new_expire) ? null : $new_expire, 
            $new_limit, 
            $edit_id
        ]);
        
        $alert_type = 'success';
        $alert_message = 'Cập nhật giới hạn thành công!';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    verify_csrf();
    $reset_id = intval($_POST['reset_id']);
    $default_password = password_hash('123456', PASSWORD_DEFAULT);
    $r_stmt = $pdo->prepare("UPDATE system_accounts SET password = ? WHERE id = ?");
    $r_stmt->execute([$default_password, $reset_id]);
    $alert_type = 'success';
    $alert_message = 'Đã reset mật khẩu về mặc định: 123456';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    verify_csrf();
    $del_id = intval($_POST['del_id']);
    if ($del_id !== $_SESSION['account_id']) {
        try {
            $pdo->beginTransaction();

            // 1. Lấy toàn bộ user_id thuộc account này
            $u_stmt = $pdo->prepare("SELECT id FROM users WHERE account_id = ?");
            $u_stmt->execute([$del_id]);
            $user_ids = $u_stmt->fetchAll(PDO::FETCH_COLUMN);

            // 2. Lấy toàn bộ page_id thuộc các users đó
            $page_ids = [];
            if (!empty($user_ids)) {
                $in_u = implode(',', array_fill(0, count($user_ids), '?'));
                $pg_stmt = $pdo->prepare("SELECT page_id FROM pages WHERE user_id IN ($in_u)");
                $pg_stmt->execute($user_ids);
                $page_ids = $pg_stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // 3. Xóa dữ liệu liên quan đến các page
            if (!empty($page_ids)) {
                $in_p = implode(',', array_fill(0, count($page_ids), '?'));

                // Xóa media files trước khi xóa scheduled_posts
                $media_stmt = $pdo->prepare("SELECT media_path FROM scheduled_posts WHERE page_id IN ($in_p) AND media_path IS NOT NULL AND media_path != ''");
                $media_stmt->execute($page_ids);
                foreach ($media_stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
                    foreach (explode(',', $path) as $p) {
                        $p = trim($p);
                        if (strpos($p, 'uploads/') !== false) {
                            $full = __DIR__ . '/../' . $p;
                            if (file_exists($full) && is_file($full)) @unlink($full);
                        }
                    }
                }

                $pdo->prepare("DELETE FROM scheduled_posts WHERE page_id IN ($in_p)")->execute($page_ids);
                $pdo->prepare("DELETE FROM posts_history WHERE page_id IN ($in_p)")->execute($page_ids);
                $pdo->prepare("DELETE FROM page_shares WHERE page_id IN ($in_p)")->execute($page_ids);
            }

            // 4. Xóa page_shares mà account là người chia sẻ hoặc người nhận
            try {
                $pdo->prepare("DELETE FROM page_shares WHERE owner_account_id = ? OR shared_with_account_id = ?")->execute([$del_id, $del_id]);
            } catch (PDOException $e) {}

            // 5. Xóa post_campaigns thuộc account
            try {
                $pdo->prepare("DELETE FROM post_campaigns WHERE account_id = ?")->execute([$del_id]);
            } catch (PDOException $e) {}

            // 6. Xóa scheduled_posts thuộc account (phòng trường hợp page đã bị xóa trước đó)
            try {
                $pdo->prepare("DELETE FROM scheduled_posts WHERE account_id = ?")->execute([$del_id]);
            } catch (PDOException $e) {}

            // 7. Xóa pages
            if (!empty($user_ids)) {
                $pdo->prepare("DELETE FROM pages WHERE user_id IN ($in_u)")->execute($user_ids);
            }

            // 8. Xóa users
            $pdo->prepare("DELETE FROM users WHERE account_id = ?")->execute([$del_id]);

            // 9. Xóa saved_replies, ai_configs, youtube_channels
            try { $pdo->prepare("DELETE FROM saved_replies WHERE account_id = ?")->execute([$del_id]); } catch (PDOException $e) {}
            try { $pdo->prepare("DELETE FROM ai_configs WHERE account_id = ?")->execute([$del_id]); } catch (PDOException $e) {}
            try { $pdo->prepare("DELETE FROM youtube_channels WHERE account_id = ?")->execute([$del_id]); } catch (PDOException $e) {}

            // 10. Cuối cùng xóa system_account
            $pdo->prepare("DELETE FROM system_accounts WHERE id = ?")->execute([$del_id]);

            $pdo->commit();

            // Clear cache
            $cache_dir = __DIR__ . '/uploads/cache';
            foreach (glob($cache_dir . "/dashboard_user_{$del_id}_*.json") as $cf) { @unlink($cf); }
            foreach (glob($cache_dir . "/dashboard_admin_*.json") as $cf) { @unlink($cf); }

            $alert_type = 'success';
            $alert_message = 'Đã xóa tài khoản và toàn bộ dữ liệu liên quan.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $alert_type = 'danger';
            $alert_message = 'Lỗi khi xóa tài khoản: ' . $e->getMessage();
        }
    } else {
        $alert_type = 'danger';
        $alert_message = 'Bạn không thể tự xóa chính mình.';
    }
}

// Fetch Accounts
$stmt = $pdo->query("
    SELECT sa.*, 
           (SELECT COUNT(*) FROM users u WHERE u.account_id = sa.id) as total_users,
           (SELECT COUNT(*) FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = sa.id) as total_pages,
           (SELECT COUNT(*) FROM youtube_channels yt WHERE yt.account_id = sa.id) as total_youtube,
           (SELECT COUNT(id) FROM scheduled_posts sp WHERE sp.account_id = sa.id AND sp.status = 'published' AND DATE(sp.scheduled_time) = CURDATE()) as posts_today
    FROM system_accounts sa 
    ORDER BY sa.id ASC
");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-title">Quản Lý Tài Khoản (Admin)</div>

<?php if ($alert_message): ?>
    <div class="alert alert-<?php echo $alert_type; ?>"><?php echo htmlspecialchars($alert_message); ?></div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-bottom: 20px;">Tạo Tài Khoản Mới</h3>
    <form method="POST" action="accounts.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-group" style="max-width: 400px; display: inline-block; vertical-align: top; margin-right: 20px;">
            <label>Tên đăng nhập (Username)</label>
            <input type="text" name="username" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" required>
        </div>
        <div class="form-group" style="max-width: 400px; display: inline-block; vertical-align: top; margin-right: 20px;">
            <label>Mật khẩu</label>
            <input type="password" name="password" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" required>
        </div>
        <div style="display: inline-block; vertical-align: top; margin-top: 28px;">
            <button type="submit" class="btn btn-primary">Tạo Account</button>
        </div>
    </form>
</div>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 style="margin: 0;">Danh Sách Tài Khoản</h3>
        <button onclick="runDataCleanup()" class="btn" style="background: var(--red-danger); color: white; display: flex; align-items: center; gap: 5px; font-weight: bold; padding: 8px 15px; border: none; border-radius: 6px; cursor: pointer;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
            Dọn Dẹp Dữ Liệu Rác
        </button>
    </div>
    <table style="min-width:750px;">
        <thead>
            <tr>
                <th>ID</th>
                <th>Tên Đăng Nhập</th>
                <th>Chức Vụ</th>
                <th>Ngày Tạo</th>
                <th>Ngày Hết Hạn</th>
                <th>Công Suất Bài/Ngày</th>
                <th>Tài Săn Đã Nối</th>
                <th>Thao Tác</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $acc): ?>
                <tr>
                    <td><?php echo $acc['id']; ?></td>
                    <td><span style="font-weight: 500; color: var(--primary-color);"><?php echo htmlspecialchars($acc['username']); ?></span></td>
                    <td>
                        <?php if ($acc['role'] === 'admin'): ?>
                            <span class="status-tag" style="background:#fef08a; color:#854d0e;">Admin</span>
                        <?php else: ?>
                            <span class="status-tag" style="background:#e0f2fe; color:#0369a1;">User</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('d/m/Y', strtotime($acc['created_at'])); ?></td>
                    <td>
                        <?php 
                        if ($acc['role'] === 'admin') {
                            echo '<span style="color: #10b981; font-weight: bold;">Vĩnh viễn</span>';
                        } else {
                            if (empty($acc['expire_date'])) {
                                echo '<span style="color: #10b981; font-weight: bold;">Vĩnh viễn</span>';
                            } else {
                                $is_expired = (strtotime($acc['expire_date']) < time());
                                $color = $is_expired ? '#ef4444' : '#10b981';
                                echo '<span style="color: '.$color.'; font-weight: 500;">' . date('d/m/Y H:i', strtotime($acc['expire_date'])) . '</span>';
                            }
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if ($acc['role'] === 'admin') {
                            echo '<span style="color: #6b7280;">&infin;</span>';
                        } else {
                            $posts_today = (int)$acc['posts_today'];
                            $limit = (int)$acc['page_limit'];
                            $color = ($posts_today >= $limit && $limit > 0) ? '#ef4444' : '#374151';
                            echo '<span style="font-weight: bold; color: ' . $color . ';">' . $posts_today . ' / ' . $limit . '</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <span style="font-size: 12px; color: var(--text-muted);">
                            👤 Ních FB: <strong style="color: var(--primary-color);"><?php echo $acc['total_users']; ?></strong><br>
                            📄 Fanpage: <strong style="color: var(--secondary-color);"><?php echo $acc['total_pages']; ?></strong><br>
                            ▶️ YouTube: <strong style="color: #ff0000;"><?php echo $acc['total_youtube']; ?></strong>
                        </span>
                    </td>
                    <td>
                        <?php if ($acc['role'] !== 'admin'): ?>
                            <button onclick="openEditModal(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars($acc['username']); ?>', '<?php echo empty($acc['expire_date']) ? '' : date('Y-m-d\TH:i', strtotime($acc['expire_date'])); ?>', <?php echo (int)$acc['page_limit']; ?>)" style="background: none; border: none; color: var(--primary-color); cursor: pointer; padding: 0; margin-right: 10px; font-size: 13px; text-decoration: underline;">Sửa LH</button>
                        <?php endif; ?>
                        
                        <form method="POST" action="accounts.php" style="display:inline;" onsubmit="return confirm('Reset mật khẩu về 123456?');">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="reset_id" value="<?php echo $acc['id']; ?>">
                            <?php echo csrf_field(); ?>
                            <button type="submit" style="background:none; border:none; color:#f59e0b; cursor:pointer; font-size:13px; padding:0; margin-right:10px; text-decoration:underline;">Reset Pass</button>
                        </form>

                        <?php if ($acc['id'] !== $_SESSION['account_id']): ?>
                            <form method="POST" action="accounts.php" style="display:inline;" onsubmit="return confirm('Xóa tài khoản này?');">
                                <input type="hidden" name="action" value="delete_account">
                                <input type="hidden" name="del_id" value="<?php echo $acc['id']; ?>">
                                <?php echo csrf_field(); ?>
                                <button type="submit" style="background:none; border:none; color:var(--red-danger); cursor:pointer; font-size:13px; padding:0; text-decoration:underline;">Xóa</button>
                            </form>
                        <?php else: ?>
                            <span style="color:#9ca3af; font-size:12px;">Đang ĐN</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal Sửa Giới Hạn -->
<div id="editLimitModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:25px; border-radius:8px; width:100%; max-width:400px; position:relative;">
        <h3 style="margin-top:0;">Sửa Quyền: <span id="e_username_label" style="color:var(--primary-color);"></span></h3>
        <form method="POST" action="accounts.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_limits">
            <input type="hidden" name="edit_id" id="e_id_input">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Ngày Giờ Hết Hạn</label>
                <input type="datetime-local" id="e_expire_input" name="expire_date" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px;">
                <small style="color:#6b7280;">Để trống = Vĩnh viễn (Giống Admin).</small>
            </div>
            
            <div class="form-group" style="margin-bottom: 25px;">
                <label>Giới Hạn Bài Đăng Tối Đa/Ngày</label>
                <input type="number" id="e_limit_input" name="page_limit" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px;" min="0">
                <small style="color:#6b7280;">Nhập số lượng bài đăng tối đa mỗi ngày tài khoản này được phép đăng.</small>
            </div>
            
            <div style="text-align: right;">
                <button type="button" onclick="closeEditModal()" class="btn" style="background:#f3f4f6; color:#374151; margin-right:10px;">Hủy</button>
                <button type="submit" class="btn btn-primary">Lưu Thay Đổi</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, username, expire, limit) {
    document.getElementById('e_id_input').value = id;
    document.getElementById('e_username_label').textContent = username;
    document.getElementById('e_expire_input').value = expire;
    document.getElementById('e_limit_input').value = limit;
    
    document.getElementById('editLimitModal').style.display = 'flex';
}
function closeEditModal() {
    document.getElementById('editLimitModal').style.display = 'none';
}

function runDataCleanup() {
    // Show loading
    const btn = document.querySelector('button[onclick="runDataCleanup()"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="loader" style="width:14px;height:14px;border:2px solid #fff;border-bottom-color:transparent;border-radius:50%;display:inline-block;animation:rotation 1s linear infinite;"></span> Đang dọn dẹp...';
    btn.disabled = true;
    btn.style.opacity = '0.7';

    fetch('actions/cleanup_data.php', {
        method: 'POST'
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            btn.innerHTML = '✅ Hoàn tất dọn dẹp!';
            setTimeout(() => { 
                btn.innerHTML = originalText; 
                btn.disabled = false; 
                btn.style.opacity = '1';
                window.location.reload(); // reload to reflect any potential DB changes visually if needed, optional
            }, 2000);
        } else {
            btn.innerHTML = '❌ Có lỗi xảy ra!';
            setTimeout(() => { 
                btn.innerHTML = originalText; 
                btn.disabled = false; 
                btn.style.opacity = '1';
            }, 3000);
        }
    })
    .catch(err => {
        btn.innerHTML = '❌ Lỗi kết nối';
        setTimeout(() => { 
            btn.innerHTML = originalText; 
            btn.disabled = false; 
            btn.style.opacity = '1';
        }, 3000);
    });
}
</script>
<style>
@keyframes rotation { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<?php include 'includes/footer.php'; ?>
