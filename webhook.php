<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

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

                            // Hàm xử lý gửi tin nhắn
                            $send_bot_msg = function($msg_text) use ($sender_id, $sender_name, $page_token) {
                                $lines = array_filter(explode("\n", str_replace("\r", "", $msg_text)), 'trim');
                                if (empty($lines)) return;
                                $chosen_msg = $lines[array_rand($lines)];
                                $final_msg = str_replace('{name}', $sender_name, $chosen_msg);

                                $url = "https://graph.facebook.com/v22.0/me/messages?access_token={$page_token}";
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
                                curl_close($ch);
                                webhook_log("BOT_CHAT_REPLY: " . $output);
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
                                    if ($match_page) {
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
                                        break; // Chỉ trả lời 1 rule đầu tiên khớp
                                    }
                                }
                            }
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
                                        $url = "https://graph.facebook.com/v22.0/me/messages?access_token={$page_token}";
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
} else {
    // Không phải Object Page
    http_response_code(404);
}
?>
