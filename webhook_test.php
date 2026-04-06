<?php
require_once __DIR__ . '/includes/db.php';
echo "<pre>";

// Kiểm tra page IDs của webhook có trong bảng pages không
$webhook_page_ids = ['148579325014720', '385520708717160', '783833318136925', '2216507885096928', '1581358195505357'];
echo "--- Kiểm tra các Page từ Webhook trong bảng pages ---\n";
foreach ($webhook_page_ids as $pid) {
    $row = $pdo->prepare("SELECT page_id, name, user_id FROM pages WHERE page_id = ?");
    $row->execute([$pid]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        echo "✅ FOUND: $pid - {$r['name']} (user_id={$r['user_id']})\n";
    } else {
        echo "❌ NOT FOUND: $pid\n";
    }
}

echo "\n--- Tổng số pages trong DB ---\n";
$total = $pdo->query("SELECT COUNT(*) FROM pages")->fetchColumn();
echo "Tổng: $total pages\n";

echo "\n--- 10 pages MỚI NHẤT trong DB ---\n";
$rows = $pdo->query("SELECT p.page_id, p.name FROM pages ORDER BY p.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "• {$r['page_id']} - {$r['name']}\n";
}
echo "</pre>";
