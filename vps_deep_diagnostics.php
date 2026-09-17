<?php
// vps_deep_diagnostics.php
// Công cụ chẩn đoán chuyên sâu VPS & CSDL MariaDB thời gian thực

if (php_sapi_name() !== 'cli' && !isset($_GET['secret_key'])) {
    // Session check for web access
    session_start();
    if (!isset($_SESSION['account_id'])) {
        die("Unauthorized access.");
    }
}

require_once __DIR__ . '/includes/db.php';

echo "=======================================================\n";
echo "   VPS & MARIADB REAL-TIME DIAGNOSTIC TOOL\n";
echo "   Time: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

// 1. TẢI TÀI NGUYÊN HỆ THỐNG (CPU / RAM / SWAP)
echo "--- [1] TÀI NGUYÊN HỆ THỐNG (SYSTEM LOAD & MEMORY) ---\n";
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    echo "CPU Load Average (1m, 5m, 15m): " . implode(", ", array_map(function($n){ return round($n, 2); }, $load)) . "\n";
}

if (file_exists('/proc/meminfo')) {
    $meminfo = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $mt);
    preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $ma);
    preg_match('/SwapTotal:\s+(\d+)\s+kB/', $meminfo, $st);
    preg_match('/SwapFree:\s+(\d+)\s+kB/', $meminfo, $sf);
    
    $total_ram = isset($mt[1]) ? round($mt[1]/1024, 1) : 0;
    $avail_ram = isset($ma[1]) ? round($ma[1]/1024, 1) : 0;
    $total_swap = isset($st[1]) ? round($st[1]/1024, 1) : 0;
    $free_swap = isset($sf[1]) ? round($sf[1]/1024, 1) : 0;
    
    echo "RAM: $avail_ram MB free / $total_ram MB total\n";
    echo "SWAP: $free_swap MB free / $total_swap MB total\n";
}
echo "\n";

// 2. CẤU HÌNH CƠ SỞ DỮ LIỆU MARIADB (BUFFER POOL & CONNECTIONS)
echo "--- [2] CẤU HÌNH MARIADB & THÔNG SỐ RAM --- \n";
try {
    $vars = $pdo->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'")->fetch();
    if ($vars) {
        $pool_mb = round((int)$vars['Value'] / 1024 / 1024, 1);
        echo "InnoDB Buffer Pool Size: {$pool_mb} MB\n";
    }
    
    $max_conn = $pdo->query("SHOW VARIABLES LIKE 'max_connections'")->fetch();
    echo "Max Connections: " . ($max_conn['Value'] ?? 'Unknown') . "\n";
    
    $act_conn = $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch();
    echo "Threads Connected Hiện Tại: " . ($act_conn['Value'] ?? 'Unknown') . "\n";
    
    $run_conn = $pdo->query("SHOW STATUS LIKE 'Threads_running'")->fetch();
    echo "Threads Running (Đang xử lý): " . ($run_conn['Value'] ?? 'Unknown') . "\n";
} catch (Exception $e) {
    echo "Lỗi lấy thông số MariaDB: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. DANH SÁCH TIẾN TRÌNH MARIADB ĐANG CHẠY (SHOW FULL PROCESSLIST)
echo "--- [3] MARIADB PROCESSLIST THỜI GIAN THỰC (ĐOẠN TRUY VẤN ĐANG CHẠY/TẠM DỪNG) ---\n";
try {
    $stmt_pl = $pdo->query("SHOW FULL PROCESSLIST");
    $processes = $stmt_pl->fetchAll(PDO::FETCH_ASSOC);
    $running_count = 0;
    
    foreach ($processes as $proc) {
        if ($proc['Command'] === 'Sleep' && $proc['Time'] < 5) continue;
        $running_count++;
        $sql_snippet = !empty($proc['Info']) ? substr($proc['Info'], 0, 120) : '[NO SQL]';
        echo sprintf(
            "ID: %-6d | User: %-12s | Time: %-4ds | State: %-25s | Query: %s\n",
            $proc['Id'],
            $proc['User'],
            $proc['Time'],
            substr($proc['State'] ?? 'None', 0, 25),
            $sql_snippet
        );
    }
    
    if ($running_count === 0) {
        echo "Không có truy vấn nào bị treo hoặc chạy quá 5s vào lúc này.\n";
    }
} catch (Exception $e) {
    echo "Lỗi lấy Processlist: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. KIỂM TRA BẢNG CÓ BỊ METADATA LOCK HOẶC TABLE LOCK NÀO KHÔNG
echo "--- [4] KIỂM TRA LOCK BẢNG & TRẠNG THÁI KHÓA (TABLE LOCKS) ---\n";
try {
    $locks = $pdo->query("SHOW OPEN TABLES WHERE In_use > 0 OR Name_locked > 0")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($locks)) {
        echo "CÁC BẢNG ĐANG BỊ LOCK/IN_USE:\n";
        foreach ($locks as $l) {
            echo "  - DB: {$l['Database']} | Table: {$l['Table']} | In_use: {$l['In_use']} | Name_locked: {$l['Name_locked']}\n";
        }
    } else {
        echo "[OK] Không có bảng CSDL nào đang bị Lock (In_use = 0).\n";
    }
} catch (Exception $e) {
    echo "Lỗi kiểm tra Locks: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. KIỂM TRA DUNG LƯỢNG & INDEX BẢNG SCHEDULED_POSTS
echo "--- [5] KIỂM TRA THÔNG SỐ BẢNG SCHEDULED_POSTS ---\n";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM scheduled_posts")->fetchColumn();
    echo "Tổng số dòng trong scheduled_posts: " . number_format($count) . "\n";
    
    $idx_stmt = $pdo->query("SHOW INDEX FROM scheduled_posts");
    $indexes = [];
    while ($r = $idx_stmt->fetch(PDO::FETCH_ASSOC)) {
        $indexes[$r['Key_name']][] = $r['Column_name'];
    }
    
    echo "Các Index đang tồn tại:\n";
    foreach ($indexes as $name => $cols) {
        echo "  - $name: (" . implode(", ", $cols) . ")\n";
    }
} catch (Exception $e) {
    echo "Lỗi kiểm tra scheduled_posts: " . $e->getMessage() . "\n";
}
echo "\n";

// 6. KIỂM TRA LOG CẢNH BÁO / PHIÊN PHP HẰNG NGÀY
echo "--- [6] KIỂM TRA FILE SOCKET & LỖI HỆ THỐNG ---\n";
$sock = '/tmp/site_total.sock';
if (file_exists($sock)) {
    echo "[OK] File socket aaPanel /tmp/site_total.sock ĐÃ TỒN TẠI.\n";
} else {
    echo "[CẢNH BÁO] File socket /tmp/site_total.sock BỊ THIẾU (Gây lỗi Apache logger pipes!).\n";
}

echo "\n=======================================================\n";
echo "   CHẨN ĐOÁN HOÀN TẤT — HÃY COPY TOÀN BỘ KẾT QUẢ TRÊN!\n";
echo "=======================================================\n";
