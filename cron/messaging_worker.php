<?php
// cron/messaging_worker.php — Redis Worker xu ly message ngam
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/redis_queue.php';

$rq = RedisQueue::getInstance();
if (!$rq->isAvailable()) {
    echo "[" . date('Y-m-d H:i:s') . "] Redis Server is NOT available. Exiting.\n";
    exit;
}

$lockKey = 'messaging_worker_lock';
if (!$rq->acquireLock($lockKey, 10)) {
    echo "[" . date('Y-m-d H:i:s') . "] Another messaging worker is currently active. Exiting.\n";
    exit;
}

echo "[" . date('Y-m-d H:i:s') . "] Messaging Worker started listening on fb_messaging_queue & zalo_messaging_queue...\n";

$max_jobs = 100;
$processed = 0;

while ($processed < $max_jobs) {
    // Refresh lock
    $rq->acquireLock($lockKey, 10);

    // 1. Check FB Messaging Queue
    $job = $rq->popJob('fb_messaging_queue', 1);
    if ($job) {
        $processed++;
        echo "[" . date('Y-m-d H:i:s') . "] Processing FB Messaging Job #{$processed}...\n";
        try {
            // Processing logic for FB Webhook message
            if (is_array($job) && isset($job['entry'])) {
                // Execute webhook messaging handling
            }
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] FB Job Error: " . $e->getMessage() . "\n";
        }
        continue;
    }

    // 2. Check Zalo Messaging Queue
    $job_zalo = $rq->popJob('zalo_messaging_queue', 1);
    if ($job_zalo) {
        $processed++;
        echo "[" . date('Y-m-d H:i:s') . "] Processing Zalo Messaging Job #{$processed}...\n";
        try {
            // Processing logic for Zalo Webhook message
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Zalo Job Error: " . $e->getMessage() . "\n";
        }
        continue;
    }

    // No jobs in queues
    usleep(200000); // 200ms
    break;
}

$rq->releaseLock($lockKey);
echo "[" . date('Y-m-d H:i:s') . "] Messaging Worker finished batch ({$processed} jobs processed).\n";
