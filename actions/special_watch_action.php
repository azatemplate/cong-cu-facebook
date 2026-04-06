<?php
// actions/special_watch_action.php
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Output buffering: bắt die() từ db.php, trả JSON thay vì HTML ──────────
ob_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
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
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

// ════════════════════════════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════════════════════════════

/**
 * Lấy page access_token từ bảng pages.
 * fb_page_db_id là id (primary key) trong bảng pages được chọn.
 * Nếu không chỉ định, lấy page mới nhất của account.
 */
function get_account_token(PDO $pdo, int $account_id, int $fb_page_db_id = 0): ?string {
    if ($fb_page_db_id > 0) {
        $stmt = $pdo->prepare("
            SELECT p.access_token FROM pages p
            JOIN users u ON u.id = p.user_id
            WHERE p.id = ? AND u.account_id = ? LIMIT 1
        ");
        $stmt->execute([$fb_page_db_id, $account_id]);
    } else {
        // Fallback: page mới nhất của account
        $stmt = $pdo->prepare("
            SELECT p.access_token FROM pages p
            JOIN users u ON u.id = p.user_id
            WHERE u.account_id = ? AND p.access_token IS NOT NULL
            ORDER BY p.id DESC LIMIT 1
        ");
        $stmt->execute([$account_id]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['access_token'])) {
        return decryptData($row['access_token']);
    }
    return null;
}

/**
 * Resolve FB slug/username → numeric page ID qua Graph API.
 * Cần thiết vì API cần numeric ID cho một số endpoint.
 */
function resolve_fb_numeric_id(string $identifier, string $access_token): string {
    // Nếu đã là số thì trả ngước luôn
    if (ctype_digit($identifier)) return $identifier;

    $url = "https://graph.facebook.com/v24.0/" . urlencode($identifier) . "?fields=id&access_token=" . urlencode($access_token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'development') ? false : true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw && $code === 200) {
        $d = json_decode($raw, true);
        if (!empty($d['id'])) return $d['id'];
    }
    // Trả về slug gốc nếu không resolve được
    return $identifier;
}

/**
 * Phân tích URL Facebook → trả về ['id_type' => 'page'|'group', 'identifier' => '...']
 * id_type: 'page' cho fanpage/profile, 'group' cho nhóm
 */
function parse_fb_url(string $url): array {
    $url = rtrim(trim($url), '/');
    $url = preg_replace('/\?.*/', '', $url);

    // Groups — dùng ~ làm delimiter để tránh xung đột với # trong character class
    if (preg_match('~facebook\.com/groups/([^/?#]+)~i', $url, $m)) {
        return ['id_type' => 'group', 'identifier' => $m[1]];
    }
    // Profile / Page
    if (preg_match('~facebook\.com/(?:pages/[^/]+/|profile\.php\?id=)?([^/?#]+)~i', $url, $m)) {
        $id = $m[1];
        if (!in_array(strtolower($id), ['watch', 'marketplace', 'gaming', 'live', 'stories'])) {
            return ['id_type' => 'page', 'identifier' => $id];
        }
    }
    return ['id_type' => 'page', 'identifier' => ''];
}

/**
 * Lấy danh sách post IDs từ Facebook Graph API
 * Dùng /feed cho mọi loại (page, group) vì /posts chỉ trả bài của trang mình.
 */
function fetch_post_ids(string $fb_id, string $id_type, int $post_count, string $access_token): array {
    $all_ids = [];
    // /feed cho cả page lẫn group — trả bài public
    $base_url = "https://graph.facebook.com/v24.0/{$fb_id}/feed";

    $params = [
        'fields'       => 'id',
        'access_token' => $access_token,
        'limit'        => min(100, $post_count + 10),
    ];
    $next_url = $base_url . '?' . http_build_query($params);
    $fetched  = 0;
    $target   = $post_count + 10;

    while ($fetched < $target && $next_url) {
        $ch = curl_init($next_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'development') ? false : true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $code !== 200) break;
        $data = json_decode($raw, true);
        if (empty($data['data'])) break;

        foreach ($data['data'] as $post) {
            if ($fetched >= $target) break;
            $all_ids[] = $post['id'];
            $fetched++;
        }

        $next_url = $data['paging']['next'] ?? null;
    }

    return array_slice($all_ids, 0, $post_count);
}

