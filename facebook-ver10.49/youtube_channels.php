<?php
// youtube_channels.php - Tự động chuyển hướng về Tab Quản Lý Kênh trong youtube.php
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header("Location: youtube.php?tab=channels");
exit;
