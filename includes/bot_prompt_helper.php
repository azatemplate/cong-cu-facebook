<?php
// includes/bot_prompt_helper.php

/**
 * Default fallback system prompt when a SaaS account user hasn't entered a custom prompt.
 */
function get_default_saas_master_prompt() {
    return <<<'PROMPT'
Bạn là trợ lý tư vấn CSKH AI chuyên nghiệp và thân thiện.

# XƯNG HÔ & PHONG CÁCH
- Trả lời ngắn gọn, lịch sự, niềm nở, đi thẳng vào câu hỏi.
- Phản hồi theo đúng ngôn ngữ của khách hàng (Ví dụ: Tiếng Anh trả lời Tiếng Anh, Tiếng Việt trả lời Tiếng Việt).
- Không lặp lại thông tin khách đã cung cấp, không hỏi dồn dập nhiều câu cùng lúc.

# MỤC TIÊU TƯ VẤN & THU THẬP THÔNG TIN
- Giải đáp thắc mắc của khách hàng về sản phẩm/dịch vụ.
- Khéo léo thu thập các thông tin cần thiết (Nhu cầu/Sản phẩm quan tâm, Quy cách số lượng, Tỉnh/thành, Số điện thoại) để chuyển bộ phận tư vấn/Sales hỗ trợ báo giá chi tiết.
- Khi khách hàng đã cung cấp đủ thông tin, xác nhận lại ngắn gọn và thông báo bộ phận tư vấn sẽ liên hệ lại.
PROMPT;
}

/**
 * Builds dynamic system prompt combining tenant's custom prompt, customer state context & JSON response instructions
 */
