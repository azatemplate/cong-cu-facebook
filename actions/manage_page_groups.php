<?php
// actions/manage_page_groups.php
error_reporting(0);
ini_set('display_errors', 0);
if (ob_get_level()) ob_end_clean();
ob_start();
session_start();

if (!isset($_SESSION['account_id'])) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$account_id = $_SESSION['account_id'];
$action     = $_POST['action'] ?? ($_GET['action'] ?? '');

// Ensure page_groups and page_group_items tables exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS page_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_acc (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS page_group_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            page_id VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_group_page (group_id, page_id),
            INDEX idx_group (group_id),
            INDEX idx_page (page_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

try {
    // 1. Lấy danh sách nhóm + số lượng Fanpage + danh sách page_ids
    if ($action === 'get_groups') {
        $stmt = $pdo->prepare("
            SELECT g.id, g.name, g.created_at,
                   COUNT(i.page_id) AS total_pages
            FROM page_groups g
            LEFT JOIN page_group_items i ON g.id = i.group_id
            WHERE g.account_id = ?
            GROUP BY g.id
            ORDER BY g.name ASC
        ");
        $stmt->execute([$account_id]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch page_ids mapping
        $stmt_items = $pdo->prepare("
            SELECT i.group_id, i.page_id
            FROM page_group_items i
            JOIN page_groups g ON i.group_id = g.id
            WHERE g.account_id = ?
        ");
        $stmt_items->execute([$account_id]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $group_items_map = [];
        foreach ($items as $item) {
            $gid = $item['group_id'];
            if (!isset($group_items_map[$gid])) $group_items_map[$gid] = [];
            $group_items_map[$gid][] = $item['page_id'];
        }

        foreach ($groups as &$g) {
            $g['page_ids'] = $group_items_map[$g['id']] ?? [];
        }
        unset($g);

        echo json_encode(['status' => 'success', 'groups' => $groups]);
        exit;
    }

    // 2. Tạo Nhóm Fanpage Mới
    if ($action === 'create_group') {
        $name = trim($_POST['name'] ?? '');
        $page_ids_raw = $_POST['page_ids'] ?? [];
        $page_ids = is_array($page_ids_raw) ? $page_ids_raw : (json_decode($page_ids_raw, true) ?: []);

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập tên Nhóm Fanpage!']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO page_groups (account_id, name) VALUES (?, ?)");
        $stmt->execute([$account_id, $name]);
        $group_id = $pdo->lastInsertId();

        $added_count = 0;
        if (!empty($page_ids)) {
            $stmt_item = $pdo->prepare("INSERT IGNORE INTO page_group_items (group_id, page_id) VALUES (?, ?)");
            foreach ($page_ids as $pid) {
                $pid = trim($pid);
                if (!empty($pid)) {
                    $stmt_item->execute([$group_id, $pid]);
                    if ($stmt_item->rowCount() > 0) $added_count++;
                }
            }
        }

        echo json_encode([
            'status' => 'success',
            'msg' => "Đã tạo nhóm '{$name}' thành công! (Đã thêm {$added_count} Fanpage vào nhóm)",
            'group_id' => $group_id
        ]);
        exit;
    }

    // 3. Đổi tên hoặc Cập nhật Nhóm
    if ($action === 'update_group') {
        $group_id = intval($_POST['group_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $page_ids_raw = $_POST['page_ids'] ?? null;

        $stmt = $pdo->prepare("SELECT id FROM page_groups WHERE id = ? AND account_id = ?");
        $stmt->execute([$group_id, $account_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['status' => 'error', 'msg' => 'Nhóm Fanpage không tồn tại hoặc không thuộc quyền quản lý.']);
            exit;
        }

        if (!empty($name)) {
            $stmt_u = $pdo->prepare("UPDATE page_groups SET name = ? WHERE id = ?");
            $stmt_u->execute([$name, $group_id]);
        }

        if ($page_ids_raw !== null) {
            $page_ids = is_array($page_ids_raw) ? $page_ids_raw : (json_decode($page_ids_raw, true) ?: []);
            // Clear and replace members
            $stmt_del = $pdo->prepare("DELETE FROM page_group_items WHERE group_id = ?");
            $stmt_del->execute([$group_id]);

            if (!empty($page_ids)) {
                $stmt_ins = $pdo->prepare("INSERT IGNORE INTO page_group_items (group_id, page_id) VALUES (?, ?)");
                foreach ($page_ids as $pid) {
                    $pid = trim($pid);
                    if (!empty($pid)) {
                        $stmt_ins->execute([$group_id, $pid]);
                    }
                }
            }
        }

        echo json_encode(['status' => 'success', 'msg' => 'Cập nhật Nhóm Fanpage thành công!']);
        exit;
    }

    // 4. Xóa Nhóm Fanpage
    if ($action === 'delete_group') {
        $group_id = intval($_POST['group_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM page_groups WHERE id = ? AND account_id = ?");
        $stmt->execute([$group_id, $account_id]);
        echo json_encode(['status' => 'success', 'msg' => 'Đã xóa Nhóm Fanpage thành công!']);
        exit;
    }

    // 5. Thêm Fanpage đã chọn vào nhóm đã có
    if ($action === 'add_pages_to_group') {
        $group_id = intval($_POST['group_id'] ?? 0);
        $page_ids_raw = $_POST['page_ids'] ?? [];
        $page_ids = is_array($page_ids_raw) ? $page_ids_raw : (json_decode($page_ids_raw, true) ?: []);

        $stmt = $pdo->prepare("SELECT id, name FROM page_groups WHERE id = ? AND account_id = ?");
        $stmt->execute([$group_id, $account_id]);
        $group = $stmt->fetch();
        if (!$group) {
            echo json_encode(['status' => 'error', 'msg' => 'Nhóm Fanpage không tồn tại.']);
            exit;
        }

        $added_count = 0;
        if (!empty($page_ids)) {
            $stmt_item = $pdo->prepare("INSERT IGNORE INTO page_group_items (group_id, page_id) VALUES (?, ?)");
            foreach ($page_ids as $pid) {
                $pid = trim($pid);
                if (!empty($pid)) {
                    $stmt_item->execute([$group_id, $pid]);
                    if ($stmt_item->rowCount() > 0) $added_count++;
                }
            }
        }

        echo json_encode(['status' => 'success', 'msg' => "Đã thêm {$added_count} Fanpage vào nhóm '{$group['name']}' thành công!"]);
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Hành động không hợp lệ.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi CSDL: ' . $e->getMessage()]);
}
?>
