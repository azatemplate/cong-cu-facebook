<?php
require_once __DIR__ . '/includes/db.php';
echo "<h2>Kiểm tra thông báo gần nhất</h2>";
$stmt = $pdo->query("SELECT id, type, sender_name, snippet, created_at, comment_id FROM page_notifications ORDER BY id DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%; font-family: sans-serif;'>";
echo "<tr style='background: #eee;'><th>ID</th><th>Loại</th><th>Người gửi</th><th>Nội dung</th><th>Thời gian</th><th>Comment ID</th></tr>";
foreach ($rows as $r) {
    echo "<tr>";
    echo "<td>{$r['id']}</td>";
    echo "<td>" . htmlspecialchars($r['type']) . "</td>";
    echo "<td>" . htmlspecialchars($r['sender_name'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($r['snippet'] ?? '') . "</td>";
    echo "<td>{$r['created_at']}</td>";
    echo "<td>" . htmlspecialchars($r['comment_id'] ?? '') . "</td>";
    echo "</tr>";
}
echo "</table>";
?>
