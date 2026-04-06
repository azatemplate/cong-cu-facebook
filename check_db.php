<?php
require_once __DIR__ . '/includes/db.php';
$stmt = $pdo->query("SELECT * FROM page_notifications ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
