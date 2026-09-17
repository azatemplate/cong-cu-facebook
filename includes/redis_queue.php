<?php
// includes/redis_queue.php — Lop ho tro Redis Queue & Atomic Lock siêu toc
require_once __DIR__ . '/config.php';

class RedisQueue {
    private static $instance = null;
    private $redis = null;
    private $connected = false;
    private $host = '127.0.0.1';
    private $port = 6379;
    private $auth = null;

    private function __construct() {
        if (defined('REDIS_HOST')) $this->host = REDIS_HOST;
        if (defined('REDIS_PORT')) $this->port = (int)REDIS_PORT;
        if (defined('REDIS_AUTH')) $this->auth = REDIS_AUTH;

        $this->connect();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect() {
        try {
            if (class_exists('Redis')) {
                $this->redis = new Redis();
                $ok = @$this->redis->connect($this->host, $this->port, 0.2);
                if ($ok) {
                    if ($this->auth) {
                        @$this->redis->auth($this->auth);
                    }
                    $this->connected = true;
                    return;
                }
            }
        } catch (Exception $e) {
            $this->connected = false;
        }

        // Socket fallback if extension is not installed or connect failed
        try {
            $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                $this->connected = false; // Require extension for full features
            }
        } catch (Exception $e) {}
    }

    public function isAvailable() {
        if (!$this->connected || !$this->redis) return false;
        try {
            return $this->redis->ping() === '+PONG' || $this->redis->ping() === true;
        } catch (Exception $e) {
            $this->connected = false;
            return false;
        }
    }

    public function pushJob($queueName, $data) {
        if (!$this->isAvailable()) return false;
        try {
            $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            return $this->redis->lPush($queueName, $payload) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function popJob($queueName, $timeout = 2) {
        if (!$this->isAvailable()) return false;
        try {
            $res = $this->redis->brPop([$queueName], (int)$timeout);
            if (!empty($res) && is_array($res) && isset($res[1])) {
                $raw = $res[1];
                $decoded = json_decode($raw, true);
                return $decoded !== null ? $decoded : $raw;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function acquireLock($lockKey, $ttlSeconds = 300) {
        if (!$this->isAvailable()) return true; // Fallback: allow processing if Redis unavailable
        try {
            // SET key val NX EX ttl -> Atomic lock
            $result = $this->redis->set($lockKey, '1', ['nx', 'ex' => (int)$ttlSeconds]);
            return $result === true || $result === 'OK';
        } catch (Exception $e) {
            return true;
        }
    }

    public function releaseLock($lockKey) {
        if (!$this->isAvailable()) return true;
        try {
            return $this->redis->del($lockKey) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getQueueLength($queueName) {
        if (!$this->isAvailable()) return 0;
        try {
            return (int)$this->redis->lLen($queueName);
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getCache($key) {
        if (!$this->isAvailable()) return false;
        try {
            $val = $this->redis->get($key);
            return $val !== false ? json_decode($val, true) : false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function setCache($key, $ttlSeconds, $data) {
        if (!$this->isAvailable()) return false;
        try {
            $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            return $this->redis->setex($key, (int)$ttlSeconds, $payload);
        } catch (Exception $e) {
            return false;
        }
    }

    public function deleteCache($key) {
        if (!$this->isAvailable()) return false;
        try {
            return $this->redis->del($key);
        } catch (Exception $e) {
            return false;
        }
    }
}

