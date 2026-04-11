<?php
// includes/telegram.php
// Helper gửi thông báo qua Telegram Bot

if (!function_exists('send_telegram_notification')) {
    /**
     * Gửi thông báo qua Telegram Bot API
     * 
     * @param PDO    $pdo     PDO database connection
     * @param string $message Nội dung tin nhắn (hỗ trợ HTML)
     * @param string $type    Loại thông báo: 'publish', 'comment', 'error', 'report'
     * @return bool
     */
    function send_telegram_notification($pdo, $message, $type = 'general') {
        try {
            $bot_token = '';
            $chat_id = '';

            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id')");
            if ($stmt) {
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $bot_token = trim($rows['telegram_bot_token'] ?? '');
                $chat_id = trim($rows['telegram_chat_id'] ?? '');
            }

            if (empty($bot_token) || empty($chat_id)) {
                return false; // Chưa cấu hình, bỏ qua im lặng
            }

            // Thêm emoji prefix theo loại thông báo
            $icons = [
                'publish'  => '✅',
                'comment'  => '💬',
                'error'    => '❌',
                'report'   => '📊',
                'general'  => '🔔',
            ];
            $icon = $icons[$type] ?? '🔔';
            $full_message = $icon . ' ' . $message;

            $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            $data = [
                'chat_id'    => $chat_id,
                'text'       => $full_message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($data),
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($http_code === 200);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Gửi báo cáo ngày qua Telegram
     */
    function send_telegram_daily_report($pdo) {
        try {
            // Thống kê hôm nay
            $today_stats = $pdo->query("
                SELECT status, COUNT(*) as cnt
                FROM scheduled_posts
                WHERE DATE(scheduled_time) = CURDATE()
                GROUP BY status
            ")->fetchAll(PDO::FETCH_KEY_PAIR);

            $published = (int)($today_stats['published'] ?? 0);
            $failed    = (int)($today_stats['failed'] ?? 0);
            $pending   = (int)($today_stats['pending'] ?? 0);
            $processing = (int)($today_stats['processing'] ?? 0);

            // Tổng page hoạt động hôm nay
            $active_pages = $pdo->query("
                SELECT COUNT(DISTINCT page_id) FROM scheduled_posts 
                WHERE DATE(scheduled_time) = CURDATE() AND status = 'published'
            ")->fetchColumn();

            $date = date('d/m/Y');
            $time = date('H:i');

            $message = "<b>📊 BÁO CÁO NGÀY {$date}</b>\n"
                     . "━━━━━━━━━━━━━━━━━━━━\n"
                     . "✅ Đã đăng: <b>{$published}</b> bài\n"
                     . "❌ Lỗi: <b>{$failed}</b> bài\n"
                     . "⏳ Đang chờ: <b>{$pending}</b> bài\n"
                     . "🔄 Đang xử lý: <b>{$processing}</b> bài\n"
                     . "📄 Fanpage hoạt động: <b>{$active_pages}</b>\n"
                     . "━━━━━━━━━━━━━━━━━━━━\n"
                     . "🕐 Cập nhật lúc: {$time}";

            return send_telegram_notification($pdo, $message, 'report');
        } catch (Exception $e) {
            return false;
        }
    }
}
?>
