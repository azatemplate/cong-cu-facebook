<?php
// Test file - truy cập trực tiếp trên trình duyệt để xem lỗi
session_start();
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Session account_id: " . ($_SESSION['account_id'] ?? 'NOT SET') . "\n\n";

// Test bot_chat_locks table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM bot_chat_locks");
    echo "=== bot_chat_locks columns ===\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} catch (PDOException $e) {
    echo "ERROR bot_chat_locks: " . $e->getMessage() . "\n";
}

echo "\n";

// Test zalo_chat_locks table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM zalo_chat_locks");
    echo "=== zalo_chat_locks columns ===\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} catch (PDOException $e) {
    echo "ERROR zalo_chat_locks: " . $e->getMessage() . "\n";
}

echo "\n";

// Test a manual toggle
echo "=== Test INSERT ===\n";
try {
    $stmt = $pdo->prepare("INSERT INTO bot_chat_locks (page_id, sender_id, expire_at) VALUES ('test_page', 'test_sender', '2099-12-31 23:59:59') ON DUPLICATE KEY UPDATE expire_at = '2099-12-31 23:59:59'");
    $stmt->execute();
    echo "INSERT OK\n";

    $stmt2 = $pdo->prepare("DELETE FROM bot_chat_locks WHERE page_id = 'test_page' AND sender_id = 'test_sender'");
    $stmt2->execute();
    echo "DELETE OK\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\nAll tests passed!";
