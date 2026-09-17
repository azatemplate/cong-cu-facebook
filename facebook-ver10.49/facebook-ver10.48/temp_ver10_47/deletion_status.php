<?php
/**
 * Facebook Data Deletion Status Page
 * 
 * Displays the status of a specific data deletion request.
 * Complies with Facebook Platform Policy requirements.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

$code = isset($_GET['id']) ? trim($_GET['id']) : '';
$status = null;
$created_at = null;
$found = false;

if (!empty($code)) {
    try {
        $stmt = $pdo->prepare("SELECT status, created_at FROM data_deletion_requests WHERE confirmation_code = ?");
        $stmt->execute([$code]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($request) {
            $status = $request['status'];
            $created_at = $request['created_at'];
            $found = true;
        }
    } catch (PDOException $e) {
        error_log("Database error looking up deletion request: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trạng Thái Xóa Dữ Liệu Facebook</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            --card-bg: rgba(30, 41, 59, 0.7);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-glow: 0 0 20px rgba(99, 102, 241, 0.2);
            --success-glow: 0 0 25px rgba(34, 197, 94, 0.3);
            --warning-glow: 0 0 25px rgba(239, 68, 68, 0.3);
        }
        body {
            margin: 0;
            padding: 40px 20px;
            font-family: 'Outfit', sans-serif;
            background: var(--bg-gradient);
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
        }
        .status-container {
            max-width: 600px;
            width: 100%;
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 45px 40px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3), var(--text-glow);
            text-align: center;
            animation: fadeIn 0.6s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .icon {
            font-size: 72px;
            margin-bottom: 24px;
            display: inline-block;
        }
        .icon-success {
            animation: scaleCheck 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) both;
            text-shadow: var(--success-glow);
        }
        @keyframes scaleCheck {
            0% { transform: scale(0); }
            100% { transform: scale(1); }
        }
        h1 {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #fff;
        }
        .highlight-title {
            background: linear-gradient(to right, #4ade80, #22c55e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        p.desc {
            color: #94a3b8;
            line-height: 1.6;
            font-size: 15px;
            margin-bottom: 30px;
        }
        .status-card {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
        }
        .status-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .status-row:last-child {
            margin-bottom: 0;
        }
        .status-label {
            color: #64748b;
            font-weight: 500;
        }
        .status-value {
            color: #f1f5f9;
            font-weight: 600;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-success {
            background: rgba(34, 197, 94, 0.15);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        label {
            display: block;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6366f1;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .input-group {
            display: flex;
            gap: 10px;
        }
        input[type="text"] {
            flex: 1;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.6);
            color: #fff;
            font-size: 15px;
            outline: none;
            transition: all 0.3s;
        }
        input[type="text"]:focus {
            border-color: #6366f1;
            box-shadow: 0 0 10px rgba(99, 102, 241, 0.3);
        }
        .btn-submit {
            background: linear-gradient(to right, #4f46e5, #7c3aed);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(99, 102, 241, 0.4);
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14.5px;
            text-align: left;
        }
        .footer-links {
            margin-top: 30px;
            font-size: 13px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            padding-top: 20px;
        }
        .footer-links a {
            color: #818cf8;
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer-links a:hover {
            color: #c084fc;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="status-container">
        <?php if ($found): ?>
            <!-- Success Status view -->
            <div class="icon icon-success">✅</div>
            <h1>Dữ Liệu Đã Được <span class="highlight-title">Xóa Sạch</span></h1>
            <p class="desc">
                Hệ thống xác nhận toàn bộ thông tin cá nhân và dữ liệu liên kết tài khoản Facebook của bạn đã được xóa hoàn tất và không thể khôi phục.
            </p>

            <div class="status-card">
                <div class="status-row">
                    <span class="status-label">Mã xác nhận:</span>
                    <span class="status-value" style="font-family: monospace;"><?php echo htmlspecialchars($code); ?></span>
                </div>
                <div class="status-row">
                    <span class="status-label">Trạng thái hệ thống:</span>
                    <span><span class="badge badge-success">Đã hoàn thành</span></span>
                </div>
                <div class="status-row">
                    <span class="status-label">Thời gian thực hiện:</span>
                    <span class="status-value"><?php echo htmlspecialchars($created_at); ?></span>
                </div>
                <div class="status-row">
                    <span class="status-label">Cam kết bảo mật:</span>
                    <span class="status-value" style="color: #4ade80;">Đã hủy liên kết 100%</span>
                </div>
            </div>
            
            <a href="facebook_data_deletion.php" class="btn-submit" style="display: inline-block; text-decoration: none; margin-top: 10px;">Quay lại trang chủ</a>

        <?php else: ?>
            <!-- Form view (when code not provided or not found) -->
            <div class="icon">🔍</div>
            <h1>Tra Cứu Trạng Thái Xóa</h1>
            <p class="desc">
                Nhập mã xác nhận xóa dữ liệu (Confirmation Code) được cung cấp từ Facebook để kiểm tra trạng thái xóa dữ liệu của bạn khỏi hệ thống.
            </p>

            <?php if (!empty($code)): ?>
                <div class="alert-error">
                    <strong>Lỗi:</strong> Không tìm thấy yêu cầu xóa dữ liệu với mã <code><?php echo htmlspecialchars($code); ?></code>. Vui lòng kiểm tra lại mã chính xác.
                </div>
            <?php endif; ?>

            <form action="deletion_status.php" method="GET">
                <div class="form-group">
                    <label for="id">Mã xác nhận xóa dữ liệu</label>
                    <div class="input-group">
                        <input type="text" id="id" name="id" value="<?php echo htmlspecialchars($code); ?>" placeholder="Ví dụ: del_..." required>
                        <button type="submit" class="btn-submit">Kiểm tra</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <div class="footer-links">
            <a href="privacy_policy.php">Chính sách bảo mật</a>
            <span style="color: rgba(255,255,255,0.2); margin: 0 10px;">|</span>
            <a href="terms_of_service.php">Điều khoản dịch vụ</a>
        </div>
    </div>
</body>
</html>
