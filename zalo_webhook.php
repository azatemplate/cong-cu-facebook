<?php
// zalo_webhook.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/zalo_api.php';
require_once __DIR__ . '/includes/security.php';

// Log webhook for debugging
$rawData = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$logFile = __DIR__ . '/zalo_webhook_debug.log';
$logEntry = "[" . date('Y-m-d H:i:s') . "] IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
$logEntry .= "Headers: " . json_encode($headers) . "\n";
$logEntry .= "Payload: " . $rawData . "\n\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

$data = json_decode($rawData, true);

if (!$data) {
    http_response_code(400);
    echo "Invalid JSON";
    exit;
}

$appId = $data['app_id'] ?? '';
$timeStamp = $data['timestamp'] ?? '';

// 1. Get OA Secret Key from DB based on appId
$stmt = $pdo->prepare("SELECT oa_secret, account_id FROM zalo_settings WHERE app_id = ?");
$stmt->execute([$appId]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings || empty($settings['oa_secret'])) {
    // Return 200 OK so that Zalo Developer dashboard verification passes during configuration
    http_response_code(200);
    echo "OK (Pending app configuration in settings)";
    exit;
}

$secretKey = decryptData($settings['oa_secret']);
$acc_id = $settings['account_id'];

// 2. Validate Signature
$receivedSignature = $_SERVER['HTTP_X_ZEVENT_SIGNATURE'] 
    ?? $_SERVER['HTTP_X_ZALO_SIGNATURE'] 
    ?? '';
if (empty($receivedSignature) && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'X-ZEvent-Signature') === 0 || strcasecmp($name, 'X-Zalo-Signature') === 0) {
            $receivedSignature = $value;
            break;
        }
    }
}

// Strip "mac=" prefix if present
if (strpos($receivedSignature, 'mac=') === 0) {
    $receivedSignature = substr($receivedSignature, 4);
}

$content = $appId . $rawData . $timeStamp . $secretKey;
$calculatedSignature = hash('sha256', $content);

if (!hash_equals($calculatedSignature, $receivedSignature)) {
    // Log signature mismatch
    error_log("Zalo signature verification failed. Calculated: $calculatedSignature, Received: $receivedSignature");
    
    // Return 200 OK so Zalo webhook test passes, but do not process the payload further
    http_response_code(200);
    echo "OK (Signature verification bypassed for validation)";
    exit;
}

// 3. Extract customer and message info
$event_name = $data['event_name'] ?? '';
$sender_id = $data['sender']['id'] ?? '';
$oa_id = $data['recipient']['id'] ?? '';

if (empty($sender_id) || empty($oa_id)) {
    http_response_code(200);
    echo "IGNORED: Missing sender or recipient";
    exit;
}

$is_message = false;
$snippet = '';

if ($event_name === 'user_send_text') {
    $is_message = true;
    $snippet = $data['message']['text'] ?? '';
} elseif ($event_name === 'user_send_image') {
    $is_message = true;
    $snippet = '[Hình ảnh]';
}

if (!$is_message) {
    http_response_code(200);
    echo "EVENT_IGNORED";
    exit;
}

// 4. Update or Insert Customer Profile
$access_token = zalo_get_active_token($oa_id, $pdo);
$cust_name = 'Khách hàng Zalo';
$cust_avatar = 'https://ui-avatars.com/api/?name=Zalo';
$cust_phone = '';
$cust_province = '';
$cust_notes = '';

