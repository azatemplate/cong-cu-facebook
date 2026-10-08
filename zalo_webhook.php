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
$msg_id = $data['message']['msg_id'] ?? '';

if ($event_name === 'user_send_text') {
    $is_message = true;
    $snippet = $data['message']['text'] ?? '';
} elseif ($event_name === 'user_send_image') {
    $is_message = true;
    $snippet = '[Hình ảnh]';
} elseif ($event_name === 'user_send_file') {
    $is_message = true;
    $snippet = '[Tệp đính kèm]';
} elseif ($event_name === 'user_send_audio') {
    $is_message = true;
    $snippet = '[Tin nhắn thoại]';
} elseif ($event_name === 'user_send_link') {
    $is_message = true;
    $snippet = '[Liên kết]';
} elseif ($event_name === 'user_send_sticker') {
    $is_message = true;
    $snippet = '[Sticker]';
} elseif ($event_name === 'user_send_video') {
    $is_message = true;
    $snippet = '[Video]';
} elseif (in_array($event_name, ['user_send_business_card', 'user_send_card', 'user_send_contact_card', 'user_send_recommended', 'user_send_chat.recommended'])) {
    $is_message = true;
    $snippet = '[Danh thiếp]';
}

if (!$is_message) {
    http_response_code(200);
    echo "EVENT_IGNORED";
    exit;
}

// 🚀 PHẢN HỒI TỨC THÌ CHO ZALO WEBHOOK SERVER (< 10ms / dưới 0.01 giây)
http_response_code(200);
echo "OK";

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

