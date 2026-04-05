<?php
// cron/cleanup.php
// Script dọn dẹp DB + cập nhật followers — chạy 1 lần/ngày lúc 06:00 hoặc 23:59
// Crontab: 0 6 * * * php /path/to/cron/cleanup.php >> /tmp/fb_cleanup.log 2>&1
// AaPanel: N Days → 1 Day → Time: 06:00 | Type: Shell Script

ignore_user_abort(true);
set_time_limit(120);

// Chi chay 1 lan moi ngay
$flag_file = sys_get_temp_dir() . '/fb_cleanup_' . date('Y-m-d') . '.done';
if (file_exists($flag_file)) {
    echo "[" . date('H:i:s') . "] Cleanup da chay hom nay (" . trim(file_get_contents($flag_file)) . "). Bo qua.\n";
    exit;
}

require_once __DIR__ . '/../includes/db.php';

echo "\n========================================\n";
echo "  FB AUTO-CLEANUP — " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

// Doc cau hinh tu DB
$retain_days = 7;
try {
    $rd = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='cleanup_retain_days'");
    if ($rd) $retain_days = max(1, (int)($rd->fetchColumn() ?: 7));
} catch (Exception $e) {}

$history_retain_days = $retain_days * 2; // History giu gap doi (14 ngay neu retain=7)
echo "[INFO] Giu lai bai da dang: $retain_days ngay | Lich su: $history_retain_days ngay\n";

$stats = [
    'media_files'      => 0,
    'scheduled_posts'  => 0,
    'posts_history'    => 0,
    'campaigns'        => 0,
    'lock_files'       => 0,
];

