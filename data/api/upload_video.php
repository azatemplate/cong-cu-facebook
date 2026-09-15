<?php
// upload_video.php - Public Media & Video Upload CDN API for data.hongdolab.com
// Optimized with High-Performance Direct Streaming & Automated Garbage Collection Architecture
@set_time_limit(0);
@ini_set('memory_limit', '1024M');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// ── THƯ MỤC LƯU TRỮ CÔNG KHAI & TẠM THỜI ─────────────────────────────────────
// File này nằm tại /www/wwwroot/data.hongdolab.com/api/upload_video.php
// -> dirname(__DIR__) = /www/wwwroot/data.hongdolab.com
$api_dir   = __DIR__;
$site_root = dirname($api_dir);
$doc_root  = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';

if ($doc_root && (is_dir($doc_root . '/uploads') || @mkdir($doc_root . '/uploads', 0777, true))) {
    $upload_dir = $doc_root . '/uploads/';
    $temp_dir   = is_dir($doc_root . '/uploads_tmp') ? ($doc_root . '/uploads_tmp/') : ($doc_root . '/uploads/');
} else {
    $upload_dir = $site_root . '/uploads/';
    $temp_dir   = is_dir($site_root . '/uploads_tmp') ? ($site_root . '/uploads_tmp/') : ($site_root . '/uploads/');
}

if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
if (!is_dir($temp_dir))   @mkdir($temp_dir, 0777, true);
@chmod($upload_dir, 0777);
@chmod($temp_dir, 0777);

// Tự động tạo /uploads/.htaccess để Apache/Nginx luôn phát video chuẩn với HTTP Range & MIME Types
$htaccess_file = $upload_dir . '.htaccess';
if (!file_exists($htaccess_file)) {
    $htaccess_rules = "<IfModule mod_headers.c>\n" .
                      "    Header set Access-Control-Allow-Origin \"*\"\n" .
                      "    Header set Accept-Ranges \"bytes\"\n" .
                      "</IfModule>\n" .
                      "AddType video/mp4 .mp4\n" .
                      "AddType video/webm .webm\n" .
                      "AddType video/ogg .ogv\n" .
                      "AddType image/jpeg .jpg .jpeg\n" .
                      "AddType image/png .png\n" .
                      "AddType image/webp .webp\n";
    @file_put_contents($htaccess_file, $htaccess_rules);
    @chmod($htaccess_file, 0644);
}

// ── CƠ CHẾ TỰ ĐỘNG DỌN DẸP AN TOÀN (Garbage Collector) ─────────────────────────
// Giữ tệp trong 5 PHÚT (300 giây) theo đúng yêu cầu của hệ thống
function cleanup_old_files($upload_dir, $temp_dir, $upload_max_age = 300, $tmp_max_age = 300) {
    $now = time();
    $real_upload = realpath($upload_dir);
    $upload_dir  = $real_upload ? (rtrim($real_upload, '/') . '/') : (rtrim($upload_dir, '/') . '/');
    $real_temp   = realpath($temp_dir);
    $temp_dir    = $real_temp ? (rtrim($real_temp, '/') . '/') : (rtrim($temp_dir, '/') . '/');
    $log = [];

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
                        $log[] = "Deleted file: {$file} (Age: {$age}s, Deleted: " . ($deleted ? 'OK' : 'FAILED') . ")";
                    }
                }
            }
        }
    }

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

// CHẠY DỌN DẸP TỰ ĐỘNG MỖI 5 PHÚT
$last_cleanup_file = $temp_dir . 'last_cleanup.txt';
if ($action === 'cleanup' || !file_exists($last_cleanup_file) || (time() - @filemtime($last_cleanup_file)) > 300) {
    @touch($last_cleanup_file);
    $cleanup_log = cleanup_old_files($upload_dir, $temp_dir, 300, 300);
    if ($action === 'cleanup') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'success', 'server_time' => date('Y-m-d H:i:s'), 'scanned_dir' => $upload_dir, 'details' => $cleanup_log]);
        exit;
    }
}

// ACTION XÓA TỆP THỦ CÔNG/API (?action=delete)
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
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

