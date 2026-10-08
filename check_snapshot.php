<?php
require 'd:/pagespeed/hi/facebook/includes/db.php';
$stmt = $pdo->query("SELECT snapshot_date, total_posts FROM dashboard_snapshots WHERE account_id = '0' ORDER BY snapshot_date DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
