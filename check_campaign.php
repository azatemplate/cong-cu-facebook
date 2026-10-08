<?php
// check_campaign.php — CLI Script kiểm tra chỉ số & điều kiện của 1 Campaign cụ thể
// Cách dùng trên VPS terminal: php check_campaign.php 5071

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

$campaign_id = isset($argv[1]) ? intval($argv[1]) : 0;

if ($campaign_id <= 0) {
    echo "❌ Vui lòng nhập Campaign ID. Ví dụ: php check_campaign.php 5071\n";
    exit(1);
}

echo "======================================================\n";
echo "🔍 KIỂM TRA CHỈ SỐ BÀI VIẾT CỦA CAMPAIGN #{$campaign_id}\n";
echo "======================================================\n";

// 1. Tìm thông tin Campaign
try {
    $c_stmt = $pdo->prepare("SELECT * FROM post_campaigns WHERE id = ?");
    $c_stmt->execute([$campaign_id]);
    $campaign = $c_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        echo "❌ Không tìm thấy Campaign #{$campaign_id} trong hệ thống.\n";
        exit(1);
    }
    echo "📋 Tên chiến dịch: {$campaign['name']}\n";
    echo "📅 Ngày tạo:       {$campaign['created_at']}\n\n";
} catch (Exception $e) {
    echo "❌ Lỗi DB: " . $e->getMessage() . "\n";
    exit(1);
}

// Helper clean ID
if (!function_exists('get_clean_fb_id')) {
    function get_clean_fb_id($fb_post_id) {
        $id = trim($fb_post_id);
        if (empty($id)) return '';
        if (strpos($id, '#') !== false) $id = explode('#', $id)[0];
        if (strpos($id, '|') !== false) $id = explode('|', $id)[0];
        if (strpos($id, 'facebook.com/') !== false || strpos($id, 'fb.watch/') !== false) {
            if (preg_match('/(?:posts|videos|reels|story|reel)\/(?:pfbid0)?([a-zA-Z0-9]+)/i', $id, $m)) {
                $id = $m[1];
            }
        }
        return $id;
    }
}