// STREAM VIDEO ENDPOINT (?action=stream&file=vid_xxx.mp4) - Hỗ trợ Range Requests HTTP 206
if ($action === 'stream') {
    $file_name = basename(trim($_GET['file'] ?? $_GET['name'] ?? ''));
    $file_path = $upload_dir . $file_name;
    if (empty($file_name) || !file_exists($file_path) || !is_file($file_path)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }

    $filesize = filesize($file_path);
    $mime = function_exists('mime_content_type') ? mime_content_type($file_path) : 'video/mp4';
    if (strpos($file_name, '.mp4') !== false) $mime = 'video/mp4';

    $start  = 0;
    $end    = $filesize - 1;
    $length = $filesize;

    header("Content-Type: {$mime}");
    header("Accept-Ranges: bytes");
    header("Access-Control-Allow-Origin: *");

    if (isset($_SERVER['HTTP_RANGE'])) {
        $c_start = $start;
        $c_end   = $end;
        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes */{$filesize}");
            exit;
        }
        if ($range === '-') {
            $c_start = $filesize - substr($range, 1);
        } else {
            $range  = explode('-', $range);
            $c_start = intval($range[0]);
            $c_end   = (isset($range[1]) && is_numeric($range[1])) ? intval($range[1]) : $end;
        }
        $c_end = ($c_end > $end) ? $end : $c_end;
        if ($c_start > $c_end || $c_start > $filesize - 1 || $c_end >= $filesize) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes */{$filesize}");
            exit;
        }
        $start  = $c_start;
        $end    = $c_end;
        $length = $end - $start + 1;
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes {$start}-{$end}/{$filesize}");
    }

    header("Content-Length: " . $length);
    $fp = fopen($file_path, 'rb');
    fseek($fp, $start);
    $buffer_size = 1024 * 64;
    while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
        if ($pos + $buffer_size > $end) {
            $buffer_size = $end - $pos + 1;
        }
        echo fread($fp, $buffer_size);
        flush();
    }
    fclose($fp);
    exit;
}

function get_server_domain() {
    $host = $_SERVER['HTTP_HOST'] ?? 'data.hongdolab.com';
    return 'https://' . $host;
}

