<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

echo "Bắt đầu cập nhật dữ liệu database bị lỗi font...<br>";

// 1. Fetch all users
$stmt_users = $pdo->query("SELECT id, access_token, name FROM users");
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $user) {
    echo "Đang cập nhật Profile User ID: " . $user['id'] . "<br>";
    $token = $user['access_token'];
    
    // Verify Profile
    $profile = get_fb_user_profile($token);
    if ($profile['status_code'] === 200 && isset($profile['data']['name'])) {
        $real_name = $profile['data']['name'];
        if ($real_name !== $user['name']) {
            $u_stmt = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
            $u_stmt->execute([$real_name, $user['id']]);
            echo "- Cập nhật tên User thành công: " . $real_name . "<br>";
        }
    }
    
    // Fetch user's pages
    echo "Đang cập nhật Fanpages cho User ID: " . $user['id'] . "...<br>";
    $after_cursor = null;
    $has_next = true;

    while ($has_next) {
        $pages_response = get_fb_user_pages($token, $after_cursor);
        
        if ($pages_response['status_code'] === 200 && isset($pages_response['data']['data'])) {
            $pages = $pages_response['data']['data'];
            
            foreach ($pages as $page) {
                $page_id = $page['id'];
                $page_name = $page['name'];
                $category = isset($page['category']) ? $page['category'] : '';
                
                $u_stmt = $pdo->prepare("UPDATE pages SET name = ?, category = ? WHERE page_id = ?");
                $u_stmt->execute([$page_name, $category, $page_id]);
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
    echo "- Cập nhật Fanpages hoàn tất.<br>";
}

echo "<br><b>ĐÃ CẬP NHẬT XONG!</b> Bạn có thể xóa file này.";
?>
