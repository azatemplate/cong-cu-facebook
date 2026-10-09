<?php
// cron/clean_overdue_30m.php
// Script xóa toàn bộ bài quá hạn > 30 phút, giữ lại bài trễ <= 30 phút.

require_once __DIR__ . '/../includes/db.php';

try {
    $stmt = $pdo->prepare("
        DELETE FROM scheduled_posts 
        WHERE status IN ('pending', 'failed') 
          AND scheduled_time < NOW() - INTERVAL 30 MINUTE
    ");
    $stmt->execute();
    $cnt = $stmt->rowCount();
    echo "=========================================\n";
    echo " -> Thanh cong: Da xoa {$cnt} bai qua han > 30 phut!\n";
    echo " -> Cac bai tre <= 30 phut da duoc giu lai an toan.\n";
    echo "=========================================\n";
} catch (Exception $e) {
    echo "Loi xoa bai: " . $e->getMessage() . "\n";
}
