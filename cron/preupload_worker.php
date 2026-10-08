<?php
// cron/preupload_worker.php
// Worker xử lý upload nháp 1 bài viết sang Meta Graph API trước giờ hẹn.

if (php_sapi_name() !== 'cli' && !isset($_GET['post_id'])) {
    die("CLI access only.");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/drive_utils.php';

$post_id = 0;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $post_id = (int)$argv[1];
} elseif (isset($_GET['post_id']) && is_numeric($_GET['post_id'])) {
    $post_id = (int)$_GET['post_id'];
}

if ($post_id <= 0) {
    die("Invalid Post ID.\n");
}

$lock_dir = __DIR__ . '/../locks';
if (!is_dir($lock_dir)) @mkdir($lock_dir, 0777, true);
$lock_file = $lock_dir . "/preupload_post_{$post_id}.lock";
$lock_fp = @fopen($lock_file, 'c+');
if ($lock_fp) {
    if (!@flock($lock_fp, LOCK_EX | LOCK_NB)) {
        die("Preupload worker for post #{$post_id} already running.\n");
    }
}

$temp_drive_file = null;

try {
    $stmt = $pdo->prepare("
        SELECT sp.*, p.access_token as page_access_token
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id
        WHERE sp.id = ?
    ");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        die("Post #{$post_id} not found.\n");
    }

    $raw_token  = !empty($post['page_access_token']) ? $post['page_access_token'] : '';
    $page_token = function_exists('decryptData') ? decryptData($raw_token) : $raw_token;
    $page_id    = $post['page_id'];
    $raw_media  = $post['media_path'];
    $post_type  = $post['post_type'];

    if (empty($page_id) || empty($page_token) || empty($raw_media)) {
        $pdo->prepare("UPDATE scheduled_posts SET preupload_status = 'failed', preupload_error = ? WHERE id = ?")->execute(['Thiếu Fanpage Token hoặc Media Path', $post_id]);
        die("Missing required page credentials or media path.\n");
    }

    // ── XỬ LÝ ĐƯỜNG DẪN MEDIA (Google Drive / Thư mục / TikTok / Remote URL / File Local) ──
    $abs_media_path = '';
    $err_msg = '';
    $is_drive  = (strpos($raw_media, 'drive:') === 0);
    $is_folder = (strpos($raw_media, 'folder:') === 0);
    $is_tiktok = (strpos($raw_media, 'tiktok:') === 0);

    if ($is_folder) {
        $folder_id   = substr($raw_media, 7);
        $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
        if (!$drive_token) {
            $err_msg = "Không thể lấy Google Access Token từ hệ thống.";
        } else {
            $file_info = resolve_drive_folder_file($pdo, $drive_token, $folder_id);
            if (isset($file_info['error'])) {
                $err_msg = "Lỗi thư mục Drive: " . $file_info['error'];
            } elseif (!empty($file_info['id'])) {
                $drive_file_id  = $file_info['id'];
                $new_media_path = 'drive:' . $drive_file_id;
                $pdo->prepare("UPDATE scheduled_posts SET media_path = ? WHERE id = ?")->execute([$new_media_path, $post_id]);
                
                $dl_info = download_drive_file_temp($drive_token, $drive_file_id);
                if (isset($dl_info['error'])) {
                    $err_msg = "Lỗi tải file Drive: " . $dl_info['error'];
                } elseif (isset($dl_info['path']) && file_exists($dl_info['path'])) {
                    $abs_media_path = $dl_info['path'];
                }
            }
        }
    } elseif ($is_drive) {
        $drive_file_id = substr($raw_media, 6);
        $drive_token   = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
        if (!$drive_token) {
            $err_msg = "Không thể lấy Google Access Token từ hệ thống.";
        } else {
            $dl_info = download_drive_file_temp($drive_token, $drive_file_id);
            if (isset($dl_info['error'])) {
                $err_msg = "Lỗi tải Google Drive: " . $dl_info['error'];
            } elseif (isset($dl_info['path']) && file_exists($dl_info['path'])) {
                $abs_media_path = $dl_info['path'];
            }
        }
    } elseif ($is_tiktok) {
        $tiktok_url = substr($raw_media, 7);
        $tik_data   = null;
        if (function_exists('fetch_tiktok_info')) {
            $tik_data = fetch_tiktok_info($tiktok_url, $pdo);
        } else {
            $ch_tik = curl_init('https://tikwm.com/api/');
            curl_setopt_array($ch_tik, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['url' => $tiktok_url, 'hd' => 1]),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res_tik = curl_exec($ch_tik);
            curl_close($ch_tik);
            $json_tik = json_decode($res_tik, true);
            if (isset($json_tik['data']['play'])) {
                $tik_data = ['download_url' => $json_tik['data']['play']];
            }
        }

        if (!$tik_data || empty($tik_data['download_url'])) {
            $err_msg = "Không thể lấy link tải video TikTok.";
        } else {
            $dl_url = $tik_data['download_url'];
            $tmp_tik = sys_get_temp_dir() . '/preup_tik_' . uniqid() . '.mp4';
            $ch_dl = curl_init($dl_url);
            $fp_dl = fopen($tmp_tik, 'w+');
            curl_setopt_array($ch_dl, [
                CURLOPT_FILE => $fp_dl,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $dl_ok = curl_exec($ch_dl);
            $dl_code = curl_getinfo($ch_dl, CURLINFO_HTTP_CODE);
            curl_close($ch_dl);
            fclose($fp_dl);

            if ($dl_ok && $dl_code === 200 && file_exists($tmp_tik) && filesize($tmp_tik) > 0) {
                $abs_media_path  = $tmp_tik;
                $temp_drive_file = $tmp_tik;
            } else {
                @unlink($tmp_tik);
                $err_msg = "Không thể tải video TikTok về VPS (HTTP {$dl_code}).";
            }
        }
    } else {
        $is_remote_url = (strpos($raw_media, 'http://') === 0 || strpos($raw_media, 'https://') === 0);
        if ($is_remote_url) {
            $is_photo = ($post_type === 'Photo' || strpos($post_type, 'Photo') !== false || strpos($post_type, 'Image') !== false || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $raw_media));
            if ($is_photo) {
                $abs_media_path = $raw_media;
            } else {
                // Video/Reel remote URL must be downloaded locally so filesize() and chunk upload work!
                $tmp_remote = sys_get_temp_dir() . '/preup_rem_' . uniqid() . '.mp4';
                $ch_rem = curl_init($raw_media);
                $fp_rem = fopen($tmp_remote, 'w+');
                curl_setopt_array($ch_rem, [
                    CURLOPT_FILE => $fp_rem,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                $rem_ok = curl_exec($ch_rem);
                $rem_code = curl_getinfo($ch_rem, CURLINFO_HTTP_CODE);
                curl_close($ch_rem);
                fclose($fp_rem);

                if ($rem_ok && $rem_code === 200 && file_exists($tmp_remote) && filesize($tmp_remote) > 0) {
                    $abs_media_path  = $tmp_remote;
                    $temp_drive_file = $tmp_remote;
                } else {
                    @unlink($tmp_remote);
                    $err_msg = "Không thể tải video từ URL từ xa (HTTP {$rem_code}).";
                }
            }
        } else {
            $abs_media_path = __DIR__ . '/../' . ltrim($raw_media, '/');
            if (!file_exists($abs_media_path) && file_exists($raw_media)) {
                $abs_media_path = $raw_media;
            }
            if (!file_exists($abs_media_path)) {
                $err_msg = "Tệp media cục bộ không tồn tại: " . $raw_media;
                $abs_media_path = '';
            }
        }
    }

    if (empty($abs_media_path)) {
        $final_err = !empty($err_msg) ? $err_msg : 'Không thể tải hoặc tìm thấy tệp media để Pre-upload.';
        $pdo->prepare("UPDATE scheduled_posts SET preupload_status = 'failed', preupload_error = ? WHERE id = ?")->execute([$final_err, $post_id]);
        die("❌ " . $final_err . "\n");
    }

    echo "🚀 [Pre-upload Worker] Bắt đầu Upload nháp Post #{$post_id} (Type: {$post_type})...\n";

    $res = fb_preupload_media($page_id, $page_token, $abs_media_path, $post_type);

    if (!empty($res['status']) && !empty($res['media_id'])) {
        $media_id   = $res['media_id'];
        $session_id = $res['session_id'] ?? '';

        $stmt_up = $pdo->prepare("
            UPDATE scheduled_posts 
            SET preupload_status = 'uploaded', 
                preuploaded_media_id = ?, 
                preupload_session_id = ?,
                preupload_error = NULL
            WHERE id = ?
        ");
        $stmt_up->execute([$media_id, $session_id, $post_id]);

        echo "✅ [Pre-upload Worker] Thành công! Post #{$post_id} -> Preuploaded Media ID: {$media_id}\n";
    } else {
        $err = $res['error'] ?? 'Unknown error';
        $pdo->prepare("UPDATE scheduled_posts SET preupload_status = 'failed', preupload_error = ? WHERE id = ?")->execute([$err, $post_id]);
        echo "❌ [Pre-upload Worker] Thất bại cho Post #{$post_id}: {$err}\n";
    }

} catch (Exception $e) {
    $err_msg = $e->getMessage();
    @$pdo->prepare("UPDATE scheduled_posts SET preupload_status = 'failed', preupload_error = ? WHERE id = ?")->execute([$err_msg, $post_id]);
    echo "Lỗi Pre-upload Worker: " . $err_msg . "\n";
} finally {
    if (!empty($temp_drive_file) && file_exists($temp_drive_file)) {
        @unlink($temp_drive_file);
    }
    if (!empty($lock_fp)) {
        @flock($lock_fp, LOCK_UN);
        @fclose($lock_fp);
        @unlink($lock_file);
    }
}
