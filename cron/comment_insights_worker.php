<?php
// cron/comment_insights_worker.php
// OPTIMIZED VERSION: Uses curl_multi for parallel API calls
//
// Checks published Reels/Videos that have comment_mode='insights' and comment_status='waiting_insights'
// Uses Facebook Graph API video_insights to check if the post meets thresholds (views, likes, comments)
// If thresholds are met, posts a comment immediately.
// Designed to be called by cron every 5 minutes.
//
// Performance: With curl_multi, checks ALL waiting posts (even thousands) in minutes
// instead of hours with the old sequential approach.
//
// Crontab: */5 * * * * php /path/to/cron/comment_insights_worker.php

ignore_user_abort(true);
set_time_limit(0);

$lock_file = sys_get_temp_dir() . "/facebook_comment_insights_worker.lock";

// Clean stale lock (> 30 min — tăng từ 15 phút vì giờ xử lý nhiều bài hơn)
if (file_exists($lock_file) && (time() - filemtime($lock_file)) > 1800) {
    @unlink($lock_file);
    echo "[!] Lock file cũ > 30 phút, đã dọn sạch.\n";
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

echo "\n=== Comment Insights Worker (Parallel Mode) ===\n";
echo "Thời gian: " . date('Y-m-d H:i:s') . "\n";

// Ghi nhận thời gian chạy vào DB để theo dõi cron
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_insights_cron_run', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()")->execute();
} catch (Exception $e) {}

