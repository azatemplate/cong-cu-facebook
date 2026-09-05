<?php
define('CRON_RUNNING', true);

// cron/start_scraper.php
// Dispatcher: Quét thông tin bài viết định kỳ & Tự động chạy Auto-Bots cho Facebook Scraper.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/db.php';
if (!function_exists('is_post_within_range')) {
    function is_post_within_range($created_time, $range_filter) {
        if (empty($range_filter) || $range_filter === 'all') {
            return true;
        }
        if (empty($created_time)) {
            return true;
        }

        $post_ts = is_numeric($created_time) ? intval($created_time) : strtotime($created_time);
        if (!$post_ts) return true;
        $now = time();

        switch ($range_filter) {
            case '1h':
                return ($now - $post_ts) <= 3600;
            case '2h':
                return ($now - $post_ts) <= 7200;
            case '3h':
                return ($now - $post_ts) <= 10800;
            case '6h':
                return ($now - $post_ts) <= 21600;
            case '12h':
                return ($now - $post_ts) <= 43200;
            case '24h':
            case '1d':
                return ($now - $post_ts) <= 86400;
            case '3d':
                return ($now - $post_ts) <= (3 * 86400);
            case '7d':
                return ($now - $post_ts) <= (7 * 86400);
            case '30d':
                return ($now - $post_ts) <= (30 * 86400);
            case 'today':
                return $post_ts >= strtotime('today midnight');
            default:
                if (preg_match('/^(\d+)h$/i', $range_filter, $m)) {
                    return ($now - $post_ts) <= (intval($m[1]) * 3600);
                }
                if (preg_match('/^(\d+)d$/i', $range_filter, $m)) {
                    return ($now - $post_ts) <= (intval($m[1]) * 86400);
                }
                return true;
        }
    }
}

require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../facebook_scraper.php';

// 1. Tìm các page có auto_refresh_hours > 0 và cần quét lại
$sql = "
    SELECT sp.id, sp.account_id, sp.user_id, sp.page_id, sp.auto_refresh_hours, sp.last_scraped_at, sp.post_count, sp.only_with_content, u.access_token as user_token
    FROM scraper_pages sp
    JOIN users u ON sp.user_id = u.id
    JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.auto_refresh_hours > 0
      AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
      AND (
          sp.last_scraped_at IS NULL 
          OR sp.last_scraped_at <= DATE_SUB(NOW(), INTERVAL sp.auto_refresh_hours HOUR)
      )
";

