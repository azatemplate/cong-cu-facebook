<?php
// upload_video.php - Public Media & Video Upload CDN API for data.hongdolab.com
// Optimized with High-Performance Direct Streaming & Automated Garbage Collection Architecture
@set_time_limit(0);
@ini_set('memory_limit', '1024M');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = isset($_GET['action']) ? trim($_GET['action']) : '';
if (empty($action)) {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
}
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_body = @file_get_contents('php://input');
    if (!empty($raw_body)) {
        $json_body = @json_decode($raw_body, true);
        if (!empty($json_body['action'])) {
            $action = trim($json_body['action']);
        }
    }
}

// Thư mục lưu trữ công khai & tạm thời (Tự động nhận diện chuẩn đường dẫn uploads trên Linux/aaPanel)
$base_dir   = dirname(__DIR__);
$upload_dir = is_dir($base_dir . '/uploads') ? ($base_dir . '/uploads/') : (__DIR__ . '/../uploads/');
$temp_dir   = is_dir($base_dir . '/uploads_tmp') ? ($base_dir . '/uploads_tmp/') : (__DIR__ . '/../uploads_tmp/');

if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
if (!is_dir($temp_dir))   @mkdir($temp_dir, 0777, true);

// ── CƠ CHẾ TỰ ĐỘNG DỌN DẸP AN TOÀN CHO BUFFER & MẠNG XÃ HỘI (Garbage Collector) ───────────────
// 1. Thư mục /uploads/ (File hoàn chỉnh): Giữ 2 TIẾNG (7200 giây)
//    Đảm bảo Facebook/Instagram/TikTok/Pinterest có đủ thời gian tải và xử lý video.
// 2. Thư mục /uploads_tmp/ (File tạm dở dang): Giữ 1 TIẾNG (3600 giây).
function cleanup_old_files($upload_dir, $temp_dir, $upload_max_age = 7200, $tmp_max_age = 3600) {
    $now = time();
    $real_upload = realpath($upload_dir);
    $upload_dir  = $real_upload ? (rtrim($real_upload, '/') . '/') : (rtrim($upload_dir, '/') . '/');
    $real_temp   = realpath($temp_dir);
    $temp_dir    = $real_temp ? (rtrim($real_temp, '/') . '/') : (rtrim($temp_dir, '/') . '/');
    $log = [];

    // 1. Quét và xóa file ảnh/video trong /uploads/ nếu đã quá 2 TIẾNG (7200s)
    if (is_dir($upload_dir)) {
        $files = @scandir($upload_dir);
        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'upload_video.php' || $file === 'last_cleanup.txt') continue;
                $file_path = $upload_dir . $file;
                if (is_file($file_path)) {
                    $mtime = @filemtime($file_path);
                    $age = $mtime ? ($now - $mtime) : 0;
                    if ($mtime && $age >= $upload_max_age) {
                        @chmod($file_path, 0777);
                        $deleted = @unlink($file_path);
                        $log[] = "Deleted file: {$file} (Age: {$age}s, Deleted: " . ($deleted ? 'OK' : 'FAILED_PERMISSION') . ")";
                    } else {
                        $log[] = "Skipped file: {$file} (Age: {$age}s < threshold {$upload_max_age}s)";
                    }
                }
            }
        }
    }

    // 2. Quét và xóa các file tạm dở dang trong /uploads_tmp/ nếu quá 1 TIẾNG (3600s)
    if (is_dir($temp_dir)) {
        $items = @scandir($temp_dir);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === 'last_cleanup.txt') continue;
                $item_path = $temp_dir . $item;
                $mtime = @filemtime($item_path);
                $age = $mtime ? ($now - $mtime) : 0;
                if ($mtime && $age >= $tmp_max_age) {
                    if (is_dir($item_path)) {
                        $sub_files = @scandir($item_path);
                        if (is_array($sub_files)) {
                            foreach ($sub_files as $sf) {
                                if ($sf !== '.' && $sf !== '..') {
                                    @chmod($item_path . '/' . $sf, 0777);
                                    @unlink($item_path . '/' . $sf);
                                }
                            }
                        }
                        @rmdir($item_path);
                        $log[] = "Deleted temp folder: {$item}";
                    } elseif (is_file($item_path)) {
                        @chmod($item_path, 0777);
                        @unlink($item_path);
                        $log[] = "Deleted temp file: {$item}";
                    }
                }
            }
        }
    }
    return $log;
}

