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
            // Lấy TẤT CẢ account trong hệ thống để gửi thông báo chuông (và Telegram nếu có)
            $accounts = $pdo->query("SELECT id, username, telegram_bot_token, telegram_chat_id, expire_date FROM system_accounts")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($accounts)) return false;

            foreach ($accounts as $acc) {
                // Thống kê hôm qua cho account này
                $stmt = $pdo->prepare("
                    SELECT status, COUNT(*) as cnt
                    FROM scheduled_posts
                    WHERE account_id = ? AND DATE(scheduled_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                    GROUP BY status
                ");
                $stmt->execute([$acc['id']]);
                $today_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $published  = (int)($today_stats['published'] ?? 0);
                $failed     = (int)($today_stats['failed'] ?? 0);
                $pending    = (int)($today_stats['pending'] ?? 0);
                $processing = (int)($today_stats['processing'] ?? 0);

                // Tổng page hoạt động hôm qua
                $p_stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT page_id) FROM scheduled_posts 
                    WHERE account_id = ? AND DATE(scheduled_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status = 'published'
                ");
                $p_stmt->execute([$acc['id']]);
                $active_pages = $p_stmt->fetchColumn();

                $date = date('d/m/Y', strtotime('-1 day')); // Báo cáo ngày hôm qua
                $time = date('H:i');

                $message = "📊 BÁO CÁO NGÀY {$date}\n"
                         . "━━━━━━━━━━━━━━━━━━━━\n"
                         . "👤 Tài khoản: {$acc['username']}\n"
                         . "✅ Đã đăng: {$published} bài\n"
                         . "❌ Lỗi: {$failed} bài\n"
                         . "⏳ Đang chờ: {$pending} bài\n"
                         . "🔄 Đang xử lý: {$processing} bài\n"
                         . "📄 Page hoạt động: {$active_pages}\n"
                         . "━━━━━━━━━━━━━━━━━━━━\n"
                         . "🕐 Cập nhật lúc: {$time}";

                // 1. Lưu vào chuông thông báo (Bell notification)
                try {
                    $sys_page_id = 'SYSTEM_ACCOUNT_' . $acc['id'];
                    $notif_snippet = json_encode([
                        'type' => 'report',
                        'content' => $message
                    ], JSON_UNESCAPED_UNICODE);
                    $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_name, snippet) VALUES (?, 'report', 'Hệ thống', ?)")
                        ->execute([$sys_page_id, $notif_snippet]);
                } catch (Exception $e) {}

                // 2. Gửi Telegram nếu có cấu hình
                if (!empty($acc['telegram_bot_token']) && !empty($acc['telegram_chat_id'])) {
                    // Format lại message có HTML tags cho Telegram
                    $tg_message = "📊 <b>BÁO CÁO NGÀY {$date}</b>\n"
                             . "━━━━━━━━━━━━━━━━━━━━\n"
                             . "👤 Tài khoản: <b>{$acc['username']}</b>\n"
                             . "✅ Đã đăng: <b>{$published}</b> bài\n"
                             . "❌ Lỗi: <b>{$failed}</b> bài\n"
                             . "⏳ Đang chờ: <b>{$pending}</b> bài\n"
                             . "🔄 Đang xử lý: <b>{$processing}</b> bài\n"
                             . "📄 Page hoạt động: <b>{$active_pages}</b>\n"
                             . "━━━━━━━━━━━━━━━━━━━━\n"
                             . "🕐 Cập nhật lúc: {$time}";
                    _tg_send($acc['telegram_bot_token'], $acc['telegram_chat_id'], $tg_message);
                }

                // 3. KIỂM TRA SẮP HẾT HẠN (CẢNH BÁO)
                if (!empty($acc['expire_date'])) {
                    $now = time();
                    $expire_time = strtotime($acc['expire_date']);
                    $diff_seconds = $expire_time - $now;

                    // Chỉ báo cáo nếu còn <= 5 ngày
                    if ($diff_seconds > 0 && $diff_seconds <= (5 * 24 * 3600)) {
                        $days_left = floor($diff_seconds / (24 * 3600));
                        if ($days_left == 0) $days_left = 1; // Chưa tới 1 ngày
                        
                        $warning_msg = "Bạn còn {$days_left} ngày để sử dụng. Liên hệ admin 0967849934 để gia hạn.";
                        
                        // Lưu vào chuông
                        try {
                            $sys_page_id = 'SYSTEM_ACCOUNT_' . $acc['id'];
                            $notif_snippet = json_encode([
                                'type' => 'expire',
                                'content' => $warning_msg
                            ], JSON_UNESCAPED_UNICODE);
                            $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_name, snippet) VALUES (?, 'report', 'Cảnh báo', ?)")
                                ->execute([$sys_page_id, $notif_snippet]);
                        } catch (Exception $e) {}
                        
                        // Gửi qua telegram
                        if (!empty($acc['telegram_bot_token']) && !empty($acc['telegram_chat_id'])) {
                            $tg_warning = "⚠️ <b>CẢNH BÁO: SẮP HẾT HẠN SỬ DỤNG</b>\n{$warning_msg}";
                            _tg_send($acc['telegram_bot_token'], $acc['telegram_chat_id'], $tg_warning);
                        }
                    }
                }
            }
            return true;
        } catch (Exception $e) {
            @file_put_contents(__DIR__ . '/../telegram_error.txt', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }
}
?>
