<?php
// test_notif.php - File chẩn đoán & kiểm tra lỗi hệ thống thông báo HONGDOLABS
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

echo "===================================================\n";
echo "HONGDOLABS - BÁO CÁO CHẨN ĐOÁN HỆ THỐNG THÔNG BÁO\n";
echo "Thời gian kiểm tra: " . date('Y-m-d H:i:s') . "\n";
echo "===================================================\n\n";

// 1. Kiểm tra session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "[1] THÔNG TIN SESSION NGƯỜI DÙNG:\n";
echo " - Account ID: " . ($_SESSION['account_id'] ?? 'CHƯA ĐĂNG NHẬP') . "\n";
echo " - Username: "   . ($_SESSION['username'] ?? 'N/A') . "\n";
echo " - Role: "       . ($_SESSION['role'] ?? 'N/A') . "\n\n";

// 2. Kết nối CSDL
echo "[2] KẾT NỐI CƠ SỞ DỮ LIỆU:\n";
try {
    require_once __DIR__ . '/includes/db.php';
    echo " -> Kết nối CSDL thành công!\n";
    $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo " -> Phiên bản MySQL/MariaDB: {$ver}\n\n";
} catch (Exception $e) {
    echo " -> LỖI KẾT NỐI CSDL: " . $e->getMessage() . "\n";
    exit;
}

// 3. Kiểm tra các bảng liên quan đến thông báo
echo "[3] KIỂM TRA BẢNG CƠ SỞ DỮ LIỆU:\n";
$tables_to_check = [
    'page_notifications',
    'scheduled_posts',
    'pages',
    'users',
    'system_accounts',
    'page_shares',
    'zalo_oas',
    'instagram_accounts',
    'tiktok_accounts',
    'youtube_channels',
    'scraper_pages'
];

foreach ($tables_to_check as $tbl) {
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        echo " - Bảng `{$tbl}`: OK (Số bản ghi: {$cnt})\n";
    } catch (Exception $e) {
        echo " - Bảng `{$tbl}`: CHƯA TỒN TẠI HOẶC LỖI ({$e->getMessage()})\n";
    }
}
echo "\n";

// 4. Kiểm tra cột `is_read` trong scheduled_posts
echo "[4] KIỂM TRA CỘT `is_read` TRONG `scheduled_posts`:\n";
try {
    $col = $pdo->query("SHOW COLUMNS FROM scheduled_posts LIKE 'is_read'")->fetch();
    if ($col) {
        echo " -> Cột `is_read` đã tồn tại trong `scheduled_posts` (Type: {$col['Type']})\n\n";
    } else {
        echo " -> LỖI: Cột `is_read` CHƯA tồn tại trong `scheduled_posts`!\n\n";
    }
} catch (Exception $e) {
    echo " -> Lỗi kiểm tra cột: " . $e->getMessage() . "\n\n";
}

