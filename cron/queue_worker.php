<?php
// cron/queue_worker.php
// Trạm thu phí (Queue Daemon) - Chạy ngầm liên tục để xử lý bài viết

ignore_user_abort(true);
set_time_limit(0);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/queue.php';

// Instantiate Queue
$queue = new JobQueue($pdo);
$queue_name = 'publish_jobs';

// Log process start
$pid = getmypid();
$log_file = dirname(__DIR__) . '/uploads/queue_worker.log';
@file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Worker Daemon #$pid started.\n", FILE_APPEND);

echo "Worker Daemon #$pid started...\n";

// Loop continuously
while (true) {
    // Pop job from queue
    $job = $queue->pop($queue_name);

    if ($job) {
        $job_id = $job['id'];
        $chan_key = $job['payload']['chan_key'] ?? '';
        
        if (!empty($chan_key)) {
            echo date('[Y-m-d H:i:s] ') . "Worker #$pid is processing chan_key: $chan_key\n";
            
            // Execute the original publish logic using a sub-process
            // This is safer than include() because publish_worker has exit/die and global state assumptions.
            $php_bin = function_exists('get_php_cli_bin') ? get_php_cli_bin() : 'php';
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $cmd = escapeshellarg($php_bin) . " " . escapeshellarg(__DIR__ . '/publish_worker.php') . " " . escapeshellarg($chan_key);
            } else {
                $cmd = escapeshellarg($php_bin) . " " . escapeshellarg(__DIR__ . '/publish_worker.php') . " " . escapeshellarg($chan_key) . " 2>&1";
            }
            
            // Chạy đồng bộ (đợi chạy xong)
            $output = [];
            $return_var = 0;
            exec($cmd, $output, $return_var);
            
            // Log output if needed
            // @file_put_contents($log_file, implode("\n", $output) . "\n", FILE_APPEND);
            
            echo date('[Y-m-d H:i:s] ') . "Worker #$pid finished chan_key: $chan_key\n";
        }
        
        // Hoàn thành Job
        $queue->complete($job_id);
    } else {
        // Không có job, tạm nghỉ 2 giây
        sleep(2);
    }
    
    // Tự giải phóng bộ nhớ và ngăn rò rỉ (restart worker sau 500 vòng lặp không việc)
    static $idle_cycles = 0;
    if (!$job) {
        $idle_cycles++;
        if ($idle_cycles > 1800) { // 1 tiếng không có việc thì tự thoát (Dispatcher sẽ tự gọi lại nếu cần)
            echo "Worker Daemon #$pid exiting after 1 hour of inactivity to free memory.\n";
            exit;
        }
    } else {
        $idle_cycles = 0;
    }
}
