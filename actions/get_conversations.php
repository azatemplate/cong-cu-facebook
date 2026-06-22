<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $merge_all = isset($_GET['merge_all']) ? intval($_GET['merge_all']) : 0;
    $nocache = isset($_GET['nocache']) ? intval($_GET['nocache']) : 0;
    
    // Determine the cache key (only cache page 1, i.e. append=0 or after is empty)
    $cache_key = '';
    if ($merge_all === 1) {
        if (isset($_SESSION['account_id'])) {
            $append = isset($_GET['append']) ? intval($_GET['append']) : 0;
            if ($append === 0) {
                $cache_key = 'fb_convs_merge_' . $_SESSION['account_id'];
            }
        }
    } else {
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $page_id = isset($_GET['page_id']) ? $_GET['page_id'] : '';
        $after = isset($_GET['after']) ? $_GET['after'] : '';
        if ($page_id && $user_id && empty($after)) {
            $cache_key = 'fb_convs_' . $page_id . '_' . $user_id;
        }
    }
    
    // Check if cache exists and is not expired
    if (!$nocache && !empty($cache_key) && isset($_SESSION[$cache_key]) && $_SESSION[$cache_key]['expires'] > time()) {
        echo json_encode($_SESSION[$cache_key]['data']);
        exit;
    }

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

        session_write_close();

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
            $output_array = ['status' => 'success', 'data' => [], 'next_cursor' => '', 'merged' => true];
            if (!empty($cache_key)) {
                session_start();
                $_SESSION[$cache_key] = [
                    'data' => $output_array,
                    'expires' => time() + 15
                ];
                session_write_close();
            }
            echo json_encode($output_array);
            exit;
        }
        
        $multi_result = get_fb_conversations_multi($pages, 8, $cursors);
        $merged_conversations = $multi_result['data'];
        $returned_cursors = $multi_result['cursors'];
        
        session_start();
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

        // Đính kèm trạng thái SĐT từ DB
        attach_phone_status_to_conversations($merged_conversations, $pdo);

        $output_array = ['status' => 'success', 'data' => $merged_conversations, 'next_cursor' => $next_cursor, 'merged' => true];
        
        if (!empty($cache_key)) {
            $_SESSION[$cache_key] = [
                'data' => $output_array,
                'expires' => time() + 15
            ];
        }
        session_write_close();

        echo json_encode($output_array);
        exit;
    }

    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $page_id = isset($_GET['page_id']) ? $_GET['page_id'] : '';
    
    if (!$user_id || empty($page_id)) {
        echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn đầy đủ User và Fanpage.']);
        exit;
    }

    session_write_close();

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
        
        $conv_data = $response['data']['data'] ?? [];
        // Đính kèm trạng thái SĐT từ DB
        attach_phone_status_to_conversations($conv_data, $pdo, $page_id);
        
        $output_array = ['status' => 'success', 'data' => $conv_data, 'next_cursor' => $next_cursor];
        
        if (!empty($cache_key)) {
            session_start();
            $_SESSION[$cache_key] = [
                'data' => $output_array,
                'expires' => time() + 15
            ];
            session_write_close();
        }
        
        echo json_encode($output_array);
    } else {
        $error_msg = isset($response['data']['error']['message']) ? $response['data']['error']['message'] : 'Lỗi không xác định';
        echo json_encode(['status' => 'error', 'msg' => $error_msg]);
    }
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
}

/**
 * Đính kèm thông tin và trạng thái số điện thoại từ DB cho danh sách cuộc hội thoại
 */
function attach_phone_status_to_conversations(&$conversations, $pdo, $currentPageId = '') {
    if (empty($conversations)) return;
    
    $sender_ids = [];
    foreach ($conversations as $c) {
        $page_id = isset($c['_page_id']) ? $c['_page_id'] : $currentPageId;
        if (isset($c['participants']['data'])) {
            foreach ($c['participants']['data'] as $p) {
                if ($p['id'] !== $page_id) {
                    $sender_ids[] = $p['id'];
                }
            }
        }
    }
    
    if (empty($sender_ids)) return;
    
    // Loại bỏ các ID trùng lặp
    $sender_ids = array_unique($sender_ids);
    
    // Tạo placeholders cho câu SQL IN
    $placeholders = implode(',', array_fill(0, count($sender_ids), '?'));
    $sql = "SELECT page_id, sender_id, phone FROM fb_customers WHERE phone IS NOT NULL AND phone != '' AND sender_id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($sender_ids));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $has_phone_map = [];
    foreach ($rows as $row) {
        $key = $row['page_id'] . '_' . $row['sender_id'];
        $has_phone_map[$key] = $row['phone'];
    }
    
    // Gán lại cho conversations
    foreach ($conversations as &$c) {
        $page_id = isset($c['_page_id']) ? $c['_page_id'] : $currentPageId;
        $c['has_phone'] = false;
        $c['phone_number'] = '';
        if (isset($c['participants']['data'])) {
            foreach ($c['participants']['data'] as $p) {
                if ($p['id'] !== $page_id) {
                    $key = $page_id . '_' . $p['id'];
                    if (isset($has_phone_map[$key])) {
                        $c['has_phone'] = true;
                        $c['phone_number'] = $has_phone_map[$key];
                    }
                }
            }
        }
    }
}
?>