// 1. ACTION UPLOAD ẢNH (?action=image)
if ($action === 'image') {
    header('Content-Type: application/json; charset=utf-8');
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
        @chmod($target_path, 0777);
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

// 1b. ACTION UPLOAD VIDEO TRỰC TIẾP KHÔNG CHUNK (?action=video hoặc ?action=direct)
if ($action === 'video' || $action === 'direct') {
    header('Content-Type: application/json; charset=utf-8');
    $file = $_FILES['video'] ?? $_FILES['file'] ?? $_FILES['media'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Vui lòng chọn tệp video hợp lệ.']);
        exit;
    }

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'mp4';
    $stored_name = 'vid_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target_path = $upload_dir . $stored_name;

    if (move_uploaded_file($file['tmp_name'], $target_path) || copy($file['tmp_name'], $target_path)) {
        @chmod($target_path, 0777);
        echo json_encode([
            'status'      => 'success',
            'url'         => get_server_domain() . '/uploads/' . $stored_name,
            'stored_name' => $stored_name,
            'size'        => @filesize($target_path),
            'mime'        => 'video/' . $ext
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Không thể lưu tệp video trực tiếp.']);
    exit;
}

// 2. ACTION KHỞI TẠO VIDEO CHUNK (?action=init)
if ($action === 'init') {
    header('Content-Type: application/json; charset=utf-8');
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

    // Tạo trước file tạm stream để ghi nối siêu tốc
    $temp_stream_file = $temp_dir . $upload_id . '.tmp';
    @touch($temp_stream_file);
    @chmod($temp_stream_file, 0777);

    echo json_encode([
        'status'     => 'success',
        'upload_id'  => $upload_id,
        'chunk_size' => 2097152 // Chunk 2MB chuẩn tối ưu
    ]);
    exit;
}

// 3. ACTION UPLOAD CHUNK (?action=chunk) - Lưu song song Stream Direct + Indexed Part File
if ($action === 'chunk') {
    header('Content-Type: application/json; charset=utf-8');
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
        $err_msg = 'Dữ liệu chunk không hợp lệ.';
        if (!empty($_FILES['chunk']['error'])) {
            $err_msg .= ' Error code: ' . $_FILES['chunk']['error'];
        }
        echo json_encode(['status' => 'error', 'message' => $err_msg]);
        exit;
    }

    $temp_stream_file = $temp_dir . $upload_id . '.tmp';
    $session_dir      = $temp_dir . $upload_id . '/';
    if (!is_dir($session_dir)) {
        @mkdir($session_dir, 0777, true);
        @chmod($session_dir, 0777);
    }

    $part_file = $session_dir . sprintf('part_%05d.part', $index);
    $tmp_file  = $_FILES['chunk']['tmp_name'];

    // 1. Ghi nối tiếp trực tiếp vào .tmp (Nhanh nhất & giữ nguyên byte stream)
    $out = @fopen($temp_stream_file, 'ab');
    if ($out) {
        @flock($out, LOCK_EX);
        $in = @fopen($tmp_file, 'rb');
        if ($in) {
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fflush($out);
        @flock($out, LOCK_UN);
        fclose($out);
    }

    // 2. Đồng thời lưu mảnh part_0000x.part để làm dự phòng
    @copy($tmp_file, $part_file);
    @chmod($part_file, 0777);

    echo json_encode(['status' => 'success', 'ok' => true, 'index' => $index, 'chunk_size' => @filesize($part_file)]);
    exit;
}

// 4. ACTION HOÀN TẤT VIDEO (?action=complete) - Ghép chính xác 100% theo Số Nguyên Index
if ($action === 'complete') {
    header('Content-Type: application/json; charset=utf-8');
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

    $expected_size = intval($meta['filesize'] ?? 0);
    $stream_size   = file_exists($temp_stream) ? filesize($temp_stream) : 0;

    // Cách 1: Sử dụng File Stream nối tiếp (Nếu kích thước khớp với filesize đã khai báo hoặc > 100 bytes)
    if ($stream_size > 100 && ($expected_size <= 0 || abs($stream_size - $expected_size) < 1000)) {
        @chmod($temp_stream, 0777);
        if (!@rename($temp_stream, $final_file)) {
            @copy($temp_stream, $final_file);
            @unlink($temp_stream);
        }
    } else {
        // Cách 2 Fallback: Ghép lại các mảnh part theo THỨ TỰ SỐ NGUYÊN CHÍNH XÁC (Tránh lỗi sắp xếp chuỗi)
        $parts = glob($session_dir . 'part_*.part');
        if (!empty($parts)) {
            usort($parts, function($a, $b) {
                preg_match('/part_(\d+)\.part$/', $a, $mA);
                preg_match('/part_(\d+)\.part$/', $b, $mB);
                $idxA = isset($mA[1]) ? intval($mA[1]) : 0;
                $idxB = isset($mB[1]) ? intval($mB[1]) : 0;
                return $idxA <=> $idxB;
            });

            $out_fp = @fopen($final_file, 'wb');
            if ($out_fp) {
                @flock($out_fp, LOCK_EX);
                foreach ($parts as $part_file) {
                    $in_fp = @fopen($part_file, 'rb');
                    if ($in_fp) {
                        stream_copy_to_stream($in_fp, $out_fp);
                        fclose($in_fp);
                    }
                }
                fflush($out_fp);
                @flock($out_fp, LOCK_UN);
                fclose($out_fp);
            }
        }
    }

    clearstatcache(true, $final_file);

    // Dọn dẹp rác phiên tạm
    if (is_dir($session_dir)) {
        $p_files = glob($session_dir . '*');
        if (is_array($p_files)) {
            foreach ($p_files as $pf) @unlink($pf);
        }
        @rmdir($session_dir);
    }
    if (file_exists($meta_file))   @unlink($meta_file);
    if (file_exists($temp_stream)) @unlink($temp_stream);

    if (file_exists($final_file) && filesize($final_file) > 100) {
        @chmod($final_file, 0777);
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

header('Content-Type: application/json; charset=utf-8');
http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Action không hợp lệ.']);