function build_cop_pha_viet_system_prompt($rule_message, $cust_name, $cust_phone, $cust_province, $cust_notes, $cust_sales_phone = '', $cust_sales_notes = '', $history_text = '') {
    // 1. Priority: Use tenant's custom prompt entered in UI / saved in database
    $user_prompt = trim($rule_message ?? '');
    $base_prompt = !empty($user_prompt) ? $user_prompt : get_default_saas_master_prompt();

    $has_phone = !empty($cust_phone);
    $has_province = !empty($cust_province);
    $has_notes = !empty($cust_notes);

    // 2. Dynamic customer state context
    $info_context = "\n\n--- THÔNG TIN KHÁCH HÀNG ĐÃ CÓ TRONG HỒ SƠ ---\n";
    $info_context .= "- Tên/Xưng hô khách hàng: " . ($cust_name ?: "CHƯA CÓ") . "\n";
    $info_context .= "- Nhu cầu / Sản phẩm quan tâm: " . ($has_notes ? "{$cust_notes}" : "CHƯA CÓ") . "\n";
    $info_context .= "- Tỉnh / Thành phố: " . ($has_province ? "{$cust_province}" : "CHƯA CÓ") . "\n";
    $info_context .= "- Số điện thoại: " . ($has_phone ? "{$cust_phone}" : "CHƯA CÓ") . "\n";
    if (!empty($cust_sales_phone)) {
        $info_context .= "- SĐT Nhân viên phụ trách: {$cust_sales_phone}\n";
    }
    if (!empty($cust_sales_notes)) {
        $info_context .= "- Ghi chú của Nhân viên: {$cust_sales_notes}\n";
    }
    $info_context .= "---------------------------------------------\n";

    // Strict state non-repetition guidelines
    $info_context .= "HƯỚNG DẪN XỬ LÝ DỮ LIỆU ĐÃ CÓ (BẮT BUỘC TUÂN THỦ 100%):\n";
    if ($has_phone) {
        $info_context .= "⚠️ KHÁCH HÀNG ĐÃ CÓ SỐ ĐIỆN THOẠI LÀ \"{$cust_phone}\". TUYỆT ĐỐI KHÔNG ĐƯỢC HỎI XIN LẠI SỐ ĐIỆN THOẠI/ZALO DƯỚI BẤT KỲ HÌNH THỨC NÀO!\n";
    }
    if ($has_province) {
        $info_context .= "⚠️ KHÁCH HÀNG ĐÃ CÓ TỈNH THÀNH LÀ \"{$cust_province}\". TUYỆT ĐỐI KHÔNG HỎI LẠI TỈNH THÀNH HOẶC ĐỊA CHỈ NỮA!\n";
    }
    if ($has_notes) {
        $info_context .= "⚠️ KHÁCH HÀNG ĐÃ CÓ SẢN PHẨM/NHU CẦU LÀ \"{$cust_notes}\". TUYỆT ĐỐI KHÔNG HỎI LẠI TÊN SẢN PHẨM ĐÃ CÓ!\n";
    }
    $info_context .= "- Với các mục ghi 'CHƯA CÓ', hãy khéo léo hỏi xin từng thông tin còn thiếu. Không hỏi dồn dập nhiều thông tin cùng lúc.\n";

    // Natural conversation & Phone refusal rules
    $info_context .= "\nQUY ĐỊNH BẮT BUỘC VỀ GIAO TIẾP VÀ TƯ VẤN:\n";
    $info_context .= "1. TUYỆT ĐỐI KHÔNG DÙNG CÂU 'Em là AI hỗ trợ...': Không bao giờ nhắc từ 'AI' hay 'trợ lý tự động' ở các câu trả lời. Chỉ xưng 'Em', gọi 'Anh/ Chị'.\n";
    $info_context .= "2. TUYỆT ĐỐI KHÔNG NHẮC LẠI THÔNG TIN VỪA NHẬN: Không nói 'Anh/ Chị đã cho em biết số lượng là...'. Khách vừa nhắn xong nên nhắc lại là thừa thãi.\n";
    $info_context .= "3. TRẢ LỜI NGẮN GỌN & ĐI THẲNG VÀO VẤN ĐỀ: Không dài dòng giải thích. Nếu thiếu SĐT hay Tỉnh thành, chỉ hỏi 1 câu ngắn gọn lịch sự.\n";
    $info_context .= "4. TUYỆT ĐỐI KHÔNG CHÀO LẠI: Không dùng 'Dạ chào...', 'Chào...' ở giữa cuộc trò chuyện.\n";
    $info_context .= "5. KHI NÀO MỚI GỬI LINK ZALO: CHỈ gửi link Zalo https://copphaviet.vn/zalo/ KHI KHÁCH HÀNG RÕ RÀNG TỪ CHỐI CUNG CẤP SỐ ĐIỆN THOẠI (ví dụ khách nói 'không cho số', 'không cho sđt'). Viết link dạng chữ thuần https://copphaviet.vn/zalo/ (KHÔNG dùng `<>`, KHÔNG dùng markdown `[link](url)`).\n";

    // 3. JSON extraction instructions for system sync
    $json_instruction = "\n\nQUY ĐỊNH PHẢN HỒI (BẮT BUỘC): Bạn BẮT BUỘC phải phản hồi dưới định dạng JSON duy nhất (không bọc trong thẻ markdown ```json, không thêm chữ giải thích bên ngoài):\n";
    $json_instruction .= "{\n";
    $json_instruction .= '  "reply": "Nội dung tin nhắn bạn trả lời khách hàng (tự nhiên, KHÔNG lặp lại tin nhắn cũ, KHÔNG hỏi thông tin đã có, KHÔNG chào lại giữa cuộc, xuống dòng bằng \\n, xưng Anh/ Chị)",' . "\n";
    $json_instruction .= '  "extracted": {' . "\n";
    $json_instruction .= '    "name": "Tên/xưng hô phát hiện mới trong tin nhắn khách hàng (nếu có, nếu không có trả về null)",' . "\n";
    $json_instruction .= '    "phone": "Số điện thoại phát hiện mới trong tin nhắn (nếu có, không lấy số cũ, nếu không có trả về null)",' . "\n";
    $json_instruction .= '    "province": "Tỉnh/Thành phố phát hiện từ tin nhắn (NHẬN DIỆN THÔNG MINH VIẾT TẮT VÀ CHÍNH TẢ: \'hn\', \'hnoi\', \'hà lội\' -> \'Hà Nội\'; \'hcm\', \'tphcm\', \'sg\', \'sài gòn\' -> \'Hồ Chí Minh\'; \'bd\' -> \'Bình Dương\'; \'dn\' -> \'Đồng Nai\'; \'đn\' -> \'Đà Nẵng\'; \'vt\', \'brvt\' -> \'Bà Rịa - Vũng Tàu\'; \'hp\' -> \'Hải Phòng\'; \'ct\' -> \'Cần Thơ\'; \'tn\' -> \'Thái Nguyên\'; \'la\' -> \'Long An\'; \'na\' -> \'Nghệ An\'; \'th\' -> \'Thanh Hóa\'; \'tb\' -> \'Thái Bình\'; \'bn\' -> \'Bắc Ninh\'; \'bg\' -> \'Bắc Giang\'; \'hd\' -> \'Hải Dương\'; \'hy\' -> \'Hưng Yên\'; \'vp\' -> \'Vĩnh Phúc\'; \'pt\' -> \'Phú Thọ\'; \'hb\' -> \'Hòa Bình\'; \'đl\', \'dalat\' -> \'Lâm Đồng\'; \'nt\' -> \'Khánh Hòa\'. Trả về null nếu không có)",' . "\n";
    $json_instruction .= '    "requirements": "TRÍCH XUẤT ĐẦY ĐỦ VÀ CHÍNH XÁC TOÀN BỘ TÊN SẢN PHẨM, QUY CÁCH, KÍCH THƯỚC VÀ SỐ LƯỢNG (Ví dụ khách nói \'Mình muốn mua 100 bát và 50m ti dạng ren thô phi 16\' -> trích xuất nguyên văn hoặc đầy đủ: \'100 bát và 50m ti dạng ren thô phi 16\'. TUYỆT ĐỐI KHÔNG trích xuất dở dang như \'50m\' hay \'100 bát\'. Nếu không có sản phẩm/nhu cầu mới thì trả về null)",' . "\n";
    $json_instruction .= '    "stop_consulting": true hoặc false (TRẢ VỀ TRUE nếu khách hàng rõ ràng từ chối tiếp tục tư vấn hoặc từ chối cho SĐT để ngắt Bot AI nhường cho Sales human)' . "\n";
    $json_instruction .= "  }\n";
    $json_instruction .= "}\n";

    return $base_prompt . $info_context . $json_instruction;
}

