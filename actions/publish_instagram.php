<?php
// actions/publish_instagram.php
error_reporting(0);
ob_start();
session_start();
set_time_limit(0);

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
require_once __DIR__ . '/../includes/instagram_api.php';

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.']);
    exit;
}

$account_id = $_SESSION['account_id'];

// Receive IG User IDs
$ig_user_ids = [];
if (isset($_POST['ig_user_ids']) && is_array($_POST['ig_user_ids'])) {
    $ig_user_ids = array_filter(array_map('trim', $_POST['ig_user_ids']));
} elseif (isset($_POST['ig_user_id']) && !empty($_POST['ig_user_id'])) {
    $ig_user_ids[] = trim($_POST['ig_user_id']);
}

if (empty($ig_user_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 kênh Instagram để đăng bài.']);
    exit;
}

// Enforce daily posting limit (page_limit from system_accounts)
$acc_stmt = $pdo->prepare("SELECT role, page_limit FROM system_accounts WHERE id = ?");
$acc_stmt->execute([$account_id]);
$user_acc_info = $acc_stmt->fetch(PDO::FETCH_ASSOC);
$user_page_limit = intval($user_acc_info['page_limit'] ?? 500);
$user_role = $user_acc_info['role'] ?? 'user';

if ($user_role !== 'admin' && $user_page_limit > 0) {
    $today_start = date('Y-m-d 00:00:00');
    $today_end = date('Y-m-d 23:59:59');
    $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE account_id = ? AND created_at >= ? AND created_at <= ?");
    $chk_stmt->execute([$account_id, $today_start, $today_end]);
    $posts_created_today = intval($chk_stmt->fetchColumn());
    if ($posts_created_today >= $user_page_limit) {
        echo json_encode(['status' => 'error', 'msg' => "⚠️ Bạn đã đạt giới hạn tối đa {$user_page_limit} bài đăng/ngày (Đã tạo hôm nay: {$posts_created_today}/{$user_page_limit}). Vui lòng liên hệ Admin để nâng hạn ngạch."]);
        exit;
    }
}

$post_sub_type = $_POST['post_sub_type'] ?? 'Instagram'; // Instagram, Instagram_Reels, Instagram_Story
$caption       = clean_markdown(trim($_POST['caption'] ?? $_POST['message'] ?? $_POST['description'] ?? ''));
$use_ai          = isset($_POST['use_ai']) && $_POST['use_ai'] == '1';
$auto_title      = isset($_POST['auto_title']) && $_POST['auto_title'] == '1';
$comment_lines   = isset($_POST['enable_comment']) && !empty(trim($_POST['comment_lines'] ?? '')) ? trim($_POST['comment_lines']) : null;

$enable_random_images = isset($_POST['enable_random_images']) && $_POST['enable_random_images'] == '1';
$random_image_count   = isset($_POST['random_image_count']) ? max(1, intval($_POST['random_image_count'])) : 5;
$delete_drive_file    = isset($_POST['delete_drive_file']) && $_POST['delete_drive_file'] == '1';

$tiktok_urls_str    = trim($_POST['tiktok_urls'] ?? '');
$drive_file_ids_str = trim($_POST['drive_file_id'] ?? '');
$is_drive_folder    = (strpos($drive_file_ids_str, 'folder:') === 0);

$media_pool = [];

// 1. TikTok Links
if (!empty($tiktok_urls_str)) {
    foreach (array_filter(array_map('trim', explode("\n", $tiktok_urls_str))) as $url) {
        $media_pool[] = ['type' => 'tiktok', 'url' => $url, 'title' => ''];
    }
}

// 2. Google Drive Files
if (!empty($drive_file_ids_str) && !$is_drive_folder) {
    $drive_ids_arr = array_filter(array_map('trim', explode(",", $drive_file_ids_str)));
    foreach ($drive_ids_arr as $id) {
        $media_pool[] = ['type' => 'drive', 'id' => $id];
    }
}

// 3. Local Uploads
$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

// Images
if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
    for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
        if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION) ?: 'jpg';
            $filename = uniqid('ig_img_') . '.' . $ext;
            copy($_FILES['images']['tmp_name'][$i], $upload_dir . $filename);
            $media_pool[] = ['type' => 'local', 'saved_path' => 'uploads/' . $filename, 'name' => $_FILES['images']['name'][$i]];
        }
    }
} elseif (isset($_FILES['images']) && !is_array($_FILES['images']['name']) && $_FILES['images']['error'] === UPLOAD_ERR_OK) {
    $ext = pathinfo($_FILES['images']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = uniqid('ig_img_') . '.' . $ext;
    copy($_FILES['images']['tmp_name'], $upload_dir . $filename);
    $media_pool[] = ['type' => 'local', 'saved_path' => 'uploads/' . $filename, 'name' => $_FILES['images']['name']];
}

// Video / Reels
if (isset($_FILES['video']) && is_array($_FILES['video']['name'])) {
    for ($i = 0; $i < count($_FILES['video']['name']); $i++) {
        if ($_FILES['video']['error'][$i] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['video']['name'][$i], PATHINFO_EXTENSION) ?: 'mp4';
            $filename = uniqid('ig_vid_') . '.' . $ext;
            copy($_FILES['video']['tmp_name'][$i], $upload_dir . $filename);
            $media_pool[] = ['type' => 'local', 'saved_path' => 'uploads/' . $filename, 'name' => $_FILES['video']['name'][$i]];
        }
    }
} elseif (isset($_FILES['video']) && !is_array($_FILES['video']['name']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
    $ext = pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION) ?: 'mp4';
    $filename = uniqid('ig_vid_') . '.' . $ext;
    copy($_FILES['video']['tmp_name'], $upload_dir . $filename);
    $media_pool[] = ['type' => 'local', 'saved_path' => 'uploads/' . $filename, 'name' => $_FILES['video']['name']];
}

