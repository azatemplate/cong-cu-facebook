<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $merge_all = isset($_GET['merge_all']) ? intval($_GET['merge_all']) : 0;
    if ($merge_all === 1) {
        if (!isset($_SESSION['account_id'])) {
            echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
            exit;
        }
        $account_id = $_SESSION['account_id'];
        $append = isset($_GET['append']) ? intval($_GET['append']) : 0;
        
        $stmt_pages = $pdo->prepare("
            (SELECT p.page_id, p.name, p.user_id, p.access_token
             FROM pages p JOIN users u ON p.user_id = u.id
             WHERE u.account_id = :aid)
            UNION
            (SELECT p.page_id, p.name, p.user_id, p.access_token
             FROM pages p
             JOIN page_shares ps ON p.page_id = ps.page_id
             JOIN users u ON p.user_id = u.id
             WHERE ps.shared_with_account_id = :aid2)
        ");
        $stmt_pages->bindValue(':aid', $account_id, PDO::PARAM_INT);
        $stmt_pages->bindValue(':aid2', $account_id, PDO::PARAM_INT);
        $stmt_pages->execute();
        $all_pages_raw = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);
        
        $pages = [];
        $cursors = [];
        
        if ($append === 1 && isset($_SESSION['merge_cursors'])) {
            $cursors = $_SESSION['merge_cursors'];
        } elseif ($append === 0) {
            $_SESSION['merge_cursors'] = [];
        }

        foreach ($all_pages_raw as $p) {
            if (!empty($p['access_token'])) {
                if ($append === 1 && empty($cursors[$p['page_id']])) {
                    continue; 
                }
                $p['access_token'] = decryptData($p['access_token']);
                $pages[] = $p;
            }
        }
        
        if (empty($pages)) {
            echo json_encode(['status' => 'success', 'data' => [], 'next_cursor' => '', 'merged' => true]);
            exit;
        }
        
        $multi_result = get_fb_conversations_multi($pages, 8, $cursors);
        $merged_conversations = $multi_result['data'];
        $returned_cursors = $multi_result['cursors'];
        
        if ($append === 0) {
            $_SESSION['merge_cursors'] = $returned_cursors;
        } else {
            foreach ($returned_cursors as $pid => $cur) {
                $_SESSION['merge_cursors'][$pid] = $cur;
            }
            foreach ($pages as $p) {
                if (empty($returned_cursors[$p['page_id']])) {
                    unset($_SESSION['merge_cursors'][$p['page_id']]);
                }
            }
        }
        
        $next_cursor = !empty($_SESSION['merge_cursors']) ? 'merging' : '';

        echo json_encode(['status' => 'success', 'data' => $merged_conversations, 'next_cursor' => $next_cursor, 'merged' => true]);
        exit;
    }

    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $page_id = isset($_GET['page_id']) ? $_GET['page_id'] : '';
    
    if (!$user_id || empty($page_id)) {
        echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn đầy đủ User và Fanpage.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT access_token FROM pages WHERE page_id = ? AND user_id = ?");
    $stmt->execute([$page_id, $user_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page || empty($page['access_token'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Token của Fanpage này.']);
        exit;
    }

    $page_access_token = decryptData($page['access_token']);

    $after = isset($_GET['after']) ? $_GET['after'] : '';

    // Lấy danh sách conversations
    $endpoint = $page_id . '/conversations';
    $params = [
        'fields' => 'id,updated_time,unread_count,tags{name},participants{id,name,email,custom_labels},messages.limit(5){message,from}',
        'access_token' => $page_access_token,
        'limit' => 20
    ];

    if ($after) {
        $params['after'] = $after;
    }

    $response = fb_api_request($endpoint, $params, 'GET');

    $target_conv_id = $_GET['target_conv_id'] ?? '';
    $target_sender_id = $_GET['target_sender_id'] ?? '';

    if (($target_conv_id || $target_sender_id) && !$after && $response['status_code'] === 200) {
        $single_data = null;
        if ($target_conv_id) {
            $single_res = fb_api_request($target_conv_id, [
                'fields' => 'id,updated_time,unread_count,tags{name},participants{id,name,email,custom_labels},messages.limit(5){message,from}',
                'access_token' => $page_access_token
            ], 'GET');
            if (isset($single_res['data']['id'])) {
                $single_data = $single_res['data'];
            }
        } elseif ($target_sender_id) {
            $single_res = fb_api_request($page_id . '/conversations', [
                'user_id' => $target_sender_id,
                'fields' => 'id,updated_time,unread_count,tags{name},participants{id,name,email,custom_labels},messages.limit(5){message,from}',
                'access_token' => $page_access_token
            ], 'GET');
            if (!empty($single_res['data']['data'][0])) {
                $single_data = $single_res['data']['data'][0];
            }
        }

        if ($single_data) {
            $data_arr = $response['data']['data'] ?? [];
            $found = false;
            foreach ($data_arr as $c) {
                if (isset($c['id']) && $c['id'] === $single_data['id']) { $found = true; break; }
            }
            if (!$found) {
                array_unshift($data_arr, $single_data);
                $response['data']['data'] = $data_arr;
            }
        }
    }

    if ($response['status_code'] === 200) {
        $next_cursor = '';
        if (isset($response['data']['paging']['cursors']['after'])) {
            $next_cursor = $response['data']['paging']['cursors']['after'];
        }
        echo json_encode(['status' => 'success', 'data' => $response['data']['data'], 'next_cursor' => $next_cursor]);
    } else {
        $error_msg = isset($response['data']['error']['message']) ? $response['data']['error']['message'] : 'Lỗi không xác định';
        echo json_encode(['status' => 'error', 'msg' => $error_msg]);
    }
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
}
?>
