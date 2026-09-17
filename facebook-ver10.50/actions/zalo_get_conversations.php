<?php
// actions/zalo_get_conversations.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/redis_queue.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

$oa_id = isset($_GET['oa_id']) ? trim($_GET['oa_id']) : '';

if (empty($oa_id)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng cung cấp OA ID.']);
    exit;
}

$account_id = $_SESSION['account_id'];
session_write_close();

$search = trim($_GET['search'] ?? '');
$rq = RedisQueue::getInstance();
$cache_key = "lc_zalo_convs:a{$account_id}_oa{$oa_id}_s" . md5($search);

if ($search === '') {
    $cached_data = $rq->getCache($cache_key);
    if ($cached_data !== false && is_array($cached_data)) {
        echo json_encode($cached_data);
        exit;
    }
}

try {
    // Verify that this OA belongs to the current user
    $stmt_oa = $pdo->prepare("SELECT oa_id FROM zalo_oas WHERE oa_id = ? AND account_id = ?");
    $stmt_oa->execute([$oa_id, $account_id]);
    if (!$stmt_oa->fetch()) {
        echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền truy cập kênh OA này.']);
        exit;
    }

    $search_param = '%' . $search . '%';
    $search_clean = '%' . preg_replace('/[\s\.\-\(\)]/', '', $search) . '%';

    $sql = "
        SELECT 
            m.sender_id,
            COALESCE(NULLIF(c.name, ''), m.sender_name, 'Khách hàng Zalo') AS sender_name,
            COALESCE(c.avatar, 'https://ui-avatars.com/api/?name=Zalo') AS sender_avatar,
            c.name AS cust_name,
            c.phone,
            c.province,
            c.consulted,
            m.snippet,
            m.unread_count,
            m.updated_time
        FROM zalo_messages m
        LEFT JOIN zalo_customers c ON m.oa_id = c.oa_id AND m.sender_id = c.sender_id
        WHERE m.oa_id = :oa_id
    ";
    if ($search !== '') {
        $sql .= " AND (COALESCE(c.name, m.sender_name) LIKE :search OR m.snippet LIKE :search OR m.sender_id LIKE :search OR c.phone LIKE :search2 OR REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '.', ''), '-', '') LIKE :search_clean)";
    }
    $sql .= " ORDER BY m.updated_time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':oa_id', $oa_id, PDO::PARAM_STR);
    if ($search !== '') {
        $stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
        $stmt->bindValue(':search2', $search_param, PDO::PARAM_STR);
        $stmt->bindValue(':search_clean', $search_clean, PDO::PARAM_STR);
    }
    $stmt->execute();
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'status' => 'success',
        'data' => $conversations
    ];

    if ($search === '') {
        $rq->setCache($cache_key, 2, $response);
    }

    echo json_encode($response);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
}
