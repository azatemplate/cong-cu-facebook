<?php
// tiktok_webhook.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/setup_tiktok_chat.php';

// Set headers for smooth TikTok API interaction
header('Content-Type: application/json; charset=utf-8');

// Disable output buffering & errors from breaking JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 1. TikTok Webhook GET Verification
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challenge = $_GET['challenge'] ?? ($_GET['echostr'] ?? '');
    if ($challenge !== '') {
        // Return challenge as string or json
        if (is_numeric($challenge)) {
            echo json_encode(['challenge' => intval($challenge)]);
        } else {
            echo json_encode(['challenge' => $challenge]);
        }
        exit;
    }
    echo json_encode(['status' => 'success', 'msg' => 'TikTok Webhook Listener is active']);
    exit;
}

// 2. TikTok Webhook POST Events & Verification Handshake
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_input = file_get_contents('php://input');
    $payload = json_decode($raw_input, true);

    if (!$payload) {
        // Fallback for form-encoded post
        $payload = $_POST;
    }

    // A. Check if TikTok sent a POST challenge verification
    if (isset($payload['challenge'])) {
        $challenge_val = $payload['challenge'];
        echo json_encode(['challenge' => is_numeric($challenge_val) ? intval($challenge_val) : $challenge_val]);
        exit;
    }

    // 🚀 PHẢN HỒI TỨC THÌ CHO TIKTOK WEBHOOK SERVER (< 10ms / dưới 0.01 giây)
    http_response_code(200);
    echo json_encode(['status' => 'success', 'msg' => 'Event received']);

    @ignore_user_abort(true);
    @set_time_limit(180);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    $event_type = $payload['event'] ?? ($payload['event_type'] ?? '');
    
    // B. Parse incoming TikTok messaging events
    if ($payload) {
        $open_id = $payload['open_id'] ?? ($payload['to_user_id'] ?? 'tiktok_app');
        
        // Check if feature is enabled for account
        try {
            $st_tt_acc = $pdo->prepare("SELECT sa.role, sa.enable_live_chat_tiktok FROM tiktok_accounts ta JOIN system_accounts sa ON ta.account_id = sa.id WHERE ta.open_id = ?");
            $st_tt_acc->execute([$open_id]);
            $tt_acc_info = $st_tt_acc->fetch(PDO::FETCH_ASSOC);
            if ($tt_acc_info && ($tt_acc_info['role'] ?? '') !== 'admin' && (int)($tt_acc_info['enable_live_chat_tiktok'] ?? 1) === 0) {
                exit;
            }
        } catch (Exception $e) {}

        $sender_id = $payload['from_user_id'] ?? ($payload['sender_id'] ?? '');
        $sender_name = $payload['sender_name'] ?? 'Khách TikTok';
        $message_text = $payload['content'] ?? ($payload['text'] ?? ($payload['message'] ?? ''));

        if (!empty($sender_id) && !empty($message_text)) {
            try {
                // Save customer record
                $stmt_c = $pdo->prepare("
                    INSERT INTO tiktok_customers (open_id, sender_id, name, last_message, last_sender, unread_count, last_message_at)
                    VALUES (?, ?, ?, ?, 'customer', 1, NOW())
                    ON DUPLICATE KEY UPDATE
                        name = COALESCE(VALUES(name), name),
                        last_message = VALUES(last_message),
                        last_sender = 'customer',
                        unread_count = unread_count + 1,
                        last_message_at = NOW()
                ");
                $stmt_c->execute([$open_id, $sender_id, $sender_name, $message_text]);

                // Save message record
                $stmt_m = $pdo->prepare("
                    INSERT INTO tiktok_messages (open_id, sender_id, sender_name, sender_type, message, is_read, created_at)
                    VALUES (?, ?, ?, 'customer', ?, 0, NOW())
                ");
                $stmt_m->execute([$open_id, $sender_id, $sender_name, $message_text]);

                // Save live notification
                $stmt_n = $pdo->prepare("
                    INSERT INTO live_notifications (page_id, page_name, platform, type, sender_id, sender_name, snippet, raw_payload, is_read, created_at)
                    VALUES (?, ?, 'tiktok', 'message', ?, ?, ?, ?, 0, NOW())
                ");
                $stmt_n->execute([$open_id, 'TikTok', $sender_id, $sender_name, $message_text, $raw_input]);
            } catch (Exception $e) {
                error_log("TikTok Webhook Message error: " . $e->getMessage());
            }
        } elseif (!empty($event_type)) {
            // General TikTok Webhook Event notification
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO live_notifications (page_id, page_name, platform, type, sender_name, snippet, raw_payload, is_read, created_at)
                    VALUES (?, 'TikTok', 'tiktok', 'webhook', 'TikTok Event', ?, ?, 0, NOW())
                ");
                $snippet = "TikTok Event: " . $event_type;
                $stmt->execute([$open_id, $snippet, $raw_input]);
            } catch (Exception $e) {}
        }
    }

    if (!headers_sent()) {
        http_response_code(200);
        echo json_encode(['status' => 'success', 'msg' => 'Event processed']);
    }
    exit;
}
?>
