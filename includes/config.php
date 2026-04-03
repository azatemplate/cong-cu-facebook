<?php
// includes/config.php
// ============================================================
// Centralized configuration — edit this file only.
// Credentials are loaded from environment variables or .env file.
// NEVER hardcode passwords or secrets here.
// ============================================================

// ── Load .env file (if exists) ───────────────────────────────────────────────
(function () {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            // Remove surrounding quotes
            if (preg_match('/^["\'](.*)["\']$/', $value, $m)) {
                $value = $m[1];
            }
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
})();

// --- Database ---
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'facebooksever');
define('DB_USER', getenv('DB_USER') ?: 'facebooksever');
define('DB_PASS', getenv('DB_PASS') ?: '');  // MUST be set via .env or environment
define('DB_CHARSET', 'utf8mb4');

// --- Cấu hình Timezone (Quan trọng để Cronjob chạy đúng với giờ người dùng) ---
date_default_timezone_set('Asia/Ho_Chi_Minh');

// --- Encryption ---
// IMPORTANT: Set this via .env file. Must be kept secret and unique per installation.
define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: '');

// --- Environment ---
// Set to 'production' on live server to enable SSL verification etc.
define('APP_ENV', getenv('APP_ENV') ?: 'production');

// ── Session Security ─────────────────────────────────────────────────────────
// These must be set BEFORE session_start() is called.
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
// Enable secure cookie flag only in production with HTTPS
if (APP_ENV === 'production' && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
    ini_set('session.cookie_secure', 1);
}
// Session lifetime: 8 hours
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
?>
