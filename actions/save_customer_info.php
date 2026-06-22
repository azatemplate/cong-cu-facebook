<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

// Clear conversation cache
foreach (array_keys($_SESSION) as $key) {
    if (strpos($key, 'fb_convs_') === 0) {
        unset($_SESSION[$key]);
    }
}
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
    exit;
}

$page_id = trim($_POST['page_id'] ?? '');
$sender_id = trim($_POST['sender_id'] ?? '');
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$province = trim($_POST['province'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$consulted = isset($_POST['consulted']) ? intval($_POST['consulted']) : 0;
$sales_phone = trim($_POST['sales_phone'] ?? '');
$sales_notes = trim($_POST['sales_notes'] ?? '');

if (!$page_id || !$sender_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu page_id hoặc sender_id']);
    exit;
}

try {
    // Update or insert customer
    $stmt = $pdo->prepare("
        INSERT INTO fb_customers (page_id, sender_id, name, phone, province, notes, consulted, sales_phone, sales_notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name), 
            phone = VALUES(phone), 
            province = VALUES(province), 
            notes = VALUES(notes),
            consulted = VALUES(consulted),
            sales_phone = VALUES(sales_phone),
            sales_notes = VALUES(sales_notes)
    ");
    $stmt->execute([$page_id, $sender_id, $name, $phone, $province, $notes, $consulted, $sales_phone, $sales_notes]);
    
    // Automatically label as "Đã cho số điện thoại" if phone is not empty
    $need_fb_sync = false;
    $conv_id = null;
    if (!empty($phone)) {
        // Find conversation ID
        $stmt_conv = $pdo->prepare("SELECT conversation_id FROM page_notifications WHERE page_id = ? AND sender_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_conv->execute([$page_id, $sender_id]);
        $conv_id = $stmt_conv->fetchColumn();
        
        if ($conv_id) {
            // Check if label already exists locally
            $stmt_lbl_check = $pdo->prepare("SELECT 1 FROM conversation_labels WHERE conv_id = ? AND page_id = ? AND recipient_id = ? AND label_name = 'Đã cho số điện thoại'");
            $stmt_lbl_check->execute([$conv_id, $page_id, $sender_id]);
            if (!$stmt_lbl_check->fetchColumn()) {
                $need_fb_sync = true;
            }
        }
    }
    
    $json_out = json_encode(['status' => 'success', 'msg' => 'Cập nhật thông tin khách hàng thành công.']);
    
    if ($need_fb_sync) {
        if (function_exists('fastcgi_finish_request')) {
            echo $json_out;
            fastcgi_finish_request();
        } else {
            ignore_user_abort(true);
            header('Connection: close');
            header('Content-Length: ' . strlen($json_out));
            ob_start();
            echo $json_out;
            $size = ob_get_length();
            ob_end_flush();
            flush();
        }
        
        // --- Below runs in background ---
        
        // Insert local label
        $stmt_lbl = $pdo->prepare("INSERT IGNORE INTO conversation_labels (conv_id, page_id, recipient_id, label_name) VALUES (?, ?, ?, 'Đã cho số điện thoại')");
        $stmt_lbl->execute([$conv_id, $page_id, $sender_id]);
        
        // Sync to Facebook
        $stmt_tok = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
        $stmt_tok->execute([$page_id]);
        $page_data = $stmt_tok->fetch(PDO::FETCH_ASSOC);
        
        if ($page_data && !empty($page_data['access_token'])) {
            require_once __DIR__ . '/../includes/fb_api.php';
            $token = decryptData($page_data['access_token']);
            
            // Find existing label on Facebook
            $res1 = fb_api_request($page_id . '/custom_labels', ['fields' => 'name', 'access_token' => $token], 'GET');
            $label_id = null;
            if (isset($res1['data']['data']) && is_array($res1['data']['data'])) {
                foreach ($res1['data']['data'] as $label) {
                    if (mb_strtolower($label['name']) === 'đã cho số điện thoại') {
                        $label_id = $label['id'];
                        break;
                    }
                }
            }
            
            // Create if not exists
            if (!$label_id) {
                $res2 = fb_api_request($page_id . '/custom_labels', ['page_label_name' => 'Đã cho số điện thoại', 'access_token' => $token], 'POST');
                $label_id = $res2['data']['id'] ?? null;
            }
            
            // Assign
            if ($label_id) {
                fb_api_request($label_id . '/label', ['user' => $sender_id, 'access_token' => $token], 'POST');
            }
        }
    } else {
        echo $json_out;
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
