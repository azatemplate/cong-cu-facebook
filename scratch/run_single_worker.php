<?php
// scratch/run_single_worker.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

echo "--- RUNNING SINGLE WORKER SYNC DEBUG ---\n";

// Find one failed post from today that is of TikTok type
try {
    $stmt = $pdo->query("
        SELECT id, page_id, account_id, post_type, content, media_path, status, error_msg, scheduled_time, campaign_id 
        FROM scheduled_posts 
        WHERE media_path LIKE 'tiktok:%' 
          AND (status = 'failed' OR status = 'pending')
        ORDER BY scheduled_time DESC 
        LIMIT 1
    ");
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Database error finding post: " . $e->getMessage() . "\n");
}

if (!$post) {
    die("No failed or pending TikTok posts found in scheduled_posts to test.\n");
}

echo "Found Post ID: {$post['id']}\n";
echo "- Status: {$post['status']}\n";
echo "- Error: {$post['error_msg']}\n";
echo "- Scheduled Time: {$post['scheduled_time']}\n";
echo "- Media Path: {$post['media_path']}\n";
echo "- Page ID: {$post['page_id']}\n";
echo "- Account ID: {$post['account_id']}\n\n";

// Reset post to processing so it's fresh
$pdo->prepare("UPDATE scheduled_posts SET status = 'processing', error_msg = NULL, retry_count = 0 WHERE id = ?")->execute([$post['id']]);
echo "Reset post status to 'processing' in DB. Starting execution...\n\n";

// Mock variables that publish_worker.php expects
$argv = [
    'publish_worker.php',
    $post['page_id'],
    'yt_' . $post['account_id'] // to simulate the lock
];
$argc = 3;

// We will execute the publish_worker logic inline for this post by including it,
// but wait! publish_worker.php queries posts from the DB.
// Since we set the post status to 'processing', it won't be fetched if it queries status IN ('pending', 'failed').
// Let's change the post status back to 'pending' so publish_worker.php fetches it, 
// and we will run publish_worker.php by passing its page_id as parameter!

$pdo->prepare("UPDATE scheduled_posts SET status = 'pending', error_msg = NULL, retry_count = 0 WHERE id = ?")->execute([$post['id']]);

echo "Executing publish_worker.php for Page ID: {$post['page_id']}...\n";
echo "--------------------------------------------------\n";

// Include publish_worker.php
// We define a global override to only run the specific post ID to avoid running other posts of the same page
$GLOBALS['_debug_single_post_id'] = $post['id'];

// Modify publish_worker.php query dynamically or let it run normally?
// Let's modify the query in publish_worker.php if we want to run only this post,
// but actually, if there are other pending posts, it will run them too.
// That's fine, we want to see the real run.

// Let's run it by calling CLI php on publish_worker.php to see the output
$php_bin = 'php';
if (file_exists('/www/server/php/81/bin/php')) {
    $php_bin = '/www/server/php/81/bin/php';
} elseif (file_exists('/www/server/php/82/bin/php')) {
    $php_bin = '/www/server/php/82/bin/php';
}

$cmd = "$php_bin " . __DIR__ . "/../cron/publish_worker.php \"{$post['page_id']}\" \"debug_run\"";
echo "Running Command: $cmd\n\n";

$output = shell_exec($cmd);
echo $output;

echo "\n--------------------------------------------------\n";
echo "Execution finished. Checking final DB state for Post ID {$post['id']}:\n";

$check = $pdo->prepare("SELECT status, error_msg, retry_count FROM scheduled_posts WHERE id = ?");
$check->execute([$post['id']]);
$final = $check->fetch(PDO::FETCH_ASSOC);

echo "- Final Status: {$final['status']}\n";
echo "- Final Error Msg: {$final['error_msg']}\n";
echo "- Final Retry Count: {$final['retry_count']}\n";
