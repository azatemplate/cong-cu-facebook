<?php
// actions/buffer_save_account.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['account_id'])) {
    if (isset($_POST['redirect']) || !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $_SESSION['flash_error'] = "Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.";
        header("Location: ../login.php");
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

$account_id = $_SESSION['account_id'];
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$is_redirect = isset($_POST['redirect']) || !isset($_SERVER['HTTP_X_REQUESTED_WITH']);

// Helper gọi Buffer GraphQL API
function callBufferGraphQL($token, $query, $variables = []) {
    $payload = ['query' => $query];
    if (!empty($variables)) {
        $payload['variables'] = $variables;
    }

    $ch = curl_init('https://api.buffer.com/graphql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($token),
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    ]);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    if ($res) {
        $json = json_decode($res, true);
        if (is_array($json)) {
            return ['code' => $httpCode, 'data' => $json, 'curl_err' => $curlErr];
        }
    }
    return ['code' => $httpCode, 'data' => null, 'curl_err' => $curlErr ?: 'HTTP Code ' . $httpCode];
}

// ── 1. THÊM HOẶC CẬP NHẬT KẾT NỐI BUFFER API KEY ────────────────────────────
if ($action === 'add_account' || $action === 'edit_account') {
    $email_input = trim($_POST['email'] ?? '');
    $access_token = trim($_POST['access_token'] ?? '');
    $db_id = intval($_POST['db_id'] ?? 0);

    if (empty($access_token)) {
        $_SESSION['flash_error'] = "Vui lòng nhập Access Token / API Key của Buffer.";
        if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
        echo json_encode(['status' => 'error', 'msg' => $_SESSION['flash_error']]);
        exit;
    }

    // Gọi GraphQL lấy thông tin tài khoản & danh sách Organization
    $account_query = 'query GetAccount { account { id email organizations { id name } } }';
    $gql_res = callBufferGraphQL($access_token, $account_query);

    if (!$gql_res || empty($gql_res['data']['data']['account'])) {
        $err_msg = 'Token không hợp lệ hoặc máy chủ không thể kết nối Buffer API.';
        if (!empty($gql_res['data']['errors'][0]['message'])) {
            $err_msg = $gql_res['data']['errors'][0]['message'];
        } elseif (!empty($gql_res['curl_err'])) {
            $err_msg = $gql_res['curl_err'];
        }

        $_SESSION['flash_error'] = "Lỗi kết nối Buffer API: " . $err_msg;
        if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
        echo json_encode(['status' => 'error', 'msg' => $_SESSION['flash_error']]);
        exit;
    }

    $buf_user = $gql_res['data']['data']['account'];
    $email = !empty($email_input) ? $email_input : ($buf_user['email'] ?? '');
    if (empty($email)) {
        $email = 'buffer_user_' . substr(md5($access_token), 0, 8) . '@buffer.com';
    }

    $orgs = $buf_user['organizations'] ?? [];
    $first_org_name = !empty($orgs[0]['name']) ? $orgs[0]['name'] : 'My Organization';

    try {
        if ($action === 'edit_account' && $db_id > 0) {
            $stmt = $pdo->prepare("UPDATE buffer_accounts SET email = ?, access_token = ?, organization = ?, synced_at = NOW() WHERE id = ? AND account_id = ?");
            $stmt->execute([$email, $access_token, $first_org_name, $db_id, $account_id]);
            $buf_acc_id = $db_id;
        } else {
            $stmt = $pdo->prepare("INSERT INTO buffer_accounts (account_id, email, access_token, organization, synced_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$account_id, $email, $access_token, $first_org_name]);
            $buf_acc_id = $pdo->lastInsertId();
        }

        // Read max_buffer_channels limit for user
        $acc_stmt = $pdo->prepare("SELECT role, max_buffer_channels FROM system_accounts WHERE id = ?");
        $acc_stmt->execute([$account_id]);
        $acc_info = $acc_stmt->fetch(PDO::FETCH_ASSOC);
        $max_buffer_channels = (int)($acc_info['max_buffer_channels'] ?? 10);
        $is_admin = (($acc_info['role'] ?? '') === 'admin');
        $buf_limit_reached = false;

        foreach ($orgs as $org) {
            $org_id = $org['id'];
            $org_name = $org['name'] ?? $first_org_name;

            $chan_res = callBufferGraphQL($access_token, $chan_query, ['orgId' => $org_id]);
            if (isset($chan_res['data']['data']['channels']) && is_array($chan_res['data']['data']['channels'])) {
                foreach ($chan_res['data']['data']['channels'] as $c) {
                    $channel_id = $c['id'] ?? '';
                    if (empty($channel_id)) continue;

                    $channel_name = $c['displayName'] ?? $c['name'] ?? 'Channel';
                    $service = strtolower($c['service'] ?? 'social');
                    $avatar = $c['avatar'] ?? '';

                    $chk_buf = $pdo->prepare("SELECT id FROM buffer_channels WHERE account_id = ? AND channel_id = ?");
                    $chk_buf->execute([$account_id, $channel_id]);
                    $existing_buf = $chk_buf->fetch();

                    if (!$existing_buf && !$is_admin && $max_buffer_channels > 0) {
                        $cnt_buf_stmt = $pdo->prepare("SELECT COUNT(*) FROM buffer_channels WHERE account_id = ?");
                        $cnt_buf_stmt->execute([$account_id]);
                        $curr_buf_count = (int)$cnt_buf_stmt->fetchColumn();

                        if ($curr_buf_count >= $max_buffer_channels) {
                            $buf_limit_reached = true;
                            continue; // Chặn không lưu thêm kênh Buffer vượt hạn ngạch vào CSDL
                        }
                    }

                    $stmt_chan = $pdo->prepare("
                        INSERT INTO buffer_channels (account_id, buffer_account_id, channel_id, channel_name, service, service_type, avatar, organization)
                        VALUES (?, ?, ?, ?, ?, 'profile', ?, ?)
                        ON DUPLICATE KEY UPDATE
                            buffer_account_id = VALUES(buffer_account_id),
                            channel_name = VALUES(channel_name),
                            service = VALUES(service),
                            avatar = VALUES(avatar),
                            organization = VALUES(organization)
                    ");
                    $stmt_chan->execute([$account_id, $buf_acc_id, $channel_id, $channel_name, $service, $avatar, $org_name]);
                    $channel_count++;
                }
            }
        }

        $msg = "Kết nối & Đồng bộ thành công $channel_count kênh từ tài khoản Buffer $email!";
        if (!empty($buf_limit_reached)) {
            $msg .= " ⚠️ Đã đạt giới hạn tối đa $max_buffer_channels Kênh Buffer. Các kênh vượt quá đã bị ngắt không lưu CSDL.";
        }
        $_SESSION['flash_msg'] = $msg;
        if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
        echo json_encode(['status' => 'success', 'msg' => $_SESSION['flash_msg'], 'channel_count' => $channel_count]);
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Lỗi CSDL: " . $e->getMessage();
        if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
        echo json_encode(['status' => 'error', 'msg' => $_SESSION['flash_error']]);
    }
    exit;
}

// ── 2. ĐỒNG BỘ LẠI (SYNC SINGLE OR ALL ACCOUNTS) ────────────────────────────
if ($action === 'sync_account' || $action === 'sync_all') {
    $db_id = intval($_POST['db_id'] ?? $_GET['db_id'] ?? 0);
    
    if ($action === 'sync_account' && $db_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM buffer_accounts WHERE id = ? AND account_id = ?");
        $stmt->execute([$db_id, $account_id]);
        $accs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM buffer_accounts WHERE account_id = ?");
        $stmt->execute([$account_id]);
        $accs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($accs)) {
        $_SESSION['flash_error'] = "Không tìm thấy tài khoản Buffer nào để đồng bộ.";
        if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
        echo json_encode(['status' => 'error', 'msg' => $_SESSION['flash_error']]);
        exit;
    }

    $total_channels_synced = 0;
    $account_query = 'query GetAccount { account { id email organizations { id name } } }';
    $chan_query = 'query GetChannels($orgId: OrganizationId!) { channels(input: { organizationId: $orgId }) { id name displayName service avatar } }';

    foreach ($accs as $acc) {
        $access_token = $acc['access_token'];
        $buf_acc_id = $acc['id'];

        $gql_res = callBufferGraphQL($access_token, $account_query);
        if ($gql_res && isset($gql_res['data']['data']['account']['organizations'])) {
            $orgs = $gql_res['data']['data']['account']['organizations'];
            $first_org_name = !empty($orgs[0]['name']) ? $orgs[0]['name'] : $acc['organization'];

            foreach ($orgs as $org) {
                $org_id = $org['id'];
                $org_name = $org['name'] ?? $first_org_name;

                $chan_res = callBufferGraphQL($access_token, $chan_query, ['orgId' => $org_id]);
                if (isset($chan_res['data']['data']['channels']) && is_array($chan_res['data']['data']['channels'])) {
                    foreach ($chan_res['data']['data']['channels'] as $c) {
                        $channel_id = $c['id'] ?? '';
                        if (empty($channel_id)) continue;

                        $channel_name = $c['displayName'] ?? $c['name'] ?? 'Channel';
                        $service = strtolower($c['service'] ?? 'social');
                        $avatar = $c['avatar'] ?? '';

                        $stmt_chan = $pdo->prepare("
                            INSERT INTO buffer_channels (account_id, buffer_account_id, channel_id, channel_name, service, service_type, avatar, organization)
                            VALUES (?, ?, ?, ?, ?, 'profile', ?, ?)
                            ON DUPLICATE KEY UPDATE
                                buffer_account_id = VALUES(buffer_account_id),
                                channel_name = VALUES(channel_name),
                                service = VALUES(service),
                                avatar = VALUES(avatar),
                                organization = VALUES(organization)
                        ");
                        $stmt_chan->execute([$account_id, $buf_acc_id, $channel_id, $channel_name, $service, $avatar, $org_name]);
                        $total_channels_synced++;
                    }
                }
            }

            $pdo->prepare("UPDATE buffer_accounts SET synced_at = NOW(), organization = ? WHERE id = ?")->execute([$first_org_name, $buf_acc_id]);
        }
    }

    $_SESSION['flash_msg'] = "Đã quét và đồng bộ thành công tổng cộng $total_channels_synced kênh từ tất cả các API Key!";
    if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
    echo json_encode(['status' => 'success', 'msg' => $_SESSION['flash_msg']]);
    exit;
}

// ── 3. XÓA TÀI KHOẢN BUFFER API KEY ─────────────────────────────────────────
if ($action === 'delete_account') {
    $db_id = intval($_POST['db_id'] ?? $_GET['db_id'] ?? 0);
    if ($db_id <= 0) {
        $_SESSION['flash_error'] = "ID tài khoản không hợp lệ.";
        if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
        echo json_encode(['status' => 'error', 'msg' => $_SESSION['flash_error']]);
        exit;
    }

    try {
        $pdo->prepare("DELETE FROM buffer_channels WHERE buffer_account_id = ? AND account_id = ?")->execute([$db_id, $account_id]);
        $pdo->prepare("DELETE FROM buffer_accounts WHERE id = ? AND account_id = ?")->execute([$db_id, $account_id]);
        $_SESSION['flash_msg'] = "Đã xóa kết nối tài khoản Buffer thành công.";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Lỗi DB: " . $e->getMessage();
    }
    
    if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
    echo json_encode(['status' => 'success', 'msg' => $_SESSION['flash_msg']]);
    exit;
}

$_SESSION['flash_error'] = "Hành động không hợp lệ.";
if ($is_redirect) { header("Location: ../buffer.php?tab=channels"); exit; }
echo json_encode(['status' => 'error', 'msg' => $_SESSION['flash_error']]);
