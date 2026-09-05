<?php
/**
 * 100% Exact Scraper Implementation of repost_other_pages.js in PHP
 * 
 * Target Page: TatDiepBeautySalonQ3 (or via ?page=...)
 * Usage: Upload to server and access via web browser or CLI:
 *        http://your-domain.com/scraper_test.php?page=TatDiepBeautySalonQ3
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

@set_time_limit(30);
@ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/security.php';

$target_page = isset($_GET['page']) && !empty($_GET['page']) ? trim($_GET['page']) : 'TatDiepBeautySalonQ3';
$limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 10;

// Helper to extract clean Page ID from input or URL (100% matching cleanPageId in repost_other_pages.js)
function clean_page_id($str) {
    if (!$str) return '';
    $str = trim($str);
    if (preg_match('/(?:profile\.php\?id=|facebook\.com\/)(\d+)/i', $str, $m)) {
        return $m[1];
    }
    $str = preg_replace('/https?:\/\/[^\/]+\//i', '', $str);
    return rtrim($str, '/');
}

$target_page_clean = clean_page_id($target_page);

// 1. Get Tokens queue (User Tokens & Page Tokens from DB)
$account_id = $_SESSION['account_id'] ?? 1;
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$server_users = [];
try {
    if ($is_admin) {
        $stmt = $pdo->query("SELECT id, name, fb_id, access_token FROM users WHERE access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 10");
        $raw_users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.name, u.fb_id, u.access_token 
            FROM users u 
            LEFT JOIN pages p ON u.id = p.user_id 
            LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
            WHERE (u.account_id = :aid OR ps.shared_with_account_id = :aid2)
              AND u.access_token IS NOT NULL AND u.access_token != ''
            ORDER BY u.id DESC LIMIT 10
        ");
        $stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
        $stmt->bindValue(':aid2', $account_id, PDO::PARAM_INT);
        $stmt->execute();
        $raw_users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    foreach ($raw_users as $u) {
        $dec = decryptData($u['access_token']);
        if (!empty($dec) && strlen($dec) > 10) {
            $server_users[] = [
                'id' => $u['id'],
                'name' => $u['name'] ?: ('User #' . $u['id']),
                'token' => $dec
            ];
        }
    }
} catch (Exception $e) {}

if (empty($server_users)) {
    try {
        $stmt = $pdo->query("SELECT id, name, fb_id, access_token FROM users WHERE access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 10");
        $raw_users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($raw_users as $u) {
            $dec = decryptData($u['access_token']);
            if (!empty($dec) && strlen($dec) > 10) {
                $server_users[] = [
                    'id' => $u['id'],
                    'name' => $u['name'] ?: ('User #' . $u['id']),
                    'token' => $dec
                ];
            }
        }
    } catch (Exception $e2) {}
}

$selected_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$tokens_to_test = [];

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $tokens_to_test[] = [
        'id' => 0,
        'user_name' => 'Custom Token (URL)',
        'token' => trim($_GET['token'])
    ];
} elseif ($selected_user_id > 0) {
    foreach ($server_users as $u) {
        if ($u['id'] == $selected_user_id) {
            $tokens_to_test[] = [
                'id' => $u['id'],
                'user_name' => $u['name'],
                'token' => $u['token']
            ];
            break;
        }
    }
}

if (empty($tokens_to_test)) {
    $tokens_to_test = array_slice($server_users, 0, 5);
}

// 2. Scan Posts using 100% exact Graph API logic from repost_other_pages.js
$scanned_posts = [];
$api_errors = [];
$endpoints = ['posts', 'feed', 'published_posts'];
$successful_endpoint = '';
$successful_user_name = '';
$active_token = '';
$successRes = null;
$page_info = null;

if (empty($tokens_to_test)) {
    $api_errors[] = "Không tìm thấy User Access Token nào trên Server Database!";
} else {
    foreach ($tokens_to_test as $token_item) {
        $curr_token = $token_item['token'];
        $curr_name  = $token_item['user_name'];

        if (!$page_info) {
            $url_info = "https://graph.facebook.com/v24.0/{$target_page_clean}?fields=id,name,fan_count,picture.type(large)&access_token=" . urlencode($curr_token);
            $ch = curl_init($url_info);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);
            $info_raw = curl_exec($ch);
            curl_close($ch);
            $info_json = $info_raw ? json_decode($info_raw, true) : null;
            if (isset($info_json['id'])) {
                $page_info = $info_json;
            }
        }

        // 100% EXACT URL & FIELDS FROM repost_other_pages.js LINE 270:
        // fields=id,message,created_time,full_picture,attachments{media,media_type,subattachments,target,type,url}
        $fields = 'id,message,created_time,full_picture,attachments{media,media_type,subattachments,target,type,url}';

        $res = null;
        $foundRes = null;

        foreach ($endpoints as $ep) {
            $url = "https://graph.facebook.com/v24.0/{$target_page_clean}/{$ep}?fields=" . urlencode($fields) . "&limit={$limit}&access_token=" . urlencode($curr_token);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);
            $response_raw = curl_exec($ch);
            curl_close($ch);

            $r = $response_raw ? json_decode($response_raw, true) : null;

            if (isset($r['data']) && is_array($r['data'])) {
                $foundRes = $r;
                $successful_endpoint = $ep;
                break;
            } elseif (isset($r['error'])) {
                $res = $r;
                $err_msg = "[{$curr_name}] /{$ep} Error {$r['error']['code']}: {$r['error']['message']}";
                $api_errors[] = $err_msg;
                if ($r['error']['code'] !== 100 && $r['error']['code'] !== 210) {
                    break;
                }
            }
        }

        $finalRes = $foundRes ?: $res;

        // Auto retry with Page Access Token if Error 210 occurs (100% matching repost_other_pages.js LINE 287)
        if (!$foundRes && isset($finalRes['error']['code']) && $finalRes['error']['code'] === 210) {
            try {
                $stmtPageTok = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = :pid LIMIT 1");
                $stmtPageTok->execute(['pid' => $target_page_clean]);
                $pageTokRow = $stmtPageTok->fetch(PDO::FETCH_ASSOC);
                $pageTok = $pageTokRow ? decryptData($pageTokRow['access_token']) : null;

                if ($pageTok) {
                    foreach ($endpoints as $ep) {
                        $url = "https://graph.facebook.com/v24.0/{$target_page_clean}/{$ep}?fields=" . urlencode($fields) . "&limit={$limit}&access_token=" . urlencode($pageTok);
                        $ch = curl_init($url);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 5,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => 0
                        ]);
                        $response_raw = curl_exec($ch);
                        curl_close($ch);

                        $r = $response_raw ? json_decode($response_raw, true) : null;
                        if (isset($r['data']) && is_array($r['data'])) {
                            $foundRes = $r;
                            $successful_endpoint = $ep . ' (Page Token Retry)';
                            break;
                        }
                    }
                }
            } catch (Exception $eRetry) {}
        }

        if ($foundRes && isset($foundRes['data']) && is_array($foundRes['data'])) {
            $successRes = $foundRes;
            $successful_user_name = $curr_name;
            $active_token = $curr_token;
            break; // Stop querying other tokens if success
        }
    }

    // 100% EXACT MEDIA PARSING FROM repost_other_pages.js LINES 321-375:
    if ($successRes && isset($successRes['data']) && is_array($successRes['data'])) {
        foreach ($successRes['data'] as $post) {
            $images = [];
            $videos = [];

            $attachList = $post['attachments']['data'] ?? [];
            foreach ($attachList as $att) {
                if (!empty($att['media']['image']['src'])) {
                    $mtype = $att['media_type'] ?? '';
                    $type  = $att['type'] ?? '';

                    if ($mtype === 'video' || (is_string($type) && strpos($type, 'video') !== false)) {
                        if (!empty($att['media']['source'])) {
                            $videos[] = $att['media']['source'];
                        } else {
                            $images[] = $att['media']['image']['src'];
                        }
                    } else {
                        $images[] = $att['media']['image']['src'];
                    }
                }

                $subList = $att['subattachments']['data'] ?? [];
                foreach ($subList as $sub) {
                    if (!empty($sub['media']['image']['src'])) {
                        $smtype = $sub['media_type'] ?? '';
                        $stype  = $sub['type'] ?? '';

                        if ($smtype === 'video' || (is_string($stype) && strpos($stype, 'video') !== false)) {
                            if (!empty($sub['media']['source'])) {
                                $videos[] = $sub['media']['source'];
                            } else {
                                $images[] = $sub['media']['image']['src'];
                            }
                        } else {
                            $images[] = $sub['media']['image']['src'];
                        }
                    }
                }
            }

            if (empty($images) && empty($videos) && !empty($post['full_picture'])) {
                $images[] = $post['full_picture'];
            }

            $final_images = array_values(array_unique($images));
            $final_videos = array_values(array_unique($videos));

            $post_type = !empty($final_videos) ? 'video' : (!empty($final_images) ? 'photo' : 'text');

            $scanned_posts[] = [
                'id' => $post['id'] ?? '',
                'sourcePageId' => $target_page_clean,
                'message' => $post['message'] ?? '',
                'createdTime' => $post['created_time'] ?? '',
                'thumbnail' => $post['full_picture'] ?? ($final_images[0] ?? ''),
                'images' => $final_images,
                'videos' => $final_videos,
                'post_type' => $post_type,
                'raw_post' => $post
            ];
        }
    }
}

// 3. Output Formats (CLI or HTML Web Interface)
$is_cli = (php_sapi_name() === 'cli');

if ($is_cli) {
    echo "========================================================\n";
    echo " 100% REPOST_OTHER_PAGES.JS EXACT PARSER TEST\n";
    echo " Page Target: {$target_page}\n";
    echo " Successful User Token: {$successful_user_name}\n";
    echo "========================================================\n\n";

    echo "TỔNG SỐ BÀI VIẾT QUÉT ĐƯỢC: " . count($scanned_posts) . "\n\n";

    $vid_count = 0;
    foreach ($scanned_posts as $idx => $p) {
        $num = $idx + 1;
        echo "[#{$num}] Post ID: {$p['id']} | Loai: " . strtoupper($p['post_type']) . "\n";
        echo "     Created At: {$p['createdTime']}\n";
        echo "     Message: " . mb_substr(str_replace("\n", " ", $p['message']), 0, 80) . "...\n";
        if (!empty($p['videos'])) {
            $vid_count++;
            echo "     [✓ VIDEO URL FOUND]: " . $p['videos'][0] . "\n";
        } elseif (!empty($p['images'])) {
            echo "     [IMAGE COUNT]: " . count($p['images']) . " (First: " . $p['images'][0] . ")\n";
        }
        echo "--------------------------------------------------------\n";
    }

    echo "KẾT QUẢ TỔNG KẾT: Tìm thấy {$vid_count} bài viết chứa Video MP4 trực tiếp!\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scraper Test (100% repost_other_pages.js) - <?php echo htmlspecialchars($target_page); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #0b132b; color: #e0e1dd; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; padding-bottom: 60px; }
        .card-custom { background: #1c2541; border: 1px solid #3a506b; border-radius: 12px; }
        .badge-video { background: linear-gradient(135deg, #ff0054, #ff5400); font-size: 0.85rem; padding: 6px 12px; }
        .badge-photo { background: linear-gradient(135deg, #00b4d8, #0077b6); font-size: 0.85rem; padding: 6px 12px; }
        .badge-text { background: linear-gradient(135deg, #6c757d, #495057); font-size: 0.85rem; padding: 6px 12px; }
        .video-box { background: #0b0e14; border: 1px solid #00f2fe; border-radius: 10px; padding: 15px; }
        video { max-width: 100%; border-radius: 8px; max-height: 380px; }
        .img-thumb { max-height: 180px; object-fit: cover; border-radius: 8px; border: 1px solid #3a506b; }
        .code-box { background: #000814; color: #00f2fe; font-family: monospace; padding: 8px 12px; border-radius: 6px; font-size: 0.85rem; word-break: break-all; }
    </style>
</head>
<body>
<div class="container mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom border-secondary">
        <div>
            <h2 class="fw-bold text-info"><i class="fa-solid fa-code me-2"></i>Scraper Test (Giống 100% repost_other_pages.js)</h2>
            <p class="text-secondary mb-0">Quét bài viết & media với thuật toán 1:1 tương thích repost_other_pages.js</p>
        </div>
        <div>
            <a href="facebook_scraper.php" class="btn btn-outline-light"><i class="fa-solid fa-arrow-left me-1"></i> Trở về Scraper Main</a>
        </div>
    </div>

    <!-- Manual Page Scraper Form -->
    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold text-info mb-3">
            <i class="fa-solid fa-spider me-2"></i>🕸️ Thêm & Quét Page Nguồn (Manual)
        </h5>
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label text-warning fw-bold mb-1">
                    <i class="fa-solid fa-user-gear me-1"></i> Chọn User Quản Lý Token:
                </label>
                <select name="user_id" class="form-select bg-dark text-light border-secondary">
                    <option value="0">⚡ Thử các User Token trên Server (Tự động)</option>
                    <?php foreach ($server_users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo ($selected_user_id == $u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['name']); ?> (ID: <?php echo $u['id']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label text-warning fw-bold mb-1">
                    <i class="fa-brands fa-facebook me-1"></i> Fanpage ID / Slug:
                </label>
                <input type="text" name="page" class="form-control bg-dark text-light border-secondary" value="<?php echo htmlspecialchars($target_page); ?>" placeholder="TatDiepBeautySalonQ3">
            </div>
            <div class="col-md-2">
                <label class="form-label text-warning fw-bold mb-1">
                    <i class="fa-solid fa-list-ol me-1"></i> Số bài quét:
                </label>
                <input type="number" name="limit" class="form-control bg-dark text-light border-secondary" value="<?php echo $limit; ?>" min="1" max="50">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100 fw-bold">
                    <i class="fa-solid fa-magnifying-glass me-1"></i> Quét Page Nguồn
                </button>
            </div>
            <div class="col-12 mt-2">
                <label class="form-label text-muted small mb-1"><i class="fa-solid fa-key me-1"></i> Hoặc dùng Access Token tùy chỉnh (EAA... Extension Token):</label>
                <input type="text" name="token" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>" placeholder="Dán Access Token EAA... thu thập từ Extension nếu muốn test">
            </div>
        </form>
    </div>

    <!-- Status & Info Card -->
    <div class="card card-custom p-3 mb-4">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center">
                    <?php if ($page_info && !empty($page_info['picture']['data']['url'])): ?>
                        <img src="<?php echo htmlspecialchars($page_info['picture']['data']['url']); ?>" class="rounded-circle me-3" width="50" height="50" style="border: 2px solid #00f2fe;">
                    <?php endif; ?>
                    <div>
                        <h5 class="fw-bold text-light mb-0">
                            <?php echo htmlspecialchars($page_info['name'] ?? $target_page); ?>
                        </h5>
                        <small class="text-muted">
                            Page ID: <strong><?php echo htmlspecialchars($page_info['id'] ?? $target_page_clean); ?></strong> 
                            <?php if (isset($page_info['fan_count'])): ?>
                                | <?php echo number_format($page_info['fan_count']); ?> lượt thích
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-6 text-md-end mt-3 mt-md-0">
                <?php if ($successful_user_name): ?>
                    <span class="badge bg-success p-2 me-1"><i class="fa-solid fa-circle-check me-1"></i> Token: <?php echo htmlspecialchars($successful_user_name); ?></span>
                <?php else: ?>
                    <span class="badge bg-danger p-2 me-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Không lấy được bài</span>
                <?php endif; ?>

                <?php if ($successful_endpoint): ?>
                    <span class="badge bg-info text-dark p-2"><i class="fa-solid fa-plug me-1"></i> Endpoint: /<?php echo htmlspecialchars($successful_endpoint); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- API Response Logs if any -->
    <?php if (!empty($api_errors)): ?>
        <div class="alert alert-dark border-secondary p-3 rounded-3 mb-4">
            <h6 class="fw-bold text-warning mb-2"><i class="fa-solid fa-terminal me-2"></i> Nhật ký kết nối API:</h6>
            <ul class="mb-0 ps-3 small text-secondary" style="max-height: 120px; overflow-y: auto;">
                <?php foreach ($api_errors as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Summary Stats -->
    <?php 
        $video_posts = array_filter($scanned_posts, fn($p) => !empty($p['videos']));
        $photo_posts = array_filter($scanned_posts, fn($p) => $p['post_type'] === 'photo');
        $text_posts  = array_filter($scanned_posts, fn($p) => $p['post_type'] === 'text');
    ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-custom p-3 text-center border-info">
                <h3 class="fw-bold text-info mb-0"><?php echo count($scanned_posts); ?></h3>
                <div class="text-secondary small mt-1">Tổng số bài quét được</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-custom p-3 text-center border-danger">
                <h3 class="fw-bold text-danger mb-0"><?php echo count($video_posts); ?></h3>
                <div class="text-secondary small mt-1">Bài viết có VIDEO MP4</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-custom p-3 text-center border-primary">
                <h3 class="fw-bold text-primary mb-0"><?php echo count($photo_posts); ?></h3>
                <div class="text-secondary small mt-1">Bài viết có HÌNH ẢNH</div>
            </div>
        </div>
    </div>

    <!-- Posts List -->
    <h4 class="fw-bold text-light mb-3"><i class="fa-solid fa-list-check me-2"></i> Danh sách bài viết (1:1 repost_other_pages.js)</h4>

    <?php if (empty($scanned_posts)): ?>
        <div class="alert alert-danger p-4 text-center rounded-3">
            <i class="fa-solid fa-circle-xmark fa-2x mb-2"></i>
            <h5>Không tìm thấy bài viết nào!</h5>
            <p class="mb-0 text-muted">Vui lòng kiểm tra lại Fanpage ID hoặc chọn User cụ thể có Token hoạt động.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($scanned_posts as $idx => $p): ?>
                <?php 
                    $has_vid = !empty($p['videos']);
                    $vid_url = $has_vid ? $p['videos'][0] : '';
                ?>
                <div class="col-12">
                    <div class="card card-custom p-3 <?php echo $has_vid ? 'border-danger' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <span class="badge <?php echo $has_vid ? 'badge-video' : ($p['post_type'] === 'photo' ? 'badge-photo' : 'badge-text'); ?> me-2">
                                    <i class="fa-solid <?php echo $has_vid ? 'fa-video' : ($p['post_type'] === 'photo' ? 'fa-image' : 'fa-font'); ?> me-1"></i>
                                    <?php echo strtoupper($p['post_type']); ?>
                                </span>
                                <small class="text-muted"><i class="fa-regular fa-clock me-1"></i> <?php echo htmlspecialchars($p['createdTime']); ?></small>
                                <span class="ms-2 text-secondary small">(ID: <?php echo htmlspecialchars($p['id']); ?>)</span>
                            </div>
                            <a href="https://facebook.com/<?php echo htmlspecialchars($p['id']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                Xem trên Facebook <i class="fa-solid fa-external-link ms-1"></i>
                            </a>
                        </div>

                        <!-- Message Content -->
                        <div class="p-2 mb-3 bg-dark rounded border border-secondary text-light small" style="white-space: pre-line; max-height: 90px; overflow-y: auto;">
                            <?php echo htmlspecialchars($p['message'] ?: '(Không có nội dung chữ)'); ?>
                        </div>

                        <!-- Video Result Box -->
                        <?php if ($has_vid): ?>
                            <div class="video-box">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="text-success fw-bold"><i class="fa-solid fa-circle-check me-1"></i> BÓC TÁCH ĐƯỢC LINK VIDEO MP4 THÀNH CÔNG!</span>
                                    <a href="<?php echo htmlspecialchars($vid_url); ?>" target="_blank" download="video.mp4" class="btn btn-sm btn-danger">
                                        <i class="fa-solid fa-download me-1"></i> Tải / Mở Link Video MP4
                                    </a>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <video controls class="w-100">
                                            <source src="<?php echo htmlspecialchars($vid_url); ?>" type="video/mp4">
                                            Trình duyệt không hỗ trợ phát Video HTML5.
                                        </video>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-warning fw-bold mb-1">Direct Video URL (MP4):</label>
                                        <textarea class="form-control form-control-sm bg-dark text-info border-secondary font-monospace mb-2" rows="4" readonly><?php echo htmlspecialchars($vid_url); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (!empty($p['images'])): ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($p['images'] as $img): ?>
                                    <a href="<?php echo htmlspecialchars($img); ?>" target="_blank">
                                        <img src="<?php echo htmlspecialchars($img); ?>" class="img-thumb">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!empty($p['thumbnail'])): ?>
                            <div>
                                <a href="<?php echo htmlspecialchars($p['thumbnail']); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($p['thumbnail']); ?>" class="img-thumb">
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
