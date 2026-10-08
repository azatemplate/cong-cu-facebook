<?php
// scratch/cleanup_spam_pages_4200.php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=========================================================\n";
echo " XÓA HOÀN TOÀN PAGE BỊ LỖI SPAM (HTTP 400) KHỎI CAMPAIGN\n";
echo "=========================================================\n\n";

// 1. Tìm các Page bị lỗi HTTP 400 Spam Rate Limit trong scheduled_posts
$stmt = $pdo->query("
    SELECT DISTINCT page_id, campaign_id, error_msg 
    FROM scheduled_posts 
    WHERE error_msg LIKE '%giới hạn tần suất%' 
       OR error_msg LIKE '%Để bảo vệ cộng đồng khỏi spam%'
       OR error_msg LIKE '%spam%'
");
$spam_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($spam_pages)) {
    echo "✅ KHÔNG CÓ PAGE NÀO BỊ KHÓA SPAM TRONG HỆ THỐNG.\n";
    exit;
}

foreach ($spam_pages as $sp) {
    $pid = $sp['page_id'];
    $cid = $sp['campaign_id'];

    echo "📍 Phát hiện Page ID #{$pid} trong Campaign #{$cid} bị lỗi khóa Spam.\n";

    // Đếm số bài sẽ bị xóa hoàn toàn
    $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM scheduled_posts WHERE page_id = ? AND campaign_id = ?");
    $stmt_cnt->execute([$pid, $cid]);
    $total_del = (int)$stmt_cnt->fetchColumn();

    // XÓA HOÀN TOÀN TẤT CẢ BÀI DẠNG THUỘC PAGE NÀY TRONG CAMPAIGN
    $stmt_del = $pdo->prepare("DELETE FROM scheduled_posts WHERE page_id = ? AND campaign_id = ?");
    $stmt_del->execute([$pid, $cid]);

    // Cập nhật lại total_posts trong post_campaigns
    $pdo->prepare("UPDATE post_campaigns SET total_posts = GREATEST(0, total_posts - ?) WHERE id = ?")->execute([$total_del, $cid]);

    echo "   -> ĐÃ XÓA HOÀN TOÀN {$total_del} BÀI CỦA PAGE #{$pid} KHỎI CAMPAIGN #{$cid}. PAGE NÀY KHÔNG CÒN TỒN TẠI TRONG CAMPAIGN NỮA!\n\n";
}

echo "=========================================================\n";
echo " XÓA HOÀN TOÀN XONG!\n";
echo "=========================================================\n";
