<?php
// actions/mark_spam.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập đã hết hạn.']);
    exit;
}

$page_id         = trim($_REQUEST['page_id'] ?? '');
$sender_id       = trim($_REQUEST['sender_id'] ?? '');
$conversation_id = trim($_REQUEST['conversation_id'] ?? '');
$folder          = trim($_REQUEST['folder'] ?? 'spam'); // 'spam' or 'inbox'

if (empty($page_id) || (empty($sender_id) && empty($conversation_id))) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu thông tin hội thoại cần chuyển Spam.']);
    exit;
}

// 1. Fetch Page Access Token
$stmt = $pdo->prepare("SELECT access_token, name FROM pages WHERE page_id = ?");
$stmt->execute([$page_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page || empty($page['access_token'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy thông tin Fanpage.']);
    exit;
}

$access_token = decryptData($page['access_token']);

// 2. Fetch conversation_id if missing or synthetic
if (empty($conversation_id) || strpos($conversation_id, 'c_') === 0) {
    $stmt_conv = $pdo->prepare("SELECT conversation_id FROM fb_conversations WHERE page_id = ? AND sender_id = ?");
    $stmt_conv->execute([$page_id, $sender_id]);
    $db_conv_id = $stmt_conv->fetchColumn();
    if (!empty($db_conv_id) && strpos($db_conv_id, 'c_') !== 0) {
        $conversation_id = $db_conv_id;
    }
}

// If still missing real conversation_id, query Facebook Graph API by sender_id
if ((empty($conversation_id) || strpos($conversation_id, 'c_') === 0) && !empty($sender_id)) {
    $lookup_url = "https://graph.facebook.com/v25.0/" . urlencode($page_id) . "/conversations?user_id=" . urlencode($sender_id) . "&access_token=" . urlencode($access_token);
    $ch_lk = curl_init($lookup_url);
    curl_setopt_array($ch_lk, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $lk_res = curl_exec($ch_lk);
    curl_close($ch_lk);
    if ($lk_res) {
        $lk_json = json_decode($lk_res, true);
        if (!empty($lk_json['data'][0]['id'])) {
            $conversation_id = $lk_json['data'][0]['id'];
            // Save resolved conversation_id to DB
            try {
                $upd_cid = $pdo->prepare("UPDATE fb_conversations SET conversation_id = ? WHERE page_id = ? AND sender_id = ?");
                $upd_cid->execute([$conversation_id, $page_id, $sender_id]);
            } catch (Exception $e) {}
        }
    }
}

$meta_api_success = false;
$meta_err_msg = '';

$res1 = null; $http_code1 = 0;
$res2 = null; $http_code2 = 0;
$res3 = null; $http_code3 = 0;
$res4_l = null; $res4_c = null; $res4_a = null; $http_code4 = 0;
$res5 = null; $http_code5 = 0;
$res_g = null; $http_code_g = 0;

// 3. Call Meta Graph API Moderate Conversations
if (!empty($conversation_id) || !empty($sender_id)) {
    // Method 1: Conversation ID + Query String
    if (!empty($conversation_id)) {
        $url1 = "https://graph.facebook.com/v25.0/" . urlencode($conversation_id) . "?folder=" . urlencode($folder) . "&access_token=" . urlencode($access_token);
        $ch1 = curl_init();
        curl_setopt_array($ch1, [
            CURLOPT_URL => $url1,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res1 = curl_exec($ch1);
        $http_code1 = curl_getinfo($ch1, CURLINFO_RESPONSE_CODE);
        curl_close($ch1);
    }

    // Method 2: Conversation ID + Form Postfields
    if (!empty($conversation_id)) {
        $url2 = "https://graph.facebook.com/v25.0/" . urlencode($conversation_id);
        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL => $url2,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'folder' => $folder,
                'access_token' => $access_token
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res2 = curl_exec($ch2);
        $http_code2 = curl_getinfo($ch2, CURLINFO_RESPONSE_CODE);
        curl_close($ch2);
    }

    // Method 3: Page Conversations API with user_id & folder
    if (!empty($sender_id)) {
        $url3 = "https://graph.facebook.com/v25.0/" . urlencode($page_id) . "/conversations?user_id=" . urlencode($sender_id) . "&folder=" . urlencode($folder) . "&access_token=" . urlencode($access_token);
        $ch3 = curl_init();
        curl_setopt_array($ch3, [
            CURLOPT_URL => $url3,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res3 = curl_exec($ch3);
        $http_code3 = curl_getinfo($ch3, CURLINFO_RESPONSE_CODE);
        curl_close($ch3);
    }

    // Method 4: Custom Labels API (Gắn nhãn Spam trên Facebook Messenger)
    if (!empty($sender_id)) {
        $lbl_url = "https://graph.facebook.com/v25.0/" . urlencode($page_id) . "/custom_labels?fields=id,name,page_label_name&access_token=" . urlencode($access_token);
        $ch_l = curl_init($lbl_url);
        curl_setopt_array($ch_l, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        $res4_l = curl_exec($ch_l);
        curl_close($ch_l);
        
        $label_id = null;
        if ($res4_l) {
            $j_l = json_decode($res4_l, true);
            if (!empty($j_l['data'])) {
                foreach ($j_l['data'] as $lbl_item) {
                    $lname = $lbl_item['page_label_name'] ?? ($lbl_item['name'] ?? '');
                    if (strcasecmp($lname, 'Spam') === 0) {
                        $label_id = $lbl_item['id'];
                        break;
                    }
                }
            }
        }
        if (!$label_id) {
            $c_url = "https://graph.facebook.com/v25.0/" . urlencode($page_id) . "/custom_labels";
            $ch_c = curl_init($c_url);
            curl_setopt_array($ch_c, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['page_label_name' => 'Spam', 'name' => 'Spam', 'access_token' => $access_token]),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res4_c = curl_exec($ch_c);
            curl_close($ch_c);
            if ($res4_c) {
                $j_c = json_decode($res4_c, true);
                if (!empty($j_c['id'])) $label_id = $j_c['id'];
            }
        }
        if ($label_id) {
            $a_url = "https://graph.facebook.com/v25.0/" . urlencode($label_id) . "/label";
            $ch_a = curl_init($a_url);
            curl_setopt_array($ch_a, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => ($folder === 'spam'),
                CURLOPT_CUSTOMREQUEST => ($folder === 'spam') ? 'POST' : 'DELETE',
                CURLOPT_POSTFIELDS => http_build_query(['user' => $sender_id, 'access_token' => $access_token]),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res4_a = curl_exec($ch_a);
            $http_code4 = curl_getinfo($ch_a, CURLINFO_RESPONSE_CODE);
            curl_close($ch_a);
        }
    }

    // Method 5: POST /{page_id}/conversations?folder=spam&user_id={sender_id}
    if (!empty($sender_id)) {
        $url5 = "https://graph.facebook.com/v25.0/" . urlencode($page_id) . "/conversations?folder=" . urlencode($folder) . "&user_id=" . urlencode($sender_id) . "&access_token=" . urlencode($access_token);
        $ch5 = curl_init($url5);
        curl_setopt_array($ch5, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res5 = curl_exec($ch5);
        $http_code5 = curl_getinfo($ch5, CURLINFO_RESPONSE_CODE);
        curl_close($ch5);
    }

    // Test GET /{page_id}/conversations?folder=spam
    $url_get = "https://graph.facebook.com/v25.0/" . urlencode($page_id) . "/conversations?folder=spam&access_token=" . urlencode($access_token);
    $ch_g = curl_init($url_get);
    curl_setopt_array($ch_g, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
    $res_g = curl_exec($ch_g);
    $http_code_g = curl_getinfo($ch_g, CURLINFO_RESPONSE_CODE);
    curl_close($ch_g);

    $raw_conv_id = preg_replace('/^t_/', '', $conversation_id);

    // Method 6: Pass Thread Control (Gửi JSON Body)
    $res6 = null; $http_code6 = 0;
    if (!empty($sender_id)) {
        $url6 = "https://graph.facebook.com/v25.0/me/pass_thread_control?access_token=" . urlencode($access_token);
        $ch6 = curl_init($url6);
        curl_setopt_array($ch6, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'recipient' => ['id' => $sender_id],
                'target_app_id' => '263902037430900', // Page Inbox App ID
                'metadata' => 'spam'
            ]),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res6 = curl_exec($ch6);
        $http_code6 = curl_getinfo($ch6, CURLINFO_RESPONSE_CODE);
        curl_close($ch6);
    }

    // Method 7: Pure Numeric Conversation ID (Không có tiền tố t_) + folder=spam
    $res7 = null; $http_code7 = 0;
    if (!empty($raw_conv_id)) {
        $url7 = "https://graph.facebook.com/v25.0/" . urlencode($raw_conv_id) . "?folder=" . urlencode($folder) . "&access_token=" . urlencode($access_token);
        $ch7 = curl_init($url7);
        curl_setopt_array($ch7, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res7 = curl_exec($ch7);
        $http_code7 = curl_getinfo($ch7, CURLINFO_RESPONSE_CODE);
        curl_close($ch7);
    }

    // Method 8: Take Thread Control (Giành quyền kiểm soát luồng chat)
    $res8 = null; $http_code8 = 0;
    if (!empty($sender_id)) {
        $url8 = "https://graph.facebook.com/v25.0/me/take_thread_control?access_token=" . urlencode($access_token);
        $ch8 = curl_init($url8);
        curl_setopt_array($ch8, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'recipient' => ['id' => $sender_id],
                'metadata' => 'spam'
            ]),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res8 = curl_exec($ch8);
        $http_code8 = curl_getinfo($ch8, CURLINFO_RESPONSE_CODE);
        curl_close($ch8);
    }

    $log_msg = date('[Y-m-d H:i:s] ') . "Meta Spam API Call: page_id={$page_id}, conv_id={$conversation_id}, raw_conv_id={$raw_conv_id}, sender_id={$sender_id}, folder={$folder}\n";
    $log_msg .= "  Method 1 HTTP {$http_code1}: {$res1}\n";
    $log_msg .= "  Method 2 HTTP {$http_code2}: {$res2}\n";
    $log_msg .= "  Method 3 HTTP {$http_code3}: {$res3}\n";
    $log_msg .= "  Method 4 (Label Assign) HTTP {$http_code4}: {$res4_a}\n";
    $log_msg .= "  Method 5 HTTP {$http_code5}: {$res5}\n";
    $log_msg .= "  Method 6 (Pass Thread Control JSON) HTTP {$http_code6}: {$res6}\n";
    $log_msg .= "  Method 7 (Raw Conv ID no t_) HTTP {$http_code7}: {$res7}\n";
    $log_msg .= "  Method 8 (Take Thread Control) HTTP {$http_code8}: {$res8}\n";
    $log_msg .= "  GET folder=spam HTTP {$http_code_g}: {$res_g}\n";
    @file_put_contents(__DIR__ . '/../uploads/spam_debug.log', $log_msg, FILE_APPEND | LOCK_EX);

    $meta_details = [
        'resolved_conversation_id' => $conversation_id,
        'raw_conversation_id' => $raw_conv_id,
        'method1_query_param' => json_decode($res1, true),
        'method2_form_body' => json_decode($res2, true),
        'method3_page_conv' => json_decode($res3, true),
        'method4_get_labels' => json_decode($res4_l, true),
        'method4_create_label' => json_decode($res4_c, true),
        'method4_assign_label' => json_decode($res4_a, true),
        'method5_user_folder' => json_decode($res5, true),
        'method6_pass_thread_control' => json_decode($res6, true),
        'method7_raw_conv_id' => json_decode($res7, true),
        'method8_take_thread_control' => json_decode($res8, true),
        'get_folder_spam' => json_decode($res_g, true)
    ];

    foreach ($meta_details as $m_name => $m_json) {
        if (!empty($m_json)) {
            if (isset($m_json['success']) && $m_json['success']) {
                $meta_api_success = true;
            } elseif (isset($m_json['id']) && !empty($m_json['id'])) {
                $meta_api_success = true;
            } elseif (isset($m_json['error']['message'])) {
                if (empty($meta_err_msg)) {
                    $meta_err_msg = "[{$m_name}] " . $m_json['error']['message'];
                }
            }
        }
    }

    if ($meta_api_success) {
        $meta_err_msg = '';
    }
}

// 4. Update local database (fb_conversations.folder)
try {
    $col = $pdo->query("SHOW COLUMNS FROM fb_conversations LIKE 'folder'");
    if ($col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE fb_conversations ADD COLUMN folder VARCHAR(20) DEFAULT 'inbox'");
    }

    if (!empty($sender_id)) {
        $upd = $pdo->prepare("UPDATE fb_conversations SET folder = ? WHERE page_id = ? AND sender_id = ?");
        $upd->execute([$folder, $page_id, $sender_id]);
    } elseif (!empty($conversation_id)) {
        $upd = $pdo->prepare("UPDATE fb_conversations SET folder = ? WHERE page_id = ? AND conversation_id = ?");
        $upd->execute([$folder, $page_id, $conversation_id]);
    }
} catch (Exception $e) {}

// Add or remove Spam label in conversation_labels table for visual indication
if ($folder === 'spam') {
    try {
        $conv_key = !empty($conversation_id) ? $conversation_id : ($page_id . '_' . $sender_id);
        $lbl_stmt = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Spam')");
        $lbl_stmt->execute([$conv_key, $page_id, $sender_id]);
    } catch (Exception $e) {}
} else {
    try {
        $conv_key = !empty($conversation_id) ? $conversation_id : ($page_id . '_' . $sender_id);
        $lbl_stmt = $pdo->prepare("DELETE FROM conversation_labels WHERE conv_id = ? AND page_id = ? AND label_name = 'Spam'");
        $lbl_stmt->execute([$conv_key, $page_id]);
    } catch (Exception $e) {}
}

$msg = ($folder === 'spam')
    ? 'Đã chuyển cuộc trò chuyện sang thư mục SPAM thành công trên hệ thống!'
    : 'Đã khôi phục cuộc trò chuyện về Hộp thư đến (Inbox)!';

$note = '';
if ($folder === 'spam' && !$meta_api_success) {
    $note = 'Theo quy định của Meta: Graph API Moderate Conversations hỗ trợ chuyển Spam từ xa trực tiếp cho Instagram Direct. Đối với Facebook Messenger, hệ thống tự động ẩn khỏi Inbox và quản lý tập trung tại tab 🚫 Spam.';
}

echo json_encode([
    'status' => 'success',
    'msg' => $msg,
    'note' => $note,
    'meta_api_success' => $meta_api_success,
    'meta_error' => $meta_err_msg,
    'meta_details' => $meta_details ?? []
]);