/**
 * Filter out duplicate questions (asking for phone/province/product when already present in DB)
 */
function filter_ai_reply_no_duplicate_asks($reply, $cust_phone = '', $cust_province = '', $cust_notes = '') {
    if (empty($reply)) return $reply;
    
    $has_phone = !empty($cust_phone);
    $has_province = !empty($cust_province);
    $has_notes = !empty($cust_notes);

    if (!$has_phone && !$has_province && !$has_notes) {
        return $reply;
    }

    // Split reply into lines/sentences
    $lines = explode("\n", $reply);
    $filtered_lines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) {
            $filtered_lines[] = $line;
            continue;
        }

        // If customer already has phone number, remove lines asking for phone number
        if ($has_phone) {
            if (preg_match('/(cho|gửi|lấy|xin)\s+(em|mình|bên em)?\s*(xin|lấy)?\s*(số|sđt|phone|zalo|số điện thoại)/iu', $trimmed) ||
                preg_match('/(cho|xin)\s+số\s+(điện thoại|đt|sđt)/iu', $trimmed) ||
                preg_match('/anh\/?chị\s+(vui lòng\s+)?(cho|gửi)\s+em\s+xin\s+s/iu', $trimmed)) {
                continue; // Skip this line asking for phone number
            }
        }

        // If customer already has province, remove lines asking for province
        if ($has_province) {
            if (preg_match('/(cho|gửi|xin)\s+(em|mình|bên em)?\s*(xin)?\s*(tỉnh|thành phố|tỉnh thành|địa chỉ)/iu', $trimmed) ||
                preg_match('/anh\/?chị\s+(ở|ở đâu|tại)\s+(tỉnh|thành|khu vực)/iu', $trimmed)) {
                continue; // Skip line asking for province
            }
        }

        $filtered_lines[] = $line;
    }

    $result = trim(implode("\n", $filtered_lines));
    return !empty($result) ? $result : $reply;
}

/**
 * Comprehensive Vietnam Province detector supporting all 63 provinces, abbreviations & common typos
 */