// 2. Lấy tất cả bài viết của Campaign
try {
    $stmt = $pdo->prepare("
        SELECT sp.*, p.name AS page_name, p.access_token 
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id
        WHERE sp.campaign_id = ?
        ORDER BY sp.id ASC
    ");
    $stmt->execute([$campaign_id]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "❌ Lỗi lấy danh sách bài đăng: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($posts)) {
    echo "📭 Campaign #{$campaign_id} không có bài viết nào trong CSDL.\n";
    exit;
}

echo "📊 Tổng số bài trong Campaign: " . count($posts) . " bài.\n";

// Lọc các bài đã đăng (published)
$published_posts = array_filter($posts, function($p) {
    return $p['status'] === 'published';
});

echo "✅ Số bài Đã Đăng (published): " . count($published_posts) . " bài.\n\n";

if (empty($published_posts)) {
    echo "⚠️ Chưa có bài nào ở trạng thái 'published' (Đã đăng) để kiểm tra chỉ số API Facebook.\n";
    exit;
}

// 3. Quét từng bài đăng bằng Facebook Graph API
$idx = 0;
foreach ($published_posts as $post) {
    $idx++;
    $id = $post['id'];
    $page_id = $post['page_id'];
    $page_name = $post['page_name'] ?? "Page #{$page_id}";
    $fb_post_id = $post['fb_post_id'];
    $token = !empty($post['access_token']) ? decryptData($post['access_token']) : '';

    echo "------------------------------------------------------\n";
    echo "📌 [Bài {$idx}/" . count($published_posts) . "] Post #{$id} | Fanpage: {$page_name} (#{$page_id})\n";
    echo "   🔗 FB Post ID trong DB: {$fb_post_id}\n";
    echo "   ⚙️ Mode BL: " . ($post['comment_mode'] ?? 'off') . " | Comment Status: " . ($post['comment_status'] ?? 'NULL') . " | Done: {$post['comment_done']}\n";

    if (empty($token)) {
        echo "   ❌ Không có Access Token hợp lệ cho Page ID {$page_id}. Bỏ qua.\n";
        continue;
    }

    if (empty($fb_post_id)) {
        echo "   ❌ Bài viết thiếu fb_post_id trên Facebook. Bỏ qua.\n";
        continue;
    }

    $clean_id = get_clean_fb_id($fb_post_id);
    $video_id = (strpos($clean_id, '_') !== false) ? explode('_', $clean_id)[1] : $clean_id;
    $target_id = (strpos($clean_id, '_') !== false) ? $clean_id : ($page_id . '_' . $clean_id);

    echo "   📡 Đang gọi Facebook Graph API (Target ID: {$target_id} | Video ID: {$video_id})...\n";

    // 1. Gọi Graph API lấy Reactions, Likes, Comments
    $url = FB_API_BASE . $target_id . '?'
         . http_build_query([
             'fields' => 'reactions.summary(true),likes.summary(true),comments.summary(true)',
             'access_token' => $token
           ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    fb_curl_setssl($ch);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $actual_views = 0;
    $actual_likes = 0;
    $actual_comments = 0;

    if ($http_code === 200) {
        $data = json_decode($res, true);
        $reacts = (int)($data['reactions']['summary']['total_count'] ?? 0);
        $lk = (int)($data['likes']['summary']['total_count'] ?? 0);
        $actual_likes = max($reacts, $lk);
        $actual_comments = (int)($data['comments']['summary']['total_count'] ?? 0);
    } else {
        echo "   ⚠️ Lỗi API Primary HTTP {$http_code} | Res: {$res}\n";
    }

    // 2. Kiểm tra tất cả 4 phương thức lấy View từ Meta Facebook Graph API chuẩn v19.0+
    $test_endpoints = [
        "Direct Video Field (fields=views)" => FB_API_BASE . $video_id . "?fields=views,length&access_token={$token}",
        "Video Insights Field (video_insights metric)" => FB_API_BASE . $video_id . "?fields=video_insights.metric(total_video_views)&access_token={$token}",
        "Post Insights (post_video_views)" => FB_API_BASE . $target_id . "/insights?metric=post_video_views&period=lifetime&access_token={$token}",
        "Page Videos List (page/videos)" => FB_API_BASE . $page_id . "/videos?fields=id,views&limit=100&access_token={$token}"
    ];

    foreach ($test_endpoints as $ep_name => $ep_url) {
        $ch_t = curl_init($ep_url);
        curl_setopt($ch_t, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_t, CURLOPT_TIMEOUT, 10);
        fb_curl_setssl($ch_t);
        $res_t = curl_exec($ch_t);
        $code_t = curl_getinfo($ch_t, CURLINFO_HTTP_CODE);
        curl_close($ch_t);

        echo "   📡 [{$ep_name}] HTTP {$code_t} | Res: {$res_t}\n";

        if ($code_t === 200) {
            $data_t = json_decode($res_t, true);

            // Case 1: Direct field 'views'
            if (isset($data_t['views']) && is_numeric($data_t['views'])) {
                $v_val = (int)$data_t['views'];
                if ($v_val > 0) $actual_views = max($actual_views, $v_val);
            }

            // Case 2: Field 'video_insights'
            if (!empty($data_t['video_insights']['data'])) {
                foreach ($data_t['video_insights']['data'] as $m_item) {
                    if (isset($m_item['values'][0]['value'])) {
                        $v_val = (int)$m_item['values'][0]['value'];
                        if ($v_val > 0) $actual_views = max($actual_views, $v_val);
                    }
                }
            }

            // Case 3: Insights edge array
            if (!empty($data_t['data']) && is_array($data_t['data'])) {
                foreach ($data_t['data'] as $item) {
                    // Page videos list: array of video objects {id, views}
                    if (isset($item['id']) && isset($item['views'])) {
                        if ((string)$item['id'] === (string)$video_id || (string)$item['id'] === (string)$clean_id) {
                            $v_val = (int)$item['views'];
                            if ($v_val > 0) $actual_views = max($actual_views, $v_val);
                        }
                    }
                    // Insights metrics array
                    if (isset($item['values'][0]['value'])) {
                        $v_val = (int)$item['values'][0]['value'];
                        if ($v_val > 0) $actual_views = max($actual_views, $v_val);
                    }
                }
            }
        }
    }

    // Lấy ngưỡng đã cài đặt
    $req_views = (isset($post['comment_threshold_views']) && $post['comment_threshold_views'] !== null && $post['comment_threshold_views'] !== '') ? (int)$post['comment_threshold_views'] : 0;
    $req_likes = (isset($post['comment_threshold_likes']) && $post['comment_threshold_likes'] !== null && $post['comment_threshold_likes'] !== '') ? (int)$post['comment_threshold_likes'] : 0;
    $req_comments = (isset($post['comment_threshold_comments']) && $post['comment_threshold_comments'] !== null && $post['comment_threshold_comments'] !== '') ? (int)$post['comment_threshold_comments'] : 0;

    $views_ok = ($actual_views >= $req_views);
    $likes_ok = ($actual_likes >= $req_likes);
    $comments_ok = ($actual_comments >= $req_comments);

    echo "   📊 CHỈ SỐ THỰC TẾ TRÊN FACEBOOK:\n";
    echo "      - Views:    {$actual_views}  (Yêu cầu: ≥ {$req_views}) " . ($views_ok ? "✅ ĐẠT" : "❌ CHƯA ĐẠT") . "\n";
    echo "      - Likes:    {$actual_likes}  (Yêu cầu: ≥ {$req_likes}) " . ($likes_ok ? "✅ ĐẠT" : "❌ CHƯA ĐẠT") . "\n";
    echo "      - Comments: {$actual_comments}  (Yêu cầu: ≥ {$req_comments}) " . ($comments_ok ? "✅ ĐẠT" : "❌ CHƯA ĐẠT") . "\n";

    if ($post['comment_done'] == 1 || $post['comment_status'] === 'done') {
        echo "   🎉 ĐÁNH GIÁ: BÀI NÀY ĐÃ ĐẠT ĐIỀU KIỆN VÀ ĐÃ BÌNH LUẬN TRƯỚC ĐÓ (comment_done = 1).\n";
    } elseif ($views_ok && $likes_ok && $comments_ok) {
        echo "   🚀 ĐÁNH GIÁ: BÀI NÀY ĐẠT ĐỦ ĐIỀU KIỆN ĐỂ KÍCH HOẠT BÌNH LUẬN NGAY! (Views {$actual_views}/{$req_views}, Likes {$actual_likes}/{$req_likes})\n";
    } else {
        echo "   ⏳ ĐÁNH GIÁ: BÀI NÀY CHƯA ĐỦ ĐIỀU KIỆN (Cần thêm chỉ số để đạt mốc cài đặt).\n";
    }
}

echo "======================================================\n";
echo "🏁 Hoàn tất kiểm tra Campaign #{$campaign_id}.\n";
echo "======================================================\n";
