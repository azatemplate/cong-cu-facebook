<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// require_admin(); // Wait, allow any user to subscribe their own pages if they want, but usually it's admin. Let's just check auth.
if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['account_id'];

// Get all pages for this account
$stmt = $pdo->prepare("
    SELECT p.page_id, p.name, p.access_token 
    FROM pages p 
    JOIN users u ON p.user_id = u.id 
    WHERE u.account_id = ? AND p.access_token IS NOT NULL
");
$stmt->execute([$account_id]);
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pages)) {
    echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Page nào cần cấu hình.']);
    exit;
}

$results = [];
$success_count = 0;

foreach ($pages as $p) {
    $token = decryptData($p['access_token']);
    if (!$token) {
        $results[] = "{$p['name']}: Lỗi giải mã Token";
        continue;
    }
    
    // Subscribe the Page to the App for Webhooks
    $endpoint = "{$p['page_id']}/subscribed_apps";
    $params = [
        'access_token' => $token
    ];
    $post_data = [
        'subscribed_fields' => 'messages,feed'
    ];
    
    $response = fb_api_request($endpoint, $params, 'POST', $post_data);
    
    if (isset($response['data']['success']) && $response['data']['success'] == true) {
        $results[] = "✅ {$p['name']}: Cài đặt thành công";
        $success_count++;
    } else {
        $err = $response['data']['error']['message'] ?? 'Lỗi không xác định API';
        $results[] = "❌ {$p['name']}: Thất bại - " . $err;
    }
}

echo json_encode([
    'status' => 'success',
    'msg' => "Đã kích hoạt Webhook cho $success_count / " . count($pages) . " Pages.",
    'details' => $results
]);
