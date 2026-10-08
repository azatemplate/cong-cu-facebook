<?php
// Script to debug cron log
$log_file = '/tmp/fb_snapshot.log';
if (file_exists($log_file)) {
    echo "<pre>";
    // Lấy 100 dòng cuối cùng để tránh quá tải trình duyệt nếu file quá to
    $lines = file($log_file);
    if ($lines) {
        $last_lines = array_slice($lines, -100);
        foreach ($last_lines as $line) {
            echo htmlspecialchars($line);
        }
    } else {
        echo "File log rỗng.";
    }
    echo "</pre>";
} else {
    echo "Không tìm thấy file log: $log_file";
}
