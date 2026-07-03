<?php
// actions/publish_post.php
error_reporting(0);
ob_start();
session_start();
set_time_limit(0); // Chống timeout khi tạo hàng ngàn bài viết

// ── Auth Guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['account_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập. Vui lòng đăng nhập lại.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/drive_utils.php';
require_once __DIR__ . '/../includes/ai_rewriter.php';

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.']);
    exit;
}

$account_id = $_SESSION['account_id'];
$user_id    = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

// Support either single page_id or array of page_ids
$page_ids = [];
if (isset($_POST['page_ids']) && is_array($_POST['page_ids'])) {
    $page_ids = $_POST['page_ids'];
} elseif (isset($_POST['page_id']) && !empty($_POST['page_id'])) {
    $page_ids[] = $_POST['page_id'];
}

$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$use_ai  = isset($_POST['use_ai']) && $_POST['use_ai'] == '1';
$comment_lines = isset($_POST['enable_comment']) && !empty(trim($_POST['comment_lines'] ?? ''))
    ? trim($_POST['comment_lines'])
    : null;

// Random image count feature
$enable_random_images = isset($_POST['enable_random_images']) && $_POST['enable_random_images'] == '1';
$random_image_count   = isset($_POST['random_image_count']) ? max(1, intval($_POST['random_image_count'])) : 5;
$delete_drive_file    = isset($_POST['delete_drive_file']) && $_POST['delete_drive_file'] == '1';

if (!$user_id || empty($page_ids) || empty($message)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng điền đầy đủ các thông tin bắt buộc.']);
    exit;
}

$drive_file_ids_str = isset($_POST['drive_file_id']) ? trim($_POST['drive_file_id']) : '';
$upload_dir = __DIR__ . '/../uploads/';

// ── Build Media Pool ──────────────────────────────────────────────────────
$is_drive_folder = (strpos($drive_file_ids_str, 'folder:') === 0);
$media_pool = [];
$post_type  = 'Status';

if (!empty($drive_file_ids_str)) {
    if ($is_drive_folder) {
        $post_type = 'Image';
    } else {
        $ids = array_filter(array_map('trim', explode(",", $drive_file_ids_str)));
        foreach ($ids as $id) {
            $media_pool[] = ['type' => 'drive', 'id' => $id];
            $post_type = 'Image';
        }
    }
}

if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
    $count = count($_FILES['images']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
            $media_pool[] = [
                'type'     => 'local',
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'name'     => $_FILES['images']['name'][$i],
                'mime'     => mime_content_type($_FILES['images']['tmp_name'][$i])
            ];
            $post_type = 'Image';
        }
    }
}

$content_data_arr = [
    'description' => $message,
    'use_ai'      => $use_ai,
    'title'       => '',
    'auto_title'  => false
];
if ($delete_drive_file) {
    $content_data_arr['delete_drive_file'] = 1;
}
$content_data = json_encode($content_data_arr);

// ── Schedule Matrix Parsing (giống reels.php) ─────────────────────────────
$start_date  = trim($_POST['start_date'] ?? '');
$end_date    = trim($_POST['end_date'] ?? '');
$time_slots  = trim($_POST['time_slots'] ?? '');
$schedule_dates = [];

if (!empty($start_date) && !empty($end_date) && !empty($time_slots)) {
    $slots   = array_filter(array_map('trim', explode(',', $time_slots)));
    $current = strtotime($start_date);
    $end     = strtotime($end_date);
    if ($current && $end && $current <= $end) {
        while ($current <= $end) {
            $date_str = date('Y-m-d', $current);
            foreach ($slots as $slot) {
                $schedule_dates[] = $date_str . ' ' . $slot . ':00';
            }
            $current = strtotime('+1 day', $current);
        }
    }
}

if ($delete_drive_file && !$is_drive_folder) {
    if (!$enable_random_images) {
        echo json_encode(['status' => 'error', 'msg' => 'Khi chọn Chống trùng & Xóa file Drive, bạn bắt buộc phải bật tính năng "Random lấy X ảnh từ danh sách đã chọn".']);
        exit;
    }
    
    $drive_count = 0;
    foreach ($media_pool as $m) {
        if ($m['type'] === 'drive') {
            $drive_count++;
        }
    }
    
    $page_count  = count($page_ids);
    $total_posts = !empty($schedule_dates) ? count($schedule_dates) * $page_count : $page_count;
    $required_count = $total_posts * $random_image_count;
    
    if ($drive_count < $required_count) {
        echo json_encode(['status' => 'error', 'msg' => "Số lượng file Google Drive đã chọn ({$drive_count} ảnh) không đủ. Bạn cần tối thiểu {$required_count} ảnh cho {$total_posts} bài đăng (Mỗi bài cần {$random_image_count} ảnh)."]);
        exit;
    }
}

// Fallback: nếu không có schedule matrix, đăng ngay
$scheduled_time = date('Y-m-d H:i:s');

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ── Create Campaign (fault-tolerant: works even if table doesn't exist yet) ──
$campaign_id   = null;
$page_count    = count($page_ids);
$total_posts   = !empty($schedule_dates) ? count($schedule_dates) * $page_count : $page_count;
$campaign_name = $post_type . ' — ' . $page_count . ' Pages';
if (!empty($schedule_dates)) {
    $campaign_name .= ' — ' . date('d/m', strtotime($start_date)) . '→' . date('d/m/Y', strtotime($end_date));
} else {
    $campaign_name .= ' — ' . date('d/m/Y H:i');
}

try {
    $camp_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, ?, ?, ?)");
    $first_time = !empty($schedule_dates) ? $schedule_dates[0] : $scheduled_time;
    $camp_stmt->execute([$account_id, $campaign_name, $post_type, $total_posts, $first_time]);
    $campaign_id = $pdo->lastInsertId();
} catch (PDOException $e) {
    // Table may not exist yet — run migrate.php to create it. Scheduling continues without campaign.
}

