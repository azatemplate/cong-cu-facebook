<?php
// actions/publish_buffer.php
error_reporting(0);
ob_start();
session_start();
set_time_limit(0);

if (!isset($_SESSION['account_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/drive_utils.php';

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Phương thức không hợp lệ.']);
    exit;
}

$account_id         = $_SESSION['account_id'];
$text_input         = trim($_POST['text'] ?? '');
$selected_channels  = $_POST['channels'] ?? [];
$auto_title         = isset($_POST['auto_title']) && $_POST['auto_title'] == '1';
$use_ai             = isset($_POST['use_ai']) && $_POST['use_ai'] == '1';
$delete_drive_file  = isset($_POST['delete_drive_file']) && $_POST['delete_drive_file'] == '1';
$tiktok_urls_str    = trim($_POST['tiktok_urls'] ?? '');
$drive_file_ids_str = trim($_POST['drive_file_id'] ?? '');

// Ma trận lên lịch hàng loạt
$start_date  = trim($_POST['start_date'] ?? '');
$end_date    = trim($_POST['end_date'] ?? '');
$time_slots  = trim($_POST['time_slots'] ?? '');

if (empty($selected_channels) || !is_array($selected_channels)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 kênh Buffer để đăng bài.']);
    exit;
}

// ── Lấy Danh Sách Channels Hợp Lệ Từ CSDL ──────────────────────────────────
$placeholders = implode(',', array_fill(0, count($selected_channels), '?'));
$params       = array_merge([$account_id], $selected_channels);

$stmt = $pdo->prepare("
    SELECT bc.channel_id, bc.channel_name, bc.service, ba.access_token, ba.email
    FROM buffer_channels bc
    JOIN buffer_accounts ba ON bc.buffer_account_id = ba.id
    WHERE bc.account_id = ? AND bc.channel_id IN ($placeholders)
");
$stmt->execute($params);
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($channels)) {
    echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy thông tin kênh Buffer hợp lệ.']);
    exit;
}

// ── Build Media Pool (Giống hệt reels.php, publish_video.php & publish_post.php) ─
$media_pool      = [];
$is_drive_folder = (strpos($drive_file_ids_str, 'folder:') === 0);

if (!empty($tiktok_urls_str)) {
    foreach (array_filter(array_map('trim', explode("\n", $tiktok_urls_str))) as $url) {
        $media_pool[] = ['type' => 'tiktok', 'url' => $url];
    }
}

if (!empty($drive_file_ids_str)) {
    if ($is_drive_folder) {
        $media_pool[] = ['type' => 'folder', 'id' => substr($drive_file_ids_str, 7)];
    } else {
        $ids = array_filter(array_map('trim', explode(",", $drive_file_ids_str)));
        foreach ($ids as $id) {
            $media_pool[] = ['type' => 'drive', 'id' => $id];
        }
    }
}

$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

if (!function_exists('upload_file_to_hongdolab_cdn')) {
    function upload_file_to_hongdolab_cdn($file_path) {
        if (!file_exists($file_path) || filesize($file_path) < 10) return false;
        @set_time_limit(0);
        
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) ?: 'mp4';
        $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $filename = basename($file_path);
        $filesize = filesize($file_path);

        // 1. SIÊU TỐC THỜI GIAN THỰC (0.001s): Nếu thư mục uploads của data.hongdolab.com có sẵn trên máy chủ
        $possible_dirs = [
            '/www/wwwroot/data.hongdolab.com/uploads/',
            __DIR__ . '/../data.hongdolab.com/uploads/',
            dirname(__DIR__) . '/uploads/'
        ];

        $doc_root = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
        if ($doc_root) {
            $possible_dirs[] = dirname($doc_root) . '/data.hongdolab.com/uploads/';
        }

        foreach ($possible_dirs as $target_cdn_dir) {
            if (is_dir($target_cdn_dir) && is_writable($target_cdn_dir)) {
                $prefix = $is_image ? 'img_' : 'vid_';
                $stored_name = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $final_target = $target_cdn_dir . $stored_name;
                if (@copy($file_path, $final_target) || @move_uploaded_file($file_path, $final_target)) {
                    @chmod($final_target, 0777);
                    clearstatcache(true, $final_target);
                    if (file_exists($final_target) && filesize($final_target) == $filesize) {
                        return 'https://data.hongdolab.com/uploads/' . $stored_name;
                    }
                }
            }
        }

        // 2. NẾU QUA HTTP API: Dùng Single-Request POST (action=video / action=image) - Không phân mảnh chunk gây lỗi
        $action_type = $is_image ? 'image' : 'video';
        $field_name  = $is_image ? 'image' : 'video';
        $mime        = function_exists('mime_content_type') ? mime_content_type($file_path) : ($is_image ? ('image/' . $ext) : ('video/' . $ext));

        $ch = curl_init('https://data.hongdolab.com/api/upload_video.php?action=' . $action_type);
        $cfile = new CURLFile($file_path, $mime, $filename);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [$field_name => $cfile],
            CURLOPT_TIMEOUT => 600,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res) {
            $json = json_decode($res, true);
            if (!empty($json['url'])) {
                return $json['url'];
            }
        }
        return false;
    }
}

