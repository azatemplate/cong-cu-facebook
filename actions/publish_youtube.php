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
require_once __DIR__ . '/../includes/kho_data_helper.php';

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.']);
    exit;
}

// Check for empty $_POST due to post_max_size overflow
if (empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    $len_mb = round((int)$_SERVER['CONTENT_LENGTH'] / (1024 * 1024), 1);
    $ini_post = ini_get('post_max_size');
    echo json_encode([
        'status' => 'error',
        'msg' => "Dung lượng tệp ({$len_mb}MB) vượt quá giới hạn POST của máy chủ (post_max_size: {$ini_post}). Vui lòng tải file qua Google Drive hoặc tăng giới hạn PHP."
    ]);
    exit;
}

$account_id = $_SESSION['account_id'];

// Xác định kênh
$channel_ids = [];
if (isset($_POST['youtube_channel_ids'])) {
    if (is_array($_POST['youtube_channel_ids'])) {
        $channel_ids = $_POST['youtube_channel_ids'];
    } elseif (is_string($_POST['youtube_channel_ids'])) {
        $channel_ids = explode(',', $_POST['youtube_channel_ids']);
    }
}
$channel_ids = array_values(array_filter(array_map('trim', $channel_ids)));

if (empty($channel_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn Kênh YouTube.']);
    exit;
}

$title_input     = clean_markdown(trim($_POST['title'] ?? ''));
$desc_input      = clean_markdown(trim($_POST['description'] ?? ''));
$tags_input      = implode(', ', sanitize_youtube_tags($_POST['tags'] ?? ''));
$privacy_status  = in_array($_POST['privacy_status'] ?? '', ['public', 'unlisted', 'private']) ? $_POST['privacy_status'] : 'public';
$category_id     = !empty($_POST['category_id']) ? trim($_POST['category_id']) : '22';
$use_ai          = isset($_POST['use_ai']) && $_POST['use_ai'] == '1';
$auto_title      = isset($_POST['auto_title']) && $_POST['auto_title'] == '1';
$data_group_id   = intval($_POST['data_group_id'] ?? ($_REQUEST['data_group_id'] ?? 0));
$data_mode       = trim($_POST['data_mode'] ?? ($_REQUEST['data_mode'] ?? 'dedup'));
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

if ($data_group_id > 0) {
    $media_pool[] = ['type' => 'kho_data', 'group_id' => $data_group_id, 'mode' => $data_mode];
} elseif (!empty($tiktok_urls_str)) {
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

$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

// 1. Nhận tệp đã được tải lên trực tiếp theo phân mảnh (Chunked Upload - chuẩn genthegreat/video-uploader)
if (!empty($_POST['local_saved_files'])) {
    $raw_lfiles = $_POST['local_saved_files'];
    $lfiles_arr = is_array($raw_lfiles) ? $raw_lfiles : json_decode($raw_lfiles, true);
    if (is_array($lfiles_arr)) {
        foreach ($lfiles_arr as $lf) {
            $lf_path = is_array($lf) ? trim($lf['path'] ?? '') : trim($lf);
            $lf_name = is_array($lf) ? trim($lf['name'] ?? basename($lf_path)) : basename($lf_path);
            if (!empty($lf_path) && file_exists(__DIR__ . '/../' . $lf_path)) {
                $media_pool[] = [
                    'type' => 'local_saved',
                    'path' => $lf_path,
                    'name' => $lf_name
                ];
            }
        }
    }
} elseif (!empty($_POST['local_saved_file'])) {
    $lf_path = trim($_POST['local_saved_file']);
    $lf_name = trim($_POST['original_name'] ?? basename($lf_path));
    if (!empty($lf_path) && file_exists(__DIR__ . '/../' . $lf_path)) {
        $media_pool[] = [
            'type' => 'local_saved',
            'path' => $lf_path,
            'name' => $lf_name
        ];
    }
}

// 2. Fallback tải trực tiếp thông thường qua multipart $_FILES
if (empty($media_pool) && isset($_FILES['video']) && is_array($_FILES['video']['name'])) {
    for ($i = 0; $i < count($_FILES['video']['name']); $i++) {
        if ($_FILES['video']['error'][$i] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['video']['name'][$i], PATHINFO_EXTENSION) ?: 'mp4';
            $filename = uniqid('yt_') . '.' . $ext;
            if (move_uploaded_file($_FILES['video']['tmp_name'][$i], $upload_dir . $filename)) {
                $media_pool[] = [
                    'type'     => 'local_saved',
                    'path'     => 'uploads/' . $filename,
                    'name'     => $_FILES['video']['name'][$i]
                ];
            }
        }
    }
} elseif (empty($media_pool) && isset($_FILES['video']) && !is_array($_FILES['video']['name']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
    $ext = pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION) ?: 'mp4';
    $filename = uniqid('yt_') . '.' . $ext;
    if (move_uploaded_file($_FILES['video']['tmp_name'], $upload_dir . $filename)) {
        $media_pool[] = [
            'type'     => 'local_saved',
            'path'     => 'uploads/' . $filename,
            'name'     => $_FILES['video']['name']
        ];
    }
}

if (empty($media_pool) && !$is_drive_folder) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng cung cấp ít nhất 1 Video (TikTok, Drive hoặc Tải lên).']);
    exit;
}

