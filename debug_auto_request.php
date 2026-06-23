<?php
// debug_auto_request.php
// Diagnostic script to troubleshoot auto-request worker skipping issues

ignore_user_abort(true);
set_time_limit(300);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

header('Content-Type: text/plain; charset=utf-8');

echo "========================================================\n";
echo "  AUTO-REQUEST DIAGNOSTICS - " . date('Y-m-d H:i:s') . "\n";
echo "========================================================\n\n";

echo "--- TIMEZONE & TIME SETTINGS ---\n";
echo "PHP date_default_timezone_get(): " . date_default_timezone_get() . "\n";
echo "PHP local time: " . date('Y-m-d H:i:s') . " (Timestamp: " . time() . ")\n";

try {
    $stmt = $pdo->query("SELECT NOW() as db_now, @@global.time_zone as gt, @@session.time_zone as st, UTC_TIMESTAMP() as utc_now");
    $db_info = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "MySQL NOW(): " . $db_info['db_now'] . "\n";
    echo "MySQL UTC_TIMESTAMP(): " . $db_info['utc_now'] . "\n";
    echo "MySQL global timezone: " . $db_info['gt'] . "\n";
    echo "MySQL session timezone: " . $db_info['st'] . "\n";
} catch (Exception $e) {
    echo "MySQL Timezone Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Helper to replace tags (same as auto_request_phone.php)
function replace_message_tags_debug($text, $customer_name, $sales_phone) {
    $text = str_replace('{name}', $customer_name, $text);
    $sales_phone = trim($sales_phone ?? '');
    return preg_replace_callback('/\{phone-sales(?:\|([^}]+))?\}/', function($matches) use ($sales_phone) {
        if (!empty($sales_phone)) {
            return $sales_phone;
        }
        return isset($matches[1]) ? $matches[1] : '';
    }, $text);
}

// ── FACEBOOK DIAGNOSTICS ────────────────────────────────────────────────
echo "========================================================\n";
echo "  FACEBOOK DIAGNOSTICS\n";
echo "========================================================\n";

try {
    $sql_fb_pages = "
        SELECT p.page_id, p.name as page_name, p.user_id,
               sa.username, sa.phone_request_enabled, sa.phone_request_hours, sa.phone_request_text, 
               sa.province_request_text, sa.product_request_text,
               sa.followup_request_enabled, sa.followup_request_hours, sa.followup_request_text
        FROM pages p
        JOIN users u ON p.user_id = u.id
        JOIN system_accounts sa ON u.account_id = sa.id
        WHERE sa.phone_request_enabled = 1 OR sa.followup_request_enabled = 1
    ";
    $pages = $pdo->query($sql_fb_pages)->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($pages) . " Facebook Fanpages with auto-request/follow-up enabled.\n";
    echo "Filtering and showing only Fanpages that have waiting customers...\n\n";

    $active_fb_pages = 0;
    foreach ($pages as $page) {
        $page_id = $page['page_id'];
        $page_name = $page['page_name'];

        $phone_request_enabled = (int)($page['phone_request_enabled'] ?? 0);
        $hours = max(1, intval($page['phone_request_hours']));
        $phone_request_text = trim($page['phone_request_text'] ?? '');
        $province_request_text = trim($page['province_request_text'] ?? '');
        $product_request_text = trim($page['product_request_text'] ?? '');
        
        $followup_request_enabled = (int)($page['followup_request_enabled'] ?? 0);
        $followup_hours = max(1, intval($page['followup_request_hours']));
        $followup_request_text = trim($page['followup_request_text'] ?? '');

        // Run the SQL query to get waiting customers
        $sql_fb_customers = "
            SELECT c.name, c.phone, c.province, c.notes, c.sender_id, c.last_message_at, c.info_requested_at, c.followup_requested_at, c.sales_phone
            FROM fb_customers c
            WHERE c.page_id = :page_id
              AND c.last_sender = 'customer'
              AND NOT EXISTS (
                  SELECT 1 FROM bot_chat_locks l
                  WHERE l.page_id = c.page_id AND l.sender_id = c.sender_id AND l.expire_at > NOW()
              )
              AND (
                  -- Case 1: Cần tự động xin thông tin
                  (
                      :phone_request_enabled = 1
                      AND NOT (c.phone IS NOT NULL AND c.phone != '' AND (:has_province_req = 0 OR (c.province IS NOT NULL AND c.province != '')) AND (:has_product_req = 0 OR (c.notes IS NOT NULL AND c.notes != '')))
                      AND c.last_message_at <= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                      AND c.last_message_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      AND (c.info_requested_at IS NULL OR c.last_message_at > c.info_requested_at)
                  )
                  OR
                  -- Case 2: Cần tự động gửi tin CSKH/Follow-up
                  (
                      :followup_request_enabled = 1
                      AND (c.phone IS NOT NULL AND c.phone != '' AND (:has_province_req = 0 OR (c.province IS NOT NULL AND c.province != '')) AND (:has_product_req = 0 OR (c.notes IS NOT NULL AND c.notes != '')))
                      AND c.last_message_at <= DATE_SUB(NOW(), INTERVAL :followup_hours HOUR)
                      AND c.last_message_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                      AND (c.followup_requested_at IS NULL OR c.last_message_at > c.followup_requested_at)
                  )
              )
        ";
        $stmt_cust = $pdo->prepare($sql_fb_customers);
        $stmt_cust->execute([
            ':page_id' => $page_id,
            ':phone_request_enabled' => $phone_request_enabled,
            ':has_province_req' => empty($province_request_text) ? 0 : 1,
            ':has_product_req' => empty($product_request_text) ? 0 : 1,
            ':hours' => $hours,
            ':followup_request_enabled' => $followup_request_enabled,
            ':followup_hours' => $followup_hours
        ]);
        $customers = $stmt_cust->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($customers) > 0) {
            $active_fb_pages++;
            echo "--------------------------------------------------------\n";
            echo "Fanpage: $page_name ($page_id) | Account: {$page['username']}\n";
            echo "  [Phone Request] Enabled: {$page['phone_request_enabled']}, Hours: {$page['phone_request_hours']}\n";
            echo "    Template Phone: '" . ($page['phone_request_text']) . "'\n";
            echo "    Template Province: '" . ($page['province_request_text']) . "'\n";
            echo "    Template Product: '" . ($page['product_request_text']) . "'\n";
            echo "  [Follow-up CSKH] Enabled: {$page['followup_request_enabled']}, Hours: {$page['followup_request_hours']}\n";
            echo "    Template Follow-up: '" . ($page['followup_request_text']) . "'\n";
            echo "  => SQL detected: " . count($customers) . " customers waiting.\n";

            $limit = 5;
            $idx = 0;
            foreach ($customers as $c) {
                $idx++;
                if ($idx > $limit) {
                    echo "  (and " . (count($customers) - $limit) . " more customers...)\n";
                    break;
                }
                echo "  [Customer #$idx] Name: '{$c['name']}' | Sender ID: {$c['sender_id']}\n";
                echo "    Phone: '" . ($c['phone'] ?? 'NULL') . "' | Province: '" . ($c['province'] ?? 'NULL') . "' | Notes: '" . ($c['notes'] ?? 'NULL') . "'\n";
                echo "    last_message_at: {$c['last_message_at']} | info_requested_at: " . ($c['info_requested_at'] ?? 'NULL') . " | followup_requested_at: " . ($c['followup_requested_at'] ?? 'NULL') . "\n";
                
                // Check completeness
                $is_info_complete = !empty($c['phone']) 
                    && (empty($province_request_text) || !empty($c['province'])) 
                    && (empty($product_request_text) || !empty($c['notes']));
                echo "    is_info_complete (PHP calculation): " . ($is_info_complete ? "TRUE" : "FALSE") . "\n";
                
                $last_msg_ts = strtotime($c['last_message_at']);
                $diff_hours = (time() - $last_msg_ts) / 3600;
                echo "    Time diff: " . round($diff_hours, 2) . " hours (PHP time() - last_message_at)\n";
                
                if (!$is_info_complete) {
                    echo "    => Logic Path: TỰ ĐỘNG XIN THÔNG TIN\n";
                    echo "      - phone_request_enabled: " . ($phone_request_enabled ? "YES" : "NO") . "\n";
                    echo "      - Hours wait check: is " . round($diff_hours, 2) . " >= $hours AND " . round($diff_hours, 2) . " <= 24? " . (($diff_hours >= $hours && $diff_hours <= 24) ? "YES" : "NO") . "\n";
                    
                    if ($phone_request_enabled && ($diff_hours >= $hours && $diff_hours <= 24)) {
                        $has_newer_msg = !empty($c['info_requested_at']) && strtotime($c['last_message_at']) <= strtotime($c['info_requested_at']);
                        echo "      - Has newer message check: (info_requested_at empty OR last_message_at > info_requested_at)? " . ($has_newer_msg ? "NO (Skipped, already requested for this message)" : "YES") . "\n";
                        
                        if (!$has_newer_msg) {
                            $msg_to_send = '';
                            if (empty($c['phone']) && !empty($phone_request_text)) $msg_to_send = $phone_request_text;
                            elseif (empty($c['province']) && !empty($province_request_text)) $msg_to_send = $province_request_text;
                            elseif (empty($c['notes']) && !empty($product_request_text)) $msg_to_send = $product_request_text;
                            
                            echo "      - Message to send: '" . $msg_to_send . "'\n";
                            if (empty($msg_to_send)) {
                                echo "      - SKIP REASON: No template message text configured for the missing information field.\n";
                            } else {
                                $message_text = replace_message_tags_debug($msg_to_send, $c['name'] ?? 'bạn', $c['sales_phone']);
                                echo "      - READY TO SEND: \"$message_text\"\n";
                            }
                        }
                    }
                } else {
                    echo "    => Logic Path: TỰ ĐỘNG GỬI TIN CSKH / FOLLOW-UP\n";
                    echo "      - followup_request_enabled: " . ($followup_request_enabled ? "YES" : "NO") . "\n";
                    echo "      - Followup text empty?: " . (empty($followup_request_text) ? "YES (Skipped, text is empty!)" : "NO") . "\n";
                    echo "      - Hours wait check: is " . round($diff_hours, 2) . " >= $followup_hours? " . (($diff_hours >= $followup_hours) ? "YES" : "NO") . "\n";
                    
                    if ($followup_request_enabled && !empty($followup_request_text) && $diff_hours >= $followup_hours) {
                        $has_newer_msg = !empty($c['followup_requested_at']) && strtotime($c['last_message_at']) <= strtotime($c['followup_requested_at']);
                        echo "      - Has newer message check: (followup_requested_at empty OR last_message_at > followup_requested_at)? " . ($has_newer_msg ? "NO (Skipped, already followed up for this message)" : "YES") . "\n";
                        
                        if (!$has_newer_msg) {
                            $message_text = replace_message_tags_debug($followup_request_text, $c['name'] ?? 'bạn', $c['sales_phone']);
                            echo "      - READY TO SEND CSKH: \"$message_text\"\n";
                        }
                    }
                }
            }
        }
    }
    
    if ($active_fb_pages === 0) {
        echo "No Facebook Fanpages have active waiting customers.\n";
    }
} catch (Exception $e) {
    echo "Facebook Diagnostics Error: " . $e->getMessage() . "\n";
}
echo "\n";

