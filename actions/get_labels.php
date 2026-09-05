<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

$conv_id      = trim($_GET['conv_id'] ?? '');
$page_id      = trim($_GET['page_id'] ?? '');
$recipient_id = trim($_GET['recipient_id'] ?? '');

if ((!$conv_id && !$recipient_id) || !$page_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu conv_id hoặc page_id']);
    exit;
}

try {
    // 1. Lấy danh sách nhãn ĐANG SỐNG từ Meta Graph API của Fanpage
    $active_label_names = [];
    $stmt_token = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt_token->execute([$page_id]);
    $raw_token = $stmt_token->fetchColumn();

    if ($raw_token) {
        $access_token = decryptData($raw_token);
        if ($access_token) {
            $meta_res = fb_api_request("{$page_id}/custom_labels", [
                'fields' => 'id,name,page_label_name',
                'limit' => 100,
                'access_token' => $access_token
            ], 'GET');

            if (!empty($meta_res['data']['data']) && is_array($meta_res['data']['data'])) {
                foreach ($meta_res['data']['data'] as $lbl) {
                    $name = trim($lbl['page_label_name'] ?? ($lbl['name'] ?? ''));
                    if ($name !== '' && strpos($name, 'ad_id.') !== 0) {
                        $active_label_names[] = mb_strtolower($name);
                    }
                }
            }
        }
    }

    // 2. Lấy các nhãn đã gắn cho khách hàng từ Database
    $stmt = $pdo->prepare("SELECT label_name, created_at FROM conversation_labels WHERE (conv_id = ? OR (recipient_id = ? AND recipient_id IS NOT NULL AND recipient_id != '')) AND page_id = ? ORDER BY created_at ASC");
    $stmt->execute([$conv_id, $recipient_id, $page_id]);
    $raw_labels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filtered_labels = [];
    $seen_names = [];

    foreach ($raw_labels as $lbl) {
        $lname = trim($lbl['label_name'] ?? '');
        if ($lname === '') continue;

        $lname_lower = mb_strtolower($lname);
        if (in_array($lname_lower, $seen_names)) continue;

        // Nếu Meta API trả về danh sách nhãn sống, lọc bỏ các nhãn đã bị xóa trên Fanpage
        if (!empty($active_label_names)) {
            if (!in_array($lname_lower, $active_label_names)) {
                // Tự động dọn dẹp bản ghi nhãn rác đã bị xóa trên Facebook khỏi DB local
                try {
                    $del_stmt = $pdo->prepare("DELETE FROM conversation_labels WHERE page_id = ? AND LOWER(label_name) = ?");
                    $del_stmt->execute([$page_id, $lname_lower]);
                } catch (Exception $e) {}
                continue;
            }
        }

        $seen_names[] = $lname_lower;
        $filtered_labels[] = $lbl;
    }

    echo json_encode(['status' => 'success', 'data' => array_values($filtered_labels)]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi truy vấn dữ liệu.']);
}
?>
