<?php
// retry_ai_reply.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/ai_rewriter.php';

header('Content-Type: text/plain; charset=utf-8');

$sender_id = isset($_GET['sender_id']) ? trim($_GET['sender_id']) : '7190028664419646';
$page_id = isset($_GET['page_id']) ? trim($_GET['page_id']) : '2216507885096928';
$custom_text = isset($_GET['text']) ? trim($_GET['text']) : 'HCM';

echo "=== RETRYING AI REPLY FOR SENDER: $sender_id ON PAGE: $page_id ===\n\n";

// 1. Fetch customer details
$stmt_cust = $pdo->prepare("SELECT name, phone, province, notes, sales_phone, sales_notes FROM fb_customers WHERE page_id = ? AND sender_id = ?");
$stmt_cust->execute([$page_id, $sender_id]);
$cust_row = $stmt_cust->fetch(PDO::FETCH_ASSOC);

if (!$cust_row) {
    echo "ERROR: Customer not found in fb_customers database!\n";
    exit;
}

$sender_name = $cust_row['name'] ?? 'Khách hàng';
$cust_phone = $cust_row['phone'] ?? '';
$cust_province = $cust_row['province'] ?? '';
$cust_notes = $cust_row['notes'] ?? '';
$cust_sales_phone = $cust_row['sales_phone'] ?? '';
$cust_sales_notes = $cust_row['sales_notes'] ?? '';

echo "Customer Name: $sender_name\n";
echo "Current Phone: " . ($cust_phone ?: "None") . "\n";
echo "Current Province: " . ($cust_province ?: "None") . "\n";
echo "Current Requirements: " . ($cust_notes ?: "None") . "\n\n";

// 2. Fetch page token and account ID
$stmt_page = $pdo->prepare("SELECT p.name, p.access_token, u.account_id FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ?");
$stmt_page->execute([$page_id]);
$page_info = $stmt_page->fetch(PDO::FETCH_ASSOC);

if (!$page_info || empty($page_info['access_token'])) {
    echo "ERROR: Page or Access Token not found!\n";
    exit;
}

$page_name = $page_info['name'];
$page_token = decryptData($page_info['access_token']);
$acc_id = $page_info['account_id'];

// 3. Fetch active AI Reply rule
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
    if ($match_scope) {
        $ai_rule = $r;
        break;
    }
}

if (!$ai_rule) {
    echo "ERROR: No active AI Reply rule found for this page scope!\n";
    exit;
}

echo "AI Rule Found: ID={$ai_rule['id']}\n\n";

// 4. Fetch chat history (N messages)
$history_count = (int)($ai_rule['history_count'] ?? 6);
$history_text = '';
$conversation_id = '';

// Get conversation ID first
$stmt_conv = $pdo->prepare("SELECT conversation_id FROM fb_conversations WHERE page_id = ? AND sender_id = ?");
$stmt_conv->execute([$page_id, $sender_id]);
$conversation_id = $stmt_conv->fetchColumn();

if ($history_count > 0 && !empty($conversation_id)) {
    echo "Fetching recent chat history (Limit $history_count)... \n";
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
        echo "=== Chat History ===\n" . $history_text . "====================\n\n";
    } else {
        echo "Could not retrieve chat history from Facebook API. Status Code: {$msg_res['status_code']}\n\n";
    }
}

