<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// ── Auth Guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
    exit;
}

$conv_id      = trim($_POST['conv_id'] ?? '');
$page_id      = trim($_POST['page_id'] ?? '');
$recipient_id = trim($_POST['recipient_id'] ?? '');
$label_name   = trim($_POST['label_name'] ?? '');

if ((!$conv_id && !$recipient_id) || !$page_id || !$label_name) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu thông tin']);
    exit;
}

try {
    if ($conv_id) {
        $stmt = $pdo->prepare("DELETE FROM conversation_labels WHERE conv_id = ? AND page_id = ? AND label_name = ?");
        $stmt->execute([$conv_id, $page_id, $label_name]);
    }
    if ($recipient_id) {
        $stmt_r = $pdo->prepare("DELETE FROM conversation_labels WHERE recipient_id = ? AND page_id = ? AND label_name = ?");
        $stmt_r->execute([$recipient_id, $page_id, $label_name]);
    }
} catch (PDOException $e) {}

// Step 2: Also try to delete from FB Custom Labels API
if (!empty($page_id) && !empty($recipient_id) && !empty($label_name)) {
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
    $stmt->execute([$page_id]);
    $token_raw = $stmt->fetchColumn();

    if ($token_raw) {
        require_once __DIR__ . '/../includes/fb_api.php';
        $token = decryptData($token_raw);
        if ($token) {
            $res1 = fb_api_request("{$page_id}/custom_labels", ['fields' => 'id,name,page_label_name', 'access_token' => $token], 'GET');
            $label_id = null;
            if (isset($res1['data']['data']) && is_array($res1['data']['data'])) {
                foreach ($res1['data']['data'] as $lbl) {
                    $lname = $lbl['page_label_name'] ?? ($lbl['name'] ?? '');
                    if (strcasecmp($lname, $label_name) === 0) {
                        $label_id = $lbl['id'];
                        break;
                    }
                }
            }
            if ($label_id) {
                fb_api_request("{$label_id}/label", ['user' => $recipient_id, 'access_token' => $token], 'DELETE');
            }
        }
    }
}

echo json_encode(['status' => 'success']);
?>
