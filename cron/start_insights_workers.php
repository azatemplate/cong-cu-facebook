<?php
// cron/start_insights_workers.php
// Master Launcher to run multiple parallel instances of comment_insights_worker.php

if (file_exists(__DIR__ . '/../includes/php_cli.php')) {
    require_once __DIR__ . '/../includes/php_cli.php';
}

$num_workers = isset($argv[1]) && is_numeric($argv[1]) ? max(1, intval($argv[1])) : 5;
$is_force = false;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if ($arg === '--force' || $arg === 'force') {
            $is_force = true;
            break;
        }
    }
}

$php_bin = function_exists('get_php_cli_bin') ? get_php_cli_bin() : 'php';
$script = __DIR__ . '/comment_insights_worker.php';
$log_file = '/tmp/fb_comment_insights.log';

echo "======================================================\n";
echo "🚀 KHỞI CHẠY ĐA LUỒNG INSIGHTS WORKERS ({$num_workers} LUỒNG SONG SONG)" . ($is_force ? " [FORCE MODE]" : "") . "\n";
echo "======================================================\n";

$force_arg = $is_force ? ' --force' : '';

for ($i = 0; $i < $num_workers; $i++) {
    $cmd_arg = "--thread={$i} --total-threads={$num_workers}{$force_arg}";
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        @pclose(@popen("start /B \"\" \"{$php_bin}\" \"{$script}\" {$cmd_arg}", "r"));
    } else {
        @exec("nohup \"{$php_bin}\" \"{$script}\" {$cmd_arg} >> {$log_file} 2>&1 &");
    }
    echo "  → [Luồng {$i}/{$num_workers}] Đã bật worker ngầm: php comment_insights_worker.php --thread={$i} --total-threads={$num_workers}\n";
}

echo "======================================================\n";
echo "🎉 TẤT CẢ {$num_workers} LUỒNG ĐÃ ĐƯỢC KÍCH HOẠT CHẠY SONG SONG TRÊN VPS!\n";
echo "======================================================\n";

