<?php
// cron/comment_insights_worker.php
// MULTI-THREADED PRIORITIZED INSIGHTS WORKER
//
// 1. Prioritizes NEWEST published posts first (ORDER BY scheduled_time DESC, id DESC)
// 2. Supports multi-threading via --thread=X and --total-threads=N
// 3. Checks each post individually using cURL multi parallel handles (Direct views field, Post Insights post_video_views, Engagement)
// 4. If thresholds are met, posts a comment immediately.

ignore_user_abort(true);
set_time_limit(0);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/telegram.php';
require_once __DIR__ . '/../includes/redis_queue.php';

// Helper function to extract clean Facebook Video/Post ID
function get_clean_fb_id($fb_post_id) {
    if (empty($fb_post_id)) return '';
    $fb_post_id = trim($fb_post_id);
    if (strpos($fb_post_id, '#') !== false) {
        $parts = explode('#', $fb_post_id);
        $last = trim(end($parts));
        if (is_numeric($last)) return $last;
        $fb_post_id = $parts[0];
    }
    if (strpos($fb_post_id, 'http') === 0) {
        if (preg_match('/\/(\d{10,})\/?/', $fb_post_id, $m)) {
            return $m[1];
        }
    }
    return $fb_post_id;
}

// Parse CLI arguments
$is_force = isset($_GET['force']) || (isset($argv) && in_array('--force', $argv));

$thread = null;
$total_threads = null;
if (isset($_GET['thread'])) $thread = intval($_GET['thread']);
if (isset($_GET['total_threads'])) $total_threads = intval($_GET['total_threads']);

if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--thread=') === 0) {
            $thread = intval(substr($arg, 9));
        }
        if (strpos($arg, '--total-threads=') === 0) {
            $total_threads = intval(substr($arg, 16));
        }
    }
}

$lock_suffix = ($thread !== null) ? "_t{$thread}" : "";
$redis_lock_key = "lock:cron:comment_insights_worker" . $lock_suffix;
$lock_file = sys_get_temp_dir() . "/facebook_comment_insights_worker{$lock_suffix}.lock";

$rq = RedisQueue::getInstance();
if ($is_force) {
    echo "[INFO] Đã bật cờ --force: Bỏ qua kiểm tra Lock.\n";
} else {
    if (!$rq->acquireLock($redis_lock_key, 300)) {
        echo "Tiến trình comment_insights_worker{$lock_suffix} đang chạy (Redis Lock). Bỏ qua.\n";
        exit;
    }
}

$lock_fp = @fopen($lock_file, 'c');
if ($lock_fp && !$is_force) {
    @flock($lock_fp, LOCK_EX | LOCK_NB);
}

register_shutdown_function(function() use ($rq, &$lock_fp, &$lock_file, $is_force, $redis_lock_key) {
    if (!$is_force) {
        try {
            if ($rq && method_exists($rq, 'releaseLock')) {
                $rq->releaseLock($redis_lock_key);
            }
        } catch (Exception $e) {}
        if ($lock_fp) {
            @flock($lock_fp, LOCK_UN);
            @fclose($lock_fp);
        }
        if ($lock_file && file_exists($lock_file)) {
            @unlink($lock_file);
        }
    }
});

$thread_label = ($thread !== null && $total_threads !== null) ? " (Thread {$thread}/{$total_threads})" : "";
echo "\n======================================================\n";
echo "   COMMENT INSIGHTS WORKER{$thread_label} — " . date('Y-m-d H:i:s') . "\n";
echo "======================================================\n";

// Record last run time
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_insights_cron_run', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()")->execute();
} catch (Exception $e) {}

// ── Fetch posts waiting for insights check (NEWEST FIRST) ──────────────────
$time_filter_sql = "";
if (!$is_force) {
    // Không quét lại bài vừa quét trong 15 phút qua (để nhường tài nguyên cho bài khác)
    $time_filter_sql = " AND (sp.updated_at IS NULL OR sp.updated_at <= NOW() - INTERVAL 15 MINUTE)";
}

