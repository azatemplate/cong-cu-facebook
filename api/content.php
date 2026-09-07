<?php
/**
 * API Công Thức Content & Phong Cách Nội Dung (20 Công Thức & 9 Phong Cách)
 * 
 * Ví dụ gọi API:
 *   GET /api/content.php?prompt=aida           -> Trả về công thức AIDA
 *   GET /api/content.php?prompt=all            -> Trả về toàn bộ 20 công thức
 *   GET /api/content.php?phongcach=ban_hang    -> Trả về phong cách bán hàng
 *   GET /api/content.php?phongcach=all         -> Trả về toàn bộ 9 phong cách
 *   GET /api/content.php                       -> Trả về toàn bộ dữ liệu (20 công thức & 9 phong cách)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// 1. Danh sách 20 Công thức Content chuẩn Marketing
$formulas = [
    [
        "id" => 1,
        "code" => "aida",
        "name" => "AIDA (Attention - Interest - Desire - Action)",
        "sort_order" => 1,
        "description" => "Attention (Gây chú ý) -> Interest (Tạo thích thú) -> Desire (Kích thích khao khát) -> Action (Kêu gọi hành động). Công thức kinh điển giật hook thu hút, đưa giá trị và thúc đẩy chốt đơn.",
        "template" => "- Attention: Đặt câu hỏi hoặc giật tiêu đề gây tò mò, giật gân.\n- Interest: Nêu thông tin thú vị, số liệu hoặc nỗi đau khiến người đọc quan tâm.\n- Desire: Đưa ra lợi ích vượt trội và viễn cảnh sở hữu sản phẩm/dịch vụ.\n- Action: Kêu gọi hành động rõ ràng (Call to Action)."
    ],
    [
        "id" => 2,
        "code" => "pas",
        "name" => "PAS (Problem - Agitate - Solve)",
        "sort_order" => 2,
        "description" => "Problem (Nêu vấn đề) -> Agitate (Xoáy sâu nỗi đau) -> Solve (Giải pháp triệt để). Đánh trực diện vào rắc rối của khách hàng, nhấn mạnh hậu quả và đưa ra giải pháp.",
        "template" => "- Problem: Nêu rõ vấn đề/nỗi đau nhức nhối mà khách hàng đang gặp phải.\n- Agitate: Xoáy sâu vào tác hại, cảm giác khó chịu nếu không xử lý ngay.\n- Solve: Giới thiệu sản phẩm/dịch vụ như một giải pháp cứu cánh tối ưu."
    ],
    [
        "id" => 3,
        "code" => "bab",
        "name" => "BAB (Before - After - Bridge)",
        "sort_order" => 3,
        "description" => "Before (Cảnh thực tại) -> After (Tương lai tươi đẹp) -> Bridge (Cầu nối giải pháp). So sánh sự lột xác trước và sau khi dùng sản phẩm.",
        "template" => "- Before: Mô tả thực trạng khó khăn, bế tắc hiện tại.\n- After: Vẽ ra bức tranh kết quả hoàn hảo, hạnh phúc sau khi giải quyết.\n- Bridge: Giới thiệu sản phẩm/dịch vụ là cây cầu giúp đạt được kết quả đó."
    ],
    [
        "id" => 4,
        "code" => "4p",
        "name" => "4P (Picture - Promise - Prove - Push)",
        "sort_order" => 4,
        "description" => "Picture (Bức tranh tương lai) -> Promise (Lời hứa giá trị) -> Prove (Bằng chứng) -> Push (Thúc đẩy mua).",
        "template" => "- Picture: Tạo dựng bức tranh trải nghiệm tuyệt vời cho khách hàng.\n- Promise: Cam kết lợi ích cụ thể mà sản phẩm mang lại.\n- Prove: Đưa ra chứng nhận, số liệu, feedback để tạo niềm tin.\n- Push: Thúc đẩy hành động bằng ưu đãi giới hạn."
    ],
    [
        "id" => 5,
        "code" => "4c",
        "name" => "4C (Clear - Concise - Compelling - Credible)",
        "sort_order" => 5,
        "description" => "Clear (Rõ ràng) -> Concise (Súc tích) -> Compelling (Thuyết phục) -> Credible (Đáng tin cậy).",
        "template" => "- Clear: Thông điệp dễ hiểu, không dùng từ rườm rà.\n- Concise: Đi thẳng vào vấn đề chính.\n- Compelling: Tạo điểm nhấn hấp dẫn khó từ chối.\n- Credible: Dựa trên sự thật, bảo hành hoặc uy tín thương hiệu."
    ],
    [
        "id" => 6,
        "code" => "4u",
        "name" => "4U (Useful - Urgent - Unique - Ultra-specific)",
        "sort_order" => 6,
        "description" => "Useful (Hữu ích) -> Urgent (Cấp bách) -> Unique (Độc đáo) -> Ultra-specific (Cụ thể chi tiết).",
        "template" => "- Useful: Mang lại giá trị thực tế cho người đọc.\n- Urgent: Tạo cảm giác gấp gáp về thời gian/số lượng.\n- Unique: Điểm khác biệt duy nhất không đâu có.\n- Ultra-specific: Con số và chi tiết cực kỳ rõ ràng."
    ],
    [
        "id" => 7,
        "code" => "quest",
        "name" => "QUEST (Qualify - Understand - Educate - Stimulate - Transition)",
        "sort_order" => 7,
        "description" => "Phân loại khách hàng -> Thấu hiểu -> Giáo dục giá trị -> Kích thích mong muốn -> Chuyển đổi.",
        "template" => "- Qualify: Xác định đối tượng mục tiêu cụ thể.\n- Understand: Thể hiện sự thấu hiểu sâu sắc nỗi niềm khách hàng.\n- Educate: Cung cấp kiến thức mới liên quan đến giải pháp.\n- Stimulate: Kích thích khao khát sở hữu.\n- Transition: Chuyển đổi người đọc thành khách hàng mua hàng."
    ],
    [
        "id" => 8,
        "code" => "fab",
        "name" => "FAB (Features - Advantages - Benefits)",
        "sort_order" => 8,
        "description" => "Features (Tính năng) -> Advantages (Ưu điểm) -> Benefits (Lợi ích thực tế cho khách hàng).",
        "template" => "- Features: Liệt kê thông số, đặc điểm nổi bật của sản phẩm.\n- Advantages: Giải thích ưu điểm vượt trội so với các lựa chọn khác.\n- Benefits: Nhấn mạnh lợi ích trực tiếp mà khách hàng nhận được."
    ],
    [
        "id" => 9,
        "code" => "acca",
        "name" => "ACCA (Awareness - Comprehension - Conviction - Action)",
        "sort_order" => 9,
        "description" => "Nhận thức vấn đề -> Thấu hiểu bản chất -> Tin tưởng tuyệt đối -> Kêu gọi hành động.",
        "template" => "- Awareness: Giúp khách hàng nhận ra vấn đề chưa biết.\n- Comprehension: Giải thích rõ nguyên nhân và tác động.\n- Conviction: Xây dựng niềm tin mãnh liệt vào giải pháp.\n- Action: Thúc đẩy khách hàng đưa ra quyết định."
    ],
    [
        "id" => 10,
        "code" => "pastor",
        "name" => "PASTOR (Problem - Amplify - Story - Testimony - Offer - Response)",
        "sort_order" => 10,
        "description" => "Problem -> Amplify -> Story -> Testimony -> Offer -> Response. Dẫn dắt bài viết bán hàng chuyên sâu.",
        "template" => "- Problem: Nêu rõ vấn đề.\n- Amplify: Khuếch đại nỗi đau.\n- Story: Kể câu chuyện trải nghiệm.\n- Testimony: Đưa ra bằng chứng thực tế từ người dùng.\n- Offer: Đưa ra lời đề nghị hấp dẫn.\n- Response: Kêu gọi hành động phản hồi."
    ],
    [
        "id" => 11,
        "code" => "slap",
        "name" => "SLAP (Stop - Look - Act - Purchase)",
        "sort_order" => 11,
        "description" => "Stop (Dừng lại) -> Look (Quan sát) -> Act (Hành động) -> Purchase (Mua hàng).",
        "template" => "- Stop: Tiêu đề giật gân khiến người đọc dừng lướt feed.\n- Look: Nội dung trực quan lôi cuốn người đọc khám phá.\n- Act: Hướng dẫn bước thực hiện đơn giản.\n- Purchase: Đẩy chốt đơn ngay lập tức."
    ],
    [
        "id" => 12,
        "code" => "sss",
        "name" => "SSS (Star - Story - Solution)",
        "sort_order" => 12,
        "description" => "Star (Nhân vật) -> Story (Câu chuyện) -> Solution (Giải pháp). Phù hợp bài viết Storytelling & Video ngắn.",
        "template" => "- Star: Giới thiệu nhân vật chính truyền cảm hứng.\n- Story: Kể hành trình vượt qua thử thách của nhân vật.\n- Solution: Khám phá ra giải pháp giúp thay đổi cuộc sống."
    ],
    [
        "id" => 13,
        "code" => "app",
        "name" => "APP (Agree - Promise - Preview)",
        "sort_order" => 13,
        "description" => "Agree (Tạo sự đồng ý) -> Promise (Hứa hẹn giá trị) -> Preview (Xem trước nội dung).",
        "template" => "- Agree: Đặt vấn đề khiến người đọc gật đầu đồng ý ngay.\n- Promise: Hứa hẹn giải pháp mang lại kết quả bất ngờ.\n- Preview: Tóm tắt các điểm chính sẽ chia sẻ trong bài."
    ],
    [
        "id" => 14,
        "code" => "pppp",
        "name" => "PPPP (Picture - Promise - Prove - Push)",
        "sort_order" => 14,
        "description" => "Mô hình 4P phiên bản trực quan: Bức tranh sinh động -> Lời hứa -> Chứng minh -> Thúc đẩy.",
        "template" => "- Picture: Phác họa bức tranh tương lai đầy cảm xúc.\n- Promise: Lời hứa thương hiệu chắc chắn.\n- Prove: Bằng chứng số liệu, chứng nhận rõ ràng.\n- Push: Kêu gọi chốt đơn nhanh chóng."
    ],
    [
        "id" => 15,
        "code" => "hero",
        "name" => "HERO (Hook - Empathy - Remedy - Outcome)",
        "sort_order" => 15,
        "description" => "Hook (Giật tiêu đề) -> Empathy (Thấu hiểu đồng cảm) -> Remedy (Phương thuốc) -> Outcome (Kết quả).",
        "template" => "- Hook: Tiêu đề thu hút sự chú ý ngay từ giây đầu tiên.\n- Empathy: Chia sẻ sự đồng cảm sâu sắc với khó khăn của người đọc.\n- Remedy: Đưa ra bí quyết/phương thuốc tháo gỡ khó khăn.\n- Outcome: Khẳng định kết quả mỹ mãn đạt được."
    ],
    [
        "id" => 16,
        "code" => "epic",
        "name" => "EPIC (Engage - Purpose - Inspire - Convert)",
        "sort_order" => 16,
        "description" => "Engage (Lôi cuốn) -> Purpose (Mục tiêu) -> Inspire (Truyền cảm hứng) -> Convert (Chuyển đổi).",
        "template" => "- Engage: Lôi cuốn bằng chủ đề đang hot.\n- Purpose: Nêu rõ mục tiêu giá trị mang lại.\n- Inspire: Truyền cảm hứng hành động tích cực.\n- Convert: Chuyển đổi người đọc thành khách hàng trung thành."
    ],
    [
        "id" => 17,
        "code" => "5w1h",
        "name" => "5W1H (Who - What - Where - When - Why - How)",
        "sort_order" => 17,
        "description" => "Trả lời đầy đủ 6 câu hỏi cốt lõi giúp nội dung minh bạch, bao quát mọi khía cạnh.",
        "template" => "- Who: Ai là người nên dùng?\n- What: Sản phẩm/dịch vụ này là gì?\n- Where: Dùng ở đâu, tìm mua ở đâu?\n- When: Khi nào nên sử dụng?\n- Why: Tại sao đây là lựa chọn hàng đầu?\n- How: Cách thức đăng ký/sử dụng như thế nào?"
    ],
    [
        "id" => 18,
        "code" => "5a",
        "name" => "5A (Awareness - Appeal - Ask - Act - Advocate)",
        "sort_order" => 18,
        "description" => "Nhận biết -> Thu hút -> Tìm hiểu -> Hành động -> Lan tỏa thương hiệu.",
        "template" => "- Awareness: Tạo sự hiện diện thương hiệu.\n- Appeal: Gia tăng sức hấp dẫn độc đáo.\n- Ask: Giải đáp các thắc mắc tìm hiểu.\n- Act: Thúc đẩy mua hàng thành công.\n- Advocate: Khuyến khích giới thiệu cho người thân/bạn bè."
    ],
    [
        "id" => 19,
        "code" => "storytelling",
        "name" => "Storytelling (Kể chuyện thương hiệu)",
        "sort_order" => 19,
        "description" => "Dẫn dắt bằng câu chuyện chân thực, giàu cảm xúc giúp kết nối thương hiệu với trái tim khách hàng.",
        "template" => "- Mở đầu: Bối cảnh và nhân vật trải nghiệm.\n- Thử thách: Những khó khăn, nút thắt bất ngờ gặp phải.\n- Khám phá: Tìm ra chân lý/giải pháp vượt qua khó khăn.\n- Đúc kết: Bài học và thông điệp ý nghĩa gửi tới độc giả."
    ],
    [
        "id" => 20,
        "code" => "spin",
        "name" => "Spin Content (Đa phiên bản chống trùng lặp)",
        "sort_order" => 20,
        "description" => "Tạo các đoạn văn bản linh hoạt phân cách bằng dấu | để hệ thống tự động xoay tua bài viết.",
        "template" => "Mẫu 1 | Mẫu 2 | Mẫu 3\nHệ thống sẽ tự động lấy ngẫu nhiên 1 trong các mẫu trên khi đăng bài."
    ]
];

// 2. Danh sách 9 Phong cách viết Content
$styles = [
    [
        "id" => 1,
        "code" => "ban_hang",
        "name" => "Bán hàng / Hard Sale (Khuyến mãi, chốt đơn ngay)",
        "sort_order" => 1,
        "description" => "Giọng văn trực diện, tập trung vào ưu đãi, tính năng vượt trội, cam kết chất lượng và kêu gọi chốt đơn quyết liệt.",
        "prompt_style" => "Hãy viết bài theo phong cách BÁN HÀNG TRỰC TIẾP (Hard Sale). Tập trung vào lợi ích sản phẩm, chương trình khuyến mãi hấp dẫn, tạo cảm giác khan hiếm và đưa ra lời kêu gọi mua hàng (CTA) mạnh mẽ."
    ],
    [
        "id" => 2,
        "code" => "chia_se",
        "name" => "Chia sẻ kiến thức / Educational (Mẹo hay, giá trị)",
        "sort_order" => 2,
        "description" => "Giọng văn chuyên gia thân thiện, cung cấp kiến thức giá trị, hướng dẫn mẹo hay có tính ứng dụng cao.",
        "prompt_style" => "Hãy viết bài theo phong cách CHIA SẺ KIẾN THỨC (Educational). Giọng văn hữu ích, khách quan, cung cấp các mẹo hay, hướng dẫn từng bước rõ ràng giúp người đọc tiếp thu giá trị trước khi nhắc nhẹ đến giải pháp."
    ],
    [
        "id" => 3,
        "code" => "ke_chuyen",
        "name" => "Kể chuyện / Storytelling (Tâm sự trải nghiệm, đồng cảm)",
        "sort_order" => 3,
        "description" => "Dẫn dắt bằng trải nghiệm cá nhân hoặc nhân vật thực tế, giọng văn chân thật, tạo sự kết nối cảm xúc.",
        "prompt_style" => "Hãy viết bài theo phong cách KỂ CHUYỆN (Storytelling). Dẫn dắt người đọc bằng một câu chuyện chân thực, có bối cảnh, cảm xúc và bài học đúc kết gắn liền với thương hiệu."
    ],
    [
        "id" => 4,
        "code" => "giat_gan",
        "name" => "Giật gân / Bắt mắt (Tiêu đề tò mò, kịch tính)",
        "sort_order" => 4,
        "description" => "Sử dụng tiêu đề độc lạ, kịch tính, tạo cảm giác tò mò cao khiến người đọc không thể bỏ qua.",
        "prompt_style" => "Hãy viết bài theo phong cách GIẬT GÂN (Viral/Hook). Sử dụng câu từ kích thích sự tò mò, giật tiều đề gây bất ngờ, tạo sự kịch tính cuốn hút người đọc theo dõi hết bài."
    ],
    [
        "id" => 5,
        "code" => "hai_huoc",
        "name" => "Hài hước / Trendy (Dí dỏm, meme, bắt trend)",
        "sort_order" => 5,
        "description" => "Ví von hóm hỉnh, lồng ghép thuật ngữ hot trend của giới trẻ mang lại tiếng cười sảng khoái và gần gũi.",
        "prompt_style" => "Hãy viết bài theo phong cách HÀI HƯỚC, DÍ DỎM (Humorous). Lồng ghép các câu từ bắt trend, ví von hài hước giúp bài viết tự nhiên, gần gũi và tạo tiếng cười cho người đọc."
    ],
    [
        "id" => 6,
        "code" => "chuyen_gia",
        "name" => "Chuyên gia / Uy tín (Phân tích chuẩn mực, số liệu)",
        "sort_order" => 6,
        "description" => "Lập luận logic sắc bén, trích dẫn số liệu, dẫn chứng thực tế minh bạch tạo dựng niềm tin tuyệt đối.",
        "prompt_style" => "Hãy viết bài theo phong cách CHUYÊN GIA (Authority/Expert). Giọng văn chuyên nghiệp, lập luận sắc bén, trích dẫn số liệu hoặc căn cứ uy tín để xây dựng niềm tin tối đa với khách hàng."
    ],
    [
        "id" => 7,
        "code" => "tam_su",
        "name" => "Tâm sự / Đồng cảm (Nhẹ nhàng, chia sẻ khó khăn)",
        "sort_order" => 7,
        "description" => "Giọng văn ấm áp, lắng nghe và thấu hiểu những nỗi niềm, góc khuất của khách hàng trong cuộc sống.",
        "prompt_style" => "Hãy viết bài theo phong cách TÂM SỰ, ĐỒNG CẢM (Empathetic). Sử dụng giọng văn nhẹ nhàng, sâu lắng, chia sẻ những nỗi đau hoặc góc khuất chung để chạm tới cảm xúc người đọc."
    ],
    [
        "id" => 8,
        "code" => "so_sanh",
        "name" => "So sánh / Đánh giá (Phân tích ưu nhược điểm)",
        "sort_order" => 8,
        "description" => "Đặt lên bàn cân các phương án, phân tích điểm mạnh - điểm yếu giúp người mua dễ đưa ra quyết định.",
        "prompt_style" => "Hãy viết bài theo phong cách SO SÁNH & ĐÁNH GIÁ (Comparison/Review). Đặt sản phẩm/giải pháp lên bàn cân, phân tích minh bạch ưu - nhược điểm giúp khách hàng tự tin đưa ra lựa chọn đúng đắn."
    ],
    [
        "id" => 9,
        "code" => "toi_gian",
        "name" => "Tối giản / Súc tích (Gạch đầu dòng, súc tích)",
        "sort_order" => 9,
        "description" => "Đi thẳng vào vấn đề chính, các gạch đầu dòng rõ ràng, không hoa mỹ dài dòng, tiết kiệm thời gian đọc.",
        "prompt_style" => "Hãy viết bài theo phong cách TỐI GIẢN (Minimalist). Đi thẳng vào trọng tâm, trình bày bằng các gạch đầu dòng rõ ràng, súc tích, không viết dài dòng."
    ]
];

// 3. Xử lý Query Parameter: ?prompt=... và ?phongcach=...
$promptQuery = isset($_GET['prompt']) ? strtolower(trim($_GET['prompt'])) : (isset($_GET['formula']) ? strtolower(trim($_GET['formula'])) : '');
$styleQuery  = isset($_GET['phongcach']) ? strtolower(trim($_GET['phongcach'])) : '';

// 3.1 Nếu có tham số ?prompt=...
if (!empty($promptQuery)) {
    if ($promptQuery === 'all') {
        echo json_encode([
            "success" => true,
            "total" => count($formulas),
            "formulas" => $formulas
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $filteredFormulas = array_values(array_filter($formulas, function($item) use ($promptQuery) {
        return (strtolower($item['code']) === $promptQuery) 
            || (strtolower($item['name']) === $promptQuery)
            || (strpos(strtolower($item['code']), $promptQuery) !== false)
            || (strpos(strtolower($item['name']), $promptQuery) !== false);
    }));

    if (count($filteredFormulas) > 0) {
        echo json_encode([
            "success" => true,
            "query" => $promptQuery,
            "total" => count($filteredFormulas),
            "formulas" => $filteredFormulas
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            "success" => false,
            "query" => $promptQuery,
            "message" => "Không tìm thấy công thức phù hợp với từ khóa '$promptQuery'",
            "available_codes" => array_column($formulas, 'code')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// 3.2 Nếu có tham số ?phongcach=...
if (!empty($styleQuery)) {
    if ($styleQuery === 'all') {
        echo json_encode([
            "success" => true,
            "total" => count($styles),
            "phongcach" => $styles
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $filteredStyles = array_values(array_filter($styles, function($item) use ($styleQuery) {
        return (strtolower($item['code']) === $styleQuery) 
            || (strpos(strtolower($item['code']), $styleQuery) !== false)
            || (strpos(strtolower($item['name']), $styleQuery) !== false);
    }));

    if (count($filteredStyles) > 0) {
        echo json_encode([
            "success" => true,
            "query" => $styleQuery,
            "total" => count($filteredStyles),
            "phongcach" => $filteredStyles
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            "success" => false,
            "query" => $styleQuery,
            "message" => "Không tìm thấy phong cách phù hợp với từ khóa '$styleQuery'",
            "available_codes" => array_column($styles, 'code')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// 3.3 Mặc định không truyền tham số -> Trả về toàn bộ 20 công thức và 9 phong cách
echo json_encode([
    "success" => true,
    "total_formulas" => count($formulas),
    "total_phongcach" => count($styles),
    "formulas" => $formulas,
    "phongcach" => $styles
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);