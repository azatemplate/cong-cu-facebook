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

// Ghi nhận thời gian chạy vào DB để theo dõi cron
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_insights_cron_run', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()")->execute();
} catch (Exception $e) {}

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
        ORDER BY sp.updated_at ASC
        LIMIT " . (php_sapi_name() === 'cli' ? '100' : '20') . "
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
    
    // Đánh dấu bài này vừa được kiểm tra (để nó xuống cuối hàng đợi ở lần chạy sau)
    $pdo->prepare("UPDATE scheduled_posts SET updated_at = NOW() WHERE id = ?")->execute([$row['id']]);
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

    // ── Step 1: Get views ──────────────────────────────────────────────
    $is_reel = ($row['post_type'] === 'Reel');
    $view_metric = $is_reel ? 'blue_reels_play_count' : 'total_video_views';
    $current_views = 0;

    $insights_response = fb_api_request(
        $fb_post_id . '/video_insights',
        [
            'metric' => $view_metric,
            'period' => 'lifetime',
            'access_token' => $page_access_token
        ]
    );

    if ($insights_response['status_code'] === 200 && !empty($insights_response['data']['data'])) {
        foreach ($insights_response['data']['data'] as $metric) {
            if ($metric['name'] === $view_metric && isset($metric['values'][0]['value'])) {
                $current_views = (int)$metric['values'][0]['value'];
                break;
            }
        }
    } else {
        // Fallback 1: Try alternate metric
        $alt_metric = $is_reel ? 'total_video_views' : 'blue_reels_play_count';
        $fallback = fb_api_request(
            $fb_post_id . '/video_insights',
            ['metric' => $alt_metric, 'period' => 'lifetime', 'access_token' => $page_access_token]
        );
        if ($fallback['status_code'] === 200 && !empty($fallback['data']['data'])) {
            foreach ($fallback['data']['data'] as $metric) {
                if ($metric['name'] === $alt_metric && isset($metric['values'][0]['value'])) {
                    $current_views = (int)$metric['values'][0]['value'];
                    echo "  ↳ Fallback $alt_metric: $current_views\n";
                    break;
                }
            }
        }
    }

    // Fallback 2: Check video object directly for views if still 0
    if ($current_views === 0) {
        $vid_res = fb_api_request($fb_post_id, ['fields' => 'views,video_view_count', 'access_token' => $page_access_token]);
        if ($vid_res['status_code'] === 200) {
            $v1 = (int)($vid_res['data']['views'] ?? 0);
            $v2 = (int)($vid_res['data']['video_view_count'] ?? 0);
            $current_views = max($v1, $v2);
            if ($current_views > 0) echo "  ↳ Lấy views trực tiếp từ Video object: $current_views\n";
        }
    }

    // ── Step 2: Get likes and comments from post object ───────────────────
    $current_likes = 0;
    $current_comments_count = 0;

    // Try multiple formats for Post ID
    $id_formats = [
        $row['page_id'] . '_' . $fb_post_id, // Format: pageid_videoid
        $fb_post_id                          // Format: videoid
    ];

    foreach ($id_formats as $target_id) {
        $post_response = fb_api_request(
            $target_id,
            [
                'fields' => 'reactions.summary(true),likes.summary(true),comments.summary(true)',
                'access_token' => $page_access_token
            ]
        );

        if ($post_response['status_code'] === 200) {
            $reacts = (int)($post_response['data']['reactions']['summary']['total_count'] ?? 0);
            $likes = (int)($post_response['data']['likes']['summary']['total_count'] ?? 0);
            $current_likes = max($reacts, $likes);
            $current_comments_count = (int)($post_response['data']['comments']['summary']['total_count'] ?? 0);
            
            if ($current_likes > 0 || $current_comments_count > 0) {
                echo "  ↳ Lấy được dữ liệu từ ID format: $target_id\n";
                break;
            }
        }
    }

    // Fallback: Try video-specific insights for social actions
    if ($current_likes === 0 && $current_comments_count === 0) {
        $like_insights = fb_api_request(
            $fb_post_id . '/video_insights',
            ['metric' => 'post_video_likes_by_reaction_type,post_video_social_actions', 'period' => 'lifetime', 'access_token' => $page_access_token]
        );

        if ($like_insights['status_code'] === 200 && !empty($like_insights['data']['data'])) {
            foreach ($like_insights['data']['data'] as $metric) {
                if ($metric['name'] === 'post_video_likes_by_reaction_type' && isset($metric['values'][0]['value'])) {
                    $reactions = $metric['values'][0]['value'];
                    if (is_array($reactions)) $current_likes = array_sum($reactions);
                }
                if ($metric['name'] === 'post_video_social_actions' && isset($metric['values'][0]['value'])) {
                    $actions = $metric['values'][0]['value'];
                    if (is_array($actions)) $current_comments_count = (int)($actions['comment'] ?? 0);
                }
            }
            if ($current_likes > 0) echo "  ↳ Lấy likes từ video_insights fallback: $current_likes\n";
        } else {
            $err = $like_insights['data']['error']['message'] ?? json_encode($like_insights['data'] ?? []);
            echo "  ⚠ Lỗi fallback video_insights: $err\n";
        }
    }
    
    if ($current_likes === 0) {
        // Log the failure to help debugging
        echo "  ⚠ Không lấy được số Like từ bất kỳ phương thức nào (API trả về 0 hoặc lỗi).\n";
    }

    // Fallback 3: Using 'engagement' field (Universal count)
    if ($current_likes === 0) {
        $eng_res = fb_api_request($fb_post_id, ['fields' => 'engagement', 'access_token' => $page_access_token]);
        if ($eng_res['status_code'] === 200 && isset($eng_res['data']['engagement'])) {
            $current_likes = (int)($eng_res['data']['engagement']['reaction_count'] ?? 0);
            if ($current_likes > 0) echo "  ↳ Lấy likes từ engagement field: $current_likes\n";
        }
    }

    echo "  📊 Kết quả: View=$current_views, Like=$current_likes, Comment=$current_comments_count\n";

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