if (!function_exists('detect_vietnam_province')) {
    function detect_vietnam_province($text) {
        if (empty($text)) return null;
        $text_clean = mb_strtolower(trim($text), 'UTF-8');
        // Replace punctuation with spaces for word boundary check
        $text_words = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text_clean);
        $text_words = preg_replace('/\s+/', ' ', $text_words);

        // Explicit Abbreviation & Typo Mapping Table
        $abbrev_map = [
            // Hà Nội
            '/\b(hn|hnoi|h lội|hà lội|hà nôi|h nội|h noi)\b/u' => 'Hà Nội',
            // Hồ Chí Minh
            '/\b(hcm|tphcm|tp hcm|tp\.hcm|sg|sai gon|sài gòn|hcmc|hồ chí minh|ho chi minh)\b/u' => 'Hồ Chí Minh',
            // Đà Nẵng
            '/\b(đn|dnag|da nang|đà nẵng|đà năng)\b/u' => 'Đà Nẵng',
            // Bình Dương
            '/\b(bd|b duong|b d|binh duong|bình dươn|bình zương)\b/u' => 'Bình Dương',
            // Đồng Nai
            '/\b(dnai|d nai|dong nai|đồng nai)\b/u' => 'Đồng Nai',
            // Bà Rịa - Vũng Tàu
            '/\b(vt|brvt|br vt|vung tau|vũng tàu|vũng tào|bà rịa vũng tàu|bà rịa - vũng tàu)\b/u' => 'Bà Rịa - Vũng Tàu',
            // Hải Phòng
            '/\b(hp|hai phong|hải phòng|hải pòng)\b/u' => 'Hải Phòng',
            // Cần Thơ
            '/\b(ct|can tho|cần thơ)\b/u' => 'Cần Thơ',
            // Thái Nguyên
            '/\b(tnguyen|t nguyen|thai nguyen|thái nguyên|tái nguyên)\b/u' => 'Thái Nguyên',
            // Long An
            '/\b(la|long an)\b/u' => 'Long An',
            // Nghệ An
            '/\b(na|nghe an|nghệ an)\b/u' => 'Nghệ An',
            // Thanh Hóa
            '/\b(th|thanh hoa|thanh hóa|thanh hoá)\b/u' => 'Thanh Hóa',
            // Thái Bình
            '/\b(tb|thai binh|thái bình)\b/u' => 'Thái Bình',
            // Bắc Ninh
            '/\b(bn|bac ninh|bắc ninh)\b/u' => 'Bắc Ninh',
            // Bắc Giang
            '/\b(bg|bac giang|bắc giang)\b/u' => 'Bắc Giang',
            // Hải Dương
            '/\b(hd|hai duong|hải dương)\b/u' => 'Hải Dương',
            // Hưng Yên
            '/\b(hy|hung yen|hưng yên)\b/u' => 'Hưng Yên',
            // Vĩnh Phúc
            '/\b(vp|vinh phuc|vĩnh phúc)\b/u' => 'Vĩnh Phúc',
            // Phú Thọ
            '/\b(pt|phu tho|phú thọ)\b/u' => 'Phú Thọ',
            // Hòa Bình
            '/\b(hb|hoa binh|hòa bình)\b/u' => 'Hòa Bình',
            // Lâm Đồng / Đà Lạt
            '/\b(đl|dl|dalat|đà lạt|lam dong|lâm đồng)\b/u' => 'Lâm Đồng',
            // Khánh Hòa / Nha Trang
            '/\b(nt|nha trang|khanh hoa|khánh hòa|khánh hoá)\b/u' => 'Khánh Hòa',
            // Nam Định
            '/\b(nđ|nd|nam dinh|nam định)\b/u' => 'Nam Định',
            // Ninh Bình
            '/\b(ninh binh|ninh bình)\b/u' => 'Ninh Bình',
            // An Giang
            '/\b(an giang)\b/u' => 'An Giang',
            // Bắc Kạn
            '/\b(bac kan|bắc kạn)\b/u' => 'Bắc Kạn',
            // Bạc Liêu
            '/\b(bac lieu|bạc liêu)\b/u' => 'Bạc Liêu',
            // Bến Tre
            '/\b(ben tre|bến tre)\b/u' => 'Bến Tre',
            // Bình Định
            '/\b(binh dinh|bình định|quy nhơn)\b/u' => 'Bình Định',
            // Bình Phước
            '/\b(binh phuoc|bình phước)\b/u' => 'Bình Phước',
            // Bình Thuận
            '/\b(binh thuan|bình thuận|phan thiết)\b/u' => 'Bình Thuận',
            // Cà Mau
            '/\b(ca mau|cà mau)\b/u' => 'Cà Mau',
            // Cao Bằng
            '/\b(cao bang|cao bằng)\b/u' => 'Cao Bằng',
            // Đắk Lắk
            '/\b(dak lak|daklak|đắk lắk|đắc lắc|bmt|buôn ma thuột)\b/u' => 'Đắk Lắk',
            // Đắk Nông
            '/\b(dak nong|đắk nông)\b/u' => 'Đắk Nông',
            // Điện Biên
            '/\b(dien bien|điện biên)\b/u' => 'Điện Biên',
            // Đồng Tháp
            '/\b(dong thap|đồng tháp)\b/u' => 'Đồng Tháp',
            // Gia Lai
            '/\b(gia lai|pleiku)\b/u' => 'Gia Lai',
            // Hà Giang
            '/\b(ha giang|hà giang)\b/u' => 'Hà Giang',
            // Hà Nam
            '/\b(ha nam|hà nam)\b/u' => 'Hà Nam',
            // Hà Tĩnh
            '/\b(ha tinh|hà tĩnh)\b/u' => 'Hà Tĩnh',
            // Hậu Giang
            '/\b(hau giang|hậu giang)\b/u' => 'Hậu Giang',
            // Kiên Giang
            '/\b(kien giang|kiên giang|phú quốc)\b/u' => 'Kiên Giang',
            // Kon Tum
            '/\b(kon tum)\b/u' => 'Kon Tum',
            // Lai Châu
            '/\b(lai chau|lai châu)\b/u' => 'Lai Châu',
            // Lạng Sơn
            '/\b(lang son|lạng sơn)\b/u' => 'Lạng Sơn',
            // Lào Cai
            '/\b(lao cai|lào cai|sapa)\b/u' => 'Lào Cai',
            // Ninh Thuận
            '/\b(ninh thuan|ninh thuận)\b/u' => 'Ninh Thuận',
            // Phú Yên
            '/\b(phu yen|phú yên)\b/u' => 'Phú Yên',
            // Quảng Bình
            '/\b(quang binh|quảng bình)\b/u' => 'Quảng Bình',
            // Quảng Nam
            '/\b(quang nam|quảng nam|hội an)\b/u' => 'Quảng Nam',
            // Quảng Ngãi
            '/\b(quang ngai|quảng ngãi)\b/u' => 'Quảng Ngãi',
            // Quảng Ninh
            '/\b(quang ninh|quảng ninh|hạ long)\b/u' => 'Quảng Ninh',
            // Quảng Trị
            '/\b(quang tri|quảng trị)\b/u' => 'Quảng Trị',
            // Sóc Trăng
            '/\b(soc trang|sóc trăng)\b/u' => 'Sóc Trăng',
            // Sơn La
            '/\b(son la|sơn la|mộc châu)\b/u' => 'Sơn La',
            // Tây Ninh
            '/\b(tay ninh|tây ninh)\b/u' => 'Tây Ninh',
            // Thừa Thiên Huế
            '/\b(thua thien hue|thừa thiên huế|huế|hue)\b/u' => 'Thừa Thiên Huế',
            // Tiền Giang
            '/\b(tien giang|tiền giang|mỹ tho)\b/u' => 'Tiền Giang',
            // Trà Vinh
            '/\b(tra vinh|trà vinh)\b/u' => 'Trà Vinh',
            // Tuyên Quang
            '/\b(tuyen quang|tuyên quang)\b/u' => 'Tuyên Quang',
            // Vĩnh Long
            '/\b(vinh long|vĩnh long)\b/u' => 'Vĩnh Long',
            // Yên Bái
            '/\b(yen bai|yên bái)\b/u' => 'Yên Bái'
        ];

        foreach ($abbrev_map as $pattern => $prov_name) {
            if (preg_match($pattern, $text_words)) {
                return $prov_name;
            }
        }

        return null;
    }
}

