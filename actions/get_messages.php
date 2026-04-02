<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conv_id = isset($_GET['conv_id']) ? $_GET['conv_id'] : '';
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $page_id = isset($_GET['page_id']) ? $_GET['page_id'] : '';
    
    if (!$conv_id || !$user_id || empty($page_id)) {
        echo json_encode(['status' => 'error', 'msg' => 'Tham số không hợp lệ.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ? AND user_id = ?");
    $stmt->execute([$page_id, $user_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page || empty($page['access_token'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Token Fanpage.']);
        exit;
    }

    $page_access_token = decryptData($page['access_token']);

    $before = isset($_GET['before']) ? $_GET['before'] : '';

    $endpoint = $conv_id . '/messages';
    $params = [
        'fields' => 'id,message,from,created_time',
        'access_token' => $page_access_token,
        'limit' => 30
    ];
    
    if (!empty($before)) {
        $params['before'] = $before;
    }

    $response = fb_api_request($endpoint, $params, 'GET');

    if ($response['status_code'] === 200) {
        $next_cursor = '';
        if (isset($response['data']['paging']['cursors']['before'])) {
            $next_cursor = $response['data']['paging']['cursors']['before'];
        }
        
        echo json_encode([
            'status' => 'success', 
            'data' => $response['data']['data'],
            'next_cursor' => $next_cursor
        ]);
    } else {
        $error_msg = isset($response['data']['error']['message']) ? $response['data']['error']['message'] : 'Lỗi không xác định';
        echo json_encode(['status' => 'error', 'msg' => $error_msg]);
    }
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
}
?>