/**
 * Lấy chi tiết bài viết theo ID: nội dung, ảnh, likes, comments, shares
 */
function fetch_post_detail(string $post_id, string $access_token): ?array {
    $fields = 'id,message,story,created_time,permalink_url,'
            . 'full_picture,'
            . 'likes.summary(true).limit(0),'
            . 'comments.summary(true).limit(0),'
            . 'shares';

    $url = "https://graph.facebook.com/v24.0/{$post_id}?" . http_build_query([
        'fields'       => $fields,
        'access_token' => $access_token,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'development') ? false : true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) return null;
    $d = json_decode($raw, true);
    if (empty($d['id'])) return null;

    $content  = $d['message'] ?? $d['story'] ?? '';
    $image    = $d['full_picture'] ?? '';
    $likes    = $d['likes']['summary']['total_count']    ?? 0;
    $comments = $d['comments']['summary']['total_count'] ?? 0;
    $shares   = $d['shares']['count']                   ?? 0;
    $post_url = $d['permalink_url'] ?? "https://www.facebook.com/{$post_id}";
    $post_time= isset($d['created_time'])
        ? date('Y-m-d H:i:s', strtotime($d['created_time']))
        : date('Y-m-d H:i:s');

    return [
        'post_fb_id' => $post_id,
        'content'    => $content,
        'image_url'  => $image,
        'likes'      => (int)$likes,
        'comments'   => (int)$comments,
        'shares'     => (int)$shares,
        'post_time'  => $post_time,
        'post_url'   => $post_url,
    ];
}

/**
 * Lấy thông tin meta page (tên, avatar) qua OG scraping
 */