// ── Insert Scheduled Posts ───────────────────────────────────────────────────
// Detect if campaign_id/comment_lines columns exist via INFORMATION_SCHEMA
$has_extra_cols = false;
try {
    $col_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME='campaign_id'");
    $has_extra_cols = ($col_chk && $col_chk->fetchColumn() > 0);
} catch (Exception $e) { }

if (!$has_extra_cols) $campaign_id = null;

$s_stmt_with    = $has_extra_cols
    ? $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id, comment_lines) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
    : null;
$s_stmt_without = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");

// Save local uploaded files once (so they persist from tmp)
$saved_local_files = [];
foreach ($media_pool as $idx => $media) {
    if ($media['type'] === 'local') {
        $ext      = pathinfo($media['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = uniqid('img_') . '.' . $ext;
        copy($media['tmp_name'], $upload_dir . $filename);
        $saved_local_files[$idx] = 'uploads/' . $filename;
    }
}

/**
 * Build media_path for one page.
 * - With random images enabled: randomly pick X images from pool
 * - If multiple images: shuffle and return JSON array of paths → publish_worker will do multi-photo post
 * - If single image: return single path string (backward compatible)
 */
function build_media_path_shuffled($media_pool, $saved_local_files, $enable_random = false, $random_count = 5) {
    if (empty($media_pool)) return null;

    // Shuffle a copy of the pool for this page
    $pool = $media_pool;
    shuffle($pool);

    // If random images enabled, only take X images from the pool
    if ($enable_random && count($pool) > $random_count) {
        $pool = array_slice($pool, 0, $random_count);
    }

    $paths = [];
    foreach ($pool as $media) {
        if ($media['type'] === 'drive') {
            $paths[] = 'drive:' . $media['id'];
        } else {
            // Find saved local file path by matching tmp_name
            foreach ($saved_local_files as $idx => $saved_path) {
                if ($media_pool[$idx]['tmp_name'] === $media['tmp_name']) {
                    $paths[] = $saved_path;
                    break;
                }
            }
        }
    }

    // Single image → backward compatible string
    if (count($paths) === 1) return $paths[0];
    // Multiple images → JSON array (publish_worker will detect and handle)
    return json_encode($paths);
}

$drive_pool = [];
if ($delete_drive_file) {
    foreach ($media_pool as $m) {
        if ($m['type'] === 'drive') {
            $drive_pool[] = $m;
        }
    }
    shuffle($drive_pool);
}

$success_count = 0;
try {
    $pdo->beginTransaction();

    if (!empty($schedule_dates)) {
        // Scheduled matrix mode (giống reels.php)
        foreach ($schedule_dates as $datetime) {
            foreach ($page_ids as $p_id) {
                if ($is_drive_folder) {
                    $media_path = $drive_file_ids_str;
                } else if ($delete_drive_file) {
                    $selected_media = array_splice($drive_pool, 0, $random_image_count);
                    $paths = [];
                    foreach ($selected_media as $m) {
                        $paths[] = 'drive:' . $m['id'];
                    }
                    $media_path = (count($paths) === 1) ? $paths[0] : json_encode($paths);
                } else {
                    $media_path = build_media_path_shuffled($media_pool, $saved_local_files, $enable_random_images, $random_image_count);
                }
                if ($campaign_id !== null && $s_stmt_with !== null) {
                    $s_stmt_with->execute([$account_id, $p_id, $post_type, $content_data, $media_path, $datetime, $campaign_id, $comment_lines]);
                } else {
                    $s_stmt_without->execute([$account_id, $p_id, $post_type, $content_data, $media_path, $datetime]);
                }
                $success_count++;
            }
        }
        $pdo->commit();
        echo json_encode(['status' => 'success', 'msg' => "Đã thả {$success_count} bài vào hàng đợi lên lịch hàng loạt!", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
    } else {
        // Immediate queue mode
        $now = date('Y-m-d H:i:s');
        foreach ($page_ids as $p_id) {
            if ($is_drive_folder) {
                $media_path = $drive_file_ids_str;
            } else if ($delete_drive_file) {
                $selected_media = array_splice($drive_pool, 0, $random_image_count);
                $paths = [];
                foreach ($selected_media as $m) {
                    $paths[] = 'drive:' . $m['id'];
                }
                $media_path = (count($paths) === 1) ? $paths[0] : json_encode($paths);
            } else {
                $media_path = build_media_path_shuffled($media_pool, $saved_local_files, $enable_random_images, $random_image_count);
            }
            if ($campaign_id !== null && $s_stmt_with !== null) {
                $s_stmt_with->execute([$account_id, $p_id, $post_type, $content_data, $media_path, $now, $campaign_id, $comment_lines]);
            } else {
                $s_stmt_without->execute([$account_id, $p_id, $post_type, $content_data, $media_path, $now]);
            }
            $success_count++;
        }
        $pdo->commit();

        $is_scheduled = false;
        if ($is_scheduled) {
            echo json_encode(['status' => 'success', 'msg' => "Đã lên lịch thành công cho $success_count Fanpage.", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
        } else {
            echo json_encode(['status' => 'success', 'msg' => "Đã đưa $success_count bài đăng vào hàng đợi xử lý ngay lập tức.", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'msg' => 'Quá trình lưu dữ liệu có lỗi: ' . $e->getMessage()]);
}
?>
