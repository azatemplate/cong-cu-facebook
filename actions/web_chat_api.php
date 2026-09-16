<?php
// actions/web_chat_api.php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai_rewriter.php';

// Enable CORS for embed script on external websites
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Auto run setup table
require_once __DIR__ . '/../setup_website_chat.php';
if (function_exists('ensure_web_chat_columns')) {
    ensure_web_chat_columns($pdo);
}

// Tự động đồng bộ account_id giữa các bảng theo tài khoản Admin đang đăng nhập
if (isset($_SESSION['account_id']) && intval($_SESSION['account_id']) > 0) {
    $admin_acc_id = intval($_SESSION['account_id']);
    try {
        $pdo->prepare("UPDATE web_visitors SET account_id = ? WHERE account_id = 1 OR account_id = 0")->execute([$admin_acc_id]);
        $pdo->prepare("UPDATE web_messages SET account_id = ? WHERE account_id = 1 OR account_id = 0")->execute([$admin_acc_id]);
        $pdo->prepare("UPDATE web_chat_configs SET account_id = ? WHERE account_id = 1 OR account_id = 0")->execute([$admin_acc_id]);
    } catch (Exception $e) {}
}

$action = $_GET['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? '';

// Support JSON payload in POST
if (empty($action)) {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $action = $json['action'] ?? '';
            $_POST = array_merge($_POST, $json);
        }
    }
}

// ── HELPER: LẤY CẤU HÌNH WIDGET DÙNG CHUNG ──────────────────────────────────
function get_widget_config($pdo, $account_id) {
    if (isset($_SESSION['account_id']) && intval($_SESSION['account_id']) > 0) {
        $account_id = intval($_SESSION['account_id']);
    }

    $stmt = $pdo->prepare("SELECT * FROM web_chat_configs WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        $stmt_any = $pdo->query("SELECT * FROM web_chat_configs LIMIT 1");
        $config = $stmt_any->fetch(PDO::FETCH_ASSOC);
    }

    if (!$config) {
        $default_config = [
            'account_id' => $account_id,
            'bot_name' => 'Gấu cười',
            'bot_avatar' => 'https://s240-ava-talk.zadn.vn/c/c/6/3/3/240/cd520d4d49a844b5abe6410e9e3dd9aa.jpg',
            'bot_subtitle' => 'Trợ lý AI MONA — đang online',
            'policy_notice' => 'Cuộc trò chuyện được lưu để cải thiện dịch vụ.',
            'greeting_msg' => 'Dạ em là Gấu cười, luôn có cách, cho anh chị!',
            'brand_footer' => 'AI chăm sóc khách hàng bởi MONA',
            'zalo_link' => '',
            'messenger_link' => '',
            'primary_color' => '#0068ff',
            'ai_enabled' => 1,
            'system_prompt' => "Bạn là trợ lý tư vấn CSKH AI chuyên nghiệp và thân thiện tên {bot_name}. Hãy trả lời ngắn gọn, lịch sự, tư vấn sản phẩm/dịch vụ cho khách hàng. Khéo léo xin số điện thoại/Zalo để nhân viên tư vấn gọi hỗ trợ ngay."
        ];
        try {
            $ins = $pdo->prepare("
                INSERT INTO web_chat_configs 
                (account_id, bot_name, bot_avatar, bot_subtitle, policy_notice, greeting_msg, brand_footer, zalo_link, messenger_link, primary_color, ai_enabled, system_prompt)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $account_id,
                $default_config['bot_name'],
                $default_config['bot_avatar'],
                $default_config['bot_subtitle'],
                $default_config['policy_notice'],
                $default_config['greeting_msg'],
                $default_config['brand_footer'],
                $default_config['zalo_link'],
                $default_config['messenger_link'],
                $default_config['primary_color'],
                $default_config['ai_enabled'],
                $default_config['system_prompt']
            ]);
        } catch (Exception $e) {}
        return $default_config;
    }
    return $config;
}

