<?php
// cron/comment_insights_worker.php
// Checks published Reels/Videos that have comment_mode='insights' and comment_status='waiting_insights'
// Uses Facebook Graph API video_insights to check if the post meets thresholds (views, likes, comments)
// If thresholds are met, posts a comment immediately.
// Designed to be called by cron every 5 minutes.
//
// Crontab: */5 * * * * php /path/to/cron/comment_insights_worker.php
//
// Reference: https://developers.facebook.com/docs/graph-api/reference/video/video_insights#reels-metrics

ignore_user_abort(true);
set_time_limit(0);

$lock_file = sys_get_temp_dir() . "/facebook_comment_insights_worker.lock";

// Clean stale lock (> 15 min)
if (file_exists($lock_file) && (time() - filemtime($lock_file)) > 900) {
    @unlink($lock_file);
    echo "[!] Lock file cũ > 15 phút, đã dọn sạch.\n";
}

$lock_fp = @fopen($lock_file, 'c');
if (!$lock_fp) {
    echo "Không mở được lock file. Bỏ qua.\n";
    exit;
}

$lock_got = false;
for ($i = 0; $i < 3; $i++) {
    if (flock($lock_fp, LOCK_EX | LOCK_NB)) { $lock_got = true; break; }
    sleep(1);
}
if (!$lock_got) {
    echo "Tiến trình comment_insights_worker đang chạy, bỏ qua.\n";
    fclose($lock_fp);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/telegram.php';

echo "\n=== Comment Insights Worker ===\n";
echo "Thời gian: " . date('Y-m-d H:i:s') . "\n";

// Detect if comment_status column exists (for backward compat)
$has_comment_status = false;
try {
    $col_q = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME='comment_status'");
    $has_comment_status = ($col_q && $col_q->fetchColumn() > 0);
} catch (Exception $e) {}

// Fetch all posts waiting for insights check
try {
    $stmt = $pdo->prepare("
        SELECT sp.id, sp.fb_post_id, sp.comment_lines, sp.page_id, sp.post_type,
               sp.comment_threshold_views, sp.comment_threshold_likes, sp.comment_threshold_comments,
               sp.account_id, sp.scheduled_time
        FROM scheduled_posts sp
        WHERE sp.status = 'published'
          AND sp.comment_mode = 'insights'
          AND sp.comment_status = 'waiting_insights'
          AND sp.comment_lines IS NOT NULL
          AND sp.comment_done = 0
          AND sp.fb_post_id IS NOT NULL
          AND sp.fb_post_id != ''
        ORDER BY sp.id ASC
        LIMIT 100
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Lỗi truy vấn: " . $e->getMessage() . "\n";
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    @unlink($lock_file);
    exit;
}

if (empty($rows)) {
    echo "Không có bài nào cần kiểm tra insights.\n";
    echo "==============================\n";
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    @unlink($lock_file);
    exit;
}

echo "Tìm thấy " . count($rows) . " bài cần kiểm tra insights.\n\n";

foreach ($rows as $row) {
    $fb_post_id = $row['fb_post_id'];
    $threshold_views = (int)($row['comment_threshold_views'] ?? 1000);
    $threshold_likes = (int)($row['comment_threshold_likes'] ?? 10);
    $threshold_comments = (int)($row['comment_threshold_comments'] ?? 5);

    echo "─ ID {$row['id']} | fb_post_id: $fb_post_id\n";
    echo "  Ngưỡng: View≥$threshold_views, Like≥$threshold_likes, Comment≥$threshold_comments\n";

    // Check expiration (24h)
    $scheduled_time = $row['scheduled_time'];
    if ($scheduled_time) {
        $elapsed_seconds = time() - strtotime($scheduled_time);
        if ($elapsed_seconds > 86400) { // 24 hours
            echo "  ⏳ Quá 24h kể từ khi đăng ($scheduled_time). Ngừng theo dõi bài này.\n\n";
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'expired_insights' WHERE id = ?")
                ->execute([$row['id']]);
            continue;
        }
    }

    // Get page access token
    try {
        $p_stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
        $p_stmt->execute([$row['page_id']]);
        $page = $p_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $page = null;
    }

    if (!$page || empty($page['access_token'])) {
        echo "  ✗ Không tìm thấy token cho page_id: {$row['page_id']}. Bỏ qua.\n\n";
        continue;
    }

    $page_access_token = decryptData($page['access_token']);

    // ── Step 1: Get video_insights for views ──────────────────────────────
    // For Reels: use blue_reels_play_count (play count excluding replays)
    // For Videos: use total_video_views
    $is_reel = ($row['post_type'] === 'Reel');
    $view_metric = $is_reel ? 'blue_reels_play_count' : 'total_video_views';

    // Query video insights for view count
    $insights_response = fb_api_request(
        $fb_post_id . '/video_insights',
        [
            'metric' => $view_metric,
            'period' => 'lifetime',
            'access_token' => $page_access_token
        ]
    );

    $current_views = 0;
    if ($insights_response['status_code'] === 200 && !empty($insights_response['data']['data'])) {
        foreach ($insights_response['data']['data'] as $metric) {
            if ($metric['name'] === $view_metric && isset($metric['values'][0]['value'])) {
                $current_views = (int)$metric['values'][0]['value'];
                break;
            }
        }
    } else {
        $err = $insights_response['data']['error']['message'] ?? json_encode($insights_response['data'] ?? []);
        echo "  ⚠ Lỗi lấy video_insights (views): $err\n";
        // Try alternate metric if reel metric fails (fallback)
        if ($is_reel) {
            $fallback = fb_api_request(
                $fb_post_id . '/video_insights',
                ['metric' => 'total_video_views', 'period' => 'lifetime', 'access_token' => $page_access_token]
            );
            if ($fallback['status_code'] === 200 && !empty($fallback['data']['data'])) {
                foreach ($fallback['data']['data'] as $metric) {
                    if ($metric['name'] === 'total_video_views' && isset($metric['values'][0]['value'])) {
                        $current_views = (int)$metric['values'][0]['value'];
                        echo "  ↳ Fallback total_video_views: $current_views\n";
                        break;
                    }
                }
            }
        }
    }

    // ── Step 2: Get likes and comments from post object ───────────────────
    // Use the Graph API to get reactions and comments count.
    // For Videos/Reels, $fb_post_id is just a numeric string. The actual Post ID is usually {page_id}_{video_id}
    $target_id = $fb_post_id;
    if (strpos($target_id, '_') === false) {
        $target_id = $row['page_id'] . '_' . $fb_post_id;
    }

    $post_response = fb_api_request(
        $target_id,
        [
            'fields' => 'reactions.summary(true),likes.summary(true),comments.summary(true)',
            'access_token' => $page_access_token
        ]
    );

    // If fetching post edge fails (e.g. some api edge cases), try raw video id with likes only
    if ($post_response['status_code'] !== 200 && strpos($target_id, '_') !== false) {
        $post_response = fb_api_request(
            $fb_post_id,
            [
                'fields' => 'likes.summary(true),comments.summary(true)',
                'access_token' => $page_access_token
            ]
        );
    }

    $current_likes = 0;
    $current_comments_count = 0;

    if ($post_response['status_code'] === 200) {
        $reacts = (int)($post_response['data']['reactions']['summary']['total_count'] ?? 0);
        $likes = (int)($post_response['data']['likes']['summary']['total_count'] ?? 0);
        $current_likes = max($reacts, $likes); // Lấy số lớn nhất từ likes hoặc reactions
        $current_comments_count = (int)($post_response['data']['comments']['summary']['total_count'] ?? 0);
    } else {
        $err = $post_response['data']['error']['message'] ?? json_encode($post_response['data'] ?? []);
        echo "  ⚠ Lỗi lấy reactions/comments: $err\n";

        // Try video-specific insights for likes as fallback
        $like_insights = fb_api_request(
            $fb_post_id . '/video_insights',
            ['metric' => 'post_video_likes_by_reaction_type,post_video_social_actions', 'period' => 'lifetime', 'access_token' => $page_access_token]
        );

        if ($like_insights['status_code'] === 200 && !empty($like_insights['data']['data'])) {
            foreach ($like_insights['data']['data'] as $metric) {
                if ($metric['name'] === 'post_video_likes_by_reaction_type' && isset($metric['values'][0]['value'])) {
                    // This returns an object like {"like": 5, "love": 2, ...}
                    $reactions = $metric['values'][0]['value'];
                    if (is_array($reactions)) {
                        $current_likes = array_sum($reactions);
                    }
                }
                if ($metric['name'] === 'post_video_social_actions' && isset($metric['values'][0]['value'])) {
                    // This returns {"comment": X, "share": Y}
                    $actions = $metric['values'][0]['value'];
                    if (is_array($actions)) {
                        $current_comments_count = (int)($actions['comment'] ?? 0);
                    }
                }
            }
        }
    }

    echo "  📊 Hiện tại: View=$current_views, Like=$current_likes, Comment=$current_comments_count\n";

    // ── Step 3: Check if all thresholds are met ──────────────────────────
    $views_ok = ($current_views >= $threshold_views);
    $likes_ok = ($current_likes >= $threshold_likes);
    $comments_ok = ($current_comments_count >= $threshold_comments);

    if (!$views_ok || !$likes_ok || !$comments_ok) {
        $missing = [];
        if (!$views_ok) $missing[] = "View ($current_views/$threshold_views)";
        if (!$likes_ok) $missing[] = "Like ($current_likes/$threshold_likes)";
        if (!$comments_ok) $missing[] = "Comment ($current_comments_count/$threshold_comments)";
        echo "  ⏳ Chưa đủ điều kiện: " . implode(', ', $missing) . "\n\n";
        continue;
    }

    echo "  ✅ ĐẠT ĐỦ điều kiện! Tiến hành bình luận...\n";

    // ── Step 4: Post comment ─────────────────────────────────────────────
    // Lock the row to prevent duplicate commenting
    $lock_stmt = $pdo->prepare("UPDATE scheduled_posts SET comment_done = 2 WHERE id = ? AND comment_done = 0");
    $lock_stmt->execute([$row['id']]);
    if ($lock_stmt->rowCount() === 0) {
        echo "  ⚠ Row đã bị xử lý bởi tiến trình khác. Bỏ qua.\n\n";
        continue;
    }

    // Pick random comment line
    $lines = array_values(array_filter(array_map('trim', explode("\n", $row['comment_lines']))));
    if (empty($lines)) {
        $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
            ->execute([$row['id']]);
        echo "  ✗ Không có nội dung bình luận. Bỏ qua.\n\n";
        continue;
    }
    $comment_text = $lines[array_rand($lines)];

    // Post the comment
    $response = fb_api_request(
        $fb_post_id . '/comments',
        ['access_token' => $page_access_token],
        'POST',
        ['message' => $comment_text]
    );

    if ($response['status_code'] === 200 && isset($response['data']['id'])) {
        $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'done', comment_at = NOW() WHERE id = ?")
            ->execute([$row['id']]);
        echo "  🎉 Bình luận thành công! Comment ID: {$response['data']['id']}\n";
        echo "  📝 Nội dung: \"$comment_text\"\n\n";

        // Gửi thông báo Telegram
        send_telegram_notification($pdo, $row['account_id'], "<b>Video đủ điều kiện bình luận!</b>\n🎬 Video: {$fb_post_id}\n📊 View: {$current_views}, Like: {$current_likes}, Comment: {$current_comments_count}\n📝 Bình luận: \"{$comment_text}\"", 'comment');

        // Gửi thông báo lên chuông (bell notification)
        try {
            $notif_snippet = json_encode([
                'type' => 'success',
                'video_id' => $fb_post_id,
                'content' => $comment_text
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_name, snippet, post_id, comment_id) VALUES (?, 'insights_comment', 'Hệ thống', ?, ?, ?)")
                ->execute([$row['page_id'], $notif_snippet, $fb_post_id, $response['data']['id']]);
        } catch (Exception $e) {
            echo "  ⚠ Không gửi được thông báo: " . $e->getMessage() . "\n";
        }
    } else {
        $err = $response['data']['error']['message'] ?? json_encode($response['data'] ?? []);
        $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
            ->execute([$row['id']]);
        echo "  ✗ Lỗi bình luận: $err\n\n";

        // Gửi thông báo lỗi Telegram
        $short_err = mb_strimwidth($err, 0, 100, '…');
        send_telegram_notification($pdo, $row['account_id'], "<b>Lỗi bình luận!</b>\n🎬 Video: {$fb_post_id}\n❌ Lỗi: {$short_err}", 'error');

        // Gửi thông báo lỗi lên chuông
        try {
            $notif_err_snippet = json_encode([
                'type' => 'error',
                'video_id' => $fb_post_id,
                'error' => mb_strimwidth($err, 0, 100, '…')
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_name, snippet, post_id) VALUES (?, 'insights_comment', 'Hệ thống', ?, ?)")
                ->execute([$row['page_id'], $notif_err_snippet, $fb_post_id]);
        } catch (Exception $e) {}
    }
}

echo "==============================\n";

// Release lock
if ($lock_fp) {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
}
@unlink($lock_file);
?>
