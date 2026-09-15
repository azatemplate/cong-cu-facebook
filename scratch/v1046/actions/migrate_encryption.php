<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Only Admin can run this
if (!isset($_SESSION['account_id']) || $_SESSION['role'] !== 'admin') {
    die("Truy cập bị từ chối.");
}

$migrated_users = 0;
$migrated_pages = 0;
$migrated_ai = 0;

try {
    // 1. Migrate users
    $stmt = $pdo->query("SELECT id, access_token FROM users WHERE access_token IS NOT NULL AND access_token != ''");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        if (strpos($u['access_token'], 'ENC:') !== 0) {
            $encrypted = encryptData($u['access_token']);
            $upd = $pdo->prepare("UPDATE users SET access_token = ? WHERE id = ?");
            $upd->execute([$encrypted, $u['id']]);
            $migrated_users++;
        }
    }

    // 2. Migrate pages
    $stmt = $pdo->query("SELECT id, access_token FROM pages WHERE access_token IS NOT NULL AND access_token != ''");
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pages as $p) {
        if (strpos($p['access_token'], 'ENC:') !== 0) {
            $encrypted = encryptData($p['access_token']);
            $upd = $pdo->prepare("UPDATE pages SET access_token = ? WHERE id = ?");
            $upd->execute([$encrypted, $p['id']]);
            $migrated_pages++;
        }
    }

    // 3. Migrate ai_configs
    $stmt = $pdo->query("SELECT id, api_keys FROM ai_configs WHERE api_keys IS NOT NULL AND api_keys != ''");
    $ai_configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ai_configs as $ai) {
        if (strpos($ai['api_keys'], 'ENC:') !== 0) {
            $encrypted = encryptData($ai['api_keys']);
            $upd = $pdo->prepare("UPDATE ai_configs SET api_keys = ? WHERE id = ?");
            $upd->execute([$encrypted, $ai['id']]);
            $migrated_ai++;
        }
    }

    echo "<h3>Migration Hoàn Tất!</h3>";
    echo "<p>Số Users đã mã hóa: $migrated_users</p>";
    echo "<p>Số Pages đã mã hóa: $migrated_pages</p>";
    echo "<p>Số AI Configs đã mã hóa: $migrated_ai</p>";
    echo "<br><a href='../index.php'>Về Trang Chủ</a>";

} catch (Exception $e) {
    die("Lỗi migrate: " . $e->getMessage());
}
?>
