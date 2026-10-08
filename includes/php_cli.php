<?php
/**
 * get_php_cli_bin()
 * Tự động nhận diện đường dẫn PHP CLI binary trên server.
 * Logic lấy từ diagnostics.php — dùng chung cho start_publish, start_comment, accounts...
 */
function get_php_cli_bin() {
    // 1. Tự động nhận diện đường dẫn PHP CLI theo đúng phiên bản PHP Web/System đang chạy (VD: PHP 8.5 => /www/server/php/85/bin/php)
    $ver_num = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    $vps_aa_php = "/www/server/php/{$ver_num}/bin/php";
    if (@file_exists($vps_aa_php)) {
        return $vps_aa_php;
    }

    // 2. Từ PHP_BINARY đang chạy (nếu không phải fpm/cgi)
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $bin = PHP_BINARY;
        if (strpos($bin, 'php-fpm') === false && strpos($bin, 'php-cgi') === false) {
            if (@file_exists($bin)) return $bin;
        }
        // Thử chuyển php-fpm/php-cgi → bin/php
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $bin = str_ireplace(['php-cgi.exe', 'php-win.exe'], 'php.exe', $bin);
        } else {
            $bin = str_replace('/sbin/php-fpm', '/bin/php', $bin);
            $bin = str_replace(['php-fpm', 'php-cgi'], 'php', $bin);
        }
        if (@file_exists($bin)) return $bin;
    }

    // 3. Tự động quét các bản PHP trên aaPanel VPS (Ưu tiên 8.5 -> 8.4 -> 8.3 -> ... -> 7.4)
    foreach (['85', '84', '83', '82', '81', '80', '74'] as $v) {
        $p = "/www/server/php/{$v}/bin/php";
        if (@file_exists($p)) return $p;
    }

    // 4. Đường dẫn phổ biến khác trên Linux VPS
    foreach (['/usr/bin/php8.5', '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php', '/usr/local/bin/php'] as $p) {
        if (@file_exists($p)) return $p;
    }

    // 5. Windows: thử PHP_BINDIR
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $try = PHP_BINDIR . '\\php.exe';
        if (@file_exists($try)) return $try;
    }

    // 6. Cuối cùng: `which php`
    $w = trim((string)@exec('which php 2>/dev/null'));
    if ($w && @file_exists($w)) return $w;

    return 'php'; // fallback mặc định
}
