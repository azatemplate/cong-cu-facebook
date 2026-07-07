<?php
// test_posts_directly.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

session_start();

// Find a valid user and page
$stmt = $pdo->query("SELECT u.id as user_id, p.page_id, u.account_id FROM pages p JOIN users u ON p.user_id = u.id LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    exit("No pages configured in pages table.\n");
}

echo "Found Page: {$row['page_id']} and User: {$row['user_id']} under Account: {$row['account_id']}\n\n";

$_SESSION['account_id'] = $row['account_id'];

$_GET['page_id'] = $row['page_id'];
$_GET['user_id'] = $row['user_id'];
$_GET['merge_all'] = '0';

echo "=== TESTING actions/get_posts_with_comments.php (merge_all = 0) ===\n";
ob_start();
include __DIR__ . '/actions/get_posts_with_comments.php';
$output = ob_get_clean();
echo "Response:\n$output\n\n";

// Test merge_all = 1
$_GET['merge_all'] = '1';
echo "=== TESTING actions/get_posts_with_comments.php (merge_all = 1) ===\n";
ob_start();
include __DIR__ . '/actions/get_posts_with_comments.php';
$output_merge = ob_get_clean();
echo "Response:\n$output_merge\n\n";

exit;
?>
