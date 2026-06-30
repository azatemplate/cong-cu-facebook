<?php
// test_ai.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ai_rewriter.php';

header('Content-Type: text/plain; charset=utf-8');

$account_id = 2;
$cust_name = 'Thang Nguyen';
$cust_phone = '0937838673';
$cust_province = 'Bình Dương';
$cust_notes = 'Kích U - 3986 cái, Đế bằng - 3986 cái';
$cust_sales_phone = 'Ms Kiều: 0911213459';
$cust_sales_notes = '';

$user_merged_text = 'r6i2 em';
$history_text = "Bạn (Cửa hàng): Dạ chào Thang Nguyen anh đã nhận được báo giá từ Ms Kiều: 0911213459 Cốp Pha Việt chưa ạ, cần Cốp Pha Việt hỗ trợ gì thêm nhắn em nhé.\nKhách hàng: r6i2 em\n";

// Lấy AI rule
$st_ai = $pdo->prepare("SELECT * FROM bot_chat_rules WHERE account_id = ? AND is_active = 1 AND rule_type = 'ai_reply' LIMIT 1");
$st_ai->execute([$account_id]);
$ai_rule = $st_ai->fetch(PDO::FETCH_ASSOC);

if (!$ai_rule) {
    echo "No active AI rule found.\n";
    exit;
}

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

echo "=== SYSTEM PROMPT ===\n" . $custom_system_prompt . "\n\n";
echo "=== CALLING AI ===\n";

$ai_reply_text = generate_chat_reply_with_ai($user_merged_text, $custom_system_prompt, $account_id, 'Cốp Pha Việt', $history_text);

echo "Response:\n" . $ai_reply_text . "\n";
