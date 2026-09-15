<?php
// actions/debug_web_chat.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../setup_website_chat.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Kiểm tra số lượng visitor
    $stmt_v = $pdo->query("SELECT * FROM web_visitors ORDER BY id DESC LIMIT 20");
    $visitors = $stmt_v->fetchAll(PDO::FETCH_ASSOC);

    // Nếu chưa có dữ liệu -> Tạo 1 tin nhắn test mẫu để kiểm tra ngay
    if (empty($visitors)) {
        $test_uuid = 'web_test_' . time();
        $ins = $pdo->prepare("INSERT INTO web_visitors (account_id, visitor_uuid, visitor_num, name, phone, notes) VALUES (1, ?, 1, 'Khách vãng lai #1', '0912345678', 'Cần tư vấn báo giá')");
        $ins->execute([$test_uuid]);

        $ins_m = $pdo->prepare("INSERT INTO web_messages (account_id, visitor_uuid, sender_type, sender_name, message) VALUES (1, ?, 'user', 'Khách hàng', 'Xin chào, em muốn tư vấn sản phẩm!')");
        $ins_m->execute([$test_uuid]);

        $ins_b = $pdo->prepare("INSERT INTO web_messages (account_id, visitor_uuid, sender_type, sender_name, message) VALUES (1, ?, 'bot', 'Gấu cười', 'Dạ em chào anh/chị, em là Gấu cười. Anh/chị cần hỗ trợ dịch vụ gì ạ?')");
        $ins_b->execute([$test_uuid]);

        $stmt_v = $pdo->query("SELECT * FROM web_visitors ORDER BY id DESC LIMIT 20");
        $visitors = $stmt_v->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2. Kiểm tra số lượng messages
    $stmt_m = $pdo->query("SELECT * FROM web_messages ORDER BY id DESC LIMIT 20");
    $messages = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // 3. Kiểm tra cấu hình widget
    $stmt_c = $pdo->query("SELECT * FROM web_chat_configs");
    $configs = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'visitors_count' => count($visitors),
        'visitors' => $visitors,
        'messages_count' => count($messages),
        'messages' => $messages,
        'configs' => $configs
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
