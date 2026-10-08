<?php
// check_process.php
// Script kiểm tra các tiến trình PHP đang chạy thực tế trên Linux Terminal (aaPanel)
header('Content-Type: text/plain; charset=utf-8');
echo "=== DANH SÁCH TIẾN TRÌNH PHP ĐANG CHẠY TRÊN MÁY CHỦ ===\n\n";

if (function_exists('shell_exec')) {
    // Tìm các tiến trình PHP, ngoại trừ tiến trình 'grep' này
    $output = shell_exec('ps aux | grep php | grep -v grep');
    
    if (empty($output)) {
        echo "Không tìm thấy tiến trình PHP nào đang chạy ngầm.\n";
    } else {
        echo $output;
    }
} else {
    echo "Hàm shell_exec() đã bị vô hiệu hóa trên server (disable_functions).\n";
    echo "Không thể kiểm tra tiến trình qua Terminal bằng trình duyệt được.\n";
}
