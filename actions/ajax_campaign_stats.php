<?php
// ajax_campaign_stats.php
@set_time_limit(0);
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
$_s_account_id = $_SESSION['account_id'] ?? 0;
session_write_close();

if (!$_s_account_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$ids_str = $_GET['ids'] ?? '';
$ids = array_filter(array_map('intval', explode(',', $ids_str)));
if (empty($ids)) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

$in_ids = implode(',', $ids);
$stats_map = [];

try {
    // 1. Thống kê số lượng bài đăng (Ép dùng index idx_camp_status, tức thì < 1ms)
    $stats_stmt = $pdo->query("
        SELECT
            campaign_id,
            status,
            comment_done,
            COUNT(*) AS cnt
        FROM scheduled_posts USE INDEX (idx_camp_status)
        WHERE campaign_id IN ($in_ids)
        GROUP BY campaign_id, status, comment_done
    ");
    if ($stats_stmt) {
        while ($row = $stats_stmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int)$row['campaign_id'];
            $st  = $row['status'];
            $cd  = (int)$row['comment_done'];
            $cnt = (int)$row['cnt'];

            if (!isset($stats_map[$cid])) {
                $stats_map[$cid] = [
                    'cnt_published'      => 0,
                    'cnt_pending'        => 0,
                    'cnt_processing'     => 0,
                    'cnt_failed'         => 0,
                    'cnt_checkpoint'     => 0,
                    'cnt_cmt_done'       => 0,
                    'cnt_cmt_pending'    => 0,
                    'cnt_cmt_processing' => 0,
                    'cnt_total'          => 0,
                    'fb_users'           => ''
                ];
            }

            $stats_map[$cid]['cnt_total'] += $cnt;
            if ($st === 'published')  $stats_map[$cid]['cnt_published']  += $cnt;
            if ($st === 'pending')    $stats_map[$cid]['cnt_pending']    += $cnt;
            if ($st === 'processing') $stats_map[$cid]['cnt_processing'] += $cnt;
            if ($st === 'failed')     $stats_map[$cid]['cnt_failed']     += $cnt;
            if ($st === 'checkpoint') $stats_map[$cid]['cnt_checkpoint'] += $cnt;

            if ($cd === 1) $stats_map[$cid]['cnt_cmt_done']       += $cnt;
            if ($cd === 0) $stats_map[$cid]['cnt_cmt_pending']    += $cnt;
            if ($cd === 2) $stats_map[$cid]['cnt_cmt_processing'] += $cnt;
        }
    }

    // 2. Lấy tên kênh/trang (Ép dùng index idx_camp_type_page, loại bỏ 504 Timeout)
    $camp_pages_stmt = $pdo->query("
        SELECT DISTINCT campaign_id, post_type, page_id 
        FROM scheduled_posts USE INDEX (idx_camp_type_page)
        WHERE campaign_id IN ($in_ids)
    ");

    if ($camp_pages_stmt) {
        $rows = $camp_pages_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $fb_page_ids = [];
        $ig_ids      = [];
        $yt_ids      = [];
        $bc_ids      = [];
        $tt_ids      = [];

        foreach ($rows as $r) {
            $pid  = trim($r['page_id'] ?? '');
            $type = $r['post_type'] ?? '';
            if (empty($pid)) continue;

            if (strpos($type, 'Buffer') === 0) {
                $bc_ids[] = $pid;
            } elseif ($type === 'YouTube') {
                $yt_ids[] = $pid;
            } elseif ($type === 'TikTok') {
                $tt_ids[] = intval($pid);
            } elseif (strpos($type, 'Instagram') === 0) {
                $ig_ids[] = intval($pid);
            } else {
                $fb_page_ids[] = $pid;
            }
        }

        $name_map = ['fb' => [], 'ig' => [], 'yt' => [], 'bc' => [], 'tt' => []];

        if (!empty($fb_page_ids)) {
            $fb_page_ids = array_unique($fb_page_ids);
            $in_fb = "'" . implode("','", array_map('addslashes', $fb_page_ids)) . "'";
            $st_fb = $pdo->query("SELECT p.page_id, u.name FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id IN ($in_fb)");
            if ($st_fb) {
                while ($fbr = $st_fb->fetch(PDO::FETCH_ASSOC)) {
                    $name_map['fb'][$fbr['page_id']] = $fbr['name'];
                }
            }
        }

        if (!empty($ig_ids)) {
            $ig_ids = array_unique(array_filter($ig_ids));
            if (!empty($ig_ids)) {
                $in_ig = implode(',', $ig_ids);
                $st_ig = $pdo->query("SELECT id, username FROM instagram_accounts WHERE id IN ($in_ig)");
                if ($st_ig) {
                    while ($igr = $st_ig->fetch(PDO::FETCH_ASSOC)) {
                        $name_map['ig'][$igr['id']] = $igr['username'];
                    }
                }
            }
        }

        if (!empty($tt_ids)) {
            $tt_ids = array_unique(array_filter($tt_ids));
            if (!empty($tt_ids)) {
                $in_tt = implode(',', $tt_ids);
                $st_tt = $pdo->query("SELECT id, display_name FROM tiktok_accounts WHERE id IN ($in_tt)");
                if ($st_tt) {
                    while ($ttr = $st_tt->fetch(PDO::FETCH_ASSOC)) {
                        $name_map['tt'][$ttr['id']] = $ttr['display_name'];
                    }
                }
            }
        }

        if (!empty($yt_ids)) {
            $yt_ids = array_unique($yt_ids);
            $in_yt = "'" . implode("','", array_map('addslashes', $yt_ids)) . "'";
            $st_yt = $pdo->query("SELECT channel_id, id, channel_title FROM youtube_channels WHERE channel_id IN ($in_yt) OR id IN ($in_yt)");
            if ($st_yt) {
                while ($ytr = $st_yt->fetch(PDO::FETCH_ASSOC)) {
                    $name_map['yt'][$ytr['channel_id']] = $ytr['channel_title'];
                    $name_map['yt'][$ytr['id']] = $ytr['channel_title'];
                }
            }
        }

        if (!empty($bc_ids)) {
            $bc_ids = array_unique($bc_ids);
            $in_bc = "'" . implode("','", array_map('addslashes', $bc_ids)) . "'";
            $st_bc = $pdo->query("SELECT channel_id, channel_name FROM buffer_channels WHERE channel_id IN ($in_bc)");
            if ($st_bc) {
                while ($bcr = $st_bc->fetch(PDO::FETCH_ASSOC)) {
                    $name_map['bc'][$bcr['channel_id']] = $bcr['channel_name'];
                }
            }
        }

        $camp_names = [];
        foreach ($rows as $r) {
            $cid  = (int)$r['campaign_id'];
            $pid  = trim($r['page_id'] ?? '');
            $type = $r['post_type'] ?? '';
            
            $name = '';
            if (strpos($type, 'Buffer') === 0) {
                $name = $name_map['bc'][$pid] ?? '';
            } elseif ($type === 'YouTube') {
                $name = $name_map['yt'][$pid] ?? '';
            } elseif ($type === 'TikTok') {
                $name = $name_map['tt'][intval($pid)] ?? '';
            } elseif (strpos($type, 'Instagram') === 0) {
                $name = $name_map['ig'][intval($pid)] ?? '';
            } else {
                $name = $name_map['fb'][$pid] ?? '';
            }

            if (!empty($name)) {
                if (!isset($camp_names[$cid])) {
                    $camp_names[$cid] = [];
                }
                if (!in_array($name, $camp_names[$cid])) {
                    $camp_names[$cid][] = $name;
                }
            }
        }

        // Lấy tên Nhóm Fanpage cho các chiến dịch chọn theo Nhóm
        $camp_group_names = [];
        try {
            $st_c_group = $pdo->query("
                SELECT pc.id AS campaign_id, pg.name AS group_name
                FROM post_campaigns pc
                JOIN page_groups pg ON pc.group_id = pg.id
                WHERE pc.id IN ($in_ids) AND pc.group_id IS NOT NULL
            ");
            if ($st_c_group) {
                while ($cgr = $st_c_group->fetch(PDO::FETCH_ASSOC)) {
                    $camp_group_names[(int)$cgr['campaign_id']] = '📂 ' . $cgr['group_name'];
                }
            }
        } catch (Exception $e) {}

        foreach ($rows as $r) {
            $cid = (int)$r['campaign_id'];
            if (!isset($stats_map[$cid])) {
                $stats_map[$cid] = [];
            }
            if (!empty($camp_group_names[$cid])) {
                $stats_map[$cid]['fb_users'] = $camp_group_names[$cid];
            }
        }

        foreach ($camp_names as $cid => $names_arr) {
            if (!isset($stats_map[$cid])) {
                $stats_map[$cid] = [];
            }
            if (empty($stats_map[$cid]['fb_users'])) {
                $stats_map[$cid]['fb_users'] = implode(', ', $names_arr);
            }
        }
    }
} catch (Exception $e) {}

// Đảm bảo tất cả id được yêu cầu đều có dữ liệu trả về
$response_data = [];
foreach ($ids as $id) {
    $st = $stats_map[$id] ?? [];
    $response_data[$id] = [
        'cnt_published'      => $st['cnt_published'] ?? 0,
        'cnt_pending'        => $st['cnt_pending'] ?? 0,
        'cnt_processing'     => $st['cnt_processing'] ?? 0,
        'cnt_failed'         => $st['cnt_failed'] ?? 0,
        'cnt_checkpoint'     => $st['cnt_checkpoint'] ?? 0,
        'cnt_cmt_done'       => $st['cnt_cmt_done'] ?? 0,
        'cnt_cmt_pending'    => $st['cnt_cmt_pending'] ?? 0,
        'cnt_cmt_processing' => $st['cnt_cmt_processing'] ?? 0,
        'cnt_total'          => $st['cnt_total'] ?? 0,
        'fb_users'           => $st['fb_users'] ?? ''
    ];
}

echo json_encode(['status' => 'success', 'data' => $response_data]);
