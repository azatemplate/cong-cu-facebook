<?php
session_start();
if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}
$current_page = 'token';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-title">Xử Lý Token Facebook</div>

<div class="card" id="processing-view" style="text-align: center; padding: 40px 20px;">
    <div style="font-size: 40px; color: var(--primary-color); margin-bottom: 20px;">⏳</div>
    <h3 style="margin-bottom: 10px;">Đang đọc dữ liệu Token...</h3>
    <p style="color: var(--text-muted);">Hệ thống đang trích xuất Access Token từ liên kết Facebook trả về.</p>
</div>

<div class="card" id="success-view" style="display: none; text-align: center; padding: 40px 20px; background: #f0fdf4; border: 1px solid #bbf7d0;">
    <div style="font-size: 40px; color: #16a34a; margin-bottom: 20px;">✔️</div>
    <h3 style="margin-bottom: 10px; color: #166534;">Lấy User Access Token Thành Công!</h3>
    <p style="color: #15803d; margin-bottom: 20px;">Chúng tôi đã lấy được mã Token của bạn. Hệ thống đang tự động lưu lại vào tài khoản...</p>
    
    <form id="autoSaveForm" action="actions/save_token.php" method="POST">
        <input type="hidden" name="access_token" id="hidden_access_token">
        <button type="submit" id="btn_submit_token" class="btn btn-primary" style="opacity: 0; pointer-events: none;">Lưu Token</button>
    </form>
</div>

<div class="card" id="error-view" style="display: none; text-align: center; padding: 40px 20px; background: #fef2f2; border: 1px solid #fecaca;">
    <div style="font-size: 40px; color: #dc2626; margin-bottom: 20px;">❌</div>
    <h3 style="margin-bottom: 10px; color: #991b1b;">Không tìm thấy Token</h3>
    <p style="color: #7f1d1d; margin-bottom: 20px;">Bạn có thể đã từ chối cấp quyền, hoặc liên kết trả về không hợp lệ.</p>
    <a href="token_management.php" class="btn btn-primary">Quay lại trang Quản lý</a>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Facebook returns token in the URL fragment like: #access_token=EAAG...&expires_in=...
    const hash = window.location.hash.substring(1);
    const params = new URLSearchParams(hash);
    
    const accessToken = params.get('access_token');
    
    setTimeout(() => {
        document.getElementById('processing-view').style.display = 'none';
        
        if (accessToken) {
            document.getElementById('success-view').style.display = 'block';
            document.getElementById('hidden_access_token').value = accessToken;
            
            // Auto submit
            document.getElementById('btn_submit_token').click();
        } else {
            document.getElementById('error-view').style.display = 'block';
        }
    }, 1500);
});
</script>

<?php include 'includes/footer.php'; ?>
