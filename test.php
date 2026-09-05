<?php
/**
 * Test file: Quét 10 bài viết từ page TatDiepBeautySalonQ3 và kiểm tra link Video MP4
 * URL: https://fbweb.hongdolab.com/test.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

@set_time_limit(60);
@ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/facebook_scraper.php';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Kiem tra Quet Video TatDiepBeautySalonQ3</title>";
echo "<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; line-height: 1.5; }
    h2 { color: #38bdf8; }
    .card { background: #1e293b; border-radius: 10px; padding: 15px; margin-bottom: 20px; border: 1px solid #334155; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { border: 1px solid #334155; padding: 10px; text-align: left; vertical-align: top; font-size: 14px; }
    th { background: #0f172a; color: #94a3b8; }
    tr:nth-child(even) { background: #162032; }
    .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
    .badge-video { background: #818cf8; color: #0f172a; }
    .badge-photo { background: #34d399; color: #0f172a; }
    .badge-text { background: #94a3b8; color: #0f172a; }
    .success { color: #4ade80; font-weight: bold; word-break: break-all; }
    .error { color: #f87171; font-weight: bold; }
    img { max-width: 100px; border-radius: 6px; }
    a { color: #38bdf8; text-decoration: none; }
    a:hover { text-decoration: underline; }
</style></head><body>";

echo "<h2>📹 Kiểm tra quét 10 bài viết từ Page: TatDiepBeautySalonQ3</h2>";

$target_page = "TatDiepBeautySalonQ3";
$limit = 10;

// 1. Lấy Token người dùng tương tự facebook_scraper.php
$account_id = $_SESSION['account_id'] ?? 1;
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$user_token = '';
$user_name = '';

try {
    $stmt = $pdo->query("SELECT id, name, access_token FROM users WHERE access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 10");
    $raw_users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($raw_users as $u) {
        $dec = decryptData($u['access_token']);
        if (!empty($dec) && strlen($dec) > 10) {
            $user_token = $dec;
            $user_name = $u['name'] ?: ("User #" . $u['id']);
            break;
        }
    }
} catch (Exception $e) {}

if (!$user_token) {
    echo "<div class='card error'>❌ Không tìm thấy User Token hợp lệ nào trên server DB. Vui lòng kiểm tra lại bảng users.</div></body></html>";
    exit;
}

echo "<div class='card'>";
echo "<p>🔑 <b>User Token đang dùng:</b> " . htmlspecialchars($user_name) . "</p>";
echo "<p>🌐 <b>Page Quét:</b> " . htmlspecialchars($target_page) . " (Số lượng: {$limit} bài)</p>";
echo "</div>";

// 2. Gọi Graph API quét bài từ TatDiepBeautySalonQ3
$fields = 'id,object_id,message,created_time,full_picture,attachments{media,media_type,subattachments,target,type,url},shares,comments.summary(total_count),reactions.summary(total_count)';

$res = fb_api_request("{$target_page}/posts", [
    'access_token' => $user_token,
    'fields' => $fields,
    'limit' => $limit
]);

if ($res['status_code'] !== 200) {
    $errMsg = $res['data']['error']['message'] ?? 'Lỗi kết nối Facebook API.';
    echo "<div class='card error'>❌ Lỗi API Facebook (HTTP {$res['status_code']}): " . htmlspecialchars($errMsg) . "</div></body></html>";
    exit;
}

$postsData = $res['data']['data'] ?? [];

if (empty($postsData)) {
    echo "<div class='card error'>⚠️ Không tìm thấy bài viết nào từ Page {$target_page}.</div></body></html>";
    exit;
}

echo "<table>";
echo "<thead><tr>
    <th style='width: 40px;'>STT</th>
    <th style='width: 140px;'>Post ID</th>
    <th style='width: 90px;'>Loại Bài</th>
    <th style='width: 120px;'>Thumbnail</th>
    <th>Nội Dung Text</th>
    <th>Kết Quả Media (Ảnh / Link Video MP4)</th>
</tr></thead><tbody>";

$stt = 0;
foreach ($postsData as $post) {
    $stt++;
    $fbid = $post['id'] ?? '';
    $msg = $post['message'] ?? '';
    $pic = $post['full_picture'] ?? '';
    $created_at = $post['created_time'] ? date('d/m/Y H:i', strtotime($post['created_time'])) : '';

    // Bóc tách Media sử dụng hàm nâng cấp từ facebook_scraper.php
    $media = extract_post_media_urls($post, $user_token);

    $images = $media['images'];
    $videos = $media['videos'];
    $v_url = $videos[0] ?? '';

    if ($media['is_video'] && !$v_url) {
        $v_url = get_video_source_from_post($user_token, $post);
        if ($v_url) {
            $videos = [$v_url];
        }
    }

    $post_type = $media['post_type'];
    $badge_class = ($post_type === 'video') ? 'badge-video' : (($post_type === 'photo') ? 'badge-photo' : 'badge-text');

    echo "<tr>";
    echo "<td>{$stt}</td>";
    echo "<td><a href='https://facebook.com/{$fbid}' target='_blank'>{$fbid}</a><br><small style='color:#64748b;'>{$created_at}</small></td>";
    echo "<td><span class='badge {$badge_class}'>" . strtoupper($post_type) . "</span></td>";
    echo "<td>" . ($pic ? "<img src='" . htmlspecialchars($pic) . "'>" : "<i>Không có</i>") . "</td>";
    echo "<td>" . nl2br(htmlspecialchars(mb_substr($msg, 0, 150))) . (mb_strlen($msg) > 150 ? '...' : '') . "</td>";

    echo "<td>";
    if ($post_type === 'video') {
        if ($v_url) {
            echo "<p class='success'>✅ CÓ LINK VIDEO MP4:</p>";
            echo "<p><a href='" . htmlspecialchars($v_url) . "' target='_blank' style='word-break: break-all;'>" . htmlspecialchars($v_url) . "</a></p>";
            echo "<video controls width='280' style='border-radius:6px; margin-top:5px;'><source src='" . htmlspecialchars($v_url) . "' type='video/mp4'></video>";
        } else {
            echo "<p class='error'>❌ KHÔNG LẤY ĐƯỢC LINK VIDEO URL (Chỉ có thumbnail)</p>";
        }
    } elseif ($post_type === 'photo') {
        echo "<p class='success'>📷 Tìm thấy " . count($images) . " ảnh:</p>";
        foreach ($images as $img_url) {
            echo "<a href='" . htmlspecialchars($img_url) . "' target='_blank'><img src='" . htmlspecialchars($img_url) . "' style='margin-right:5px; max-width:60px;'></a>";
        }
    } else {
        echo "<i>Bài viết dạng Text thuần</i>";
    }
    echo "</td>";

    echo "</tr>";
}

echo "</tbody></table></body></html>";