$stmt = $pdo->prepare($sql);
if ($stmt && $stmt->execute()) {
    $pagesToScrape = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($pagesToScrape)) {
        echo "Co " . count($pagesToScrape) . " Fanpage can Auto Scrape.
";
        foreach ($pagesToScrape as $pageInfo) {
            $page_id = $pageInfo['page_id'];
            $account_id = $pageInfo['account_id'];
            $encrypted_token = $pageInfo['user_token'];
            
            $token = decryptData($encrypted_token);
            if (!$token) {
                echo "  -> Bo qua Page ID $page_id vi Token giang ma loi.
";
                continue;
            }

            echo "  -> Tien hanh Scrape Page ID $page_id...
";
            $fields = 'id,object_id,message,created_time,full_picture,attachments{media,media_type,subattachments,target,type,url},shares,comments.summary(total_count),reactions.summary(total_count)';
            $limit = $pageInfo['post_count'] > 0 ? $pageInfo['post_count'] : 10;
            $only_with_content = intval($pageInfo['only_with_content'] ?? 0);

            try {
                $pdo->exec("ALTER TABLE scraper_posts MODIFY COLUMN picture TEXT");
            } catch (Exception $e) {}

            try {
                $col = $pdo->query("SHOW COLUMNS FROM scraper_posts LIKE 'video_url'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_posts ADD COLUMN video_url TEXT");
                }
                $col = $pdo->query("SHOW COLUMNS FROM scraper_posts LIKE 'post_type'");
                if ($col && $col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE scraper_posts ADD COLUMN post_type VARCHAR(20) DEFAULT 'photo'");
                }
            } catch (Exception $e_pcols) {}

            $stmtPost = $pdo->prepare("
                INSERT INTO scraper_posts (page_id, fb_post_id, message, picture, video_url, post_type, shares, comments, likes, post_created_at)
                VALUES (:pid, :fbid, :msg, :pic, :vurl, :ptype, :sha, :com, :lik, :c_at)
                ON DUPLICATE KEY UPDATE message=:msg, picture=:pic, video_url=:vurl, post_type=:ptype, shares=:sha, comments=:com, likes=:lik
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
                        echo "     [API ERROR] Page $page_id: $errMsg
";
                    }
                    break;
                }

                $postsData = $res['data']['data'] ?? [];
                if (empty($postsData)) {
                    if ($pageNum === 1) {
                        echo "     [INFO] Page $page_id khong co post nao.
";
                        $stmtUpd = $pdo->prepare("UPDATE scraper_pages SET last_scraped_at = NOW() WHERE id = :id");
                        $stmtUpd->execute(['id' => $pageInfo['id']]);
                    }
                    break;
                }

                foreach ($postsData as $post) {
                    $fbid = $post['id'] ?? '';
                    if (!$fbid) continue;
                    $msg = $post['message'] ?? '';

                    if ($only_with_content && trim($msg) === '') {
                        continue;
                    }

                    $c_at = $post['created_time'] ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : null;
                    $pic = $post['full_picture'] ?? '';
                    
                    $attachments = $post['attachments']['data'][0] ?? null;
                    $media_type = $attachments['media_type'] ?? '';
                    $attach_type = $attachments['type'] ?? '';
                    $vurl = $attachments['media']['source'] ?? '';
                    $target_id = $attachments['target']['id'] ?? '';

                    if (($media_type === 'video' || strpos(strtolower($attach_type), 'video') !== false) && empty($vurl) && !empty($target_id)) {
                        $v_res = fb_api_request("{$target_id}", ['access_token' => $token, 'fields' => 'source']);
                        if (!empty($v_res['data']['source'])) {
                            $vurl = $v_res['data']['source'];
                        }
                    }

                    $ptype = 'text';
                    if ($media_type === 'video' || !empty($vurl)) {
                        $ptype = (strpos(strtolower($attach_type), 'reel') !== false) ? 'reel' : 'video';
                    } elseif (!empty($pic)) {
                        $ptype = 'photo';
                    }

                    $sha = $post['shares']['count'] ?? 0;
                    $com = $post['comments']['summary']['total_count'] ?? 0;
                    $lik = $post['reactions']['summary']['total_count'] ?? ($post['likes']['summary']['total_count'] ?? 0);

                    try {
                        $stmtPost->execute([
                            'pid' => $page_id, 'fbid' => $fbid, 'msg' => $msg, 'pic' => $pic,
                            'vurl' => $vurl, 'ptype' => $ptype, 'sha' => $sha, 'com' => $com,
                            'lik' => $lik, 'c_at' => $c_at
                        ]);
                        $count++;
                    } catch (Exception $e) {}

                    if ($count >= $limit) break;
                }

                if ($count >= $limit) break;
                $nextUrl = $res['data']['paging']['next'] ?? null;
                if (!$nextUrl) break;
            }

            $stmtUpdatePostCount = $pdo->prepare("UPDATE scraper_pages SET post_count = :count, last_scraped_at = NOW() WHERE id = :id");
            $stmtUpdatePostCount->execute(['count' => $count, 'id' => $pageInfo['id']]);
            echo "     [SUCCESS] Da cao va insert $count bai viet cho Page $page_id.
";
        }
    }
}

// 2. Tự động kiểm tra và chạy ngầm các Auto-Bot đã thiết lập
echo "Dang kiem tra danh sach Auto-Bots can chay ngam...
";
try {
    $stmtBots = $pdo->query("SELECT id, account_id, name, check_interval_seconds, last_run_at, status FROM scraper_bots");
    $allBots = $stmtBots->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $activeBots = [];

    foreach ($allBots as $bItem) {
        if (isset($bItem['status']) && $bItem['status'] === 'stopped') {
            // Keep active if not explicitly stopped
            // continue;
        }
        $interval = max(30, intval($bItem['check_interval_seconds'] ?? 300));
        $last_run = !empty($bItem['last_run_at']) ? strtotime($bItem['last_run_at']) : 0;
        $elapsed = time() - $last_run;

        if ($last_run === 0 || $elapsed >= $interval) {
            $activeBots[] = $bItem;
        }
    }

    echo "Co " . count($activeBots) . " Auto-Bot den luot chay ngam.
";
    foreach ($activeBots as $bot) {
        echo "  -> Dang chay Auto-Bot '{$bot['name']}' (ID: {$bot['id']})...
";
        $botRes = executeScraperBot($pdo, $bot['id'], $bot['account_id']);
        if ($botRes['status'] === 'success') {
            $s = $botRes['summary'] ?? [];
            echo "     [HOAN TAT] Quet: {$s['sources_checked']} nguon | Bai: {$s['posts_found']} | Dang: {$s['posts_published']} | Loi: {$s['posts_failed']}
";
        } else {
            echo "     [LOI] {$botRes['message']}
";
        }
    }
} catch (Exception $eBot) {
    echo "Loi khi chay Auto-Bots: " . $eBot->getMessage() . "
";
}

echo "Scraper Dispatch hoan tat.
";