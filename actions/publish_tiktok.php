<?php
// actions/publish_tiktok.php
error_reporting(0);
ob_start();
session_start();
set_time_limit(0);

if (!isset($_SESSION['account_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/tiktok_api.php';
require_once __DIR__ . '/../includes/drive_utils.php';

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.']);
    exit;
}

$account_id = $_SESSION['account_id'];

// Multi-Channel Selector Support
$tiktok_account_ids = [];
if (isset($_POST['tiktok_account_ids']) && is_array($_POST['tiktok_account_ids'])) {
    $tiktok_account_ids = array_map('intval', $_POST['tiktok_account_ids']);
} elseif (isset($_POST['tiktok_account_id']) && !empty($_POST['tiktok_account_id'])) {
    $tiktok_account_ids[] = intval($_POST['tiktok_account_id']);
}

if (empty($tiktok_account_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 Kênh TikTok đăng bài.']);
    exit;
}

// Fetch all selected TikTok Accounts for current user
$in_clause = implode(',', array_fill(0, count($tiktok_account_ids), '?'));
$params = array_merge($tiktok_account_ids, [$account_id]);
$stmt = $pdo->prepare("SELECT * FROM tiktok_accounts WHERE id IN ($in_clause) AND account_id = ? AND is_active = 1");
$stmt->execute($params);
$valid_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($valid_accounts)) {
    echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy tài khoản TikTok hợp lệ hoặc chưa được ủy quyền.']);
    exit;
}

$title_input    = trim($_POST['title'] ?? '');
$auto_title     = isset($_POST['auto_title']) && $_POST['auto_title'] == '1';
$privacy_level  = trim($_POST['privacy_level'] ?? 'PUBLIC_TO_EVERYONE');
$allow_comment  = isset($_POST['allow_comment']) && $_POST['allow_comment'] == '1';
$allow_duet     = isset($_POST['allow_duet']) && $_POST['allow_duet'] == '1';
$allow_stitch   = isset($_POST['allow_stitch']) && $_POST['allow_stitch'] == '1';
$auto_add_music = isset($_POST['auto_add_music']) && $_POST['auto_add_music'] == '1';

$drive_file_ids_str = trim($_POST['drive_file_id'] ?? '');
$tiktok_urls_str    = trim($_POST['tiktok_urls'] ?? '');
$is_drive_folder    = (strpos($drive_file_ids_str, 'folder:') === 0);

// ── Build Media Pool ──────────────────────────────────────────────────
$media_pool = [];

// 1. TikTok Links
if (!empty($tiktok_urls_str)) {
    foreach (array_filter(array_map('trim', explode("\n", $tiktok_urls_str))) as $url) {
        $media_pool[] = ['type' => 'tiktok', 'url' => $url, 'title' => ''];
    }
}

// 2. Google Drive Files or Folder
if (!empty($drive_file_ids_str)) {
    if ($is_drive_folder) {
        $folder_id = substr($drive_file_ids_str, 7);
        $folder_name = trim($_POST['drive_file_names'] ?? '');
        $media_pool[] = ['type' => 'drive_folder', 'id' => $folder_id, 'title' => $folder_name ? $folder_name : 'Google Drive Folder'];
    } else {
        $drive_names_str = trim($_POST['drive_file_names'] ?? '');
        $drive_ids_arr = array_filter(array_map('trim', explode(",", $drive_file_ids_str)));
        $drive_names_arr = !empty($drive_names_str) ? explode("|||", $drive_names_str) : [];
        
        foreach ($drive_ids_arr as $idx => $id) {
            $drive_title = '';
            $drive_name = isset($drive_names_arr[$idx]) ? trim($drive_names_arr[$idx]) : '';
            if ($drive_name) {
                $drive_title = pathinfo($drive_name, PATHINFO_FILENAME);
            }
            $media_pool[] = ['type' => 'drive', 'id' => $id, 'title' => $drive_title, 'original_name' => $drive_name];
        }
    }
}

// 3. Local Uploads
$upload_dir = __DIR__ . '/../uploads/videos/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

if (isset($_FILES['video']) && is_array($_FILES['video']['name'])) {
    for ($i = 0; $i < count($_FILES['video']['name']); $i++) {
        if ($_FILES['video']['error'][$i] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['video']['name'][$i], PATHINFO_EXTENSION) ?: 'mp4';
            $filename = 'tt_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['video']['tmp_name'][$i], $upload_dir . $filename)) {
                $media_pool[] = [
                    'type' => 'local',
                    'saved_path' => 'uploads/videos/' . $filename,
                    'name' => $_FILES['video']['name'][$i]
                ];
            }
        }
    }
} elseif (empty($media_pool) && isset($_FILES['video']) && !is_array($_FILES['video']['name']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
    $ext = pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION) ?: 'mp4';
    $filename = 'tt_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    if (move_uploaded_file($_FILES['video']['tmp_name'], $upload_dir . $filename)) {
        $media_pool[] = [
            'type' => 'local',
            'saved_path' => 'uploads/videos/' . $filename,
            'name' => $_FILES['video']['name']
        ];
    }
}