function resolve_media_path_yt($media, $upload_dir, $auto_title, $title_input, $desc_input, $pdo = null, $account_id = 0) {
    $t_title = $title_input;
    $t_desc  = $desc_input;
    $media_path = null;
    $original_source = null;

    if ($media['type'] === 'kho_data') {
        $url = get_url_from_kho_data($pdo, $account_id, $media['group_id'], $media['mode']);
        if (!$url) {
            throw new Exception("Nhóm Data trong Kho Data đã hết URL khả dụng.");
        }
        $media_path = 'tiktok:' . $url;
        $original_source = $url;
    } elseif ($media['type'] === 'drive') {
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
    } elseif ($media['type'] === 'local_saved') {
        $media_path = $media['path'];
        $original_source = $media['name'];
        if ($auto_title && empty($t_title)) {
            $fn_no_ext = pathinfo($media['name'], PATHINFO_FILENAME);
            $t_title   = $fn_no_ext;
            $t_desc    = $fn_no_ext . ($desc_input ? "\n\n" . $desc_input : '');
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
$spread_time = isset($_POST['spread_time']) && $_POST['spread_time'] == '1';
$schedule_dates = [];

if (!empty($start_date) && !empty($end_date) && !empty($time_slots)) {
    $max_end = date('Y-m-d', strtotime($start_date . ' + 90 days'));
    if ($end_date > $max_end) $end_date = $max_end;
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
$channel_count = count($channel_ids);
$first_time    = !empty($schedule_dates) ? $schedule_dates[0] : date('Y-m-d H:i:s');
if ($spread_time && !empty($schedule_dates) && $channel_count > 1) {
    $first_time = date('Y-m-d H:i:s', strtotime($first_time) - 1800);
}
$total_posts   = !empty($schedule_dates) ? count($schedule_dates) * $channel_count : $channel_count;
$campaign_name = 'YouTube — ' . $channel_count . ' Kênh';
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
$media_pool_idx = 0;

if (!empty($schedule_dates)) {
    // Scheduled matrix mode
    $channel_count = count($channel_ids);
    foreach ($schedule_dates as $datetime) {
        $base_ts = strtotime($datetime);
        foreach ($channel_ids as $p_idx => $p_id) {
            $post_datetime = $datetime;
            if ($spread_time && $channel_count > 1) {
                $sec_offset = (int)round(-1800 + ($p_idx * (3600 / ($channel_count - 1))));
                $post_datetime = date('Y-m-d H:i:s', $base_ts + $sec_offset);
            }
            if ($is_drive_folder) {
                $media_path = $drive_file_ids_str;
                $t_title = $title_input;
                $t_desc = $desc_input;
                $original_source = 'Google Drive Folder';
            } else {
                if ($delete_drive_file && !empty($drive_pool)) {
                    $media = array_shift($drive_pool);
                } else {
                    $media = $media_pool[$media_pool_idx % count($media_pool)];
                    $media_pool_idx++;
                }
                [$media_path, $t_title, $t_desc, $original_source] = resolve_media_path_yt($media, $upload_dir, $auto_title, $title_input, $desc_input, $pdo, $account_id);
            }
            $content_arr = [
                'description'    => $t_desc,
                'title'          => $t_title,
                'tags'           => $tags_input,
                'privacy_status' => $privacy_status,
                'category_id'    => $category_id,
                'auto_title'     => $auto_title,
                'use_ai'         => $use_ai,
                'original_source'=> $original_source
            ];
            if ($delete_drive_file) {
                $content_arr['delete_drive_file'] = 1;
            }
            $content_data = json_encode($content_arr);
            insert_sp_yt($s_stmt_with, $s_stmt_without, $campaign_id, $comment_lines, $account_id, $p_id, $content_data, $media_path, $post_datetime);
            $success_count++;
        }
    }
    echo json_encode(['status' => 'success', 'msg' => "Đã thả {$success_count} video vào hàng đợi Upload YouTube!", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
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
            if ($delete_drive_file && !empty($drive_pool)) {
                $media = array_shift($drive_pool);
            } else {
                $media = $media_pool[$media_pool_idx % count($media_pool)];
                $media_pool_idx++;
            }
            [$media_path, $t_title, $t_desc, $original_source] = resolve_media_path_yt($media, $upload_dir, $auto_title, $title_input, $desc_input, $pdo, $account_id);
        }
        $content_arr = [
            'description'    => $t_desc,
            'title'          => $t_title,
            'tags'           => $tags_input,
            'privacy_status' => $privacy_status,
            'category_id'    => $category_id,
            'auto_title'     => $auto_title,
            'use_ai'         => $use_ai,
            'original_source'=> $original_source
        ];
        if ($delete_drive_file) {
            $content_arr['delete_drive_file'] = 1;
        }
        $content_data = json_encode($content_arr);
        insert_sp_yt($s_stmt_with, $s_stmt_without, $campaign_id, $comment_lines, $account_id, $p_id, $content_data, $media_path, $now);
        $success_count++;
    }
    // Tự động kích hoạt tiến trình ngầm đăng bài ngay lập tức
    try {
        if (!function_exists('get_php_cli_bin')) {
            @include_once __DIR__ . '/../includes/php_cli.php';
        }
        if (function_exists('get_php_cli_bin')) {
            $php_bin = get_php_cli_bin();
            $script = dirname(__DIR__) . '/cron/start_publish.php';
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                @pclose(@popen("start /B \"\" \"$php_bin\" \"$script\"", "r"));
            } else {
                @exec("nohup \"$php_bin\" \"$script\" > /dev/null 2>&1 &");
            }
        }
    } catch (Exception $e) {}

    echo json_encode(['status' => 'success', 'msg' => "Đã đưa $success_count video YouTube vào hàng đợi xử lý ngay.", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
}
?>
