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

// Xoa lock file cu neu > 15 phut (worker cu crash khong release)
if (file_exists($comment_lock_file) && (time() - filemtime($comment_lock_file)) > 900) {
    @unlink($comment_lock_file);
    echo "  [!] Lock file comment cu > 15 phut, da don sach cho Account ID: $target_account_id\n";
}

$comment_lock_fp = @fopen($comment_lock_file, 'c');
if (!$comment_lock_fp) {
    echo "Khong mo duoc lock file comment. Bo qua.\n";
    exit;
}

// Thu lock trong 3 giay thay vi exit ngay
$lock_got = false;
for ($i = 0; $i < 3; $i++) {
    if (flock($comment_lock_fp, LOCK_EX | LOCK_NB)) { $lock_got = true; break; }
    sleep(1);
}
if (!$lock_got) {
    echo "Tien trinh Binh luan cho Account ID #$target_account_id dang chay, bo qua.\n";
    fclose($comment_lock_fp);
    exit;
}

// Chống Thundering Herd: Giãn cách vài mili-giây siêu nhỏ
usleep(rand(50000, 800000)); // Nghỉ 0.05 đến 0.8 giây

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
        $yt_stmt = $pdo->prepare("SELECT yc.*, COALESCE(yc.gg_client_id, sa.gg_client_id) AS gg_client_id, COALESCE(yc.gg_client_secret, sa.gg_client_secret) AS gg_client_secret FROM youtube_channels yc JOIN system_accounts sa ON yc.account_id = sa.id WHERE yc.id = ?");
        $yt_stmt->execute([$row['page_id']]);
        $yt_channel = $yt_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$yt_channel || empty($yt_channel['refresh_token'])) {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1" . ($has_comment_status ? ", comment_status = 'error'" : "") . " WHERE id = ?")
                ->execute([$row['id']]);
            echo " -> Bỏ qua ID {$row['id']}: không tìm thấy kênh YouTube hoặc token.\n";
            continue;
        }

        // Lấy Client ID/Secret, fallback về Admin nếu user chưa cấu hình
        $client_id = $yt_channel['gg_client_id'];
        $client_secret = $yt_channel['gg_client_secret'];

        if (empty($client_id) || empty($client_secret)) {
            $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1" . ($has_comment_status ? ", comment_status = 'error'" : "") . " WHERE id = ?")
                ->execute([$row['id']]);
            echo " -> Bỏ qua ID {$row['id']}: Thiếu Cấu hình Google Client ID/Secret của bạn (Vui lòng vào Cài đặt để bổ sung).\n";
            continue;
        }



        // Đổi Refresh Token lấy Access Token
        $ch_tok = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch_tok, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_tok, CURLOPT_POST, true);
        curl_setopt($ch_tok, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $client_id,
            'client_secret' => $client_secret,
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

    } elseif (strpos($row['post_type'], 'Instagram') !== false) {
        // --- INSTAGRAM COMMENT ---
        if ($row['post_type'] === 'Instagram_Story') {
            if ($has_comment_status) {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                    ->execute([$row['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                    ->execute([$row['id']]);
            }
            echo " -> Bỏ qua ID {$row['id']}: Instagram Story không hỗ trợ bình luận.\n";
            continue;
        }

        try {
            $ig_stmt = $pdo->prepare("SELECT access_token FROM instagram_accounts WHERE (ig_user_id = ? OR id = ?) AND account_id = ?");
            $ig_stmt->execute([$row['page_id'], $row['page_id'], $target_account_id]);
            $ig_acc = $ig_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $ig_acc = null;
        }

        if (!$ig_acc || empty($ig_acc['access_token'])) {
            if ($has_comment_status) {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                    ->execute([$row['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                    ->execute([$row['id']]);
            }
            echo " -> Bỏ qua ID {$row['id']}: Không tìm thấy tài khoản Instagram hoặc access token.\n";
            continue;
        }

        $ig_access_token = $ig_acc['access_token'];

        // Parse numeric ig_media_id from fb_post_id (e.g. "https://www.instagram.com/reel/xxx/#18011910947756121" or "18011910947756121")
        $ig_media_id = $fb_post_id;
        if (strpos($ig_media_id, '#') !== false) {
            $parts = explode('#', $ig_media_id);
            $ig_media_id = end($parts);
        }
        $ig_media_id = trim($ig_media_id);

        $comment_text_spun = function_exists('spin_text') ? spin_text($comment_text) : $comment_text;

        $url = FB_API_BASE . $ig_media_id . "/comments";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'message' => $comment_text_spun,
                'access_token' => $ig_access_token
            ])
        ]);
        apply_proxy_to_curl($ch, $ig_access_token);
        fb_curl_setssl($ch);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        $res_json = json_decode($res, true);

        if (!empty($res_json['id'])) {
            if ($has_comment_status) {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'done' WHERE id = ?")
                    ->execute([$row['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                    ->execute([$row['id']]);
            }
            echo " -> ID {$row['id']}: Bình luận Instagram thành công! ID: {$res_json['id']}\n";
        } else {
            $errMsg = $res_json['error']['message'] ?? ($err ?: $res);
            if ($has_comment_status) {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1, comment_status = 'error' WHERE id = ?")
                    ->execute([$row['id']]);
            } else {
                $pdo->prepare("UPDATE scheduled_posts SET comment_done = 1 WHERE id = ?")
                    ->execute([$row['id']]);
            }
            echo " -> ID {$row['id']}: Lỗi bình luận Instagram: $errMsg\n";
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

// Giai phong va xoa lock file (tranh tich luy file rac)
if ($comment_lock_fp) {
    flock($comment_lock_fp, LOCK_UN);
    fclose($comment_lock_fp);
}
@unlink($comment_lock_file);