// Kích hoạt dọn dẹp an toàn: CHẠY TỰ ĐỘNG MỖI 10 PHÚT (600s) ĐỂ KHÔNG TỐN CPU DƯ THỪA
$last_cleanup_file = $temp_dir . 'last_cleanup.txt';
if ($action === 'cleanup' || !file_exists($last_cleanup_file) || (time() - @filemtime($last_cleanup_file)) > 600) {
    @touch($last_cleanup_file);
    $cleanup_log = cleanup_old_files($upload_dir, $temp_dir, 7200, 3600);
    if ($action === 'cleanup') {
        echo json_encode(['status' => 'success', 'server_time' => date('Y-m-d H:i:s'), 'scanned_dir' => $upload_dir, 'details' => $cleanup_log]);
        exit;
    }
}

// ACTION XÓA TỆP THỦ CÔNG/API (?action=delete)
if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $target_file = trim($input['file'] ?? $input['filename'] ?? $input['url'] ?? $_GET['file'] ?? $_GET['url'] ?? '');
    if (!empty($target_file)) {
        $file_name = basename(parse_url($target_file, PHP_URL_PATH));
        $full_path = $upload_dir . $file_name;
        if (file_exists($full_path) && is_file($full_path)) {
            @chmod($full_path, 0777);
            $deleted = @unlink($full_path);
            if ($deleted) {
                echo json_encode(['status' => 'success', 'message' => "Đã xóa tệp: {$file_name}"]);
                exit;
            }
        }
    }
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Tệp không tồn tại hoặc đã bị xóa trước đó.']);
    exit;
}

function get_server_domain() {
    $is_ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
    $scheme = $is_ssl ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'data.hongdolab.com';
    return $scheme . '://' . $host;
}

// 1. ACTION UPLOAD ẢNH (?action=image)
if ($action === 'image') {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Vui lòng chọn tệp ảnh hợp lệ.']);
        exit;
    }

    $file = $_FILES['image'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowed_exts)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Định dạng ảnh không hỗ trợ.']);
        exit;
    }

    $stored_name = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target_path = $upload_dir . $stored_name;

    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        $file_url = get_server_domain() . '/uploads/' . $stored_name;
        $mime_type = function_exists('mime_content_type') ? mime_content_type($target_path) : ('image/' . $ext);

        echo json_encode([
            'status'      => 'success',
            'url'         => $file_url,
            'stored_name' => $stored_name,
            'size'        => @filesize($target_path),
            'mime'        => $mime_type
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Không thể lưu tệp ảnh.']);
    }
    exit;
}

// 2. ACTION KHỞI TẠO VIDEO CHUNK (?action=init)
if ($action === 'init') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $filename = trim($input['filename'] ?? 'video.mp4');
    $filesize = intval($input['filesize'] ?? 0);
    $mime     = trim($input['mime'] ?? 'video/mp4');

    $upload_id = 'vid_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));
    
    // Lưu metadata phiên upload
    $meta_file = $temp_dir . $upload_id . '.json';
    file_put_contents($meta_file, json_encode([
        'filename' => $filename,
        'filesize' => $filesize,
        'mime'     => $mime,
        'created'  => time()
    ]));

    // Tạo sẵn file tạm stream để ghi nối dữ liệu
    $temp_stream_file = $temp_dir . $upload_id . '.tmp';
    @touch($temp_stream_file);

    echo json_encode([
        'status'     => 'success',
        'upload_id'  => $upload_id,
        'chunk_size' => 4194304
    ]);
    exit;
}

