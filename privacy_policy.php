<?php
session_start();
$is_logged_in = isset($_SESSION['account_id']);
if ($is_logged_in) {
    $current_page = 'privacy';
    require_once __DIR__ . '/includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { padding: 40px 20px; background: #f3f4f6; }
        .public-container { max-width: 800px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="public-container">
<?php } ?>

<div class="page-title">
    <span class="lang-vi">Chính sách Bảo mật</span>
    <span class="lang-en" style="display:none;">Privacy Policy</span>
</div>

<div class="card" style="max-width: 800px; line-height: 1.6; position: relative;">
    <div class="lang-switch" style="position: absolute; top: 20px; right: 20px;">
        <button onclick="setLang('vi')" id="btn-vi" style="padding: 5px 12px; cursor: pointer; border-radius: 6px; border: 1px solid var(--primary, #4f46e5); background: var(--primary, #4f46e5); color: white; font-weight: 600; font-size: 13px;">VN</button>
        <button onclick="setLang('en')" id="btn-en" style="padding: 5px 12px; cursor: pointer; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #4b5563; font-weight: 600; font-size: 13px;">EN</button>
    </div>

    <div class="lang-en" style="display: none;">
        <h1 style="color: var(--text-main); font-size: 24px; margin-top: 0; padding-right: 100px;">Privacy Policy</h1>
        <p><em>Last updated: <?php echo date('F j, Y'); ?></em></p>

        <h3>1. Information We Collect</h3>
        <p>Our application collects your Facebook User ID, Name, and Page Access Tokens in order to provide Fanpage Management services. We do not collect passwords or any other personal data outside of what you authorize via Facebook Login.</p>

        <h3>2. How We Use Your Information</h3>
        <p>We use the collected information solely for the purpose of allowing you to manage, publish, and schedule content on your Facebook Fanpages from our dashboard. Your Page Access Tokens are securely stored and only used to execute actions you initiate within the application.</p>

        <h3>3. Data Sharing</h3>
        <p>We do not share, sell, or distribute your data to any third parties. All interactions happen directly between our server and the official Facebook Graph API.</p>

        <h3>4. Data Retention & Deletion</h3>
        <p>Your access tokens and associated page data are retained as long as you maintain an active account with us. You can delete your tokens at any time from the "Token Management" section, which will permanently remove the token and its associated fanpage records from our database.</p>

        <h3>5. Security</h3>
        <p>We implement standard security measures to protect your data. All database connections are secured, and we enforce authentication to ensure only authorized users can access their respective tokens.</p>

        <h3>6. Contact Us</h3>
        <p>If you have any questions regarding this Privacy Policy or how we handle data, please contact the system administrator.</p>
    </div>

    <div class="lang-vi">
        <h1 style="color: var(--text-main); font-size: 24px; margin-top: 0; padding-right: 100px;">Chính sách Bảo mật</h1>
        <p><em>Cập nhật lần cuối: <?php echo date('j/n/Y'); ?></em></p>

        <h3>1. Thông tin Chúng tôi Thu thập</h3>
        <p>Ứng dụng của chúng tôi thu thập ID Người dùng Facebook, Tên và Token Truy cập Trang của bạn để cung cấp dịch vụ Quản lý Fanpage. Chúng tôi không thu thập mật khẩu hoặc bất kỳ dữ liệu cá nhân nào khác ngoài những gì bạn ủy quyền thông qua Đăng nhập Facebook.</p>

        <h3>2. Cách Chúng tôi Sử dụng Thông tin</h3>
        <p>Chúng tôi sử dụng thông tin thu thập được chỉ nhằm mục đích cho phép bạn quản lý, xuất bản và lên lịch nội dung trên các Fanpage Facebook của mình từ bảng điều khiển của chúng tôi. Token Truy cập Trang của bạn được lưu trữ an toàn và chỉ được sử dụng để thực hiện các hành động do bạn khởi xướng trong ứng dụng.</p>

        <h3>3. Chia sẻ Dữ liệu</h3>
        <p>Chúng tôi không chia sẻ, bán hoặc phân phối dữ liệu của bạn cho bất kỳ bên thứ ba nào. Mọi tương tác đều diễn ra trực tiếp giữa máy chủ của chúng tôi và Facebook Graph API chính thức.</p>

        <h3>4. Lưu giữ & Xóa Dữ liệu</h3>
        <p>Token truy cập và dữ liệu trang được liên kết của bạn sẽ được lưu giữ chừng nào bạn còn duy trì một tài khoản hoạt động với chúng tôi. Bạn có thể xóa token của mình bất cứ lúc nào từ phần "Quản lý Token", thao tác này sẽ xóa vĩnh viễn token và các bản ghi fanpage được liên kết của nó khỏi cơ sở dữ liệu của chúng tôi.</p>

        <h3>5. Bảo mật</h3>
        <p>Chúng tôi thực hiện các biện pháp bảo mật tiêu chuẩn để bảo vệ dữ liệu của bạn. Tất cả các kết nối cơ sở dữ liệu đều được bảo mật và chúng tôi thực thi xác thực để đảm bảo chỉ những người dùng được ủy quyền mới có thể truy cập vào token tương ứng của họ.</p>

        <h3>6. Liên hệ</h3>
        <p>Nếu bạn có bất kỳ câu hỏi nào liên quan đến Chính sách Bảo mật này hoặc cách chúng tôi xử lý dữ liệu, vui lòng liên hệ với quản trị viên hệ thống.</p>
    </div>
</div>

<script>
function setLang(lang) {
    if(lang === 'en') {
        document.querySelectorAll('.lang-en').forEach(function(el) { el.style.display = 'block'; });
        document.querySelectorAll('.lang-vi').forEach(function(el) { el.style.display = 'none'; });
        document.getElementById('btn-en').style.background = 'var(--primary, #4f46e5)';
        document.getElementById('btn-en').style.color = 'white';
        document.getElementById('btn-en').style.borderColor = 'var(--primary, #4f46e5)';
        document.getElementById('btn-vi').style.background = '#fff';
        document.getElementById('btn-vi').style.color = '#4b5563';
        document.getElementById('btn-vi').style.borderColor = '#d1d5db';
        localStorage.setItem('pref_lang', 'en');
    } else {
        document.querySelectorAll('.lang-en').forEach(function(el) { el.style.display = 'none'; });
        document.querySelectorAll('.lang-vi').forEach(function(el) { el.style.display = 'block'; });
        document.getElementById('btn-vi').style.background = 'var(--primary, #4f46e5)';
        document.getElementById('btn-vi').style.color = 'white';
        document.getElementById('btn-vi').style.borderColor = 'var(--primary, #4f46e5)';
        document.getElementById('btn-en').style.background = '#fff';
        document.getElementById('btn-en').style.color = '#4b5563';
        document.getElementById('btn-en').style.borderColor = '#d1d5db';
        localStorage.setItem('pref_lang', 'vi');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    var pref = localStorage.getItem('pref_lang') || 'vi';
    setLang(pref);
});
</script>

<?php 
if ($is_logged_in) {
    include 'includes/footer.php';
} else {
?>
    </div>
</body>
</html>
<?php } ?>