// ── HELPER: ĐẢM BẢO TỒN TẠI KHÁCH VẮNG LAI TRONG DB ─────────────────────────
function ensure_visitor_exists($pdo, $account_id, $visitor_uuid) {
    if (isset($_SESSION['account_id']) && intval($_SESSION['account_id']) > 0) {
        $account_id = intval($_SESSION['account_id']);
    }

    if (empty($visitor_uuid)) {
        $visitor_uuid = 'web_' . uniqid() . '_' . bin2hex(random_bytes(4));
    }

    $stmt_v = $pdo->prepare("SELECT * FROM web_visitors WHERE visitor_uuid = ?");
    $stmt_v->execute([$visitor_uuid]);
    $visitor = $stmt_v->fetch(PDO::FETCH_ASSOC);

    if (!$visitor) {
        $stmt_max = $pdo->query("SELECT MAX(visitor_num) FROM web_visitors");
        $max_num = intval($stmt_max->fetchColumn() ?: 0);
        $next_num = $max_num + 1;
        $visitor_name = "Khách vãng lai #" . $next_num;

        try {
            $ins_v = $pdo->prepare("
                INSERT INTO web_visitors (account_id, visitor_uuid, visitor_num, name)
                VALUES (?, ?, ?, ?)
            ");
            $ins_v->execute([$account_id, $visitor_uuid, $next_num, $visitor_name]);
        } catch (Exception $e) {}

        $stmt_v->execute([$visitor_uuid]);
        $visitor = $stmt_v->fetch(PDO::FETCH_ASSOC);
    }
    return $visitor;
}

// ── HELPER: TRÍCH XUẤT TỰ ĐỘNG SĐT / THÔNG TIN TỪ NỘI DUNG CHAT ──────────────
function extract_lead_info_and_update($pdo, $account_id, $visitor_uuid, $text) {
    if (empty($text)) return;
    
    // Tìm SĐT dạng 10 chữ số đầu 03, 05, 07, 08, 09
    $phone = null;
    if (preg_match('/(03|05|07|08|09)\d{8}/', $text, $matches)) {
        $phone = $matches[0];
    }
    
    // Tìm Tên từ các cú pháp "Tên tôi là...", "Mình tên...", "Tôi là...", "Em tên...", "Anh là..."
    $name = null;
    if (preg_match('/(?:tên|mình tên|tôi tên|tôi là|em tên|anh là|chị là)\s+([^\n\.,!]{2,25})/ui', $text, $nMatch)) {
        $candidate_name = trim($nMatch[1]);
        if (!empty($candidate_name) && strlen($candidate_name) < 30) {
            $name = $candidate_name;
        }
    }
    
    if ($phone || $name) {
        try {
            $updates = [];
            $params = [];
            if ($phone) {
                $updates[] = "phone = ?";
                $params[] = $phone;
            }
            if ($name && !empty($name)) {
                $updates[] = "name = ?";
                $params[] = $name;
            }
            $params[] = $visitor_uuid;
            
            $sql = "UPDATE web_visitors SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE visitor_uuid = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (Exception $e) {}
    }
}


// ── ROUTER CÁC HÀM API ──────────────────────────────────────────────────────

try {
    // 1. INIT VISITOR (Dành cho Widget bên ngoài)
    if ($action === 'init_visitor') {
        $account_id = intval($_GET['account_id'] ?? $_POST['account_id'] ?? ($_SESSION['account_id'] ?? 1));
        $visitor_uuid = trim($_GET['visitor_uuid'] ?? $_POST['visitor_uuid'] ?? '');
        
        if (empty($visitor_uuid)) {
            $visitor_uuid = 'web_' . uniqid() . '_' . bin2hex(random_bytes(4));
        }

        $config = get_widget_config($pdo, $account_id);
        $visitor = ensure_visitor_exists($pdo, $account_id, $visitor_uuid);
        
        // Lấy tin nhắn hiện tại
        $stmt_m = $pdo->prepare("SELECT sender_type, sender_name, message, attachments, created_at FROM web_messages WHERE visitor_uuid = ? ORDER BY id ASC");
        $stmt_m->execute([$visitor_uuid]);
        $messages = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
        
        // Nếu là khách mới và chưa có tin nhắn -> tự động gửi tin nhắn chào mừng
        if (empty($messages) && !empty($config['greeting_msg'])) {
            $bot_name = $config['bot_name'] ?: 'Gấu cười';
            $ins_m = $pdo->prepare("
                INSERT INTO web_messages (account_id, visitor_uuid, sender_type, sender_name, message)
                VALUES (?, ?, 'bot', ?, ?)
            ");
            $ins_m->execute([$account_id, $visitor_uuid, $bot_name, $config['greeting_msg']]);
            
            $stmt_m->execute([$visitor_uuid]);
            $messages = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'status' => 'success',
            'account_id' => $account_id,
            'visitor_uuid' => $visitor_uuid,
            'visitor_name' => $visitor['name'] ?? 'Khách vãng lai',
            'config' => [
                'bot_name' => $config['bot_name'],
                'bot_avatar' => $config['bot_avatar'],
                'bot_subtitle' => $config['bot_subtitle'],
                'policy_notice' => $config['policy_notice'],
                'brand_footer' => $config['brand_footer'],
                'zalo_link' => $config['zalo_link'],
                'messenger_link' => $config['messenger_link'],
                'primary_color' => $config['primary_color'],
                'widget_position' => $config['widget_position'] ?? 'right',
                'bottom_offset' => intval($config['bottom_offset'] ?? 24),
                'side_offset' => intval($config['side_offset'] ?? 24)
            ],
            'messages' => $messages
        ]);
        exit;
    }

    // 2. VISITOR GỬI TIN NHẮN TỪ WIDGET
    if ($action === 'send_visitor_msg') {
        $account_id = intval($_POST['account_id'] ?? $_GET['account_id'] ?? ($_SESSION['account_id'] ?? 1));
        $visitor_uuid = trim($_POST['visitor_uuid'] ?? $_GET['visitor_uuid'] ?? '');
        $user_msg = trim($_POST['message'] ?? $_GET['message'] ?? '');
        
        if (empty($visitor_uuid)) {
            $visitor_uuid = 'web_' . uniqid() . '_' . bin2hex(random_bytes(4));
        }

        if (empty($user_msg)) {
            echo json_encode(['status' => 'error', 'msg' => 'Nội dung tin nhắn không được để trống']);
            exit;
        }

        $config = get_widget_config($pdo, $account_id);
        $visitor = ensure_visitor_exists($pdo, $account_id, $visitor_uuid);

        // Lưu tin nhắn khách hàng
        $ins_user = $pdo->prepare("
            INSERT INTO web_messages (account_id, visitor_uuid, sender_type, sender_name, message)
            VALUES (?, ?, 'user', 'Khách hàng', ?)
        ");
        $ins_user->execute([$account_id, $visitor_uuid, $user_msg]);
        
        // Cập nhật trạng thái web_visitors
        $upd_v = $pdo->prepare("
            UPDATE web_visitors 
            SET last_sender = 'customer', unread_count = unread_count + 1, last_message_at = NOW(), updated_at = NOW()
            WHERE visitor_uuid = ?
        ");
        $upd_v->execute([$visitor_uuid]);
        
        // Tự động kiểm tra và trích xuất Lead (SĐT, Tên) từ tin nhắn khách
        extract_lead_info_and_update($pdo, $account_id, $visitor_uuid, $user_msg);

        // AI Chatbot tự động trả lời (nếu bật AI)
        $bot_reply = null;
        if (intval($config['ai_enabled']) === 1) {
            $bot_name = $config['bot_name'] ?: 'Gấu cười';

            // Lấy 8 tin nhắn gần nhất làm lịch sử ngữ cảnh cho AI
            $stmt_hist = $pdo->prepare("
                SELECT sender_type, message 
                FROM web_messages 
                WHERE visitor_uuid = ? 
                ORDER BY id DESC LIMIT 8
            ");
            $stmt_hist->execute([$visitor_uuid]);
            $raw_hist = array_reverse($stmt_hist->fetchAll(PDO::FETCH_ASSOC));
            
            $history_lines = [];
            foreach ($raw_hist as $h) {
                $role = ($h['sender_type'] === 'user') ? 'Khách hàng' : 'Trợ lý CSKH';
                $history_lines[] = "{$role}: {$h['message']}";
            }
            $history_text = implode("\n", $history_lines);

            // Fetch updated visitor profile info
            $stmt_v_info = $pdo->prepare("SELECT name, phone, province, notes, consulted FROM web_visitors WHERE visitor_uuid = ?");
            $stmt_v_info->execute([$visitor_uuid]);
            $v_info = $stmt_v_info->fetch(PDO::FETCH_ASSOC);

            $cust_name = $v_info['name'] ?? '';
            $cust_phone = $v_info['phone'] ?? '';
            $cust_province = $v_info['province'] ?? '';
            $cust_notes = $v_info['notes'] ?? '';

            // Nếu tên vẫn là tên mặc định "Khách vãng lai #..." -> Coi như CHƯA CÓ tên để AI khéo léo hỏi xin tên
            $is_default_name = empty($cust_name) || (mb_strpos($cust_name, 'Khách vãng lai') !== false);
            $display_name_status = $is_default_name ? "CHƯA CÓ" : $cust_name;

            // Build AI prompt context (Identical to Zalo OA & Facebook Live Chat)
            $info_context = "\n\n--- THÔNG TIN KHÁCH HÀNG ĐÃ CÓ ---\n";
            $info_context .= "- Tên/Xưng hô khách hàng: " . $display_name_status . "\n";
            $info_context .= "- Số điện thoại: " . ($cust_phone ?: "CHƯA CÓ") . "\n";
            $info_context .= "- Tỉnh thành: " . ($cust_province ?: "CHƯA CÓ") . "\n";
            $info_context .= "- Nhu cầu/Yêu cầu khách hàng: " . ($cust_notes ?: "CHƯA CÓ") . "\n";
            $info_context .= "-----------------------------------\n";
            $info_context .= "HƯỚNG DẪN BẮT BUỘC: Bạn là chatbot chăm sóc khách hàng chuyên nghiệp. Hãy kiểm tra các thông tin ở trên:\n";
            $info_context .= "1. Với thông tin nào đã có (không phải là 'CHƯA CÓ'), bạn tuyệt đối không được hỏi lại khách hàng nữa.\n";
            $info_context .= "2. Với thông tin nào ghi 'CHƯA CÓ', hãy khéo léo, tự nhiên và thân thiện hỏi khách hàng để xin nốt. Quy tắc xin thông tin: Chỉ hỏi xin TỪNG thông tin một trong mỗi tin nhắn, TUYỆT ĐỐI không hỏi dồn dập nhiều thông tin cùng lúc. Bạn hãy ưu tiên hỏi xin Tên/Xưng hô (ví dụ: 'Dạ em có thể xưng hô với anh/chị như thế nào ạ?') và Nhu cầu/Sản phẩm trước để biết khách muốn mua gì và tiện giao tiếp, sau đó mới hỏi đến Tỉnh thành (khi hỏi tỉnh thành bạn phải chủ động giới thiệu địa chỉ cửa hàng của mình trước để khách biết vị trí của shop), và cuối cùng mới xin Số điện thoại để Sales liên hệ báo giá cụ thể. Trả lời NGẮN GỌN, đi thẳng vào câu hỏi. TUYỆT ĐỐI không cảm ơn đi cảm ơn lại nhiều lần.\n";
            $info_context .= "3. Khi đã thu thập đủ cả 4 thông tin (Tên khách hàng, Số điện thoại, Tỉnh thành, Nhu cầu), hãy tóm tắt lại và gửi lời cảm ơn khách hàng.\n";
            $info_context .= "4. ĐỊNH DẠNG TIN NHẮN: Hãy xuống dòng hợp lý để tin nhắn dễ đọc. Mỗi ý chính nên ở một dòng riêng.\n";
            $info_context .= "5. KHÔNG LIỆT KÊ/TÓM TẮT GIỮA CUỘC: Trong suốt quá trình xin thông tin (khi chưa đủ thông tin), bạn TUYỆT ĐỐI KHÔNG ĐƯỢC nhắc lại, liệt kê hay tóm tắt các thông tin đã thu thập được. Chỉ tóm tắt đầy đủ duy nhất một lần ở cuối cuộc trò chuyện khi đã thu thập đủ thông tin.\n";

            if (!$is_default_name) {
                $info_context .= "⚠️ LƯU Ý ĐẶC BIỆT QUAN TRỌNG: Khách hàng này ĐÃ CÓ tên xưng hô là \"{$cust_name}\". Bạn TUYỆT ĐỐI KHÔNG ĐƯỢC HỎI XIN LẠI tên nữa và hãy chủ động xưng hô thân mật với khách bằng tên \"{$cust_name}\".\n";
            }
            if (!empty($cust_phone)) {
                $info_context .= "⚠️ LƯU Ý ĐẶC BIỆT QUAN TRỌNG: Khách hàng này ĐÃ CÓ số điện thoại là \"{$cust_phone}\". Bạn TUYỆT ĐỐI KHÔNG ĐƯỢC HỎI XIN lại số điện thoại trong mọi trường hợp.\n";
            }
            if (!empty($cust_province)) {
                $info_context .= "⚠️ LƯU Ý ĐẶC BIỆT QUAN TRỌNG: Khách hàng này ĐÃ CÓ tỉnh thành là \"{$cust_province}\". Bạn TUYỆT ĐỐI KHÔNG ĐƯỢC HỎI LẠI tỉnh thành nữa.\n";
            }

            $json_instruction = "\n\nQUY ĐỊNH PHẢN HỒI: BẮT BUỘC phải phản hồi dưới định dạng JSON duy nhất:\n";
            $json_instruction .= "{\n";
            $json_instruction .= '  "reply": "Nội dung tin nhắn trả lời khách hàng (tiếng Việt tự nhiên)",' . "\n";
            $json_instruction .= '  "extracted": {' . "\n";
            $json_instruction .= '    "name": "Tên/xưng hô phát hiện mới của khách hàng (ví dụ: Anh Hùng, Chị Mai) nếu khách cung cấp (hoặc null)",' . "\n";
            $json_instruction .= '    "phone": "Số điện thoại phát hiện mới trong tin nhắn (hoặc null)",' . "\n";
            $json_instruction .= '    "province": "Tỉnh thành phát hiện mới trong tin nhắn (hoặc null)",' . "\n";
            $json_instruction .= '    "requirements": "Nhu cầu/sản phẩm khách hàng muốn mua (hoặc null)",' . "\n";
            $json_instruction .= '    "stop_consulting": true hoặc false' . "\n";
            $json_instruction .= "  }\n";
            $json_instruction .= "}\n";

            $base_prompt = $config['system_prompt'] ?: "Bạn là trợ lý tư vấn CSKH AI chuyên nghiệp tên {bot_name}. Hãy trả lời thân thiện, lịch sự.";
            $sys_prompt = str_replace('{bot_name}', $bot_name, $base_prompt) . $info_context . $json_instruction;

            $ai_response_raw = generate_chat_reply_with_ai($user_msg, $sys_prompt, $account_id, 'Website Live Chat', $history_text);

            $reply_to_send = '';
            if (!empty($ai_response_raw)) {
                $ai_parsed = parse_ai_json_reply($ai_response_raw);
                $reply_to_send = $ai_parsed['reply'];
                $parsed_extracted = $ai_parsed['extracted'];

                if (!empty($reply_to_send)) {
                    $ai_upd_fields = [];
                    $ai_upd_params = [];
                    if (!empty($parsed_extracted['name'])) {
                        $extracted_name = trim($parsed_extracted['name']);
                        if ($extracted_name !== $cust_name && mb_strpos($extracted_name, 'Khách vãng lai') === false) {
                            $ai_upd_fields[] = "name = ?";
                            $ai_upd_params[] = $extracted_name;
                        }
                    }
                    if (!empty($parsed_extracted['phone'])) {
                        $ai_upd_fields[] = "phone = ?";
                        $ai_upd_params[] = trim($parsed_extracted['phone']);
                    }
                    if (!empty($parsed_extracted['province'])) {
                        $ai_upd_fields[] = "province = ?";
                        $ai_upd_params[] = trim($parsed_extracted['province']);
                    }
                    if (!empty($parsed_extracted['requirements'])) {
                        $ai_upd_fields[] = "notes = ?";
                        $ai_upd_params[] = trim($parsed_extracted['requirements']);
                    }
                    if (isset($parsed_extracted['stop_consulting']) && $parsed_extracted['stop_consulting'] === true) {
                        $ai_upd_fields[] = "consulted = 3";
                    }

                    if (!empty($ai_upd_fields)) {
                        $ai_upd_params[] = $visitor_uuid;
                        $st_upd = $pdo->prepare("UPDATE web_visitors SET " . implode(", ", $ai_upd_fields) . ", updated_at = NOW() WHERE visitor_uuid = ?");
                        $st_upd->execute($ai_upd_params);

                        // Tự động đẩy dữ liệu sang API nếu đủ điều kiện
                        try {
                            require_once __DIR__ . '/../includes/customer_api_helper.php';
                            $st_cur = $pdo->prepare("SELECT name, phone, province, notes, consulted, 'Website' AS platform FROM web_visitors WHERE visitor_uuid = ?");
                            $st_cur->execute([$visitor_uuid]);
                            $cur_v = $st_cur->fetch(PDO::FETCH_ASSOC);
                            if ($cur_v) push_customer_lead_to_api($pdo, $account_id, $cur_v);
                        } catch (Exception $e) {}
                    }
                }
            }

            if (empty($reply_to_send)) {
                $reply_to_send = "Dạ {$bot_name} đã nhận được tin nhắn từ anh/chị rồi ạ! Anh/chị cho em xin thông tin nhu cầu hoặc số điện thoại/Zalo để bên em hỗ trợ tư vấn báo giá ngay nhé!";
            }

            $ins_bot = $pdo->prepare("
                INSERT INTO web_messages (account_id, visitor_uuid, sender_type, sender_name, message)
                VALUES (?, ?, 'bot', ?, ?)
            ");
            $ins_bot->execute([$account_id, $visitor_uuid, $bot_name, $reply_to_send]);
            
            $bot_reply = [
                'sender_type' => 'bot',
                'sender_name' => $bot_name,
                'message' => $reply_to_send,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // Lấy lại danh sách tin nhắn mới nhất
        $stmt_m = $pdo->prepare("SELECT sender_type, sender_name, message, attachments, created_at FROM web_messages WHERE visitor_uuid = ? ORDER BY id ASC");
        $stmt_m->execute([$visitor_uuid]);
        $messages = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'bot_reply' => $bot_reply,
            'messages' => $messages
        ]);
        exit;
    }

    // 3. GET MESSAGES (Polling từ Widget hoặc Dashboard)
    if ($action === 'get_messages') {
        $visitor_uuid = trim($_GET['visitor_uuid'] ?? $_POST['visitor_uuid'] ?? '');
        $is_admin = isset($_SESSION['account_id']);

        if (empty($visitor_uuid)) {
            echo json_encode(['status' => 'error', 'msg' => 'Thiếu visitor_uuid']);
            exit;
        }

        // Nếu admin mở xem tin nhắn -> reset unread_count
        if ($is_admin) {
            $upd_read = $pdo->prepare("UPDATE web_visitors SET unread_count = 0 WHERE visitor_uuid = ?");
            $upd_read->execute([$visitor_uuid]);
        }

        $stmt_m = $pdo->prepare("SELECT sender_type, sender_name, message, attachments, created_at FROM web_messages WHERE visitor_uuid = ? ORDER BY id ASC");
        $stmt_m->execute([$visitor_uuid]);
        $messages = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'messages' => $messages]);
        exit;
    }

    // 4. LẤY DANH SÁCH KHÁCH VẮNG LAI (Cho website.php Dashboard)
    if ($action === 'get_visitors_list') {
        $stmt = $pdo->query("
            SELECT v.*, 
                   (SELECT message FROM web_messages WHERE visitor_uuid = v.visitor_uuid ORDER BY id DESC LIMIT 1) AS last_message
            FROM web_visitors v
            ORDER BY v.updated_at DESC
        ");
        $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'visitors' => $visitors]);
        exit;
    }

    // 5. AGENT/NHÂN VIÊN GỬI TIN NHẮN TỪ WEBSITE.PHP
    if ($action === 'send_agent_msg') {
        $account_id = $_SESSION['account_id'] ?? 1;
        $visitor_uuid = trim($_POST['visitor_uuid'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (empty($visitor_uuid) || empty($message)) {
            echo json_encode(['status' => 'error', 'msg' => 'Nội dung không được rỗng']);
            exit;
        }

        $ins_agent = $pdo->prepare("
            INSERT INTO web_messages (account_id, visitor_uuid, sender_type, sender_name, message)
            VALUES (?, ?, 'agent', 'Tư vấn viên', ?)
        ");
        $ins_agent->execute([$account_id, $visitor_uuid, $message]);

        $upd_v = $pdo->prepare("
            UPDATE web_visitors 
            SET last_sender = 'agent', unread_count = 0, last_message_at = NOW(), updated_at = NOW() 
            WHERE visitor_uuid = ?
        ");
        $upd_v->execute([$visitor_uuid]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    // 6. LƯU CẤU HÌNH WIDGET (Tab Cấu hình trong website.php)
    if ($action === 'save_widget_config') {
        $account_id = $_SESSION['account_id'] ?? 1;
        $bot_name = trim($_POST['bot_name'] ?? 'Gấu cười');
        $bot_avatar = trim($_POST['bot_avatar'] ?? '');
        $bot_subtitle = trim($_POST['bot_subtitle'] ?? 'Trợ lý AI MONA — đang online');
        $policy_notice = trim($_POST['policy_notice'] ?? 'Cuộc trò chuyện được lưu để cải thiện dịch vụ.');
        $greeting_msg = trim($_POST['greeting_msg'] ?? '');
        $brand_footer = trim($_POST['brand_footer'] ?? 'AI chăm sóc khách hàng bởi MONA');
        $zalo_link = trim($_POST['zalo_link'] ?? '');
        $messenger_link = trim($_POST['messenger_link'] ?? '');
        $primary_color = trim($_POST['primary_color'] ?? '#0068ff');
        $ai_enabled = intval($_POST['ai_enabled'] ?? 1);
        $system_prompt = trim($_POST['system_prompt'] ?? '');
        $widget_position = trim($_POST['widget_position'] ?? 'right');
        $bottom_offset = intval($_POST['bottom_offset'] ?? 24);
        $side_offset = intval($_POST['side_offset'] ?? 24);

        $sql_save = "
            INSERT INTO web_chat_configs 
            (account_id, bot_name, bot_avatar, bot_subtitle, policy_notice, greeting_msg, brand_footer, zalo_link, messenger_link, primary_color, ai_enabled, system_prompt, widget_position, bottom_offset, side_offset)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                bot_name = VALUES(bot_name),
                bot_avatar = VALUES(bot_avatar),
                bot_subtitle = VALUES(bot_subtitle),
                policy_notice = VALUES(policy_notice),
                greeting_msg = VALUES(greeting_msg),
                brand_footer = VALUES(brand_footer),
                zalo_link = VALUES(zalo_link),
                messenger_link = VALUES(messenger_link),
                primary_color = VALUES(primary_color),
                ai_enabled = VALUES(ai_enabled),
                system_prompt = VALUES(system_prompt),
                widget_position = VALUES(widget_position),
                bottom_offset = VALUES(bottom_offset),
                side_offset = VALUES(side_offset)
        ";
        try {
            $stmt_save = $pdo->prepare($sql_save);
            $stmt_save->execute([
                $account_id, $bot_name, $bot_avatar, $bot_subtitle, $policy_notice, 
                $greeting_msg, $brand_footer, $zalo_link, $messenger_link, 
                $primary_color, $ai_enabled, $system_prompt,
                $widget_position, $bottom_offset, $side_offset
            ]);
        } catch (PDOException $ex) {
            // Tự động chữa lành CSDL nếu thiếu cột
            if (function_exists('ensure_web_chat_columns')) {
                ensure_web_chat_columns($pdo);
            }
            $stmt_save = $pdo->prepare($sql_save);
            $stmt_save->execute([
                $account_id, $bot_name, $bot_avatar, $bot_subtitle, $policy_notice, 
                $greeting_msg, $brand_footer, $zalo_link, $messenger_link, 
                $primary_color, $ai_enabled, $system_prompt,
                $widget_position, $bottom_offset, $side_offset
            ]);
        }

        echo json_encode(['status' => 'success', 'msg' => 'Đã lưu cấu hình Widget & AI Bot thành công!']);
        exit;
    }

    // 7. CẬP NHẬT THÔNG TIN HỒ SƠ KHÁCH VẮNG LAI
    if ($action === 'save_visitor_info') {
        $visitor_uuid = trim($_POST['visitor_uuid'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $consulted = intval($_POST['consulted'] ?? 0);

        // If customer has ALL 4 FIELDS (name, phone, province, notes), promote status 0 -> 4 (Chờ xử lý). If info incomplete, reset 4 -> 0.
        $is_full_info = !empty($name) && !empty($phone) && !empty($province) && !empty($notes);
        if ($is_full_info && $consulted === 0) {
            $consulted = 4;
        } elseif (!$is_full_info && $consulted === 4) {
            $consulted = 0;
        }

        if (empty($visitor_uuid)) {
            echo json_encode(['status' => 'error', 'msg' => 'Thiếu visitor_uuid']);
            exit;
        }

        $upd_info = $pdo->prepare("
            UPDATE web_visitors 
            SET name = ?, phone = ?, province = ?, notes = ?, consulted = ?, updated_at = NOW()
            WHERE visitor_uuid = ?
        ");
        $upd_info->execute([$name, $phone, $province, $notes, $consulted, $visitor_uuid]);

        try {
            require_once __DIR__ . '/../includes/customer_api_helper.php';
            push_customer_lead_to_api($pdo, $_SESSION['account_id'] ?? 1, [
                'name' => $name,
                'phone' => $phone,
                'province' => $province,
                'notes' => $notes,
                'consulted' => $consulted,
                'platform' => 'Website'
            ]);
        } catch (Exception $e) {}

        echo json_encode(['status' => 'success', 'msg' => 'Đã cập nhật thông tin khách hàng thành công!']);
        exit;
    }

    // 8. UPLOAD TẬP TIN HOẶC HÌNH ẢNH ĐÍNH KÈM CHAT WIDGET
    if ($action === 'upload_file') {
        $visitor_uuid = trim($_POST['visitor_uuid'] ?? $_GET['visitor_uuid'] ?? '');
        $sender_type = trim($_POST['sender_type'] ?? 'user');
        $sender_name = trim($_POST['sender_name'] ?? 'Khách hàng');
        $account_id = intval($_POST['account_id'] ?? ($_SESSION['account_id'] ?? 1));

        if (empty($visitor_uuid) || empty($_FILES['file'])) {
            echo json_encode(['status' => 'error', 'msg' => 'Thiếu file gửi đi']);
            exit;
        }

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'msg' => 'Tải file thất bại']);
            exit;
        }

        $upload_dir = __DIR__ . '/../uploads/web_chat/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'txt'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['status' => 'error', 'msg' => 'Định dạng file không được hỗ trợ']);
            exit;
        }

        $new_filename = 'chat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target_path = $upload_dir . $new_filename;

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
            $base_path = rtrim(str_replace('/actions', '', dirname($_SERVER['PHP_SELF'])), '/');
            $file_url = $domain . $base_path . '/uploads/web_chat/' . $new_filename;

            $is_img = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            $attachments_json = json_encode([
                'url' => $file_url,
                'name' => $file['name'],
                'type' => $is_img ? 'image' : 'file'
            ], JSON_UNESCAPED_UNICODE);

            $msg_text = $is_img ? '[Hình ảnh đính kèm]' : '[Tập tin: ' . $file['name'] . ']';

            // Insert into web_messages
            $ins = $pdo->prepare("
                INSERT INTO web_messages (account_id, visitor_uuid, sender_type, sender_name, message, attachments)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$account_id, $visitor_uuid, $sender_type, $sender_name, $msg_text, $attachments_json]);

            // Update web_visitors
            $upd = $pdo->prepare("
                UPDATE web_visitors 
                SET last_sender = ?, unread_count = unread_count + 1, last_message_at = NOW(), updated_at = NOW()
                WHERE visitor_uuid = ?
            ");
            $upd->execute([$sender_type === 'user' ? 'customer' : 'agent', $visitor_uuid]);

            echo json_encode([
                'status' => 'success',
                'file_url' => $file_url,
                'file_name' => $file['name'],
                'type' => $is_img ? 'image' : 'file'
            ]);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Không thể lưu file đính kèm']);
            exit;
        }
    }

    echo json_encode(['status' => 'error', 'msg' => 'Invalid action']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
