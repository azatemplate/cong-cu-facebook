<?php
// actions/proxy_actions.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['account_id'])) {
    header("Location: ../login.php");
    exit;
}

$account_id = $_SESSION['account_id'];



function parse_proxy_line($line) {
    $line = trim($line);
    if (empty($line)) return null;

    $protocol = 'http';
    if (preg_match('#^(https?|socks4|socks5)://#i', $line, $m)) {
        $protocol = strtolower($m[1]);
        $line = preg_replace('#^(https?|socks4|socks5)://#i', '', $line);
    }

    $ip = '';
    $port = '';
    $user = '';
    $pass = '';

    if (strpos($line, '@') !== false) {
        $parts = explode('@', $line, 2);
        $auth_parts = explode(':', $parts[0], 2);
        $user = $auth_parts[0] ?? '';
        $pass = $auth_parts[1] ?? '';

        $server_part = $parts[1];
        if (preg_match('/^\[([a-fA-F0-9:]+)\]:(\d+)$/', $server_part, $matches)) {
            $ip = $matches[1];
            $port = $matches[2];
        } else {
            $last_colon = strrpos($server_part, ':');
            if ($last_colon !== false) {
                $ip = substr($server_part, 0, $last_colon);
                $port = substr($server_part, $last_colon + 1);
            } else {
                $ip = $server_part;
            }
        }
    } else {
        if (preg_match('/^\[([a-fA-F0-9:]+)\]:(\d+)(?::([^:]+):([^:]+))?$/', $line, $matches)) {
            $ip = $matches[1];
            $port = $matches[2];
            $user = $matches[3] ?? '';
            $pass = $matches[4] ?? '';
        } else {
            $parts = explode(':', $line);
            $cnt = count($parts);
            if ($cnt === 2) {
                $ip = $parts[0];
                $port = $parts[1];
            } else if ($cnt === 4) {
                $ip = $parts[0];
                $port = $parts[1];
                $user = $parts[2];
                $pass = $parts[3];
            } else if ($cnt > 4) {
                $port = array_pop($parts);
                if (!is_numeric($port) && count($parts) >= 2) {
                    $pass = $port;
                    $user = array_pop($parts);
                    $port = array_pop($parts);
                }
                $ip = implode(':', $parts);
            }
        }
    }

    $ip = trim($ip, '[]');
    $port = trim($port);
    $user = trim($user);
    $pass = trim($pass);

    if (empty($ip) || empty($port)) return null;

    $ip_type = (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) ? 'IPv6' : 'IPv4';
    $auth_str = (!empty($user) && !empty($pass)) ? "$user:$pass@" : "";
    $clean_ip_str = ($ip_type === 'IPv6' && strpos($ip, ':') !== false && strpos($ip, '[') === false) ? "[$ip]" : $ip;
    $proxy_string = "$protocol://$auth_str$clean_ip_str:$port";

    return [
        'proxy_string' => $proxy_string,
        'ip'           => $ip,
        'port'         => $port,
        'username'     => $user,
        'password'     => $pass,
        'protocol'     => $protocol,
        'ip_type'      => $ip_type
    ];
}

