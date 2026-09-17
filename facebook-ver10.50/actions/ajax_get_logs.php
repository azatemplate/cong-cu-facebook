<?php
// actions/ajax_get_logs.php — Lay, loc, bat/tat va xoa log chi tiet
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

// Authenticate Admin
$cron_secret = '';
try {
    $cs = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='cron_secret'");
    if ($cs) $cron_secret = trim($cs->fetchColumn() ?: '');
} catch (Exception $e) {}

$bypass_ok = ($cron_secret && isset($_GET['secret']) && hash_equals($cron_secret, $_GET['secret']));

if (!$bypass_ok) {
    if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => 'Unauthorized: Ban can quyen Admin.']);
        exit;
    }
}

$action   = isset($_GET['action']) ? trim($_GET['action']) : '';
$account_id_param = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
$post_id  = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
$log_type = isset($_GET['log_type']) ? trim($_GET['log_type']) : 'publish';
$search   = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit    = isset($_GET['lines']) ? min(1000, max(20, intval($_GET['lines']))) : 300;

// ── ACTION: Fetch Failed Posts for a specific Account ────────────────
if ($account_id_param > 0) {
    try {
        $today = date('Y-m-d');
        $today_start = $today . ' 00:00:00';
        $today_end   = $today . ' 23:59:59';

        $stmt = $pdo->prepare("
            SELECT sp.id, sp.page_id, sp.post_type, sp.status, sp.scheduled_time, sp.updated_at, sp.error_msg, sp.media_path, sp.retry_count,
                   sa.username AS account_name
            FROM scheduled_posts sp
            LEFT JOIN system_accounts sa ON sp.account_id = sa.id
            WHERE sp.account_id = ?
              AND sp.status = 'failed'
              AND sp.scheduled_time >= ? AND sp.scheduled_time <= ?
            ORDER BY sp.scheduled_time DESC, sp.id DESC
            LIMIT 500
        ");
        $stmt->execute([$account_id_param, $today_start, $today_end]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status'     => 'ok',
            'account_id' => $account_id_param,
            'posts'      => $posts,
            'count'      => count($posts)
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi đọc CSDL: ' . $e->getMessage()]);
        exit;
    }
}

$tmp_dir = sys_get_temp_dir();
$log_map = [
    'publish'  => $tmp_dir . '/fb_publish.log',
    'comment'  => $tmp_dir . '/fb_comment.log',
    'insights' => $tmp_dir . '/fb_comment_insights.log',
    'worker'   => __DIR__ . '/../cron/worker_error.log'
];

// ── ACTION: Check or Toggle Detailed Logging Status ────────────────────────
if ($action === 'status' || $action === 'toggle') {
    $current_enabled = true; // Default enabled
    try {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'detailed_logging_enabled'");
        if ($stmt) {
            $val = $stmt->fetchColumn();
            if ($val === '0') $current_enabled = false;
        }
    } catch (Exception $e) {}

    if ($action === 'toggle') {
        $new_state = $current_enabled ? '0' : '1';
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('detailed_logging_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$new_state, $new_state]);
            $current_enabled = ($new_state === '1');
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => 'Không thể cập nhật trạng thái ghi log: ' . $e->getMessage()]);
            exit;
        }
    }

    echo json_encode([
        'status'  => 'ok',
        'enabled' => $current_enabled,
        'msg'     => $current_enabled ? 'Ghi log chi tiết đang BẬT' : 'Ghi log chi tiết đang TẮT'
    ]);
    exit;
}

// ── ACTION: Clear / Truncate Log Files ──────────────────────────────────────
if ($action === 'clear') {
    $cleared_files = [];
    if (isset($_GET['clear_all']) && $_GET['clear_all'] === '1') {
        foreach ($log_map as $key => $file_path) {
            if (file_exists($file_path)) {
                @file_put_contents($file_path, '');
                $cleared_files[] = basename($file_path);
            }
        }
    } else {
        $file_path = $log_map[$log_type] ?? $log_map['publish'];
        if (file_exists($file_path)) {
            @file_put_contents($file_path, '');
            $cleared_files[] = basename($file_path);
        }
    }

    echo json_encode([
        'status' => 'ok',
        'msg'    => 'Đã xóa sạch dữ liệu file log: ' . implode(', ', $cleared_files)
    ]);
    exit;
}

// ── DEFAULT ACTION: Fetch Log Lines ──────────────────────────────────────────
$file_path = $log_map[$log_type] ?? $log_map['publish'];

// Get current logging enabled state
$logging_enabled = true;
try {
    $st = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'detailed_logging_enabled'");
    if ($st && $st->fetchColumn() === '0') $logging_enabled = false;
} catch (Exception $e) {}

if (!file_exists($file_path) || filesize($file_path) === 0) {
    echo json_encode([
        'status'          => 'ok',
        'logging_enabled' => $logging_enabled,
        'logs'            => ['[Chưa có dữ liệu log hoặc file log rỗng]'],
        'count'           => 0,
        'post_id'         => $post_id,
        'file_name'       => basename($file_path),
        'file_size'       => 0
    ]);
    exit;
}

// Read lines from the log file (from end of file backwards)
$file_size = filesize($file_path);
$mtime = date('Y-m-d H:i:s', filemtime($file_path));

$lines = [];
$fp = @fopen($file_path, 'r');
if ($fp) {
    $buffer = [];
    while (($line = fgets($fp)) !== false) {
        $buffer[] = rtrim($line, "\r\n");
    }
    fclose($fp);
    
    $buffer = array_reverse($buffer);
    $target_tag = $post_id > 0 ? "[POST #$post_id]" : '';
    
    foreach ($buffer as $line) {
        if (empty(trim($line))) continue;
        
        if (!empty($target_tag) && strpos($line, $target_tag) === false) {
            continue;
        }

        if (!empty($search) && mb_stripos($line, $search) === false) {
            continue;
        }

        $lines[] = $line;
        if (count($lines) >= $limit) break;
    }
}

if ($post_id > 0 && empty($lines)) {
    try {
        $stmt = $pdo->prepare("SELECT id, status, error_msg, scheduled_time, updated_at FROM scheduled_posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $lines[] = "[" . ($p['updated_at'] ?? $p['scheduled_time']) . "] [POST #{$p['id']}] Trạng thái: {$p['status']} | Log lỗi lưu DB: " . ($p['error_msg'] ?: 'Không có ghi nhận lỗi');
        }
    } catch (Exception $e) {}
}

echo json_encode([
    'status'          => 'ok',
    'logging_enabled' => $logging_enabled,
    'logs'            => array_reverse($lines),
    'count'           => count($lines),
    'post_id'         => $post_id,
    'file_name'       => basename($file_path),
    'file_size'       => $file_size,
    'last_modified'   => $mtime
]);