if (isset($_FILES['media_files']) && is_array($_FILES['media_files']['name'])) {
    for ($i = 0; $i < count($_FILES['media_files']['name']); $i++) {
        if ($_FILES['media_files']['error'][$i] === UPLOAD_ERR_OK) {
            $ext      = pathinfo($_FILES['media_files']['name'][$i], PATHINFO_EXTENSION) ?: 'jpg';
            $filename = uniqid('buf_') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target   = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['media_files']['tmp_name'][$i], $target)) {
                $media_pool[] = ['type' => 'local', 'path' => 'uploads/' . $filename];
            }
        }
    }
}

// Helper giải mã media_path cho 1 bài
function resolve_buffer_media_path($media) {
    if (!$media) return '';
    if ($media['type'] === 'folder') return 'folder:' . $media['id'];
    if ($media['type'] === 'drive') return 'drive:' . $media['id'];
    if ($media['type'] === 'tiktok') return 'tiktok:' . $media['url'];
    if ($media['type'] === 'local') return $media['path'];
    return '';
}

// ── Matrix Schedule Parsing ────────────────────────────────────────────────
$schedule_timestamps = [];
if (!empty($start_date) && !empty($end_date) && !empty($time_slots)) {
    $slots   = array_filter(array_map('trim', explode(',', $time_slots)));
    $current = strtotime($start_date);
    $end     = strtotime($end_date);
    if ($current && $end && $current <= $end) {
        while ($current <= $end) {
            $date_str = date('Y-m-d', $current);
            foreach ($slots as $slot) {
                $time_clean = date('H:i:s', strtotime($slot));
                $schedule_timestamps[] = strtotime($date_str . ' ' . $time_clean);
            }
            $current = strtotime('+1 day', $current);
        }
    }
}

$is_scheduled = !empty($schedule_timestamps);

// ── Create Campaign ───────────────────────────────────────────────────────
$first_time    = $is_scheduled ? date('Y-m-d H:i:s', $schedule_timestamps[0]) : date('Y-m-d H:i:s');
$total_posts   = $is_scheduled ? (count($schedule_timestamps) * count($channels)) : count($channels);
$campaign_name = 'Buffer API — ' . count($channels) . ' Kênh';
if ($is_scheduled) {
    $campaign_name .= ' — ' . date('d/m', $schedule_timestamps[0]) . '→' . date('d/m/Y', end($schedule_timestamps));
} else {
    $campaign_name .= ' — ' . date('d/m/Y H:i');
}

$campaign_id = null;
try {
    $camp_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, ?, ?, ?)");
    $camp_stmt->execute([$account_id, $campaign_name, 'Buffer', $total_posts, $first_time]);
    $campaign_id = $pdo->lastInsertId();
} catch (PDOException $e) {}