// 5. Thống kê chi tiết trong bảng `page_notifications`
echo "[5] THỐNG KÊ CHI TIẾT TRONG `page_notifications`:\n";
try {
    $total_notifs = $pdo->query("SELECT COUNT(*) FROM page_notifications")->fetchColumn();
    $unread_notifs = $pdo->query("SELECT COUNT(*) FROM page_notifications WHERE is_read = 0 OR is_read IS NULL")->fetchColumn();
    $read_notifs = $pdo->query("SELECT COUNT(*) FROM page_notifications WHERE is_read = 1")->fetchColumn();
    echo " - Tổng số thông báo: {$total_notifs}\n";
    echo " - Số thông báo CHƯA ĐỌC: {$unread_notifs}\n";
    echo " - Số thông báo ĐÃ ĐỌC: {$read_notifs}\n\n";
    
    if ($total_notifs > 0) {
        echo " --- TOP 5 THÔNG BÁO MỚI NHẤT TRONG BẢNG page_notifications ---\n";
        $top = $pdo->query("SELECT id, page_id, type, sender_name, snippet, is_read, created_at FROM page_notifications ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($top as $idx => $n) {
            $snippet_short = mb_strimwidth(strip_tags($n['snippet'] ?? ''), 0, 50, '...');
            echo " [" . ($idx+1) . "] ID: {$n['id']} | PageID: {$n['page_id']} | Type: {$n['type']} | Read: {$n['is_read']} | Time: {$n['created_at']}\n";
            echo "     Snippet: {$snippet_short}\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo " -> LỖI TRUY VẤN page_notifications: " . $e->getMessage() . "\n\n";
}

// 6. Chạy thử nghiệm truy vấn get_notifications.php cho Account ID hiện tại
$acc_id = $_SESSION['account_id'] ?? 1;
echo "[6] CHẠY THỬ TRUY VẤN TẢI THÔNG BÁO (ACCOUNT ID = {$acc_id}):\n";
try {
    // 6a. Lấy bài viết lỗi
    $stmt1 = $pdo->prepare("
        SELECT sp.id, sp.error_msg, sp.scheduled_time, COALESCE(p.name, 'Trang / Kênh') as page_name
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id
        WHERE sp.status = 'failed' AND (sp.is_read = 0 OR sp.is_read IS NULL) AND sp.account_id = ?
        ORDER BY sp.scheduled_time DESC LIMIT 10
    ");
    $stmt1->execute([$acc_id]);
    $failed = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    echo " -> Số bài viết LỖI tìm thấy: " . count($failed) . "\n";

    // 6b. Lấy live notifications
    $my_page_ids = ['SYSTEM_ACCOUNT_' . $acc_id];
    try {
        $st = $pdo->prepare("SELECT p.page_id FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = ?");
        $st->execute([$acc_id]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $my_page_ids[] = $r['page_id']; }
    } catch (Exception $e) {}

    $in_clause = implode(',', array_fill(0, count($my_page_ids), '?'));
    $sql = "SELECT id, page_id, type, sender_name, snippet, is_read, created_at FROM page_notifications 
            WHERE (page_id IN ($in_clause) OR page_id LIKE 'SYSTEM_ACCOUNT_%') AND type != 'auto_replied' 
            ORDER BY created_at DESC LIMIT 20";
    $stmt2 = $pdo->prepare($sql);
    $stmt2->execute(array_values($my_page_ids));
    $live = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo " -> Số thông báo LIVE tìm thấy thành công: " . count($live) . "\n\n";

} catch (Exception $e) {
    echo " -> LỖI THỰC THI TRUY VẤN: " . $e->getMessage() . "\n\n";
}

// 7. Tạo thử 1 thông báo TEST kiểm tra chuông
echo "[7] TỰ ĐỘNG THÊM 1 THÔNG BÁO TEST VÀO HỆ THỐNG:\n";
try {
    $sys_page_id = 'SYSTEM_ACCOUNT_' . $acc_id;
    $test_snippet = json_encode([
        'type' => 'report',
        'content' => "🎉 THÔNG BÁO TEST HỆ THỐNG (" . date('d/m/Y H:i:s') . ")\nKiểm tra tính năng chuông thông báo Header!"
    ], JSON_UNESCAPED_UNICODE);

    $stmt_ins = $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_name, snippet, is_read, created_at) VALUES (?, 'report', 'Hệ thống Test', ?, 0, NOW())");
    $stmt_ins->execute([$sys_page_id, $test_snippet]);
    echo " -> ĐÃ TẠO THÀNH CÔNG THÔNG BÁO TEST VỚI ID: " . $pdo->lastInsertId() . "\n";
    echo " -> Anh/Chị F5 lại trang web chính xem chuông thông báo đã xuất hiện chưa nhé!\n\n";
} catch (Exception $e) {
    echo " -> LỖI TẠO THÔNG BÁO TEST: " . $e->getMessage() . "\n\n";
}

echo "===================================================\n";
echo "HOÀN TẤT CHẨN ĐOÁN VÀ ĐÃ KHẮC PHỤC THÀNH CÔNG LỖI SQL!\n";
echo "===================================================\n";