// ── Save file/image/audio/video/namecard attachment metadata to DB ──────────────────────────
if (in_array($event_name, ['user_send_file', 'user_send_image', 'user_send_audio', 'user_send_video', 'user_send_business_card', 'user_send_card', 'user_send_contact_card', 'user_send_recommended', 'user_send_chat.recommended']) && !empty($msg_id)) {
    try {
        $attachments = $data['message']['attachments'] ?? [];
        if (!empty($attachments)) {
            $att = $attachments[0];
            $payload = $att['payload'] ?? [];
            $file_name = $payload['name'] ?? $payload['file_name'] ?? ($payload['title'] ?? ($payload['displayName'] ?? ''));
            $file_url = $payload['url'] ?? ($payload['avatar'] ?? ($payload['thumbnail'] ?? ''));
            $file_size = intval($payload['size'] ?? 0);
            $file_type = $att['type'] ?? $event_name;
            
            // Map event names to simpler types
            if ($file_type === 'user_send_file') $file_type = 'file';
            if ($file_type === 'user_send_image') $file_type = 'image';
            if ($file_type === 'user_send_audio') $file_type = 'audio';
            if ($file_type === 'user_send_video') $file_type = 'video';
            
            if (in_array($event_name, ['user_send_business_card', 'user_send_card', 'user_send_contact_card', 'user_send_recommended', 'user_send_chat.recommended']) || in_array($file_type, ['business_card', 'contact', 'recommended', 'namecard'])) {
                $file_type = 'namecard';
                $card_phone = $payload['phone'] ?? '';
                $card_avatar = $payload['thumbnail'] ?? ($payload['avatar'] ?? '');
                $qr_code_url = '';
                
                if (!empty($payload['description'])) {
                    $desc_raw = $payload['description'];
                    if (is_string($desc_raw) && (strpos($desc_raw, '{') === 0 || strpos($desc_raw, '[') === 0)) {
                        $desc_json = json_decode($desc_raw, true);
                        if (is_array($desc_json)) {
                            if (!empty($desc_json['qrCodeUrl'])) $qr_code_url = $desc_json['qrCodeUrl'];
                            if (!empty($desc_json['phone'])) $card_phone = $desc_json['phone'];
                            if (!empty($desc_json['name']) && empty($file_name)) $file_name = $desc_json['name'];
                        }
                    }
                }
                
                $msg_text = $data['message']['text'] ?? '';
                if ($msg_text !== 'This is test message' && !empty($msg_text) && empty($file_name)) {
                    $file_name = $msg_text;
                }
                if (empty($file_name)) $file_name = 'Danh thiếp Zalo';
                
                $card_meta = [
                    'name' => $file_name,
                    'phone' => $card_phone,
                    'avatar' => $card_avatar,
                    'qr_code' => $qr_code_url
                ];
                
                $file_name = "Danh thiếp: {$file_name}" . ($card_phone ? " - SĐT: {$card_phone}" : "");
                $file_url = json_encode($card_meta, JSON_UNESCAPED_UNICODE);
            }
            
            // Default file name based on type
            if (empty($file_name)) {
                if ($file_type === 'image') $file_name = 'Hình ảnh';
                elseif ($file_type === 'audio') $file_name = 'Tin nhắn thoại';
                elseif ($file_type === 'video') $file_name = 'Video';
                elseif ($file_type === 'namecard') $file_name = 'Danh thiếp Zalo';
                else $file_name = 'Tệp đính kèm';
            }
            
            $stmt_file = $pdo->prepare("
                INSERT INTO zalo_file_messages (oa_id, message_id, sender_id, file_name, file_url, file_size, file_type)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    file_name = VALUES(file_name),
                    file_url = VALUES(file_url),
                    file_size = VALUES(file_size),
                    file_type = VALUES(file_type)
            ");
            $stmt_file->execute([$oa_id, $msg_id, $sender_id, $file_name, $file_url, $file_size, $file_type]);
        }
    } catch (Exception $e) {
        error_log("Failed to save Zalo file metadata: " . $e->getMessage());
    }
}

require_once __DIR__ . '/includes/bot_prompt_helper.php';
save_chat_history_log($pdo, 'zalo', $oa_id, $sender_id, 'user', $cust_name, $snippet);

// 4. Update or Insert Customer Profile
// Khởi tạo/Cập nhật thông tin tương tác cuối trong zalo_customers
try {
    $st_last = $pdo->prepare("
        INSERT INTO zalo_customers (oa_id, sender_id, name, last_sender, last_message_at, customer_last_message_at, info_request_count, followup_requested_at)
        VALUES (?, ?, 'Khách hàng Zalo', 'customer', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, NULL)
        ON DUPLICATE KEY UPDATE
            last_sender = 'customer',
            followup_requested_at = IF(consulted IN (1, 3) AND last_message_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR), NULL, followup_requested_at),
            consulted = IF(consulted IN (1, 3) AND last_message_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR), 2, consulted),
            last_message_at = CURRENT_TIMESTAMP,
            customer_last_message_at = CURRENT_TIMESTAMP
    ");
    $st_last->execute([$oa_id, $sender_id]);
} catch (Exception $e) {
    error_log("Failed to insert/update zalo_customers interaction: " . $e->getMessage());
}

$access_token = zalo_get_active_token($oa_id, $pdo);
$cust_name = 'Khách hàng Zalo';
$cust_avatar = 'https://ui-avatars.com/api/?name=Zalo';
$cust_phone = '';
$cust_province = '';
$cust_notes = '';
$cust_sales_phone = '';
$cust_sales_notes = '';
$cust_consulted = 0;

