<?php
/**
 * Test Script for Scraping Videos & Posts from Page TatDiepBeautySalonQ3
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/security.php';

@ini_set('display_errors', 1);
error_reporting(E_ALL);

$target_page = isset($_GET['page']) ? trim($_GET['page']) : 'TatDiepBeautySalonQ3';

// 1. Get an active user token from database
$stmtToken = $pdo->query("SELECT id, name, access_token FROM users WHERE access_token IS NOT NULL AND access_token != '' ORDER BY id DESC LIMIT 1");
$user = $stmtToken->fetch(PDO::FETCH_ASSOC);

$token = '';
$user_name = '';
if ($user) {
    $token = decryptData($user['access_token']);
    $user_name = $user['name'] ?: 'User #' . $user['id'];
}

// Fallback to custom token passed via URL parameter if any
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = trim($_GET['token']);
}

// 2. Define scraper helper functions if not already defined
if (!function_exists('scrape_fb_video_mp4_url')) {
    function scrape_fb_video_mp4_url($url) {
        if (empty($url)) return '';
        
        $test_urls = [
            $url,
            str_replace(['www.facebook.com', 'web.facebook.com', 'm.facebook.com'], 'mbasic.facebook.com', $url),
            str_replace(['www.facebook.com', 'web.facebook.com', 'mbasic.facebook.com'], 'm.facebook.com', $url)
        ];

        foreach ($test_urls as $t_url) {
            $ch = curl_init($t_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);
            $html = curl_exec($ch);
            curl_close($ch);
            if (!$html) continue;

            if (preg_match('/href=["\'](\/video\/redirect\/\?src=[^"\']+)["\']/', $html, $m)) {
                $raw_url = urldecode(str_replace('/video/redirect/?src=', '', html_entity_decode($m[1])));
                if (filter_var($raw_url, FILTER_VALIDATE_URL)) {
                    return $raw_url;
                }
            }

            $patterns = [
                '/["\']browser_native_hd_url["\']\s*:\s*["\']([^"\']+)["\']/',
                '/["\']browser_native_sd_url["\']\s*:\s*["\']([^"\']+)["\']/',
                '/["\']playable_url_quality_hd["\']\s*:\s*["\']([^"\']+)["\']/',
                '/["\']playable_url["\']\s*:\s*["\']([^"\']+)["\']/',
                '/hd_src\s*:\s*["\']([^"\']+)["\']/',
                '/sd_src\s*:\s*["\']([^"\']+)["\']/'
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $raw_url = stripslashes(str_replace('\/', '/', $m[1]));
                    if (filter_var($raw_url, FILTER_VALIDATE_URL)) {
                        return $raw_url;
                    }
                }
            }
        }
        return '';
    }
}

if (!function_exists('get_video_source_from_post')) {
    function get_video_source_from_post($token, $post, $page_videos_map = []) {
        $attachments = $post['attachments']['data'][0] ?? null;
        if (!empty($attachments['media']['source'])) {
            return $attachments['media']['source'];
        }

        $subList = $attachments['subattachments']['data'] ?? [];
        foreach ($subList as $sub) {
            if (!empty($sub['media']['source'])) {
                return $sub['media']['source'];
            }
        }

        $target_id = $attachments['target']['id'] ?? '';
        if (!empty($target_id)) {
            if (!empty($page_videos_map[$target_id])) {
                return $page_videos_map[$target_id];
            }
            $res = fb_api_request("{$target_id}", ['access_token' => $token, 'fields' => 'source,playable_url,playable_url_quality_hd']);
            if (!empty($res['data']['source'])) return $res['data']['source'];
            if (!empty($res['data']['playable_url_quality_hd'])) return $res['data']['playable_url_quality_hd'];
            if (!empty($res['data']['playable_url'])) return $res['data']['playable_url'];
        }

        $pic = $post['full_picture'] ?? ($post['picture'] ?? '');
        if ($pic) {
            if (preg_match_all('/(?:[\/_]|^)(\d{13,16})(?:[\/_]|\.|$)/', $pic, $m_all)) {
                $vid_candidates = array_unique($m_all[1]);
                foreach ($vid_candidates as $vid_id) {
                    if (!empty($page_videos_map[$vid_id])) {
                        return $page_videos_map[$vid_id];
                    }
                    $res = fb_api_request("{$vid_id}", ['access_token' => $token, 'fields' => 'source,playable_url,playable_url_quality_hd']);
                    if (!empty($res['data']['source'])) return $res['data']['source'];
                    if (!empty($res['data']['playable_url_quality_hd'])) return $res['data']['playable_url_quality_hd'];
                    if (!empty($res['data']['playable_url'])) return $res['data']['playable_url'];

                    $test_urls = [
                        "https://www.facebook.com/reel/{$vid_id}",
                        "https://www.facebook.com/watch/?v={$vid_id}",
                        "https://mbasic.facebook.com/video/mbasic.php?module=video_publisher&v={$vid_id}"
                    ];
                    foreach ($test_urls as $t_u) {
                        $h_src = scrape_fb_video_mp4_url($t_u);
                        if ($h_src) return $h_src;
                    }
                }
            }
        }

        $url = $attachments['url'] ?? ($attachments['target']['url'] ?? ($post['permalink_url'] ?? ''));
        if ($url) {
            $h_src = scrape_fb_video_mp4_url($url);
            if ($h_src) return $h_src;
        }

        if ($target_id) {
            $watch_url = "https://www.facebook.com/watch/?v=" . $target_id;
            $h_src = scrape_fb_video_mp4_url($watch_url);
            if ($h_src) return $h_src;
        }

        return '';
    }
}

if (!function_exists('extract_post_media_urls')) {
    function extract_post_media_urls($post, $token = '') {
        $images = [];
        $videos = [];
        $is_video_post = false;

        $attachList = $post['attachments']['data'] ?? [];
        foreach ($attachList as $att) {
            $mtype = strtolower($att['media_type'] ?? '');
            $type = strtolower($att['type'] ?? '');
            $ttype = strtolower($att['target']['type'] ?? '');
            $url = strtolower($att['url'] ?? ($att['target']['url'] ?? ''));

            $is_vid = ($mtype === 'video' || $mtype === 'reel' || 
                       strpos($type, 'video') !== false || strpos($type, 'reel') !== false || 
                       strpos($ttype, 'video') !== false || strpos($ttype, 'reel') !== false ||
                       strpos($url, '/reel/') !== false || strpos($url, '/watch') !== false || strpos($url, '/videos/') !== false);

            if ($is_vid || !empty($att['media']['source'])) {
                $is_video_post = true;
                if (!empty($att['media']['source'])) {
                    $videos[] = $att['media']['source'];
                }
            } else {
                if (!empty($att['media']['image']['src'])) {
                    $images[] = $att['media']['image']['src'];
                }
            }

            $subList = $att['subattachments']['data'] ?? [];
            foreach ($subList as $sub) {
                $smtype = strtolower($sub['media_type'] ?? '');
                $stype = strtolower($sub['type'] ?? '');
                $sttype = strtolower($sub['target']['type'] ?? '');
                $surl = strtolower($sub['url'] ?? ($sub['target']['url'] ?? ''));

                $sub_is_vid = ($smtype === 'video' || $smtype === 'reel' || 
                               strpos($stype, 'video') !== false || strpos($stype, 'reel') !== false || 
                               strpos($sttype, 'video') !== false || strpos($sttype, 'reel') !== false ||
                               strpos($surl, '/reel/') !== false || strpos($surl, '/watch') !== false || strpos($surl, '/videos/') !== false);

                if ($sub_is_vid || !empty($sub['media']['source'])) {
                    $is_video_post = true;
                    if (!empty($sub['media']['source'])) {
                        $videos[] = $sub['media']['source'];
                    }
                } else {
                    if (!empty($sub['media']['image']['src'])) {
                        $images[] = $sub['media']['image']['src'];
                    }
                }
            }
        }

        if (empty($videos)) {
            $v_source = get_video_source_from_post($token, $post);
            if ($v_source) {
                $videos[] = $v_source;
                $is_video_post = true;
            }
        }

        if (!$is_video_post && empty($images) && !empty($post['full_picture'])) {
            $images[] = $post['full_picture'];
        }

        $post_type = $is_video_post ? 'video' : (!empty($images) ? 'photo' : 'text');

        return [
            'is_video' => $is_video_post,
            'post_type' => $post_type,
            'images' => $is_video_post ? [] : array_values(array_unique(array_filter($images))),
            'videos' => array_values(array_unique(array_filter($videos)))
        ];
    }
}

// 3. Fetch Page Data from Facebook API
$api_response = null;
$error_message = '';
$posts_list = [];
$page_info = null;

if (empty($token)) {
    $error_message = "Không tìm thấy Facebook Access Token trong cơ sở dữ liệu. Vui lòng thêm tài khoản Facebook hoặc truyền ?token=YOUR_ACCESS_TOKEN trên URL.";
} else {
    // Check page info first
    $page_info_res = fb_api_request("{$target_page}", [
        'access_token' => $token,
        'fields' => 'id,name,fan_count,picture.type(large)'
    ]);
    if ($page_info_res['status_code'] === 200) {
        $page_info = $page_info_res['data'];
    }

    // Fetch posts
    $fields = 'id,message,created_time,full_picture,attachments{media_type,media{source,image},target,type,url,subattachments{media_type,media{source,image},target,type,url}},shares,comments.summary(total_count),reactions.summary(total_count)';
    
    $api_response = fb_api_request("{$target_page}/posts", [
        'access_token' => $token,
        'fields' => $fields,
        'limit' => 20
    ]);

    if ($api_response['status_code'] === 200) {
        $posts_data = $api_response['data']['data'] ?? [];
        foreach ($posts_data as $post) {
            $media_info = extract_post_media_urls($post, $token);
            $posts_list[] = [
                'id' => $post['id'] ?? '',
                'created_time' => $post['created_time'] ?? '',
                'message' => $post['message'] ?? '',
                'full_picture' => $post['full_picture'] ?? '',
                'media_info' => $media_info,
                'raw_post' => $post
            ];
        }
    } else {
        $error_message = $api_response['data']['error']['message'] ?? 'Lỗi không xác định từ Graph API.';
    }
}

$is_cli = (php_sapi_name() === 'cli');

if ($is_cli) {
    echo "=== TEST FB SCRAPER FOR PAGE: {$target_page} ===\n";
    if ($error_message) {
        echo "LỖI: {$error_message}\n";
        exit(1);
    }
    echo "User Token: {$user_name}\n";
    if ($page_info) {
        echo "Page Name: " . ($page_info['name'] ?? '') . " (ID: " . ($page_info['id'] ?? '') . ")\n";
    }
    echo "Tổng số bài viết quét được: " . count($posts_list) . "\n\n";

    $video_count = 0;
    foreach ($posts_list as $idx => $p) {
        $num = $idx + 1;
        $m = $p['media_info'];
        echo "[#{$num}] Post ID: {$p['id']} | Loai: {$m['post_type']}\n";
        echo "     Thoi gian: {$p['created_time']}\n";
        echo "     Noi dung: " . mb_substr(str_replace("\n", " ", $p['message']), 0, 80) . "...\n";
        if (!empty($m['videos'])) {
            $video_count++;
            echo "     [SUCCESS] VIDEO URL: " . $m['videos'][0] . "\n";
        } elseif (!empty($m['images'])) {
            echo "     Anh count: " . count($m['images']) . " (First: " . $m['images'][0] . ")\n";
        }
        echo "--------------------------------------------------------\n";
    }
    echo "KẾT QUẢ: Quét được {$video_count} bài viết chứa Video / Reel!\n";
    exit(0);
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Scraper Video - <?php echo htmlspecialchars($target_page); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #0f172a; color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-bottom: 50px; }
        .card-custom { background: #1e293b; border: 1px solid #334155; border-radius: 12px; }
        .badge-video { background: linear-gradient(135deg, #ef4444, #dc2626); font-size: 0.85rem; padding: 6px 12px; }
        .badge-photo { background: linear-gradient(135deg, #3b82f6, #2563eb); font-size: 0.85rem; padding: 6px 12px; }
        .badge-text { background: linear-gradient(135deg, #64748b, #475569); font-size: 0.85rem; padding: 6px 12px; }
        .video-box { background: #090d16; border: 1px solid #00f2fe; border-radius: 8px; padding: 10px; }
        video { max-width: 100%; border-radius: 8px; max-height: 350px; }
        .img-thumb { max-height: 180px; object-fit: cover; border-radius: 8px; border: 1px solid #334155; }
    </style>
</head>
<body>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-info"><i class="fa-brands fa-facebook"></i> Test Scraper Video: <?php echo htmlspecialchars($target_page); ?></h2>
            <p class="text-secondary mb-0">Kiểm tra khả năng bóc tách Video & Media từ Facebook Page target</p>
        </div>
        <div>
            <a href="facebook_scraper.php" class="btn btn-outline-light"><i class="fa-solid fa-arrow-left"></i> Quay lại Facebook Scraper</a>
        </div>
    </div>

    <!-- Page / Token Info -->
    <div class="card card-custom p-3 mb-4">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5 class="text-warning mb-1"><i class="fa-solid fa-key"></i> Facebook Token Sử Dụng:</h5>
                <p class="mb-0 text-light"><strong><?php echo htmlspecialchars($user_name ?: 'Chưa có Token'); ?></strong> <?php if ($token) echo '<span class="badge bg-success ms-2">Đang hoạt động</span>'; ?></p>
            </div>
            <div class="col-md-6 text-md-end">
                <?php if ($page_info): ?>
                    <div class="d-inline-flex align-items-center bg-dark p-2 px-3 rounded-3 border border-secondary">
                        <img src="<?php echo htmlspecialchars($page_info['picture']['data']['url'] ?? ''); ?>" class="rounded-circle me-2" width="40" height="40">
                        <div class="text-start">
                            <div class="fw-bold"><?php echo htmlspecialchars($page_info['name'] ?? ''); ?></div>
                            <small class="text-muted">ID: <?php echo htmlspecialchars($page_info['id'] ?? ''); ?> | <?php echo number_format($page_info['fan_count'] ?? 0); ?> lượt thích</small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger p-3 rounded-3">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <strong>Lỗi Quét Bài:</strong> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php else: ?>
        
        <?php
            $video_posts = array_filter($posts_list, fn($p) => !empty($p['media_info']['videos']));
            $photo_posts = array_filter($posts_list, fn($p) => $p['media_info']['post_type'] === 'photo');
        ?>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card card-custom p-3 text-center border-info">
                    <h3 class="fw-bold text-info"><?php echo count($posts_list); ?></h3>
                    <div class="text-muted">Tổng bài viết đã quét</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom p-3 text-center border-danger">
                    <h3 class="fw-bold text-danger"><?php echo count($video_posts); ?></h3>
                    <div class="text-muted">Bài viết chứa VIDEO / REEL</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom p-3 text-center border-primary">
                    <h3 class="fw-bold text-primary"><?php echo count($photo_posts); ?></h3>
                    <div class="text-muted">Bài viết chứa HÌNH ẢNH</div>
                </div>
            </div>
        </div>

        <h4 class="fw-bold text-light mb-3"><i class="fa-solid fa-list"></i> Danh sách bài viết & Link Video bóc tách được</h4>

        <div class="row g-3">
            <?php foreach ($posts_list as $idx => $p): ?>
                <?php 
                    $m = $p['media_info']; 
                    $has_vid = !empty($m['videos']);
                ?>
                <div class="col-12">
                    <div class="card card-custom p-3 <?php echo $has_vid ? 'border-danger' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <span class="badge <?php echo $has_vid ? 'badge-video' : ($m['post_type'] === 'photo' ? 'badge-photo' : 'badge-text'); ?> me-2">
                                    <i class="fa-solid <?php echo $has_vid ? 'fa-video' : ($m['post_type'] === 'photo' ? 'fa-image' : 'fa-font'); ?>"></i>
                                    <?php echo strtoupper($m['post_type']); ?>
                                </span>
                                <small class="text-muted"><i class="fa-regular fa-clock me-1"></i> <?php echo htmlspecialchars($p['created_time']); ?></small>
                                <span class="ms-2 text-secondary small">(ID: <?php echo htmlspecialchars($p['id']); ?>)</span>
                            </div>
                            <a href="https://facebook.com/<?php echo htmlspecialchars($p['id']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                Xem trên FB <i class="fa-solid fa-external-link ms-1"></i>
                            </a>
                        </div>

                        <p class="mb-3 text-light" style="white-space: pre-line; max-height: 100px; overflow-y: auto; background: #0f172a; padding: 10px; border-radius: 6px; font-size: 0.95rem;">
                            <?php echo htmlspecialchars($p['message'] ?: '(Không có nội dung văn bản)'); ?>
                        </p>

                        <?php if ($has_vid): ?>
                            <div class="video-box mt-2">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="text-success fw-bold"><i class="fa-solid fa-circle-check"></i> Bóc Tách Được Video MP4 thành công!</span>
                                    <a href="<?php echo htmlspecialchars($m['videos'][0]); ?>" target="_blank" class="btn btn-sm btn-danger">
                                        <i class="fa-solid fa-download me-1"></i> Tải / Mở Direct Link Video
                                    </a>
                                </div>
                                <div class="row">
                                    <div class="col-md-7">
                                        <video controls class="w-100">
                                            <source src="<?php echo htmlspecialchars($m['videos'][0]); ?>" type="video/mp4">
                                            Trình duyệt không hỗ trợ xem video trực tiếp.
                                        </video>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="small text-muted mb-1">Direct Video URL:</div>
                                        <textarea class="form-control form-control-sm bg-dark text-light border-secondary" rows="5" readonly><?php echo htmlspecialchars($m['videos'][0]); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (!empty($m['images'])): ?>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <?php foreach ($m['images'] as $img): ?>
                                    <a href="<?php echo htmlspecialchars($img); ?>" target="_blank">
                                        <img src="<?php echo htmlspecialchars($img); ?>" class="img-thumb">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!empty($p['full_picture'])): ?>
                            <div class="mt-2">
                                <a href="<?php echo htmlspecialchars($p['full_picture']); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($p['full_picture']); ?>" class="img-thumb">
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