try {
    $stmt_cust = $pdo->prepare("SELECT name, avatar, phone, province, notes FROM zalo_customers WHERE oa_id = ? AND sender_id = ?");
    $stmt_cust->execute([$oa_id, $sender_id]);
    $cust = $stmt_cust->fetch(PDO::FETCH_ASSOC);

    if ($cust) {
        $cust_name = $cust['name'];
        $cust_avatar = $cust['avatar'];
        $cust_phone = $cust['phone'];
        $cust_province = $cust['province'];
        $cust_notes = $cust['notes'];
    }

    if ($access_token && (!$cust || empty($cust['name']) || $cust['name'] === 'Khách hàng Zalo' || empty($cust['avatar']) || strpos($cust['avatar'], 'ui-avatars.com') !== false)) {
        // Fetch from Zalo API
        $profile = zalo_get_customer_profile($access_token, $sender_id);
        if ($profile) {
            $cust_name = $profile['display_name'] ?? ($profile['displayName'] ?? ($profile['sharedInfo']['name'] ?? 'Khách hàng Zalo'));
            $cust_avatar = $profile['avatar'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($cust_name);
            if (empty($cust_province) && !empty($profile['sharedInfo']['city'])) {
                $cust_province = detect_vietnam_province($profile['sharedInfo']['city']);
            }
            
            $stmt_ins = $pdo->prepare("
                INSERT INTO zalo_customers (oa_id, sender_id, name, avatar, phone, province, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    name = VALUES(name),
                    avatar = VALUES(avatar),
                    province = COALESCE(NULLIF(VALUES(province), ''), province)
            ");
            $stmt_ins->execute([$oa_id, $sender_id, $cust_name, $cust_avatar, $cust_phone, $cust_province, $cust_notes]);
        }
    }
    // 5. Update Conversation Snippet & Unread Count in zalo_messages
    $stmt_msg = $pdo->prepare("
        INSERT INTO zalo_messages (oa_id, sender_id, sender_name, type, snippet, unread_count, updated_time)
        VALUES (?, ?, ?, 'message', ?, 1, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
            sender_name = COALESCE(VALUES(sender_name), sender_name),
            snippet = VALUES(snippet),
            unread_count = unread_count + 1,
            updated_time = CURRENT_TIMESTAMP
    ");
    $stmt_msg->execute([$oa_id, $sender_id, $cust_name, $snippet]);

    // 5b. Insert Zalo Message Notification for the Header bell
    try {
        $stmt_notif = $pdo->prepare("
            INSERT INTO page_notifications (page_id, type, sender_id, sender_name, conversation_id, snippet) 
            VALUES (?, 'message', ?, ?, ?, ?)
        ");
        $stmt_notif->execute([$oa_id, $sender_id, $cust_name, $sender_id, $snippet]);
    } catch (Exception $e) {
        error_log("Failed to insert Zalo notification: " . $e->getMessage());
    }

    // 6. Check Chatbot lock (Manual hand-off)
    $stmt_lock = $pdo->prepare("SELECT expire_at FROM zalo_chat_locks WHERE oa_id = ? AND sender_id = ? AND expire_at > NOW()");
    $stmt_lock->execute([$oa_id, $sender_id]);
    $is_locked = (bool)$stmt_lock->fetch();

    if ($is_locked) {
        http_response_code(200);
        echo "LOCKED: Chatbot disabled due to manual admin activity";
        exit;
    }

    // 7. Bot AI Reply
    if ($event_name === 'user_send_text' && !empty($snippet)) {
        // Query bot AI rules
        $st_ai = $pdo->prepare("SELECT * FROM bot_chat_rules WHERE account_id = ? AND is_active = 1 AND rule_type = 'ai_reply'");
        $st_ai->execute([$acc_id]);
        $ai_rules_all = $st_ai->fetchAll(PDO::FETCH_ASSOC);
        
        $ai_rule = null;
        foreach ($ai_rules_all as $r) {
            $match_scope = false;
            if ($r['pages_scope'] === 'ALL') {
                $match_scope = true;
            } else {
                $scope_arr = @json_decode($r['pages_scope'], true);
                if (is_array($scope_arr) && in_array($oa_id, $scope_arr)) {
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
            
            // Handle message delays / merging
            if ($delay_s > 0) {
                // Check if another webhook thread is already waiting
                $stmt_lock_check = $pdo->prepare("SELECT expire_at FROM zalo_chat_locks WHERE oa_id = ? AND sender_id = ? AND expire_at > NOW()");
                $stmt_lock_check->execute([$oa_id, $sender_id]);
                if ($stmt_lock_check->fetch()) {
                    // Already waiting, let that process handle sending the reply
                    http_response_code(200);
                    echo "WAITING: Merging message in background thread";
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    exit;
                } else {
                    // Set temporary merging lock
                    $stmt_ins_lock = $pdo->prepare("
                        INSERT INTO zalo_chat_locks (oa_id, sender_id, expire_at)
                        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                        ON DUPLICATE KEY UPDATE expire_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                    ");
                    $stmt_ins_lock->execute([$oa_id, $sender_id, $delay_s, $delay_s]);
                    
                    // Flush response to Zalo
                    http_response_code(200);
                    echo "EVENT_RECEIVED";
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    
                    // Wait for more messages
                    sleep($delay_s);
                    
                    // Delete merging lock
                    $stmt_del_lock = $pdo->prepare("DELETE FROM zalo_chat_locks WHERE oa_id = ? AND sender_id = ?");
                    $stmt_del_lock->execute([$oa_id, $sender_id]);
                }
            }

            // Fetch history from Zalo OA API
            $data_param = json_encode([
                'user_id' => $sender_id,
                'offset' => 0,
                'count' => 10
            ]);
            $history_url = ZALO_API_BASE . 'v2.0/oa/conversation?data=' . urlencode($data_param);
            $history_headers = ["access_token: {$access_token}"];
            $history_res = zalo_api_request($history_url, 'GET', $history_headers);
            
            $history_text = '';
            $user_merged_text = $snippet;
            
            if ($history_res['status_code'] === 200 && isset($history_res['data']['error']) && $history_res['data']['error'] === 0) {
                $history_msgs = $history_res['data']['data'] ?? [];
                
                // If delay occurred, merge user messages within the delay window
                if ($delay_s > 0) {
                    $merged_parts = [];
                    $cutoff_time = (time() - $delay_s - 2) * 1000;
                    
                    foreach ($history_msgs as $msg) {
                        if (isset($msg['src']) && $msg['src'] == 1 && $msg['time'] >= $cutoff_time) {
                            if (isset($msg['type']) && $msg['type'] === 'text' && !empty($msg['message'])) {
                                $merged_parts[] = trim($msg['message']);
                            }
                        }
                    }
                    if (!empty($merged_parts)) {
                        $merged_parts = array_reverse($merged_parts);
                        $user_merged_text = implode("\n", $merged_parts);
                    }
                }
                
                // Construct history text
                $history_count = (int)($ai_rule['history_count'] ?? 6);
                $sliced_history = array_slice($history_msgs, 0, $history_count);
                $sliced_history = array_reverse($sliced_history);
                
                foreach ($sliced_history as $msg) {
                    if (empty($msg['message'])) continue;
                    $role = ($msg['src'] == 0) ? "Bạn (Cửa hàng)" : "Khách hàng";
                    $history_text .= "$role: " . $msg['message'] . "\n";
                }
            }

            // Build AI prompt context
            $info_context = "\n\n--- THÔNG TIN KHÁCH HÀNG ĐÃ CÓ ---\n";
            $info_context .= "- Tên khách hàng: " . ($cust_name ?: "CHƯA CÓ") . "\n";
            $info_context .= "- Số điện thoại: " . ($cust_phone ?: "CHƯA CÓ") . "\n";
            $info_context .= "- Tỉnh thành: " . ($cust_province ?: "CHƯA CÓ") . "\n";
            $info_context .= "- Nhu cầu/Yêu cầu khách hàng: " . ($cust_notes ?: "CHƯA CÓ") . "\n";
            $info_context .= "-----------------------------------\n";
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
            $json_instruction .= '    "requirements": "Nhu cầu/yêu cầu đầy đủ nhất của khách hàng đã được cập nhật hoặc bổ sung thêm thông tin mới. Hãy đối chiếu với mục Nhu cầu/Yêu cầu khách hàng trong THÔNG TIN KHÁCH HÀNG ĐÃ CÓ ở trên: nếu khách bổ sung chi tiết cho sản phẩm cũ (ví dụ: ban đầu là \'cùm giáo\' sau đó nói thêm \'100 cái\' -> trả về \'Cùm giáo - 100 cái\'), hoặc khách bổ sung thêm sản phẩm/nhu cầu mới khác (ví dụ: ban đầu là \'Cùm giáo - 100 cái\', sau đó quay lại bảo mua thêm \'100 mét ty ren\' -> hãy ghép nối và trả về toàn bộ nhu cầu tích lũy: \'Cùm giáo - 100 cái, mua thêm 100 mét ty ren\'). Nếu không có thông tin gì mới hoặc không thay đổi, trả về null"\n';
            $json_instruction .= "  }\n";
            $json_instruction .= "}\n";

            $custom_system_prompt = $ai_rule['message'] . $info_context . $json_instruction;
            
            require_once __DIR__ . '/includes/ai_rewriter.php';
            
            $oa_name = '';
            $stmt_oa_name = $pdo->prepare("SELECT name FROM zalo_oas WHERE oa_id = ?");
            $stmt_oa_name->execute([$oa_id]);
            if ($oa_row = $stmt_oa_name->fetch()) {
                $oa_name = $oa_row['name'];
            }

            // Run AI
            $ai_reply_text = generate_chat_reply_with_ai($user_merged_text, $custom_system_prompt, $acc_id, $oa_name, $history_text);
            
            $reply_to_send = '';
            if (!empty($ai_reply_text)) {
                $parsed_json = json_decode(clean_json_response($ai_reply_text), true);
                if (is_array($parsed_json) && isset($parsed_json['reply'])) {
                    $reply_to_send = $parsed_json['reply'];
                    
                    // Update customer DB fields if extracted
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
                        $ai_upd_params[] = $oa_id;
                        $ai_upd_params[] = $sender_id;
                        $st_ai_upd = $pdo->prepare("UPDATE zalo_customers SET " . implode(", ", $ai_upd_fields) . " WHERE oa_id = ? AND sender_id = ?");
                        $st_ai_upd->execute($ai_upd_params);
                    }
                } else {
                    $reply_to_send = $ai_reply_text;
                }
                
                // Regular PHP checks
                $php_detected_phone = '';
                if (preg_match('/(03|05|07|08|09)+([0-9]{8})\b/', $user_merged_text, $matches)) {
                    $php_detected_phone = $matches[0];
                }
                $php_detected_province = detect_vietnam_province($user_merged_text);
                
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
                    $php_upd_vals[] = $oa_id;
                    $php_upd_vals[] = $sender_id;
                    $st_php_upd = $pdo->prepare("UPDATE zalo_customers SET " . implode(", ", $php_upd_cols) . " WHERE oa_id = ? AND sender_id = ?");
                    $st_php_upd->execute($php_upd_vals);
                }
                
                // Send reply to Zalo
                if (!empty($reply_to_send)) {
                    $send_res = zalo_send_text_message($access_token, $sender_id, $reply_to_send);
                    if (isset($send_res['status_code']) && $send_res['status_code'] === 200 && isset($send_res['data']['error']) && $send_res['data']['error'] === 0) {
                        $stmt_upd_thread = $pdo->prepare("
                            INSERT INTO zalo_messages (oa_id, sender_id, sender_name, snippet, unread_count, updated_time)
                            VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE 
                                snippet = VALUES(snippet),
                                unread_count = 0,
                                updated_time = CURRENT_TIMESTAMP
                        ");
                        $stmt_upd_thread->execute([$oa_id, $sender_id, $cust_name, $reply_to_send]);
                    }
                }
            }
        }
    }

    // Return status 200 to Zalo
    if (!headers_sent()) {
        http_response_code(200);
        echo "OK";
    }
} catch (Exception $e) {
    error_log("Zalo webhook exception: " . $e->getMessage());
    http_response_code(500);
    echo "Internal Error: " . $e->getMessage();
}

/**
 * Detect Vietnam Province inside text
 */
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
        'An Giang', 'Ba Ria - Vung Tau', 'Ba Ria Vung Tau', 'Vung Tau', 'Bac Giang', 'Bac Kan', 'Bac Lieu', 'Bac Ninh', 'Ben Tre', 'Binh Dinh', 
        'Binh Duong', 'Binh Phuoc', 'Binh Thuan', 'Ca Mau', 'Can Tho', 'Cao Bang', 'Da Nang', 'Dak Lak', 'Dak Nong', 'Dien Bien', 
        'Dong Nai', 'Dong Thap', 'Gia Lai', 'Ha Giang', 'Ha Nam', 'Ha Noi', 'Ha Tinh', 'Hai Duong', 'Hai Phong', 'Hau Giang', 
        'Hoa Binh', 'Hung Yen', 'Khanh Hoa', 'Nha Trang', 'Kien Giang', 'Kon Tum', 'Lai Chau', 'Lam Dong', 'Da Lat', 'Lang Son', 
        'Lao Cai', 'Long An', 'Nam Dinh', 'Nghe An', 'Ninh Binh', 'Ninh Thuận', 'Phu Tho', 'Phu Yen', 'Quang Binh', 'Quang Nam', 
        'Quang Ngai', 'Quang Ninh', 'Quang Tri', 'Soc Trang', 'Son La', 'Tay Ninh', 'Thai Binh', 'Thai Nguyen', 'Thanh Hoa', 
        'Thua Thien Hue', 'Hue', 'Tien Giang', 'Tra Vinh', 'Tuyen Quang', 'Vinh Long', 'Vinh Phuc', 'Yen Bai', 'Sai Gon', 'Ho Chi Minh'
    ];
    
    $text_lower = mb_strtolower($text, 'UTF-8');
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
?>
