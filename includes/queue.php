<?php
// includes/queue.php

class JobQueue {
    private $pdo;
    private $redis = null;
    private $use_redis = false;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Try to connect to Redis
        if (extension_loaded('redis')) {
            try {
                $this->redis = new Redis();
                if ($this->redis->connect('127.0.0.1', 6379, 1)) {
                    $this->use_redis = true;
                }
            } catch (Exception $e) {
                $this->use_redis = false;
            }
        }
    }

    public function isRedisEnabled() {
        return $this->use_redis;
    }

    /**
     * Push a job into the queue
     */
    public function push($queue_name, $payload_array, $delay_seconds = 0) {
        $payload_json = json_encode($payload_array, JSON_UNESCAPED_UNICODE);
        
        if ($this->use_redis && $delay_seconds == 0) {
            // Push to Redis List (FIFO)
            $this->redis->lPush("queue:{$queue_name}", $payload_json);
            return true;
        } else {
            // Fallback to MySQL if Redis is unavailable or if job is delayed
            $available_at = date('Y-m-d H:i:s', time() + $delay_seconds);
            $stmt = $this->pdo->prepare("INSERT INTO queue_jobs (queue_name, payload, available_at) VALUES (?, ?, ?)");
            $stmt->execute([$queue_name, $payload_json, $available_at]);
            return true;
        }
    }

    /**
     * Pop a job from the queue
     * Returns associative array: ['id' => job_id, 'payload' => payload_array] or null if empty
     */
    public function pop($queue_name) {
        if ($this->use_redis) {
            // Try Redis first
            $job_json = $this->redis->rPop("queue:{$queue_name}");
            if ($job_json) {
                return [
                    'id' => 'redis_' . uniqid(),
                    'payload' => json_decode($job_json, true)
                ];
            }
        }

        // Fallback or delayed jobs in MySQL
        $this->pdo->beginTransaction();
        try {
            // Get the oldest available job and lock it
            $stmt = $this->pdo->prepare("
                SELECT id, payload 
                FROM queue_jobs 
                WHERE queue_name = ? AND reserved_at IS NULL AND available_at <= NOW() 
                ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED
            ");
            $stmt->execute([$queue_name]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($job) {
                // Reserve the job
                $update = $this->pdo->prepare("UPDATE queue_jobs SET reserved_at = NOW(), attempts = attempts + 1 WHERE id = ?");
                $update->execute([$job['id']]);
                $this->pdo->commit();
                
                return [
                    'id' => 'mysql_' . $job['id'],
                    'payload' => json_decode($job['payload'], true)
                ];
            }
            
            $this->pdo->commit();
            return null;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return null;
        }
    }

    /**
     * Mark a job as completed (delete it)
     */
    public function complete($job_id) {
        if (strpos($job_id, 'mysql_') === 0) {
            $id = substr($job_id, 6);
            $stmt = $this->pdo->prepare("DELETE FROM queue_jobs WHERE id = ?");
            $stmt->execute([$id]);
        }
        // Redis jobs are already popped from the list
    }

    /**
     * Mark a job as failed (release it back to queue after a delay, or delete if max attempts reached)
     */
    public function fail($job_id, $delay_seconds = 60, $max_attempts = 3) {
        if (strpos($job_id, 'mysql_') === 0) {
            $id = substr($job_id, 6);
            
            // Check attempts
            $stmt = $this->pdo->prepare("SELECT attempts, payload, queue_name FROM queue_jobs WHERE id = ?");
            $stmt->execute([$id]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($job) {
                if ($job['attempts'] >= $max_attempts) {
                    // Delete completely
                    $this->pdo->prepare("DELETE FROM queue_jobs WHERE id = ?")->execute([$id]);
                } else {
                    // Release back with delay
                    $available_at = date('Y-m-d H:i:s', time() + $delay_seconds);
                    $this->pdo->prepare("UPDATE queue_jobs SET reserved_at = NULL, available_at = ? WHERE id = ?")->execute([$available_at, $id]);
                }
            }
        } else {
            // For redis, we'd need to re-push or handle DLQ (Dead Letter Queue), but for simplicity we'll just ignore or push to MySQL
            // In a real system, you'd push back to Redis or a failed_jobs table.
        }
    }
}
