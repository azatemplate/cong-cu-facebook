<?php
// cron/comment_worker.php
// Posts scheduled comments on published posts where comment_at <= NOW() and comment_done = 0
// Called automatically at end of publish_worker.php

ignore_user_abort(true);
set_time_limit(0);

$target_account_id = isset($argv[1]) ? (int)$argv[1] : 0;
if ($target_account_id <= 0) {
    echo "Tiến trình gọi thiếu Account ID. Hủy bỏ.\n";
    exit;
}

$comment_lock_file = sys_get_temp_dir() . "/facebook_comment_worker_account_$target_account_id.lock";
$comment_lock_fp = fopen($comment_lock_file, 'c');
if (!flock($comment_lock_fp, LOCK_EX | LOCK_NB)) {
    echo "Tiến trình Bình luận cho Account ID #$target_account_id đang chạy, vui lòng đợi...\n";
    exit;
}

if (!isset($pdo)) require_once __DIR__ . '/../includes/db.php';
if (!function_exists('fb_api_request')) require_once __DIR__ . '/../includes/fb_api.php';

echo "\n--- Comment Worker ---\n";

// Detect if comment_status column exists
$has_comment_status = false;
try {
    $col_q = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME='comment_status'");
    $has_comment_status = ($col_q && $col_q->fetchColumn() > 0);
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT sp.id, sp.fb_post_id, sp.comment_lines, sp.page_id, sp.post_type
        FROM scheduled_posts sp
        WHERE sp.status = 'published'
          AND sp.comment_lines IS NOT NULL
          AND sp.comment_at IS NOT NULL
          AND sp.comment_at <= NOW()
          AND sp.comment_done = 0
          AND sp.account_id = ?
        LIMIT 50
    ");
    $stmt->execute([$target_account_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Bảng chưa có cột comment — chạy migrate.php trước.\n";
    return;
}

if (empty($rows)) {
    echo "Không có comment nào cần đăng lúc này.\n";
    echo "----------------------\n";
    return;
}

echo "Tìm thấy " . count($rows) . " bài cần bình luận.\n";

foreach ($rows as $row) {
    // Lock the row to prevent duplicate commenting from concurrent cron jobs
    $lock_stmt = $pdo->prepare("UPDATE scheduled_posts SET comment_done = 2 WHERE id = ? AND comment_done = 0");
    $lock_stmt->execute([$row['id']]);
    if ($lock_stmt->rowCount() === 0) continue;

    $fb_post_id = $row['fb_post_id'];
    if (empty($fb_post_id)) {
        // Mark done with error status to avoid retrying forever with missing post_id
        if ($has_comment_status) {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                ->execute([$row['id']]);
        } else {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                ->execute([$row['id']]);
        }
        echo " -> Bỏ qua ID {$row['id']}: không có fb_post_id.\n";
        continue;
    }

    // Pick random line
    $lines = array_values(array_filter(array_map('trim', explode("\n", $row['comment_lines']))));
    if (empty($lines)) {
        if ($has_comment_status) {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                ->execute([$row['id']]);
        } else {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                ->execute([$row['id']]);
        }
        continue;
    }
    $comment_text = $lines[array_rand($lines)];

    if ($row['post_type'] === 'YouTube') {
        // --- YOUTUBE COMMENT ---
        // Lấy thông tin kênh youtube_channels
        $yt_stmt = $pdo->prepare("SELECT yc.*, sa.gg_client_id, sa.gg_client_secret FROM youtube_channels yc JOIN system_accounts sa ON yc.account_id = sa.id WHERE yc.id = ?");
        $yt_stmt->execute([$row['page_id']]);
        $yt_channel = $yt_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$yt_channel || empty($yt_channel['refresh_token'])) {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1" . ($has_comment_status ? ", comment_status = 'error'" : "") . " WHERE id = ?")
                ->execute([$row['id']]);
            echo " -> Bỏ qua ID {$row['id']}: không tìm thấy kênh YouTube hoặc token.\n";
            continue;
        }

        // Đổi Refresh Token lấy Access Token
        $ch_tok = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch_tok, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_tok, CURLOPT_POST, true);
        curl_setopt($ch_tok, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $yt_channel['gg_client_id'],
            'client_secret' => $yt_channel['gg_client_secret'],
            'refresh_token' => $yt_channel['refresh_token'],
            'grant_type' => 'refresh_token'
        ]));
        $token_res = curl_exec($ch_tok);
        curl_close($ch_tok);
        $token_data = json_decode($token_res, true);

        if (empty($token_data['access_token'])) {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1" . ($has_comment_status ? ", comment_status = 'error'" : "") . " WHERE id = ?")
                ->execute([$row['id']]);
            echo " -> ID {$row['id']}: Lỗi cấp Access Token YouTube.\n";
            continue;
        }

        // Đăng comment YouTube
        $yt_comment_data = [
            "snippet" => [
                "videoId" => $fb_post_id, // Đối với YT thì fb_post_id là videoId
                "topLevelComment" => [
                    "snippet" => [
                        "textOriginal" => $comment_text
                    ]
                ]
            ]
        ];

        $ch_yt = curl_init('https://www.googleapis.com/youtube/v3/commentThreads?part=snippet');
        curl_setopt($ch_yt, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_yt, CURLOPT_POST, true);
        curl_setopt($ch_yt, CURLOPT_POSTFIELDS, json_encode($yt_comment_data));
        curl_setopt($ch_yt, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $token_data['access_token'],
            "Content-Type: application/json; charset=UTF-8"
        ]);
        $yt_res = curl_exec($ch_yt);
        $yt_code = curl_getinfo($ch_yt, CURLINFO_HTTP_CODE);
        curl_close($ch_yt);

        if ($yt_code == 200 || $yt_code == 201) {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1" . ($has_comment_status ? ", comment_status = 'done'" : "") . " WHERE id = ?")
                ->execute([$row['id']]);
            echo " -> ID {$row['id']}: Bình luận YouTube thành công!\n";
        } else {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1" . ($has_comment_status ? ", comment_status = 'error'" : "") . " WHERE id = ?")
                ->execute([$row['id']]);
            echo " -> ID {$row['id']}: Lỗi bình luận YouTube ($yt_code) - $yt_res\n";
        }

    } else {
        // --- FACEBOOK COMMENT ---
        // Get page token
        try {
            $p_stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
            $p_stmt->execute([$row['page_id']]);
            $page = $p_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $page = null;
        }

        if (!$page || empty($page['access_token'])) {
            if ($has_comment_status) {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                    ->execute([$row['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                    ->execute([$row['id']]);
            }
            echo " -> Bỏ qua ID {$row['id']}: không tìm thấy token.\n";
            continue;
        }

        $page_access_token = decryptData($page['access_token']);

        // Post comment  /{post_id}/comments
        $response = fb_api_request(
            $fb_post_id . '/comments',
            ['access_token' => $page_access_token],
            'POST',
            ['message' => $comment_text]
        );

        if ($response['status_code'] === 200 && isset($response['data']['id'])) {
            if ($has_comment_status) {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'done' WHERE id = ?")
                    ->execute([$row['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                    ->execute([$row['id']]);
            }
            echo " -> ID {$row['id']}: Bình luận thành công!\n";
        } else {
            $err = $response['data']['error']['message'] ?? json_encode($response['data']);
            if ($has_comment_status) {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                    ->execute([$row['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                    ->execute([$row['id']]);
            }
            echo " -> ID {$row['id']}: Lỗi bình luận: $err\n";
        }
    }
}

echo "----------------------\n";
