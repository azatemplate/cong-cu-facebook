<?php
// vps_realtime_debug.php — Real-time performance & bottleneck debugger

header('Content-Type: text/plain; charset=utf-8');

echo "=========================================================\n";
echo "   ANTIGRAVITY VPS REAL-TIME DEBUG & BENCHMARK REPORT\n";
echo "   Time: " . date('Y-m-d H:i:s') . "\n";
echo "=========================================================\n\n";

// 1. CHUẨN ĐOÁN KẾT NỐI MARIADB & THỜI GIAN TRUY VẤN
$t0 = microtime(true);
require_once __DIR__ . '/includes/db.php';
$db_conn_ms = round((microtime(true) - $t0) * 1000, 2);
echo "[1] KẾT NỐI CSDL MARIADB:\n";
echo "    -> Thời gian tạo kết nối PDO: {$db_conn_ms} ms\n\n";

// 2. CHẨN ĐOÁN CÁC QUERY CHÍNH TRÊN SCHEDULED_POSTS
echo "[2] ĐO TỐC ĐỘ TRUY VẤN CSDL (BENCHMARK QUERIES):\n";

$t1 = microtime(true);
$cnt_all = $pdo->query("SELECT COUNT(*) FROM scheduled_posts")->fetchColumn();
$t1_ms = round((microtime(true) - $t1) * 1000, 2);
echo "    -> COUNT(*) scheduled_posts ($cnt_all dòng): {$t1_ms} ms\n";

$t2 = microtime(true);
$cnt_pending = $pdo->query("SELECT COUNT(*) FROM scheduled_posts WHERE status IN ('pending', 'failed')")->fetchColumn();
$t2_ms = round((microtime(true) - $t2) * 1000, 2);
echo "    -> Query bài Pending/Failed ($cnt_pending dòng): {$t2_ms} ms\n";

$t3 = microtime(true);
$cnt_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$t3_ms = round((microtime(true) - $t3) * 1000, 2);
echo "    -> Query danh sách Users ($cnt_users tài khoản): {$t3_ms} ms\n\n";

// 3. CHẨN ĐOÁN KHÓA PHIÊN (SESSION LOCK)
echo "[3] KIỂM TRA SESSION LOCKING:\n";
$t_sess = microtime(true);
@session_start();
$sess_id = session_id();
session_write_close();
$sess_ms = round((microtime(true) - $t_sess) * 1000, 2);
echo "    -> ID Session: $sess_id\n";
echo "    -> Thời gian Start & Close Session: {$sess_ms} ms\n\n";

// 4. CHẨN ĐOÁN KẾT NỐI REDIS
echo "[4] KIỂM TRA REDIS CACHE:\n";
$t_red = microtime(true);
require_once __DIR__ . '/includes/redis_queue.php';
$rq = RedisQueue::getInstance();
$is_red = $rq->isAvailable();
$red_ms = round((microtime(true) - $t_red) * 1000, 2);
echo "    -> Trạng thái Redis: " . ($is_red ? "HOẠT ĐỘNG (READY)" : "KHÔNG KHẢ DỤNG / BỎ QUA CACHE") . "\n";
echo "    -> Thời gian test Redis: {$red_ms} ms\n\n";

// 5. CHẨN ĐOÁN ENDPOINT AJAX DASHBOARD BẰNG CURL CỤC BỘ
echo "[5] TEST TỐC ĐỘ GỌI ENDPOINT AJAX KHI TRUY CẬP WEB:\n";
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');

function test_endpoint($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $t_start = microtime(true);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms = round((microtime(true) - $t_start) * 1000, 2);
    curl_close($ch);
    return ['code' => $code, 'ms' => $ms, 'size' => strlen($resp ?? '')];
}

$ep_db = test_endpoint($base_url . '/actions/ajax_dashboard_db.php');
echo "    -> Endpoint actions/ajax_dashboard_db.php: Status {$ep_db['code']} | Time: {$ep_db['ms']} ms | Size: {$ep_db['size']} bytes\n";

$ep_queue = test_endpoint($base_url . '/actions/ajax_dashboard_queue.php');
echo "    -> Endpoint actions/ajax_dashboard_queue.php: Status {$ep_queue['code']} | Time: {$ep_queue['ms']} ms | Size: {$ep_queue['size']} bytes\n\n";

// 6. DANH SÁCH TRUY VẤN MARIADB CHẬM ĐANG NGHỄN (PROCESSLIST)
echo "[6] DANH SÁCH TRUY VẤN CHẬM MARIADB ĐANG CHẠY:\n";
try {
    $procs = $pdo->query("SHOW FULL PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC);
    $slow_found = false;
    foreach ($procs as $pr) {
        if ($pr['Command'] !== 'Sleep' && $pr['Time'] >= 1) {
            $slow_found = true;
            echo "    -> ID {$pr['Id']} | Time: {$pr['Time']}s | State: {$pr['State']} | SQL: " . substr($pr['Info'], 0, 100) . "\n";
        }
    }
    if (!$slow_found) {
        echo "    -> [OK] KHÔNG CÓ TRUY VẤN NÀO BỊ NGHỄN HOẶC CHẠY QUÁ 1 GIÂY.\n";
    }
} catch (Exception $e) {
    echo "    -> Lỗi đọc Processlist: " . $e->getMessage() . "\n";
}

echo "\n=========================================================\n";
echo "   HOÀN TẤT BÁO CÁO CHẨN ĐOÁN SYSTEM BENCHMARK\n";
echo "=========================================================\n";
