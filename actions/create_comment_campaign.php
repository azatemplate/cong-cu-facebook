<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Chỉ chấp nhận phương thức POST']);
    exit;
}

$account_id = (int)$_SESSION['account_id'];

$raw_posts = $_POST['post_ids'] ?? '[]';
$post_ids = is_array($raw_posts) ? $raw_posts : json_decode($raw_posts, true);
$comment_lines = trim($_POST['comment_lines'] ?? '');
$delay_minutes = (int)($_POST['delay_minutes'] ?? 5);
$start_time_input = trim($_POST['start_time'] ?? '');

if (empty($post_ids) || !is_array($post_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 bài viết để tạo chiến dịch']);
    exit;
}

if (empty($comment_lines)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập nội dung bình luận']);
    exit;
}

if ($delay_minutes < 0) $delay_minutes = 0;

session_write_close();

try {
    // Determine base start time
    $base_time = time();
    if (!empty($start_time_input)) {
        $parsed_start = strtotime($start_time_input);
        if ($parsed_start && $parsed_start >= time()) {
            $base_time = $parsed_start;
        }
    }

    // Fetch page_id for selected posts from fetched_fanpage_posts
    $in_clause = implode(',', array_fill(0, count($post_ids), '?'));
    $stmt_posts = $pdo->prepare("
        SELECT fb_post_id, page_id 
        FROM fetched_fanpage_posts 
        WHERE account_id = ? AND fb_post_id IN ($in_clause)
    ");
    $stmt_posts->execute(array_merge([$account_id], $post_ids));
    $found_posts = $stmt_posts->fetchAll(PDO::FETCH_ASSOC);

    if (empty($found_posts)) {
        echo json_encode(['status' => 'error', 'msg' => 'Dữ liệu bài viết không hợp lệ hoặc đã bị xóa']);
        exit;
    }

    $stmt_ins = $pdo->prepare("
        INSERT INTO scheduled_posts 
        (account_id, page_id, fb_post_id, post_type, status, comment_lines, comment_at, comment_done, created_at)
        VALUES 
        (?, ?, ?, 'text', 'published', ?, ?, 0, NOW())
    ");

    $created_count = 0;
    $delay_seconds = $delay_minutes * 60;

    foreach ($found_posts as $idx => $p) {
        $comment_at_ts = $base_time + ($idx * $delay_seconds);
        $comment_at = date('Y-m-d H:i:s', $comment_at_ts);

        $stmt_ins->execute([
            $account_id,
            $p['page_id'],
            $p['fb_post_id'],
            $comment_lines,
            $comment_at
        ]);
        $created_count++;
    }

    echo json_encode([
        'status' => 'success',
        'msg'    => "Đã khởi tạo thành công chiến dịch seeding cho {$created_count} bài viết! Tiến trình Cron sẽ tự động kích hoạt và đăng bình luận."
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi tạo chiến dịch: ' . $e->getMessage()]);
}
?>
