<?php
// debug_settings.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== FACEBOOK SETTINGS (system_accounts) ===\n";
try {
    $stmt_fb = $pdo->query("SELECT id, name, followup_request_enabled, followup_request_hours, followup_request_text FROM system_accounts");
    $fb_sets = $stmt_fb->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($fb_sets) . " rows.\n";
    foreach ($fb_sets as $s) {
        echo "ID: {$s['id']} | Name: {$s['name']} | Enabled: {$s['followup_request_enabled']} | Hours: {$s['followup_request_hours']}\n";
        echo "Text: {$s['followup_request_text']}\n";
        echo "--------------------------------------------------\n";
    }
} catch (Exception $e) {
    echo "ERROR FB: " . $e->getMessage() . "\n";
}

echo "\n=== ZALO SETTINGS (zalo_settings) ===\n";
try {
    $stmt_za = $pdo->query("SELECT account_id, followup_request_enabled, followup_request_hours, followup_request_text FROM zalo_settings");
    $za_sets = $stmt_za->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($za_sets) . " rows.\n";
    foreach ($za_sets as $s) {
        echo "Account ID: {$s['account_id']} | Enabled: {$s['followup_request_enabled']} | Hours: {$s['followup_request_hours']}\n";
        echo "Text: {$s['followup_request_text']}\n";
        echo "--------------------------------------------------\n";
    }
} catch (Exception $e) {
    echo "ERROR ZALO: " . $e->getMessage() . "\n";
}
