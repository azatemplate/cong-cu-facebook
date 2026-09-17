<?php
// verify_db_settings.php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== VERIFYING SYSTEM SETTINGS ===\n\n";

try {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rows)) {
        foreach ($rows as $r) {
            echo "Key: {$r['setting_key']} | Value: {$r['setting_value']}\n";
        }
    } else {
        echo "Table system_settings is empty.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
