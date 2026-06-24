<?php
// actions/publish_youtube.php
error_reporting(0);
ob_start();
session_start();

if (!isset($_SESSION['account_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/drive_utils.php';
require_once __DIR__ . '/../includes/ai_rewriter.php';

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.']);
    exit;
}

$account_id = $_SESSION['account_id'];

// Xác định kênh
$channel_ids = [];
if (isset($_POST['youtube_channel_ids']) && is_array($_POST['youtube_channel_ids'])) {
    $channel_ids = $_POST['youtube_channel_ids'];
}

if (empty($channel_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn Kênh YouTube.']);
    exit;
}

$title_input     = trim($_POST['title'] ?? '');
$desc_input      = trim($_POST['description'] ?? '');
$tags_input      = trim($_POST['tags'] ?? '');
$use_ai          = isset($_POST['use_ai']) && $_POST['use_ai'] == '1';
$auto_title      = isset($_POST['auto_title']) && $_POST['auto_title'] == '1';
$tiktok_urls_str = trim($_POST['tiktok_urls'] ?? '');
$drive_file_ids_str = trim($_POST['drive_file_id'] ?? '');
$comment_lines   = isset($_POST['enable_comment']) && !empty(trim($_POST['comment_lines'] ?? ''))
    ? trim($_POST['comment_lines'])
    : null;
$delete_drive_file   = isset($_POST['delete_drive_file']) && $_POST['delete_drive_file'] == '1';
$is_drive_folder     = (strpos($drive_file_ids_str, 'folder:') === 0);

// Determine Media
$media_pool = [];
$drive_token = null;

if (!empty($tiktok_urls_str)) {
    foreach (array_filter(array_map('trim', explode("\n", $tiktok_urls_str))) as $url) {
        $media_pool[] = ['type' => 'tiktok', 'url' => $url, 'title' => ''];
    }
}

if (!empty($drive_file_ids_str) && !$is_drive_folder) {
    // Lấy tên file từ frontend (đã có sẵn từ Drive browser) thay vì gọi API cho từng file
    $drive_names_str = trim($_POST['drive_file_names'] ?? '');
    $drive_ids_arr = array_filter(array_map('trim', explode(",", $drive_file_ids_str)));
    $drive_names_arr = !empty($drive_names_str) ? explode("|||", $drive_names_str) : [];
    
    foreach ($drive_ids_arr as $idx => $id) {
        $drive_title = '';
        $drive_name = isset($drive_names_arr[$idx]) ? trim($drive_names_arr[$idx]) : '';
        if (empty($drive_name)) {
            if (!$drive_token) $drive_token = get_drive_access_token($pdo, $account_id);
            if ($drive_token) $drive_name = get_drive_file_name($drive_token, $id);
        }
        if ($drive_name) {
            $drive_title = pathinfo($drive_name, PATHINFO_FILENAME);
        }
        $media_pool[] = ['type' => 'drive', 'id' => $id, 'title' => $drive_title, 'original_name' => $drive_name];
    }
}

if (isset($_FILES['video']) && is_array($_FILES['video']['name'])) {
    for ($i = 0; $i < count($_FILES['video']['name']); $i++) {
        if ($_FILES['video']['error'][$i] === UPLOAD_ERR_OK) {
            $media_pool[] = [
                'type'     => 'local',
                'tmp_name' => $_FILES['video']['tmp_name'][$i],
                'name'     => $_FILES['video']['name'][$i]
            ];
        }
    }
} elseif (empty($media_pool) && isset($_FILES['video']) && !is_array($_FILES['video']['name']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
    $media_pool[] = [
        'type'     => 'local',
        'tmp_name' => $_FILES['video']['tmp_name'],
        'name'     => $_FILES['video']['name']
    ];
}

if (empty($media_pool) && !$is_drive_folder) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng cung cấp ít nhất 1 Video (TikTok, Drive hoặc Tải lên).']);
    exit;
}

$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

function resolve_media_path_yt($media, $upload_dir, $auto_title, $title_input, $desc_input) {
    $t_title = $title_input;
    $t_desc  = $desc_input;
    $media_path = null;
    $original_source = null;

    if ($media['type'] === 'drive') {
        $media_path = 'drive:' . $media['id'];
        $original_source = isset($media['original_name']) && !empty($media['original_name']) ? $media['original_name'] : $media['title'];
        if ($auto_title && empty($t_title)) { $t_title = $media['title']; $t_desc = $media['title'] . ($desc_input ? "\n\n" . $desc_input : ''); }
    } elseif ($media['type'] === 'tiktok') {
        $media_path = 'tiktok:' . $media['url'];
        $original_source = $media['url'];
        if ($auto_title && empty($t_title)) { 
            $t_title = $media['title']; 
            $t_desc = $media['title'] . ($desc_input ? "\n\n" . $desc_input : ''); 
        }
    } elseif ($media['type'] === 'local') {
        $ext        = pathinfo($media['name'], PATHINFO_EXTENSION) ?: 'mp4';
        $filename   = uniqid('yt_') . '.' . $ext;
        $media_path = 'uploads/' . $filename;
        copy($media['tmp_name'], $upload_dir . $filename);
        $original_source = $media['name'];
        if ($auto_title && empty($t_title)) {
            $fn_no_ext = pathinfo($media['name'], PATHINFO_FILENAME);
            $t_title   = $fn_no_ext;
            $t_desc    = $fn_no_ext . ($desc_input ? "\n\n" . $desc_input : '');
        }
    }
    return [$media_path, $t_title, $t_desc, $original_source];
}

// ── Schedule Matrix Parsing ───────────────────────────────────────────────
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
    $drive_count = 0;
    foreach ($media_pool as $m) {
        if ($m['type'] === 'drive') {
            $drive_count++;
        }
    }
    
    $total_posts = !empty($schedule_dates) ? count($schedule_dates) * count($channel_ids) : count($channel_ids);
    if ($drive_count < $total_posts) {
        echo json_encode(['status' => 'error', 'msg' => "Số lượng file Google Drive đã chọn ({$drive_count} file) không đủ. Bạn cần tối thiểu {$total_posts} file cho {$total_posts} video YouTube."]);
        exit;
    }
}

// ── Create Campaign ─────────────────────────────────────
$campaign_id = null;
$first_time    = !empty($schedule_dates) ? $schedule_dates[0] : date('Y-m-d H:i:s');
$total_posts   = !empty($schedule_dates) ? count($schedule_dates) * count($channel_ids) : count($channel_ids);
$campaign_name = 'YouTube — ' . count($channel_ids) . ' Kênh';
if (!empty($schedule_dates)) {
    $campaign_name .= ' — ' . date('d/m', strtotime($start_date)) . '→' . date('d/m/Y', strtotime($end_date));
} else {
    $campaign_name .= ' — ' . date('d/m/Y H:i');
}
try {
    $camp_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, ?, ?, ?)");
    $camp_stmt->execute([$account_id, $campaign_name, 'YouTube', $total_posts, $first_time]);
    $campaign_id = $pdo->lastInsertId();
} catch (PDOException $e) { }

// Detect extra cols
$has_extra_cols = false;
try {
    $col_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME='campaign_id'");
    $has_extra_cols = ($col_chk && $col_chk->fetchColumn() > 0);
} catch (Exception $e) { }

if (!$has_extra_cols) $campaign_id = null;

$s_stmt_with    = $has_extra_cols
    ? $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id, comment_lines) VALUES (?, ?, 'YouTube', ?, ?, ?, 'pending', ?, ?)")
    : null;
