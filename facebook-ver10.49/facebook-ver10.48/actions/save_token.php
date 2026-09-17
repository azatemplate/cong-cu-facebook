<?php
session_start();
set_time_limit(0); // Prevent PHP execution timeout when fetching thousands of pages
if (!isset($_SESSION['account_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_input = isset($_POST['access_tokens']) ? $_POST['access_tokens'] : (isset($_POST['access_token']) ? $_POST['access_token'] : '');
    $tokens = array_values(array_filter(array_map('trim', explode("\n", $raw_input))));
    $account_id = $_SESSION['account_id'];
    session_write_close(); // Release session file lock so other requests/pages don't freeze
    
    if (empty($tokens)) {
        header('Location: ../token_management.php?status=error&msg=' . urlencode('Token không được để trống.'));
        exit;
    }

    // 0. Fetch App Credentials for Token Exchange
    $stmt_app = $pdo->prepare("SELECT fb_app_id, fb_app_secret FROM system_accounts WHERE id = ?");
    $stmt_app->execute([$account_id]);
    $current_acc = $stmt_app->fetch(PDO::FETCH_ASSOC);

    $app_id = null;
    $app_secret = null;

    if (!empty($current_acc['fb_app_id']) && !empty($current_acc['fb_app_secret'])) {
        $app_id = $current_acc['fb_app_id'];
        $app_secret = $current_acc['fb_app_secret'];
    } else {
        $stmt_admin = $pdo->prepare("SELECT fb_app_id, fb_app_secret FROM system_accounts WHERE role = 'admin' LIMIT 1");
        $stmt_admin->execute();
        $admin_acc = $stmt_admin->fetch(PDO::FETCH_ASSOC);
        if ($admin_acc && !empty($admin_acc['fb_app_id']) && !empty($admin_acc['fb_app_secret'])) {
            $app_id = $admin_acc['fb_app_id'];
            $app_secret = $admin_acc['fb_app_secret'];
        }
    }

    // 0.5 Check Account Expiry & Channel Limits
    $limit_stmt = $pdo->prepare("SELECT role, page_limit, max_fb_pages, expire_date FROM system_accounts WHERE id = ?");
    $limit_stmt->execute([$account_id]);
    $acc_info = $limit_stmt->fetch(PDO::FETCH_ASSOC);
    $page_limit   = (int)($acc_info['page_limit'] ?? 500);
    $max_fb_pages = (int)($acc_info['max_fb_pages'] ?? 450);
    $is_admin     = (($acc_info['role'] ?? '') === 'admin');

    if (!$is_admin && !empty($acc_info['expire_date']) && strtotime($acc_info['expire_date']) < time()) {
        header('Location: ../token_management.php?status=error&msg=' . urlencode('Tài khoản đã hết hạn. Vui lòng liên hệ Admin để gia hạn.'));
        exit;
    }

    $conflict_action = isset($_POST['conflict_action']) ? trim($_POST['conflict_action']) : (isset($_POST['overwrite_conflict']) && $_POST['overwrite_conflict'] == '1' ? 'overwrite' : 'skip');
    $confirmed = isset($_POST['confirmed']) && $_POST['confirmed'] == '1';

    // Single token conflict prompt mode (only when single token & not confirmed & overwrite_conflict checkbox not passed)
    if (count($tokens) === 1 && !$confirmed && !isset($_POST['overwrite_conflict'])) {
        $token = $tokens[0];

        if ($app_id && $app_secret) {
            $long_token = fb_exchange_token($token, $app_id, $app_secret);
            if ($long_token) $token = $long_token;
        }

        $profile_response = get_fb_user_profile($token);
        if ($profile_response['status_code'] !== 200 || !isset($profile_response['data']['id'])) {
            $error_msg = isset($profile_response['data']['error']['message']) ? $profile_response['data']['error']['message'] : 'Token không hợp lệ hoặc đã hết hạn.';
            header('Location: ../token_management.php?status=error&msg=' . urlencode($error_msg));
            exit;
        }

        $fb_user_id = $profile_response['data']['id'];
        $fb_user_name = $profile_response['data']['name'];

        $stmt = $pdo->prepare("SELECT id FROM users WHERE fb_id = ?");
        $stmt->execute([$fb_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $user_db_id = $user['id'];
            $update_stmt = $pdo->prepare("UPDATE users SET name = ?, access_token = ?, account_id = ? WHERE id = ?");
            $update_stmt->execute([$fb_user_name, encryptData($token), $account_id, $user_db_id]);
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO users (fb_id, name, access_token, account_id) VALUES (?, ?, ?, ?)");
            $insert_stmt->execute([$fb_user_id, $fb_user_name, encryptData($token), $account_id]);
            $user_db_id = $pdo->lastInsertId();
        }

        $all_fb_pages = [];
        $after_cursor = null;
        $has_next = true;

        while ($has_next) {
            $pages_response = get_fb_user_pages($token, $after_cursor);
            if ($pages_response['status_code'] === 200 && isset($pages_response['data']['data'])) {
                $pages = $pages_response['data']['data'];
                foreach ($pages as $p) {
                    if (!empty($fb_user_id) && (string)$p['id'] === (string)$fb_user_id) {
                        continue;
                    }
                    $all_fb_pages[] = $p;
                }
                if (isset($pages_response['data']['paging']['cursors']['after']) && count($pages) > 0) {
                    $after_cursor = $pages_response['data']['paging']['cursors']['after'];
                } else {
                    $has_next = false;
                }
            } else {
                $has_next = false;
            }
        }
        $pre_fetched_pages[$token] = $all_fb_pages;

        $conflict_pages = [];
        if (!empty($all_fb_pages)) {
            $fb_page_ids = array_map(function($p) { return $p['id']; }, $all_fb_pages);
            $chunks = array_chunk($fb_page_ids, 100);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt_conflict = $pdo->prepare("SELECT p.page_id, p.name as page_name, u.name as owner_name FROM pages p LEFT JOIN users u ON p.user_id = u.id WHERE p.page_id IN ($placeholders) AND p.user_id != ?");
                $params = array_merge($chunk, [$user_db_id]);
                $stmt_conflict->execute($params);
                $conflict_pages = array_merge($conflict_pages, $stmt_conflict->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        if (count($conflict_pages) > 0) {
            ?>
            <base href="../">
            <?php
            $current_page = 'token';
            require_once __DIR__ . '/../includes/header.php';
            ?>
            <div class="container-fluid" style="max-width: 800px; margin: 40px auto; padding: 0 15px;">
                <div class="card" style="border: 1px solid #f59e0b; background-color: #fffbeb; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border-radius: 12px; padding: 30px;">
                    <div style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 25px;">
                        <div style="font-size: 36px; line-height: 1; color: #d97706; margin-top: -3px;">⚠️</div>
                        <div>
                            <h3 style="margin: 0; color: #92400e; font-size: 20px; font-weight: 700;">Phát hiện trùng lặp Fanpage</h3>
                            <p style="margin: 6px 0 0; color: #b45309; font-size: 14px; line-height: 1.5;">
                                Có <strong><?php echo count($conflict_pages); ?></strong> Fanpage dưới đây đã được thêm vào hệ thống bởi người dùng khác.
                            </p>
                        </div>
                    </div>

                    <div style="max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 5px; margin-bottom: 25px; box-shadow: inset 0 2px 4px 0 rgba(0,0,0,0.06);">
                        <table style="width: 100%; margin: 0; font-size: 13px; border-collapse: collapse; border: none;">
                            <thead>
                                <tr style="border-bottom: 2px solid #f3f4f6; text-align: left; background: #fafafa;">
                                    <th style="padding: 10px; font-weight: 600; color: #374151;">Tên Fanpage</th>
                                    <th style="padding: 10px; font-weight: 600; color: #374151;">Page ID</th>
                                    <th style="padding: 10px; font-weight: 600; color: #374151;">Người sở hữu hiện tại</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($conflict_pages as $cp): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6; transition: background 0.15s;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background='transparent'">
                                    <td style="padding: 10px; font-weight: 500; color: #111827;"><?php echo htmlspecialchars($cp['page_name']); ?></td>
                                    <td style="padding: 10px; color: #6b7280; font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($cp['page_id']); ?></td>
                                    <td style="padding: 10px; color: #ef4444; font-weight: 600;">
                                        <span style="display: inline-flex; align-items: center; gap: 4px; background: #fef2f2; padding: 2px 8px; border-radius: 12px; font-size: 11px;">
                                            👤 <?php echo htmlspecialchars($cp['owner_name'] ?? 'Không rõ'); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="background: #ffffff; border: 1px solid #e5e7eb; padding: 20px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05);">
                        <p style="margin: 0 0 15px 0; font-weight: 600; color: #374151; font-size: 14px;">Bạn muốn xử lý các Fanpage trùng lặp này như thế nào?</p>
                        
                        <form id="conflictForm" action="actions/save_token.php" method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="access_token" value="<?php echo htmlspecialchars($token); ?>">
                            <input type="hidden" name="confirmed" value="1">
                            <input type="hidden" name="conflict_action" id="conflict_action" value="">
                            
                            <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
                                <label style="display: flex; gap: 12px; align-items: flex-start; padding: 15px; border: 2px solid #10b981; background: #ecfdf5; border-radius: 8px; cursor: pointer; transition: all 0.2s;" class="option-card" id="label-skip">
                                    <input type="radio" name="action_radio" value="skip" checked style="margin-top: 4px; accent-color: #10b981;">
                                    <div>
                                        <span style="font-weight: bold; color: #065f46; display: block; font-size: 15px;">Bỏ qua các trang đã có (Khuyên dùng)</span>
                                        <span style="font-size: 13px; color: #047857; display: block; margin-top: 4px; line-height: 1.4;">
                                            Giữ nguyên quyền sở hữu các Fanpage trùng lặp cho người dùng cũ. Hệ thống chỉ đồng bộ các Fanpage chưa có trên hệ thống vào tài khoản của bạn.
                                        </span>
                                    </div>
                                </label>

                                <label style="display: flex; gap: 12px; align-items: flex-start; padding: 15px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; cursor: pointer; transition: all 0.2s;" class="option-card" id="label-overwrite">
                                    <input type="radio" name="action_radio" value="overwrite" style="margin-top: 4px; accent-color: #ef4444;">
                                    <div>
                                        <span style="font-weight: bold; color: #1f2937; display: block; font-size: 15px;">Ghi đè quyền sở hữu</span>
                                        <span style="font-size: 13px; color: #4b5563; display: block; margin-top: 4px; line-height: 1.4;">
                                            Chuyển toàn bộ Fanpage trùng lặp sang tài khoản của bạn. Các Fanpage này ở tài khoản của người dùng cũ sẽ bị xóa (số lượng = 0).
                                        </span>
                                    </div>
                                </label>
                            </div>
                        </form>
                    </div>

                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <a href="token_management.php" class="btn btn-secondary" style="padding: 10px 20px; border-radius: 6px; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db; background: #fff; color: #374151;">Huỷ bỏ</a>
                        <button onclick="submitConflictForm()" class="btn btn-primary" style="padding: 10px 24px; border-radius: 6px; font-weight: bold; background: #0284c7; border: none; color: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.4);">Xác nhận tiếp tục</button>
                    </div>
                </div>
            </div>

            <script>
                document.querySelectorAll('input[name="action_radio"]').forEach(radio => {
                    radio.addEventListener('change', function() {
                        const skipCard = document.getElementById('label-skip');
                        const overwriteCard = document.getElementById('label-overwrite');
                        
                        skipCard.style.borderColor = '#e5e7eb';
                        skipCard.style.background = '#fff';
                        overwriteCard.style.borderColor = '#e5e7eb';
                        overwriteCard.style.background = '#fff';
                        
                        if (this.value === 'skip') {
                            skipCard.style.borderColor = '#10b981';
                            skipCard.style.background = '#ecfdf5';
                        } else {
                            overwriteCard.style.borderColor = '#ef4444';
                            overwriteCard.style.background = '#fef2f2';
                        }
                    });
                });

                function submitConflictForm() {
                    const selectedAction = document.querySelector('input[name="action_radio"]:checked').value;
                    document.getElementById('conflict_action').value = selectedAction;
                    document.getElementById('conflictForm').submit();
                }
            </script>
            <?php
            require_once __DIR__ . '/../includes/footer.php';
            exit;
        }
    }

    // Process Token(s) Batch/Direct Sync
    try {
        $col = $pdo->query("SHOW COLUMNS FROM pages LIKE 'avatar'");
        if ($col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE pages ADD COLUMN avatar TEXT DEFAULT NULL");
        }
    } catch (Exception $e) {}

    $p_check_stmt = $pdo->prepare("SELECT p.id, u.account_id FROM pages p LEFT JOIN users u ON p.user_id = u.id WHERE p.page_id = ?");
    $p_update_stmt = $pdo->prepare("UPDATE pages SET name = ?, access_token = ?, category = ?, followers_count = ?, avatar = ?, user_id = ? WHERE page_id = ?");
    $p_insert_stmt = $pdo->prepare("INSERT INTO pages (page_id, name, access_token, category, followers_count, avatar, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");

    $total_users_count = 0;
    $total_pages_count = 0;
    $error_messages = [];

    foreach ($tokens as $idx => $token) {
        if ($app_id && $app_secret) {
            $long_token = fb_exchange_token($token, $app_id, $app_secret);
            if ($long_token) $token = $long_token;
        }

        $profile_response = get_fb_user_profile($token);
        if ($profile_response['status_code'] !== 200 || !isset($profile_response['data']['id'])) {
            $err = isset($profile_response['data']['error']['message']) ? $profile_response['data']['error']['message'] : 'Token không hợp lệ hoặc đã hết hạn.';
            $error_messages[] = "Token #" . ($idx + 1) . ": " . $err;
            continue;
        }

        $fb_user_id = $profile_response['data']['id'];
        $fb_user_name = $profile_response['data']['name'];

        $stmt = $pdo->prepare("SELECT id FROM users WHERE fb_id = ?");
        $stmt->execute([$fb_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $user_db_id = $user['id'];
            $update_stmt = $pdo->prepare("UPDATE users SET name = ?, access_token = ?, account_id = ? WHERE id = ?");
            $update_stmt->execute([$fb_user_name, encryptData($token), $account_id, $user_db_id]);
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO users (fb_id, name, access_token, account_id) VALUES (?, ?, ?, ?)");
            $insert_stmt->execute([$fb_user_id, $fb_user_name, encryptData($token), $account_id]);
            $user_db_id = $pdo->lastInsertId();
        }
        $total_users_count++;

        if (isset($pre_fetched_pages[$token])) {
            $all_fb_pages = $pre_fetched_pages[$token];
        } else {
            $all_fb_pages = [];
            $after_cursor = null;
            $has_next = true;

            while ($has_next) {
                $pages_response = get_fb_user_pages($token, $after_cursor);
                if ($pages_response['status_code'] === 200 && isset($pages_response['data']['data'])) {
                    $pages = $pages_response['data']['data'];
                    foreach ($pages as $p) {
                        if (!empty($fb_user_id) && (string)$p['id'] === (string)$fb_user_id) {
                            continue;
                        }
                        $all_fb_pages[] = $p;
                    }
                    if (isset($pages_response['data']['paging']['cursors']['after']) && count($pages) > 0) {
                        $after_cursor = $pages_response['data']['paging']['cursors']['after'];
                    } else {
                        $has_next = false;
                    }
                } else {
                    $has_next = false;
                }
            }
        }

        foreach ($all_fb_pages as $page) {
            $page_id = $page['id'];
            $page_name = $page['name'];
            $page_token = isset($page['access_token']) ? $page['access_token'] : '';
            $category = isset($page['category']) ? $page['category'] : '';
            $followers = isset($page['followers_count']) ? $page['followers_count'] : 0;
            $avatar = "avatar.php?id=" . $page_id;

            // Bỏ qua nếu page_id trùng với fb_user_id (Nick cá nhân của người dùng)
            if (!empty($fb_user_id) && (string)$page_id === (string)$fb_user_id) {
                continue;
            }

            $p_check_stmt->execute([$page_id]);
            $existing_page = $p_check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_page) {
                if ($conflict_action === 'skip' && $existing_page['account_id'] != $account_id) {
                    continue;
                }
                $p_update_stmt->execute([$page_name, encryptData($page_token), $category, $followers, $avatar, $user_db_id, $page_id]);
                $total_pages_count++;
            } else {
                // Kiểm tra hạn ngạch Fanpage FB được phép chèn mới
                if (!$is_admin && $max_fb_pages > 0) {
                    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = ?");
                    $cnt_stmt->execute([$account_id]);
                    $curr_count = (int)$cnt_stmt->fetchColumn();
                    if ($curr_count >= $max_fb_pages) {
                        $limit_reached_flag = true;
                        continue; // Dừng không lưu thêm Fanpage vượt hạn ngạch vào CSDL
                    }
                }
                $p_insert_stmt->execute([$page_id, $page_name, encryptData($page_token), $category, $followers, $avatar, $user_db_id]);
                $total_pages_count++;
            }
        }

        // Tự động dọn dẹp bất kỳ nick cá nhân nào đã bị lưu nhầm vào bảng pages trước đây
        try {
            $pdo->exec("DELETE p FROM pages p JOIN users u ON p.page_id = u.fb_id");
        } catch (Exception $e) {}

        // Trigger conversation sync in parallel multi-curl (non-blocking)
        try {
            if (!empty($all_fb_pages)) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $base_url_path = $protocol . $host . dirname($_SERVER['REQUEST_URI']);

                $mh = curl_multi_init();
                $handles = [];
                // Limit to max 20 pages per batch to prevent server overload
                $page_chunks = array_chunk($all_fb_pages, 20);
                foreach ($page_chunks[0] as $page) {
                    $page_id = $page['id'];
                    $sync_url = $base_url_path . "/sync_fb_conversations.php?page_id=" . urlencode($page_id) . "&user_id=" . intval($user_db_id);
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $sync_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
                    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_multi_add_handle($mh, $ch);
                    $handles[] = $ch;
                }

                $running = null;
                do {
                    curl_multi_exec($mh, $running);
                    usleep(5000);
                } while ($running > 0);

                foreach ($handles as $ch) {
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                }
                curl_multi_close($mh);
            }
        } catch (Exception $e) {}
    }

    // Clear Dashboard Cache
    $cache_dir = __DIR__ . '/../uploads/cache';
    foreach (glob($cache_dir . "/dashboard_user_{$account_id}_*.json") as $cf) { @unlink($cf); }
    foreach (glob($cache_dir . "/dashboard_admin_*.json") as $cf) { @unlink($cf); }

    if ($total_users_count > 0) {
        $msg = "Thêm thành công $total_users_count tài khoản Facebook ($total_pages_count Fanpage)!";
        if (!empty($limit_reached_flag)) {
            $msg .= " ⚠️ Bạn đã đạt giới hạn tối đa $max_fb_pages Fanpage FB. Các Fanpage vượt quá hạn ngạch đã tự động bị chặn không lưu vào CSDL.";
        }
        if (!empty($error_messages)) {
            $msg .= " (Lỗi " . count($error_messages) . " token: " . implode("; ", $error_messages) . ")";
        }
        header('Location: ../token_management.php?status=success&pages=' . $total_pages_count . '&msg=' . urlencode($msg));
    } else {
        $msg = !empty($error_messages) ? implode("; ", $error_messages) : "Không có Token nào hợp lệ.";
        if (!empty($limit_reached_flag)) {
            $msg = "⚠️ Tài khoản của bạn đã đạt/vượt giới hạn tối đa $max_fb_pages Fanpage FB. Vui lòng liên hệ Admin để nâng cấp gói cước!";
        }
        header('Location: ../token_management.php?status=error&msg=' . urlencode($msg));
    }
    exit;
} else {
    header('Location: ../token_management.php');
    exit;
}
?>