// ── ZALO DIAGNOSTICS ────────────────────────────────────────────────────
echo "========================================================\n";
echo "  ZALO DIAGNOSTICS\n";
echo "========================================================\n";

try {
    $sql_zalo_oas = "
        SELECT zo.oa_id, zo.account_id,
               zs.phone_request_enabled, zs.phone_request_hours, zs.phone_request_text, 
               zs.province_request_text, zs.product_request_text,
               zs.followup_request_enabled, zs.followup_request_hours, zs.followup_request_text
        FROM zalo_oas zo
        JOIN zalo_settings zs ON zo.account_id = zs.account_id
        WHERE zs.phone_request_enabled = 1 OR zs.followup_request_enabled = 1
    ";
    $oas = $pdo->query($sql_zalo_oas)->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($oas) . " Zalo OAs with auto-request/follow-up enabled.\n";
    echo "Filtering and showing only OAs that have waiting customers...\n\n";

    $active_zalo_oas = 0;
    foreach ($oas as $oa) {
        $oa_id = $oa['oa_id'];
        
        $phone_request_enabled = (int)($oa['phone_request_enabled'] ?? 0);
        $hours = max(1, intval($oa['phone_request_hours']));
        $phone_request_text = trim($oa['phone_request_text'] ?? '');
        $province_request_text = trim($oa['province_request_text'] ?? '');
        $product_request_text = trim($oa['product_request_text'] ?? '');
        
        $followup_request_enabled = (int)($oa['followup_request_enabled'] ?? 0);
        $followup_hours = max(1, intval($oa['followup_request_hours']));
        $followup_request_text = trim($oa['followup_request_text'] ?? '');

        // Run the SQL query to get waiting customers
        $sql_zalo_customers = "
            SELECT name, phone, province, notes, sender_id, last_message_at, info_requested_at, followup_requested_at, sales_phone
            FROM zalo_customers
            WHERE oa_id = :oa_id
              AND last_sender = 'customer'
              AND NOT EXISTS (
                  SELECT 1 FROM zalo_chat_locks l
                  WHERE l.oa_id = zalo_customers.oa_id AND l.sender_id = zalo_customers.sender_id AND l.expire_at > NOW()
              )
              AND (
                  -- Case 1: Cần tự động xin thông tin
                  (
                      :phone_request_enabled = 1
                      AND NOT (phone IS NOT NULL AND phone != '' AND (:has_province_req = 0 OR (province IS NOT NULL AND province != '')) AND (:has_product_req = 0 OR (notes IS NOT NULL AND notes != '')))
                      AND last_message_at <= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                      AND last_message_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      AND (info_requested_at IS NULL OR last_message_at > info_requested_at)
                  )
                  OR
                  -- Case 2: Cần tự động gửi tin CSKH/Follow-up
                  (
                      :followup_request_enabled = 1
                      AND (phone IS NOT NULL AND phone != '' AND (:has_province_req = 0 OR (province IS NOT NULL AND province != '')) AND (:has_product_req = 0 OR (notes IS NOT NULL AND notes != '')))
                      AND last_message_at <= DATE_SUB(NOW(), INTERVAL :followup_hours HOUR)
                      AND last_message_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                      AND (followup_requested_at IS NULL OR last_message_at > followup_requested_at)
                  )
              )
        ";
        $stmt_cust = $pdo->prepare($sql_zalo_customers);
        $stmt_cust->execute([
            ':oa_id' => $oa_id,
            ':phone_request_enabled' => $phone_request_enabled,
            ':has_province_req' => empty($province_request_text) ? 0 : 1,
            ':has_product_req' => empty($product_request_text) ? 0 : 1,
            ':hours' => $hours,
            ':followup_request_enabled' => $followup_request_enabled,
            ':followup_hours' => $followup_hours
        ]);
        $customers = $stmt_cust->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($customers) > 0) {
            $active_zalo_oas++;
            echo "--------------------------------------------------------\n";
            echo "Zalo OA: $oa_id | Account: {$oa['account_id']}\n";
            echo "  [Phone Request] Enabled: {$oa['phone_request_enabled']}, Hours: {$oa['phone_request_hours']}\n";
            echo "    Template Phone: '" . ($oa['phone_request_text']) . "'\n";
            echo "    Template Province: '" . ($oa['province_request_text']) . "'\n";
            echo "    Template Product: '" . ($oa['product_request_text']) . "'\n";
            echo "  [Follow-up CSKH] Enabled: {$oa['followup_request_enabled']}, Hours: {$oa['followup_request_hours']}\n";
            echo "    Template Follow-up: '" . ($oa['followup_request_text']) . "'\n";
            echo "  => SQL detected: " . count($customers) . " Zalo customers waiting.\n";

            $limit = 5;
            $idx = 0;
            foreach ($customers as $c) {
                $idx++;
                if ($idx > $limit) {
                    echo "  (and " . (count($customers) - $limit) . " more customers...)\n";
                    break;
                }
                echo "  [Customer #$idx] Name: '{$c['name']}' | Sender ID: {$c['sender_id']}\n";
                echo "    Phone: '" . ($c['phone'] ?? 'NULL') . "' | Province: '" . ($c['province'] ?? 'NULL') . "' | Notes: '" . ($c['notes'] ?? 'NULL') . "'\n";
                echo "    last_message_at: {$c['last_message_at']} | info_requested_at: " . ($c['info_requested_at'] ?? 'NULL') . " | followup_requested_at: " . ($c['followup_requested_at'] ?? 'NULL') . "\n";
                
                // Check completeness
                $is_info_complete = !empty($c['phone']) 
                    && (empty($province_request_text) || !empty($c['province'])) 
                    && (empty($product_request_text) || !empty($c['notes']));
                echo "    is_info_complete (PHP calculation): " . ($is_info_complete ? "TRUE" : "FALSE") . "\n";
                
                $last_msg_ts = strtotime($c['last_message_at']);
                $diff_hours = (time() - $last_msg_ts) / 3600;
                echo "    Time diff: " . round($diff_hours, 2) . " hours (PHP time() - last_message_at)\n";
                
                if (!$is_info_complete) {
                    echo "    => Logic Path: TỰ ĐỘNG XIN THÔNG TIN\n";
                    echo "      - phone_request_enabled: " . ($phone_request_enabled ? "YES" : "NO") . "\n";
                    echo "      - Hours wait check: is " . round($diff_hours, 2) . " >= $hours AND " . round($diff_hours, 2) . " <= 24? " . (($diff_hours >= $hours && $diff_hours <= 24) ? "YES" : "NO") . "\n";
                    
                    if ($phone_request_enabled && ($diff_hours >= $hours && $diff_hours <= 24)) {
                        $has_newer_msg = !empty($c['info_requested_at']) && strtotime($c['last_message_at']) <= strtotime($c['info_requested_at']);
                        echo "      - Has newer message check: (info_requested_at empty OR last_message_at > info_requested_at)? " . ($has_newer_msg ? "NO (Skipped, already requested for this message)" : "YES") . "\n";
                        
                        if (!$has_newer_msg) {
                            $msg_to_send = '';
                            if (empty($c['phone']) && !empty($phone_request_text)) $msg_to_send = $phone_request_text;
                            elseif (empty($c['province']) && !empty($province_request_text)) $msg_to_send = $province_request_text;
                            elseif (empty($c['notes']) && !empty($product_request_text)) $msg_to_send = $product_request_text;
                            
                            echo "      - Message to send: '" . $msg_to_send . "'\n";
                            if (empty($msg_to_send)) {
                                echo "      - SKIP REASON: No template message text configured for the missing information field.\n";
                            } else {
                                $message_text = replace_message_tags_debug($msg_to_send, $c['name'] ?? 'bạn', $c['sales_phone']);
                                echo "      - READY TO SEND: \"$message_text\"\n";
                            }
                        }
                    }
                } else {
                    echo "    => Logic Path: TỰ ĐỘNG GỬI TIN CSKH / FOLLOW-UP\n";
                    echo "      - followup_request_enabled: " . ($followup_request_enabled ? "YES" : "NO") . "\n";
                    echo "      - Followup text empty?: " . (empty($followup_request_text) ? "YES (Skipped, text is empty!)" : "NO") . "\n";
                    echo "      - Hours wait check: is " . round($diff_hours, 2) . " >= $followup_hours? " . (($diff_hours >= $followup_hours) ? "YES" : "NO") . "\n";
                    
                    if ($followup_request_enabled && !empty($followup_request_text) && $diff_hours >= $followup_hours) {
                        $has_newer_msg = !empty($c['followup_requested_at']) && strtotime($c['last_message_at']) <= strtotime($c['followup_requested_at']);
                        echo "      - Has newer message check: (followup_requested_at empty OR last_message_at > followup_requested_at)? " . ($has_newer_msg ? "NO (Skipped, already followed up for this message)" : "YES") . "\n";
                        
                        if (!$has_newer_msg) {
                            $message_text = replace_message_tags_debug($followup_request_text, $c['name'] ?? 'bạn', $c['sales_phone']);
                            echo "      - READY TO SEND CSKH: \"$message_text\"\n";
                        }
                    }
                }
            }
        }
    }
    
    if ($active_zalo_oas === 0) {
        echo "No Zalo OAs have active waiting customers.\n";
    }
} catch (Exception $e) {
    echo "Zalo Diagnostics Error: " . $e->getMessage() . "\n";
}
echo "\n========================================================\n";
echo "  DIAGNOSTICS COMPLETE\n";
echo "========================================================\n";
?>
