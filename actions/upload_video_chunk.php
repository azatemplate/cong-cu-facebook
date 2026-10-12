<?php
// actions/upload_video_chunk.php
// Phân mảnh tệp tải lên trực tiếp máy chủ - Tránh giới hạn 50M post_max_size và triệt tiêu HTTP 504 Gateway Timeout
// Chuẩn kiến trúc genthegreat/video-uploader
error_reporting(0);
ob_start();
session_start();

if (!isset($_SESSION['account_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
    exit;
}

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.']);
    exit;
}

$upload_id    = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id'] ?? '');
$chunk_index  = isset($_POST['chunk_index']) ? intval($_POST['chunk_index']) : -1;
$total_chunks = isset($_POST['total_chunks']) ? intval($_POST['total_chunks']) : -1;
$file_name    = trim($_POST['file_name'] ?? '');

if (empty($upload_id) || $chunk_index < 0 || $total_chunks <= 0 || empty($file_name)) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu thông số phân mảnh tải lên (upload_id, chunk_index, total_chunks, file_name).']);
    exit;
}

if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    $err_code = $_FILES['chunk']['error'] ?? 'NO_FILE';
    echo json_encode(['status' => 'error', 'msg' => "Lỗi nhận phân mảnh $chunk_index/$total_chunks: Mã lỗi $err_code."]);
    exit;
}

$base_upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($base_upload_dir)) {
    @mkdir($base_upload_dir, 0777, true);
}

$chunks_base_dir = $base_upload_dir . 'chunks/';
if (!is_dir($chunks_base_dir)) {
    @mkdir($chunks_base_dir, 0777, true);
}

// Thư mục lưu các chunks của lượt upload này
$task_dir = $chunks_base_dir . $upload_id . '/';
if (!is_dir($task_dir)) {
    @mkdir($task_dir, 0777, true);
}

$target_chunk_file = $task_dir . 'part_' . $chunk_index;
if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $target_chunk_file)) {
    echo json_encode(['status' => 'error', 'msg' => "Không thể lưu phân mảnh $chunk_index trên máy chủ."]);
    exit;
}

// Nếu đây là chunk cuối cùng -> Ghép toàn bộ thành tệp hoàn chỉnh
$is_last = ($chunk_index + 1 === $total_chunks);

if ($is_last) {
    // 1. Kiểm tra đủ tất cả các part
    for ($i = 0; $i < $total_chunks; $i++) {
        if (!file_exists($task_dir . 'part_' . $i)) {
            echo json_encode(['status' => 'error', 'msg' => "Thiếu phân mảnh số $i/$total_chunks khi hoàn tất ghép tệp."]);
            exit;
        }
    }

    // 2. Tạo tên tệp đích an toàn
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed_exts = ['mp4', 'mov', 'mpeg', 'mpg', 'avi', 'wmv', 'flv', 'webm', 'm4v', 'mkv'];
    if (!in_array($ext, $allowed_exts)) {
        $ext = 'mp4';
    }

    $final_filename = 'yt_' . time() . '_' . substr(md5($upload_id . uniqid('', true)), 0, 10) . '.' . $ext;
    $final_filepath = $base_upload_dir . $final_filename;

    $out = fopen($final_filepath, 'wb');
    if (!$out) {
        echo json_encode(['status' => 'error', 'msg' => 'Không thể tạo tệp đích để ghép dữ liệu.']);
        exit;
    }

    for ($i = 0; $i < $total_chunks; $i++) {
        $part_file = $task_dir . 'part_' . $i;
        $in = fopen($part_file, 'rb');
        if ($in) {
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        @unlink($part_file);
    }
    fclose($out);
    @rmdir($task_dir);

    // Dọn dẹp các thư mục chunk quá hạn (> 2 giờ)
    try {
        $now = time();
        foreach (glob($chunks_base_dir . '*', GLOB_ONLYDIR) as $dir) {
            if ($now - filemtime($dir) > 7200) {
                foreach (glob($dir . '/*') as $f) @unlink($f);
                @rmdir($dir);
            }
        }
    } catch (Exception $e) {}

    $filesize = file_exists($final_filepath) ? filesize($final_filepath) : 0;

    echo json_encode([
        'status'       => 'success',
        'is_completed' => true,
        'saved_path'   => 'uploads/' . $final_filename,
        'file_name'    => $file_name,
        'file_size'    => $filesize
    ]);
    exit;
}

// Chưa phải chunk cuối cùng -> Trả về kết quả xác nhận thành công
echo json_encode([
    'status'       => 'success',
    'is_completed' => false,
    'chunk_index'  => $chunk_index,
    'total_chunks' => $total_chunks
]);
exit;
