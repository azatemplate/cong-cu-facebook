<?php
// cron/start_scraper.php
// Dispatcher: Quét thông tin bài viết định kỳ cho Facebook Scraper.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

// Tìm các page có auto_refresh_hours > 0 và cần quét lại
$sql = "
    SELECT id, account_id, user_id, page_id, access_token, auto_refresh_hours, last_scraped_at, post_count, only_with_content
    FROM scraper_pages
    WHERE auto_refresh_hours > 0
      AND access_token IS NOT NULL
      AND (
          last_scraped_at IS NULL 
          OR last_scraped_at <= DATE_SUB(NOW(), INTERVAL auto_refresh_hours HOUR)
      )
";

$stmt = $pdo->prepare($sql);
if (!$stmt) {
    echo "Loi prepare SQL cho Scraper"; exit;
}
if (!$stmt->execute()) {
    echo "Loi execute SQL cho Scraper"; exit;
}

$pagesToScrape = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pagesToScrape)) {
    echo "Khong co Fanpage nao can Auto Scrape tai thoi diem nay.\n";
    return; // Dùng return để không dừng luồng chính nếu file được require
}

echo "Co " . count($pagesToScrape) . " Fanpage can Auto Scrape.\n";

// Helper function giải mã token (giống form trong facebook_scraper.php)
if (!function_exists('decryptData')) {
    function decryptData($encrypted_data) {
        if (empty($encrypted_data)) return '';
        $encryption_key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'YOUR_SECRET_KEY';
        list($encrypted_data, $iv) = array_pad(explode('::', base64_decode($encrypted_data), 2), 2, null);
        if ($iv === null) return '';
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $encryption_key, 0, $iv);
    }
}

foreach ($pagesToScrape as $pageInfo) {
    $page_id = $pageInfo['page_id'];
    $account_id = $pageInfo['account_id'];
    $encrypted_token = $pageInfo['access_token'];
    
    $token = decryptData($encrypted_token);
    if (!$token) {
        echo "  -> Bo qua Page ID $page_id vi Token giang ma loi.\n";
        continue;
    }

    echo "  -> Tien hanh Scrape Page ID $page_id...\n";

    // Build API request
    $fields = 'id,message,created_time,full_picture,shares,comments.summary(total_count),reactions.summary(total_count)';
    $limit = $pageInfo['post_count'] > 0 ? $pageInfo['post_count'] : 10;
    $only_with_content = intval($pageInfo['only_with_content'] ?? 0);

    $stmtPost = $pdo->prepare("
        INSERT INTO scraper_posts (page_id, fb_post_id, message, picture, shares, comments, likes, post_created_at)
        VALUES (:pid, :fbid, :msg, :pic, :sha, :com, :lik, :c_at)
        ON DUPLICATE KEY UPDATE message=:msg, picture=:pic, shares=:sha, comments=:com, likes=:lik
    ");

    $count = 0;
    $batchSize = 10;
    $nextUrl = null;
    $maxPages = 10;
    $pageNum = 0;

    while ($count < $limit && $pageNum < $maxPages) {
        $pageNum++;

        if ($nextUrl) {
            $res = fb_api_request_url($nextUrl);
        } else {
            $res = fb_api_request("{$page_id}/posts", [
                'access_token' => $token,
                'fields'       => $fields,
                'limit'        => $batchSize
            ]);
        }

        if ($res['status_code'] !== 200) {
            if ($pageNum === 1) {
                $errMsg = $res['data']['error']['message'] ?? 'Loi khong the xac dinh';
                echo "     [API ERROR] Page $page_id: $errMsg\n";
            }
            break;
        }

        $postsData = $res['data']['data'] ?? [];
        if (empty($postsData)) {
            if ($pageNum === 1) {
                echo "     [INFO] Page $page_id khong co post nao.\n";
                $stmtUpd = $pdo->prepare("UPDATE scraper_pages SET last_scraped_at = NOW() WHERE id = :id");
                $stmtUpd->execute(['id' => $pageInfo['id']]);
            }
            break;
        }

        foreach ($postsData as $post) {
            $fbid = $post['id'] ?? '';
            if (!$fbid) continue;
            
            $msg = $post['message'] ?? '';

            // Lọc bài không có nội dung nếu bật only_with_content
            if ($only_with_content && trim($msg) === '') {
                continue;
            }

            $c_at = $post['created_time'] ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : null;
            $pic = $post['full_picture'] ?? '';
            $sha = $post['shares']['count'] ?? 0;
            $com = $post['comments']['summary']['total_count'] ?? 0;
            $lik = $post['reactions']['summary']['total_count'] ?? ($post['likes']['summary']['total_count'] ?? 0);

            try {
                $stmtPost->execute([
                    'pid' => $page_id,
                    'fbid' => $fbid,
                    'msg' => $msg,
                    'pic' => $pic,
                    'sha' => $sha,
                    'com' => $com,
                    'lik' => $lik,
                    'c_at' => $c_at
                ]);
                $count++;
            } catch (Exception $e) {
                // Ignore single db insert error and continue
            }

            if ($count >= $limit) break;
        }

        // Đã đủ số lượng thì dừng
        if ($count >= $limit) break;

        // Lấy URL trang tiếp theo
        $nextUrl = $res['data']['paging']['next'] ?? null;
        if (!$nextUrl) break;
    }

    // Update scraper_pages post_count and last_scraped_at
    $stmtUpdatePostCount = $pdo->prepare("UPDATE scraper_pages SET post_count = :count, last_scraped_at = NOW() WHERE id = :id");
    $stmtUpdatePostCount->execute(['count' => $count, 'id' => $pageInfo['id']]);

    echo "     [SUCCESS] Da cao va insert $count bai viet cho Page $page_id.\n";
}

echo "Scraper Dispatch hoan tat.\n";
