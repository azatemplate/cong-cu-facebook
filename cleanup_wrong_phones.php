<?php
// cleanup_wrong_phones.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

echo "=== CLEANING UP WRONGLY SCANNED HOTLINE PHONE NUMBERS ===\n\n";

$hotlines = ['0932087886', '0916375359', '0911213459', '0918018859', '0967849934'];
$hotlines_str = "'" . implode("','", $hotlines) . "'";

try {
    // 1. Count affected customers before cleanup
    $stmt_count = $pdo->query("SELECT COUNT(*) FROM fb_customers WHERE phone IN ($hotlines_str)");
    $affected_cust_count = $stmt_count->fetchColumn();
    echo "Found $affected_cust_count customer records with wrong hotline numbers.\n";
    
    // 2. Fetch list of affected customers
    $stmt_list = $pdo->query("SELECT page_id, sender_id, name, phone FROM fb_customers WHERE phone IN ($hotlines_str)");
    $list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($list)) {
        echo "\nAffected Customers:\n";
        foreach ($list as $row) {
            echo " - Page: {$row['page_id']} | Sender: {$row['sender_id']} | Name: {$row['name']} | Phone: {$row['phone']}\n";
        }
    }
    
    // 3. Delete 'Đã cho số điện thoại' labels for these customers
    $sql_del_labels = "
        DELETE cl FROM conversation_labels cl
        JOIN fb_customers fc ON cl.page_id = fc.page_id AND cl.recipient_id = fc.sender_id
        WHERE cl.label_name = 'Đã cho số điện thoại'
          AND fc.phone IN ($hotlines_str)
    ";
    $stmt_del = $pdo->prepare($sql_del_labels);
    $stmt_del->execute();
    $deleted_labels_count = $stmt_del->rowCount();
    echo "\nDeleted $deleted_labels_count 'Đã cho số điện thoại' labels from database.\n";
    
    // 4. Clear the phone numbers in fb_customers
    $sql_clear_phone = "
        UPDATE fb_customers 
        SET phone = NULL 
        WHERE phone IN ($hotlines_str)
    ";
    $stmt_clear = $pdo->prepare($sql_clear_phone);
    $stmt_clear->execute();
    $cleared_phone_count = $stmt_clear->rowCount();
    echo "Cleared phone field for $cleared_phone_count customer records.\n";
    
    echo "\nCLEANUP COMPLETED SUCCESSFULLY!\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
