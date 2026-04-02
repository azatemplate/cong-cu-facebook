<?php
// includes/config.php
// ============================================================
// Centralized configuration — edit this file only.
// KEEP THIS FILE OUTSIDE PUBLIC WEBROOT if possible,
// or at minimum protect it with .htaccess deny rules.
// ============================================================

// --- Database ---
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'facebooksever');
define('DB_USER', getenv('DB_USER') ?: 'facebooksever');
define('DB_PASS', getenv('DB_PASS') ?: 'MncfFCSdWBXkbrxN');
define('DB_CHARSET', 'utf8mb4');

// --- Cấu hình Timezone (Quan trọng để Cronjob chạy đúng với giờ người dùng) ---
date_default_timezone_set('Asia/Ho_Chi_Minh');

// --- Encryption ---
// IMPORTANT: Change this key in production! Keep it secret.
define('ENCRYPTION_KEY', '!@#SystemKey2026FacebookApp#@!');

// --- Environment ---
// Set to 'production' on live server to enable SSL verification etc.
define('APP_ENV', 'production'); // 'development' | 'production'
?>