// 5. Build system prompt
$info_context = "\n\n--- THÔNG TIN KHÁCH HÀNG ĐÃ CÓ ---\n";
$info_context .= "- Tên khách hàng: " . ($sender_name ?: "CHƯA CÓ") . "\n";
$info_context .= "- Số điện thoại: " . ($cust_phone ?: "CHƯA CÓ") . "\n";
$info_context .= "- Tỉnh thành: " . ($cust_province ?: "CHƯA CÓ") . "\n";
$info_context .= "- Nhu cầu/Yêu cầu khách hàng: " . ($cust_notes ?: "CHƯA CÓ") . "\n";
$info_context .= "- Số điện thoại Sales phụ trách: " . ($cust_sales_phone ?: "CHƯA CÓ") . "\n";
$info_context .= "- Ghi chú của Sales: " . ($cust_sales_notes ?: "CHƯA CÓ") . "\n";
$info_context .= "-----------------------------------\n";
if (!empty($cust_sales_phone)) {
    $info_context .= "HƯỚNG DẪN THÊM VỀ BÀN GIAO SALES: Nếu có thông tin 'Số điện thoại Sales phụ trách' và khách hàng hỏi/thắc mắc về việc liên hệ, báo giá hoặc phản hồi chậm, bạn hãy khéo léo thông báo cho khách hàng biết rằng nhân viên Sales số điện thoại " . $cust_sales_phone . " đã/đang xử lý và liên hệ với khách hàng. Hướng dẫn khách hàng liên hệ trực tiếp hoặc add Zalo số đó để được xử lý nhanh nhất.\n";
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
$json_instruction .= '    "requirements": "Nhu cầu/yêu cầu đầy đủ nhất của khách hàng đã được cập nhật hoặc bổ sung thêm thông tin mới...",\n';
$json_instruction .= '    "stop_consulting": true hoặc false (trả về true nếu khách hàng nói hoặc ngụ ý địa chỉ quá xa không mua nữa, từ chối hoặc không có nhu cầu tiếp tục tư vấn, để hệ thống tự động dừng tư vấn khách hàng này, ngược lại trả về false)\n';
$json_instruction .= "  }\n";
$json_instruction .= "}\n";

$custom_system_prompt = $ai_rule['message'] . $info_context . $json_instruction;

echo "Calling OpenAI API... \n";
$ai_reply_text = generate_chat_reply_with_ai($custom_text, $custom_system_prompt, $acc_id, $page_name, $history_text);
echo "AI Raw Response:\n" . $ai_reply_text . "\n\n";

if (empty($ai_reply_text)) {
    echo "ERROR: AI generated empty response!\n";
    exit;
}

$parsed_json = json_decode(clean_json_response($ai_reply_text), true);
$reply_to_send = '';

if (is_array($parsed_json) && isset($parsed_json['reply'])) {
    $reply_to_send = $parsed_json['reply'];
    echo "Parsed Reply message: \"$reply_to_send\"\n";
    
    // Extracted fields
    $ext_phone = $parsed_json['extracted']['phone'] ?? null;
    $ext_prov = $parsed_json['extracted']['province'] ?? null;
    $ext_notes = $parsed_json['extracted']['requirements'] ?? null;
    $ext_stop = $parsed_json['extracted']['stop_consulting'] ?? null;
    echo "Extracted Phone: " . ($ext_phone ?: "None") . "\n";
    echo "Extracted Province: " . ($ext_prov ?: "None") . "\n";
    echo "Extracted Requirements: " . ($ext_notes ?: "None") . "\n";
    echo "Extracted Stop Consulting: " . ($ext_stop ? "True" : "False") . "\n\n";
    
    // Perform database updates if any information was extracted
    $ai_upd_fields = [];
    $ai_upd_params = [];
    if (!empty($ext_phone) && $ext_phone !== $cust_phone) {
        $ai_upd_fields[] = "phone = ?";
        $ai_upd_params[] = $ext_phone;
    }
    if (!empty($ext_prov) && $ext_prov !== $cust_province) {
        $ai_upd_fields[] = "province = ?";
        $ai_upd_params[] = $ext_prov;
    }
    if (!empty($ext_notes) && $ext_notes !== $cust_notes) {
        $ai_upd_fields[] = "notes = ?";
        $ai_upd_params[] = $ext_notes;
    }
    if ($ext_stop === true) {
        $ai_upd_fields[] = "consulted = 3";
    }
    if (!empty($ai_upd_fields)) {
        $ai_upd_params[] = $page_id;
        $ai_upd_params[] = $sender_id;
        $st_upd = $pdo->prepare("UPDATE fb_customers SET " . implode(", ", $ai_upd_fields) . " WHERE page_id = ? AND sender_id = ?");
        $st_upd->execute($ai_upd_params);
        echo "Database updated with new extracted info.\n";
    }
} else {
    $reply_to_send = $ai_reply_text;
    echo "AI did not return valid JSON. Sending fallback raw text.\n";
}

if (empty($reply_to_send)) {
    echo "ERROR: Reply message text is empty!\n";
    exit;
}

// 6. Send the message using our new retrying function
echo "Sending message to Facebook Graph API...\n";
$url = "https://graph.facebook.com/v25.0/me/messages?access_token={$page_token}";
$post_data = json_encode([
    'recipient' => ['id' => $sender_id],
    'message' => ['text' => $reply_to_send],
    'messaging_type' => 'RESPONSE'
]);

$max_send_retries = 3;
$success = false;
$output = '';
$http_code = 0;
$err_msg = '';

for ($attempt = 1; $attempt <= $max_send_retries; $attempt++) {
    echo "Send Attempt $attempt... ";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $output = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($output === false) {
        $err_msg = curl_error($ch);
        curl_close($ch);
        echo "FAILED (cURL error: $err_msg)\n";
        sleep(1);
    } else {
        curl_close($ch);
        $res_data = json_decode($output, true);
        if ($http_code === 200 && !isset($res_data['error'])) {
            $success = true;
            echo "SUCCESS!\nResponse: $output\n";
            break;
        } else {
            $err_msg = $output;
            echo "FAILED (FB API error: Code=$http_code Response=$output)\n";
            if ($http_code >= 400 && $http_code < 500) {
                echo "Client error, breaking retry loop.\n";
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
    
    echo "\n=== RESULT: SUCCESS! Message successfully sent to customer and database updated. ===\n";
} else {
    echo "\n=== RESULT: FAILED! Could not send message to Facebook Graph API after $max_send_retries attempts. ===\n";
}

exit;
?>