function fetch_page_meta(string $url): array {
    $info = ['name' => '', 'avatar' => ''];
    $parsed = parse_fb_url($url);
    $identifier = $parsed['identifier'];
    if (!$identifier) {
        $info['name'] = parse_url($url, PHP_URL_PATH) ?? $url;
        return $info;
    }

    $og_url = ($parsed['id_type'] === 'group')
        ? "https://www.facebook.com/groups/{$identifier}"
        : "https://www.facebook.com/{$identifier}";

    $ch = curl_init($og_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        CURLOPT_HTTPHEADER     => ['Accept-Language: vi-VN,vi;q=0.9'],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if ($html) {
        if (preg_match('/property=["\']og:title["\'][^>]+content=["\']([^"\']*)["\']|content=["\']([^"\']*)["\'][^>]+property=["\']og:title["\']/i', $html, $m)) {
            $info['name'] = html_entity_decode($m[1] ?: $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $t = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $info['name'] = trim(preg_replace('/\s*[\|\-]\s*Facebook.*$/i', '', $t));
        }
        if (preg_match('/property=["\']og:image["\'][^>]+content=["\']([^"\']*)["\']|content=["\']([^"\']*)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
            $info['avatar'] = html_entity_decode($m[1] ?: $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    if (empty($info['name'])) $info['name'] = $identifier;
    return $info;
}

// ════════════════════════════════════════════════════════════════════════════
//  ACTION: list
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'list') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM special_watch_targets WHERE account_id = ? ORDER BY created_at DESC");
        $stmt->execute([$account_id]);
        echo json_encode(['status' => 'success', 'targets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'success', 'targets' => []]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//  ACTION: get_labels
// ════════════════════════════════════════════════════════════════════════════
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
    if (empty($labels)) {
        $labels = ['Marketing', 'Competitor', 'Inspiration', 'News'];
    }
    echo json_encode(['status' => 'success', 'labels' => $labels]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//  ACTION: get_users — trả các PAGES có token để dùng quét bài
// ════════════════════════════════════════════════════════════════════════════
// Giữ tên action là get_users để không đổi JS, nhưng bây giờ trả pages (page token mạnh hơn user token)
if ($action === 'get_users') {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.page_name AS name, p.page_id AS fb_id
            FROM pages p
            JOIN users u ON u.id = p.user_id
            WHERE u.account_id = ? AND p.access_token IS NOT NULL AND p.access_token != ''
            ORDER BY p.page_name ASC
        ");
        $stmt->execute([$account_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'users' => $users]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'users' => [], 'message' => $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//  ACTION: add — thêm trang mới và quét bài viết thật từ Graph API
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'add') {
    $page_url     = trim($_POST['page_url']    ?? '');
    $label        = trim($_POST['label']       ?? '');
    $post_limit   = max(1, min(200, (int)($_POST['post_limit'] ?? 20)));
    $auto_refresh = isset($_POST['auto_refresh']) ? 1 : 0;
    // fb_user_id = id (PK) của bảng pages (page token)
    $fb_page_db_id = (int)($_POST['fb_user_id'] ?? 0); // giữ tên POST key để không đổi JS

    if (empty($page_url)) {
        echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập đường link trang.']);
        exit;
    }
    if (!preg_match('~facebook\.com~i', $page_url) && !filter_var($page_url, FILTER_VALIDATE_URL)) {
        echo json_encode(['status' => 'error', 'message' => 'Đường link không hợp lệ. Vui lòng nhập URL Facebook.']);
        exit;
    }

    // Lấy page access_token theo page được chọn
    $access_token = get_account_token($pdo, $account_id, $fb_page_db_id);
    if (!$access_token) {
        echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy access token. Vui lòng kết nối Facebook trước.']);
        exit;
    }

    // Meta trang (tên + avatar)
    $meta        = fetch_page_meta($page_url);
    $parsed      = parse_fb_url($page_url);
    $identifier  = $parsed['identifier'];
    $id_type     = $parsed['id_type'];
    // Resolve slug → numeric ID (Graph API yêu cầu numeric ID cho /feed)
    $fb_id       = resolve_fb_numeric_id($identifier, $access_token);
    $page_name   = !empty($meta['name'])   ? $meta['name']   : $identifier;
    $page_avatar = !empty($meta['avatar']) ? $meta['avatar'] : '';

    try {
        // Lưu target + fb_page_db_id để scan sau dùng đúng token
        $stmt = $pdo->prepare("
            INSERT INTO special_watch_targets
                (account_id, fb_user_id, page_url, page_name, page_avatar, label, post_limit, auto_refresh, post_count, last_scanned_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)
        ");
        $stmt->execute([$account_id, $fb_page_db_id ?: null, $page_url, $page_name, $page_avatar, $label, $post_limit, $auto_refresh]);
        $target_id = (int)$pdo->lastInsertId();

        // Lấy post IDs từ Graph API
        $post_ids = fetch_post_ids($fb_id, $id_type, $post_limit, $access_token);
        $saved    = 0;

        if (!empty($post_ids)) {
            $ins = $pdo->prepare("
                INSERT IGNORE INTO special_watch_posts
                    (target_id, post_fb_id, content, image_url, likes, comments, shares, post_time, post_url)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($post_ids as $pid) {
                $detail = fetch_post_detail($pid, $access_token);
                if (!$detail) continue;
                $ins->execute([
                    $target_id,
                    $detail['post_fb_id'],
                    $detail['content'],
                    $detail['image_url'],
                    $detail['likes'],
                    $detail['comments'],
                    $detail['shares'],
                    $detail['post_time'],
                    $detail['post_url'],
                ]);
                $saved++;
            }
        }

        // Cập nhật số lượng
        $pdo->prepare("UPDATE special_watch_targets SET post_count = ?, last_scanned_at = NOW() WHERE id = ?")
            ->execute([$saved, $target_id]);

        $msg = $saved > 0
            ? "Đã thêm trang và quét được {$saved} bài viết."
            : "Đã thêm trang. Không lấy được bài viết (token thiếu quyền hoặc trang riêng tư).";

        echo json_encode([
            'status'     => 'success',
            'message'    => $msg,
            'target_id'  => $target_id,
            'page_name'  => $page_name,
            'avatar'     => $page_avatar,
            'post_count' => $saved,
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//  ACTION: scan — quét lại bài viết thủ công từ Graph API
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'scan') {
    $target_id = (int)($_POST['target_id'] ?? 0);
    if (!$target_id) {
        echo json_encode(['status' => 'error', 'message' => 'ID không hợp lệ.']);
        exit;
    }

    try {
        $target = $pdo->prepare("SELECT * FROM special_watch_targets WHERE id = ? AND account_id = ?");
        $target->execute([$target_id, $account_id]);
        $row = $target->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy trang theo dõi.']);
            exit;
        }

        $parsed   = parse_fb_url($row['page_url']);
        $id_type  = $parsed['id_type'];
        // Dùng đúng page token đã lưu lúc thêm
        $saved_page_id = (int)($row['fb_user_id'] ?? 0);
        $access_token = get_account_token($pdo, $account_id, $saved_page_id);
        if (!$access_token) {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy access token.']);
            exit;
        }
        // Resolve slug → numeric ID
        $fb_id = resolve_fb_numeric_id($parsed['identifier'], $access_token);

        // Xóa bài cũ
        $pdo->prepare("DELETE FROM special_watch_posts WHERE target_id = ?")->execute([$target_id]);

        // Lấy bài mới
        $post_ids = fetch_post_ids($fb_id, $id_type, (int)$row['post_limit'], $access_token);
        $saved    = 0;

        if (!empty($post_ids)) {
            $ins = $pdo->prepare("
                INSERT IGNORE INTO special_watch_posts
                    (target_id, post_fb_id, content, image_url, likes, comments, shares, post_time, post_url)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($post_ids as $pid) {
                $detail = fetch_post_detail($pid, $access_token);
                if (!$detail) continue;
                $ins->execute([
                    $target_id,
                    $detail['post_fb_id'],
                    $detail['content'],
                    $detail['image_url'],
                    $detail['likes'],
                    $detail['comments'],
                    $detail['shares'],
                    $detail['post_time'],
                    $detail['post_url'],
                ]);
                $saved++;
            }
        }

        $pdo->prepare("UPDATE special_watch_targets SET post_count = ?, last_scanned_at = NOW() WHERE id = ?")
            ->execute([$saved, $target_id]);

        echo json_encode([
            'status'     => 'success',
            'message'    => "Đã cập nhật {$saved} bài viết mới nhất.",
            'post_count' => $saved,
            'scanned_at' => date('d/m/Y H:i'),
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//  ACTION: get_posts — lấy bài viết đã lưu
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'get_posts') {
    $target_id = (int)($_GET['target_id'] ?? $_POST['target_id'] ?? 0);
    if (!$target_id) {
        echo json_encode(['status' => 'error', 'message' => 'ID không hợp lệ.']);
        exit;
    }

    try {
        $t_stmt = $pdo->prepare("SELECT * FROM special_watch_targets WHERE id = ? AND account_id = ?");
        $t_stmt->execute([$target_id, $account_id]);
        $target_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target_row) {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy trang theo dõi.']);
            exit;
        }

        $p_stmt = $pdo->prepare("SELECT * FROM special_watch_posts WHERE target_id = ? ORDER BY post_time DESC");
        $p_stmt->execute([$target_id]);
        $posts = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'target' => [
                'id'        => $target_row['id'],
                'page_name' => $target_row['page_name'],
                'avatar'    => $target_row['page_avatar'],
                'label'     => $target_row['label'],
            ],
            'posts' => $posts,
            'total' => count($posts),
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//  ACTION: delete
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'delete') {
    $target_id = (int)($_POST['target_id'] ?? 0);
    if (!$target_id) {
        echo json_encode(['status' => 'error', 'message' => 'ID không hợp lệ.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM special_watch_targets WHERE id = ? AND account_id = ?");
        $stmt->execute([$target_id, $account_id]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Đã xóa trang theo dõi.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy hoặc không có quyền xóa.']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Action không hợp lệ.']);
exit;