try {
    $stmt_cust = $pdo->prepare("SELECT name, avatar, phone, province, notes, consulted, sales_phone, sales_notes FROM zalo_customers WHERE oa_id = ? AND sender_id = ?");
    $stmt_cust->execute([$oa_id, $sender_id]);
    $cust = $stmt_cust->fetch(PDO::FETCH_ASSOC);

    if ($cust) {
        $cust_name = $cust['name'];
        $cust_avatar = $cust['avatar'];
        $cust_phone = $cust['phone'];
        $cust_province = $cust['province'];
        $cust_notes = $cust['notes'];
        $cust_consulted = (int)($cust['consulted'] ?? 0);
        $cust_sales_phone = $cust['sales_phone'] ?? '';
        $cust_sales_notes = $cust['sales_notes'] ?? '';
    }

    if ($access_token && (!$cust || empty($cust['name']) || $cust['name'] === 'Khách hàng Zalo' || empty($cust['avatar']) || strpos($cust['avatar'], 'ui-avatars.com') !== false)) {
        // Fetch from Zalo API
        $profile = zalo_get_customer_profile($access_token, $sender_id);
        $fetched_ok = false;
        if ($profile) {
            $n = $profile['display_name'] ?? ($profile['displayName'] ?? ($profile['sharedInfo']['name'] ?? ''));
            if (!empty($n) && $n !== 'Khách hàng Zalo') {
                $cust_name = $n;
                $cust_avatar = $profile['avatar'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($cust_name);
                if (empty($cust_province) && !empty($profile['sharedInfo']['city'])) {
                    $cust_province = detect_vietnam_province($profile['sharedInfo']['city']);
                }
                $fetched_ok = true;
            }
        }

        if (!$fetched_ok) {
            // Fallback: Fetch conversation history to extract display name and avatar
            $data_param = json_encode([
                'user_id' => $sender_id,
                'offset' => 0,
                'count' => 10
            ]);
            $history_url = ZALO_API_BASE . 'v2.0/oa/conversation?data=' . urlencode($data_param);
            $history_headers = ["access_token: {$access_token}"];
            $history_res = zalo_api_request($history_url, 'GET', $history_headers);
            
            if ($history_res['status_code'] === 200 && isset($history_res['data']['error']) && $history_res['data']['error'] === 0) {
                $history_msgs = $history_res['data']['data'] ?? [];
                $extracted_name = '';
                $extracted_avatar = '';
                
                foreach ($history_msgs as $msg) {
                    if (isset($msg['src'])) {
                        if ($msg['src'] == 1) { // From user
                            $n = $msg['from_display_name'] ?? '';
                            $a = $msg['from_avatar'] ?? '';
                        } else { // From OA
                            $n = $msg['to_display_name'] ?? '';
                            $a = $msg['to_avatar'] ?? '';
                        }
                        
                        if (!empty($n) && $n !== 'Khách hàng Zalo' && $n !== 'Khách hàng' && empty($extracted_name)) {
                            $extracted_name = $n;
                        }
                        if (!empty($a) && strpos($a, 'ui-avatars.com') === false && empty($extracted_avatar)) {
                            $extracted_avatar = $a;
                        }
                    }
                    if (!empty($extracted_name) && !empty($extracted_avatar)) {
                        break;
                    }
                }
                
                if (!empty($extracted_name)) {
                    $cust_name = $extracted_name;
                    $cust_avatar = $extracted_avatar ?: 'https://ui-avatars.com/api/?name=' . urlencode($cust_name);
                    $fetched_ok = true;
                }
            }
        }

        if ($fetched_ok) {
            $stmt_ins = $pdo->prepare("
                INSERT INTO zalo_customers (oa_id, sender_id, name, avatar, phone, province, notes, last_sender, last_message_at, customer_last_message_at, info_request_count, followup_requested_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'customer', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, NULL)
                ON DUPLICATE KEY UPDATE 
                    name = VALUES(name),
                    avatar = VALUES(avatar),
                    province = COALESCE(NULLIF(VALUES(province), ''), province),
                    last_sender = 'customer',
                    followup_requested_at = IF(consulted IN (1, 3) AND last_message_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR), NULL, followup_requested_at),
                    consulted = IF(consulted IN (1, 3) AND last_message_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR), 2, consulted),
                    last_message_at = CURRENT_TIMESTAMP,
                    customer_last_message_at = CURRENT_TIMESTAMP
            ");
            $stmt_ins->execute([$oa_id, $sender_id, $cust_name, $cust_avatar, $cust_phone, $cust_province, $cust_notes]);
            
            // Also update zalo_messages sender_name just in case it was stored as default before
            $stmt_upd_msg = $pdo->prepare("
                UPDATE zalo_messages 
                SET sender_name = ? 
                WHERE oa_id = ? AND sender_id = ?
            ");
            $stmt_upd_msg->execute([$cust_name, $oa_id, $sender_id]);
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

    if ($is_locked || $cust_consulted === 3) {
        http_response_code(200);
        echo "LOCKED: Chatbot disabled due to active lock or consulted status = 3";
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
            if ($delay_s <= 0) {
                $delay_s = 3; // Mặc định gom tin nhắn trong 3 giây để tránh AI trả lời lặp tin
            }
            
            // Handle message delays / merging
            if ($delay_s > 0) {
                // 1. Dọn dẹp lock cũ đã hết hạn
                $stmt_clean_lock = $pdo->prepare("DELETE FROM zalo_chat_locks WHERE oa_id = ? AND sender_id = ? AND expire_at <= NOW()");
                $stmt_clean_lock->execute([$oa_id, $sender_id]);

                // 2. Thử chèn lock mới atomic (INSERT IGNORE)
                $stmt_ins_lock = $pdo->prepare("INSERT IGNORE INTO zalo_chat_locks (oa_id, sender_id, expire_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
                $stmt_ins_lock->execute([$oa_id, $sender_id, $delay_s]);
                
                if ($stmt_ins_lock->rowCount() == 0) {
                    // Đã có tiến trình khác giữ lock (tin nhắn mới gởi liên tục trong khoảng delay)
                    http_response_code(200);
                    echo "WAITING: Merging message in background thread";
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    exit;
                }

                // Chèn thành công -> Tiến trình này chịu trách nhiệm
                http_response_code(200);
                echo "EVENT_RECEIVED";
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                
                // Đợi gom tin nhắn
                sleep($delay_s);
                
                // Xóa lock khi hoàn tất
                $stmt_del_lock = $pdo->prepare("DELETE FROM zalo_chat_locks WHERE oa_id = ? AND sender_id = ?");
                $stmt_del_lock->execute([$oa_id, $sender_id]);
            }

            require_once __DIR__ . '/includes/bot_prompt_helper.php';
            $history_count = (int)($ai_rule['history_count'] ?? 10);
            $history_text = get_local_chat_history_text($pdo, 'zalo', $oa_id, $sender_id, $history_count, $access_token);
            $user_merged_text = $snippet;
            require_once __DIR__ . '/includes/bot_prompt_helper.php';
            $custom_system_prompt = build_cop_pha_viet_system_prompt($ai_rule['message'] ?? '', $cust_name, $cust_phone, $cust_province, $cust_notes, $cust_sales_phone, $cust_sales_notes, $history_text);
            
            require_once __DIR__ . '/includes/ai_rewriter.php';
            
            $oa_name = '';
            $stmt_oa_name = $pdo->prepare("SELECT name FROM zalo_oas WHERE oa_id = ?");
            $stmt_oa_name->execute([$oa_id]);
            if ($oa_row = $stmt_oa_name->fetch()) {
                $oa_name = $oa_row['name'];
            }

            // Run AI
            $ai_reply_text = generate_chat_reply_with_ai($user_merged_text, $custom_system_prompt, $acc_id, $oa_name, $history_text);
            
            if (!empty($ai_reply_text)) {
                $ai_parsed = parse_ai_json_reply($ai_reply_text);
                $reply_to_send = $ai_parsed['reply'];
                $parsed_extracted = $ai_parsed['extracted'];
                
                if (!empty($reply_to_send)) {
                    $reply_to_send = filter_ai_reply_no_duplicate_asks($reply_to_send, $cust_phone, $cust_province, $cust_notes);

                    // Update customer DB fields if extracted
                    $ai_upd_fields = [];
                    $ai_upd_params = [];
                    
                    if (!empty($parsed_extracted['phone'])) {
                        $new_phone = trim($parsed_extracted['phone']);
                        if ($new_phone !== $cust_phone) {
                            $ai_upd_fields[] = "phone = ?";
                            $ai_upd_params[] = $new_phone;
                            $cust_phone = $new_phone;
                        }
                    }
                    if (!empty($parsed_extracted['province'])) {
                        $new_province = trim($parsed_extracted['province']);
                        if ($new_province !== $cust_province) {
                            $ai_upd_fields[] = "province = ?";
                            $ai_upd_params[] = $new_province;
                            $cust_province = $new_province;
                        }
                    }
                    $extracted_req = $parsed_extracted['requirements'] ?? null;
                    if (!empty($extracted_req) && trim($extracted_req) !== 'null') {
                        $ext_clean = trim($extracted_req);
                        if (empty($cust_notes)) {
                            $final_notes = $ext_clean;
                        } else if (mb_strpos(mb_strtolower($cust_notes, 'UTF-8'), mb_strtolower($ext_clean, 'UTF-8')) === false) {
                            $final_notes = $cust_notes . " - " . $ext_clean;
                        } else {
                            $final_notes = $cust_notes;
                        }
                    } else {
                        $final_notes = detect_product_and_quantity($user_merged_text, $cust_notes);
                    }
                    if (!empty($final_notes) && $final_notes !== $cust_notes) {
                        $ai_upd_fields[] = "notes = ?";
                        $ai_upd_params[] = $final_notes;
                        $cust_notes = $final_notes;
                    }
                    if (isset($parsed_extracted['stop_consulting']) && $parsed_extracted['stop_consulting'] === true) {
                        $ai_upd_fields[] = "consulted = 3";
                    } else {
                        $ai_upd_fields[] = "consulted = IF(name IS NOT NULL AND TRIM(name) != '' AND name != 'Khách hàng Zalo' AND phone IS NOT NULL AND TRIM(phone) != '' AND province IS NOT NULL AND TRIM(province) != '' AND notes IS NOT NULL AND TRIM(notes) != '', IF(consulted = 0, 4, consulted), IF(consulted = 4, 0, consulted))";
                    }
                    
                    if (!empty($ai_upd_fields)) {
                        $ai_upd_params[] = $oa_id;
                        $ai_upd_params[] = $sender_id;
                        $st_ai_upd = $pdo->prepare("UPDATE zalo_customers SET " . implode(", ", $ai_upd_fields) . " WHERE oa_id = ? AND sender_id = ?");
                        $st_ai_upd->execute($ai_upd_params);
                    }
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
                $php_upd_cols[] = "consulted = IF(name IS NOT NULL AND TRIM(name) != '' AND name != 'Khách hàng Zalo' AND phone IS NOT NULL AND TRIM(phone) != '' AND province IS NOT NULL AND TRIM(province) != '' AND notes IS NOT NULL AND TRIM(notes) != '', IF(consulted = 0, 4, consulted), IF(consulted = 4, 0, consulted))";
                if (!empty($php_upd_cols)) {
                    $php_upd_vals[] = $oa_id;
                    $php_upd_vals[] = $sender_id;
                    $st_php_upd = $pdo->prepare("UPDATE zalo_customers SET " . implode(", ", $php_upd_cols) . " WHERE oa_id = ? AND sender_id = ?");
                    $st_php_upd->execute($php_upd_vals);
                }
                
                // Tự động đẩy dữ liệu sang API nếu cấu hình bật
                try {
                    require_once __DIR__ . '/includes/customer_api_helper.php';
                    $st_z_cur = $pdo->prepare("SELECT name, phone, province, notes, sales_phone, sales_notes, consulted, 'Zalo' AS platform FROM zalo_customers WHERE oa_id = ? AND sender_id = ?");
                    $st_z_cur->execute([$oa_id, $sender_id]);
                    $cur_z = $st_z_cur->fetch(PDO::FETCH_ASSOC);
                    if ($cur_z) push_customer_lead_to_api($pdo, $acc_id, $cur_z);
                } catch (Exception $e) {}
                
                // Send reply to Zalo
                if (!empty($reply_to_send)) {
                    $send_res = zalo_send_text_message($access_token, $sender_id, $reply_to_send);
                    save_chat_history_log($pdo, 'zalo', $oa_id, $sender_id, 'bot', 'Bạn (Cửa hàng)', $reply_to_send);
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

                        // Cập nhật last_sender cho Zalo customer
                        try {
                            $st_upd_agent = $pdo->prepare("UPDATE zalo_customers SET last_sender = 'agent', last_message_at = CURRENT_TIMESTAMP WHERE oa_id = ? AND sender_id = ?");
                            $st_upd_agent->execute([$oa_id, $sender_id]);
                        } catch (Exception $e) {}
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