$s_stmt_without = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status) VALUES (?, ?, 'YouTube', ?, ?, ?, 'pending')");

function insert_sp_yt($s_with, $s_without, $campaign_id, $comment_lines, ...$args) {
    if ($campaign_id !== null && $s_with !== null) {
        $s_with->execute(array_merge($args, [$campaign_id, $comment_lines]));
    } else {
        $s_without->execute($args);
    }
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

if (!empty($schedule_dates)) {
    // Scheduled matrix mode
    foreach ($schedule_dates as $datetime) {
        foreach ($channel_ids as $p_id) {
            if ($is_drive_folder) {
                $media_path = $drive_file_ids_str;
                $t_title = $title_input;
                $t_desc = $desc_input;
                $original_source = 'Google Drive Folder';
            } else {
                if ($delete_drive_file) {
                    $media = array_shift($drive_pool);
                } else {
                    $media = $media_pool[array_rand($media_pool)];
                }
                [$media_path, $t_title, $t_desc, $original_source] = resolve_media_path_yt($media, $upload_dir, $auto_title, $title_input, $desc_input);
            }
            $content_arr = ['description' => $t_desc, 'title' => $t_title, 'tags' => $tags_input, 'auto_title' => $auto_title, 'use_ai' => $use_ai, 'original_source' => $original_source];
            if ($delete_drive_file) {
                $content_arr['delete_drive_file'] = 1;
            }
            $content_data = json_encode($content_arr);
            insert_sp_yt($s_stmt_with, $s_stmt_without, $campaign_id, $comment_lines, $account_id, $p_id, $content_data, $media_path, $datetime);
            $success_count++;
        }
    }
    echo json_encode(['status' => 'success', 'msg' => "Đã thả {$success_count} video vào hàng đợi Upload YouTube!", 'campaign_id' => $campaign_id]);
} else {
    // Immediate queue mode
    $now = date('Y-m-d H:i:s');
    foreach ($channel_ids as $p_id) {
        if ($is_drive_folder) {
            $media_path = $drive_file_ids_str;
            $t_title = $title_input;
            $t_desc = $desc_input;
            $original_source = 'Google Drive Folder';
        } else {
            if ($delete_drive_file) {
                $media = array_shift($drive_pool);
            } else {
                $media = $media_pool[array_rand($media_pool)];
            }
            [$media_path, $t_title, $t_desc, $original_source] = resolve_media_path_yt($media, $upload_dir, $auto_title, $title_input, $desc_input);
        }
        $content_arr = ['description' => $t_desc, 'title' => $t_title, 'tags' => $tags_input, 'auto_title' => $auto_title, 'use_ai' => $use_ai, 'original_source' => $original_source];
        if ($delete_drive_file) {
            $content_arr['delete_drive_file'] = 1;
        }
        $content_data = json_encode($content_arr);
        insert_sp_yt($s_stmt_with, $s_stmt_without, $campaign_id, $comment_lines, $account_id, $p_id, $content_data, $media_path, $now);
        $success_count++;
    }
    echo json_encode(['status' => 'success', 'msg' => "Đã đưa $success_count video YouTube vào hàng đợi xử lý ngay.", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
}
?>