// ── Insert Scheduled Posts Matrix ──────────────────────────────────────────
$success_count = 0;
$now_str = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    if ($is_scheduled) {
        $schedule_index = 0;
        foreach ($schedule_timestamps as $st_time) {
            $st_datetime = date('Y-m-d H:i:s', $st_time);
            
            foreach ($channels as $c_idx => $c) {
                $media_item = !empty($media_pool) ? $media_pool[($schedule_index + $c_idx) % count($media_pool)] : null;
                $media_path_str = resolve_buffer_media_path($media_item);

                $orig_src = '';
                if ($media_item) {
                    if ($media_item['type'] === 'tiktok') $orig_src = $media_item['url'];
                    elseif ($media_item['type'] === 'folder') $orig_src = 'Google Drive Folder';
                    elseif ($media_item['type'] === 'drive') $orig_src = 'Google Drive File';
                    elseif ($media_item['type'] === 'local') $orig_src = basename($media_item['path']);
                }

                $content_arr = [
                    'text' => $text_input,
                    'description' => $text_input,
                    'original_source' => $orig_src,
                    'auto_title' => $auto_title,
                    'use_ai' => $use_ai,
                    'delete_drive_file' => $delete_drive_file
                ];

                $sp_stmt = $pdo->prepare("
                    INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
                ");
                $sp_stmt->execute([
                    $account_id,
                    $c['channel_id'],
                    'Buffer (' . ucfirst($c['service']) . ')',
                    json_encode($content_arr),
                    $media_path_str,
                    $st_datetime,
                    $campaign_id
                ]);
                $success_count++;
            }
            $schedule_index++;
        }
    } else {
        foreach ($channels as $c_idx => $c) {
            $media_item = !empty($media_pool) ? $media_pool[$c_idx % count($media_pool)] : null;
            $media_path_str = resolve_buffer_media_path($media_item);

            $orig_src = '';
            if ($media_item) {
                if ($media_item['type'] === 'tiktok') $orig_src = $media_item['url'];
                elseif ($media_item['type'] === 'folder') $orig_src = 'Google Drive Folder';
                elseif ($media_item['type'] === 'drive') $orig_src = 'Google Drive File';
                elseif ($media_item['type'] === 'local') $orig_src = basename($media_item['path']);
            }

            $content_arr = [
                'text' => $text_input,
                'description' => $text_input,
                'original_source' => $orig_src,
                'auto_title' => $auto_title,
                'use_ai' => $use_ai,
                'delete_drive_file' => $delete_drive_file
            ];

            $sp_stmt = $pdo->prepare("
                INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $sp_stmt->execute([
                $account_id,
                $c['channel_id'],
                'Buffer (' . ucfirst($c['service']) . ')',
                json_encode($content_arr),
                $media_path_str,
                $now_str,
                $campaign_id
            ]);
            $success_count++;
        }
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi lưu dữ liệu: ' . $e->getMessage()]);
    exit;
}

// ── Trigger Background Worker ──────────────────────────────────────────────
try {
    if (!function_exists('get_php_cli_bin')) {
        @include_once __DIR__ . '/../includes/php_cli.php';
    }
    $php_bin = function_exists('get_php_cli_bin') ? get_php_cli_bin() : 'php';
    $script_path = __DIR__ . '/../cron/start_publish.php';

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        @pclose(@popen("start /B \"\" \"$php_bin\" \"$script_path\" > NUL 2>&1", "r"));
    } else {
        @exec("nohup \"$php_bin\" \"$script_path\" > /dev/null 2>&1 &");
    }

    // Web cURL Async Trigger Fallback (Kích hoạt tức thì nếu CLI exec bị chặn bởi php.ini)
    if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $trigger_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/trigger_publish.php";
        $ch = curl_init($trigger_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 300,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    }
} catch (Exception $e) {}

echo json_encode([
    'status' => 'success',
    'msg' => "Đã đưa $success_count bài đăng vào hàng đợi xử lý ngay.",
    'redirect' => 'manage_posts.php',
    'campaign_id' => $campaign_id
]);
exit;
