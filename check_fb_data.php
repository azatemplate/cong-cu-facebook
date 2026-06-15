<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

echo "=== DIAGNOSTIC: CHECK FB DATA & AVATAR ===\n\n";

try {
    // 1. Lấy thông tin Page đầu tiên
    $stmt_page = $pdo->query("SELECT page_id, name, access_token FROM pages LIMIT 1");
    $page = $stmt_page->fetch(PDO::FETCH_ASSOC);
    
    if (!$page) {
        echo "Lỗi: Không tìm thấy Page nào trong bảng 'pages'.\n";
        exit;
    }
    
    $page_id = $page['page_id'];
    $page_name = $page['name'];
    $token = decryptData($page['access_token']);
    
    echo "Đang kiểm thử với Page:\n";
    echo "  - Page ID: $page_id\n";
    echo "  - Tên Page: $page_name\n";
    echo "  - Token (đầu): " . substr($token, 0, 15) . "...\n\n";
    
    // 2. Lấy thông tin khách hàng gần nhất từ page_notifications
    $stmt_notif = $pdo->prepare("SELECT sender_id, sender_name FROM page_notifications WHERE page_id = ? AND sender_id != ? ORDER BY id DESC LIMIT 1");
    $stmt_notif->execute([$page_id, $page_id]);
    $cust = $stmt_notif->fetch(PDO::FETCH_ASSOC);
    
    if (!$cust) {
        echo "Lỗi: Không tìm thấy khách hàng nào nhắn tin cho Page này trong 'page_notifications'.\n";
        exit;
    }
    
    $sender_id = $cust['sender_id'];
    $sender_name = $cust['sender_name'];
    
    echo "Khách hàng gần nhất:\n";
    echo "  - Sender ID: $sender_id\n";
    echo "  - Tên hiển thị: $sender_name\n\n";
    
    // 3. Test Graph API /picture Edge
    $url_edge = "https://graph.facebook.com/v25.0/{$sender_id}/picture?type=normal&redirect=0&access_token={$token}";
    echo "1. Đang test /picture Edge qua URL:\n   $url_edge\n";
    
    $ch = curl_init($url_edge);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res_edge = curl_exec($ch);
    $code_edge = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "   HTTP Code: $code_edge\n";
    echo "   Phản hồi:\n   $res_edge\n\n";
    
    // 4. Test Graph API ?fields=profile_pic
    $url_fields = "https://graph.facebook.com/v25.0/{$sender_id}?fields=name,profile_pic&access_token={$token}";
    echo "2. Đang test ?fields=profile_pic qua URL:\n   $url_fields\n";
    
    $ch2 = curl_init($url_fields);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    $res_fields = curl_exec($ch2);
    $code_fields = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    
    echo "   HTTP Code: $code_fields\n";
    echo "   Phản hồi:\n   $res_fields\n\n";
    
} catch (Exception $e) {
    echo "Ngoại lệ: " . $e->getMessage() . "\n";
}
?>
