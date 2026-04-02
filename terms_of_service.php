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

<div class="page-title">Terms of Service</div>

<div class="card" style="max-width: 800px; line-height: 1.6;">
    <h1 style="color: var(--text-main); font-size: 24px;">Terms of Service</h1>
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

<?php 
if ($is_logged_in) {
    include 'includes/footer.php';
} else {
?>
    </div>
</body>
</html>
<?php } ?>
