<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $page_id = isset($_POST['page_id']) ? $_POST['page_id'] : '';
    // Lấy chuỗi JSON chứa mảng ID hội thoại
    $recipient_ids_json = isset($_POST['recipient_ids']) ? $_POST['recipient_ids'] : '[]';
    $recipient_ids = json_decode($recipient_ids_json, true);

    if (!$user_id || empty($page_id) || empty($recipient_ids) || !is_array($recipient_ids)) {
        echo json_encode(['status' => 'error', 'msg' => 'Thiếu thông tin hoặc định dạng ID không hợp lệ.']);
        exit;
    }

    // Lấy Token Page
    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ? AND user_id = ?");
    $stmt->execute([$page_id, $user_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page || empty($page['access_token'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Token của Fanpage này.']);
        exit;
    }

    $page_access_token = decryptData($page['access_token']);
    $success_count = 0;

    $debug_responses = [];

    foreach ($recipient_ids as $uid) {
        $url = "https://graph.facebook.com/v25.0/me/messages?access_token=" . $page_access_token;
        
        $post_data = [
            'recipient' => ['id' => $uid],
            'sender_action' => 'mark_seen'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response_json = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $debug_responses[] = json_decode($response_json, true) ?: $response_json;

        if ($http_code === 200) {
            $success_count++;
        }
    }

    echo json_encode([
        'status' => 'success', 
        'msg' => "Đã đánh dấu đã đọc $success_count cuộc trò chuyện.",
        'success_count' => $success_count,
        'debug' => $debug_responses
    ]);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
}
?>
