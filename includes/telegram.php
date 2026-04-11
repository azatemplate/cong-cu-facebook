<?php
// includes/telegram.php
// Helper gửi thông báo qua Telegram Bot — per-user (mỗi tài khoản riêng)

if (!function_exists('send_telegram_notification')) {
    /**
     * Gửi tin nhắn trực tiếp đến 1 bot + chat_id cụ thể
     */
    function _tg_send($bot_token, $chat_id, $message) {
        if (empty($bot_token) || empty($chat_id)) return false;
        try {
            $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            $data = [
                'chat_id'    => $chat_id,
                'text'       => $message,
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
     * Gửi thông báo qua Telegram Bot API cho 1 account cụ thể
     * 
     * @param PDO    $pdo        PDO database connection
     * @param int    $account_id ID tài khoản nhận thông báo
     * @param string $message    Nội dung tin nhắn (hỗ trợ HTML)
     * @param string $type       Loại thông báo: 'publish', 'comment', 'error', 'report'
     * @return bool
     */
    function send_telegram_notification($pdo, $account_id, $message, $type = 'general') {
        try {
            $stmt = $pdo->prepare("SELECT telegram_bot_token, telegram_chat_id FROM system_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$acc || empty($acc['telegram_bot_token']) || empty($acc['telegram_chat_id'])) {
                return false; // User chưa cấu hình, bỏ qua im lặng
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

            return _tg_send($acc['telegram_bot_token'], $acc['telegram_chat_id'], $full_message);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Gửi báo cáo ngày qua Telegram cho TẤT CẢ user đã cấu hình Telegram
     */
    function send_telegram_daily_report($pdo) {
        try {
            // Lấy tất cả account có cấu hình Telegram
            $accounts = $pdo->query("
                SELECT id, username, telegram_bot_token, telegram_chat_id 
                FROM system_accounts 
                WHERE telegram_bot_token IS NOT NULL AND telegram_bot_token != ''
                  AND telegram_chat_id IS NOT NULL AND telegram_chat_id != ''
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($accounts)) return false;

            foreach ($accounts as $acc) {
                // Thống kê hôm nay cho account này
                $stmt = $pdo->prepare("
                    SELECT status, COUNT(*) as cnt
                    FROM scheduled_posts
                    WHERE account_id = ? AND DATE(scheduled_time) = CURDATE()
                    GROUP BY status
                ");
                $stmt->execute([$acc['id']]);
                $today_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $published  = (int)($today_stats['published'] ?? 0);
                $failed     = (int)($today_stats['failed'] ?? 0);
                $pending    = (int)($today_stats['pending'] ?? 0);
                $processing = (int)($today_stats['processing'] ?? 0);

                // Tổng page hoạt động hôm nay
                $p_stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT page_id) FROM scheduled_posts 
                    WHERE account_id = ? AND DATE(scheduled_time) = CURDATE() AND status = 'published'
                ");
                $p_stmt->execute([$acc['id']]);
                $active_pages = $p_stmt->fetchColumn();

                $date = date('d/m/Y');
                $time = date('H:i');

                $message = "📊 <b>BÁO CÁO NGÀY {$date}</b>\n"
                         . "━━━━━━━━━━━━━━━━━━━━\n"
                         . "👤 Tài khoản: <b>{$acc['username']}</b>\n"
                         . "✅ Đã đăng: <b>{$published}</b> bài\n"
                         . "❌ Lỗi: <b>{$failed}</b> bài\n"
                         . "⏳ Đang chờ: <b>{$pending}</b> bài\n"
                         . "🔄 Đang xử lý: <b>{$processing}</b> bài\n"
                         . "📄 Fanpage hoạt động: <b>{$active_pages}</b>\n"
                         . "━━━━━━━━━━━━━━━━━━━━\n"
                         . "🕐 Cập nhật lúc: {$time}";

                _tg_send($acc['telegram_bot_token'], $acc['telegram_chat_id'], $message);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>
