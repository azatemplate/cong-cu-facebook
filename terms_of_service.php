<?php
session_start();
$is_logged_in = isset($_SESSION['account_id']);
if ($is_logged_in) {
    $current_page = 'terms';
    require_once __DIR__ . '/includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service</title>
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
    <span class="lang-vi">Điều khoản Dịch vụ</span>
    <span class="lang-en" style="display:none;">Terms of Service</span>
</div>

<div class="card" style="max-width: 800px; line-height: 1.6; position: relative;">
    <div class="lang-switch" style="position: absolute; top: 20px; right: 20px;">
        <button onclick="setLang('vi')" id="btn-vi" style="padding: 5px 12px; cursor: pointer; border-radius: 6px; border: 1px solid var(--primary, #4f46e5); background: var(--primary, #4f46e5); color: white; font-weight: 600; font-size: 13px;">VN</button>
        <button onclick="setLang('en')" id="btn-en" style="padding: 5px 12px; cursor: pointer; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #4b5563; font-weight: 600; font-size: 13px;">EN</button>
    </div>

    <div class="lang-en" style="display: none;">
        <h1 style="color: var(--text-main); font-size: 24px; margin-top: 0; padding-right: 100px;">Terms of Service</h1>
        <p><em>Last updated: <?php echo date('F j, Y'); ?></em></p>

        <h3>1. Acceptance of Terms</h3>
        <p>By accessing and using this Fanpage Management system, you accept and agree to be bound by the terms and provision of this agreement.</p>

        <h3>2. Description of Service</h3>
        <p>We provide a web-based interface that allows users to manage multiple Facebook Fanpages, post content, and schedule uploads through the Facebook Graph API.</p>

        <h3>3. User Responsibilities</h3>
        <p>You are responsible for any activity that occurs under your account. You agree not to use the system for any illegal or unauthorized purposes. You must comply with all local laws and Facebook's Platform Policies.</p>

        <h3>4. API Limitations</h3>
        <p>Our service relies on the Facebook Graph API. We are not responsible for any downtime, API changes, or limitations imposed by Facebook that may affect the functionality of this application.</p>

        <h3>5. Termination</h3>
        <p>We reserve the right to suspend or terminate your access to the application at any time for any reason, particularly if you violate these Terms of Service or Facebook's Platform Terms.</p>

        <h3>6. Changes to Terms</h3>
        <p>We reserve the right to modify these terms at any time. Your continued use of the service after any such changes constitutes your acceptance of the new Terms of Service.</p>
    </div>

    <div class="lang-vi">
        <h1 style="color: var(--text-main); font-size: 24px; margin-top: 0; padding-right: 100px;">Điều khoản Dịch vụ</h1>
        <p><em>Cập nhật lần cuối: <?php echo date('j/n/Y'); ?></em></p>

        <h3>1. Chấp nhận Điều khoản</h3>
        <p>Bằng việc truy cập và sử dụng hệ thống Quản lý Fanpage này, bạn chấp nhận và đồng ý bị ràng buộc bởi các điều khoản và quy định của thỏa thuận này.</p>

        <h3>2. Mô tả Dịch vụ</h3>
        <p>Chúng tôi cung cấp một giao diện web cho phép người dùng quản lý nhiều Fanpage Facebook, đăng nội dung và lên lịch tải lên thông qua Facebook Graph API.</p>

        <h3>3. Trách nhiệm của Người dùng</h3>
        <p>Bạn chịu trách nhiệm về mọi hoạt động diễn ra dưới tài khoản của mình. Bạn đồng ý không sử dụng hệ thống cho bất kỳ mục đích bất hợp pháp hoặc không được phép nào. Bạn phải tuân thủ tất cả luật pháp địa phương và Chính sách Nền tảng của Facebook.</p>

        <h3>4. Giới hạn API</h3>
        <p>Dịch vụ của chúng tôi phụ thuộc vào Facebook Graph API. Chúng tôi không chịu trách nhiệm về bất kỳ thời gian chết, thay đổi API hoặc giới hạn nào do Facebook áp đặt có thể ảnh hưởng đến chức năng của ứng dụng này.</p>

        <h3>5. Chấm dứt Dịch vụ</h3>
        <p>Chúng tôi có quyền đình chỉ hoặc chấm dứt quyền truy cập của bạn vào ứng dụng bất cứ lúc nào với bất kỳ lý do gì, đặc biệt là nếu bạn vi phạm các Điều khoản Dịch vụ này hoặc Điều khoản Nền tảng của Facebook.</p>

        <h3>6. Thay đổi Điều khoản</h3>
        <p>Chúng tôi có quyền sửa đổi các điều khoản này bất cứ lúc nào. Việc bạn tiếp tục sử dụng dịch vụ sau những thay đổi đó cấu thành sự chấp nhận của bạn đối với Điều khoản Dịch vụ mới.</p>
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
