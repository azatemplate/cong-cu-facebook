<?php
// check_customer_comments.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

$sender_id = '28035987046103432';

echo "=== DIAGNOSING COMMENT ATTRIBUTION FOR SENDER $sender_id ===\n\n";

try {
    // 1. Fetch customer details
    $stmt_cust = $pdo->prepare("SELECT * FROM fb_customers WHERE sender_id = ?");
    $stmt_cust->execute([$sender_id]);
    $customer = $stmt_cust->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        echo "Customer NOT found in fb_customers.\n";
    } else {
        echo "Customer details in fb_customers:\n";
        print_r($customer);
    }
    
    // 2. Search page_notifications by sender_id
    echo "\nSearching page_notifications by sender_id = '$sender_id':\n";
    $stmt_not = $pdo->prepare("SELECT * FROM page_notifications WHERE sender_id = ? ORDER BY id DESC");
    $stmt_not->execute([$sender_id]);
    $notifications = $stmt_not->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($notifications) . " notifications.\n";
    print_r($notifications);
    
    // 3. Search page_notifications by Name if customer exists
    if ($customer && !empty($customer['name'])) {
        $name = $customer['name'];
        echo "\nSearching page_notifications by name = '$name':\n";
        $stmt_name = $pdo->prepare("SELECT * FROM page_notifications WHERE sender_name LIKE ? ORDER BY id DESC LIMIT 5");
        $stmt_name->execute(['%' . $name . '%']);
        $name_notifs = $stmt_name->fetchAll(PDO::FETCH_ASSOC);
        echo "Found " . count($name_notifs) . " notifications matching name.\n";
        print_r($name_notifs);
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