// 3. ACTION UPLOAD CHUNK (?action=chunk) - Siêu tốc với C-Native Stream & Tiết kiệm 50% Disk I/O
if ($action === 'chunk') {
    $upload_id = trim($_POST['upload_id'] ?? $_GET['upload_id'] ?? '');
    $index     = intval($_POST['index'] ?? $_GET['index'] ?? -1);

    if (empty($upload_id)) {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input) {
            $upload_id = trim($input['upload_id'] ?? '');
            if ($index < 0) $index = intval($input['index'] ?? -1);
        }
    }

    if (empty($upload_id) || $index < 0 || empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Dữ liệu chunk không hợp lệ.']);
        exit;
    }

    $temp_stream_file = $temp_dir . $upload_id . '.tmp';
    
    // Ghi trực tiếp nối đuôi (Direct C-Level Stream Copy với Khóa Độc Quyền LOCK_EX)
    $out = @fopen($temp_stream_file, 'ab');
    if ($out) {
        @flock($out, LOCK_EX);
        $in = @fopen($_FILES['chunk']['tmp_name'], 'rb');
        if ($in) {
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        @flock($out, LOCK_UN);
        fclose($out);

        echo json_encode(['status' => 'success', 'ok' => true, 'index' => $index, 'current_size' => @filesize($temp_stream_file)]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Không thể nối chunk #{$index} vào file tạm."]);
    exit;
}

// 4. ACTION HOÀN TẤT VIDEO (?action=complete) - Siêu tốc 0.001s
if ($action === 'complete') {
    $upload_id = trim($_POST['upload_id'] ?? $_GET['upload_id'] ?? '');
    if (empty($upload_id)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $upload_id = trim($input['upload_id'] ?? '');
    }

    $meta_file   = $temp_dir . $upload_id . '.json';
    $temp_stream = $temp_dir . $upload_id . '.tmp';
    $session_dir = $temp_dir . $upload_id . '/';

    $meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
    $ext  = strtolower(pathinfo($meta['filename'] ?? 'video.mp4', PATHINFO_EXTENSION)) ?: 'mp4';
    $stored_name = $upload_id . '.' . $ext;
    $final_file  = $upload_dir . $stored_name;

    // Cách 1: Chuyển đổi tên file từ Stream Temp file (Hoàn tất siêu tốc 0.001s, 0% CPU)
    if (file_exists($temp_stream) && filesize($temp_stream) > 100) {
        @chmod($temp_stream, 0777);
        $renamed = @rename($temp_stream, $final_file);
        if (!$renamed) {
            @copy($temp_stream, $final_file);
            @unlink($temp_stream);
        }
    } else {
        // Cách 2 Fallback: Ghép từ mảnh chunk rời nếu Stream temp trống
        $parts = glob($session_dir . 'part_*.part');
        if (!empty($parts)) {
            natsort($parts);
            $parts = array_values($parts);

            $out_fp = @fopen($final_file, 'wb');
            if ($out_fp) {
                foreach ($parts as $part_file) {
                    $in_fp = @fopen($part_file, 'rb');
                    if ($in_fp) {
                        stream_copy_to_stream($in_fp, $out_fp);
                        fclose($in_fp);
                    }
                }
                fclose($out_fp);
            }
        }
    }

    // Dọn dẹp thư mục tạm và metadata phiên
    if (is_dir($session_dir)) {
        $p_files = glob($session_dir . '*');
        if (is_array($p_files)) {
            foreach ($p_files as $pf) @unlink($pf);
        }
        @rmdir($session_dir);
    }
    if (file_exists($meta_file)) @unlink($meta_file);
    if (file_exists($temp_stream)) @unlink($temp_stream);

    if (file_exists($final_file) && filesize($final_file) > 10) {
        $file_url = get_server_domain() . '/uploads/' . $stored_name;

        echo json_encode([
            'status'      => 'success',
            'url'         => $file_url,
            'stored_name' => $stored_name,
            'size'        => filesize($final_file),
            'mime'        => $meta['mime'] ?? 'video/mp4'
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Lỗi tạo tệp hoàn chỉnh từ các mảnh chunk.']);
    exit;
}

if (empty($action)) {
    echo json_encode([
        'status'            => 'success',
        'message'           => 'CDN Upload API is online.',
        'supported_actions' => ['init', 'chunk', 'complete', 'image', 'cleanup', 'delete']
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => "Action '{$action}' không hợp lệ."]);
