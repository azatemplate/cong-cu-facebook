<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/ai_rewriter.php';

// Cấu hình mã xác minh (Verify Token) cho Webhook
$verify_token = 'HVP_WEBHOOK_VERIFY_TOKEN_2026'; // Bạn có thể đổi mã này và nhập vào form cấu hình Webhook trên Facebook App

// 1. Xử lý yêu cầu xác minh từ Facebook (GET Request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hub_mode      = $_GET['hub_mode'] ?? '';
    $hub_verify    = $_GET['hub_verify_token'] ?? '';
    $hub_challenge = $_GET['hub_challenge'] ?? '';

    if ($hub_mode === 'subscribe' && $hub_verify === $verify_token) {
        // Facebook yêu cầu trả về hub_challenge để xác minh thành công
        echo $hub_challenge;
        http_response_code(200);
        exit;
    } else {
        http_response_code(403);
        echo "Token xác minh không khớp.";
        exit;
    }
}

// 2. Xử lý dữ liệu gửi đến từ Facebook (POST Request)
// Yêu cầu lấy dữ liệu thô
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
        'Binh Duong', 'Binh Phuoc', 'Binh Thuan', 'Ca Mau', 'Can Tho', 'Cao Bang', 'Da Nang', 'Dak Lak', 'Dak Nong', 'Dien Bien', 
        'Dong Nai', 'Dong Thap', 'Gia Lai', 'Ha Giang', 'Ha Nam', 'Ha Noi', 'Ha Tinh', 'Hai Duong', 'Hai Phong', 'Hau Giang', 
        'Hoa Binh', 'Hung Yen', 'Khanh Hoa', 'Nha Trang', 'Kien Giang', 'Kon Tum', 'Lai Chau', 'Lam Dong', 'Da Lat', 'Lang Son', 
        'Lao Cai', 'Long An', 'Nam Dinh', 'Nghe An', 'Ninh Binh', 'Ninh Thuan', 'Phu Tho', 'Phu Yen', 'Quang Binh', 'Quang Nam', 
        'Quang Ngai', 'Quang Ninh', 'Quang Tri', 'Soc Trang', 'Son La', 'Tay Ninh', 'Thai Binh', 'Thai Nguyen', 'Thanh Hoa', 
        'Thua Thien Hue', 'Hue', 'Tien Giang', 'Tra Vinh', 'Tuyen Quang', 'Vinh Long', 'Vinh Phuc', 'Yen Bai', 'Sai Gon', 'Ho Chi Minh'
    ];
    
    $text_lower = mb_strtolower($text, 'UTF-8');
    
    // Sắp xếp tỉnh thành theo độ dài giảm dần để khớp các cụm từ dài trước (tránh khớp đè)
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