/**
 * PHP Heuristic Fallback to extract construction products and quantities from raw user text
 */
function detect_product_and_quantity($text, $current_notes = '') {
    if (empty($text)) return $current_notes;
    $text_clean = mb_strtolower(trim($text), 'UTF-8');
    
    // Common construction product names
    $products = [
        'giàn giáo', 'gian giao', 'giáo nêm', 'giao nem', 'giáo khung', 'giao khung', 'giáo ringlock', 'ringlock',
        'cùm giáo', 'cum giao', 'cùm xoay', 'cum xoay', 'cùm tĩnh', 'cum tinh', 'cùm',
        'ty ren', 'tyren', 'tán chuồn', 'tan chuon', 'bát chuồn', 'bat chuon', 'bát', 'ren thô', 'ren',
        'kích tăng', 'kich tang', 'chân kích', 'chan kich', 'kích bằng', 'kich bang', 'kích đầu', 'kich dau',
        'xà gồ', 'xa go', 'cây chống', 'cay chong', 'coppha', 'cốp pha', 'ván khuôn', 'van khuon',
        'ván phủ phim', 'van phu phim', 'thép hộp', 'thep hop'
    ];
    
    $found_prod = null;
    foreach ($products as $p) {
        if (mb_strpos($text_clean, $p) !== false) {
            if ($p === 'gian giao' || $p === 'giàn giáo') $found_prod = 'Giàn giáo';
            elseif ($p === 'giao nem' || $p === 'giáo nêm') $found_prod = 'Giáo nêm';
            elseif ($p === 'giao khung' || $p === 'giáo khung') $found_prod = 'Giáo khung';
            elseif ($p === 'ringlock' || $p === 'giáo ringlock') $found_prod = 'Giáo Ringlock';
            elseif (strpos($p, 'cùm') !== false || strpos($p, 'cum') !== false) $found_prod = 'Cùm giáo';
            elseif (strpos($p, 'ty') !== false || strpos($p, 'tán') !== false || strpos($p, 'bat') !== false || strpos($p, 'bát') !== false || strpos($p, 'ren') !== false) $found_prod = 'Ty ren - Tán chuồn';
            elseif (strpos($p, 'kích') !== false || strpos($p, 'kich') !== false) $found_prod = 'Kích tăng';
            elseif (strpos($p, 'xà gồ') !== false || strpos($p, 'xa go') !== false) $found_prod = 'Xà gồ';
            elseif (strpos($p, 'cây chống') !== false || strpos($p, 'cay chong') !== false) $found_prod = 'Cây chống';
            elseif (strpos($p, 'coppha') !== false || strpos($p, 'cốp pha') !== false || strpos($p, 'ván') !== false) $found_prod = 'Cốp pha';
            else $found_prod = mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
            break;
        }
    }
    
    // Quantity regex e.g. "2 bộ", "10 bộ", "50 cái", "100 cây", "50m"
    $found_qty = null;
    if (preg_match('/(\d+)\s*(bộ|bo|cái|cai|cây|cay|tấm|tam|mét|met|m|kg|tấn|tan)/iu', $text_clean, $m)) {
        // Only consider isolated quantity if a product was also found or if unit is explicit
        if (!empty($found_prod) || in_array(mb_strtolower($m[2]), ['bộ', 'bo', 'cái', 'cai', 'cây', 'cay', 'tấm', 'tam', 'tấn', 'tan'])) {
            $found_qty = $m[1] . ' ' . $m[2];
        }
    }
    
    if (empty($found_prod) && empty($found_qty)) {
        return $current_notes;
    }
    
    $new_entry = '';
    if ($found_prod && $found_qty) {
        $new_entry = "$found_prod - $found_qty";
    } elseif ($found_prod) {
        $new_entry = $found_prod;
    } else {
        $new_entry = $found_qty;
    }
    
    if (!empty($current_notes)) {
        if (mb_strpos(mb_strtolower($current_notes, 'UTF-8'), mb_strtolower($new_entry, 'UTF-8')) !== false) {
            return $current_notes;
        }
        return $current_notes . " - " . $new_entry;
    }
    
    return $new_entry;
}

