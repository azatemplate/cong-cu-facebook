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

<div class="page-title">Privacy Policy</div>

<div class="card" style="max-width: 800px; line-height: 1.6;">
    <h1 style="color: var(--text-main); font-size: 24px;">Privacy Policy</h1>
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

<?php 
if ($is_logged_in) {
    include 'includes/footer.php';
} else {
?>
    </div>
</body>
</html>
<?php } ?>