if ($data && isset($data['object']) && $data['object'] === 'page') {
    
    // Tự động tạo bảng nếu chưa có (dành cho môi trường mới chưa chạy script tạo bảng)
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

    // Migration cho các cột quảng cáo (Ads Tracking) nếu chưa có trong fb_customers
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

        // Kiểm tra xem có field 'messaging' (Tin nhắn mới) không
        if (isset($entry['messaging'])) {
            foreach ($entry['messaging'] as $messaging_event) {
                $is_message = isset($messaging_event['message']) && !isset($messaging_event['message']['is_echo']);
                $is_postback = isset($messaging_event['postback']);

                if ($is_message || $is_postback) {
                    $sender_id = $messaging_event['sender']['id'];
                    $text = '';
                    $is_welcome_trigger = false;

                    if ($is_message) {
                        $text = $messaging_event['message']['text'] ?? 'Đã gửi một tệp đính kèm';
                    } elseif ($is_postback) {
                        $payload = $messaging_event['postback']['payload'] ?? '';
                        $is_welcome_trigger = true;
                        $text = '[Hành động: Bấm nút/Bắt đầu]';
                    }
                    
                    // Trích xuất thông tin quảng cáo (Ads Tracking) từ sự kiện referral
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
                    
                    // Lấy thông tin người gửi và conversation_id qua Graph API
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
                                    
                                    // Tìm participants để lấy tên
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

                    // Lưu trực tiếp vào Database & Kiểm tra tin nhắn đầu tiên
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

                        // Cập nhật cuộc hội thoại đệm fb_conversations
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

                        // Đảm bảo thông tin khách hàng được khởi tạo/cập nhật trong fb_customers
                        try {
                            $stmt_cust = $pdo->prepare("
                                INSERT INTO fb_customers (page_id, sender_id, name, last_sender, last_message_at, info_request_count, followup_requested_at, is_ads, ad_id, ad_title, ad_photo_url) 
                                VALUES (?, ?, ?, 'customer', CURRENT_TIMESTAMP, 0, NULL, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                    name = VALUES(name),
                                    last_sender = 'customer',
                                    last_message_at = CURRENT_TIMESTAMP,
                                    followup_requested_at = IF(consulted IN (1, 3) AND last_message_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR), NULL, followup_requested_at),
                                    consulted = IF(consulted IN (1, 3) AND last_message_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR), 2, consulted),
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
                                
                                // Tự động gán nhãn Đã cho số điện thoại nếu có SĐT trong DB
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

                    // ===== BẮT ĐẦU BOT CHAT LOGIC =====
                    try {
                        // Lấy token và account_id (nếu chưa có ở trên)
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

                            // Kiểm tra trạng thái tư vấn (consulted = 3 nghĩa là Dừng tư vấn)
                            $stmt_cust_status = $pdo->prepare("SELECT consulted FROM fb_customers WHERE page_id = ? AND sender_id = ?");
                            $stmt_cust_status->execute([$page_id, $sender_id]);
                            $cust_consulted = (int)$stmt_cust_status->fetchColumn();

                            if ($is_locked || $cust_consulted === 3) {
                                webhook_log("BOT_CHAT_LOCKED: Chatbot disabled due to active lock or consulted status = 3 for sender $sender_id on page $page_id");
                            } else {
                                // Hàm xử lý gửi tin nhắn
                                $send_bot_msg = function($msg_text) use ($sender_id, $sender_name, $page_token, $page_id, $pdo) {
                                $lines = array_filter(explode("\n", str_replace("\r", "", $msg_text)), 'trim');
                                if (empty($lines)) return;
                                $chosen_msg = $lines[array_rand($lines)];
                                $final_msg = str_replace('{name}', $sender_name, $chosen_msg);

                                $url = "https://graph.facebook.com/v25.0/me/messages?access_token={$page_token}";
                                $post_data = json_encode([
                                    'recipient' => ['id' => $sender_id],
                                    'message' => ['text' => $final_msg],
                                    'messaging_type' => 'RESPONSE'
                                ]);
                                
                                $ch = curl_init($url);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                                $output = curl_exec($ch);
                                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                curl_close($ch);
                                webhook_log("BOT_CHAT_REPLY: " . $output);

                                if ($http_code === 200) {
                                    try {
                                        $st_upd = $pdo->prepare("UPDATE fb_customers SET last_sender = 'agent', last_message_at = CURRENT_TIMESTAMP WHERE page_id = ? AND sender_id = ?");
                                        $st_upd->execute([$page_id, $sender_id]);
                                    } catch (Exception $e) {}
                                }
                            };

                            $matched_rule = false;

                            // 1. Kiểm tra Welcome rule (nếu là tin nhắn đầu tiên hoặc postback)
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

                            // 2. Kiểm tra Keyword rule (nếu không phải welcome và là tin nhắn text)
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
                                        break; // Chỉ trả lời 1 rule đầu tiên khớp
                                    }
                                }
                            }

                            // 3. Kiểm tra AI Reply rule (nếu không phải welcome và không khớp keyword)
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
                                    
                                    // Bắt đầu gộp tin nhắn nếu có thời gian chờ
                                    if ($delay_s > 0) {
                                        // Kiểm tra xem đã có tiến trình nào đang chờ gộp tin cho user này chưa
                                        $stmt_lock = $pdo->prepare("SELECT expire_at FROM bot_chat_locks WHERE page_id=? AND sender_id=? AND expire_at > NOW()");
                                        $stmt_lock->execute([$page_id, $sender_id]);
                                        if ($stmt_lock->fetch()) {
                                            // Đã có process đang sleep. Process này chỉ lưu tin (đã làm) và dừng lại.
                                            http_response_code(200);
                                            echo "EVENT_RECEIVED";
                                            if (function_exists('fastcgi_finish_request')) {
                                                fastcgi_finish_request();
                                            }
                                            exit;
                                        } else {
                                            // Tạo lock mới cho tiến trình này
                                            $stmt_ins_lock = $pdo->prepare("INSERT INTO bot_chat_locks (page_id, sender_id, expire_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE expire_at = DATE_ADD(NOW(), INTERVAL ? SECOND)");
                                            $stmt_ins_lock->execute([$page_id, $sender_id, $delay_s, $delay_s]);
                                            
                                            // Ngắt HTTP connection sớm để FB không bị chờ
                                            http_response_code(200);
                                            echo "EVENT_RECEIVED";
                                            if (function_exists('fastcgi_finish_request')) {
                                                fastcgi_finish_request();
                                            }
                                            
                                            // Tiến hành ngủ để gom các tin nhắn tới sau
                                            sleep($delay_s);
                                            
                                            // Thức dậy: Xóa lock
                                            $stmt_del_lock = $pdo->prepare("DELETE FROM bot_chat_locks WHERE page_id=? AND sender_id=?");
                                            $stmt_del_lock->execute([$page_id, $sender_id]);
                                            
                                            // Gom toàn bộ tin nhắn của user này trong khoảng X giây qua
                                            // Cộng thêm 3 giây trừ hao thời gian truy vấn
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
                                    }

                                    $page_name = '';
                                    $stmt_page = $pdo->prepare("SELECT name FROM pages WHERE page_id=?");
                                    $stmt_page->execute([$page_id]);
                                    if($p_row = $stmt_page->fetch(PDO::FETCH_ASSOC)) {
                                        $page_name = $p_row['name'];
                                    }

                                    // Lấy lịch sử trò chuyện (tối đa N tin nhắn gần nhất)
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

                                    // Truy vấn thông tin khách hàng hiện tại từ DB
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

                                    // Thiết lập ngữ cảnh thông tin và hướng dẫn thu thập cho AI Chatbot
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
                                    $info_context .= "HƯỚNG DẪN BẮT BUỘC: Bạn là chatbot chăm sóc khách hàng chuyên nghiệp. Hãy kiểm tra các thông tin ở trên:\n";
                                    $info_context .= "1. Với thông tin nào đã có (không phải là 'CHƯA CÓ'), bạn tuyệt đối không được hỏi lại khách hàng nữa.\n";
                                    $info_context .= "2. Với thông tin nào ghi 'CHƯA CÓ', hãy khéo léo và thân thiện hỏi khách hàng trong quá trình trò chuyện. Hỏi từng thông tin một cách tự nhiên, không hỏi dồn dập.\n";
                                    $info_context .= "3. Khi đã thu thập đủ cả 3 thông tin (Số điện thoại, Tỉnh thành, Nhu cầu), hãy tóm tắt lại và gửi lời cảm ơn khách hàng.\n";

                                    $json_instruction = "\n\nQUY ĐỊNH PHẢN HỒI: Để đồng bộ thông tin vào hệ thống quản lý, bạn BẮT BUỘC phải phản hồi dưới định dạng JSON duy nhất (không bọc trong thẻ ```json hay bất kỳ chữ giải thích nào khác ngoài cấu trúc JSON), nội dung như sau:\n";
                                    $json_instruction .= "{\n";
                                    $json_instruction .= '  "reply": "Nội dung tin nhắn bạn muốn trả lời khách hàng (viết bằng tiếng Việt tự nhiên)",\n';
                                    $json_instruction .= '  "extracted": {\n';
                                    $json_instruction .= '    "phone": "Số điện thoại phát hiện được trong tin nhắn mới của khách hàng (nếu có, không lấy số cũ), nếu khách hàng gửi lại số điện thoại khác thì trả về số mới, nếu không có trả về null",\n';
                                    $json_instruction .= '    "province": "Tỉnh thành phát hiện được trong tin nhắn mới của khách hàng (nếu có, không lấy tỉnh cũ), nếu không có trả về null",\n';
                                    $json_instruction .= '    "requirements": "Nhu cầu/yêu cầu đầy đủ nhất của khách hàng đã được cập nhật hoặc bổ sung thêm thông tin mới. Hãy đối chiếu với mục Nhu cầu/Yêu cầu khách hàng trong THÔNG TIN KHÁCH HÀNG ĐÃ CÓ ở trên để cập nhật hoặc tích lũy một cách chính xác theo các nguyên tắc sau:\n1. BẮT BUỘC phải trích xuất ngay tên sản phẩm khi khách hàng đề cập, dù khách hàng chưa cung cấp số lượng (ví dụ: khách nói \'tôi muốn mua cùm giáo\' -> lập tức cập nhật \'Cùm giáo\'). Không được bỏ qua hay chờ số lượng.\n2. Nếu khách hàng bổ sung số lượng cho sản phẩm đã nói trước đó (ví dụ: thông tin cũ là \'Cùm giáo\', nay khách nói thêm \'lấy cho em 50 cái\' -> cập nhật tích lũy thành \'Cùm giáo - 50 cái\').\n3. Nếu khách hàng bổ sung thêm sản phẩm/yêu cầu mới khác (ví dụ: thông tin cũ là \'Cùm giáo - 50 cái\', nay khách nói mua thêm \'100m ty ren\' -> tích lũy thêm thành \'Cùm giáo - 50 cái, 100m ty ren\').\nNếu khách hàng không đề cập gì thêm về sản phẩm/nhu cầu hoặc không có thông tin thay đổi so với thông tin đã có, trả về null"\n';
                                    $json_instruction .= "  }\n";
                                    $json_instruction .= "}\n";
 
                                    $custom_system_prompt = $ai_rule['message'] . $info_context . $json_instruction;
                                    $ai_reply_text = generate_chat_reply_with_ai($text, $custom_system_prompt, $acc_id, $page_name, $history_text);
                                    
                                    $reply_to_send = '';
                                    if (!empty($ai_reply_text)) {
                                        // Thử giải mã JSON phản hồi của AI
                                        $parsed_json = json_decode(clean_json_response($ai_reply_text), true);
                                        if (is_array($parsed_json) && isset($parsed_json['reply'])) {
                                            $reply_to_send = $parsed_json['reply'];
                                            
                                            // Cập nhật CSDL nếu AI trích xuất được thông tin mới
                                            $ai_upd_fields = [];
                                            $ai_upd_params = [];
                                            
                                            if (!empty($parsed_json['extracted']['phone'])) {
                                                $new_phone = trim($parsed_json['extracted']['phone']);
                                                if ($new_phone !== $cust_phone) {
                                                    $ai_upd_fields[] = "phone = ?";
                                                    $ai_upd_params[] = $new_phone;
                                                    $cust_phone = $new_phone;
                                                }
                                            }
                                            if (!empty($parsed_json['extracted']['province'])) {
                                                $new_province = trim($parsed_json['extracted']['province']);
                                                if ($new_province !== $cust_province) {
                                                    $ai_upd_fields[] = "province = ?";
                                                    $ai_upd_params[] = $new_province;
                                                    $cust_province = $new_province;
                                                }
                                            }
                                            if (!empty($parsed_json['extracted']['requirements'])) {
                                                $new_notes = trim($parsed_json['extracted']['requirements']);
                                                if ($new_notes !== $cust_notes) {
                                                    $ai_upd_fields[] = "notes = ?";
                                                    $ai_upd_params[] = $new_notes;
                                                    $cust_notes = $new_notes;
                                                }
                                            }
                                            
                                            if (!empty($ai_upd_fields)) {
                                                $ai_upd_params[] = $page_id;
                                                $ai_upd_params[] = $sender_id;
                                                $st_ai_upd = $pdo->prepare("UPDATE fb_customers SET " . implode(", ", $ai_upd_fields) . " WHERE page_id = ? AND sender_id = ?");
                                                $st_ai_upd->execute($ai_upd_params);
                                            }
                                        } else {
                                            // Fallback nếu AI không trả về JSON hợp lệ
                                            $reply_to_send = $ai_reply_text;
                                        }
                                        
                                        // Đồng thời luôn chạy regex và string detection của PHP để đảm bảo độ chính xác
                                        $php_detected_phone = '';
                                        if (preg_match('/(03|05|07|08|09)+([0-9]{8})\b/', $text, $matches)) {
                                            $php_detected_phone = $matches[0];
                                        }
                                        $php_detected_province = detect_vietnam_province($text);
                                        
                                        $php_upd_cols = [];
                                        $php_upd_vals = [];
                                        if ($php_detected_phone && $php_detected_phone !== $cust_phone) {
                                            $php_upd_cols[] = "phone = ?";
                                            $php_upd_vals[] = $php_detected_phone;
                                            $cust_phone = $php_detected_phone;
                                        }
                                        if ($php_detected_province && $php_detected_province !== $cust_province) {
                                            $php_upd_cols[] = "province = ?";
                                            $php_upd_vals[] = $php_detected_province;
                                            $cust_province = $php_detected_province;
                                        }
                                        
                                        if (!empty($php_upd_cols)) {
                                            $php_upd_vals[] = $page_id;
                                            $php_upd_vals[] = $sender_id;
                                            $st_php_upd = $pdo->prepare("UPDATE fb_customers SET " . implode(", ", $php_upd_cols) . " WHERE page_id = ? AND sender_id = ?");
                                            $st_php_upd->execute($php_upd_vals);
                                        }
                                        
                                        // Gán nhãn "Đã cho số điện thoại" nếu có SĐT
                                        if (!empty($cust_phone) && !empty($conversation_id)) {
                                            try {
                                                $stmt_lbl = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
                                                $stmt_lbl->execute([$conversation_id, $page_id, $sender_id]);
                                            } catch (Exception $e) {}
                                        }
                                        
                                        if (!empty($reply_to_send)) {
                                            $send_bot_msg($reply_to_send);
                                            $matched_rule = true;
                                        }
                                    }
                                    
                                    // Nếu process này đã gộp (đã sleep), thì sau khi gửi tin nhắn ta nên kết thúc script luôn
                                    // Vì đã gửi response 200 OK ở trên rồi
                                    if ($delay_s > 0) {
                                        exit;
                                    }
                                }
                            }
                            } // End of if (!$is_locked)
                        }
                    } catch (Exception $e) { webhook_log('BOT CHAT LOGIC ERR: ' . $e->getMessage()); }
                    // ===== KẾT THÚC BOT CHAT LOGIC =====
                }
            }
        }
        
        // Kiểm tra xem có field 'changes' (Comments, Posts mới thuộc Feed) không
        if (isset($entry['changes'])) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'feed') {
                    $val = $change['value'];
                    // Chỉ lấy bình luận mới (item = comment) và không phải do chính page bình luận (ẩn danh / tự reply)
                    if ($val['item'] === 'comment' && $val['verb'] === 'add') {
                        $sender_id   = $val['from']['id'] ?? '';
                        $sender_name = $val['from']['name'] ?? 'Khách hàng';
                        $comment_id  = $val['comment_id'] ?? '';
                        $post_id     = $val['post_id'] ?? '';
                        $text        = $val['message'] ?? '';
                        
                        // Bỏ qua nếu ng gửi chính là Page
                        if ($sender_id === $page_id) continue;

                        try {
                            $stmt = $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_id, sender_name, snippet, post_id, comment_id) VALUES (?, 'comment', ?, ?, ?, ?, ?)");
                            $r = $stmt->execute([$page_id, $sender_id, $sender_name, $text, $post_id, $comment_id]);
                            webhook_log("CMT INSERT: page=$page_id post=$post_id sender=$sender_name result=" . ($r ? 'OK id='.$pdo->lastInsertId() : 'FAIL'));
                        } catch (Exception $e) { webhook_log('CMT DB ERR: ' . $e->getMessage()); }

                        // ── Bắt đầu Phản Hồi Tự Động ──
                        try {
                            // Lấy access_token và account_id
                            $stmt = $pdo->prepare("SELECT p.access_token, u.account_id FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ?");
                            $stmt->execute([$page_id]);
                            $page_info = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($page_info && !empty($page_info['access_token'])) {
                                $page_token = decryptData($page_info['access_token']);
                                $acc_id = $page_info['account_id'];

                                // Lấy cấu hình tự động của tài khoản
                                $stmt_acc = $pdo->prepare("SELECT auto_reply_enabled, auto_reply_text, auto_inbox_enabled, auto_inbox_text, auto_pages_scope FROM system_accounts WHERE id = ?");
                                $stmt_acc->execute([$acc_id]);
                                $acc_setup = $stmt_acc->fetch(PDO::FETCH_ASSOC);

                                if ($acc_setup) {
                                    $is_page_allowed = false;
                                    $scope = $acc_setup['auto_pages_scope'] ?? 'ALL';
                                    if ($scope === 'ALL') {
                                        $is_page_allowed = true;
                                    } else {
                                        $scope_arr = @json_decode($scope, true);
                                        if (is_array($scope_arr) && in_array($page_id, $scope_arr)) {
                                            $is_page_allowed = true;
                                        }
                                    }

                                    if ($is_page_allowed) {
                                        // 1. Tự động Phản hồi (Public Comment)
                                    if (!empty($acc_setup['auto_reply_enabled']) && !empty($acc_setup['auto_reply_text'])) {
                                        // Tách các mẫu câu theo dòng và chọn ngẫu nhiên
                                        $lines = array_filter(explode("\n", str_replace("\r", "", $acc_setup['auto_reply_text'])), 'trim');
                                        $chosen_msg = $lines[array_rand($lines)];
                                        
                                        $msg = str_replace('{name}', $sender_name, $chosen_msg);
                                        $url = "https://graph.facebook.com/v25.0/{$comment_id}/comments";
                                        $post_data = json_encode([
                                            'message' => $msg,
                                            'access_token' => $page_token
                                        ]);
                                        
                                        $ch = curl_init($url);
                                        curl_setopt($ch, CURLOPT_POST, 1);
                                        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
                                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                                        $output = curl_exec($ch);
                                        curl_close($ch);
                                        webhook_log("AUTO_REPLY: " . $output);
                                    }

                                    // 2. Tự động Nhắn tin (Private Inbox)
                                    if (!empty($acc_setup['auto_inbox_enabled']) && !empty($acc_setup['auto_inbox_text'])) {
                                        // Tách các mẫu câu theo dòng và chọn ngẫu nhiên
                                        $lines_inbox = array_filter(explode("\n", str_replace("\r", "", $acc_setup['auto_inbox_text'])), 'trim');
                                        $chosen_inbox = $lines_inbox[array_rand($lines_inbox)];

                                        $msg = str_replace('{name}', $sender_name, $chosen_inbox);
                                        $url = "https://graph.facebook.com/v25.0/me/messages?access_token={$page_token}";
                                        $post_data = json_encode([
                                            'recipient' => ['comment_id' => $comment_id],
                                            'message' => ['text' => $msg],
                                            'messaging_type' => 'RESPONSE'
                                        ]);
                                        
                                        $ch = curl_init($url);
                                        curl_setopt($ch, CURLOPT_POST, 1);
                                        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
                                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                        curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Giới hạn 3s để không chặn tiến trình webhook
                                        $output = curl_exec($ch);
                                        curl_close($ch);
                                        webhook_log("AUTO_INBOX: " . $output);
                                    }
                                 } // End of is_page_allowed
                            }
                        }
                    } catch (Exception $e) { webhook_log('AUTO_REPLY ERR: ' . $e->getMessage()); }
                    }
                }
            }
        }
    }
    
    // Trả về 200 OK để Facebook biết đã nhận được
    http_response_code(200);
    echo "EVENT_RECEIVED";
    exit;
}

// Không phải Object Page
http_response_code(404);
echo "NOT_A_PAGE_OBJECT";
exit;

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
?>
