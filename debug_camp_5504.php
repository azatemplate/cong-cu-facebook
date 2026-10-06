<?php
// debug_camp_5504.php
// Script chẩn đoán và chạy trực tiếp Campaign từ Terminal
require_once __DIR__ . '/includes/db.php';

$campaign_id = isset($argv[1]) && is_numeric($argv[1]) ? intval($argv[1]) : 5504;

echo "=======================================================\n";
echo " 🛠️ CHẨN ĐOÁN & CHẠY TRỰC TIẾP CAMPAIGN #{$campaign_id} (CLI)\n";
echo "=======================================================\n\n";

// 1. Dọn dẹp File Lock của Campaign này
$lock_dir = __DIR__ . '/locks';
$lock_key = md5('camp_' . $campaign_id);
$lock_file = $lock_dir . "/publish_user_" . $lock_key . ".lock";
if (file_exists($lock_file)) {
    @unlink($lock_file);
    echo "  [1/4] ✅ Đã xóa File Lock cũ: {$lock_file}\n";
} else {
    echo "  [1/4] ✅ Không có File Lock kẹt.\n";
}

// 2. Kiểm tra thông tin Campaign
$stmt_c = $pdo->prepare("SELECT * FROM post_campaigns WHERE id = ?");
$stmt_c->execute([$campaign_id]);
$camp = $stmt_c->fetch(PDO::FETCH_ASSOC);

if (!$camp) {
    echo "  ❌ KHÔNG TÌM THẤY CAMPAIGN #{$campaign_id} TRONG DATABASE!\n";
    exit(1);
}

echo "  [2/4] Campaign: '{$camp['name']}' (Account ID: {$camp['account_id']})\n";

// 3. Reset các bài kẹt/lỗi/chờ về status = pending
$stmt_reset = $pdo->prepare("
    UPDATE scheduled_posts 
    SET status = 'pending', retry_count = 0, error_msg = NULL 
    WHERE campaign_id = ? AND status IN ('failed', 'processing', 'checkpoint', 'pending')
");
$stmt_reset->execute([$campaign_id]);
$reset_count = $stmt_reset->rowCount();
echo "  [3/4] ✅ Đã Reset {$reset_count} bài viết về trạng thái 'pending' (retry_count = 0).\n\n";

// Hiển thị danh sách các bài sẽ chạy trong Campaign
$stmt_posts = $pdo->prepare("SELECT id, page_id, post_type, scheduled_time, status, media_path FROM scheduled_posts WHERE campaign_id = ? ORDER BY scheduled_time ASC");
$stmt_posts->execute([$campaign_id]);
$posts = $stmt_posts->fetchAll(PDO::FETCH_ASSOC);

echo "📋 DANH SÁCH BÀI VIẾT TRONG CAMPAIGN #{$campaign_id}:\n";
if (empty($posts)) {
    echo "   ⚠️ Không có bài viết nào trong Campaign này.\n";
} else {
    foreach ($posts as $idx => $p) {
        $num = $idx + 1;
        echo "   #{$num}. Post ID #{$p['id']} | Type: {$p['post_type']} | Page ID: {$p['page_id']} | Time: {$p['scheduled_time']} | Status: {$p['status']}\n";
    }
}

echo "\n-------------------------------------------------------\n";
echo "🚀 BẮT ĐẦU CHẠY PUBLISH WORKER CHO CAMPAIGN #{$campaign_id}...\n";
echo "-------------------------------------------------------\n\n";

// 4. Kích hoạt trực tiếp publish_worker.php
$_SERVER['argv'] = [__DIR__ . '/cron/publish_worker.php', 'camp_' . $campaign_id, '1'];
$argv = $_SERVER['argv'];
require __DIR__ . '/cron/publish_worker.php';
