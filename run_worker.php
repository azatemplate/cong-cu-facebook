<?php
// run_worker.php
// Wrapper an toàn nằm ở thư mục Gốc để vượt qua Nginx restriction của aaPanel.
// Nhận vào ?type=publish&page_id=xxx

if (!isset($_GET['type'])) exit;

// Đảm bảo tiến trình chạy độc lập ngầm không bị ngắt khi cURL timeout
ignore_user_abort(true);
set_time_limit(0);

// Nếu chạy qua Web/FPM, ngắt kết nối HTTP ngay lập tức để Nginx không kill process
if (function_exists('fastcgi_finish_request')) {
    echo "OK";
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    @fastcgi_finish_request();
} else {
    ob_start();
    echo "OK";
    $size = ob_get_length();
    header("Content-Length: $size");
    header("Connection: close");
    @ob_end_flush();
    @ob_flush();
    @flush();
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
}

$type = $_GET['type'];
$page_id = $_GET['page_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';

// Giả lập biến argv cho CLI để tương thích với source code cũ
global $argv, $argc;
$argv = [
    __FILE__,
    $page_id,
    $user_id
];
$argc = 3;

if ($type === 'publish') {
    require_once __DIR__ . '/cron/publish_worker.php';
} elseif ($type === 'comment') {
    require_once __DIR__ . '/cron/comment_worker.php';
}
