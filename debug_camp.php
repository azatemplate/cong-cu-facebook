<?php
// debug_camp.php
require_once __DIR__ . '/includes/db.php';

$campaign_id = intval($_GET['id'] ?? 1722);
header('Content-Type: text/plain; charset=utf-8');

echo "=== CAMPAIGN $campaign_id POSTS ===\n";
$stmt = $pdo->prepare("SELECT id, page_id, post_type, media_path, status, scheduled_time, error_msg, fb_post_id FROM scheduled_posts WHERE campaign_id = ? ORDER BY id DESC LIMIT 20");
$stmt->execute([$campaign_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No posts found for Campaign ID $campaign_id.\n";
} else {
    foreach ($rows as $row) {
        echo "ID: {$row['id']} | Page: {$row['page_id']} | Type: {$row['post_type']} | Media: {$row['media_path']} | Status: {$row['status']} | Time: {$row['scheduled_time']} | FB Post: {$row['fb_post_id']} | Error: {$row['error_msg']}\n";
    }
}

echo "\n=== LATEST 20 POSTED FOLDER FILES ===\n";
$stmt = $pdo->query("SELECT * FROM posted_folder_files ORDER BY id DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo "ID: {$row['id']} | Folder: {$row['folder_id']} | File: {$row['file_id']} | Posted At: {$row['posted_at']}\n";
}

echo "\n=== TESTING PDO DUPLICATE KEY EXCEPTION CODE ===\n";
try {
    // Attempt duplicate insert to see exact code returned by this system's PDO/MariaDB
    $pdo->exec("INSERT INTO posted_folder_files (folder_id, file_id) VALUES ('test_folder_123', 'test_file_456')");
    echo "First insert succeeded.\n";
    
    // Duplicate insert
    $pdo->exec("INSERT INTO posted_folder_files (folder_id, file_id) VALUES ('test_folder_123', 'test_file_456')");
    echo "Second insert succeeded? (No unique key working!)\n";
} catch (PDOException $e) {
    echo "Caught PDOException!\n";
    echo " - getCode(): " . var_export($e->getCode(), true) . "\n";
    echo " - errorInfo: " . var_export($e->errorInfo, true) . "\n";
} finally {
    // Cleanup test data
    $pdo->exec("DELETE FROM posted_folder_files WHERE folder_id = 'test_folder_123'");
}

echo "\n=== TOTAL FILES IN DRIVE FOR LATEST POSTED FOLDER ===\n";
if (!empty($rows)) {
    $latest_folder = $rows[0]['folder_id'];
    require_once __DIR__ . '/includes/drive_utils.php';
    
    // Try to get token for one of the pages
    $stmt_page = $pdo->query("SELECT page_id, account_id FROM scheduled_posts WHERE campaign_id = $campaign_id LIMIT 1");
    $p_info = $stmt_page->fetch(PDO::FETCH_ASSOC);
    if ($p_info) {
        $token = get_drive_access_token($pdo, $p_info['account_id'], $p_info['page_id']);
        if ($token) {
            $files = list_drive_files_in_folder($token, $latest_folder);
            if ($files === false) {
                echo "Could not list files for folder $latest_folder (API error).\n";
            } else {
                echo "Folder ID: $latest_folder | Total files found: " . count($files) . "\n";
                foreach (array_slice($files, 0, 10) as $f) {
                    echo " - ID: {$f['id']} | Name: {$f['name']} | Mime: {$f['mimeType']}\n";
                }
            }
        } else {
            echo "Could not get drive access token.\n";
        }
    }
}
