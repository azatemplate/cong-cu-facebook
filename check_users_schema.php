<?php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

$stmt = $pdo->query("SHOW COLUMNS FROM users");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($cols as $c) {
    echo $c['Field'] . " - " . $c['Type'] . "\n";
}
?>