// ── Fetch ALL posts waiting for insights check (không giới hạn LIMIT) ─────
try {
    $stmt = $pdo->prepare("
        SELECT sp.id, sp.fb_post_id, sp.comment_lines, sp.page_id, sp.post_type,
               sp.comment_threshold_views, sp.comment_threshold_likes, sp.comment_threshold_comments,
               sp.account_id, sp.scheduled_time
        FROM scheduled_posts sp
        JOIN system_accounts sa ON sp.account_id = sa.id
        WHERE sp.status = 'published'
          AND sp.comment_mode = 'insights'
          AND sp.comment_status = 'waiting_insights'
          AND sp.comment_lines IS NOT NULL
          AND sp.comment_done = 0
          AND sp.fb_post_id IS NOT NULL
          AND sp.fb_post_id != ''
          AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
        ORDER BY sp.id ASC
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

echo "Tìm thấy " . count($rows) . " bài cần kiểm tra insights (chế độ song song).\n\n";

// ── Bước 0: Lọc bài hết hạn 24h trước ──────────────────────────────────────
$active_rows = [];
$expired_ids = [];
foreach ($rows as $row) {
    $scheduled_time = $row['scheduled_time'];
    if ($scheduled_time && (time() - strtotime($scheduled_time)) > 86400) {
        $expired_ids[] = $row['id'];
        echo "─ ID {$row['id']} | ⏳ Quá 24h ($scheduled_time). Ngừng theo dõi.\n";
    } else {
        $active_rows[] = $row;
    }
}

// Batch update expired posts
if (!empty($expired_ids)) {
    $placeholders = implode(',', array_fill(0, count($expired_ids), '?'));
    $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'expired_insights' WHERE id IN ($placeholders)")
        ->execute($expired_ids);
    echo "→ Đã đánh dấu " . count($expired_ids) . " bài hết hạn.\n\n";
}

if (empty($active_rows)) {
    echo "Không còn bài active nào sau khi lọc hết hạn.\n";
    echo "==============================\n";
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    @unlink($lock_file);
    exit;
}

echo "Bài còn active: " . count($active_rows) . "\n";

// ── Bước 1: Thu thập page access tokens ─────────────────────────────────────
$page_ids_needed = array_unique(array_column($active_rows, 'page_id'));
$page_tokens = [];
try {
    $placeholders = implode(',', array_fill(0, count($page_ids_needed), '?'));
    $tok_stmt = $pdo->prepare("SELECT page_id, access_token FROM pages WHERE page_id IN ($placeholders)");
    $tok_stmt->execute(array_values($page_ids_needed));
    while ($t = $tok_stmt->fetch(PDO::FETCH_ASSOC)) {
        $page_tokens[$t['page_id']] = decryptData($t['access_token']);
    }
} catch (Exception $e) {
    echo "Lỗi lấy token: " . $e->getMessage() . "\n";
}

// Lọc bỏ bài không có token
$valid_rows = [];
foreach ($active_rows as $row) {
    if (!empty($page_tokens[$row['page_id']])) {
        $valid_rows[] = $row;
    } else {
        echo "─ ID {$row['id']} | ✗ Không tìm thấy token cho page_id: {$row['page_id']}. Bỏ qua.\n";
    }
}

if (empty($valid_rows)) {
    echo "Không còn bài nào có token hợp lệ.\n";
    echo "==============================\n";
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    @unlink($lock_file);
    exit;
}

echo "Bài có token hợp lệ: " . count($valid_rows) . "\n";

// Touch lock file để tránh bị coi là stale
@touch($lock_file);

// ═══════════════════════════════════════════════════════════════════════════════
// PHA 1: CHECK VIEWS SONG SONG (curl_multi, 50 bài/lượt)
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n── Pha 1: Kiểm tra Views (song song, 50 bài/lượt) ──\n";

$views_data = []; // id => views count
$chunks = array_chunk($valid_rows, 50);

foreach ($chunks as $chunk_idx => $chunk) {
    $multi_curl = curl_multi_init();
    $handles = [];

    foreach ($chunk as $row) {
        $fb_post_id = $row['fb_post_id'];
        $token = $page_tokens[$row['page_id']];
        $is_reel = ($row['post_type'] === 'Reel');
        $view_metric = $is_reel ? 'blue_reels_play_count' : 'total_video_views';

        $url = FB_API_BASE . $fb_post_id . '/video_insights?'
             . http_build_query(['metric' => $view_metric, 'period' => 'lifetime', 'access_token' => $token]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        fb_curl_setssl($ch);
        curl_multi_add_handle($multi_curl, $ch);
        $handles[$row['id']] = ['ch' => $ch, 'row' => $row, 'metric' => $view_metric];
    }

    // Execute all requests in parallel
    $active = null;
    do {
        $mrc = curl_multi_exec($multi_curl, $active);
        if ($active) {
            curl_multi_select($multi_curl, 0.5);
        }
    } while ($active && $mrc == CURLM_OK);

    // Collect results
    foreach ($handles as $id => $info) {
        $response = curl_multi_getcontent($info['ch']);
        $http_code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi_curl, $info['ch']);

        $current_views = 0;
        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (!empty($data['data'])) {
                foreach ($data['data'] as $metric) {
                    if ($metric['name'] === $info['metric'] && isset($metric['values'][0]['value'])) {
                        $current_views = (int)$metric['values'][0]['value'];
                        break;
                    }
                }
            }
        }
        $views_data[$id] = $current_views;
    }
    curl_multi_close($multi_curl);

    echo "  Batch " . ($chunk_idx + 1) . "/" . count($chunks) . ": " . count($chunk) . " bài đã kiểm tra views.\n";

    // Touch lock file giữa các batch
    @touch($lock_file);
}

// ── Fallback: Bài có views = 0, thử alternate metric + video object ─────────
$zero_view_rows = [];
foreach ($valid_rows as $row) {
    if (($views_data[$row['id']] ?? 0) === 0) {
        $zero_view_rows[] = $row;
    }
}

if (!empty($zero_view_rows)) {
    echo "  → " . count($zero_view_rows) . " bài views=0, thử fallback alternate metric...\n";
    $fallback_chunks = array_chunk($zero_view_rows, 50);

    foreach ($fallback_chunks as $chunk) {
        $multi_curl = curl_multi_init();
        $handles = [];

        foreach ($chunk as $row) {
            $fb_post_id = $row['fb_post_id'];
            $token = $page_tokens[$row['page_id']];
            $is_reel = ($row['post_type'] === 'Reel');
            // Dùng metric ngược lại
            $alt_metric = $is_reel ? 'total_video_views' : 'blue_reels_play_count';

            $url = FB_API_BASE . $fb_post_id . '/video_insights?'
                 . http_build_query(['metric' => $alt_metric, 'period' => 'lifetime', 'access_token' => $token]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            fb_curl_setssl($ch);
            curl_multi_add_handle($multi_curl, $ch);
            $handles[$row['id']] = ['ch' => $ch, 'row' => $row, 'metric' => $alt_metric];
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
            if ($active) curl_multi_select($multi_curl, 0.5);
        } while ($active && $mrc == CURLM_OK);

        foreach ($handles as $id => $info) {
            $response = curl_multi_getcontent($info['ch']);
            $http_code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi_curl, $info['ch']);

            if ($http_code === 200) {
                $data = json_decode($response, true);
                if (!empty($data['data'])) {
                    foreach ($data['data'] as $metric) {
                        if ($metric['name'] === $info['metric'] && isset($metric['values'][0]['value'])) {
                            $v = (int)$metric['values'][0]['value'];
                            if ($v > 0) $views_data[$id] = $v;
                            break;
                        }
                    }
                }
            }
        }
        curl_multi_close($multi_curl);
    }

    // Fallback 2: Bài vẫn views=0, thử lấy trực tiếp từ Video object
    $still_zero = [];
    foreach ($zero_view_rows as $row) {
        if (($views_data[$row['id']] ?? 0) === 0) {
            $still_zero[] = $row;
        }
    }

    if (!empty($still_zero)) {
        echo "  → " . count($still_zero) . " bài vẫn views=0, thử Video object fields...\n";
        $fallback2_chunks = array_chunk($still_zero, 50);
        foreach ($fallback2_chunks as $chunk) {
            $multi_curl = curl_multi_init();
            $handles = [];

            foreach ($chunk as $row) {
                $url = FB_API_BASE . $row['fb_post_id'] . '?'
                     . http_build_query(['fields' => 'views,video_view_count', 'access_token' => $page_tokens[$row['page_id']]]);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                fb_curl_setssl($ch);
                curl_multi_add_handle($multi_curl, $ch);
                $handles[$row['id']] = ['ch' => $ch, 'row' => $row];
            }

            $active = null;
            do {
                $mrc = curl_multi_exec($multi_curl, $active);
                if ($active) curl_multi_select($multi_curl, 0.5);
            } while ($active && $mrc == CURLM_OK);

            foreach ($handles as $id => $info) {
                $response = curl_multi_getcontent($info['ch']);
                $http_code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($multi_curl, $info['ch']);

                if ($http_code === 200) {
                    $data = json_decode($response, true);
                    $v1 = (int)($data['views'] ?? 0);
                    $v2 = (int)($data['video_view_count'] ?? 0);
                    $v = max($v1, $v2);
                    if ($v > 0) $views_data[$id] = $v;
                }
            }
            curl_multi_close($multi_curl);
        }
    }
}

@touch($lock_file);

// ═══════════════════════════════════════════════════════════════════════════════
// PHA 2: CHECK LIKES/COMMENTS SONG SONG (curl_multi, 50 bài/lượt)
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n── Pha 2: Kiểm tra Likes/Comments (song song, 50 bài/lượt) ──\n";

$engagement_data = []; // id => ['likes' => x, 'comments' => y]
$engagement_chunks = array_chunk($valid_rows, 50);

foreach ($engagement_chunks as $chunk_idx => $chunk) {
    $multi_curl = curl_multi_init();
    $handles = [];

    foreach ($chunk as $row) {
        $token = $page_tokens[$row['page_id']];
        // Thử format pageid_videoid trước (phổ biến nhất)
        $target_id = $row['page_id'] . '_' . $row['fb_post_id'];

        $url = FB_API_BASE . $target_id . '?'
             . http_build_query([
                 'fields' => 'reactions.summary(true),likes.summary(true),comments.summary(true)',
                 'access_token' => $token
               ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        fb_curl_setssl($ch);
        curl_multi_add_handle($multi_curl, $ch);
        $handles[$row['id']] = ['ch' => $ch, 'row' => $row];
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($multi_curl, $active);
        if ($active) curl_multi_select($multi_curl, 0.5);
    } while ($active && $mrc == CURLM_OK);

    foreach ($handles as $id => $info) {
        $response = curl_multi_getcontent($info['ch']);
        $http_code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi_curl, $info['ch']);

        $likes = 0;
        $comments = 0;

        if ($http_code === 200) {
            $data = json_decode($response, true);
            $reacts = (int)($data['reactions']['summary']['total_count'] ?? 0);
            $lk = (int)($data['likes']['summary']['total_count'] ?? 0);
            $likes = max($reacts, $lk);
            $comments = (int)($data['comments']['summary']['total_count'] ?? 0);
        }
        $engagement_data[$id] = ['likes' => $likes, 'comments' => $comments, 'format_ok' => ($http_code === 200)];
    }
    curl_multi_close($multi_curl);

    echo "  Batch " . ($chunk_idx + 1) . "/" . count($engagement_chunks) . ": " . count($chunk) . " bài đã kiểm tra engagement.\n";
    @touch($lock_file);
}

// Fallback: Bài format pageid_videoid không được, thử videoid trực tiếp
$format_failed = [];
foreach ($valid_rows as $row) {
    $eng = $engagement_data[$row['id']] ?? null;
    if (!$eng || (!$eng['format_ok'] || ($eng['likes'] === 0 && $eng['comments'] === 0))) {
        $format_failed[] = $row;
    }
}

if (!empty($format_failed)) {
    echo "  → " . count($format_failed) . " bài thử fallback videoid format...\n";
    $fb_chunks = array_chunk($format_failed, 50);

    foreach ($fb_chunks as $chunk) {
        $multi_curl = curl_multi_init();
        $handles = [];

        foreach ($chunk as $row) {
            $token = $page_tokens[$row['page_id']];
            // Thử chỉ videoid
            $url = FB_API_BASE . $row['fb_post_id'] . '?'
                 . http_build_query([
                     'fields' => 'reactions.summary(true),likes.summary(true),comments.summary(true)',
                     'access_token' => $token
                   ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            fb_curl_setssl($ch);
            curl_multi_add_handle($multi_curl, $ch);
            $handles[$row['id']] = ['ch' => $ch, 'row' => $row];
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
            if ($active) curl_multi_select($multi_curl, 0.5);
        } while ($active && $mrc == CURLM_OK);

        foreach ($handles as $id => $info) {
            $response = curl_multi_getcontent($info['ch']);
            $http_code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi_curl, $info['ch']);

            if ($http_code === 200) {
                $data = json_decode($response, true);
                $reacts = (int)($data['reactions']['summary']['total_count'] ?? 0);
                $lk = (int)($data['likes']['summary']['total_count'] ?? 0);
                $likes = max($reacts, $lk);
                $comments_count = (int)($data['comments']['summary']['total_count'] ?? 0);

                if ($likes > 0 || $comments_count > 0) {
                    $engagement_data[$id] = ['likes' => $likes, 'comments' => $comments_count, 'format_ok' => true];
                }
            }
        }
        curl_multi_close($multi_curl);
    }
}

// Fallback 3: engagement field cho bài vẫn likes=0
$still_no_likes = [];
foreach ($valid_rows as $row) {
    $eng = $engagement_data[$row['id']] ?? null;
    if (!$eng || $eng['likes'] === 0) {
        $still_no_likes[] = $row;
    }
}

if (!empty($still_no_likes)) {
    echo "  → " . count($still_no_likes) . " bài likes=0, thử engagement field...\n";
    $eng_chunks = array_chunk($still_no_likes, 50);

    foreach ($eng_chunks as $chunk) {
        $multi_curl = curl_multi_init();
        $handles = [];

        foreach ($chunk as $row) {
            $url = FB_API_BASE . $row['fb_post_id'] . '?'
                 . http_build_query(['fields' => 'engagement', 'access_token' => $page_tokens[$row['page_id']]]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            fb_curl_setssl($ch);
            curl_multi_add_handle($multi_curl, $ch);
            $handles[$row['id']] = ['ch' => $ch, 'row' => $row];
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
            if ($active) curl_multi_select($multi_curl, 0.5);
        } while ($active && $mrc == CURLM_OK);

        foreach ($handles as $id => $info) {
            $response = curl_multi_getcontent($info['ch']);
            $http_code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi_curl, $info['ch']);

            if ($http_code === 200) {
                $data = json_decode($response, true);
                if (isset($data['engagement'])) {
                    $reaction_count = (int)($data['engagement']['reaction_count'] ?? 0);
                    if ($reaction_count > 0) {
                        if (!isset($engagement_data[$id])) {
                            $engagement_data[$id] = ['likes' => 0, 'comments' => 0, 'format_ok' => true];
                        }
                        $engagement_data[$id]['likes'] = max($engagement_data[$id]['likes'], $reaction_count);
                    }
                }
            }
        }
        curl_multi_close($multi_curl);
    }
}

@touch($lock_file);

// ═══════════════════════════════════════════════════════════════════════════════
// PHA 3: SO SÁNH NGƯỠNG & BÌNH LUẬN CHO CÁC BÀI ĐỦ ĐIỀU KIỆN
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n── Pha 3: Đánh giá ngưỡng & bình luận ──\n";

$qualified_count = 0;
$not_qualified_count = 0;

foreach ($valid_rows as $row) {
    $id = $row['id'];
    $current_views = $views_data[$id] ?? 0;
    $current_likes = $engagement_data[$id]['likes'] ?? 0;
    $current_comments = $engagement_data[$id]['comments'] ?? 0;

    $threshold_views = (int)($row['comment_threshold_views'] ?? 1000);
    $threshold_likes = (int)($row['comment_threshold_likes'] ?? 10);
    $threshold_comments = (int)($row['comment_threshold_comments'] ?? 5);

    $views_ok = ($current_views >= $threshold_views);
    $likes_ok = ($current_likes >= $threshold_likes);
    $comments_ok = ($current_comments >= $threshold_comments);

    // Cập nhật updated_at để round-robin (bài vừa check xuống cuối hàng đợi)
    $pdo->prepare("UPDATE scheduled_posts SET updated_at = NOW() WHERE id = ?")->execute([$id]);

    if (!$views_ok || !$likes_ok || !$comments_ok) {
        $not_qualified_count++;
        // Chỉ log chi tiết nếu số lượng nhỏ, tránh spam log
        if (count($valid_rows) <= 50) {
            $missing = [];
            if (!$views_ok) $missing[] = "V($current_views/$threshold_views)";
            if (!$likes_ok) $missing[] = "L($current_likes/$threshold_likes)";
            if (!$comments_ok) $missing[] = "C($current_comments/$threshold_comments)";
            echo "  ⏳ ID $id: Chưa đủ — " . implode(', ', $missing) . "\n";
        }
        continue;
    }

    // ═══ BÀI ĐỦ ĐIỀU KIỆN — BÌNH LUẬN NGAY ═══
    $qualified_count++;
    echo "\n  ✅ ID $id | ĐẠT ĐỦ điều kiện!\n";
    echo "    📊 View=$current_views, Like=$current_likes, Comment=$current_comments\n";
    echo "    🎯 Ngưỡng: V≥$threshold_views, L≥$threshold_likes, C≥$threshold_comments\n";

    // Lock the row to prevent duplicate commenting
    $lock_stmt = $pdo->prepare("UPDATE scheduled_posts SET comment_done = 2 WHERE id = ? AND comment_done = 0");
    $lock_stmt->execute([$id]);
    if ($lock_stmt->rowCount() === 0) {
        echo "    ⚠ Row đã bị xử lý bởi tiến trình khác. Bỏ qua.\n";
        continue;
    }

    // Pick random comment line
    $lines = array_values(array_filter(array_map('trim', explode("\n", $row['comment_lines']))));
    if (empty($lines)) {
        $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
            ->execute([$id]);
        echo "    ✗ Không có nội dung bình luận. Bỏ qua.\n";
        continue;
    }
    $comment_text = $lines[array_rand($lines)];

    // Post the comment (tuần tự vì cần đảm bảo từng bài)
    $fb_post_id = $row['fb_post_id'];
    $page_access_token = $page_tokens[$row['page_id']];

    $response = fb_api_request(
        $fb_post_id . '/comments',
        ['access_token' => $page_access_token],
        'POST',
        ['message' => $comment_text]
    );

    if ($response['status_code'] === 200 && isset($response['data']['id'])) {
        $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'done', comment_at = NOW() WHERE id = ?")
            ->execute([$id]);
        echo "    🎉 Bình luận thành công! Comment ID: {$response['data']['id']}\n";
        echo "    📝 Nội dung: \"$comment_text\"\n";

        // Gửi thông báo Telegram
        send_telegram_notification($pdo, $row['account_id'], "<b>Video đủ điều kiện bình luận!</b>\n🎬 Video: {$fb_post_id}\n📊 View: {$current_views}, Like: {$current_likes}, Comment: {$current_comments}\n📝 Bình luận: \"{$comment_text}\"", 'comment');

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
            echo "    ⚠ Không gửi được thông báo: " . $e->getMessage() . "\n";
        }
    } else {
        $err = $response['data']['error']['message'] ?? json_encode($response['data'] ?? []);
        $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
            ->execute([$id]);
        echo "    ✗ Lỗi bình luận: $err\n";

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

// Log tổng kết
if ($not_qualified_count > 50) {
    echo "\n  📋 Tổng bài chưa đủ điều kiện: $not_qualified_count (ẩn chi tiết vì quá nhiều)\n";
}

echo "\n══════════════════════════════════════════\n";
echo "📊 Tổng kết: " . count($valid_rows) . " bài đã kiểm tra\n";
echo "   ✅ Đủ điều kiện & bình luận: $qualified_count\n";
echo "   ⏳ Chưa đủ điều kiện: $not_qualified_count\n";
echo "   🕐 Hết hạn 24h: " . count($expired_ids) . "\n";
echo "   ✗ Thiếu token: " . (count($active_rows) - count($valid_rows)) . "\n";
echo "══════════════════════════════════════════\n";

// Release lock
if ($lock_fp) {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
}
@unlink($lock_file);
?>