// ── BUOC 1: Xoa media files cua bai da published > retain_days ───────────────
echo "\n[STEP 1] Xoa media files cu...\n";
try {
    $media_stmt = $pdo->prepare("
        SELECT id, media_path FROM scheduled_posts
        WHERE status = 'published'
          AND scheduled_time < DATE_SUB(NOW(), INTERVAL ? DAY)
          AND media_path IS NOT NULL
          AND media_path != ''
    ");
    $media_stmt->execute([$retain_days]);
    $media_rows = $media_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($media_rows as $row) {
        $mp = $row['media_path'];
        // Ho tro ca JSON array (multi-image) va path don
        $decoded = @json_decode($mp, true);
        $paths   = is_array($decoded) ? $decoded : [$mp];

        foreach ($paths as $p) {
            $p = trim($p);
            // Chi xoa file trong thu muc uploads/ — khong xoa file he thong
            if (strpos($p, 'uploads/') === false) continue;
            $full_path = __DIR__ . '/../' . ltrim($p, '/');
            if (!file_exists($full_path) || !is_file($full_path)) continue;

            // An toan: khong xoa neu con bai khac (pending/processing) dung chung file
            $usage_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM scheduled_posts
                WHERE status IN ('pending', 'processing', 'failed')
                  AND media_path LIKE ?
            ");
            $usage_stmt->execute(['%' . basename($p) . '%']);
            if ($usage_stmt->fetchColumn() > 0) continue;

            if (@unlink($full_path)) {
                $stats['media_files']++;
            }
        }
    }
    echo "  -> Da xoa: {$stats['media_files']} file media\n";
} catch (Exception $e) {
    echo "  [LOI] Media cleanup: " . $e->getMessage() . "\n";
}

// ── BUOC 2: Xoa scheduled_posts da published > retain_days ───────────────────
echo "\n[STEP 2] Xoa rows scheduled_posts cu (published > {$retain_days} ngay)...\n";
try {
    $del_pub = $pdo->prepare("
        DELETE FROM scheduled_posts
        WHERE status = 'published'
          AND scheduled_time < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $del_pub->execute([$retain_days]);
    $stats['scheduled_posts'] += $del_pub->rowCount();
    echo "  -> Da xoa: {$del_pub->rowCount()} rows (published)\n";
} catch (Exception $e) {
    echo "  [LOI] Delete published: " . $e->getMessage() . "\n";
}

// ── BUOC 3: Xoa scheduled_posts failed da het retry va qua han > retain_days ──
echo "\n[STEP 3] Xoa rows scheduled_posts cu (failed het retry > {$retain_days} ngay)...\n";
try {
    $max_retries_cfg = 3;
    $mr = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='max_retries'");
    if ($mr) $max_retries_cfg = (int)($mr->fetchColumn() ?: 3);

    $del_fail = $pdo->prepare("
        DELETE FROM scheduled_posts
        WHERE status = 'failed'
          AND retry_count >= ?
          AND scheduled_time < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $del_fail->execute([$max_retries_cfg, $retain_days]);
    $cnt_fail = $del_fail->rowCount();
    $stats['scheduled_posts'] += $cnt_fail;
    echo "  -> Da xoa: {$cnt_fail} rows (failed het retry)\n";
} catch (Exception $e) {
    echo "  [LOI] Delete failed: " . $e->getMessage() . "\n";
}

// ── BUOC 4: Xoa posts_history > history_retain_days ──────────────────────────
echo "\n[STEP 4] Xoa posts_history cu (> {$history_retain_days} ngay)...\n";
try {
    $del_hist = $pdo->prepare("
        DELETE FROM posts_history
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $del_hist->execute([$history_retain_days]);
    $stats['posts_history'] = $del_hist->rowCount();
    echo "  -> Da xoa: {$stats['posts_history']} rows lich su\n";
} catch (Exception $e) {
    echo "  [LOI] History cleanup: " . $e->getMessage() . "\n";
}

// ── BUOC 5: Xoa campaigns rong (khong con bai nao) ───────────────────────────
echo "\n[STEP 5] Xoa campaigns rong...\n";
try {
    $del_camp = $pdo->exec("
        DELETE FROM post_campaigns
        WHERE id NOT IN (
            SELECT DISTINCT campaign_id FROM scheduled_posts
            WHERE campaign_id IS NOT NULL
        )
    ");
    $stats['campaigns'] = (int)$del_camp;
    echo "  -> Da xoa: {$stats['campaigns']} campaigns rong\n";
} catch (Exception $e) {
    echo "  [LOI] Campaigns cleanup: " . $e->getMessage() . "\n";
}

// ── BUOC 6: OPTIMIZE TABLE de thu hoi disk space ─────────────────────────────
echo "\n[STEP 6] Toi uu tables (thu hoi disk space)...\n";
$total_deleted = array_sum([$stats['scheduled_posts'], $stats['posts_history'], $stats['campaigns']]);
if ($total_deleted > 50) {
    // Chi OPTIMIZE khi co du lieu bi xoa (tranh lock bang khong can thiet)
    try {
        $pdo->exec("OPTIMIZE TABLE scheduled_posts");
        echo "  -> OPTIMIZE TABLE scheduled_posts hoan tat\n";
    } catch (Exception $e) {
        echo "  [LOI] OPTIMIZE: " . $e->getMessage() . "\n";
    }
    try {
        $pdo->exec("OPTIMIZE TABLE posts_history");
        echo "  -> OPTIMIZE TABLE posts_history hoan tat\n";
    } catch (Exception $e) {}
} else {
    echo "  -> It du lieu bi xoa ($total_deleted rows), bo qua OPTIMIZE TABLE\n";
}

// ── BUOC 7: Xoa lock files cu trong /tmp/ ────────────────────────────────────
echo "\n[STEP 7] Xoa lock files cu...\n";
$tmp_dir = sys_get_temp_dir();
$patterns = [
    $tmp_dir . '/facebook_publish_worker_page_*.lock',
    $tmp_dir . '/facebook_comment_worker_account_*.lock',
    $tmp_dir . '/fb_cleanup_*.done', // Flag cua ngay hom qua tro ve truoc
];
foreach ($patterns as $pattern) {
    foreach (glob($pattern) ?: [] as $lf) {
        // Chi xoa neu file > 2 gio (tranh xoa lock cua process dang chay)
        if ((time() - filemtime($lf)) > 7200) {
            if (@unlink($lf)) $stats['lock_files']++;
        }
    }
}
// Xoa flag cua cac ngay cu (giu lai flag hom nay)
foreach (glob($tmp_dir . '/fb_cleanup_*.done') ?: [] as $f) {
    $fname = basename($f, '.done');
    $fdate = str_replace('fb_cleanup_', '', $fname);
    if ($fdate !== date('Y-m-d') && (time() - filemtime($f)) > 86400) {
        @unlink($f);
        $stats['lock_files']++;
    }
}
echo "  -> Da xoa: {$stats['lock_files']} file cu trong /tmp/\n";

// ── BAO CAO TONG KET ─────────────────────────────────────────────────────────
echo "\n========================================\n";
echo "  TONG KET CLEANUP\n";
echo "  Media files xoa:       {$stats['media_files']}\n";
echo "  Rows scheduled_posts:  {$stats['scheduled_posts']}\n";
echo "  Rows posts_history:    {$stats['posts_history']}\n";
echo "  Campaigns trong:       {$stats['campaigns']}\n";
echo "  Lock/flag files:       {$stats['lock_files']}\n";
echo "  Thoi gian:             " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

// Danh dau da chay hom nay
@file_put_contents($flag_file, date('Y-m-d H:i:s'));

echo "\n[DONE] Cleanup hoan tat. Flag: $flag_file\n";