/**
 * Save chat message to local database history log (Instant accumulation)
 */
function save_chat_history_log($pdo, $platform, $page_id, $sender_id, $sender_type, $sender_name, $message) {
    if (empty($page_id) || empty($sender_id) || empty($message)) return;
    $message = trim($message);
    if ($message === '' || $message === 'Đã gửi một tệp đính kèm') return;

    try {
        // Prevent storing duplicate consecutive identical message from same sender within short window
        $chk = $pdo->prepare("
            SELECT message FROM chat_history_logs 
            WHERE platform = ? AND page_id = ? AND sender_id = ? AND sender_type = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $chk->execute([$platform, $page_id, $sender_id, $sender_type]);
        $last_msg = $chk->fetchColumn();
        if ($last_msg === $message) return;

        $stmt = $pdo->prepare("
            INSERT INTO chat_history_logs (platform, page_id, sender_id, sender_type, sender_name, message, created_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$platform, $page_id, $sender_id, $sender_type, $sender_name, $message]);
    } catch (Exception $e) {}
}

/**
 * Retrieve accumulated chat history text from local DB (Instant, 0 external API calls).
 * Performs a 1-time initial seed from FB / Zalo Graph API if local table is empty for this customer.
 */
function get_local_chat_history_text($pdo, $platform, $page_id, $sender_id, $limit = 10, $token = '', $conv_id_or_user_id = '') {
    $limit = max(1, min(100, intval($limit)));
    $history_text = '';

    try {
        $stmt = $pdo->prepare("
            SELECT sender_type, sender_name, message 
            FROM chat_history_logs 
            WHERE platform = ? AND page_id = ? AND sender_id = ? 
            ORDER BY id DESC LIMIT {$limit}
        ");
        $stmt->execute([$platform, $page_id, $sender_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $rows = array_reverse($rows);
            foreach ($rows as $r) {
                $role = ($r['sender_type'] === 'user') ? "Khách hàng" : "Bạn (Cửa hàng)";
                $history_text .= "$role: " . $r['message'] . "\n";
            }
            return $history_text;
        }

        // --- FALLBACK: Initial 1-time seed from FB / Zalo API if local table is empty ---
        if ($platform === 'facebook' && !empty($conv_id_or_user_id) && !empty($token)) {
            require_once __DIR__ . '/fb_api.php';
            $msg_res = fb_api_request($conv_id_or_user_id . '/messages', [
                'fields' => 'message,from',
                'limit' => $limit,
                'access_token' => $token
            ], 'GET');

            if ($msg_res['status_code'] === 200 && !empty($msg_res['data']['data'])) {
                $msgs = array_reverse($msg_res['data']['data']);
                foreach ($msgs as $m) {
                    if (empty($m['message'])) continue;
                    $is_page = ($m['from']['id'] === $page_id);
                    $stype = $is_page ? 'bot' : 'user';
                    $sname = $is_page ? 'Bạn (Cửa hàng)' : 'Khách hàng';
                    save_chat_history_log($pdo, 'facebook', $page_id, $sender_id, $stype, $sname, $m['message']);
                    $role = $is_page ? "Bạn (Cửa hàng)" : "Khách hàng";
                    $history_text .= "$role: " . $m['message'] . "\n";
                }
            }
        } elseif ($platform === 'zalo' && !empty($token)) {
            require_once __DIR__ . '/zalo_api.php';
            $data_param = json_encode(['user_id' => $sender_id, 'offset' => 0, 'count' => $limit]);
            $url = ZALO_API_BASE . 'v2.0/oa/conversation?data=' . urlencode($data_param);
            $res = zalo_api_request($url, 'GET', ["access_token: {$token}"]);

            if ($res['status_code'] === 200 && isset($res['data']['error']) && $res['data']['error'] === 0) {
                $msgs = $res['data']['data'] ?? [];
                $sliced = array_slice($msgs, 0, $limit);
                $sliced = array_reverse($sliced);
                foreach ($sliced as $m) {
                    if (empty($m['message'])) continue;
                    $is_oa = ($m['src'] == 0);
                    $stype = $is_oa ? 'bot' : 'user';
                    $sname = $is_oa ? 'Bạn (Cửa hàng)' : 'Khách hàng';
                    save_chat_history_log($pdo, 'zalo', $page_id, $sender_id, $stype, $sname, $m['message']);
                    $role = $is_oa ? "Bạn (Cửa hàng)" : "Khách hàng";
                    $history_text .= "$role: " . $m['message'] . "\n";
                }
            }
        }
    } catch (Exception $e) {}

    return $history_text;
}