$thread_filter_sql = "";
if ($thread !== null && $total_threads !== null && $total_threads > 0) {
    $thread_filter_sql = " AND (sp.id % {$total_threads} = {$thread})";
}

try {
    $stmt = $pdo->prepare("
        SELECT sp.id, sp.fb_post_id, sp.comment_lines, sp.page_id, sp.post_type,
               sp.comment_threshold_views, sp.comment_threshold_likes, sp.comment_threshold_comments,
               sp.account_id, sp.scheduled_time, sp.created_at, sp.updated_at
        FROM scheduled_posts sp
        JOIN system_accounts sa ON sp.account_id = sa.id
        WHERE sp.status = 'published'
          AND sp.comment_mode = 'insights'
          AND (sp.comment_status = 'waiting_insights' OR sp.comment_status IS NULL OR sp.comment_status = '' OR sp.comment_status = 'pending')
          AND sp.comment_lines IS NOT NULL
          AND sp.comment_lines != ''
          AND sp.comment_done = 0
          AND sp.fb_post_id IS NOT NULL
          AND sp.fb_post_id != ''
          AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
          {$time_filter_sql}
          {$thread_filter_sql}
        ORDER BY sp.scheduled_time DESC, sp.id DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "❌ Lỗi truy vấn DB: " . $e->getMessage() . "\n";
    exit;
}

if (empty($rows)) {
    echo "📭 Không có bài viết nào cần kiểm tra lúc này{$thread_label}.\n";
    echo "======================================================\n";
    exit;
}

echo "📋 Tìm thấy " . count($rows) . " bài viết cần kiểm tra (Ưu tiên bài MỚI ĐĂNG trước).\n\n";

// ── Bước 0: Lọc bài quá hạn 48h ──────────────────────────────────────────
$active_rows = [];
$expired_ids = [];
foreach ($rows as $row) {
    $ref_time = !empty($row['scheduled_time']) ? strtotime($row['scheduled_time']) : (!empty($row['created_at']) ? strtotime($row['created_at']) : time());
    if ($ref_time > 0 && (time() - $ref_time) > 172800) { // 48 giờ
        $expired_ids[] = $row['id'];
        echo "  ⏳ Post #{$row['id']} | Quá 48h theo dõi (" . date('Y-m-d H:i', $ref_time) . "). Đánh dấu hết hạn.\n";
    } else {
        $active_rows[] = $row;
    }
}

if (!empty($expired_ids)) {
    $placeholders = implode(',', array_fill(0, count($expired_ids), '?'));
    $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'expired_insights' WHERE id IN ($placeholders)")
        ->execute($expired_ids);
    echo "  → Đã cập nhật " . count($expired_ids) . " bài sang trạng thái 'expired_insights'.\n\n";
}

if (empty($active_rows)) {
    echo "📭 Không còn bài active nào sau khi lọc các bài quá hạn 48h.\n";
    echo "======================================================\n";
    exit;
}

echo "🔍 Số bài active sẵn sàng quét API: " . count($active_rows) . " bài.\n";

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
    echo "❌ Lỗi lấy token trang: " . $e->getMessage() . "\n";
}

$valid_rows = [];
foreach ($active_rows as $row) {
    if (!empty($page_tokens[$row['page_id']])) {
        $valid_rows[] = $row;
    } else {
        echo "  ❌ Post #{$row['id']} | Không tìm thấy Access Token cho Page ID: {$row['page_id']}. Ngắt dừng theo dõi.\n";
        try {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'missing_token' WHERE id = ?")->execute([$row['id']]);
        } catch (Exception $e) {}
    }
}

if (empty($valid_rows)) {
    echo "❌ Không còn bài nào có Token trang hợp lệ.\n";
    echo "======================================================\n";
    exit;
}

@touch($lock_file);

