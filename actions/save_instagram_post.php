<?php
// actions/save_instagram_post.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/fb_api.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập hệ thống.']);
    exit;
}

$account_id = $_SESSION['account_id'];
$post_type  = trim($_POST['post_type'] ?? ''); // story or reels
$page_id    = trim($_POST['page_id'] ?? '');
$caption    = trim($_POST['caption'] ?? '');

if (empty($page_id)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng chọn Trang Instagram.']);
    exit;
}

if (!in_array($post_type, ['story', 'reels'])) {
    echo json_encode(['success' => false, 'message' => 'Loại đăng không hợp lệ (chỉ hỗ trợ Story hoặc Reels).']);
    exit;
}

// Fetch Page info & Instagram business ID
$stmt = $pdo->prepare("
    SELECT p.page_id, p.access_token, p.ig_account_id, p.name as page_name
    FROM pages p
    JOIN users u ON p.user_id = u.id
    WHERE p.page_id = ? AND u.account_id = ?
");
$stmt->execute([$page_id, $account_id]);
$page_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page_info || empty($page_info['ig_account_id'])) {
    echo json_encode(['success' => false, 'message' => 'Trang được chọn chưa liên kết với tài khoản Instagram Business.']);
    exit;
}

$token = decryptData($page_info['access_token']);
$ig_id = $page_info['ig_account_id'];

// Handle media upload
$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$base_url = $protocol . $_SERVER['HTTP_HOST'] . get_base_url();

$media_url = '';
$cover_url = '';

if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['media_file']['tmp_name'];
    $file_name = $_FILES['media_file']['name'];
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov'];
    if (!in_array($ext, $allowed_exts)) {
        echo json_encode(['success' => false, 'message' => 'Định dạng tệp không được hỗ trợ. Vui lòng tải lên ảnh (JPG, PNG) hoặc video (MP4, MOV).']);
        exit;
    }

    $new_filename = 'ig_' . time() . '_' . uniqid() . '.' . $ext;
    $target_path = $upload_dir . $new_filename;

    if (move_uploaded_file($file_tmp, $target_path)) {
        $media_url = $base_url . 'uploads/' . $new_filename;
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi lưu tệp media trên máy chủ.']);
        exit;
    }
} else if (!empty($_POST['media_url'])) {
    $media_url = trim($_POST['media_url']);
}

if (empty($media_url)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng chọn hoặc tải lên tệp Media cho bài viết.']);
    exit;
}

// Cover image for Reels
if (isset($_FILES['cover_file']) && $_FILES['cover_file']['error'] === UPLOAD_ERR_OK) {
    $c_tmp = $_FILES['cover_file']['tmp_name'];
    $c_name = $_FILES['cover_file']['name'];
    $c_ext = strtolower(pathinfo($c_name, PATHINFO_EXTENSION));
    
    if (in_array($c_ext, ['jpg', 'jpeg', 'png'])) {
        $c_filename = 'ig_cover_' . time() . '_' . uniqid() . '.' . $c_ext;
        if (move_uploaded_file($c_tmp, $upload_dir . $c_filename)) {
            $cover_url = $base_url . 'uploads/' . $c_filename;
        }
    }
}

// 1. Create Media Container
$params = [
    'access_token' => $token
];

$file_ext = strtolower(pathinfo(parse_url($media_url, PHP_URL_PATH), PATHINFO_EXTENSION));
$is_video = in_array($file_ext, ['mp4', 'mov', 'm4v', 'avi']);

if ($post_type === 'story') {
    $params['media_type'] = 'STORIES';
    if ($is_video) {
        $params['video_url'] = $media_url;
    } else {
        $params['image_url'] = $media_url;
    }
} else if ($post_type === 'reels') {
    $params['media_type'] = 'REELS';
    $params['video_url'] = $media_url;
    if (!empty($caption)) {
        $params['caption'] = $caption;
    }
    if (!empty($cover_url)) {
        $params['cover_url'] = $cover_url;
    }
}

$container_res = fb_api_request("$ig_id/media", $params, 'POST');

if ($container_res['status_code'] !== 200 || empty($container_res['data']['id'])) {
    $err = $container_res['data']['error']['message'] ?? 'Lỗi khởi tạo bài đăng trên Instagram.';
    echo json_encode(['success' => false, 'message' => $err]);
    exit;
}

$container_id = $container_res['data']['id'];

// 2. Poll container status until FINISHED
$max_attempts = 15;
$published = false;
$error_msg = '';

for ($i = 0; $i < $max_attempts; $i++) {
    // Check status
    $status_res = fb_api_request($container_id, [
        'fields' => 'status_code,status',
        'access_token' => $token
    ]);

    if ($status_res['status_code'] === 200 && isset($status_res['data']['status_code'])) {
        $status = strtoupper($status_res['data']['status_code']);
        if ($status === 'FINISHED') {
            $published = true;
            break;
        } else if ($status === 'ERROR') {
            $error_msg = 'Trạng thái tệp media bị lỗi trong quá trình xử lý của Instagram.';
            break;
        }
    }
    
    // For simple image stories, status check might complete instantly
    if (!$is_video && $i == 0) {
        // give instant try
    }
    sleep(2);
}

// If it's an image story or finished video, attempt to publish
$pub_res = fb_api_request("$ig_id/media_publish", [
    'creation_id' => $container_id,
    'access_token' => $token
], 'POST');

if ($pub_res['status_code'] === 200 && !empty($pub_res['data']['id'])) {
    $post_id = $pub_res['data']['id'];
    $label = ($post_type === 'story') ? 'Story' : 'Reels';
    echo json_encode([
        'success' => true, 
        'message' => "Đã xuất bản Instagram $label thành công!",
        'post_id' => $post_id
    ]);
} else {
    $err = $pub_res['data']['error']['message'] ?? ($error_msg ?: 'Không thể hoàn tất xuất bản bài viết lên Instagram.');
    echo json_encode(['success' => false, 'message' => $err]);
}
