<?php
// actions/run_scan_in_background.php
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

// Kiểm tra khóa tiến trình trùng lặp (giới hạn 3 phút chống spam)
$lock_file = __DIR__ . '/../locks/scan_' . intval($account_id) . '.lock';
if (file_exists($lock_file) && (time() - filemtime($lock_file) < 180)) {
    echo json_encode([
        'status' => 'error',
        'msg' => 'Tiến trình quét SĐT cũ đang được chạy ngầm trên máy chủ. Vui lòng chờ tiến trình trước hoàn tất (tối đa 3 phút) trước khi chạy lại.'
    ]);
    exit;
}

// Tạo khóa tiến trình trước khi kích hoạt worker
@file_put_contents($lock_file, time());

try {
    require_once __DIR__ . '/../includes/php_cli.php';
    $php_bin = get_php_cli_bin();
    $script = __DIR__ . '/scan_old_phones.php';
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen("start /B \"\" \"$php_bin\" \"$script\" \"$account_id\"", "r"));
    } else {
        exec("nohup \"$php_bin\" \"$script\" \"$account_id\" > /dev/null 2>&1 &");
    }

    echo json_encode([
        'status' => 'success',
        'msg' => 'Tiến trình quét tin nhắn cũ lấy SĐT (tối đa 200 KH) đã được kích hoạt chạy ngầm. Bạn có thể tắt trình duyệt.'
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
