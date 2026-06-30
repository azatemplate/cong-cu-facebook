<?php
// debug_cskh.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');

$search = isset($_GET['search']) ? trim($_GET['search']) : 'Thang Nguyen';

echo "<h2>Chẩn đoán dữ liệu CSKH cho: " . htmlspecialchars($search) . "</h2>";

// 1. Tìm trong fb_customers
echo "<h3>1. Tìm trong Facebook Customers (fb_customers)</h3>";
$stmt_fb = $pdo->prepare("SELECT * FROM fb_customers WHERE name LIKE ?");
$stmt_fb->execute(['%' . $search . '%']);
$fb_custs = $stmt_fb->fetchAll(PDO::FETCH_ASSOC);

if (empty($fb_custs)) {
    echo "<p>Không tìm thấy trong fb_customers.</p>";
} else {
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr><th>Page ID</th><th>Sender ID</th><th>Name</th><th>Phone</th><th>Province</th><th>Notes</th><th>Last Sender</th><th>Last Message At</th><th>Info Req At</th><th>Followup Req At</th><th>Req Count</th></tr>";
    foreach ($fb_custs as $c) {
        echo "<tr>";
        echo "<td>{$c['page_id']}</td>";
        echo "<td>{$c['sender_id']}</td>";
        echo "<td>" . htmlspecialchars($c['name']) . "</td>";
        echo "<td>{$c['phone']}</td>";
        echo "<td>{$c['province']}</td>";
        echo "<td>" . htmlspecialchars($c['notes']) . "</td>";
        echo "<td>{$c['last_sender']}</td>";
        echo "<td>{$c['last_message_at']}</td>";
        echo "<td>{$c['info_requested_at']}</td>";
        echo "<td>{$c['followup_requested_at']}</td>";
        echo "<td>{$c['info_request_count']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 2. Tìm trong zalo_customers
echo "<h3>2. Tìm trong Zalo Customers (zalo_customers)</h3>";
$stmt_za = $pdo->prepare("SELECT * FROM zalo_customers WHERE name LIKE ?");
$stmt_za->execute(['%' . $search . '%']);
$za_custs = $stmt_za->fetchAll(PDO::FETCH_ASSOC);

if (empty($za_custs)) {
    echo "<p>Không tìm thấy trong zalo_customers.</p>";
} else {
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr><th>OA ID</th><th>Sender ID</th><th>Name</th><th>Phone</th><th>Province</th><th>Notes</th><th>Last Sender</th><th>Last Message At</th><th>Info Req At</th><th>Followup Req At</th><th>Req Count</th></tr>";
    foreach ($za_custs as $c) {
        echo "<tr>";
        echo "<td>{$c['oa_id']}</td>";
        echo "<td>{$c['sender_id']}</td>";
        echo "<td>" . htmlspecialchars($c['name']) . "</td>";
        echo "<td>{$c['phone']}</td>";
        echo "<td>{$c['province']}</td>";
        echo "<td>" . htmlspecialchars($c['notes']) . "</td>";
        echo "<td>{$c['last_sender']}</td>";
        echo "<td>{$c['last_message_at']}</td>";
        echo "<td>{$c['info_requested_at']}</td>";
        echo "<td>{$c['followup_requested_at']}</td>";
        echo "<td>{$c['info_request_count']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 3. Xem lịch sử tin nhắn/thông báo gần đây
echo "<h3>3. Lịch sử thông báo/tin nhắn gần đây (page_notifications)</h3>";
$stmt_notif = $pdo->prepare("SELECT * FROM page_notifications WHERE sender_name LIKE ? OR snippet LIKE ? ORDER BY id DESC LIMIT 20");
$stmt_notif->execute(['%' . $search . '%', '%' . $search . '%']);
$notifs = $stmt_notif->fetchAll(PDO::FETCH_ASSOC);

if (empty($notifs)) {
    echo "<p>Không tìm thấy thông báo nào liên quan.</p>";
} else {
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr><th>ID</th><th>Page ID</th><th>Type</th><th>Sender Name</th><th>Snippet</th><th>Created At</th></tr>";
    foreach ($notifs as $n) {
        echo "<tr>";
        echo "<td>{$n['id']}</td>";
        echo "<td>{$n['page_id']}</td>";
        echo "<td>{$n['type']}</td>";
        echo "<td>" . htmlspecialchars($n['sender_name']) . "</td>";
        echo "<td>" . htmlspecialchars($n['snippet']) . "</td>";
        echo "<td>{$n['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
