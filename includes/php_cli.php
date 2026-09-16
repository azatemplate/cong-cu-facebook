<?php
/**
 * get_php_cli_bin()
 * Tự động nhận diện đường dẫn PHP CLI binary phù hợp nhất trên server.
 */
function get_php_cli_bin() {
    // 1. Windows OS
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $try = PHP_BINDIR . '\\php.exe';
        if (@file_exists($try)) return $try;
        return 'php';
    }

    // 2. Tự động khớp theo phiên bản PHP Web đang chạy (VD: PHP 7.4 -> 74, PHP 8.1 -> 81, PHP 8.2 -> 82)
    $ver_code = PHP_MAJOR_VERSION . PHP_MINOR_VERSION; // e.g. "74", "81", "82"
    $aapanel_ver_path = "/www/server/php/{$ver_code}/bin/php";
    if (@file_exists($aapanel_ver_path)) {
        return $aapanel_ver_path;
    }

    // 3. Kiểm tra từ PHP_BINDIR (tránh php-fpm)
    $bindir_php = rtrim(PHP_BINDIR, '/') . '/php';
    if (strpos($bindir_php, 'fpm') === false && strpos($bindir_php, 'cgi') === false && @file_exists($bindir_php)) {
        return $bindir_php;
    }

    // 4. Kiểm tra từ PHP_BINARY (nếu không phải fpm/cgi)
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $bin = PHP_BINARY;
        if (strpos($bin, 'php-fpm') === false && strpos($bin, 'php-cgi') === false && @file_exists($bin)) {
            return $bin;
        }
        $bin_clean = str_replace(['/sbin/php-fpm', 'php-fpm', 'php-cgi'], ['/bin/php', 'php', 'php'], $bin);
        if (@file_exists($bin_clean)) return $bin_clean;
    }

    // 5. Kiểm tra `which php`
    $w = trim((string)@exec('which php 2>/dev/null'));
    if ($w && @file_exists($w)) return $w;

    // 6. Quét các đường dẫn phổ biến trên Linux / aaPanel
    $common_paths = [
        '/usr/bin/php',
        '/usr/local/bin/php',
        "/www/server/php/{$ver_code}/bin/php",
        '/www/server/php/74/bin/php',
        '/www/server/php/80/bin/php',
        '/www/server/php/81/bin/php',
        '/www/server/php/82/bin/php',
        '/www/server/php/83/bin/php',
        '/www/server/php/84/bin/php',
        '/www/server/php/85/bin/php'
    ];
    foreach ($common_paths as $p) {
        if (@file_exists($p)) return $p;
    }

    // 7. Fallback aaPanel glob
    $found = glob('/www/server/php/*/bin/php');
    if (!empty($found)) {
        return end($found);
    }

    return 'php';
}
