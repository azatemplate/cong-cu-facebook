<?php
// actions/run_sync_worker.php
@set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
if ($account_id <= 0) {
    exit('Invalid account ID');
}

// Lấy toàn bộ Fanpage của tài khoản
try {
    $stmt_pages = $pdo->prepare("
        (SELECT p.page_id, p.name, p.user_id, p.access_token
         FROM pages p JOIN users u ON p.user_id = u.id
         WHERE u.account_id = :aid)
        UNION
        (SELECT p.page_id, p.name, p.user_id, p.access_token
         FROM pages p
         JOIN page_shares ps ON p.page_id = ps.page_id
         JOIN users u ON p.user_id = u.id
         WHERE ps.shared_with_account_id = :aid2)
    ");
    $stmt_pages->execute([':aid' => $account_id, ':aid2' => $account_id]);
    $pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    exit;
}

if (empty($pages)) {
    exit;
}

foreach ($pages as $page) {
    if (empty($page['access_token'])) continue;
    $page_access_token = decryptData($page['access_token']);
    $pid = $page['page_id'];

    $after = '';
    $page_iteration = 0;
    
    while (true) {
        $page_iteration++;
        if ($page_iteration > 1000) { // Giới hạn tối đa 50.000 cuộc hội thoại
            break;
        }

        $params = [
            'fields' => 'id,updated_time,unread_count,tags{name},participants{id,name,email,custom_labels},messages.limit(1){message,from}',
            'access_token' => $page_access_token,
            'limit' => 50
        ];
        if ($after) {
            $params['after'] = $after;
        }

        $response = fb_api_request($pid . '/conversations', $params, 'GET');

        if ($response['status_code'] !== 200 || empty($response['data']['data'])) {
            break;
        }

        $conversations = $response['data']['data'];

        foreach ($conversations as $c) {
            $conv_id = $c['id'];
            $updated_time = date('Y-m-d H:i:s', strtotime($c['updated_time']));
            $unread_count = (int)($c['unread_count'] ?? 0);

            // Tìm người gửi
            $sender_id = '';
            $sender_name = 'Khách hàng';
            if (isset($c['participants']['data'])) {
                foreach ($c['participants']['data'] as $p) {
                    if ($p['id'] !== $pid) {
                        $sender_id = $p['id'];
                        $sender_name = $p['name'] ?? 'Khách hàng';
                        break;
                    }
                }
            }

            if (empty($sender_id)) continue;

            $snippet = '';
            if (!empty($c['messages']['data'])) {
                $snippet = $c['messages']['data'][0]['message'] ?? '';
            }

            // Sync to fb_conversations
            $stmt_ins = $pdo->prepare("
                INSERT INTO fb_conversations (page_id, sender_id, sender_name, snippet, unread_count, updated_time, conversation_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    sender_name = VALUES(sender_name),
                    snippet = IF(VALUES(updated_time) >= updated_time, VALUES(snippet), snippet),
                    unread_count = VALUES(unread_count),
                    updated_time = IF(VALUES(updated_time) >= updated_time, VALUES(updated_time), updated_time),
                    conversation_id = VALUES(conversation_id)
            ");
            $stmt_ins->execute([$pid, $sender_id, $sender_name, $snippet, $unread_count, $updated_time, $conv_id]);

            // Sync to fb_customers
            try {
                $phone = extract_phone_number($snippet);

                $stmt_cust = $pdo->prepare("
                    INSERT INTO fb_customers (page_id, sender_id, name, phone, last_message_at)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        phone = IF(VALUES(phone) IS NOT NULL AND VALUES(phone) != '', VALUES(phone), phone)
                ");
                $stmt_cust->execute([$pid, $sender_id, $sender_name, $phone ?: null, $updated_time]);

                if ($phone) {
                    $lbl_stmt = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
                    $lbl_stmt->execute([$conv_id, $pid, $sender_id]);
                }
            } catch (Exception $e) {}

            // Đồng bộ nhãn (tags/labels) từ Facebook Page Inbox để phát hiện quảng cáo cũ
            if (isset($c['tags']['data'])) {
                $is_ads_tag = 0;
                $ad_id_tag = null;
                foreach ($c['tags']['data'] as $tag) {
                    $tag_name = trim($tag['name'] ?? '');
                    if ($tag_name === '') continue;

                    // Lưu nhãn vào bảng conversation_labels
                    try {
                        $stmt_lbl = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, ?)");
                        $stmt_lbl->execute([$conv_id, $pid, $sender_id, $tag_name]);
                    } catch (Exception $e) {}

                    // Kiểm tra xem nhãn có phải là quảng cáo không
                    if ($tag_name === 'messenger_ads') {
                        $is_ads_tag = 1;
                    } elseif (strpos($tag_name, 'ad_id.') === 0) {
                        $is_ads_tag = 1;
                        $ad_id_tag = substr($tag_name, 6); // Lấy phần số sau "ad_id."
                    }
                }

                // Nếu phát hiện nhãn quảng cáo, cập nhật thông tin trong fb_customers
                if ($is_ads_tag === 1) {
                    try {
                        $stmt_upd_ads = $pdo->prepare("
                            UPDATE fb_customers 
                            SET is_ads = 1, 
                                ad_id = COALESCE(?, ad_id),
                                ad_title = COALESCE(ad_title, 'Quảng cáo Facebook (Đồng bộ nhãn)')
                            WHERE page_id = ? AND sender_id = ?
                        ");
                        $stmt_upd_ads->execute([$ad_id_tag, $pid, $sender_id]);
                    } catch (Exception $e) {}
                }
            }
        }

        if (isset($response['data']['paging']['cursors']['after'])) {
            $after = $response['data']['paging']['cursors']['after'];
        } else {
            break;
        }
    }
}

// Giải phóng khóa tiến trình đồng bộ
$lock_file = __DIR__ . '/../locks/sync_' . intval($account_id) . '.lock';
@unlink($lock_file);

/**
 * Trích xuất số điện thoại Việt Nam từ văn bản
 */
function extract_phone_number($text) {
    if (empty($text)) return null;
    $clean = preg_replace('/[\s.\-_]+/', '', $text);
    if (preg_match('/(?:\+84|84|0)(3|5|7|8|9)\d{8}\b/', $clean, $matches)) {
        $phone = $matches[0];
        if (strpos($phone, '+84') === 0) {
            $phone = '0' . substr($phone, 3);
        } elseif (strpos($phone, '84') === 0 && strlen($phone) === 11) {
            $phone = '0' . substr($phone, 2);
        }
        return $phone;
    }
    return null;
}
?>
