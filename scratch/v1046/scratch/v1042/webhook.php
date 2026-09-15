<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/ai_rewriter.php';

                                             // Do not retry on 4xx client errors
$verify_token = 'HVP_WEBHOOK_VERIFY_TOKEN_2026';                                              // Do not retry on 4xx client errors

                                             // Do not retry on 4xx client errors
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hub_mode      = $_GET['hub_mode'] ?? '';
    $hub_verify    = $_GET['hub_verify_token'] ?? '';
    $hub_challenge = $_GET['hub_challenge'] ?? '';

    if ($hub_mode === 'subscribe' && $hub_verify === $verify_token) {
                                                     // Do not retry on 4xx client errors
        echo $hub_challenge;
        http_response_code(200);
        exit;
    } else {
        http_response_code(403);
        echo "Token xác minh không khớp.";
        exit;
    }
}

                                             // Do not retry on 4xx client errors
                                             // Do not retry on 4xx client errors
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

// Debug logging
@file_put_contents(__DIR__ . '/webhook_debug.txt', date('Y-m-d H:i:s') . "\n" . $input . "\n\n", FILE_APPEND);

// DB error logging helper
function webhook_log($msg) {
    @file_put_contents(__DIR__ . '/webhook_db_errors.txt', date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
}

function detect_vietnam_province($text) {
    $provinces = [
        'An Giang', 'Bà Rịa - Vũng Tàu', 'Bà Rịa Vũng Tàu', 'Vũng Tàu', 'Bắc Giang', 'Bắc Kạn', 'Bạc Liêu', 'Bắc Ninh', 'Bến Tre', 'Bình Định', 
        'Bình Dương', 'Bình Phước', 'Bình Thuận', 'Cà Mau', 'Cần Thơ', 'Cao Bằng', 'Đà Nẵng', 'Đắk Lắk', 'Đắk Nông', 'Điện Biên', 
        'Đồng Nai', 'Đồng Tháp', 'Gia Lai', 'Hà Giang', 'Hà Nam', 'Hà Nội', 'Hà Tĩnh', 'Hải Dương', 'Hải Phòng', 'Hậu Giang', 
        'Hòa Bình', 'Hưng Yên', 'Khánh Hòa', 'Nha Trang', 'Kiên Giang', 'Kon Tum', 'Lai Châu', 'Lâm Đồng', 'Đà Lạt', 'Lạng Sơn', 
        'Lào Cai', 'Long An', 'Nam Định', 'Nghệ An', 'Ninh Bình', 'Ninh Thuận', 'Phú Thọ', 'Phú Yên', 'Quảng Bình', 'Quảng Nam', 
        'Quảng Ngãi', 'Quảng Ninh', 'Quảng Trị', 'Sóc Trăng', 'Sơn La', 'Tây Ninh', 'Thái Bình', 'Thái Nguyên', 'Thanh Hóa', 
        'Thừa Thiên Huế', 'Huế', 'Tiền Giang', 'Trà Vinh', 'Tuyên Quang', 'Vĩnh Long', 'Vĩnh Phúc', 'Yên Bái', 'TPHCM', 'TP HCM', 
        'Sài Gòn', 'Hồ Chí Minh',
        // Unaccented versions
        'An Giang', 'Ba Ria - Vung Tau', 'Ba Ria Vung Tau', 'Vung Tau', 'Bac Giang', 'Bac Kan', 'Bac Lieu', 'Bac Ninh', 'Ben Tre', 'Binh Dinh', 
        'Binh Duong', 'Binh Phước', 'Binh Thuan', 'Ca Mau', 'Can Tho', 'Cao Bang', 'Da Nang', 'Dak Lak', 'Dak Nong', 'Dien Bien', 
        'Dong Nai', 'Dong Thap', 'Gia Lai', 'Ha Giang', 'Ha Nam', 'Ha Noi', 'Ha Tinh', 'Hai Duong', 'Hai Phong', 'Hau Giang', 
        'Hoa Binh', 'Hung Yen', 'Khanh Hoa', 'Nha Trang', 'Kien Giang', 'Kon Tum', 'Lai Chau', 'Lam Dong', 'Da Lat', 'Lang Son', 
        'Lao Cai', 'Long An', 'Nam Dinh', 'Nghe An', 'Ninh Binh', 'Ninh Thuan', 'Phu Tho', 'Phu Yen', 'Quang Binh', 'Quang Nam', 
        'Quang Ngai', 'Quang Ninh', 'Quang Tri', 'Soc Trang', 'Son La', 'Tay Ninh', 'Thai Binh', 'Thai Nguyen', 'Thanh Hoa', 
        'Thua Thien Hue', 'Hue', 'Tien Giang', 'Tra Vinh', 'Tuyen Quang', 'Vinh Long', 'Vinh Phuc', 'Yen Bai', 'Sai Gon', 'Ho Chi Minh'
    ];
    
    $text_lower = mb_strtolower($text, 'UTF-8');
    
                                                 // Do not retry on 4xx client errors
    usort($provinces, function($a, $b) {
        return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
    });
    
    foreach ($provinces as $p) {
        $p_lower = mb_strtolower($p, 'UTF-8');
        if (mb_strpos($text_lower, $p_lower) !== false) {
            if (in_array(strtolower($p), ['tphcm', 'tp hcm', 'sài gòn', 'sai gon', 'hồ chí minh', 'ho chi minh'])) {
                return 'Hồ Chí Minh';
            }
            if (strtolower($p) == 'vũng tàu' || strtolower($p) == 'vung tau') {
                return 'Bà Rịa - Vũng Tàu';
            }
            if (strtolower($p) == 'huế' || strtolower($p) == 'hue') {
                return 'Thừa Thiên Huế';
            }
            if (strtolower($p) == 'nha trang') {
                return 'Khánh Hòa';
            }
            if (strtolower($p) == 'đà lạt' || strtolower($p) == 'da lat') {
                return 'Lâm Đồng';
            }
            return mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
        }
    }
    return null;
}

/**
 * Check if the current local time falls inside a bot rule's active hours
 */
function is_bot_rule_time_active($rule) {
    if (!isset($rule['active_time_type']) || $rule['active_time_type'] === 'ALL_DAY') {
        return true;
    }
    $current_time = date('H:i:s');
    $start = $rule['active_start_time'] ?? '00:00:00';
    $end = $rule['active_end_time'] ?? '23:59:59';
    if ($start <= $end) {
        return ($current_time >= $start && $current_time <= $end);
    } else {
        return ($current_time >= $start || $current_time <= $end);
    }
}

if ($data && isset($data['object']) && $data['object'] === 'page') {
    // 🚀 PHẢN HỒI TỨC THÌ CHO META WEBHOOK SERVER (< 10ms / dưới 0.01 giây)
    http_response_code(200);
    echo "EVENT_RECEIVED";

    // Cho phép PHP tiếp tục chạy ngầm hoàn tất lưu DB & AI sau khi ngắt kết nối với Facebook
    @ignore_user_abort(true);
    @set_time_limit(180);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
    
    // Tự động kiểm tra / khởi tạo bảng nếu chưa có (Chạy ngầm không ảnh hưởng tốc độ)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS page_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_id VARCHAR(50) NOT NULL,
            type VARCHAR(20) NOT NULL, /* 'message' or 'comment' */
            sender_id VARCHAR(50) NULL,
            sender_name VARCHAR(100) NULL,
            snippet TEXT NULL,
            post_id VARCHAR(50) NULL,
            comment_id VARCHAR(50) NULL,
            conversation_id VARCHAR(50) NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page (page_id),
            INDEX idx_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE fb_customers ADD COLUMN is_ads TINYINT DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE fb_customers ADD COLUMN ad_id VARCHAR(50) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE fb_customers ADD COLUMN ad_title VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE fb_customers ADD COLUMN ad_photo_url TEXT DEFAULT NULL");
    } catch (Exception $e) {}

    foreach ($data['entry'] as $entry) {
        $page_id = $entry['id'];

                                                     // Do not retry on 4xx client errors
        if (isset($entry['messaging'])) {
            foreach ($entry['messaging'] as $messaging_event) {
                $is_message = isset($messaging_event['message']) && !isset($messaging_event['message']['is_echo']);
                $is_postback = isset($messaging_event['postback']);
                $is_referral = isset($messaging_event['referral']);

                if ($is_message || $is_postback || $is_referral) {
                    $sender_id = $messaging_event['sender']['id'];
                    $text = '';
                    $is_welcome_trigger = false;

                    if ($is_message) {
                        if (isset($messaging_event['message']['text'])) {
                            $text = $messaging_event['message']['text'];
                        } elseif (isset($messaging_event['message']['attachments'])) {
                            $attachments = $messaging_event['message']['attachments'];
                            $first_att = $attachments[0] ?? null;
                            if ($first_att) {
                                $att_type = $first_att['type'] ?? '';
                                if ($att_type === 'sticker') {
                                    $sticker_id = $first_att['payload']['sticker_id'] ?? '';
                                    if ($sticker_id == '369239263222822') {
                                        $text = '[Khách gửi biểu tượng Thích (like)]';
                                    } else {
                                        $text = '[Khách gửi một nhãn dán (sticker)]';
                                    }
                                } elseif ($att_type === 'image') {
                                    $text = '[Khách gửi một hình ảnh]';
                                } elseif ($att_type === 'video') {
                                    $text = '[Khách gửi một video]';
                                } elseif ($att_type === 'audio') {
                                    $text = '[Khách gửi một tin nhắn thoại/audio]';
                                } elseif ($att_type === 'file') {
                                    $text = '[Khách gửi một tệp đính kèm/file]';
                                } else {
                                    $text = 'Đã gửi một tệp đính kèm';
                                }
                            } else {
                                $text = 'Đã gửi một tệp đính kèm';
                            }
                        } else {
                            $text = 'Đã gửi một tệp đính kèm';
                        }
                    } elseif ($is_postback) {
                        $payload = $messaging_event['postback']['payload'] ?? '';
                        $is_welcome_trigger = true;
                        $text = '[Hành động: Bấm nút/Bắt đầu]';
                    } elseif ($is_referral) {
                        $text = '[Hành động: Click quảng cáo]';
                    }
                }

                    
                                                                 // Do not retry on 4xx client errors
                    $is_ads = 0;
                    $ad_id = null;
                    $ad_title = null;
                    $ad_photo_url = null;

                    $referral = null;
                    if (isset($messaging_event['referral'])) {
                        $referral = $messaging_event['referral'];
                    } elseif (isset($messaging_event['message']['referral'])) {
                        $referral = $messaging_event['message']['referral'];
                    } elseif (isset($messaging_event['postback']['referral'])) {
                        $referral = $messaging_event['postback']['referral'];
                    }

                    if ($referral && ($referral['source'] ?? '') === 'ADS') {
                        $is_ads = 1;
                        $ad_id = $referral['ad_id'] ?? null;
                        $ad_title = $referral['ads_context_data']['ad_title'] ?? null;
                        $ad_photo_url = $referral['ads_context_data']['photo_url'] ?? $referral['ads_context_data']['video_url'] ?? null;
                        webhook_log("REFERRAL ADS DETECTED: ad_id=$ad_id, title=$ad_title");
                    }

                                                                 // Do not retry on 4xx client errors
                    if ($referral && ($referral['type'] ?? '') === 'REPLY_TO_FEED') {
                        $ref_comment_id = $referral['comment_id'] ?? null;
                        if ($ref_comment_id) {
                            try {
                                                                             // Do not retry on 4xx client errors
                                $stmt_get_feed = $pdo->prepare("
                                    SELECT sender_id FROM page_notifications 
                                    WHERE comment_id = ? AND type = 'comment' 
                                    ORDER BY id DESC LIMIT 1
                                ");
                                $stmt_get_feed->execute([$ref_comment_id]);
                                $feed_uid = $stmt_get_feed->fetchColumn();

                                                                             // Do not retry on 4xx client errors
                                $stmt_upd_cmt = $pdo->prepare("
                                    UPDATE page_notifications 
                                    SET sender_id = ? 
                                    WHERE comment_id = ? AND type = 'comment'
                                ");
                                $stmt_upd_cmt->execute([$sender_id, $ref_comment_id]);
                                webhook_log("REPLY_TO_FEED MAP SUCCESS: comment_id=$ref_comment_id mapped to sender_id=$sender_id");

                                                                             // Do not retry on 4xx client errors
                                if ($feed_uid && $feed_uid !== $sender_id) {
                                    $stmt_get_old = $pdo->prepare("SELECT phone, province FROM fb_customers WHERE page_id = ? AND sender_id = ?");
                                    $stmt_get_old->execute([$page_id, $feed_uid]);
                                    $old_cust = $stmt_get_old->fetch(PDO::FETCH_ASSOC);

                                    if ($old_cust && (!empty($old_cust['phone']) || !empty($old_cust['province']))) {
                                        $stmt_upd_new = $pdo->prepare("
                                            UPDATE fb_customers 
                                            SET phone = COALESCE(NULLIF(phone, ''), ?),
                                                province = COALESCE(NULLIF(province, ''), ?)
                                            WHERE page_id = ? AND sender_id = ?
                                        ");
                                        $stmt_upd_new->execute([
                                            $old_cust['phone'] ?: null,
                                            $old_cust['province'] ?: null,
                                            $page_id,
                                            $sender_id
                                        ]);
                                        webhook_log("SYNCED PROFILE FROM FEED_UID $feed_uid TO MESSENGER_UID $sender_id: phone=" . $old_cust['phone']);
                                    }
                                }
                            } catch (Exception $e) {
                                webhook_log("REPLY_TO_FEED MAP ERR: " . $e->getMessage());
                            }
                        }
                    }
                    
                                                                 // Do not retry on 4xx client errors
                    $sender_name = 'Khách hàng';
                    $conversation_id = null;
                    try {
                        $ts = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
                        $ts->execute([$page_id]);
                        if ($page = $ts->fetch(PDO::FETCH_ASSOC)) {
                            $page_token = decryptData($page['access_token']);
                            if ($page_token) {
                                $conv_res = fb_api_request($page_id . '/conversations', ['fields' => 'participants', 'user_id' => $sender_id, 'access_token' => $page_token]);
                                webhook_log("API Conv Res for $sender_id: " . json_encode($conv_res));
                                
                                if ($conv_res['status_code'] === 200 && !empty($conv_res['data']['data'][0])) {
                                    $conv_data = $conv_res['data']['data'][0];
                                    $conversation_id = $conv_data['id'] ?? null;
                                    
                                                                                 // Do not retry on 4xx client errors
                                    if (!empty($conv_data['participants']['data'])) {
                                        foreach ($conv_data['participants']['data'] as $p) {
                                            if ($p['id'] == $sender_id && !empty($p['name'])) {
                                                $sender_name = $p['name'];
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        webhook_log("Exception in conv fetch: " . $e->getMessage());
                    }

                                                                 // Do not retry on 4xx client errors
                    $is_first_message = false;
                    try {
                        // Check if it's the very first message!
                        $stmt_chk = $pdo->prepare("SELECT COUNT(*) FROM page_notifications WHERE page_id=? AND sender_id=? AND type='message'");
                        $stmt_chk->execute([$page_id, $sender_id]);
                        $msg_count = (int)$stmt_chk->fetchColumn();
                        
                        $is_first_message = ($msg_count === 0);

                        $stmt = $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_id, sender_name, conversation_id, snippet) VALUES (?, 'message', ?, ?, ?, ?)");
                        $r = $stmt->execute([$page_id, $sender_id, $sender_name, $conversation_id, $text]);
                        webhook_log("MSG INSERT: page=$page_id sender=$sender_id result=" . ($r ? 'OK id='.$pdo->lastInsertId() : 'FAIL'));

                                                                     // Do not retry on 4xx client errors
                        try {
                            $stmt_conv = $pdo->prepare("
                                INSERT INTO fb_conversations (page_id, sender_id, sender_name, snippet, unread_count, updated_time, conversation_id)
                                VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, ?)
                                ON DUPLICATE KEY UPDATE
                                    sender_name = COALESCE(VALUES(sender_name), sender_name),
                                    snippet = VALUES(snippet),
                                    unread_count = unread_count + 1,
                                    updated_time = CURRENT_TIMESTAMP,
                                    conversation_id = COALESCE(VALUES(conversation_id), conversation_id)
                            ");
                            $stmt_conv->execute([$page_id, $sender_id, $sender_name, $text, $conversation_id]);
                        } catch (Exception $e) {
                            webhook_log("CONV INSERT ERR: " . $e->getMessage());
                        }

                                                                     // Do not retry on 4xx client errors
                        try {
                            $stmt_cust = $pdo->prepare("
                                INSERT INTO fb_customers (page_id, sender_id, name, last_sender, last_message_at, customer_last_message_at, info_request_count, followup_requested_at, is_ads, ad_id, ad_title, ad_photo_url) 
                                VALUES (?, ?, ?, 'customer', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, NULL, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                    name = VALUES(name),
                                    last_sender = 'customer',
                                    followup_requested_at = IF(consulted IN (1, 3) AND last_message_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR), NULL, followup_requested_at),
                                    consulted = IF(consulted IN (1, 3) AND last_message_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR), 2, consulted),
                                    last_message_at = CURRENT_TIMESTAMP,
                                    customer_last_message_at = CURRENT_TIMESTAMP,
                                    is_ads = IF(VALUES(is_ads) = 1, 1, is_ads),
                                    ad_id = IF(VALUES(is_ads) = 1, VALUES(ad_id), ad_id),
                                    ad_title = IF(VALUES(is_ads) = 1, VALUES(ad_title), ad_title),
                                    ad_photo_url = IF(VALUES(is_ads) = 1, VALUES(ad_photo_url), ad_photo_url)
                            ");
                            $stmt_cust->execute([$page_id, $sender_id, $sender_name, $is_ads, $ad_id, $ad_title, $ad_photo_url]);
                            
                            if ($is_message && !empty($text) && $text !== 'Đã gửi một tệp đính kèm' && strpos($text, '[Hành động:') === false) {
                                $detected_phone = '';
                                if (preg_match('/(03|05|07|08|09)+([0-9]{8})\b/', $text, $matches)) {
                                    $detected_phone = $matches[0];
                                }
                                $detected_province = detect_vietnam_province($text);
                                
                                $php_upd_cols = [];
                                $php_upd_vals = [];
                                if ($detected_phone) {
                                    $php_upd_cols[] = "phone = ?";
                                    $php_upd_vals[] = $detected_phone;
                                }
                                if ($detected_province) {
                                    $php_upd_cols[] = "province = ?";
                                    $php_upd_vals[] = $detected_province;
                                }
                                
                                if (!empty($php_upd_cols)) {
                                    $php_upd_vals[] = $page_id;
                                    $php_upd_vals[] = $sender_id;
                                    $stmt_upd = $pdo->prepare("UPDATE fb_customers SET " . implode(", ", $php_upd_cols) . " WHERE page_id = ? AND sender_id = ?");
                                    $stmt_upd->execute($php_upd_vals);
                                }
                                
                                                                             // Do not retry on 4xx client errors
                                $stmt_chk_ph = $pdo->prepare("SELECT phone FROM fb_customers WHERE page_id = ? AND sender_id = ?");
                                $stmt_chk_ph->execute([$page_id, $sender_id]);
                                $has_phone = $stmt_chk_ph->fetchColumn();
                                if ($has_phone && !empty($conversation_id)) {
                                    try {
                                        $stmt_lbl = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
                                        $stmt_lbl->execute([$conversation_id, $page_id, $sender_id]);
                                    } catch (Exception $e) {}
                                }
                            }
                        } catch (Exception $e) {
                            webhook_log("CUSTOMER PROFILE UPDATE ERR: " . $e->getMessage());
                        }
                    } catch (Exception $e) { webhook_log('MSG DB ERR: ' . $e->getMessage()); }

                                                                 // Do not retry on 4xx client errors
                    try {
                                                                     // Do not retry on 4xx client errors
                        $stmt = $pdo->prepare("SELECT p.access_token, u.account_id FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ?");
                        $stmt->execute([$page_id]);
                        $page_info = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($page_info && !empty($page_info['access_token'])) {
                            $page_token = decryptData($page_info['access_token']);
                            $acc_id = $page_info['account_id'];

                            // Check chatbot lock (Manual hand-off / admin override)
                            $stmt_lock = $pdo->prepare("SELECT expire_at FROM bot_chat_locks WHERE page_id = ? AND sender_id = ? AND expire_at > NOW()");
                            $stmt_lock->execute([$page_id, $sender_id]);
                            $is_locked = (bool)$stmt_lock->fetch();

                                                                         // Do not retry on 4xx client errors
                            $stmt_cust_status = $pdo->prepare("SELECT consulted FROM fb_customers WHERE page_id = ? AND sender_id = ?");
                            $stmt_cust_status->execute([$page_id, $sender_id]);
                            $cust_consulted = (int)$stmt_cust_status->fetchColumn();

                            if ($is_locked || $cust_consulted === 3) {
                                webhook_log("BOT_CHAT_LOCKED: Chatbot disabled due to active lock or consulted status = 3 for sender $sender_id on page $page_id");
                            } else {
                                // Split lines and choose randomly
                                $send_bot_msg = function($msg_text, $is_multiline = false) use ($sender_id, $sender_name, $page_token, $page_id, $pdo) {
                                 $msg_text = str_replace("\r", "", $msg_text);
                                 if ($is_multiline) {
                                     // Send multiline message
                                     $final_msg = str_replace('{name}', $sender_name, $msg_text);
                                 } else {
                                     // Split lines and choose randomly
                                     $lines = array_filter(explode("\n", $msg_text), 'trim');
                                     if (empty($lines)) return;
                                     $chosen_msg = $lines[array_rand($lines)];
                                     $final_msg = str_replace('{name}', $sender_name, $chosen_msg);
                                 }

                                 $url = "https://graph.facebook.com/v25.0/me/messages?access_token={$page_token}";
                                 $post_data = json_encode([
                                     'recipient' => ['id' => $sender_id],
                                     'message' => ['text' => $final_msg],
                                     'messaging_type' => 'RESPONSE'
                                 ]);
                                 
                                 $max_send_retries = 3;
                                 $success = false;
                                 $output = '';
                                 $http_code = 0;
                                 $err_msg = '';

                                 for ($attempt = 1; $attempt <= $max_send_retries; $attempt++) {
                                     $ch = curl_init($url);
                                     curl_setopt($ch, CURLOPT_POST, 1);
                                     curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
                                     curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                                     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                     curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout 10s per request
                                     $output = curl_exec($ch);
                                     $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                     
                                     if ($output === false) {
                                         $err_msg = curl_error($ch);
                                         curl_close($ch);
                                         webhook_log("BOT_CHAT_REPLY SEND ATTEMPT $attempt FAILED: error=$err_msg sender_id=$sender_id");
                                         sleep(1); // Sleep 1s before retry
                                     } else {
                                         curl_close($ch);
                                         $res_data = json_decode($output, true);
                                         if ($http_code === 200 && !isset($res_data['error'])) {
                                             $success = true;
                                             webhook_log("BOT_CHAT_REPLY SUCCESS (Attempt $attempt): " . $output);
                                             break;
                                         } else {
                                             $err_msg = $output;
                                             webhook_log("BOT_CHAT_REPLY FB API ERROR (Attempt $attempt): code=$http_code response=$output sender_id=$sender_id");
                                                                                          // Do not retry on 4xx client errors
                                             if ($http_code >= 400 && $http_code < 500) {
                                                 break;
                                             }
                                             sleep(1);
                                         }
                                     }
                                 }

                                 if ($success) {
                                     try {
                                         $st_upd = $pdo->prepare("UPDATE fb_customers SET last_sender = 'agent', last_message_at = CURRENT_TIMESTAMP WHERE page_id = ? AND sender_id = ?");
                                         $st_upd->execute([$page_id, $sender_id]);
                                     } catch (Exception $e) {}

                                     // Update fb_conversations to bubble up
                                     try {
                                         $stmt_conv = $pdo->prepare("
                                             INSERT INTO fb_conversations (page_id, sender_id, sender_name, snippet, unread_count, updated_time)
                                             VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)
                                             ON DUPLICATE KEY UPDATE
                                                 sender_name = COALESCE(VALUES(sender_name), sender_name),
                                                 snippet = VALUES(snippet),
                                                 unread_count = 0,
                                                 updated_time = CURRENT_TIMESTAMP
                                         ");
                                         $stmt_conv->execute([$page_id, $sender_id, $sender_name, $final_msg]);
                                     } catch (Exception $e) {
                                         webhook_log("BOT_CHAT_REPLY CONV UPDATE ERR: " . $e->getMessage());
                                     }
                                 } else {
                                     webhook_log("BOT_CHAT_REPLY SEND FAILED PERMANENTLY after $max_send_retries attempts. Last error: $err_msg");
                                 }
                             };$matched_rule = false;

                                                                         // Do not retry on 4xx client errors
                            if ($is_first_message || $is_welcome_trigger) {
                                $st_rule = $pdo->prepare("SELECT * FROM bot_chat_rules WHERE account_id=? AND is_active=1 AND rule_type='welcome'");
                                $st_rule->execute([$acc_id]);
                                $all_rules = $st_rule->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($all_rules as $r) {
                                    $match_page = false;
                                    if ($r['pages_scope'] === 'ALL') {
                                        $match_page = true;
                                    } else {
                                        $scope_arr = @json_decode($r['pages_scope'], true);
                                        if (is_array($scope_arr) && in_array($page_id, $scope_arr)) {
                                            $match_page = true;
                                        }
                                    }
                                    if ($match_page && is_bot_rule_time_active($r)) {
                                        $send_bot_msg($r['message']);
                                        $matched_rule = true;
                                        break; // Only apply the first matching welcome rule
                                    }
                                }
                            }

                                                                         // Do not retry on 4xx client errors
                            if (!$matched_rule && $is_message && !empty($text)) {
                                $text_lower = mb_strtolower($text, 'UTF-8');
                                $st_kw = $pdo->prepare("SELECT * FROM bot_chat_rules WHERE account_id=? AND is_active=1 AND rule_type='keyword'");
                                $st_kw->execute([$acc_id]);
                                $kw_rules_all = $st_kw->fetchAll(PDO::FETCH_ASSOC);
                                
                                $kw_rules = [];
                                foreach ($kw_rules_all as $r) {
                                    if ($r['pages_scope'] === 'ALL') {
                                        $kw_rules[] = $r;
                                    } else {
                                        $scope_arr = @json_decode($r['pages_scope'], true);
                                        if (is_array($scope_arr) && in_array($page_id, $scope_arr)) {
                                            $kw_rules[] = $r;
                                        }
                                    }
                                }

                                foreach ($kw_rules as $rule) {
                                    if (!is_bot_rule_time_active($rule)) continue;
                                    $kws = array_filter(array_map('trim', explode(',', $rule['keywords'])));
                                    $found = false;
                                    foreach ($kws as $kw) {
                                        if ($kw !== '' && mb_strpos($text_lower, mb_strtolower($kw, 'UTF-8')) !== false) {
                                            $found = true;
                                            break;
                                        }
                                    }
                                    if ($found) {
                                        $send_bot_msg($rule['message']);
                                        $matched_rule = true;
                                        break;                                              // Do not retry on 4xx client errors
                                    }
                                }
                            }

                                                                         // Do not retry on 4xx client errors
                            if (!$matched_rule && $is_message && !empty($text)) {
                                $st_ai = $pdo->prepare("SELECT * FROM bot_chat_rules WHERE account_id=? AND is_active=1 AND rule_type='ai_reply'");
                                $st_ai->execute([$acc_id]);
                                $ai_rules_all = $st_ai->fetchAll(PDO::FETCH_ASSOC);
                                
                                $ai_rule = null;
                                foreach ($ai_rules_all as $r) {
                                    $match_scope = false;
                                    if ($r['pages_scope'] === 'ALL') {
                                        $match_scope = true;
                                    } else {
                                        $scope_arr = @json_decode($r['pages_scope'], true);
                                        if (is_array($scope_arr) && in_array($page_id, $scope_arr)) {
                                            $match_scope = true;
                                        }
                                    }
                                    if ($match_scope && is_bot_rule_time_active($r)) {
                                        $ai_rule = $r;
                                        break;
                                    }
                                }

                                if ($ai_rule) {
                                    $delay_s = (int)($ai_rule['delay_seconds'] ?? 0);
                                    if ($delay_s > 0 && $delay_s < 10) {
                                        $delay_s = 10;
                                    }
                                    
                                                                                 // Do not retry on 4xx client errors
                                    if ($delay_s > 0) {
                                                                                     // Do not retry on 4xx client errors
                                        $stmt_clean_lock = $pdo->prepare("DELETE FROM bot_chat_locks WHERE page_id=? AND sender_id=? AND expire_at <= NOW()");
                                        $stmt_clean_lock->execute([$page_id, $sender_id]);

                                                                                     // Do not retry on 4xx client errors
                                        $stmt_ins_lock = $pdo->prepare("INSERT IGNORE INTO bot_chat_locks (page_id, sender_id, expire_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
                                        $stmt_ins_lock->execute([$page_id, $sender_id, $delay_s]);
                                        
                                        if ($stmt_ins_lock->rowCount() == 0) {
                                                                                         // Do not retry on 4xx client errors
                                                                                         // Do not retry on 4xx client errors
                                            http_response_code(200);
                                            echo "EVENT_RECEIVED";
                                            if (function_exists('fastcgi_finish_request')) {
                                                fastcgi_finish_request();
                                            }
                                            exit;
                                        }

                                                                                     // Do not retry on 4xx client errors
                                                                                     // Do not retry on 4xx client errors
                                        http_response_code(200);
                                        echo "EVENT_RECEIVED";
                                        if (function_exists('fastcgi_finish_request')) {
                                            fastcgi_finish_request();
                                        }
                                        
                                                                                     // Do not retry on 4xx client errors
                                        sleep($delay_s);
                                        
                                                                                     // Do not retry on 4xx client errors
                                        $stmt_del_lock = $pdo->prepare("DELETE FROM bot_chat_locks WHERE page_id=? AND sender_id=?");
                                        $stmt_del_lock->execute([$page_id, $sender_id]);
                                        
                                                                                     // Do not retry on 4xx client errors
                                                                                     // Do not retry on 4xx client errors
                                        $stmt_msgs = $pdo->prepare("SELECT snippet FROM page_notifications WHERE page_id=? AND sender_id=? AND type='message' AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY id ASC");
                                        $stmt_msgs->execute([$page_id, $sender_id, $delay_s + 3]);
                                        $all_snippets = [];
                                        while($m_row = $stmt_msgs->fetch()) {
                                            if (!empty($m_row['snippet'])) {
                                                $all_snippets[] = trim($m_row['snippet']);
                                            }
                                        }
                                        if (!empty($all_snippets)) {
                                            $text = implode("\n", $all_snippets);
                                        }
                                    }

                                    $page_name = '';
                                    $stmt_page = $pdo->prepare("SELECT name FROM pages WHERE page_id=?");
                                    $stmt_page->execute([$page_id]);
                                    if($p_row = $stmt_page->fetch(PDO::FETCH_ASSOC)) {
                                        $page_name = $p_row['name'];
                                    }

                                                                                 // Do not retry on 4xx client errors
                                    $history_count = (int)($ai_rule['history_count'] ?? 6);
                                    $history_text = '';
                                    if ($history_count > 0 && !empty($conversation_id) && !empty($page_token)) {
                                        $msg_res = fb_api_request($conversation_id . '/messages', [
                                            'fields' => 'message,from',
                                            'limit' => $history_count,
                                            'access_token' => $page_token
                                        ], 'GET');
                                        
                                        if ($msg_res['status_code'] === 200 && !empty($msg_res['data']['data'])) {
                                            $msgs = array_reverse($msg_res['data']['data']);
                                            foreach ($msgs as $m) {
                                                if (empty($m['message'])) continue;
                                                $role = ($m['from']['id'] === $page_id) ? "Bạn (Cửa hàng)" : "Khách hàng";
                                                $history_text .= "$role: " . $m['message'] . "\n";
                                            }
                                        }
                                    }

                                                                                 // Do not retry on 4xx client errors
                                    $cust_name = '';
                                    $cust_phone = '';
                                    $cust_province = '';
                                    $cust_notes = '';
                                    $cust_sales_phone = '';
                                    $cust_sales_notes = '';
                                    $st_cust = $pdo->prepare("SELECT name, phone, province, notes, sales_phone, sales_notes FROM fb_customers WHERE page_id = ? AND sender_id = ?");
                                    $st_cust->execute([$page_id, $sender_id]);
                                    if ($cust_row = $st_cust->fetch(PDO::FETCH_ASSOC)) {
                                        $cust_name = $cust_row['name'] ?? '';
                                        $cust_phone = $cust_row['phone'] ?? '';
                                        $cust_province = $cust_row['province'] ?? '';
                                        $cust_notes = $cust_row['notes'] ?? '';
                                        $cust_sales_phone = $cust_row['sales_phone'] ?? '';
                                        $cust_sales_notes = $cust_row['sales_notes'] ?? '';
                                    }

                                                                                 // Do not retry on 4xx client errors
                                    $info_context = "\n\n--- THÔNG TIN KHÁCH HÀNG ĐÃ CÓ ---\n";
                                    $info_context .= "- Tên khách hàng: " . ($cust_name ?: "CHƯA CÓ") . "\n";
                                    $info_context .= "- Số điện thoại: " . ($cust_phone ?: "CHƯA CÓ") . "\n";
                                    $info_context .= "- Tỉnh thành: " . ($cust_province ?: "CHƯA CÓ") . "\n";
                                    $info_context .= "- Nhu cầu/Yêu cầu khách hàng: " . ($cust_notes ?: "CHƯA CÓ") . "\n";
                                    $info_context .= "- Số điện thoại Sales phụ trách: " . ($cust_sales_phone ?: "CHƯA CÓ") . "\n";
                                    $info_context .= "- Ghi chú của Sales: " . ($cust_sales_notes ?: "CHƯA CÓ") . "\n";
                                    $info_context .= "-----------------------------------\n";
                                    if (!empty($cust_sales_phone)) {
                                        $info_context .= "HƯỚNG DẪN THÊM VỀ BÀN GIAO SALES: Nếu có thông tin 'Số điện thoại Sales phụ trách' và khách hàng hỏi/thắc mắc về việc liên hệ, báo giá hoặc phản hồi chậm, bạn hãy khéo léo thông báo cho khách hàng biết rằng nhân viên Sales số điện thoại " . $cust_sales_phone . " đã/đang xử lý và liên hệ với khách hàng (dựa trên Ghi chú của Sales nếu có, ví dụ như gọi không liên lạc được, bận...). Hướng dẫn khách hàng liên hệ trực tiếp hoặc add Zalo số đó để được xử lý nhanh nhất.\n";
                                    }
                                    if (!empty($cust_phone)) {
                                        $info_context .= "⚠️ LƯU Ý ĐẶC BIỆT QUAN TRỌNG: Khách hàng này ĐÃ CÓ số điện thoại là \"{$cust_phone}\". Bạn TUYỆT ĐỐI KHÔNG ĐƯỢC HỎI XIN lại số điện thoại trong mọi trường hợp (ngay cả khi khách hàng hỏi về việc liên hệ, báo giá, hoặc đơn hàng). Nếu khách hàng yêu cầu liên hệ hoặc báo giá, hãy nói rõ rằng bộ phận tư vấn sẽ liên hệ qua số điện thoại {$cust_phone} đã có.\n";
                                    }
                                    if (!empty($cust_province)) {
                                        $info_context .= "⚠️ LƯU Ý ĐẶC BIỆT QUAN TRỌNG: Khách hàng này ĐÃ CÓ tỉnh thành là \"{$cust_province}\". Bạn TUYỆT ĐỐI KHÔNG ĐƯỢC HỎI LẠI khách hàng ở tỉnh nào nữa. Nếu cần tính phí vận chuyển hoặc báo giá, hãy mặc định sử dụng luôn tỉnh thành \"{$cust_province}\" để tính toán hoặc báo với khách là sẽ giao về \"{$cust_province}\".\n";
                                    }
                                    $info_context .= "HƯỚNG DẪN BẮT BUỘC: Bạn là chatbot chăm sóc khách hàng chuyên nghiệp. Hãy kiểm tra các thông tin ở trên:\n";
                                    $info_context .= "1. Với thông tin nào đã có (không phải là 'CHƯA CÓ'), bạn tuyệt đối không được hỏi lại khách hàng nữa.\n";
                                    $info_context .= "2. Với thông tin nào ghi 'CHƯA CÓ', hãy khéo léo, tự nhiên và thân thiện hỏi khách hàng để xin nốt. Quy tắc xin thông tin: Chỉ hỏi xin TỪNG thông tin một trong mỗi tin nhắn, TUYỆT ĐỐI không hỏi dồn dập nhiều thông tin cùng lúc (ví dụ: không được hỏi xin cả tỉnh thành lẫn số điện thoại trong cùng một câu). Bạn phải ưu tiên hỏi về Nhu cầu/Sản phẩm trước để biết khách muốn mua gì, sau đó mới hỏi đến Tỉnh thành (khi hỏi tỉnh thành bạn phải chủ động giới thiệu địa chỉ cửa hàng của mình trước để khách biết vị trí của shop), và cuối cùng mới xin Số điện thoại để Sales liên hệ báo giá cụ thể. Trả lời NGẮN GỌN, đi thẳng vào câu hỏi. TUYỆT ĐỐI không cảm ơn đi cảm ơn lại nhiều lần (không cần nói câu cảm ơn mỗi khi nhận được một thông tin đơn lẻ như địa chỉ hay số điện thoại, chỉ ghi nhận nhanh và hỏi tiếp ngắn gọn).\n";
                                    $info_context .= "3. Khi đã thu thập đủ cả 3 thông tin (Số điện thoại, Tỉnh thành, Nhu cầu), hãy tóm tắt lại và gửi lời cảm ơn khách hàng.\n";
                                    $info_context .= "4. ĐỊNH DẠNG TIN NHẮN: Hãy xuống dòng hợp lý để tin nhắn dễ đọc. Mỗi ý chính nên ở một dòng riêng. Khi liệt kê nhiều sản phẩm hoặc thông tin, dùng dấu gạch đầu dòng (- ) và xuống hàng cho từng mục. Không viết tất cả thành một đoạn dài liền nhau.\n";
                                    $info_context .= "5. XỬ LÝ KHÁCH Ở QUÁ XA HOẶC KHÔNG MUỐN MUA: Nếu khách hàng nói hoặc ngụ ý rằng địa chỉ của chúng ta quá xa so với họ (ví dụ: 'xa quá', 'ở xa thế', 'không tiện', v.v.) hoặc từ chối tiếp tục tư vấn, bạn hãy trả lời lịch sự và ngắn gọn: 'Cảm ơn anh/chị đã liên hệ, nếu có cơ hội mong được hợp tác.' sau đó thiết lập trường 'stop_consulting' trong JSON trả về thành true để hệ thống tự động dừng tư vấn khách này. Với những trường hợp này, bạn tuyệt đối không được tiếp tục hỏi xin số điện thoại hay thông tin gì khác nữa.\n";
                                     $info_context .= "6. KHÔNG LIỆT KÊ/TÓM TẮT GIỮA CUỘC: Trong suốt quá trình xin thông tin (khi chưa đủ cả 3 thông tin), bạn TUYỆT ĐỐI KHÔNG ĐƯỢC nhắc lại, liệt kê hay tóm tắt các thông tin đã thu thập được dưới dạng danh sách hay gạch đầu dòng. Hãy đi thẳng vào câu hỏi tiếp theo một cách ngắn gọn, tự nhiên. Chỉ tóm tắt đầy đủ thông tin dưới dạng danh sách gạch đầu dòng duy nhất một lần ở cuối cuộc trò chuyện khi đã thu thập đủ cả 3 thông tin (Số điện thoại, Tỉnh thành, Nhu cầu).\n";

                                    $json_instruction = "\n\nQUY ĐỊNH PHẢN HỒI: Để đồng bộ thông tin vào hệ thống quản lý, bạn BẮT BUỘC phải phản hồi dưới định dạng JSON duy nhất (không bọc trong thẻ ```json hay bất kỳ chữ giải thích nào khác ngoài cấu trúc JSON), nội dung như sau:\n";
                                    $json_instruction .= "{\n";
                                    $json_instruction .= '  "reply": "Nội dung tin nhắn bạn muốn trả lời khách hàng (viết bằng tiếng Việt tự nhiên)",\n';
                                    $json_instruction .= '  "extracted": {\n';
                                    $json_instruction .= '    "phone": "Số điện thoại phát hiện được trong tin nhắn mới của khách hàng (nếu có, không lấy số cũ), nếu khách hàng gửi lại số điện thoại khác thì trả về số mới, nếu không có trả về null",\n';
                                    $json_instruction .= '    "province": "Tỉnh thành phát hiện được trong tin nhắn mới của khách hàng (nếu có, không lấy tỉnh cũ), nếu không có trả về null",\n';
                                    $json_instruction .= '    "requirements": "Nhu cầu/yêu cầu đầy đủ nhất của khách hàng đã được cập nhật hoặc bổ sung thêm thông tin mới. Hãy đối chiếu với mục Nhu cầu/Yêu cầu khách hàng trong THÔNG TIN KHÁCH HÀNG ĐÃ CÓ ở trên để cập nhật hoặc tích lũy một cách chính xác theo các nguyên tắc sau:\n1. BẮT BUỘC phải trích xuất ngay tên sản phẩm khi khách hàng đề cập, dù khách hàng chưa cung cấp số lượng (ví dụ: khách nói \'tôi muốn mua cùm giáo\' -> lập tức cập nhật \'Cùm giáo\'). Không được bỏ qua hay chờ số lượng.\n2. Nếu khách hàng bổ sung số lượng cho sản phẩm đã nói trước đó (ví dụ: thông tin cũ là \'Cùm giáo\', nay khách nói thêm \'lấy cho em 50 cái\' -> cập nhật tích lũy thành \'Cùm giáo - 50 cái\').\n3. Nếu khách hàng bổ sung thêm sản phẩm/yêu cầu mới khác (ví dụ: thông tin cũ là \'Cùm giáo - 50 cái\', nay khách nói mua thêm \'100m ty ren\' -> tích lũy thêm thành \'Cùm giáo - 50 cái, 100m ty ren\').\nNếu khách hàng không đề cập gì thêm về sản phẩm/nhu cầu hoặc không có thông tin thay đổi so với thông tin đã có, trả về null",\n';
                                    $json_instruction .= '    "stop_consulting": true hoặc false (trả về true nếu khách hàng nói hoặc ngụ ý địa chỉ quá xa không mua nữa, từ chối hoặc không có nhu cầu tiếp tục tư vấn, để hệ thống tự động dừng tư vấn khách hàng này, ngược lại trả về false)\n';
                                    $json_instruction .= "  }\n";
                                    $json_instruction .= "}\n";

                                    $custom_system_prompt = $ai_rule['message'] . $info_context . $json_instruction;
                                    $ai_reply_text = generate_chat_reply_with_ai($text, $custom_system_prompt, $acc_id, $page_name, $history_text);
                                    
                                    $reply_to_send = '';
                                    if (!empty($ai_reply_text)) {
                                                                                     // Do not retry on 4xx client errors
                                        $ai_parsed = parse_ai_json_reply($ai_reply_text);
                                        $reply_to_send = $ai_parsed['reply'];
                                        $parsed_extracted = $ai_parsed['extracted'];

                                        if (!empty($reply_to_send)) {
                                            $ai_upd_fields = [];
                                            $ai_upd_params = [];
                                            
                                            if (!empty($parsed_extracted['phone'])) {
                                                $new_phone = trim($parsed_extracted['phone']);
                                                if ($new_phone !== $cust_phone) {
                                                    $ai_upd_fields[] = "phone = ?";
                                                    $ai_upd_params[] = $new_phone;
                                                }
                                            }
                                            if (!empty($parsed_extracted['province'])) {
                                                $new_prov = trim($parsed_extracted['province']);
                                                if ($new_prov !== $cust_province) {
                                                    $ai_upd_fields[] = "province = ?";
                                                    $ai_upd_params[] = $new_prov;
                                                }
                                            }
                                            if (!empty($parsed_extracted['requirements'])) {
                                                $new_req = trim($parsed_extracted['requirements']);
                                                if ($new_req !== $cust_notes) {
                                                    $ai_upd_fields[] = "notes = ?";
                                                    $ai_upd_params[] = $new_req;
                                                }
                                            }
                                            if (isset($parsed_extracted['stop_consulting']) && $parsed_extracted['stop_consulting'] === true) {
                                                $ai_upd_fields[] = "consulted = 3";
                                            }
                                            
                                            if (!empty($ai_upd_fields)) {
                                                try {
                                                    $ai_upd_params[] = $page_id;
                                                    $ai_upd_params[] = $sender_id;
                                                    $stmt_ai_upd = $pdo->prepare("UPDATE fb_customers SET " . implode(", ", $ai_upd_fields) . " WHERE page_id = ? AND sender_id = ?");
                                                    $stmt_ai_upd->execute($ai_upd_params);
                                                    webhook_log("AI EXTRACTED INFO SAVED for sender $sender_id: " . json_encode($parsed_extracted));
                                                    
                                                    $stmt_chk_ph = $pdo->prepare("SELECT phone FROM fb_customers WHERE page_id = ? AND sender_id = ?");
                                                    $stmt_chk_ph->execute([$page_id, $sender_id]);
                                                    $has_phone = $stmt_chk_ph->fetchColumn();
                                                    if ($has_phone && !empty($conversation_id)) {
                                                        try {
                                                            $stmt_lbl = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
                                                            $stmt_lbl->execute([$conversation_id, $page_id, $sender_id]);
                                                        } catch (Exception $e) {}
                                                        
                                                        // Auto CAPI: Tự động đẩy sự kiện Lead lên Facebook CAPI khi phát hiện SĐT mới
                                                        try {
                                                            $stmt_capi = $pdo->prepare("
                                                                SELECT p.capi_pixel_id, p.capi_token, p.auto_send_capi, cust.capi_pushed, cust.name, cust.phone
                                                                FROM pages p
                                                                JOIN fb_customers cust ON p.page_id = cust.page_id
                                                                WHERE cust.page_id = ? AND cust.sender_id = ?
                                                            ");
                                                            $stmt_capi->execute([$page_id, $sender_id]);
                                                            $capi_data = $stmt_capi->fetch(PDO::FETCH_ASSOC);
                                                            
                                                            if ($capi_data 
                                                                && !empty($capi_data['capi_pixel_id']) 
                                                                && !empty($capi_data['capi_token']) 
                                                                && !empty($capi_data['phone'])
                                                                && intval($capi_data['capi_pushed']) === 0 
                                                                && intval($capi_data['auto_send_capi'] ?? 0) === 1
                                                            ) {
                                                                require_once __DIR__ . '/includes/security.php';
                                                                require_once __DIR__ . '/includes/capi_utils.php';
                                                                $capi_pixel = $capi_data['capi_pixel_id'];
                                                                $capi_tok = decryptData($capi_data['capi_token']);
                                                                
                                                                $capi_res = send_facebook_capi_lead($capi_pixel, $capi_tok, $page_id, $sender_id, [
                                                                    'name' => $capi_data['name'] ?: '',
                                                                    'phone' => $capi_data['phone']
                                                                ]);
                                                                
                                                                if ($capi_res['success']) {
                                                                    $pdo->prepare("UPDATE fb_customers SET capi_pushed = 1 WHERE page_id = ? AND sender_id = ?")->execute([$page_id, $sender_id]);
                                                                    webhook_log("AUTO CAPI PUSHED for sender $sender_id on page $page_id");
                                                                } else {
                                                                    webhook_log("AUTO CAPI FAILED for sender $sender_id: " . json_encode($capi_res));
                                                                }
                                                            }
                                                        } catch (Exception $capi_ex) {
                                                            webhook_log("AUTO CAPI ERR: " . $capi_ex->getMessage());
                                                        }
                                                    }
                                                } catch (Exception $e) {
                                                    webhook_log("AI EXTRACTED SAVE ERR: " . $e->getMessage());
                                                }
                                            }
                                        }
                                    }

                                    if (!empty($reply_to_send)) {
                                        $send_bot_msg($reply_to_send, true);
                                    }
                                }
                            }
                        }
                    }
                    } catch (Exception $e) {
                        webhook_log("Chatbot Main Block Err: " . $e->getMessage());
                    }
                }
            }
        }

                                                     // Do not retry on 4xx client errors
        if (isset($entry['changes'])) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'feed') {
                    $val = $change['value'];
                    $item = $val['item'] ?? '';
                    $verb = $val['verb'] ?? '';
                    
                    if ((!empty($val['comment_id']) || $item === 'comment' || $item === 'status') && ($verb === 'add' || $verb === 'edited')) {
                        $comment_id = $val['comment_id'] ?? '';
                        $post_id = $val['post_id'] ?? $val['parent_id'] ?? '';
                        $sender_id = $val['from']['id'] ?? '';
                        $sender_name = $val['from']['name'] ?? 'Khách hàng';
                        $text = $val['message'] ?? '';
                        
                        if ($sender_id === $page_id) {
                            continue; // Skip comments made by the page itself
                        }
                        
                        try {
                            $stmt = $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_id, sender_name, snippet, post_id, comment_id) VALUES (?, 'comment', ?, ?, ?, ?, ?)");
                            $r = $stmt->execute([$page_id, $sender_id, $sender_name, $text, $post_id, $comment_id]);
                            webhook_log("CMT INSERT: page=$page_id post=$post_id sender=$sender_name result=" . ($r ? 'OK id='.$pdo->lastInsertId() : 'FAIL'));
                        } catch (Exception $e) { webhook_log('CMT DB ERR: ' . $e->getMessage()); }

                                                                     // Do not retry on 4xx client errors
                        $detected_phone = '';
                        if (preg_match('/(03|05|07|08|09)+([0-9]{8})\b/', $text, $matches)) {
                            $detected_phone = $matches[0];
                        }
                        $detected_province = detect_vietnam_province($text);
                        
                        try {
                            $stmt_cust = $pdo->prepare("
                                INSERT INTO fb_customers (page_id, sender_id, name, phone, province, last_message_at, customer_last_message_at)
                                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                                ON DUPLICATE KEY UPDATE
                                    name = VALUES(name),
                                    phone = COALESCE(NULLIF(VALUES(phone), ''), phone),
                                    province = COALESCE(NULLIF(VALUES(province), ''), province),
                                    last_message_at = CURRENT_TIMESTAMP,
                                    customer_last_message_at = CURRENT_TIMESTAMP
                            ");
                            $stmt_cust->execute([
                                $page_id, 
                                $sender_id, 
                                $sender_name, 
                                $detected_phone ?: null, 
                                $detected_province ?: null
                            ]);
                            
                                                                         // Do not retry on 4xx client errors
                            if ($detected_phone) {
                                $stmt_lbl = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
                                $stmt_lbl->execute(['c_' . $comment_id, $page_id, $sender_id, 'Đã cho số điện thoại']);
                            }
                        } catch (Exception $e) {
                            webhook_log("COMMENT CUST SAVE ERR: " . $e->getMessage());
                        }

                        // ── AUTO REPLY & AUTO PRIVATE INBOX FOR NEW COMMENTS ──
                        if (!empty($comment_id) && ($verb === 'add' || empty($verb))) {
                            process_auto_comment_reply($pdo, $page_id, $comment_id, $sender_id, $sender_name, $text);
                        }
                    }
                }
            }
        }
    }

if (!headers_sent()) {
    http_response_code(200);
    echo "EVENT_RECEIVED";
}
?>
