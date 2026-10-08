<?php
// test_webhook_cli.php - CLI Diagnostic script for Facebook Webhook
if (php_sapi_name() !== 'cli') {
    die("Script này chỉ chạy được trong Terminal (CLI mode).\n");
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/security.php';

echo "=======================================================\n";
echo " 🛠️  HỆ THỐNG KIỂM TRA VÀ CHẨN ĐOÁN META WEBHOOK (CLI) \n";
echo "=======================================================\n\n";

$domain = "https://fbweb.hongdolab.com";

// 1. Kiểm tra file webhook.php và Handshake GET Verification
echo "[1/5] Kiểm tra Handshake Verification GET trên webhook.php...\n";
$verify_token = 'HVP_WEBHOOK_VERIFY_TOKEN_2026';
$challenge_code = rand(100000, 999999);

$domain_url = $domain . "/webhook.php?hub_mode=subscribe&hub_verify_token=" . urlencode($verify_token) . "&hub_challenge=" . $challenge_code;
$ch = curl_init($domain_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 200 && trim($res) == (string)$challenge_code) {
    echo "  ✅ Handshake GET Verification OK! (HTTP 200, URL: $domain/webhook.php, Challenge Code: $challenge_code)\n";
} else {
    echo "  ⚠️ Public Webhook (HTTP $code): '$res'\n";
    echo "  Thử lại kết nối qua Local VirtualHost Host Header...\n";
    
    $local_url = "http://127.0.0.1/webhook.php?hub_mode=subscribe&hub_verify_token=" . urlencode($verify_token) . "&hub_challenge=" . $challenge_code;
    $ch_loc = curl_init($local_url);
    curl_setopt_array($ch_loc, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Host: fbweb.hongdolab.com']
    ]);
    $res_loc = curl_exec($ch_loc);
    $code_loc = curl_getinfo($ch_loc, CURLINFO_HTTP_CODE);
    curl_close($ch_loc);

    if ($code_loc === 200 && trim($res_loc) == (string)$challenge_code) {
        echo "  ✅ Local Webhook với Host Header OK! (HTTP 200, Challenge Code: $challenge_code)\n";
    } else {
        echo "  ❌ Lỗi Handshake (HTTP $code_loc): '$res_loc'\n";
    }
}
echo "\n";

// 2. Kiểm tra CSDL và các bảng lưu thông báo
echo "[2/5] Kiểm tra cấu trúc CSDL Webhook...\n";
$tables = ['page_notifications', 'fb_conversations', 'fb_customers', 'pages', 'users'];
foreach ($tables as $tbl) {
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
        echo "  ✅ Bảng '$tbl': OK ($cnt bản ghi)\n";
    } catch (Exception $e) {
        echo "  ❌ Bảng '$tbl': LỖI - " . $e->getMessage() . "\n";
    }
}
echo "\n";

