<?php
// add_all_missing_indexes.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

echo "=== ADDING ESSENTIAL DATABASE PERFORMANCE INDEXES ===\n\n";

function add_idx($pdo, $table, $idx_name, $cols) {
    try {
        echo "Adding index $idx_name ($cols) to $table...\n";
        $pdo->exec("ALTER TABLE {$table} ADD INDEX {$idx_name} ({$cols})");
        echo "[SUCCESS] Index $idx_name added to $table.\n\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "[EXISTED] Index $idx_name already exists on $table.\n\n";
        } else {
            echo "[ERROR] $table.$idx_name: " . $e->getMessage() . "\n\n";
        }
    }
}

// 1. Scheduled Posts Critical Queries
add_idx($pdo, 'scheduled_posts', 'idx_sp_status_time', 'status, scheduled_time');
add_idx($pdo, 'scheduled_posts', 'idx_sp_acc_status_time', 'account_id, status, scheduled_time');
add_idx($pdo, 'scheduled_posts', 'idx_sp_camp_status', 'campaign_id, status');
add_idx($pdo, 'scheduled_posts', 'idx_sp_page_status', 'page_id, status');
add_idx($pdo, 'scheduled_posts', 'idx_sp_cmt_status', 'status, comment_status');

// 2. Notifications Queries
add_idx($pdo, 'page_notifications', 'idx_pn_page_created', 'page_id, created_at');
add_idx($pdo, 'page_notifications', 'idx_pn_page_read_created', 'page_id, is_read, created_at');

// 3. User & Page Relationship Queries
add_idx($pdo, 'users', 'idx_u_account_id', 'account_id');
add_idx($pdo, 'pages', 'idx_p_user_id', 'user_id');
add_idx($pdo, 'pages', 'idx_p_page_id', 'page_id');
add_idx($pdo, 'instagram_accounts', 'idx_ig_acc_id', 'account_id');
add_idx($pdo, 'buffer_channels', 'idx_bc_acc_id', 'account_id');
add_idx($pdo, 'youtube_channels', 'idx_yt_acc_id', 'account_id');

// 4. Folder Anti-Dup Table
add_idx($pdo, 'posted_folder_files', 'idx_pff_folder_file', 'folder_id, file_id');

echo "=== ALL INDEXES PROCESSED SUCCESSFULLY! ===\n";
?>
