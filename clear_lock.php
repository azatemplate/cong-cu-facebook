<?php
// clear_lock.php
require_once __DIR__ . '/includes/redis_queue.php';
$rq = RedisQueue::getInstance();
$rq->releaseLock('lock:cron:daily_snapshot');
echo "<h2>✅ Đã đập vỡ ổ khóa Redis thành công!</h2>";
echo "Khóa 10 phút tồn đọng từ tiến trình lỗi cũ đã được xóa bỏ.<br>";
echo "Cronjob của bạn sẽ tự động chạy lại bình thường ở <b>phút tiếp theo</b> (bạn không cần làm gì thêm).";
