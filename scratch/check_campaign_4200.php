<?php
// scratch/check_campaign_4200.php
require_once __DIR__ . '/../includes/db.php';

$campaign_id = isset($_GET['id']) ? (int)$_GET['id'] : 4200;

header('Content-Type: text/plain; charset=utf-8');

echo "=========================================================\n";
echo " KIỂM TRA CHI TIẾT CAMPAIGN #{$campaign_id}\n";
echo "=========================================================\n\n";

// 1. Thông tin Campaign
$stmt_c = $pdo->prepare("SELECT * FROM post_campaigns WHERE id = ?");
$stmt_c->execute([$campaign_id]);
$campaign = $stmt_c->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    echo "❌ KHÔNG TÌM THẤY CAMPAIGN #{$campaign_id} TRONG DATABASE!\n";
    exit;
}

echo "📌 Tên Campaign: {$campaign['name']}\n";
echo "📌 Trạng thái Campaign: " . ($campaign['status'] ?? 'N/A') . "\n";
echo "📌 Thời gian tạo: {$campaign['created_at']}\n\n";

// 2. Thống kê bài viết
$stmt_s = $pdo->prepare("
    SELECT status, COUNT(*) as cnt 
    FROM scheduled_posts 
    WHERE campaign_id = ? 
    GROUP BY status
");
$stmt_s->execute([$campaign_id]);
$stats = $stmt_s->fetchAll(PDO::FETCH_KEY_PAIR);

echo "📊 THỐNG KÊ TRẠNG THÁI BÀI ĐĂNG:\n";
echo "   - Đã đăng (published): " . ($stats['published'] ?? 0) . "\n";
echo "   - Đang chờ (pending):   " . ($stats['pending'] ?? 0) . "\n";
echo "   - Đang xử lý (processing): " . ($stats['processing'] ?? 0) . "\n";
echo "   - Thất bại (failed):    " . ($stats['failed'] ?? 0) . "\n";
echo "   - Checkpoint:           " . ($stats['checkpoint'] ?? 0) . "\n\n";

// 3. Danh sách các bài bị lỗi nhiều lần nhất
$stmt_f = $pdo->prepare("
    SELECT id, page_id, post_type, status, retry_count, error_msg, scheduled_time, updated_at
    FROM scheduled_posts
    WHERE campaign_id = ? AND status IN ('failed', 'checkpoint', 'processing')
    ORDER BY retry_count DESC, id DESC
    LIMIT 30
");
$stmt_f->execute([$campaign_id]);
$failed_posts = $stmt_f->fetchAll(PDO::FETCH_ASSOC);

if (empty($failed_posts)) {
    echo "✅ KHÔNG CÓ BÀI NÀO BỊ LỖI TRONG CAMPAIGN NÀY.\n";
} else {
    echo "⚠️ DANH SÁCH BÀI LỖI KẸT THỬ LẠI (TỐI ĐA 30 BÀI):\n";
    echo "---------------------------------------------------------------------------------------------------\n";
    echo sprintf("%-8s | %-12s | %-10s | %-10s | %-8s | %s\n", "POST ID", "POST TYPE", "STATUS", "RETRY", "PAGE ID", "ERROR MSG");
    echo "---------------------------------------------------------------------------------------------------\n";
    foreach ($failed_posts as $p) {
        $err = str_replace(["\r", "\n"], ' ', $p['error_msg'] ?? '');
        $err_short = mb_strimwidth($err, 0, 45, '...');
        echo sprintf("%-8d | %-12s | %-10s | x%-7d | %-8s | %s\n", 
            $p['id'], 
            $p['post_type'], 
            $p['status'], 
            (int)$p['retry_count'], 
            $p['page_id'], 
            $err_short
        );
    }
}
echo "\n=========================================================\n";