function check_single_proxy($proxy_row) {
    $ch = curl_init('https://graph.facebook.com');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $ip_str = ($proxy_row['ip_type'] === 'IPv6' && strpos($proxy_row['ip'], ':') !== false) ? '[' . $proxy_row['ip'] . ']' : $proxy_row['ip'];
    curl_setopt($ch, CURLOPT_PROXY, $ip_str . ':' . $proxy_row['port']);
    if (!empty($proxy_row['username']) && !empty($proxy_row['password'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_row['username'] . ':' . $proxy_row['password']);
    }
    if (strtolower($proxy_row['protocol']) === 'socks5') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $start = microtime(true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    $duration = round((microtime(true) - $start) * 1000);

    if ($response !== false && $http_code > 0 && $http_code < 500) {
        return ['status' => 'live', 'latency' => $duration];
    } else {
        return ['status' => 'dead', 'latency' => 0, 'error' => $curl_err];
    }
}

$action = $_REQUEST['action'] ?? '';

if ($action === 'add_proxies') {
    $raw_input = $_POST['proxy_list'] ?? '';
    $lines = explode("\n", $raw_input);
    
    $added = 0;
    $duplicates = 0;
    $invalid = 0;

    $stmt_chk = $pdo->prepare("
        SELECT id FROM proxies 
        WHERE account_id = ? AND (proxy_string = ? OR (ip = ? AND port = ?))
    ");

    $stmt_ins = $pdo->prepare("
        INSERT INTO proxies (account_id, proxy_string, ip, port, username, password, protocol, ip_type, status, latency, country)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'untested', 0, 'VN')
    ");

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $parsed = parse_proxy_line($line);
        if (!$parsed) {
            $invalid++;
            continue;
        }

        $stmt_chk->execute([
            $account_id,
            $parsed['proxy_string'],
            $parsed['ip'],
            $parsed['port']
        ]);

        if ($stmt_chk->fetch()) {
            $duplicates++;
            continue;
        }

        $stmt_ins->execute([
            $account_id,
            $parsed['proxy_string'],
            $parsed['ip'],
            $parsed['port'],
            $parsed['username'] ?: null,
            $parsed['password'] ?: null,
            $parsed['protocol'],
            $parsed['ip_type']
        ]);
        $added++;
    }

    if ($added > 0) {
        $msg = "Đã thêm thành công $added Proxy mới vào hệ thống.";
        if ($duplicates > 0) {
            $msg .= " ⚠️ Có $duplicates Proxy đã tồn tại từ trước (hệ thống không thêm trùng).";
        }
        if ($invalid > 0) {
            $msg .= " ($invalid dòng sai cú pháp bị bỏ qua).";
        }
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = "success";
    } else if ($duplicates > 0) {
        $_SESSION['flash_msg'] = "⚠️ Tất cả $duplicates Proxy bạn nhập đều đã có trong hệ thống trước đó (không thêm trùng).";
        $_SESSION['flash_type'] = "warning";
    } else {
        $_SESSION['flash_msg'] = "Không có Proxy hợp lệ nào được thêm (dữ liệu rỗng hoặc sai cú pháp).";
        $_SESSION['flash_type'] = "danger";
    }

    header("Location: ../proxy.php");
    exit;
}

if ($action === 'check_proxy') {
    $proxy_id = (int)($_REQUEST['proxy_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM proxies WHERE id = ? AND account_id = ?");
    $stmt->execute([$proxy_id, $account_id]);
    $proxy = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($proxy) {
        $res = check_single_proxy($proxy);
        $upd = $pdo->prepare("UPDATE proxies SET status = ?, latency = ? WHERE id = ?");
        $upd->execute([$res['status'], $res['latency'], $proxy_id]);

        $st_str = ($res['status'] === 'live') ? "Sống ✓ ({$res['latency']}ms)" : "Chết ✗";
        $_SESSION['flash_msg'] = "Đã kiểm tra Proxy {$proxy['ip']}:{$proxy['port']} -> Kết quả: $st_str";
        $_SESSION['flash_type'] = ($res['status'] === 'live') ? "success" : "danger";
    }

    header("Location: ../proxy.php");
    exit;
}

if ($action === 'check_all_proxies') {
    $stmt = $pdo->prepare("SELECT * FROM proxies WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $proxies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("UPDATE proxies SET status = ?, latency = ? WHERE id = ?");
    $live = 0;
    $dead = 0;

    foreach ($proxies as $px) {
        $res = check_single_proxy($px);
        $upd->execute([$res['status'], $res['latency'], $px['id']]);
        if ($res['status'] === 'live') $live++;
        else $dead++;
    }

    $_SESSION['flash_msg'] = "Đã kiểm tra hoàn tất " . count($proxies) . " Proxy ($live Sống ✓, $dead Chết ✗).";
    $_SESSION['flash_type'] = "success";
    header("Location: ../proxy.php");
    exit;
}

if ($action === 'assign_proxy') {
    $proxy_id = (int)($_POST['proxy_id'] ?? 0);
    $user_id  = (int)($_POST['user_id'] ?? 0);

    if ($proxy_id <= 0 || $user_id <= 0) {
        $_SESSION['flash_msg'] = "Vui lòng chọn đầy đủ thông tin Proxy và Người dùng Token.";
        $_SESSION['flash_type'] = "danger";
        header("Location: ../proxy.php");
        exit;
    }

    $stmt_px = $pdo->prepare("SELECT id FROM proxies WHERE id = ? AND account_id = ?");
    $stmt_px->execute([$proxy_id, $account_id]);
    if (!$stmt_px->fetch()) {
        $_SESSION['flash_msg'] = "Proxy không tồn tại hoặc không thuộc tài khoản của bạn.";
        $_SESSION['flash_type'] = "danger";
        header("Location: ../proxy.php");
        exit;
    }

    $pdo->prepare("UPDATE users SET proxy_id = NULL WHERE proxy_id = ?")->execute([$proxy_id]);
    $pdo->prepare("UPDATE proxies SET assigned_user_id = NULL WHERE assigned_user_id = ?")->execute([$user_id]);

    $pdo->prepare("UPDATE proxies SET assigned_user_id = ? WHERE id = ?")->execute([$user_id, $proxy_id]);
    $pdo->prepare("UPDATE users SET proxy_id = ? WHERE id = ?")->execute([$proxy_id, $user_id]);

    $_SESSION['flash_msg'] = "Đã gán Proxy cho Người dùng Token thành công!";
    $_SESSION['flash_type'] = "success";
    header("Location: ../proxy.php");
    exit;
}

if ($action === 'unassign_proxy') {
    $proxy_id = (int)($_REQUEST['proxy_id'] ?? 0);

    $stmt_px = $pdo->prepare("SELECT assigned_user_id FROM proxies WHERE id = ? AND account_id = ?");
    $stmt_px->execute([$proxy_id, $account_id]);
    $px = $stmt_px->fetch(PDO::FETCH_ASSOC);

    if ($px) {
        if (!empty($px['assigned_user_id'])) {
            $pdo->prepare("UPDATE users SET proxy_id = NULL WHERE id = ?")->execute([$px['assigned_user_id']]);
        }
        $pdo->prepare("UPDATE proxies SET assigned_user_id = NULL WHERE id = ?")->execute([$proxy_id]);
    }

    $_SESSION['flash_msg'] = "Đã hủy gán Proxy khỏi Người dùng.";
    $_SESSION['flash_type'] = "success";
    header("Location: ../proxy.php");
    exit;
}

if ($action === 'delete_proxy') {
    $proxy_id = (int)($_REQUEST['proxy_id'] ?? 0);

    $stmt_px = $pdo->prepare("SELECT assigned_user_id FROM proxies WHERE id = ? AND account_id = ?");
    $stmt_px->execute([$proxy_id, $account_id]);
    $px = $stmt_px->fetch(PDO::FETCH_ASSOC);

    if ($px) {
        if (!empty($px['assigned_user_id'])) {
            $pdo->prepare("UPDATE users SET proxy_id = NULL WHERE id = ?")->execute([$px['assigned_user_id']]);
        }
        $pdo->prepare("DELETE FROM proxies WHERE id = ?")->execute([$proxy_id]);
    }

    $_SESSION['flash_msg'] = "Đã xóa Proxy khỏi hệ thống.";
    $_SESSION['flash_type'] = "success";
    header("Location: ../proxy.php");
    exit;
}

header("Location: ../proxy.php");
exit;