if (empty($media_pool) && !$is_drive_folder && empty($drive_file_ids_str)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 ảnh/video từ máy, Google Drive hoặc Link TikTok.']);
    exit;
}

// Schedule parsing
$start_date = trim($_POST['start_date'] ?? '');
$end_date   = trim($_POST['end_date'] ?? '');
$time_slots = trim($_POST['time_slots'] ?? '');
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

// Create Campaign
$campaign_id = null;
$total_posts = !empty($schedule_dates) ? count($schedule_dates) * count($ig_user_ids) : count($ig_user_ids);
$campaign_name = "Instagram " . str_replace('Instagram_', '', $post_sub_type) . " — " . count($ig_user_ids) . " Channels";
if (!empty($schedule_dates)) {
    $campaign_name .= " — " . date('d/m', strtotime($start_date)) . '→' . date('d/m/Y', strtotime($end_date));
}

try {
    $camp_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, ?, ?, ?)");
    $first_time = !empty($schedule_dates) ? $schedule_dates[0] : date('Y-m-d H:i:s');
    $camp_stmt->execute([$account_id, $campaign_name, $post_sub_type, $total_posts, $first_time]);
    $campaign_id = $pdo->lastInsertId();
} catch (Exception $e) {}

// Insert to scheduled_posts
$has_extra_cols = false;
try {
    $col_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME='campaign_id'");
    $has_extra_cols = ($col_chk && $col_chk->fetchColumn() > 0);
} catch (Exception $e) {}

$s_stmt_with    = $has_extra_cols
    ? $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id, comment_lines) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
    : null;
$s_stmt_without = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");

