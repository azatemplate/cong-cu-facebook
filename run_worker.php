<?php
// run_worker.php
// Wrapper an toàn nằm ở thư mục Gốc để vượt qua Nginx restriction của aaPanel.
// Nhận vào ?type=publish&page_id=xxx

if (!isset($_GET['type'])) exit;

$type = $_GET['type'];
$page_id = $_GET['page_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';

// Giả lập biến agrv cho CLI để tương thích với source code cũ
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
