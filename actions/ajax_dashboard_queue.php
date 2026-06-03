<?php
// actions/ajax_dashboard_queue.php
session_start();
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

$account_id = $_SESSION['account_id'];
session_write_close();

$response = [
    'recent' => [],
    'upcoming' => []
];

try {
    // 1. Fetch 20 most recent posts (published or failed)
    $stmt_recent = $pdo->prepare("
        SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.status, sp.error_msg, sp.fb_post_id,
               CASE 
                   WHEN sp.post_type = 'YouTube' THEN COALESCE(yt.channel_title, 'Kênh YouTube/Không rõ')
                   ELSE COALESCE(p.name, 'Không rõ')
               END as page_name
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id AND sp.post_type != 'YouTube'
        LEFT JOIN youtube_channels yt ON sp.page_id = yt.id AND sp.post_type = 'YouTube'
        WHERE sp.account_id = ? AND sp.status IN ('published', 'failed')
        ORDER BY sp.scheduled_time DESC
        LIMIT 20
    ");
    $stmt_recent->execute([$account_id]);
    $recent_posts = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($recent_posts as $post) {
        $response['recent'][] = [
            'id' => $post['id'],
            'page_name' => htmlspecialchars($post['page_name']),
            'post_type' => $post['post_type'],
            'scheduled_time' => $post['scheduled_time'],
            'status' => $post['status'],
            'error_msg' => htmlspecialchars($post['error_msg'] ?? ''),
            'fb_post_id' => $post['fb_post_id']
        ];
    }
    
    // 2. Fetch 20 upcoming posts (pending or processing)
    $stmt_upcoming = $pdo->prepare("
        SELECT sp.id, sp.page_id, sp.post_type, sp.scheduled_time, sp.status,
               CASE 
                   WHEN sp.post_type = 'YouTube' THEN COALESCE(yt.channel_title, 'Kênh YouTube/Không rõ')
                   ELSE COALESCE(p.name, 'Không rõ')
               END as page_name,
               TIMESTAMPDIFF(SECOND, NOW(), sp.scheduled_time) as seconds_left
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id AND sp.post_type != 'YouTube'
        LEFT JOIN youtube_channels yt ON sp.page_id = yt.id AND sp.post_type = 'YouTube'
        WHERE sp.account_id = ? AND sp.status IN ('pending', 'processing')
        ORDER BY sp.scheduled_time ASC
        LIMIT 20
    ");
    $stmt_upcoming->execute([$account_id]);
    $upcoming_posts = $stmt_upcoming->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($upcoming_posts as $post) {
        $response['upcoming'][] = [
            'id' => $post['id'],
            'page_name' => htmlspecialchars($post['page_name']),
            'post_type' => $post['post_type'],
            'scheduled_time' => $post['scheduled_time'],
            'status' => $post['status'],
            'seconds_left' => max(0, (int)$post['seconds_left'])
        ];
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

echo json_encode($response);