// ═══════════════════════════════════════════════════════════════════════════════
// STREAMING BATCH (50 BÀI/ĐỢT) — QUÉT CHI TIẾT TỪNG BÀI SONG SONG CẤP THẤP
// ═══════════════════════════════════════════════════════════════════════════════
$chunks = array_chunk($valid_rows, 50);
$total_valid = count($valid_rows);
$total_chunks = count($chunks);

echo "\n🚀 BẮT ĐẦU QUÉT & BÌNH LUẬN SONG SONG ($total_valid bài chia làm $total_chunks đợt - Ưu tiên bài mới trước)...\n";
@ob_flush(); @flush();

$qualified_count = 0;
$not_qualified_count = 0;
$api_error_count = 0;

foreach ($chunks as $chunk_idx => $chunk) {
    if (function_exists('ensure_pdo_alive')) {
        ensure_pdo_alive($pdo);
    }
    @touch($lock_file);

    $chunk_num = $chunk_idx + 1;
    echo "\n⚡ [ĐỢT {$chunk_num}/{$total_chunks}] Đang lấy chỉ số API cho " . count($chunk) . " bài...\n";
    @ob_flush(); @flush();

    // Gọi song song 3 Handles cURL cho MỖI BÀI VIẾT trong đợt
    $multi_curl = curl_multi_init();
    $post_handles = [];

    foreach ($chunk as $row) {
        $clean_id = get_clean_fb_id($row['fb_post_id']);
        $video_id = (strpos($clean_id, '_') !== false) ? explode('_', $clean_id)[1] : $clean_id;
        $target_id = (strpos($clean_id, '_') !== false) ? $clean_id : ($row['page_id'] . '_' . $clean_id);
        $token = $page_tokens[$row['page_id']];

        // Handle 1: Engagement (Reactions, Likes, Comments)
        $url1 = FB_API_BASE . $target_id . '?' . http_build_query([
            'fields' => 'reactions.summary(true),likes.summary(true),comments.summary(true)',
            'access_token' => $token
        ]);
        $ch1 = curl_init($url1);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch1, CURLOPT_TIMEOUT, 15);
        fb_curl_setssl($ch1);
        curl_multi_add_handle($multi_curl, $ch1);

        // Handle 2: Direct Video field 'views'
        $url2 = FB_API_BASE . $video_id . '?' . http_build_query([
            'fields' => 'views,length',
            'access_token' => $token
        ]);
        $ch2 = curl_init($url2);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
        fb_curl_setssl($ch2);
        curl_multi_add_handle($multi_curl, $ch2);

        // Handle 3: Post Insights 'post_video_views'
        $url3 = FB_API_BASE . $target_id . '/insights?' . http_build_query([
            'metric' => 'post_video_views',
            'period' => 'lifetime',
            'access_token' => $token
        ]);
        $ch3 = curl_init($url3);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_TIMEOUT, 15);
        fb_curl_setssl($ch3);
        curl_multi_add_handle($multi_curl, $ch3);

        $post_handles[$row['id']] = [
            'ch1' => $ch1,
            'ch2' => $ch2,
            'ch3' => $ch3,
            'row' => $row,
            'video_id' => $video_id,
            'target_id' => $target_id
        ];
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($multi_curl, $active);
        if ($active) curl_multi_select($multi_curl, 0.5);
    } while ($active && $mrc == CURLM_OK);

    $batch_metrics = [];
    $fatal_error_posts = [];

    foreach ($post_handles as $id => $h) {
        $views = 0;
        $likes = 0;
        $comments = 0;

        // Parse Handle 1 (Engagement)
        $res1 = curl_multi_getcontent($h['ch1']);
        $code1 = curl_getinfo($h['ch1'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi_curl, $h['ch1']);
        if ($code1 === 200) {
            $d1 = json_decode($res1, true);
            $reacts = (int)($d1['reactions']['summary']['total_count'] ?? 0);
            $lk = (int)($d1['likes']['summary']['total_count'] ?? 0);
            $likes = max($reacts, $lk);
            $comments = (int)($d1['comments']['summary']['total_count'] ?? 0);
        } else {
            $d1 = json_decode($res1, true);
            $err_code = (int)($d1['error']['code'] ?? 0);
            $err_subcode = (int)($d1['error']['error_subcode'] ?? 0);
            $err_msg = $d1['error']['message'] ?? ($res1 ? mb_strimwidth($res1, 0, 100, '…') : "HTTP {$code1}");
            $err_msg_lower = mb_strtolower($err_msg);

            $is_token_or_checkpoint = in_array($err_code, [190, 102, 200])
                || in_array($err_subcode, [458, 459, 460, 463, 467])
                || strpos($err_msg_lower, 'checkpoint') !== false
                || strpos($err_msg_lower, 'access token') !== false
                || strpos($err_msg_lower, 'session') !== false
                || strpos($err_msg_lower, 'permission') !== false;

            $is_object_not_found = ($err_code === 100)
                || strpos($err_msg_lower, 'does not exist') !== false
                || strpos($err_msg_lower, 'cannot be loaded') !== false
                || strpos($err_msg_lower, 'unsupported get request') !== false;

            if ($is_token_or_checkpoint) {
                $fatal_error_posts[$id] = [
                    'status' => 'error_checkpoint',
                    'reason' => "Tài khoản/Token lỗi hoặc bị Checkpoint (#{$err_code}): " . mb_strimwidth($err_msg, 0, 80, '…')
                ];
            } elseif ($is_object_not_found) {
                $fatal_error_posts[$id] = [
                    'status' => 'error_post_deleted',
                    'reason' => "Bài viết không tồn tại/đã xóa (#{$err_code}): " . mb_strimwidth($err_msg, 0, 80, '…')
                ];
            } elseif ($code1 >= 400) {
                $fatal_error_posts[$id] = [
                    'status' => 'error_api',
                    'reason' => "Lỗi FB Graph API HTTP {$code1}: " . mb_strimwidth($err_msg, 0, 80, '…')
                ];
            }
        }

        // Parse Handle 2 (Direct Video Views)
        $res2 = curl_multi_getcontent($h['ch2']);
        $code2 = curl_getinfo($h['ch2'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi_curl, $h['ch2']);
        if ($code2 === 200) {
            $d2 = json_decode($res2, true);
            if (isset($d2['views']) && is_numeric($d2['views'])) {
                $views = max($views, (int)$d2['views']);
            }
        }

        // Parse Handle 3 (Post Insights post_video_views)
        $res3 = curl_multi_getcontent($h['ch3']);
        $code3 = curl_getinfo($h['ch3'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi_curl, $h['ch3']);
        if ($code3 === 200) {
            $d3 = json_decode($res3, true);
            if (!empty($d3['data'])) {
                foreach ($d3['data'] as $m_item) {
                    if (isset($m_item['values'][0]['value'])) {
                        $v = (int)$m_item['values'][0]['value'];
                        if ($v > 0) $views = max($views, $v);
                    }
                }
            }
        }

        $batch_metrics[$id] = [
            'views' => $views,
            'likes' => $likes,
            'comments' => $comments
        ];
    }
    curl_multi_close($multi_curl);

    // ── ĐÁNH GIÁ NGƯỠNG & BÌNH LUẬN CHO BÀI ĐỦ ĐIỀU KIỆN ─────────────────────
    $chunk_qualified = 0;
    $chunk_ids = array_column($chunk, 'id');

    foreach ($chunk as $row) {
        $id = $row['id'];

        if (isset($fatal_error_posts[$id])) {
            $err_info = $fatal_error_posts[$id];
            $api_error_count++;
            echo "  ❌ Post #{$id} | {$err_info['reason']}. Dừng theo dõi (Xóa khỏi Chờ Insights).\n";
            try {
                if (function_exists('ensure_pdo_alive')) ensure_pdo_alive($pdo);
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = ? WHERE id = ?")
                    ->execute([$err_info['status'], $id]);
            } catch (Exception $e) {}
            continue;
        }

        $current_views = $batch_metrics[$id]['views'] ?? 0;
        $current_likes = $batch_metrics[$id]['likes'] ?? 0;
        $current_comments = $batch_metrics[$id]['comments'] ?? 0;

        $threshold_views = (isset($row['comment_threshold_views']) && $row['comment_threshold_views'] !== null && $row['comment_threshold_views'] !== '') ? (int)$row['comment_threshold_views'] : 0;
        $threshold_likes = (isset($row['comment_threshold_likes']) && $row['comment_threshold_likes'] !== null && $row['comment_threshold_likes'] !== '') ? (int)$row['comment_threshold_likes'] : 0;
        $threshold_comments = (isset($row['comment_threshold_comments']) && $row['comment_threshold_comments'] !== null && $row['comment_threshold_comments'] !== '') ? (int)$row['comment_threshold_comments'] : 0;

        $views_ok = ($current_views >= $threshold_views);
        $likes_ok = ($current_likes >= $threshold_likes);
        $comments_ok = ($current_comments >= $threshold_comments);

        if (!$views_ok || !$likes_ok || !$comments_ok) {
            $not_qualified_count++;
            continue;
        }

        // ═══ ĐẠT ĐỦ ĐIỀU KIỆN — BÌNH LUẬN NGAY ═══
        $qualified_count++;
        $chunk_qualified++;

        echo "  🎉 [ĐẠT ĐỦ ĐIỀU KIỆN] Post #{$id}!\n";
        echo "     📊 Chỉ số thực tế: View = {$current_views}, Like = {$current_likes}, Comment = {$current_comments}\n";
        echo "     🎯 Ngưỡng yêu cầu:  View ≥ {$threshold_views}, Like ≥ {$threshold_likes}, Comment ≥ {$threshold_comments}\n";

        if (function_exists('ensure_pdo_alive')) ensure_pdo_alive($pdo);
        $lock_stmt = $pdo->prepare("UPDATE scheduled_posts SET comment_done = 2 WHERE id = ? AND comment_done = 0");
        $lock_stmt->execute([$id]);
        if ($lock_stmt->rowCount() === 0) {
            echo "     ⚠️ Row #{$id} đã được xử lý bởi tiến trình khác. Bỏ qua.\n";
            continue;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $row['comment_lines']))));
        if (empty($lines)) {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                ->execute([$id]);
            echo "     ❌ Post #{$id}: Nội dung bình luận rỗng. Đánh dấu lỗi.\n";
            continue;
        }
        $comment_text = $lines[array_rand($lines)];
        if (function_exists('spin_text')) {
            $comment_text = spin_text($comment_text);
        }

        $clean_id = get_clean_fb_id($row['fb_post_id']);
        $target_id = (strpos($clean_id, '_') !== false) ? $clean_id : ($row['page_id'] . '_' . $clean_id);
        $page_access_token = $page_tokens[$row['page_id']];

        echo "     💬 Đang đăng bình luận lên Facebook (Target: {$target_id})...\n";

        $response = fb_api_request(
            $target_id . '/comments',
            ['access_token' => $page_access_token],
            'POST',
            ['message' => $comment_text]
        );

        if ($response['status_code'] !== 200 && $target_id !== $clean_id && !empty($clean_id)) {
            echo "     ⚠️ Post {$target_id} lỗi HTTP {$response['status_code']}. Thử fallback Clean ID {$clean_id}...\n";
            $response = fb_api_request(
                $clean_id . '/comments',
                ['access_token' => $page_access_token],
                'POST',
                ['message' => $comment_text]
            );
        }

        if ($response['status_code'] === 200 && isset($response['data']['id'])) {
            $comment_id = $response['data']['id'];
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'done', comment_at = NOW() WHERE id = ?")
                ->execute([$id]);
            echo "     ✅ BÌNH LUẬN THÀNH CÔNG! Comment ID: {$comment_id}\n";
            echo "     📝 Nội dung: \"{$comment_text}\"\n";

            send_telegram_notification($pdo, $row['account_id'], "<b>Video đủ điều kiện bình luận!</b>\n🎬 Video: {$target_id}\n📊 View: {$current_views}, Like: {$current_likes}, Comment: {$current_comments}\n📝 Bình luận: \"{$comment_text}\"", 'comment');

            try {
                $notif_snippet = json_encode([
                    'type' => 'success',
                    'video_id' => $target_id,
                    'content' => $comment_text
                ], JSON_UNESCAPED_UNICODE);
                $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_name, snippet, post_id, comment_id) VALUES (?, 'insights_comment', 'Hệ thống', ?, ?, ?)")
                    ->execute([$row['page_id'], $notif_snippet, $target_id, $comment_id]);
            } catch (Exception $e) {}
        } else {
            $err = $response['data']['error']['message'] ?? json_encode($response['data'] ?? []);
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                ->execute([$id]);
            echo "     ❌ LỖI GỬI BÌNH LUẬN (HTTP {$response['status_code']}): {$err}\n";

            $short_err = mb_strimwidth($err, 0, 100, '…');
            send_telegram_notification($pdo, $row['account_id'], "<b>Lỗi bình luận!</b>\n🎬 Video: {$target_id}\n❌ Lỗi: {$short_err}", 'error');

            try {
                $notif_err_snippet = json_encode([
                    'type' => 'error',
                    'video_id' => $target_id,
                    'error' => mb_strimwidth($err, 0, 100, '…')
                ], JSON_UNESCAPED_UNICODE);
                $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_name, snippet, post_id) VALUES (?, 'insights_comment', 'Hệ thống', ?, ?)")
                    ->execute([$row['page_id'], $notif_err_snippet, $target_id]);
            } catch (Exception $e) {}
        }
    }

    // ── Cập nhật updated_at cho đợt (Tránh lặp lại trong 15 phút) ─────────────
    if (!empty($chunk_ids)) {
        try {
            if (function_exists('ensure_pdo_alive')) ensure_pdo_alive($pdo);
            $in_clause = implode(',', array_fill(0, count($chunk_ids), '?'));
            $pdo->prepare("UPDATE scheduled_posts SET updated_at = NOW() WHERE id IN ($in_clause)")->execute($chunk_ids);
        } catch (Exception $e) {}
    }

    echo "  ✔ Hoàn tất đợt {$chunk_num}/{$total_chunks} (" . count($chunk) . " bài): " . ($chunk_qualified > 0 ? "🎉 {$chunk_qualified} BÀI ĐẠT ĐỦ ĐIỀU KIỆN & ĐÃ COMMENT THÀNH CÔNG!" : "Chưa có bài nào đạt ngưỡng.") . "\n";
    @ob_flush(); @flush();
}

echo "\n======================================================\n";
echo "📊 TỔNG KẾT TIẾN TRÌNH CHECK INSIGHTS{$thread_label}:\n";
echo "   - Tổng số bài quét API:         " . count($valid_rows) . "\n";
echo "   - 🎉 Đủ điều kiện & đã comment: {$qualified_count}\n";
echo "   - ⏳ Chưa đủ ngưỡng điều kiện:  {$not_qualified_count}\n";
echo "   - ⌛ Quá 48h tự ngừng theo dõi: " . count($expired_ids) . "\n";
echo "   - ❌ Bài lỗi token:             " . (count($active_rows) - count($valid_rows)) . "\n";
echo "   - 🛑 Bài lỗi API/Checkpoint:    {$api_error_count}\n";
echo "======================================================\n\n";
