<?php
// ─── Kho Data Manager (Replacement for tiktok_search.php) ───────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db.php';

$account_id = $_SESSION['account_id'] ?? 0;

// Auto-ensure tables exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS media_data_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_acc (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS media_data_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            url TEXT NOT NULL,
            url_hash VARCHAR(32) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_group_url (group_id, url_hash),
            INDEX idx_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

// ─── Handle AJAX Requests (Fast Return JSON without outputting HTML) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$account_id) {
        echo json_encode(['success' => false, 'message' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!']);
        exit;
    }

    try {
        $action = $_POST['action'];

        // 1. Tạo Nhóm Data mới
        if ($action === 'create_group') {
            $name = trim($_POST['name'] ?? '');
            $urls_raw = $_POST['urls'] ?? '';

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Vui lòng nhập tên nhóm Data!']);
                exit;
            }

            // Tách danh sách URL theo dòng / khoảng trắng
            $urls = preg_split('/[\r\n\s]+/', $urls_raw, -1, PREG_SPLIT_NO_EMPTY);
            $valid_urls = [];
            foreach ($urls as $u) {
                $u = trim($u);
                if (filter_var($u, FILTER_VALIDATE_URL) || preg_match('/https?:\/\//i', $u)) {
                    $valid_urls[] = $u;
                }
            }

            $total_input = count($valid_urls);
            $unique_urls = array_values(array_unique($valid_urls));
            $input_dedup_count = $total_input - count($unique_urls);

            // Khởi tạo Nhóm Data
            $stmt = $pdo->prepare("INSERT INTO media_data_groups (account_id, name) VALUES (?, ?)");
            $stmt->execute([$account_id, $name]);
            $group_id = $pdo->lastInsertId();

            $saved_count = 0;
            if (!empty($unique_urls)) {
                $stmt_item = $pdo->prepare("INSERT IGNORE INTO media_data_items (group_id, url, url_hash) VALUES (?, ?, ?)");
                foreach ($unique_urls as $u) {
                    $hash = md5($u);
                    $stmt_item->execute([$group_id, $u, $hash]);
                    if ($stmt_item->rowCount() > 0) {
                        $saved_count++;
                    }
                }
            }

            $db_dedup_count = count($unique_urls) - $saved_count;
            $total_dedup = $input_dedup_count + $db_dedup_count;

            $msg = "Đã tạo nhóm '{$name}' thành công! Đã lưu {$saved_count} link";
            if ($total_dedup > 0) {
                $msg .= " (Đã tự động lọc trùng {$total_dedup} link lặp lại).";
            } else {
                $msg .= ".";
            }

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'group_id' => $group_id,
                'saved_count' => $saved_count,
                'dedup_count' => $total_dedup
            ]);
            exit;
        }

        // 2. Thêm link vào nhóm đã có
        if ($action === 'add_items') {
            $group_id = intval($_POST['group_id'] ?? 0);
            $urls_raw = $_POST['urls'] ?? '';

            $stmt = $pdo->prepare("SELECT id, name FROM media_data_groups WHERE id = ? AND account_id = ?");
            $stmt->execute([$group_id, $account_id]);
            $group = $stmt->fetch();
            if (!$group) {
                echo json_encode(['success' => false, 'message' => 'Nhóm Data không tồn tại hoặc không thuộc quyền quản lý.']);
                exit;
            }

            $urls = preg_split('/[\r\n\s]+/', $urls_raw, -1, PREG_SPLIT_NO_EMPTY);
            $valid_urls = [];
            foreach ($urls as $u) {
                $u = trim($u);
                if (filter_var($u, FILTER_VALIDATE_URL) || preg_match('/https?:\/\//i', $u)) {
                    $valid_urls[] = $u;
                }
            }

            $total_input = count($valid_urls);
            $unique_urls = array_values(array_unique($valid_urls));
            $input_dedup_count = $total_input - count($unique_urls);

            $saved_count = 0;
            if (!empty($unique_urls)) {
                $stmt_item = $pdo->prepare("INSERT IGNORE INTO media_data_items (group_id, url, url_hash) VALUES (?, ?, ?)");
                foreach ($unique_urls as $u) {
                    $hash = md5($u);
                    $stmt_item->execute([$group_id, $u, $hash]);
                    if ($stmt_item->rowCount() > 0) {
                        $saved_count++;
                    }
                }
            }

            $db_dedup_count = count($unique_urls) - $saved_count;
            $total_dedup = $input_dedup_count + $db_dedup_count;

            $msg = "Đã thêm {$saved_count} link mới vào nhóm!";
            if ($total_dedup > 0) {
                $msg .= " (Đã lọc bỏ {$total_dedup} link trùng lặp).";
            }

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'saved_count' => $saved_count,
                'dedup_count' => $total_dedup
            ]);
            exit;
        }

        // 3. Đổi tên nhóm
        if ($action === 'rename_group') {
            $group_id = intval($_POST['group_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Vui lòng nhập tên nhóm!']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE media_data_groups SET name = ? WHERE id = ? AND account_id = ?");
            $stmt->execute([$name, $group_id, $account_id]);
            echo json_encode(['success' => true, 'message' => 'Đã cập nhật tên nhóm thành công!']);
            exit;
        }

        // 4. Xóa nhóm
        if ($action === 'delete_group') {
            $group_id = intval($_POST['group_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM media_data_groups WHERE id = ? AND account_id = ?");
            $stmt->execute([$group_id, $account_id]);
            echo json_encode(['success' => true, 'message' => 'Đã xóa nhóm Data thành công!']);
            exit;
        }

        // 5. Xóa link lẻ
        if ($action === 'delete_item') {
            $item_id = intval($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE i FROM media_data_items i JOIN media_data_groups g ON i.group_id = g.id WHERE i.id = ? AND g.account_id = ?");
            $stmt->execute([$item_id, $account_id]);
            echo json_encode(['success' => true, 'message' => 'Đã xóa link thành công!']);
            exit;
        }

        // 6. Xóa tất cả link trong nhóm
        if ($action === 'clear_items') {
            $group_id = intval($_POST['group_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE i FROM media_data_items i JOIN media_data_groups g ON i.group_id = g.id WHERE g.id = ? AND g.account_id = ?");
            $stmt->execute([$group_id, $account_id]);
            echo json_encode(['success' => true, 'message' => 'Đã xóa toàn bộ link trong nhóm!']);
            exit;
        }

        // 7. Lấy danh sách link trong nhóm
        if ($action === 'get_items') {
            $group_id = intval($_POST['group_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT i.id, i.url FROM media_data_items i JOIN media_data_groups g ON i.group_id = g.id WHERE g.id = ? ORDER BY i.id DESC LIMIT 1000");
            $stmt->execute([$group_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'items' => $items]);
            exit;
        }
    } catch (Throwable $ex) {
        echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ DB: ' . $ex->getMessage()]);
        exit;
    }
}

// ─── Render Page Layout ──────────────────────────────────────────────────────
$current_page = 'tiktok_search';
require_once __DIR__ . '/includes/header.php';

// Fetch All Data Groups
$groups = [];
try {
    $stmt = $pdo->prepare("
        SELECT g.id, g.account_id, g.name, g.created_at,
               (SELECT COUNT(*) FROM media_data_items i WHERE i.group_id = g.id) AS total_items
        FROM media_data_groups g
        WHERE g.account_id = ?
        ORDER BY g.id DESC
    ");
    $stmt->execute([$account_id]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $groups = [];
}
?>

<style>
/* ── Design Tokens & Refactored Styling for Kho Data ── */
:root {
    --kd-primary: #4f46e5;
    --kd-primary-hover: #4338ca;
    --kd-primary-glow: rgba(79, 70, 229, 0.15);
    --kd-surface: #ffffff;
    --kd-border: #e2e8f0;
    --kd-text-main: #0f172a;
    --kd-text-muted: #64748b;
    --kd-radius: 16px;
}

.kd-header-banner {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    border-radius: var(--kd-radius);
    padding: 26px 30px;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(30, 27, 75, 0.2);
    position: relative;
    overflow: hidden;
}
.kd-header-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, rgba(99, 102, 241, 0) 70%);
    pointer-events: none;
}
.kd-header-title { display: flex; align-items: center; gap: 16px; }
.kd-icon-badge {
    width: 50px; height: 50px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #a5b4fc; flex-shrink: 0;
}
.kd-header-text h2 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 24px; font-weight: 800;
    margin: 0 0 4px; color: #ffffff;
    letter-spacing: -0.01em;
}
.kd-header-text p { font-size: 13px; color: #cbd5e1; margin: 0; }

.btn-create-group {
    padding: 12px 22px;
    background: linear-gradient(135deg, var(--kd-primary) 0%, var(--kd-primary-hover) 100%);
    color: #ffffff; border: none; border-radius: 12px;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    box-shadow: 0 8px 20px var(--kd-primary-glow);
    transition: all 0.2s ease; font-family: inherit;
}
.btn-create-group:hover { transform: translateY(-1px); box-shadow: 0 12px 25px var(--kd-primary-glow); }

/* Search Toolbar for Data File Names (User Request) */
.kd-toolbar {
    background: var(--kd-surface);
    border: 1px solid var(--kd-border);
    border-radius: var(--kd-radius);
    padding: 18px 24px;
    margin-bottom: 24px;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 16px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
}

.search-box-wrapper { position: relative; flex: 1; min-width: 280px; }
.search-box-wrapper input {
    width: 100%; padding: 12px 16px 12px 42px;
    background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 12px;
    font-size: 14px; font-weight: 500; color: var(--kd-text-main);
    transition: all 0.2s; box-sizing: border-box; font-family: inherit;
}
.search-box-wrapper input:focus {
    outline: none; border-color: var(--kd-primary); background: #ffffff;
    box-shadow: 0 0 0 4px var(--kd-primary-glow);
}
.search-box-wrapper svg {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    width: 18px; height: 18px; color: #94a3b8; pointer-events: none;
}

.kd-group-count-badge {
    background: #eef2ff; color: var(--kd-primary); border: 1px solid #c7d2fe;
    padding: 6px 14px; border-radius: 20px; font-size: 12.5px; font-weight: 700;
}

/* Grid Nhóm Data */
.groups-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 20px;
    margin-bottom: 30px;
}
.group-card {
    background: var(--kd-surface); border: 1px solid var(--kd-border); border-radius: var(--kd-radius);
    padding: 24px; display: flex; flex-direction: column; justify-content: space-between;
    transition: all 0.25s ease; position: relative; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
}
.group-card:hover {
    border-color: #cbd5e1; transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
}
.group-card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
.group-card-title { font-family: 'Be Vietnam Pro', sans-serif; font-size: 16px; font-weight: 800; color: var(--kd-text-main); margin: 0; word-break: break-word; }
.group-badge {
    background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;
    padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; white-space: nowrap;
}
.group-card-meta { font-size: 12px; color: var(--kd-text-muted); margin-bottom: 18px; font-weight: 500; }
.group-card-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.btn-card-action {
    flex: 1; padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;
    border: 1px solid var(--kd-border); background: #f8fafc; color: var(--kd-text-main);
    cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    transition: all 0.2s; white-space: nowrap; font-family: inherit;
}
.btn-card-action:hover { border-color: var(--kd-primary); color: var(--kd-primary); background: #ffffff; }
.btn-card-action.danger { color: #ef4444; border-color: #fecaca; background: #fff1f2; }
.btn-card-action.danger:hover { border-color: #ef4444; color: #ffffff; background: #ef4444; }

/* Glassmorphism Modal Design */
.kd-modal-backdrop {
    position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center; z-index: 9999; padding: 20px;
}
.kd-modal-content {
    background: #ffffff; border-radius: 20px;
    width: 100%; max-width: 660px; max-height: 90vh; display: flex; flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25); animation: modalFadeIn 0.25s ease-out; overflow: hidden;
}
@keyframes modalFadeIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.kd-modal-header {
    padding: 20px 26px; border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; justify-content: space-between;
}
.kd-modal-header h3 { font-family: 'Be Vietnam Pro', sans-serif; font-size: 18px; font-weight: 800; margin: 0; color: var(--kd-text-main); }
.kd-modal-close {
    background: #f1f5f9; border: none; font-size: 16px; font-weight: bold; color: var(--kd-text-muted);
    width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.2s;
}
.kd-modal-close:hover { color: #ef4444; background: #fee2e2; }
.kd-modal-body { padding: 26px; overflow-y: auto; flex: 1; }
.kd-modal-footer {
    padding: 16px 26px; border-top: 1px solid #f1f5f9;
    display: flex; justify-content: flex-end; gap: 12px; background: #f8fafc;
}

.kd-form-group { margin-bottom: 20px; }
.kd-form-group label { display: block; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
.kd-form-group input[type="text"], .kd-form-group textarea {
    width: 100%; padding: 12px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px;
    background: #f8fafc; color: var(--kd-text-main); font-size: 14px; font-weight: 500; font-family: inherit;
    box-sizing: border-box; transition: all 0.2s ease;
}
.kd-form-group input:focus, .kd-form-group textarea:focus {
    outline: none; border-color: var(--kd-primary); background: #ffffff;
    box-shadow: 0 0 0 4px var(--kd-primary-glow);
}

.items-list-wrap { max-height: 400px; overflow-y: auto; border: 1.5px solid #e2e8f0; border-radius: 12px; background: #ffffff; }
.item-row {
    padding: 12px 16px; border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13.5px;
}
.item-row:last-child { border-bottom: none; }
.item-url { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; color: var(--kd-primary); font-weight: 600; text-decoration: none; }
.item-url:hover { text-decoration: underline; }

.empty-groups {
    text-align: center; padding: 60px 20px; background: var(--kd-surface); border: 1.5px dashed var(--kd-border);
    border-radius: 20px; color: var(--kd-text-muted);
}
.empty-groups .icon { font-size: 48px; margin-bottom: 12px; }
</style>

<!-- Banner Header -->
<div class="kd-header-banner">
    <div class="kd-header-title">
        <div class="kd-icon-badge">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="kd-header-text">
            <h2>Kho Data (Quản Lý Danh Sách URL Media)</h2>
            <p>Tạo &amp; quản lý các Nhóm Data chứa URL Video (TikTok, Instagram, YouTube, Facebook...) · Tự động lọc trùng thông minh</p>
        </div>
    </div>
    <button class="btn-create-group" onclick="openCreateModal()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>Tạo Nhóm Data Mới</span>
    </button>
</div>

<!-- Dynamic Alert Container -->
<div id="kd-alert-container"></div>

<!-- Search Toolbar for Data File / Group Names (User Request) -->
<div class="kd-toolbar">
    <div class="search-box-wrapper">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="groupSearchInput" onkeyup="filterGroupList()" placeholder="🔍 Tìm kiếm tên file / nhóm Data...">
    </div>
    <div class="kd-group-count-badge">
        Hiển thị: <strong id="visibleGroupCount"><?php echo count($groups); ?></strong> / <strong><?php echo count($groups); ?></strong> Nhóm Data
    </div>
</div>

<!-- Groups Grid -->
<?php if (empty($groups)): ?>
    <div class="empty-groups">
        <div class="icon">📁</div>
        <h3 style="font-size:18px; font-weight:800; color:#0f172a; margin:0 0 6px;">Chưa có Nhóm Data nào!</h3>
        <p style="margin:0;">Bấm nút <strong>"Tạo Nhóm Data Mới"</strong> phía trên để nhập danh sách URL video tự động lọc trùng.</p>
    </div>
<?php else: ?>
    <div class="groups-grid" id="groupsGridContainer">
        <?php foreach ($groups as $g): ?>
            <div class="group-card" id="group-card-<?php echo $g['id']; ?>" data-group-name="<?php echo htmlspecialchars(mb_strtolower($g['name'] ?? '', 'UTF-8')); ?>">
                <div>
                    <div class="group-card-header">
                        <h3 class="group-card-title"><?php echo htmlspecialchars($g['name']); ?></h3>
                        <span class="group-badge"><?php echo number_format($g['total_items']); ?> link</span>
                    </div>
                    <div class="group-card-meta">
                        📅 Ngày tạo: <?php echo date('d/m/Y H:i', strtotime($g['created_at'])); ?>
                    </div>
                </div>
                <div class="group-card-actions">
                    <button class="btn-card-action" style="background: #be185d; color: #fff; border-color: #be185d; font-weight: 700;" onclick="openAddFastModal(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars(addslashes($g['name'])); ?>')">
                        <span>➕</span> Thêm Data
                    </button>
                    <button class="btn-card-action" onclick="openDetailModal(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars(addslashes($g['name'])); ?>')">
                        <span>👁️</span> Xem / Sửa
                    </button>
                    <button class="btn-card-action" onclick="openRenameModal(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars(addslashes($g['name'])); ?>')">
                        <span>✏️</span> Đổi tên
                    </button>
                    <button class="btn-card-action danger" onclick="deleteGroup(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars(addslashes($g['name'])); ?>')">
                        <span>🗑️</span> Xóa
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div id="noMatchGroupRow" style="display:none;" class="empty-groups">
        <div class="icon">🔍</div>
        <h3 style="font-size:16px; font-weight:800; color:#0f172a; margin:0 0 6px;">Không tìm thấy nhóm Data nào phù hợp</h3>
        <p style="margin:0;">Thử tìm kiếm từ khóa tên file/nhóm Data khác.</p>
    </div>
<?php endif; ?>

<!-- MODAL: Nhanh Thêm Data Vào Nhóm -->
<div class="kd-modal-backdrop" id="modal-add-fast">
    <div class="kd-modal-content">
        <div class="kd-modal-header">
            <h3 id="add-fast-modal-title" style="color: #be185d;">➕ Thêm Data Vào Nhóm</h3>
            <button class="kd-modal-close" onclick="closeModal('modal-add-fast')">✕</button>
        </div>
        <div class="kd-modal-body">
            <input type="hidden" id="add-fast-group-id">
            <div class="kd-form-group">
                <label for="add-fast-urls" style="color: #be185d; font-weight: 700;">Danh Sách Link Video Bổ Sung (Mỗi link một dòng)</label>
                <textarea id="add-fast-urls" rows="9" placeholder="Dán danh sách 1000+ URL video mới bổ sung vào đây (TikTok, Instagram, YouTube, Facebook...).&#10;Hệ thống sẽ tự động lọc trùng bỏ bớt các link đã có sẵn trong nhóm này!"></textarea>
            </div>
            <p style="font-size:12px; color:var(--kd-text-muted); margin:0;">
                💡 <em>Hệ thống sẽ giữ nguyên các link cũ và tự động thêm các link chưa bị trùng vào nhóm.</em>
            </p>
        </div>
        <div class="kd-modal-footer">
            <button class="btn-card-action" onclick="closeModal('modal-add-fast')">Hủy</button>
            <button class="btn-create-group" id="btn-submit-add-fast" onclick="submitAddFast()">
                <span>📥 Bổ Sung Vào Nhóm</span>
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Tạo Nhóm Data -->
<div class="kd-modal-backdrop" id="modal-create">
    <div class="kd-modal-content">
        <div class="kd-modal-header">
            <h3>➕ Tạo Nhóm Data Mới</h3>
            <button class="kd-modal-close" onclick="closeModal('modal-create')">✕</button>
        </div>
        <div class="kd-modal-body">
            <div class="kd-form-group">
                <label for="create-group-name">Tên Nhóm Data / File Data</label>
                <input type="text" id="create-group-name" placeholder="Ví dụ: Nhóm Data 1, Video Hot TikTok, Reels Quần Áo..." autocomplete="off">
            </div>
            <div class="kd-form-group">
                <label for="create-group-urls">Danh Sách Link Video (Mỗi link một dòng)</label>
                <textarea id="create-group-urls" rows="9" placeholder="Dán danh sách 1000+ URL video vào đây (TikTok, Instagram, YouTube, Facebook...).&#10;Hệ thống sẽ tự động lọc trùng bỏ bớt các link lặp lại!"></textarea>
            </div>
            <p style="font-size:12px; color:var(--kd-text-muted); margin:0;">
                💡 <em>Hệ thống hỗ trợ nhận diện link tự động và loại bỏ tất cả các link bị trùng lặp.</em>
            </p>
        </div>
        <div class="kd-modal-footer">
            <button class="btn-card-action" onclick="closeModal('modal-create')">Hủy</button>
            <button class="btn-create-group" id="btn-submit-create" onclick="submitCreateGroup()">
                <span>💾 Lưu Nhóm Data</span>
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Xem / Quản Lý Link Trong Nhóm -->
<div class="kd-modal-backdrop" id="modal-detail">
    <div class="kd-modal-content" style="max-width: 740px;">
        <div class="kd-modal-header">
            <h3 id="detail-modal-title">📁 Chi Tiết Nhóm Data</h3>
            <button class="kd-modal-close" onclick="closeModal('modal-detail')">✕</button>
        </div>
        <div class="kd-modal-body">
            <!-- Form thêm link vào nhóm -->
            <div style="background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 14px; padding: 18px; margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 12px; text-transform:uppercase; color:#334155; margin-bottom: 8px; display: block;">➕ Thêm Link Mới Vào Nhóm Này</label>
                <textarea id="add-more-urls" rows="3" placeholder="Dán các link video mới cần bổ sung vào nhóm... (Tự động lọc trùng với link đã có)" style="width:100%; box-sizing:border-box; margin-bottom: 12px;"></textarea>
                <div style="display: flex; justify-content: flex-end;">
                    <button class="btn-create-group" style="padding: 8px 16px; font-size: 13px;" onclick="submitAddItems()">
                        <span>📥 Thêm Vào Nhóm</span>
                    </button>
                </div>
            </div>

            <!-- List items -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: var(--kd-text-muted);" id="detail-items-count">Danh sách URL (0 link):</span>
                <button class="btn-card-action danger" style="padding: 6px 12px; font-size: 12px; flex: none;" onclick="clearGroupItems()">
                    <span>🗑️ Xóa toàn bộ link</span>
                </button>
            </div>
            <div class="items-list-wrap" id="detail-items-list">
                <div style="text-align:center; padding: 24px; color: var(--kd-text-muted);">Đang tải dữ liệu...</div>
            </div>
        </div>
        <div class="kd-modal-footer">
            <button class="btn-card-action" onclick="closeModal('modal-detail')">Đóng</button>
        </div>
    </div>
</div>

<!-- MODAL: Đổi Tên Nhóm -->
<div class="kd-modal-backdrop" id="modal-rename">
    <div class="kd-modal-content" style="max-width: 460px;">
        <div class="kd-modal-header">
            <h3>✏️ Đổi Tên Nhóm Data</h3>
            <button class="kd-modal-close" onclick="closeModal('modal-rename')">✕</button>
        </div>
        <div class="kd-modal-body">
            <input type="hidden" id="rename-group-id">
            <div class="kd-form-group">
                <label for="rename-group-name">Tên Nhóm Data / File Data Mới</label>
                <input type="text" id="rename-group-name" placeholder="Nhập tên nhóm mới..." autocomplete="off">
            </div>
        </div>
        <div class="kd-modal-footer">
            <button class="btn-card-action" onclick="closeModal('modal-rename')">Hủy</button>
            <button class="btn-create-group" style="padding: 10px 18px; font-size: 13px;" onclick="submitRenameGroup()">Lưu Thay Đổi</button>
        </div>
    </div>
</div>

<script>
let currentDetailGroupId = 0;

function filterGroupList() {
    const input = document.getElementById('groupSearchInput');
    const filter = input ? (input.value || '').trim().toLowerCase() : '';
    const cards = document.querySelectorAll('#groupsGridContainer .group-card');
    let visible = 0;

    cards.forEach(card => {
        const name = (card.getAttribute('data-group-name') || '').toLowerCase();
        if (!filter || name.includes(filter)) {
            card.style.display = 'flex';
            visible++;
        } else {
            card.style.display = 'none';
        }
    });

    const countLabel = document.getElementById('visibleGroupCount');
    if (countLabel) countLabel.textContent = visible;

    const noMatch = document.getElementById('noMatchGroupRow');
    if (noMatch) {
        noMatch.style.display = (visible === 0 && cards.length > 0) ? 'block' : 'none';
    }
}

function notify(msg, type = 'info') {
    if (typeof window.showNotice === 'function') {
        window.showNotice(msg, type);
    } else {
        alert(msg);
    }
}

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function openCreateModal() {
    document.getElementById('create-group-name').value = '';
    document.getElementById('create-group-urls').value = '';
    openModal('modal-create');
}

function openAddFastModal(groupId, groupName) {
    document.getElementById('add-fast-group-id').value = groupId;
    document.getElementById('add-fast-modal-title').textContent = `➕ Thêm Data Vào Nhóm: ${groupName}`;
    document.getElementById('add-fast-urls').value = '';
    openModal('modal-add-fast');
}

function submitAddFast() {
    const groupId = document.getElementById('add-fast-group-id').value;
    const urls = document.getElementById('add-fast-urls').value.trim();

    if (!urls) {
        notify('Vui lòng dán danh sách link cần thêm!', 'warning');
        return;
    }

    const btn = document.getElementById('btn-submit-add-fast');
    btn.disabled = true;
    btn.innerHTML = '⌛ Đang xử lý & lọc trùng...';

    const formData = new FormData();
    formData.append('action', 'add_items');
    formData.append('group_id', groupId);
    formData.append('urls', urls);

    fetch('tiktok_search.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<span>📥 Bổ Sung Vào Nhóm</span>';
        if (res.success) {
            notify(res.message, 'success');
            closeModal('modal-add-fast');
            setTimeout(() => location.reload(), 1200);
        } else {
            notify(res.message, 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<span>📥 Bổ Sung Vào Nhóm</span>';
        notify('Lỗi xử lý phản hồi từ máy chủ!', 'error');
    });
}

function submitCreateGroup() {
    const name = document.getElementById('create-group-name').value.trim();
    const urls = document.getElementById('create-group-urls').value.trim();

    if (!name) {
        notify('Vui lòng nhập tên nhóm Data!', 'warning');
        document.getElementById('create-group-name').focus();
        return;
    }

    const btn = document.getElementById('btn-submit-create');
    btn.disabled = true;
    btn.innerHTML = '⌛ Đang xử lý & lọc trùng...';

    const formData = new FormData();
    formData.append('action', 'create_group');
    formData.append('name', name);
    formData.append('urls', urls);

    fetch('tiktok_search.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<span>💾 Lưu Nhóm Data</span>';
        if (res.success) {
            notify(res.message, 'success');
            closeModal('modal-create');
            setTimeout(() => location.reload(), 1200);
        } else {
            notify(res.message || 'Có lỗi xảy ra.', 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<span>💾 Lưu Nhóm Data</span>';
        notify('Lỗi xử lý phản hồi từ máy chủ!', 'error');
    });
}

function openDetailModal(groupId, groupName) {
    currentDetailGroupId = groupId;
    document.getElementById('detail-modal-title').textContent = `📁 Nhóm: ${groupName}`;
    document.getElementById('add-more-urls').value = '';
    openModal('modal-detail');
    loadGroupItems(groupId);
}

function loadGroupItems(groupId) {
    const wrap = document.getElementById('detail-items-list');
    wrap.innerHTML = '<div style="text-align:center; padding: 24px; color: var(--kd-text-muted);">⌛ Đang tải danh sách link...</div>';

    const formData = new FormData();
    formData.append('action', 'get_items');
    formData.append('group_id', groupId);

    fetch('tiktok_search.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const items = res.items || [];
            document.getElementById('detail-items-count').textContent = `Danh sách URL (${items.length} link):`;
            if (items.length === 0) {
                wrap.innerHTML = '<div style="text-align:center; padding: 30px; color: var(--kd-text-muted);">Nhóm này chưa có URL nào. Hãy dán link vào ô phía trên để thêm.</div>';
                return;
            }

            let html = '';
            items.forEach((item, idx) => {
                html += `<div class="item-row" id="item-row-${item.id}">
                    <span style="color:var(--kd-text-muted); font-size:12px; font-weight:700; min-width:32px;">#${items.length - idx}</span>
                    <a href="${escapeHtml(item.url)}" target="_blank" class="item-url" title="${escapeHtml(item.url)}">${escapeHtml(item.url)}</a>
                    <button class="btn-card-action danger" style="padding: 4px 10px; font-size: 11px; flex: none;" onclick="deleteItem(${item.id})">Xóa</button>
                </div>`;
            });
            wrap.innerHTML = html;
        } else {
            wrap.innerHTML = `<div style="text-align:center; padding: 20px; color: #ef4444;">${res.message}</div>`;
        }
    });
}

function submitAddItems() {
    const urls = document.getElementById('add-more-urls').value.trim();
    if (!urls) {
        notify('Vui lòng dán danh sách link cần thêm!', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'add_items');
    formData.append('group_id', currentDetailGroupId);
    formData.append('urls', urls);

    fetch('tiktok_search.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            notify(res.message, 'success');
            document.getElementById('add-more-urls').value = '';
            loadGroupItems(currentDetailGroupId);
            const cardBadge = document.querySelector(`#group-card-${currentDetailGroupId} .group-badge`);
            if (cardBadge) {
                let currentCount = parseInt(cardBadge.textContent) || 0;
                cardBadge.textContent = `${currentCount + res.saved_count} link`;
            }
        } else {
            notify(res.message, 'error');
        }
    });
}

function deleteItem(itemId) {
    const formData = new FormData();
    formData.append('action', 'delete_item');
    formData.append('item_id', itemId);

    fetch('tiktok_search.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            notify(res.message, 'success');
            const row = document.getElementById(`item-row-${itemId}`);
            if (row) row.remove();
            const countEl = document.getElementById('detail-items-count');
            let m = countEl.textContent.match(/\((\d+)/);
            if (m) {
                let newC = Math.max(0, parseInt(m[1]) - 1);
                countEl.textContent = `Danh sách URL (${newC} link):`;
            }
        } else {
            notify(res.message, 'error');
        }
    });
}

function clearGroupItems() {
    if (!confirm('Bạn có chắc chắn muốn xóa toàn bộ link trong nhóm này?')) return;
    const formData = new FormData();
    formData.append('action', 'clear_items');
    formData.append('group_id', currentDetailGroupId);

    fetch('tiktok_search.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            notify(res.message, 'success');
            loadGroupItems(currentDetailGroupId);
        } else {
            notify(res.message, 'error');
        }
    });
}

function openRenameModal(groupId, currentName) {
    document.getElementById('rename-group-id').value = groupId;
    document.getElementById('rename-group-name').value = currentName;
    openModal('modal-rename');
}

function submitRenameGroup() {
    const groupId = document.getElementById('rename-group-id').value;
    const name = document.getElementById('rename-group-name').value.trim();

    if (!name) { notify('Vui lòng nhập tên nhóm!', 'warning'); return; }

    const formData = new FormData();
    formData.append('action', 'rename_group');
    formData.append('group_id', groupId);
    formData.append('name', name);

    fetch('tiktok_search.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            notify(res.message, 'success');
            closeModal('modal-rename');
            setTimeout(() => location.reload(), 1200);
        } else {
            notify(res.message, 'error');
        }
    });
}

function deleteGroup(groupId, groupName) {
    if (!confirm(`Bạn có chắc chắn muốn xóa nhóm Data "${groupName}"?`)) return;
    const formData = new FormData();
    formData.append('action', 'delete_group');
    formData.append('group_id', groupId);

    fetch('tiktok_search.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const card = document.getElementById(`group-card-${groupId}`);
            if (card) card.remove();
            notify(res.message, 'success');
            filterGroupList();
        } else {
            notify(res.message, 'error');
        }
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal('modal-add-fast');
        closeModal('modal-create');
        closeModal('modal-detail');
        closeModal('modal-rename');
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
