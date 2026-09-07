<?php
/**
 * API Công Thức Content & Phong Cách Nội Dung (19 Công Thức & 9 Phong Cách Chuẩn Marketing)
 * 
 * Nguồn tham chiếu kỹ thuật:
 *   - Copywriting School (https://copywriting.school/formulas)
 *   - Wilson Komala (https://wilsonkomala.medium.com)
 *   - SimplePage 101 Công Thức Content (https://simplepage.vn)
 *   - Spec chuẩn khóa định nghĩa HERO & EPIC theo thiết kế hệ thống.
 * 
 * Ví dụ gọi API:
 *   GET /api/content.php?prompt=aida           -> Trả về thông tin chi tiết công thức AIDA
 *   GET /api/content.php?prompt=all            -> Trả về toàn bộ 19 công thức
 *   GET /api/content.php?phongcach=ban_hang    -> Trả về chi tiết phong cách bán hàng
 *   GET /api/content.php?phongcach=all         -> Trả về toàn bộ 9 phong cách
 *   GET /api/content.php                       -> Trả về toàn bộ dữ liệu (19 công thức & 9 phong cách)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// 1. Danh sách 19 Công thức Content chuẩn Marketing nâng cao
$formulas = [
    [
        "id" => 1,
        "code" => "aida",
        "name" => "AIDA (Attention - Interest - Desire - Action)",
        "sort_order" => 1,
        "description" => "Công thức kinh điển trong Marketing giúp gây chú ý ngay từ câu đầu tiên, duy trì sự thích thú, khơi gợi khao khát sở hữu và thúc đẩy hành động mua hàng nhanh chóng.",
        "best_for" => "Bài viết bán hàng trực tiếp, quảng cáo Facebook Ads, bài giới thiệu sản phẩm mới.",
        "structure" => [
            "Attention (Gây chú ý)" => "Câu hook/tiêu đề giật gân, độc lạ hoặc đặt câu hỏi chạm đúng nỗi đau.",
            "Interest (Tạo thích thú)" => "Đưa ra thông tin độc đáo, số liệu thú vị hoặc câu chuyện khiến khách hàng tò mò.",
            "Desire (Khơi khao khát)" => "Nêu bật lợi ích vượt trội, viễn cảnh tốt đẹp và bằng chứng sở hữu sản phẩm.",
            "Action (Kêu gọi hành động)" => "Lời kêu gọi hành động (CTA) rõ ràng kèm ưu đãi có giới hạn."
        ],
        "prompt_instruction" => "Áp dụng Công thức AIDA:\n- Attention: Giật tiêu đề gây tò mò, thu hút sự chú ý ngay lập tức.\n- Interest: Nêu thông tin thú vị, số liệu hoặc nỗi đau khiến người đọc quan tâm theo dõi.\n- Desire: Đưa ra lợi ích vượt trội và viễn cảnh sở hữu sản phẩm/dịch vụ.\n- Action: Kêu gọi hành động (Call To Action) chốt đơn rõ ràng.",
        "example" => "Attention: 90% chủ shop mất tiền ngu vì chạy quảng cáo sai cách!\nInterest: Với bí quyết này, bạn giảm 50% chi phí MKT ngay lập tức.\nDesire: Hơn 5,000 chủ shop đã áp dụng và bùng nổ doanh số.\nAction: Bấm nút Đăng Ký ngay hôm nay để nhận tài liệu miễn phí!"
    ],
    [
        "id" => 2,
        "code" => "pas",
        "name" => "PAS (Problem - Agitate - Solve)",
        "sort_order" => 2,
        "description" => "Đánh trực diện vào vấn đề nhức nhối của khách hàng, xoáy sâu nỗi đau và tác hại nếu không xử lý ngay, sau đó đưa ra giải pháp cứu cánh triệt để.",
        "best_for" => "Bài viết giải quyết nỗi đau, dịch vụ tư vấn, sản phẩm y tế/làm đẹp/chăm sóc sức khỏe.",
        "structure" => [
            "Problem (Nêu vấn đề)" => "Xác định khó khăn, nỗi đau hoặc rắc rối nhức nhối mà khách hàng đang chịu đựng.",
            "Agitate (Xoáy sâu nỗi đau)" => "Nhấn mạnh hậu quả nặng nề và cảm giác bế tắc nếu kéo dài tình trạng này.",
            "Solve (Giải pháp cứu cánh)" => "Giới thiệu sản phẩm/dịch vụ như giải pháp cứu cánh tối ưu xử lý dứt điểm."
        ],
        "prompt_instruction" => "Áp dụng Công thức PAS:\n- Problem: Nêu rõ vấn đề/nỗi đau nhức nhối mà khách hàng đang gặp phải.\n- Agitate: Xoáy sâu vào tác hại, hậu quả và cảm giác khó chịu nếu không xử lý ngay.\n- Solve: Giới thiệu sản phẩm/dịch vụ như một giải pháp cứu cánh tối ưu.",
        "example" => "Problem: Tới cuối tháng lại lo lắng không đủ tiền trả mặt bằng?\nAgitate: Nếu kéo dài 2 tháng nữa, bạn có thể phải đóng cửa đứa con tinh thần của mình!\nSolve: Dịch vụ tư vấn tối ưu chi phí của chúng tôi sẽ giúp bạn lội ngược dòng."
    ],
    [
        "id" => 3,
        "code" => "bab",
        "name" => "BAB (Before - After - Bridge)",
        "sort_order" => 3,
        "description" => "So sánh bức tranh bế tắc trước khi dùng và tương lai tươi đẹp sau khi dùng, sử dụng sản phẩm như cây cầu kết nối hai thực tại.",
        "best_for" => "Bài viết lột xác, feedback thực tế, sản phẩm giảm cân, học ngoại ngữ, nâng cấp bản thân.",
        "structure" => [
            "Before (Trước khi dùng)" => "Mô tả thực trạng khó khăn, tự ti hoặc bế tắc hiện tại của khách hàng.",
            "After (Sau khi dùng)" => "Vẽ ra viễn cảnh hoàn hảo, sung sướng sau khi đã giải quyết xong rắc rối.",
            "Bridge (Cầu nối giải pháp)" => "Giới thiệu sản phẩm/dịch vụ là cây cầu giúp biến viễn cảnh thành hiện thực."
        ],
        "prompt_instruction" => "Áp dụng Công thức BAB:\n- Before: Thực trạng khó khăn, bế tắc hiện tại của khách hàng.\n- After: Bức tranh kết quả hoàn hảo, sung sướng sau khi giải quyết.\n- Bridge: Giới thiệu sản phẩm/dịch vụ là cây cầu nối đạt được kết quả đó.",
        "example" => "Before: Da ngăm đen, thiếu tự tin khi diện đồ đi tiệc với bạn bè.\nAfter: Da trắng hồng rạng rỡ, thu hút mọi ánh nhìn sau 2 tuần.\nBridge: Bí quyết chính là dòng kem dưỡng trắng chuyên sâu đến từ Hàn Quốc."
    ],
    [
        "id" => 4,
        "code" => "4p",
        "name" => "4P (Picture - Promise - Prove - Push)",
        "sort_order" => 4,
        "description" => "Phác họa bức tranh tương lai sống động, đưa ra lời hứa thương hiệu, chứng minh bằng dữ liệu uy tín và thúc đẩy chốt đơn.",
        "best_for" => "Khóa học đào tạo, bất động sản, tài chính, sản phẩm cao cấp.",
        "structure" => [
            "Picture (Bức tranh tương lai)" => "Vẽ nên viễn cảnh ước mơ mà khách hàng luôn khao khát.",
            "Promise (Lời hứa giá trị)" => "Cam kết lợi ích cụ thể sản phẩm mang lại.",
            "Prove (Chứng minh uy tín)" => "Bằng chứng số liệu, chứng nhận uy tín, feedback khách hàng thực tế.",
            "Push (Thúc đẩy chốt đơn)" => "Tạo sự gấp gáp về ưu đãi/giới hạn suất để chốt đơn ngay."
        ],
        "prompt_instruction" => "Áp dụng Công thức 4P:\n- Picture: Vẽ ra bức tranh trải nghiệm tuyệt vời cho khách hàng.\n- Promise: Lời hứa cam kết lợi ích thực tế sản phẩm mang lại.\n- Prove: Đưa ra bằng chứng, số liệu, chứng nhận uy tín.\n- Push: Thúc đẩy chốt đơn bằng ưu đãi giới hạn.",
        "example" => "Picture: Tưởng tượng bạn tự do tài chính, du lịch khắp nơi mà doanh nghiệp vẫn vận hành tự động.\nPromise: Khóa học của chúng tôi cam kết giúp bạn xây dựng hệ thống đó trong 90 ngày.\nProve: Đã có 1,200 học viên thành công và x3 doanh thu.\nPush: Chỉ còn 5 suất ưu đãi 50% cuối cùng!"
    ],
    [
        "id" => 5,
        "code" => "4c",
        "name" => "4C (Clear - Concise - Compelling - Credible)",
        "sort_order" => 5,
        "description" => "Bộ tiêu chí viết content súc tích, đi thẳng vào vấn đề, giàu sức thuyết phục và minh bạch đáng tin cậy.",
        "best_for" => "Bài viết B2B, tin tức thông báo, bài ra mắt sản phẩm công nghệ.",
        "structure" => [
            "Clear (Rõ ràng)" => "Thông điệp dễ hiểu, không dùng từ mơ hồ.",
            "Concise (Súc tích)" => "Loại bỏ từ thừa, đi thẳng vào trọng tâm.",
            "Compelling (Thuyết phục)" => "Tạo điểm nhấn hấp dẫn khó từ chối.",
            "Credible (Đáng tin cậy)" => "Dựa trên sự thật, bảo hành chính hãng hoặc cam kết uy tín."
        ],
        "prompt_instruction" => "Áp dụng Công thức 4C: Viết bài Clear (Rõ ràng, dễ hiểu) - Concise (Súc tích, ngắn gọn) - Compelling (Thuyết phục hấp dẫn) - Credible (Đáng tin cậy, có căn cứ).",
        "example" => "Clear & Concise: Áo phông Cotton 100% thấm hút mồ hôi tuyệt đối.\nCompelling: Mặc mát lạnh ngày hè, không xù lông sau 50 lần giặt.\nCredible: Bảo hành 1 đổi 1 trong 30 ngày nếu không đúng cam kết."
    ],
    [
        "id" => 6,
        "code" => "4u",
        "name" => "4U (Useful - Urgent - Unique - Ultra-specific)",
        "sort_order" => 6,
        "description" => "Công thức tối ưu tiêu đề và nội dung dựa trên 4 yếu tố: Hữu ích, Cấp bách, Độc đáo và Cực kỳ cụ thể.",
        "best_for" => "Viết tiêu đề bài viết, email marketing, thông báo khuyến mãi lớn.",
        "structure" => [
            "Useful (Hữu ích)" => "Mang lại lợi ích thiết thực ngay cho người đọc.",
            "Urgent (Cấp bách)" => "Tạo áp lực thời gian hoặc giới hạn số lượng.",
            "Unique (Độc đáo)" => "Điểm khác biệt duy nhất không tìm thấy ở đối thủ.",
            "Ultra-specific (Cực kỳ cụ thể)" => "Dùng con số chính xác và chi tiết cụ thể."
        ],
        "prompt_instruction" => "Áp dụng Công thức 4U: Nội dung Useful (Hữu ích cho người đọc) - Urgent (Tạo cảm giác gấp gáp) - Unique (Điểm khác biệt độc đáo) - Ultra-specific (Số liệu cực kỳ cụ thể).",
        "example" => "Chỉ còn 3 suất cuối cùng trong hôm nay: Bí quyết tiết kiệm 50% chi phí MKT nhờ quy trình tự động 5 bước!"
    ],
    [
        "id" => 7,
        "code" => "quest",
        "name" => "QUEST (Qualify - Understand - Educate - Stimulate - Transition)",
        "sort_order" => 7,
        "description" => "Dẫn dắt khách hàng qua 5 giai đoạn tâm lý từ phân loại đối tượng đến giáo dục giá trị và chuyển đổi thành đơn hàng.",
        "best_for" => "Bài viết bán hàng bài bản dài, ebook, hội thảo web, khóa học chuyên sâu.",
        "structure" => [
            "Qualify (Phân loại)" => "Gọi tên chính xác đối tượng mục tiêu.",
            "Understand (Thấu hiểu)" => "Bày tỏ sự thấu hiểu sâu sắc niềm đau của khách.",
            "Educate (Giáo dục)" => "Cung cấp góc nhìn/kiến thức mới về giải pháp.",
            "Stimulate (Kích thích)" => "Nêu nổi bật giá trị vượt trội để kích thích khao khát.",
            "Transition (Chuyển đổi)" => "Hướng dẫn các bước đăng ký/mua hàng cụ thể."
        ],
        "prompt_instruction" => "Áp dụng Công thức QUEST:\n- Qualify: Phân loại đúng đối tượng mục tiêu.\n- Understand: Bày tỏ sự thấu hiểu nỗi niềm khó khăn của họ.\n- Educate: Giáo dục kiến thức giá trị mới.\n- Stimulate: Kích thích khao khát sở hữu giải pháp.\n- Transition: Chuyển đổi kêu gọi mua hàng.",
        "example" => "Dành riêng cho các mẹ bỉm sữa muốn kiếm thêm thu nhập tại nhà mà không ảnh hưởng chăm con..."
    ],
    [
        "id" => 8,
        "code" => "fab",
        "name" => "FAB (Features - Advantages - Benefits)",
        "sort_order" => 8,
        "description" => "Chuyển hóa các tính năng kỹ thuật khô khô thành ưu điểm vượt trội và lợi ích thiết thực đối với người dùng.",
        "best_for" => "Bài review sản phẩm công nghệ, gia dụng, thiết bị điện tử, phần mềm.",
        "structure" => [
            "Features (Tính năng)" => "Liệt kê thông số kỹ thuật, chất liệu, thiết kế.",
            "Advantages (Ưu điểm)" => "Phân tích ưu điểm nổi bật so với thế hệ cũ hoặc đối thủ.",
            "Benefits (Lợi ích)" => "Nhấn mạnh giá trị cảm xúc & tiện ích thực tế mà khách nhận được."
        ],
        "prompt_instruction" => "Áp dụng Công thức FAB:\n- Features: Tính năng nổi bật của sản phẩm.\n- Advantages: Ưu điểm vượt trội hơn các giải pháp khác.\n- Benefits: Lợi ích thực tế người dùng trực tiếp nhận được.",
        "example" => "Features: Pin dung lượng 5,000mAh sạc siêu nhanh 67W.\nAdvantages: Sạc đầy 100% chỉ trong 25 phút, không lo đứt quãng công việc.\nBenefits: Bạn thoải mái livestream cả ngày mà không cần mang sạc dự phòng."
    ],
    [
        "id" => 9,
        "code" => "acca",
        "name" => "ACCA (Awareness - Comprehension - Conviction - Action)",
        "sort_order" => 9,
        "description" => "Xây dựng nhận thức bài bản, giúp khách thấu hiểu bản chất, xây dựng niềm tin vững chắc và đưa ra hành động.",
        "best_for" => "Bài viết giáo dục thị trường, sản phẩm dịch vụ mới đòi hỏi tư duy.",
        "structure" => [
            "Awareness (Nhận thức)" => "Giúp khách nhận ra vấn đề ẩn chưa biết đến.",
            "Comprehension (Thấu hiểu)" => "Giải thích rõ nguyên nhân và tác hại sâu xa.",
            "Conviction (Tin tưởng)" => "Thuyết phục bằng lập luận logic và bằng chứng.",
            "Action (Hành động)" => "Kêu gọi hành động dứt điểm."
        ],
        "prompt_instruction" => "Áp dụng Công thức ACCA: Awareness (Giúp nhận thức vấn đề) -> Comprehension (Giúp thấu hiểu bản chất) -> Conviction (Tạo niềm tin vững chắc) -> Action (Kêu gọi hành động).",
        "example" => "Giúp khách hiểu rõ bản chất vấn đề -> Thuyết phục bằng lý lẽ vững chắc -> Tạo niềm tin -> Kêu gọi mua."
    ],
    [
        "id" => 10,
        "code" => "pastor",
        "name" => "PASTOR (Problem - Amplify - Story - Testimony - Offer - Response)",
        "sort_order" => 10,
        "description" => "Công thức Copywriting bán hàng đỉnh cao dành cho bài viết dài (Sales page), kết hợp nỗi đau, câu chuyện và bằng chứng.",
        "best_for" => "Trang bán hàng dài (Sales page), bài viết ra mắt khóa học/dịch vụ giá trị cao.",
        "structure" => [
            "Problem (Vấn đề)" => "Nêu rõ vấn đề.",
            "Amplify (Khuếch đại)" => "Nhấn mạnh rủi ro nếu bỏ qua.",
            "Story (Câu chuyện)" => "Kể hành trình tìm ra giải pháp.",
            "Testimony (Bằng chứng)" => "Đưa ra đánh giá khách quan của người đi trước.",
            "Offer (Lời đề nghị)" => "Đưa ra trọn bộ giải pháp kèm quà tặng.",
            "Response (Phản hồi)" => "Kêu gọi đăng ký ngay lập tức."
        ],
        "prompt_instruction" => "Áp dụng Công thức PASTOR: Problem (Nêu vấn đề) -> Amplify (Khuếch đại rủi ro) -> Story (Câu chuyện lột xác) -> Testimony (Bằng chứng thực tế) -> Offer (Lời đề nghị hấp dẫn) -> Response (Kêu gọi phản hồi mua hàng).",
        "example" => "Nêu nỗi đau -> Khuếch đại ảnh hưởng -> Kể câu chuyện lột xác -> Lời đề nghị mua hàng -> Kêu gọi phản hồi."
    ],
    [
        "id" => 11,
        "code" => "slap",
        "name" => "SLAP (Stop - Look - Act - Purchase)",
        "sort_order" => 11,
        "description" => "Quy trình thu hút thị giác và tâm lý giúp dừng người đọc lướt trang, thúc đẩy quan sát và chốt đơn tức thì.",
        "best_for" => "Bài viết Facebook/Instagram thời trang, ẩm thực, tiêu dùng nhanh.",
        "structure" => [
            "Stop (Dừng lại)" => "Tiêu đề hoặc hình ảnh giật gân khiến người đọc dừng lại 3 giây.",
            "Look (Quan sát)" => "Trình bày bắt mắt lôi cuốn người đọc khám phá chi tiết.",
            "Act (Hành động)" => "Đưa ra thông tin ưu đãi cực hời.",
            "Purchase (Mua hàng)" => "Kêu gọi chốt đơn ngay không cần suy nghĩ."
        ],
        "prompt_instruction" => "Áp dụng Công thức SLAP: Stop (Dừng người đọc lướt feed) -> Look (Lôi cuốn quan sát chi tiết) -> Act (Hướng dẫn hành động) -> Purchase (Thúc đẩy chốt đơn).",
        "example" => "Dừng lại 3 giây! Mẫu váy hot nhất mùa hè đã cập bến. Đặt hàng hôm nay nhận voucher giảm 30%."
    ],
    [
        "id" => 12,
        "code" => "sss",
        "name" => "SSS (Star - Story - Solution)",
        "sort_order" => 12,
        "description" => "Tập trung vào một nhân vật trung tâm (Star), kể lại hành trình thử thách (Story) và khám phá ra giải pháp (Solution).",
        "best_for" => "Content Storytelling, kịch bản Video TikTok/Reels, câu chuyện khởi nghiệp.",
        "structure" => [
            "Star (Nhân vật)" => "Giới thiệu nhân vật chính truyền cảm hứng.",
            "Story (Câu chuyện)" => "Mô tả gian nan, thử thách tưởng chừng bế tắc.",
            "Solution (Giải pháp)" => "Bước ngoặt tìm ra giải pháp thay đổi cuộc đời."
        ],
        "prompt_instruction" => "Áp dụng Công thức SSS: Star (Giới thiệu nhân vật truyền cảm hứng) -> Story (Kể hành trình thử thách gian nan) -> Solution (Khám phá giải pháp đột phá).",
        "example" => "Star: Câu chuyện của Anh Nam - thợ xây 10 năm kinh nghiệm.\nStory: Gian nan khi tìm kiếm vật liệu giàn giáo an toàn mà giá hợp lý.\nSolution: Tìm ra giải pháp cốp pha thép tiêu chuẩn giúp tiết kiệm 30% chi phí thi công."
    ],
    [
        "id" => 13,
        "code" => "app",
        "name" => "APP (Agree - Promise - Preview)",
        "sort_order" => 13,
        "description" => "Khởi đầu bằng một nhận định khiến độc giả gật đầu đồng ý, hứa hẹn giá trị và xem trước nội dung nổi bật.",
        "best_for" => "Mở bài Blog, bài viết chia sẻ kinh nghiệm trên Fanpage.",
        "structure" => [
            "Agree (Đồng ý)" => "Đặt vấn đề hoặc sự thật khách hàng luôn gật đầu thừa nhận.",
            "Promise (Lời hứa)" => "Hứa hẹn hướng dẫn giải quyết dứt điểm vấn đề đó.",
            "Preview (Xem trước)" => "Tóm tắt 3-5 ý chính sẽ bật mí trong bài."
        ],
        "prompt_instruction" => "Áp dụng Công thức APP: Agree (Đưa ra nhận định khiến độc giả đồng ý ngay) -> Promise (Hứa hẹn giá trị bất ngờ) -> Preview (Xem trước các điểm chính trong bài).",
        "example" => "Agree: Có phải bạn cũng ghét cảnh tắc đường mỗi sáng?\nPromise: Tôi sẽ chỉ cho bạn cách tiết kiệm 30 phút di chuyển.\nPreview: Đây là 3 cung đường tắt ít người biết..."
    ],
    [
        "id" => 14,
        "code" => "pppp",
        "name" => "PPPP (Picture - Promise - Prove - Push)",
        "sort_order" => 14,
        "description" => "Mô hình 4P trực quan hóa bức tranh tương lai sinh động, lời hứa thương hiệu, chứng minh minh bạch và chốt đơn.",
        "best_for" => "Bài viết giới thiệu giải pháp doanh nghiệp, dịch vụ trọn gói.",
        "structure" => [
            "Picture (Hình ảnh sinh động)" => "Phác họa bức tranh thành công rực rỡ.",
            "Promise (Lời hứa)" => "Lời hứa cam kết từ phía nhà cung cấp.",
            "Prove (Chứng minh)" => "Số liệu chứng minh thực tế và chứng nhận chất lượng.",
            "Push (Thúc đẩy)" => "Thúc đẩy khách hàng quyết định ngay."
        ],
        "prompt_instruction" => "Áp dụng Công thức PPPP: Picture (Bức tranh tương lai sinh động) -> Promise (Lời hứa cam kết) -> Prove (Số liệu chứng minh) -> Push (Chốt đơn nhanh chóng).",
        "example" => "Tạo hình ảnh trực quan sinh động -> Hứa hẹn giá trị -> Chứng minh bằng số liệu -> Đẩy chốt đơn ngay."
    ],
    [
        "id" => 15,
        "code" => "hero",
        "name" => "HERO (Hook - Empathy - Remedy - Outcome)",
        "sort_order" => 15,
        "description" => "Cấu trúc HERO chuẩn spec hệ thống: Hook giật gân -> Empathy thấu hiểu đồng cảm -> Remedy bí quyết phương thuốc -> Outcome kết quả mỹ mãn.",
        "best_for" => "Bài viết vượt qua bế tắc kinh doanh, lấy lại phong độ, chia sẻ chuyên sâu.",
        "structure" => [
            "Hook (Giật tiêu đề)" => "Tiêu đề cực mạnh thu hút sự chú ý tức thì.",
            "Empathy (Đồng cảm)" => "Thấu hiểu sự mệt mỏi, bế tắc của người đọc.",
            "Remedy (Phương thuốc)" => "Đưa ra bí quyết giải pháp độc quyền.",
            "Outcome (Kết quả mỹ mãn)" => "Khẳng định kết quả mỹ mãn đạt được sau khi áp dụng."
        ],
        "prompt_instruction" => "Áp dụng Công thức HERO:\n- Hook: Giật tiêu đề thu hút sự chú ý ngay từ giây đầu tiên.\n- Empathy: Chia sẻ sự thấu hiểu đồng cảm sâu sắc với khó khăn của người đọc.\n- Remedy: Đưa ra phương thuốc/bí quyết tháo gỡ khó khăn.\n- Outcome: Khẳng định kết quả mỹ mãn đạt được.",
        "example" => "Hook: Dừng lại 3 giây nếu bạn đang bế tắc vì doanh số tụt dốc!\nEmpathy: Tôi hiểu cảm giác lo lắng, thức trắng đêm nhìn chi phí tăng vọt.\nRemedy: Đây là bộ quy trình tối ưu Campaign độc quyền.\nOutcome: Lấy lại lợi nhuận ngay trong tuần đầu tiên."
    ],
    [
        "id" => 16,
        "code" => "epic",
        "name" => "EPIC (Engage - Purpose - Inspire - Convert)",
        "sort_order" => 16,
        "description" => "Cấu trúc EPIC chuẩn spec hệ thống: Engage lôi cuốn -> Purpose mục tiêu giá trị -> Inspire truyền cảm hứng -> Convert chuyển đổi.",
        "best_for" => "Bài viết xây dựng thương hiệu cá nhân, truyền động lực, kết nối cộng đồng.",
        "structure" => [
            "Engage (Lôi cuốn)" => "Mở đầu bằng chủ đề nóng hổi hoặc góc nhìn mới lạ.",
            "Purpose (Mục tiêu)" => "Nêu rõ giá trị và ý nghĩa cốt lõi.",
            "Inspire (Truyền cảm hứng)" => "Kích hoạt tinh thần và niềm tin tích cực.",
            "Convert (Chuyển đổi)" => "Kêu gọi tham gia cộng đồng hoặc sở hữu giải pháp."
        ],
        "prompt_instruction" => "Áp dụng Công thức EPIC:\n- Engage: Lôi cuốn bằng chủ đề nóng hổi hoặc góc nhìn độc đáo.\n- Purpose: Nêu rõ mục tiêu giá trị mang lại cho độc giả.\n- Inspire: Truyền cảm hứng hành động tích cực mạnh mẽ.\n- Convert: Chuyển đổi người đọc thành khách hàng/thành viên trung thành.",
        "example" => "Engage: Bắt đầu bằng hook lôi cuốn.\nPurpose: Nêu rõ thông điệp ý nghĩa.\nInspire: Truyền động lực mạnh mẽ.\nConvert: Chuyển đổi thành hành động cụ thể."
    ],
    [
        "id" => 17,
        "code" => "5w1h",
        "name" => "5W1H (Who - What - Where - When - Why - How)",
        "sort_order" => 17,
        "description" => "Cung cấp bức tranh toàn diện 360 độ về sản phẩm/dịch vụ qua 6 câu hỏi kinh điển trong báo chí và marketing.",
        "best_for" => "Bài viết tổng quan sản phẩm, thông báo sự kiện, bài hướng dẫn sử dụng.",
        "structure" => [
            "Who (Ai)" => "Ai là người phù hợp nhất để sử dụng?",
            "What (Cái gì)" => "Sản phẩm/dịch vụ này chứa đựng điều gì đặc biệt?",
            "Where (Ở đâu)" => "Dùng ở đâu, trải nghiệm ở đâu?",
            "When (Khi nào)" => "Thời điểm nào là tốt nhất để bắt đầu?",
            "Why (Tại sao)" => "Lý do tại sao phải chọn sản phẩm này mà không phải cái khác?",
            "How (Như thế nào)" => "Đăng ký và sở hữu bằng cách nào?"
        ],
        "prompt_instruction" => "Áp dụng Công thức 5W1H: Làm rõ Who (Ai dùng) - What (Là cái gì) - Where (Dùng ở đâu) - When (Khi nào) - Why (Tại sao chọn) - How (Cách thức đăng ký).",
        "example" => "Who: Dành cho các chủ shop online.\nWhat: Bộ phần mềm tự động đăng bài multi-channel.\nWhere: Sử dụng trực tiếp trên trình duyệt máy tính.\nWhen: Áp dụng ngay hôm nay.\nWhy: Giúp tiết kiệm 80% thời gian đăng bài.\nHow: Đăng ký trải nghiệm miễn phí tại link bên dưới."
    ],
    [
        "id" => 18,
        "code" => "5a",
        "name" => "5A (Awareness - Appeal - Ask - Act - Advocate)",
        "sort_order" => 18,
        "description" => "Mô hình hành vi khách hàng thời đại số, dẫn dắt từ nhận biết đến lan tỏa thương hiệu cho bạn bè.",
        "best_for" => "Chiến dịch Branding dài hạn, chăm sóc khách hàng trung thành.",
        "structure" => [
            "Awareness (Nhận biết)" => "Giúp khách nhận biết sự tồn tại của thương hiệu.",
            "Appeal (Thu hút)" => "Tạo sức hút khó cưỡng qua thông điệp độc đáo.",
            "Ask (Tìm hiểu)" => "Giải đáp các thắc mắc tìm hiểu của khách hàng.",
            "Act (Hành động)" => "Thúc đẩy hành vi mua hàng.",
            "Advocate (Lan tỏa)" => "Khuyến khích giới thiệu & đánh giá 5 sao."
        ],
        "prompt_instruction" => "Áp dụng Công thức 5A: Awareness (Tạo nhận biết) -> Appeal (Gia tăng sức hút) -> Ask (Giải đáp thắc mắc) -> Act (Thúc đẩy mua) -> Advocate (Khuyến khích lan tỏa thương hiệu).",
        "example" => "Nhận biết -> Thu hút -> Tìm hiểu -> Mua hàng -> Lan tỏa & Giới thiệu cho người khác."
    ],
    [
        "id" => 19,
        "code" => "storytelling",
        "name" => "Storytelling (Kể chuyện thương hiệu)",
        "sort_order" => 19,
        "description" => "Nghệ thuật kể chuyện truyền cảm hứng, kết nối trái tim khách hàng qua bối cảnh chân thực và bài học nhân văn.",
        "best_for" => "Bài viết thương hiệu cá nhân, câu chuyện sản phẩm, câu chuyện người sáng lập.",
        "structure" => [
            "Context (Bối cảnh)" => "Bối cảnh và nhân vật khởi đầu.",
            "Conflict (Biến cố)" => "Biến cố, khó khăn hoặc thử thách bất ngờ.",
            "Climax (Đỉnh điểm)" => "Đỉnh điểm căng thẳng và bước ngoặt thay đổi.",
            "Resolution (Giải pháp)" => "Giải pháp và bài học sâu sắc gửi gắm."
        ],
        "prompt_instruction" => "Áp dụng Công thức Storytelling: Dẫn dắt bằng câu chuyện chân thực, có bối cảnh khởi đầu, thử thách gian nan, bước ngoặt giải pháp và thông điệp thương hiệu sâu sắc.",
        "example" => "Mở đầu bằng bối cảnh -> Biến cố thử thách -> Khám phá giải pháp -> Đúc kết thông điệp ý nghĩa."
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

// 3.3 Mặc định không truyền tham số -> Trả về toàn bộ 19 công thức và 9 phong cách
echo json_encode([
    "success" => true,
    "total_formulas" => count($formulas),
    "total_phongcach" => count($styles),
    "formulas" => $formulas,
    "phongcach" => $styles
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);