<?php
// actions/run_sync_in_background.php
@error_reporting(0);
@ini_set('display_errors', 0);

session_start();
if (!isset($_SESSION['account_id'])) {
    session_write_close();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}
$account_id = $_SESSION['account_id'];
session_write_close(); // Giải phóng session lock ngay lập tức

header('Content-Type: application/json');

// Kiểm tra khóa tiến trình trùng lặp (giới hạn 15 phút chống spam)
$lock_file = __DIR__ . '/../locks/sync_' . intval($account_id) . '.lock';
if (file_exists($lock_file) && (time() - filemtime($lock_file) < 900)) {
    echo json_encode([
        'status' => 'error',
        'msg' => 'Tiến trình đồng bộ đang được chạy ngầm trên máy chủ. Vui lòng chờ tiến trình trước hoàn tất (tối đa 15 phút) trước khi chạy lại.'
    ]);
    exit;
}

// Tạo khóa tiến trình trước khi kích hoạt worker
@file_put_contents($lock_file, time());

try {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $uri_dir = str_replace('\\', '/', dirname(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''));
    $sync_url = $protocol . $host . rtrim($uri_dir, '/') . "/run_sync_worker.php?account_id=" . intval($account_id);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sync_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Timeout 1s để phản hồi ngay lập tức về trình duyệt
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    curl_close($ch);

    echo json_encode([
        'status' => 'success',
        'msg' => 'Tiến trình đồng bộ lịch sử đã được kích hoạt chạy ngầm trên máy chủ. Bạn có thể đóng trình duyệt hoặc làm việc khác bình thường.'
    ]);
} catch (Exception $e) {
    // Xóa khóa nếu kích hoạt lỗi
    @unlink($lock_file);
    echo json_encode([
        'status' => 'error',
        'msg' => 'Lỗi kích hoạt tiến trình ngầm: ' . $e->getMessage()
    ]);
}
exit;
?>
