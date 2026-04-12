<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';
require_once __DIR__ . '/includes/security.php';
header('Content-Type: text/plain; charset=utf-8');

$aid = $_SESSION['account_id'] ?? 0;
echo "=== SESSION INFO ===\n";
echo "account_id: $aid\n\n";

// Pages của account hiện tại
echo "=== Pages của account_id=$aid ===\n";
$stmt = $pdo->prepare("SELECT p.page_id, p.name, p.user_id FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = ?");
$stmt->execute([$aid]);
$myPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($myPages as $r) {
    echo "• {$r['page_id']} - {$r['name']} (user_id={$r['user_id']})\n";
}
echo "\n";

// Thống kê page_notifications theo type và is_read
echo "=== page_notifications — Thống kê theo TYPE ===\n";
$stat = $pdo->query("SELECT type, is_read, COUNT(*) as cnt FROM page_notifications GROUP BY type, is_read ORDER BY type, is_read");
foreach ($stat->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $readStr = $r['is_read'] ? 'đã đọc' : 'chưa đọc';
    echo "• type={$r['type']} | {$readStr} | số lượng={$r['cnt']}\n";
}
echo "\n";

// 10 notifications mới nhất
echo "=== Notifications (10 mới nhất) ===\n";
$stmt3 = $pdo->query("SELECT id, page_id, type, is_read, LEFT(snippet,40) as snippet, created_at FROM page_notifications ORDER BY id DESC LIMIT 10");
foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $readStr = $r['is_read'] ? '✓read' : 'unread';
    echo "[{$r['id']}] {$r['type']} | {$readStr} | page={$r['page_id']} | {$r['snippet']} | {$r['created_at']}\n";
}
echo "\n";

// Kiểm tra webhook subscription thực tế từ Facebook
echo "=== Kiểm tra Webhook Subscription trên Facebook ===\n";
if (empty($myPages)) {
    echo "Không có page nào cho account này.\n";
} else {
    foreach (array_slice($myPages, 0, 3) as $p) {
        $stmtToken = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ?");
        $stmtToken->execute([$p['page_id']]);
        $tokenRow = $stmtToken->fetch(PDO::FETCH_ASSOC);
        if (!$tokenRow || !$tokenRow['access_token']) { echo "• {$p['name']}: Không có token\n"; continue; }
        $token = decryptData($tokenRow['access_token']);
        if (!$token) { echo "• {$p['name']}: Lỗi giải mã token\n"; continue; }
        $resp = fb_api_request("{$p['page_id']}/subscribed_apps", ['access_token' => $token], 'GET');
        if (!empty($resp['data']['data'])) {
            foreach ($resp['data']['data'] as $app) {
                $fields = implode(', ', $app['subscribed_fields'] ?? []);
                echo "• {$p['name']}: ✅ fields=[{$fields}]\n";
                if (!in_array('feed', $app['subscribed_fields'] ?? [])) {
                    echo "  ⚠️  THIẾU 'feed' — comment không được gửi về webhook!\n";
                }
            }
        } elseif (!empty($resp['data']['error'])) {
            echo "• {$p['name']}: ❌ {$resp['data']['error']['message']}\n";
        } else {
            echo "• {$p['name']}: ⚠️ Chưa subscribe app nào\n";
        }
    }
}
echo "\n";

// =================================================
// KIỂM TRA ĐẶC BIỆT cho page 148579325014720
// =================================================
echo "=== Kiểm tra riêng page 148579325014720 (CỐP PHA VIỆT) ===\n";
$targetPageId = '148579325014720';
$stmtT = $pdo->prepare("SELECT access_token, name FROM pages WHERE page_id = ?");
$stmtT->execute([$targetPageId]);
$targetPage = $stmtT->fetch(PDO::FETCH_ASSOC);
if ($targetPage && $targetPage['access_token']) {
    $targetToken = decryptData($targetPage['access_token']);
    if ($targetToken) {
        $resp2 = fb_api_request("{$targetPageId}/subscribed_apps", ['access_token' => $targetToken], 'GET');
        if (!empty($resp2['data']['data'])) {
            foreach ($resp2['data']['data'] as $app) {
                $fields = implode(', ', $app['subscribed_fields'] ?? []);
                echo "• {$app['name_with_namespace']}: fields=[{$fields}]\n";
                $hasFeed = in_array('feed', $app['subscribed_fields'] ?? []);
                if ($hasFeed) {
                    echo "  ✅ 'feed' ĐÃ được subscribe → Comment sẽ đến\n";
                    echo "  ⚠️  Nếu vẫn không nhận → Vấn đề ở Facebook App Dashboard (app level)\n";
                } else {
                    echo "  ❌ THIẾU 'feed' → Comment KHÔNG được gửi về webhook\n";
                    echo "  👉 Fix: Vào live_comments.php để trigger re-subscribe\n";
                }
            }
        } elseif (!empty($resp2['data']['error'])) {
            echo "❌ Lỗi API: {$resp2['data']['error']['message']}\n";
        } else {
            echo "⚠️ Page chưa subscribe bất kỳ app nào\n";
        }
    } else {
        echo "❌ Không giải mã được token\n";
    }
} else {
    echo "⚠️ Page không có trong DB hoặc không có token\n";
}
echo "\n";

// Đếm feed events trong webhook_debug.txt
echo "=== Thống kê events trong webhook_debug.txt ===\n";
$dbgFile = __DIR__ . '/webhook_debug.txt';
if (file_exists($dbgFile)) {
    $content = file_get_contents($dbgFile);
    $msgCount  = substr_count($content, '"messaging"');
    $feedCount = substr_count($content, '"changes"');
    echo "• Messaging events (tin nhắn): $msgCount\n";
    echo "• Feed/Changes events (bình luận):  $feedCount\n";
    if ($feedCount === 0) {
        echo "  ❌ KHÔNG có feed event nào → Facebook App chưa cấu hình 'feed' webhook field\n";
        echo "  👉 Fix: Facebook Developer Portal → App → Webhooks → Page → Tick 'feed'\n";
    }
    echo "\n--- 500 ký tự cuối webhook_debug.txt ---\n";
    echo substr($content, -500);
} else {
    echo "File không tồn tại.\n";
}