// 3. Kiểm tra các Fanpage & Subscribed Apps với Facebook Graph API
echo "[3/5] Kiểm tra trạng thái Kích hoạt Webhook (subscribed_apps) trên từng Fanpage...\n";
try {
    $stmt = $pdo->query("SELECT p.page_id, p.name, p.access_token, u.account_id FROM pages p JOIN users u ON p.user_id = u.id");
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pages)) {
        echo "  ⚠️ Không tìm thấy Fanpage nào trong CSDL.\n";
    } else {
        echo "  Tìm thấy " . count($pages) . " Fanpage trong hệ thống:\n";
        foreach ($pages as $p) {
            $page_id = $p['page_id'];
            $page_name = $p['name'];
            $token = decryptData($p['access_token']);

            if (empty($token)) {
                echo "  ❌ [$page_id] $page_name: Token bị rỗng hoặc lỗi giải mã.\n";
                continue;
            }

            // GET /{page_id}/subscribed_apps
            $chk_res = fb_api_request("{$page_id}/subscribed_apps", ['access_token' => $token], 'GET');
            $sub_data = $chk_res['data']['data'] ?? [];
            $is_subbed = false;
            $fields = [];

            if (!empty($sub_data)) {
                foreach ($sub_data as $app) {
                    $is_subbed = true;
                    if (!empty($app['subscribed_fields'])) {
                        $fields = array_merge($fields, $app['subscribed_fields']);
                    }
                }
            }

            if ($is_subbed) {
                $field_str = implode(',', array_unique($fields));
                echo "  ✅ [$page_id] $page_name: ĐÃ KÍCH HOẠT WEBHOOK (Fields: " . ($field_str ?: 'ALL') . ")\n";
            } else {
                echo "  ⚠️ [$page_id] $page_name: CHƯA ĐĂNG KÝ WEBHOOK. Tiến hành kích hoạt ngay...\n";
                // Tự động Subscribe ngay!
                $sub_res = fb_api_request("{$page_id}/subscribed_apps", ['access_token' => $token], 'POST', [
                    'subscribed_fields' => 'messages,messaging_postbacks,messaging_referrals,feed'
                ]);
                if (isset($sub_res['data']['success']) && $sub_res['data']['success'] == true) {
                    echo "     🚀 -> ĐÃ KÍCH HOẠT WEBHOOK THÀNH CÔNG CHO PAGE $page_name!\n";
                } else {
                    $err = $sub_res['data']['error']['message'] ?? 'Lỗi không xác định';
                    echo "     ❌ -> Kích hoạt thất bại: $err\n";
                }
            }
        }
    }
} catch (Exception $e) {
    echo "  ❌ Lỗi kiểm tra Fanpage: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Giả lập gửi 1 Webhook Payload thử nghiệm đến webhook.php
echo "[4/5] Giả lập gửi 1 tin nhắn thử nghiệm (Payload SIMULATION) tới webhook.php...\n";
$test_page_id = $pages[0]['page_id'] ?? '100000000000000';
$test_sender_id = 'test_user_cli_' . rand(100, 999);
$test_msg = "Test Webhook CLI Message " . date('H:i:s d/m/Y');

$mock_payload = [
    'object' => 'page',
    'entry' => [
        [
            'id' => $test_page_id,
            'messaging' => [
                [
                    'sender' => ['id' => $test_sender_id],
                    'recipient' => ['id' => $test_page_id],
                    'timestamp' => time(),
                    'message' => [
                        'mid' => 'm_test_' . time(),
                        'text' => $test_msg
                    ]
                ]
            ]
        ]
    ]
];

$ch_post = curl_init($domain . "/webhook.php");
curl_setopt_array($ch_post, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($mock_payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);
$post_res = curl_exec($ch_post);
$post_code = curl_getinfo($ch_post, CURLINFO_HTTP_CODE);
curl_close($ch_post);

if ($post_code !== 200) {
    $ch_post_loc = curl_init("http://127.0.0.1/webhook.php");
    curl_setopt_array($ch_post_loc, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($mock_payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Host: fbweb.hongdolab.com'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    $post_res = curl_exec($ch_post_loc);
    $post_code = curl_getinfo($ch_post_loc, CURLINFO_HTTP_CODE);
    curl_close($ch_post_loc);
}

echo "  Gửi HTTP POST payload giả lập... (Code HTTP $post_code, Trả về: '$post_res')\n";

// Kiểm tra xem dữ liệu giả lập có được ghi vào CSDL thành công không
sleep(1);
$chk_notif = $pdo->prepare("SELECT id, snippet FROM page_notifications WHERE sender_id = ? ORDER BY id DESC LIMIT 1");
$chk_notif->execute([$test_sender_id]);
$inserted_row = $chk_notif->fetch(PDO::FETCH_ASSOC);

if ($inserted_row) {
    echo "  ✅ XÁC NHẬN THÀNH CÔNG: Webhook đã ghi dữ liệu vào CSDL (ID: {$inserted_row['id']}, Snippet: '{$inserted_row['snippet']}')!\n";
    // Clean up test entry
    $pdo->prepare("DELETE FROM page_notifications WHERE sender_id = ?")->execute([$test_sender_id]);
    $pdo->prepare("DELETE FROM fb_conversations WHERE sender_id = ?")->execute([$test_sender_id]);
    $pdo->prepare("DELETE FROM fb_customers WHERE sender_id = ?")->execute([$test_sender_id]);
} else {
    echo "  ❌ KHÔNG THẤY BẢN GHI TRONG CSDL: Tiến trình ghi dữ liệu giả lập không thành công.\n";
}
echo "\n";

// 5. Kiểm tra log file webhook_debug.txt & webhook_db_errors.txt
echo "[5/5] Kiểm tra file nhật ký log Webhook...\n";
$debug_file = __DIR__ . '/webhook_debug.txt';
$error_file = __DIR__ . '/webhook_db_errors.txt';

if (file_exists($debug_file)) {
    $sz = filesize($debug_file);
    echo "  📄 File 'webhook_debug.txt' tồn tại ($sz bytes).\n";
} else {
    echo "  ℹ️ File 'webhook_debug.txt' chưa tạo (Sẽ tự tạo khi Meta gửi Webhook thật về).\n";
}

if (file_exists($error_file)) {
    $sz = filesize($error_file);
    echo "  📄 File 'webhook_db_errors.txt' tồn tại ($sz bytes). Nạp 5 dòng cuối:\n";
    $lines = array_slice(file($error_file), -5);
    foreach ($lines as $l) {
        echo "     " . trim($l) . "\n";
    }
} else {
    echo "  ✅ Không có file 'webhook_db_errors.txt' (Không phát sinh lỗi CSDL khi nhận Webhook).\n";
}

echo "\n=======================================================\n";
echo " 🎉 HOÀN TẤT KIỂM TRA WEBHOOK CLI!                     \n";
echo "=======================================================\n";
