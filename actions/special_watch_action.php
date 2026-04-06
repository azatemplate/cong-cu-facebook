<?php
// actions/special_watch_action.php
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Output buffering: bắt die() từ db.php, trả JSON thay vì HTML ──────────
ob_start();
require_once __DIR__ . '/../includes/db.php';
$_db_out = ob_get_clean();

header('Content-Type: application/json');

// Nếu db.php gọi die() → $pdo chưa được tạo → trả JSON lỗi
if (!isset($pdo)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Lỗi kết nối CSDL. Vui lòng liên hệ Admin.',
    ]);
    exit;
}

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Chưa đăng nhập.']);
    exit;
}

$account_id = (int)$_SESSION['account_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Helper: extract FB page identifier from URL ──────────────────────────────
function extract_fb_identifier(string $url): string {
    $url = trim($url);
    // Remove trailing slash
    $url = rtrim($url, '/');
    // Remove query strings
    $url = preg_replace('/\?.*/', '', $url);
    // Try to extract path
    if (preg_match('#facebook\.com/([^/?#]+)/?$#i', $url, $m)) {
        return $m[1];
    }
    return '';
}

// ── Helper: fetch page info by scraping OG tags ──────────────────────────────
function fetch_page_meta(string $url): array {
    $info = ['name' => '', 'avatar' => ''];

    // Extract identifier from URL
    $identifier = extract_fb_identifier($url);
    if (!$identifier) {
        // Use URL as fallback name
        $info['name'] = parse_url($url, PHP_URL_PATH) ?? $url;
        return $info;
    }

    // Try to get info from Facebook Open Graph
    $og_url = 'https://www.facebook.com/' . $identifier;
    $ch = curl_init($og_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        CURLOPT_HTTPHEADER     => ['Accept-Language: vi-VN,vi;q=0.9'],
    ]);
    $html = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || !$html) {
        $info['name'] = $identifier;
        return $info;
    }

    // Extract og:title
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\'](.*?)["\']/i', $html, $m)) {
        $info['name'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Clean " | Facebook" suffix
        $title = preg_replace('/\s*[\|\-|]\s*Facebook.*$/i', '', $title);
        $info['name'] = trim($title);
    }

    // Extract og:image
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/i', $html, $m)) {
        $info['avatar'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Fallback name
    if (empty($info['name'])) {
        $info['name'] = $identifier;
    }

    return $info;
}

// ── Helper: generate demo posts for a target (placeholder until real FB API) ──
function generate_demo_posts(int $target_id, int $limit, string $page_name): array {
    $posts = [];
    $sample_contents = [
        '3 công cụ free làm video triệu view không cần lộ mặt 🔥',
        '101GB tài nguyên làm hiệu ứng video siêu đầy đủ 🎬',
        '3 trang web hay ho mà dân mmo phải biết 💡',
        'Bí quyết tăng reach Facebook không cần chạy quảng cáo 📈',
        'Tool auto comment Facebook miễn phí 2024 ⚡',
        'Cách kiếm 5 triệu/tháng từ affiliate marketing không cần vốn 💰',
        'Share bộ preset Lightroom cho ảnh đẹp lung linh ✨',
        'Top 10 kênh YouTube học marketing online hay nhất 📺',
        'Hướng dẫn chạy quảng cáo TikTok từ A-Z cho người mới 🎯',
        'Tổng hợp 50 mẫu caption Facebook viral nhất tuần này 📝',
    ];
    $images = [
        'https://via.placeholder.com/400x300/1a1a2e/ffffff?text=Post+1',
        'https://via.placeholder.com/400x300/16213e/ffffff?text=Post+2',
        'https://via.placeholder.com/400x300/0f3460/ffffff?text=Post+3',
        'https://via.placeholder.com/400x300/533483/ffffff?text=Post+4',
        '',
    ];

    for ($i = 0; $i < $limit; $i++) {
        $content_idx = $i % count($sample_contents);
        $image_idx   = $i % count($images);
        $days_ago    = rand(0, 30);
        $posts[] = [
            'target_id' => $target_id,
            'post_fb_id'=> 'demo_' . $target_id . '_' . ($i + 1),
            'content'   => $page_name . ': ' . $sample_contents[$content_idx],
            'image_url' => $images[$image_idx],
            'likes'     => rand(10, 5000),
            'comments'  => rand(0, 500),
            'shares'    => rand(0, 200),
            'post_time' => date('Y-m-d H:i:s', strtotime("-{$days_ago} days -" . rand(0, 23) . " hours")),
            'post_url'  => 'https://www.facebook.com/demo_post_' . ($i + 1),
        ];
    }
    return $posts;
}

// ╔══════════════════════════════════════════════════════════════════╗
// ║  ACTION: add — thêm trang mới để theo dõi                       ║
// ╚══════════════════════════════════════════════════════════════════╝
if ($action === 'add') {
    $page_url   = trim($_POST['page_url'] ?? '');
    $label      = trim($_POST['label'] ?? '');
    $post_limit = max(1, min(200, (int)($_POST['post_limit'] ?? 20)));
    $auto_refresh = isset($_POST['auto_refresh']) ? 1 : 0;

    if (empty($page_url)) {
        echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập đường link trang.']);
        exit;
    }

    // Basic URL validation (accept facebook.com URLs or standard URLs)
    if (!preg_match('#facebook\.com#i', $page_url) && !filter_var($page_url, FILTER_VALIDATE_URL)) {
        echo json_encode(['status' => 'error', 'message' => 'Đường link không hợp lệ. Vui lòng nhập URL Facebook.']);
        exit;
    }

    try {
        // Fetch page meta
        $meta = fetch_page_meta($page_url);
        $page_name   = $meta['name'] ?: extract_fb_identifier($page_url) ?: 'Trang không xác định';
        $page_avatar = $meta['avatar'] ?: '';

        // Insert target
        $stmt = $pdo->prepare("
            INSERT INTO special_watch_targets
                (account_id, page_url, page_name, page_avatar, label, post_limit, auto_refresh, post_count, last_scanned_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL)
        ");
        $stmt->execute([$account_id, $page_url, $page_name, $page_avatar, $label, $post_limit, $auto_refresh]);
        $target_id = (int)$pdo->lastInsertId();

        // Auto-scan: generate demo posts
        $posts = generate_demo_posts($target_id, $post_limit, $page_name);
        $ins   = $pdo->prepare("
            INSERT INTO special_watch_posts (target_id, post_fb_id, content, image_url, likes, comments, shares, post_time, post_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($posts as $p) {
            $ins->execute([
                $p['target_id'], $p['post_fb_id'], $p['content'], $p['image_url'],
                $p['likes'], $p['comments'], $p['shares'], $p['post_time'], $p['post_url'],
            ]);
        }

        // Update counts
        $pdo->prepare("UPDATE special_watch_targets SET post_count = ?, last_scanned_at = NOW() WHERE id = ?")
            ->execute([count($posts), $target_id]);

        echo json_encode([
            'status'    => 'success',
            'message'   => 'Đã thêm trang và quét ' . count($posts) . ' bài viết.',
            'target_id' => $target_id,
            'page_name' => $page_name,
            'avatar'    => $page_avatar,
            'post_count'=> count($posts),
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi CSDL: ' . $e->getMessage()]);
    }
    exit;
}

// ╔══════════════════════════════════════════════════════════════════╗
// ║  ACTION: delete — xóa trang theo dõi                            ║
// ╚══════════════════════════════════════════════════════════════════╝
if ($action === 'delete') {
    $target_id = (int)($_POST['target_id'] ?? 0);
    if (!$target_id) {
        echo json_encode(['status' => 'error', 'message' => 'ID không hợp lệ.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM special_watch_targets WHERE id = ? AND account_id = ?");
    $stmt->execute([$target_id, $account_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Đã xóa trang theo dõi.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy hoặc không có quyền xóa.']);
    }
    exit;
}

// ╔══════════════════════════════════════════════════════════════════╗
// ║  ACTION: scan — quét lại bài viết thủ công                      ║
// ╚══════════════════════════════════════════════════════════════════╝
if ($action === 'scan') {
    $target_id = (int)($_POST['target_id'] ?? 0);
    if (!$target_id) {
        echo json_encode(['status' => 'error', 'message' => 'ID không hợp lệ.']);
        exit;
    }

    // Verify ownership
    $target = $pdo->prepare("SELECT * FROM special_watch_targets WHERE id = ? AND account_id = ?");
    $target->execute([$target_id, $account_id]);
    $row = $target->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy trang theo dõi.']);
        exit;
    }

    // Delete old posts
    $pdo->prepare("DELETE FROM special_watch_posts WHERE target_id = ?")->execute([$target_id]);

    // Generate fresh posts
    $posts = generate_demo_posts($target_id, (int)$row['post_limit'], $row['page_name']);
    $ins   = $pdo->prepare("
        INSERT INTO special_watch_posts (target_id, post_fb_id, content, image_url, likes, comments, shares, post_time, post_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($posts as $p) {
        $ins->execute([
            $p['target_id'], $p['post_fb_id'], $p['content'], $p['image_url'],
            $p['likes'], $p['comments'], $p['shares'], $p['post_time'], $p['post_url'],
        ]);
    }

    $pdo->prepare("UPDATE special_watch_targets SET post_count = ?, last_scanned_at = NOW() WHERE id = ?")
        ->execute([count($posts), $target_id]);

    echo json_encode([
        'status'     => 'success',
        'message'    => 'Đã cập nhật ' . count($posts) . ' bài viết mới nhất.',
        'post_count' => count($posts),
        'scanned_at' => date('d/m/Y H:i'),
    ]);
    exit;
}

// ╔══════════════════════════════════════════════════════════════════╗
// ║  ACTION: get_posts — lấy bài viết đã quét                        ║
// ╚══════════════════════════════════════════════════════════════════╝
if ($action === 'get_posts') {
    $target_id = (int)($_GET['target_id'] ?? $_POST['target_id'] ?? 0);
    if (!$target_id) {
        echo json_encode(['status' => 'error', 'message' => 'ID không hợp lệ.']);
        exit;
    }

    // Verify ownership
    $target_stmt = $pdo->prepare("SELECT * FROM special_watch_targets WHERE id = ? AND account_id = ?");
    $target_stmt->execute([$target_id, $account_id]);
    $target_row = $target_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target_row) {
        echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy trang theo dõi.']);
        exit;
    }

    $posts_stmt = $pdo->prepare("
        SELECT * FROM special_watch_posts WHERE target_id = ? ORDER BY post_time DESC
    ");
    $posts_stmt->execute([$target_id]);
    $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'      => 'success',
        'target'      => [
            'id'        => $target_row['id'],
            'page_name' => $target_row['page_name'],
            'avatar'    => $target_row['page_avatar'],
            'label'     => $target_row['label'],
        ],
        'posts'       => $posts,
        'total'       => count($posts),
    ]);
    exit;
}

// ╔══════════════════════════════════════════════════════════════════╗
// ║  ACTION: get_labels — lấy danh sách nhãn đã tạo                 ║
// ╚══════════════════════════════════════════════════════════════════╝
if ($action === 'get_labels') {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT label FROM special_watch_targets
            WHERE account_id = ? AND label != '' AND label IS NOT NULL
            ORDER BY label ASC
        ");
        $stmt->execute([$account_id]);
        $labels = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'label');
    } catch (Exception $e) {
        $labels = [];
    }

    // Add defaults if empty
    if (empty($labels)) {
        $labels = ['Marketing', 'Competitor', 'Inspiration', 'News'];
    }

    echo json_encode(['status' => 'success', 'labels' => $labels]);
    exit;
}

// ╔══════════════════════════════════════════════════════════════════╗
// ║  ACTION: list — lấy danh sách trang theo dõi                    ║
// ╚══════════════════════════════════════════════════════════════════╝
if ($action === 'list') {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM special_watch_targets WHERE account_id = ? ORDER BY created_at DESC
        ");
        $stmt->execute([$account_id]);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'targets' => $targets]);
    } catch (Exception $e) {
        // Bảng chưa được tạo — trả về danh sách rỗng
        echo json_encode(['status' => 'success', 'targets' => []]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Action không hợp lệ.']);
exit;
