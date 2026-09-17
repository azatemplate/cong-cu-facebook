<?php
/**
 * get_php_cli_bin()
 * Tự động nhận diện đường dẫn PHP CLI binary trên server.
 * Logic lấy từ diagnostics.php — dùng chung cho start_publish, start_comment, accounts...
 */
function get_php_cli_bin() {
    // 1. Thử nhận diện theo phiên bản PHP đang chạy (ví dụ aaPanel /www/server/php/74/bin/php)
    if (defined('PHP_MAJOR_VERSION') && defined('PHP_MINOR_VERSION')) {
        $ver_bin = '/www/server/php/' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/bin/php';
        if (@file_exists($ver_bin)) {
            return $ver_bin;
        }
    }

    // 2. Từ PHP_BINARY (nếu đang chạy CLI hoặc fpm/cgi)
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $bin = PHP_BINARY;
        if (strpos($bin, 'php-fpm') === false && strpos($bin, 'php-cgi') === false) {
            if (@file_exists($bin)) return $bin;
        }
        // Thử chuyển php-fpm/php-cgi → php
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $bin = str_ireplace(['php-cgi.exe', 'php-win.exe'], 'php.exe', $bin);
        } else {
            $bin = str_replace('/sbin/php-fpm', '/bin/php', $bin);
            $bin = str_replace(['php-fpm', 'php-cgi'], 'php', $bin);
        }
        if (@file_exists($bin)) return $bin;
    }

    // 3. Fallback: quét tất cả phiên bản PHP trên aaPanel
    $found = glob('/www/server/php/*/bin/php');
    if (!empty($found)) {
        foreach (array_reverse($found) as $f) {
            if (@file_exists($f)) return $f;
        }
    }

    // 4. Đường dẫn phổ biến khác
    foreach (['/usr/bin/php', '/usr/local/bin/php'] as $p) {
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

    return 'php'; // fallback cuối
}
