<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
    exit;
}

$user_id      = intval($_POST['user_id'] ?? 0);
$page_id      = trim($_POST['page_id'] ?? '');
$recipient_id = trim($_POST['recipient_id'] ?? '');
$conv_id      = trim($_POST['conv_id'] ?? '');
$tag_name     = trim($_POST['tag_name'] ?? '');

if (!$user_id || !$page_id || !$recipient_id || !$tag_name) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu thông tin']);
    exit;
}

// ── Step 1: Save to local DB (primary storage — never lost after F5) ──────
try {
    $ins = $pdo->prepare("
        INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name)
        VALUES (?, ?, ?, ?)
    ");
    $ins->execute([$conv_id, $page_id, $recipient_id, $tag_name]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi lưu nhãn: ' . $e->getMessage()]);
    exit;
}

// ── Step 2: Also try to sync to FB Custom Labels API (best-effort) ────────
$stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ? AND user_id = ?");
$stmt->execute([$page_id, $user_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if ($page && !empty($page['access_token'])) {
    $token = decryptData($page['access_token']);

    // Find existing label
    $res1 = fb_api_request($page_id . '/custom_labels', ['fields' => 'name', 'access_token' => $token], 'GET');
    $label_id = null;
    if (isset($res1['data']['data']) && is_array($res1['data']['data'])) {
        foreach ($res1['data']['data'] as $label) {
            if (mb_strtolower($label['name']) === mb_strtolower($tag_name)) {
                $label_id = $label['id'];
                break;
            }
        }
    }

    // Create if not exists
    if (!$label_id) {
        $res2 = fb_api_request($page_id . '/custom_labels', ['page_label_name' => $tag_name, 'access_token' => $token], 'POST');
        $label_id = $res2['data']['id'] ?? null;
    }

    // Assign to PSID (ignore errors — local DB is the source of truth)
    if ($label_id) {
        fb_api_request($label_id . '/label', ['user' => $recipient_id, 'access_token' => $token], 'POST');
    }
}

// ── Return success based on DB save (not FB API result) ───────────────────
echo json_encode(['status' => 'success', 'msg' => 'Gắn thẻ thành công']);
?>
