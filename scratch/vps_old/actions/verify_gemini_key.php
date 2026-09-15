<?php
// actions/verify_gemini_key.php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
if (empty($key)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing key']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status FROM gemini_keys WHERE api_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        if ($row['status'] == 1) {
            echo json_encode([
                'status' => 'success'
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Key is inactive']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid key']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
