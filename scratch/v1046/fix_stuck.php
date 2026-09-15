<?php
// fix_stuck.php - Dọn lock + kích hoạt lại tiến trình đăng bài
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/php_cli.php';
header('Content-Type: text/plain; charset=utf-8');

// 1. Xóa TẤT CẢ lock file publish
$lock_dir = __DIR__ . '/locks';
$locks = glob($lock_dir . '/publish_user_*.lock') ?: [];
echo "=== BƯỚC 1: Dọn sạch lock files ===\n";
echo "Tìm thấy " . count($locks) . " lock file.\n";
foreach ($locks as $lf) {
    $ok = @unlink($lf);
    echo ($ok ? "[XÓA OK]" : "[THẤT BẠI]") . " " . basename($lf) . "\n";
}

// 2. Đếm bài pending quá hạn theo account
echo "\n=== BƯỚC 2: Thống kê bài pending quá hạn ===\n";
$stmt = $pdo->query("
    SELECT sa.id, sa.username, sa.expire_date, COUNT(sp.id) as pending_count
    FROM scheduled_posts sp
    JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.status = 'pending' AND sp.scheduled_time <= NOW()
    GROUP BY sa.id
    ORDER BY pending_count DESC
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $expired = (!empty($r['expire_date']) && strtotime($r['expire_date']) < time()) ? ' [HẾT HẠN]' : ' [CÒN HẠN]';
    echo "- {$r['username']} (ID:{$r['id']}): {$r['pending_count']} bài pending | Hạn: " . ($r['expire_date'] ?: 'Không giới hạn') . $expired . "\n";
}

// 3. Kích hoạt start_publish.php nền
echo "\n=== BƯỚC 3: Kích hoạt tiến trình đăng bài ===\n";
$php_bin = get_php_cli_bin();
echo "PHP CLI Binary: $php_bin\n";
echo "File exists: " . (file_exists($php_bin) ? "YES" : "NO") . "\n";

$script = __DIR__ . '/cron/start_publish.php';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    pclose(popen("start /B \"\" \"$php_bin\" \"$script\"", "r"));
} else {
    exec("\"$php_bin\" \"$script\" > /dev/null 2>&1 &");
}
echo "→ Đã kích hoạt start_publish.php chạy nền!\n";

echo "\n=== HOÀN TẤT ===\n";
echo "Chờ 1-2 phút rồi kiểm tra diagnostics.php, số bài pending sẽ giảm dần.\n";