if (empty($media_pool)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng cung cấp ít nhất 1 Video (TikTok, Drive hoặc Tải từ máy).']);
    exit;
}

// ── Schedule Matrix Parsing ───────────────────────────────────────────────
$start_date  = trim($_POST['start_date'] ?? '');
$end_date    = trim($_POST['end_date'] ?? '');
$time_slots  = trim($_POST['time_slots'] ?? '');
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

// ── Create Campaign (Chiến dịch bài đăng) ──────────────────────────────────
$campaign_id = null;
$first_time  = !empty($schedule_dates) ? $schedule_dates[0] : date('Y-m-d H:i:s');
$acc_count   = count($valid_accounts);
$first_acc   = $valid_accounts[0];

$total_posts = !empty($schedule_dates) 
    ? (count($schedule_dates) * $acc_count) 
    : (count($media_pool) * $acc_count);

if ($acc_count === 1) {
    $campaign_name = 'TikTok — 1 kênh';
} else {
    $campaign_name = 'TikTok — ' . $acc_count . ' kênh';
}

if (!empty($schedule_dates)) {
    $campaign_name .= ' — ' . date('d/m', strtotime($start_date)) . '→' . date('d/m/Y', strtotime($end_date));
} else {
    $campaign_name .= ' — ' . date('d/m/Y H:i');
}

try {
    $camp_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, 'TikTok', ?, ?)");
    $camp_stmt->execute([$account_id, $campaign_name, $total_posts, $first_time]);
    $campaign_id = $pdo->lastInsertId();
} catch (Exception $e) {}

// ── Insert Scheduled Posts ────────────────────────────────────────────────
$success_count = 0;

try {
    $pdo->beginTransaction();

    $stmt_insert = $pdo->prepare("
        INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id)
        VALUES (?, ?, 'TikTok', ?, ?, ?, 'pending', ?)
    ");

    if (!empty($schedule_dates)) {
        // Scheduled matrix mode
        $idx = 0;
        foreach ($schedule_dates as $datetime) {
            foreach ($valid_accounts as $tt_acc) {
                if ($is_drive_folder) {
                    $media_path = $drive_file_ids_str;
                    $post_title = $title_input;
                } else {
                    $media = $media_pool[$idx % count($media_pool)];
                    if ($media['type'] === 'drive') {
                        $media_path = 'drive:' . $media['id'];
                        $post_title = $auto_title ? $media['title'] : $title_input;
                    } elseif ($media['type'] === 'tiktok') {
                        $media_path = 'tiktok:' . $media['url'];
                        $post_title = $title_input;
                    } else {
                        $media_path = $media['saved_path'];
                        $post_title = $auto_title ? pathinfo($media['name'], PATHINFO_FILENAME) : $title_input;
                    }
                }

                if (empty($post_title)) $post_title = $title_input;

                $stmt_insert->execute([$account_id, $tt_acc['id'], $post_title, $media_path, $datetime, $campaign_id]);
                $success_count++;
            }
            $idx++;
        }
    } else {
        // Immediate queue mode
        $now = date('Y-m-d H:i:s');
        foreach ($media_pool as $media) {
            foreach ($valid_accounts as $tt_acc) {
                if ($media['type'] === 'drive') {
                    $media_path = 'drive:' . $media['id'];
                    $post_title = $auto_title ? $media['title'] : $title_input;
                } elseif ($media['type'] === 'tiktok') {
                    $media_path = 'tiktok:' . $media['url'];
                    $post_title = $title_input;
                } elseif ($media['type'] === 'drive_folder') {
                    $media_path = 'folder:' . $media['id'];
                    $post_title = $title_input;
                } else {
                    $media_path = $media['saved_path'];
                    $post_title = $auto_title ? pathinfo($media['name'], PATHINFO_FILENAME) : $title_input;
                }

                if (empty($post_title)) $post_title = $title_input;

                $stmt_insert->execute([$account_id, $tt_acc['id'], $post_title, $media_path, $now, $campaign_id]);
                $success_count++;
            }
        }
    }

    $pdo->commit();

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

    ob_clean();
    echo json_encode([
        'status' => 'success',
        'msg' => "🎉 Đã đưa {$success_count} bài TikTok cho {$acc_count} kênh vào hàng đợi xử lý thành công!",
        'redirect' => 'manage_posts.php',
        'campaign_id' => $campaign_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean();
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi lưu hàng đợi bài đăng TikTok: ' . $e->getMessage()]);
}
?>
