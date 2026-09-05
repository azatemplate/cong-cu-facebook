<?php
/**
 * Diagnostic Test Script for Facebook Scraper & Auto-Bot
 * File: test_facebook_scraper.php
 * Upload this file to your web server to test scraping and bot execution.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error reporting for diagnostic purposes
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

// Ensure required DB tables
if (function_exists('ensureScraperTables')) {
    ensureScraperTables($pdo);
} else {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scraper_pages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                user_id INT NOT NULL,
                page_id VARCHAR(255) NOT NULL,
                page_name VARCHAR(255),
                followers_count INT DEFAULT 0,
                post_count INT DEFAULT 0,
                access_token TEXT,
                auto_refresh_hours INT DEFAULT 0,
                last_scraped_at DATETIME DEFAULT NULL,
                only_with_content TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scraper_page (account_id, page_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scraper_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                page_id VARCHAR(255) NOT NULL,
                fb_post_id VARCHAR(255) NOT NULL,
                message TEXT,
                picture TEXT,
                shares INT DEFAULT 0,
                comments INT DEFAULT 0,
                likes INT DEFAULT 0,
                post_created_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_post (page_id, fb_post_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scraper_bots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                user_id INT DEFAULT 0,
                name VARCHAR(255) NOT NULL,
                sources JSON DEFAULT NULL,
                targets JSON DEFAULT NULL,
                check_interval_seconds INT DEFAULT 300,
                target_post_gap_seconds INT DEFAULT 0,
                range_filter VARCHAR(20) DEFAULT 'today',
                format_filter VARCHAR(20) DEFAULT 'all',
                distribute_mode VARCHAR(20) DEFAULT 'all',
                find_words TEXT DEFAULT NULL,
                replace_words TEXT DEFAULT NULL,
                remove_hashtag TINYINT(1) DEFAULT 0,
                remove_link TINYINT(1) DEFAULT 1,
                seeding JSON DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'stopped',
                last_run_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bot_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scraper_bot_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bot_id INT NOT NULL,
                account_id INT NOT NULL,
                source_post_id VARCHAR(255) NOT NULL,
                source_page_id VARCHAR(255) DEFAULT NULL,
                source_page_name VARCHAR(255) DEFAULT NULL,
                source_permalink VARCHAR(500) DEFAULT NULL,
                target_page_id VARCHAR(255) DEFAULT NULL,
                target_page_name VARCHAR(255) DEFAULT NULL,
                posted_permalink VARCHAR(500) DEFAULT NULL,
                post_type VARCHAR(50) DEFAULT 'text',
                post_content TEXT DEFAULT NULL,
                status ENUM('success', 'fail') DEFAULT 'success',
                error_message TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_log_bot (bot_id),
                INDEX idx_log_source_post (source_post_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {}
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$log_output = [];

function add_log($msg, $type = 'info') {
    global $log_output;
    $time = date('H:i:s');
    $log_output[] = [
        'time' => $time,
        'msg'  => $msg,
        'type' => $type
    ];
}

// 1. Fetch available user tokens from system
$system_users = [];
try {
    $stmtU = $pdo->query("SELECT id, name, email, account_id, access_token FROM users WHERE access_token IS NOT NULL AND access_token != ''");
    $system_users = $stmtU->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 2. Handle Test Scrape Request
$scraped_posts = [];
$scrape_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_scrape') {
    $target_page = trim($_POST['target_page'] ?? 'TatDiepBeautySalonQ3');
    $token_user_id = intval($_POST['user_id'] ?? 0);
    $limit = max(1, min(50, intval($_POST['limit'] ?? 10)));
    $custom_token = trim($_POST['custom_token'] ?? '');

    add_log("Bắt đầu kiểm tra cào bài từ Trang Nguồn: <strong>{$target_page}</strong> (Số lượng limit: {$limit})");

    // Retrieve Token
    $access_token = $custom_token;
    if (empty($access_token) && $token_user_id > 0) {
        $stmtToken = $pdo->prepare("SELECT access_token FROM users WHERE id = :uid LIMIT 1");
        $stmtToken->execute(['uid' => $token_user_id]);
        $raw = $stmtToken->fetchColumn();
        if ($raw) {
            $access_token = function_exists('decryptData') ? decryptData($raw) : $raw;
        }
    }

    if (empty($access_token) && !empty($system_users)) {
        $raw = $system_users[0]['access_token'];
        $access_token = function_exists('decryptData') ? decryptData($raw) : $raw;
        add_log("Sử dụng User Access Token mặc định của: <strong>" . htmlspecialchars($system_users[0]['name']) . "</strong>", 'info');
    }

    if (empty($access_token)) {
        add_log("⚠ LỖI: Không tìm thấy Facebook Access Token nào khả dụng trong hệ thống!", 'error');
    } else {
        add_log("Đã tải Facebook Access Token thành công. Tiến hành gửi request đến Graph API...");

        $fields = 'id,message,created_time,full_picture,attachments{media_type,media{source,image},target,type,url,subattachments{media_type,media{source,image},target,type,url}},shares,comments.summary(total_count),reactions.summary(total_count)';
        
        $res = fb_api_request("{$target_page}/posts", [
            'access_token' => $access_token,
            'fields'       => $fields,
            'limit'        => $limit
        ]);

        if ($res['status_code'] === 200 && !empty($res['data']['data'])) {
            $raw_posts = $res['data']['data'];
            add_log("✔ QUÉT THÀNH CÔNG: Tìm thấy <strong>" . count($raw_posts) . "</strong> bài viết từ Facebook Graph API!", 'success');

            $save_count = 0;
            foreach ($raw_posts as $post) {
                $fbid = $post['id'] ?? '';
                $msg = $post['message'] ?? '';
                $picture = $post['full_picture'] ?? '';
                $shares = intval($post['shares']['count'] ?? 0);
                $comments = intval($post['comments']['summary']['total_count'] ?? 0);
                $likes = intval($post['reactions']['summary']['total_count'] ?? 0);
                $post_created_at = isset($post['created_time']) ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : null;

                // Extract Media
                $images = [];
                $videos = [];
                $is_video = false;

                if (!empty($post['attachments']['data'])) {
                    foreach ($post['attachments']['data'] as $att) {
                        if (!empty($att['media']['source'])) {
                            $videos[] = $att['media']['source'];
                            $is_video = true;
                        } elseif (!empty($att['media']['image']['src'])) {
                            $images[] = $att['media']['image']['src'];
                        }

                        if (!empty($att['subattachments']['data'])) {
                            foreach ($att['subattachments']['data'] as $sub) {
                                if (!empty($sub['media']['source'])) {
                                    $videos[] = $sub['media']['source'];
                                    $is_video = true;
                                } elseif (!empty($sub['media']['image']['src'])) {
                                    $images[] = $sub['media']['image']['src'];
                                }
                            }
                        }
                    }
                }

                if (!$is_video && empty($images) && $picture) {
                    $images[] = $picture;
                }

                $scraped_posts[] = [
                    'id'         => $fbid,
                    'message'    => $msg,
                    'picture'    => $picture,
                    'is_video'   => $is_video,
                    'images'     => $images,
                    'videos'     => $videos,
                    'shares'     => $shares,
                    'comments'   => $comments,
                    'likes'      => $likes,
                    'created_at' => $post_created_at
                ];

                // Save to database table `scraper_posts`
                try {
                    $stmtSave = $pdo->prepare("
                        INSERT INTO scraper_posts (page_id, fb_post_id, message, picture, shares, comments, likes, post_created_at)
                        VALUES (:page_id, :fb_post_id, :msg, :pic, :shares, :comments, :likes, :pca)
                        ON DUPLICATE KEY UPDATE 
                            message = VALUES(message), 
                            picture = VALUES(picture), 
                            shares = VALUES(shares), 
                            comments = VALUES(comments), 
                            likes = VALUES(likes)
                    ");
                    $stmtSave->execute([
                        'page_id'    => $target_page,
                        'fb_post_id' => $fbid,
                        'msg'        => $msg,
                        'pic'        => $picture,
                        'shares'     => $shares,
                        'comments'   => $comments,
                        'likes'      => $likes,
                        'pca'        => $post_created_at
                    ]);
                    $save_count++;
                } catch (Exception $eDb) {
                    add_log("Lỗi lưu DB bài {$fbid}: " . $eDb->getMessage(), 'error');
                }
            }

            add_log("✔ Đã lưu/cập nhật <strong>{$save_count}</strong> bài viết vào bảng <code>scraper_posts</code> trong CSDL!", 'success');
        } else {
            $err_msg = $res['data']['error']['message'] ?? json_encode($res['data']);
            add_log("❌ LỖI API FACEBOOK (Mã HTTP {$res['status_code']}): " . htmlspecialchars($err_msg), 'error');
        }
    }
}

// 3. Handle Test Run Auto Bot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_run_bot') {
    $bot_id = intval($_POST['bot_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 1);

    add_log("Kích hoạt chạy thử BOT Auto-Post (BOT ID: <strong>{$bot_id}</strong>, Account ID: <strong>{$account_id}</strong>)");

    if (function_exists('executeScraperBot')) {
        $result = executeScraperBot($pdo, $bot_id, $account_id);
        if ($result['status'] === 'success') {
            add_log("✔ CHẠY BOT THÀNH CÔNG: " . htmlspecialchars($result['message'] ?? 'OK'), 'success');
        } else {
            add_log("❌ CHẠY BOT THẤT BẠI: " . htmlspecialchars($result['message'] ?? 'Unknown Error'), 'error');
        }
    } else {
        add_log("⚠ Hàm <code>executeScraperBot()</code> chưa được nạp. Vui lòng kiểm tra lại <code>facebook_scraper.php</code>.", 'error');
    }
}

// Get system bots list
$system_bots = [];
try {
    $stmtBots = $pdo->query("SELECT id, name, status, account_id, sources, targets FROM scraper_bots ORDER BY id DESC");
    $system_bots = $stmtBots->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get total saved scraped posts
$total_scraped_in_db = 0;
try {
    $total_scraped_in_db = $pdo->query("SELECT COUNT(*) FROM scraper_posts")->fetchColumn();
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facebook Scraper & Auto-Bot Diagnostics Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #0f172a; color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-bottom: 50px; }
        .card { background-color: #1e293b; border: 1px solid #334155; border-radius: 12px; margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3); }
        .card-header { background-color: rgba(30, 41, 59, 0.8); border-bottom: 1px solid #334155; font-weight: 600; color: #38bdf8; padding: 16px 20px; }
        .form-control, .form-select { background-color: #0f172a; border: 1px solid #475569; color: #f8fafc; border-radius: 8px; }
        .form-control:focus, .form-select:focus { background-color: #0f172a; border-color: #38bdf8; color: #fff; box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25); }
        .btn-primary { background: linear-gradient(135deg, #0284c7, #2563eb); border: none; font-weight: 600; padding: 10px 20px; border-radius: 8px; }
        .btn-primary:hover { background: linear-gradient(135deg, #0369a1, #1d4ed8); }
        .btn-success { background: linear-gradient(135deg, #059669, #10b981); border: none; font-weight: 600; padding: 10px 20px; border-radius: 8px; }
        .log-box { background: #020617; border: 1px solid #1e293b; border-radius: 10px; padding: 16px; font-family: 'Courier New', Courier, monospace; max-height: 350px; overflow-y: auto; font-size: 13px; }
        .log-entry { margin-bottom: 6px; padding: 4px 8px; border-radius: 4px; }
        .log-info { color: #94a3b8; }
        .log-success { color: #4ade80; background: rgba(74, 222, 128, 0.1); }
        .log-error { color: #f87171; background: rgba(248, 113, 113, 0.1); }
        .post-card { background: #0f172a; border: 1px solid #334155; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .post-img { max-width: 120px; max-height: 120px; object-fit: cover; border-radius: 8px; margin-right: 12px; }
        .badge-video { background: #ec4899; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; }
        .badge-photo { background: #3b82f6; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary pb-3">
        <div>
            <h2 class="fw-bold text-info m-0">🛠️ Facebook Scraper Diagnostics Test</h2>
            <p class="text-muted m-0 fs-6">Công cụ chạy thử nghiệm Quét bài viết Facebook & Tự động Đăng bài (Auto-Bot)</p>
        </div>
        <div>
            <span class="badge bg-secondary fs-6">PHP <?= phpversion() ?></span>
            <span class="badge bg-primary fs-6">cURL: <?= extension_loaded('curl') ? 'Sẵn sàng' : 'Chưa bật' ?></span>
            <span class="badge bg-success fs-6">Tổng bài lưu DB: <?= number_format($total_scraped_in_db) ?></span>
        </div>
    </div>

    <!-- Diagnostic Console Log -->
    <?php if (!empty($log_output)): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>📋 Nhật Ký Thực Thi (Console Log)</span>
            <span class="badge bg-info text-dark"><?= count($log_output) ?> sự kiện</span>
        </div>
        <div class="card-body">
            <div class="log-box">
                <?php foreach ($log_output as $l): ?>
                    <div class="log-entry log-<?= $l['type'] ?>">
                        <span class="opacity-50">[<?= $l['time'] ?>]</span> <?= $l['msg'] ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Test Scraper Form -->
        <div class="col-md-7">
            <div class="card h-100">
                <div class="card-header">
                    🔍 1. Quét Bài Viết Nguồn (Test Scrape Fanpage)
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="test_scrape">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">ID / Username Page Nguồn Facebook:</label>
                            <input type="text" name="target_page" class="form-control" value="TatDiepBeautySalonQ3" placeholder="Ví dụ: TatDiepBeautySalonQ3 hoặc 10006354890..." required>
                            <div class="form-text text-muted">Có thể nhập Username hoặc Page ID dạng số của Facebook.</div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">User Quản Lý Token:</label>
                                <select name="user_id" class="form-select">
                                    <?php if (empty($system_users)): ?>
                                        <option value="0">Chưa có User nào trong hệ thống</option>
                                    <?php else: ?>
                                        <?php foreach ($system_users as $u): ?>
                                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (ID: <?= $u['id'] ?>)</option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Số bài cần quét (Limit):</label>
                                <input type="number" name="limit" class="form-control" value="10" min="1" max="50">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Facebook Access Token Tùy Chọn (Nếu cần):</label>
                            <input type="text" name="custom_token" class="form-control" placeholder="Để trống nếu muốn tự lấy Token của User trên">
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            🚀 Chạy Quét Thử & Lưu Vào CSDL
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Test Bot Auto-Post Form -->
        <div class="col-md-5">
            <div class="card h-100">
                <div class="card-header">
                    🤖 2. Chạy Thử Auto-Bot Repost
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="test_run_bot">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Chọn BOT Cần Chạy:</label>
                            <select name="bot_id" class="form-select" required>
                                <?php if (empty($system_bots)): ?>
                                    <option value="0">Chưa có BOT nào được tạo</option>
                                <?php else: ?>
                                    <?php foreach ($system_bots as $b): ?>
                                        <option value="<?= $b['id'] ?>">#<?= $b['id'] ?> - <?= htmlspecialchars($b['name']) ?> (Trạng thái: <?= $b['status'] ?>)</option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Account ID (Tài khoản hệ thống):</label>
                            <input type="number" name="account_id" class="form-control" value="1" required>
                        </div>

                        <button type="submit" class="btn btn-success w-100" <?= empty($system_bots) ? 'disabled' : '' ?>>
                            ⚡ Kích Hoạt BOT Tự Động Theo Dõi & Đăng Bài
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scraped Posts Results Display -->
    <?php if (!empty($scraped_posts)): ?>
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>📰 Kết Quả Bài Viết Đã Quét Được (<?= count($scraped_posts) ?> bài)</span>
            <span class="badge bg-success">Đã lưu vào `scraper_posts`</span>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($scraped_posts as $p): ?>
                <div class="col-md-6">
                    <div class="post-card">
                        <div class="d-flex align-items-start">
                            <?php if ($p['picture']): ?>
                                <img src="<?= htmlspecialchars($p['picture']) ?>" class="post-img" alt="Post media">
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="<?= $p['is_video'] ? 'badge-video' : 'badge-photo' ?>">
                                        <?= $p['is_video'] ? '🎬 Video / Reel' : '🖼️ Hình Ảnh / Text' ?>
                                    </span>
                                    <small class="text-muted"><?= $p['created_at'] ?></small>
                                </div>
                                <p class="text-light mb-2 fs-6" style="white-space: pre-line; max-height: 90px; overflow: hidden; text-overflow: ellipsis;">
                                    <?= htmlspecialchars($p['message'] ?: '(Không có nội dung chữ)') ?>
                                </p>
                                <div class="d-flex gap-3 text-muted fs-7">
                                    <span>👍 <?= number_format($p['likes']) ?></span>
                                    <span>💬 <?= number_format($p['comments']) ?></span>
                                    <span>🔗 <?= number_format($p['shares']) ?></span>
                                    <span class="ms-auto text-info">ID: <?= htmlspecialchars($p['id']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