$drive_pool = [];
if ($delete_drive_file && !$is_drive_folder) {
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

    function resolve_ig_media_path(&$drive_pool, $media_pool, $drive_file_ids_str, $is_drive_folder, $delete_drive_file, $post_sub_type, $enable_random_images, $random_image_count) {
        if ($is_drive_folder) return $drive_file_ids_str;
        if (empty($media_pool)) return null;

        if ($delete_drive_file && !empty($drive_pool)) {
            if ($post_sub_type === 'Instagram' && $enable_random_images) {
                $picked = array_splice($drive_pool, 0, min(count($drive_pool), $random_image_count));
                $paths = [];
                foreach ($picked as $pm) { $paths[] = 'drive:' . $pm['id']; }
                return (count($paths) === 1) ? $paths[0] : json_encode($paths);
            } else {
                $picked_item = array_shift($drive_pool);
                if ($picked_item) return 'drive:' . $picked_item['id'];
            }
        }

        // Standard random fallback
        if ($post_sub_type === 'Instagram' && $enable_random_images && count($media_pool) > 1) {
            $pool_copy = $media_pool;
            shuffle($pool_copy);
            $picked = array_slice($pool_copy, 0, min(count($pool_copy), $random_image_count));
            $paths = [];
            foreach ($picked as $pm) {
                if ($pm['type'] === 'drive') $paths[] = 'drive:' . $pm['id'];
                elseif ($pm['type'] === 'tiktok') $paths[] = 'tiktok:' . $pm['url'];
                elseif ($pm['type'] === 'local') $paths[] = $pm['saved_path'];
            }
            return (count($paths) === 1) ? $paths[0] : json_encode($paths);
        }

        $m = $media_pool[array_rand($media_pool)];
        if ($m['type'] === 'drive') return 'drive:' . $m['id'];
        if ($m['type'] === 'tiktok') return 'tiktok:' . $m['url'];
        if ($m['type'] === 'local') return $m['saved_path'];
        return null;
    }

    $content_arr = [
        'description' => $caption,
        'use_ai'      => $use_ai,
        'auto_title'  => $auto_title
    ];
    if ($delete_drive_file) $content_arr['delete_drive_file'] = 1;
    $content_data = json_encode($content_arr);

    if (!empty($schedule_dates)) {
        foreach ($schedule_dates as $datetime) {
            foreach ($ig_user_ids as $ig_id) {
                $media_path = resolve_ig_media_path($drive_pool, $media_pool, $drive_file_ids_str, $is_drive_folder, $delete_drive_file, $post_sub_type, $enable_random_images, $random_image_count);
                if ($has_extra_cols && $s_stmt_with) {
                    $s_stmt_with->execute([$account_id, $ig_id, $post_sub_type, $content_data, $media_path, $datetime, $campaign_id, $comment_lines]);
                } else {
                    $s_stmt_without->execute([$account_id, $ig_id, $post_sub_type, $content_data, $media_path, $datetime]);
                }
                $success_count++;
            }
        }
    } else {
        $now = date('Y-m-d H:i:s');
        foreach ($ig_user_ids as $ig_id) {
            $media_path = resolve_ig_media_path($drive_pool, $media_pool, $drive_file_ids_str, $is_drive_folder, $delete_drive_file, $post_sub_type, $enable_random_images, $random_image_count);
            if ($has_extra_cols && $s_stmt_with) {
                $s_stmt_with->execute([$account_id, $ig_id, $post_sub_type, $content_data, $media_path, $now, $campaign_id, $comment_lines]);
            } else {
                $s_stmt_without->execute([$account_id, $ig_id, $post_sub_type, $content_data, $media_path, $now]);
            }
            $success_count++;
        }
    }

    $pdo->commit();

    // Auto-trigger background publisher immediately (CLI + HTTP cURL fallback)
    try {
        if (!empty($ig_user_ids)) {
            $in_ig = implode(',', array_fill(0, count($ig_user_ids), '?'));
            $pdo->prepare("UPDATE scheduled_posts SET status = 'pending' WHERE page_id IN ($in_ig) AND status = 'processing'")
                ->execute($ig_user_ids);
        }

        $disabled_funcs = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
        $exec_enabled = function_exists('exec') && !in_array('exec', $disabled_funcs);
        
        if ($exec_enabled) {
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
        }

        // Local HTTP cURL fallback launcher
        $base_url = '';
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
            $root_web_path = rtrim(str_replace('\\', '/', str_replace($doc_root, '', dirname(__DIR__))), '/');
            $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $root_web_path;
        } else {
            try {
                $stmt_u = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'base_site_url'");
                $base_url = $stmt_u ? trim($stmt_u->fetchColumn() ?: '') : '';
            } catch (Exception $e) {}
        }

        if (!empty($base_url)) {
            foreach ($ig_user_ids as $ig_id) {
                $url = rtrim($base_url, '/') . "/run_worker.php?type=publish&page_id=" . urlencode($ig_id) . "&user_id=" . urlencode('ig_' . $ig_id);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
                curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                @curl_exec($ch);
                @curl_close($ch);
            }
        }
    } catch (Exception $e) {}

    echo json_encode([
        'status'   => 'success',
        'msg'      => "🎉 Đã đưa {$success_count} bài viết Instagram vào hàng đợi thành công!",
        'redirect' => 'manage_posts.php',
        'campaign_id' => $campaign_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi xử lý: ' . $e->getMessage()]);
}
