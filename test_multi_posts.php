<?php
// test_multi_posts.php
header('Content-Type: text/plain; charset=utf-8');

// Turn off output buffering immediately for real-time logs
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
set_time_limit(60);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fb_api.php';

echo "=== PROFILING get_fb_posts_multi (STREAMING LOGS) ===\n\n";

$account_id = 16; // From user output

try {
    // 1. Get all pages
    $stmt_pages = $pdo->prepare("
        (SELECT p.page_id, p.name, p.user_id, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = :aid)
        UNION
        (SELECT p.page_id, p.name, p.user_id, p.access_token FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id JOIN users u ON p.user_id = u.id WHERE ps.shared_with_account_id = :aid2)
    ");
    $stmt_pages->bindValue(':aid', $account_id, PDO::PARAM_INT);
    $stmt_pages->bindValue(':aid2', $account_id, PDO::PARAM_INT);
    $stmt_pages->execute();
    $all_pages_raw = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($all_pages_raw) . " pages in database.\n";
    
    $pages = [];
    foreach ($all_pages_raw as $p) {
        if (!empty($p['access_token'])) {
            $p['access_token'] = decryptData($p['access_token']);
            $pages[] = $p;
            echo " - Page: {$p['name']} (ID: {$p['page_id']})\n";
        }
    }
    
    if (empty($pages)) {
        exit("No pages with tokens configured.\n");
    }
    
    echo "\nStarting get_fb_posts_multi with limit = 15...\n";
    $start_time = microtime(true);
    
    // Inline implementation of get_fb_posts_multi with echo statements for profiling
    $all_posts = [];
    $next_cursors = [];
    $chunks = array_chunk($pages, 10);
    $chunk_idx = 0;

    foreach ($chunks as $chunk) {
        $chunk_idx++;
        echo "Processing chunk $chunk_idx (contains " . count($chunk) . " pages)...\n";
        $multi_curl = curl_multi_init();
        $handles = [];

        foreach ($chunk as $p) {
            $page_id = $p['page_id'];
            $token = $p['access_token'];
            
            $url = FB_API_BASE . "{$page_id}/feed?fields=id,message,created_time,full_picture,comments.summary(1).limit(1),reactions.summary(1).limit(1)&limit=15&access_token={$token}";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Reduce timeout to 15s to fail faster
            fb_curl_setssl($ch);
            curl_multi_add_handle($multi_curl, $ch);
            $handles[$page_id] = $ch;
        }

        echo " - Initiating multi cURL execute...\n";
        $active = null;
        $exec_start = microtime(true);
        
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multi_curl, 0.2) === -1) {
                usleep(5000);
            }
            do {
                $mrc = curl_multi_exec($multi_curl, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            
            // Add a timeout break inside the loop just in case
            if (microtime(true) - $exec_start > 25) {
                echo "   [WARNING] Chunk cURL execution took more than 25 seconds! Aborting loop.\n";
                break;
            }
        }
        
        echo " - Chunk cURL finished in " . round(microtime(true) - $exec_start, 3) . " seconds.\n";

        foreach ($handles as $page_id => $ch) {
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_multi_remove_handle($multi_curl, $ch);
            
            echo "   * Page $page_id: HTTP $http_code" . ($curl_err ? " (cURL Error: $curl_err)" : "") . "\n";
            
            if ($http_code === 200) {
                $data = json_decode($response, true);
                if (!empty($data['data'])) {
                    $current_page = null;
                    foreach ($chunk as $c) {
                        if ($c['page_id'] == $page_id) {
                            $current_page = $c;
                            break;
                        }
                    }
                    
                    if ($current_page) {
                        foreach ($data['data'] as $post) {
                            $post['_page_id'] = $current_page['page_id'];
                            $post['_user_id'] = $current_page['user_id'];
                            $post['_page_name'] = $current_page['name'] ?? 'Page';
                            $post['picture'] = $post['full_picture'] ?? null;
                            $cc = $post['comments']['summary']['total_count'] ?? 0;
                            $rc = $post['reactions']['summary']['total_count'] ?? 0;
                            $post['comment_count'] = $cc;
                            $post['reaction_count'] = $rc;
                            $post['has_comments'] = $cc > 0;
                            $all_posts[] = $post;
                        }
                        echo "     Loaded " . count($data['data']) . " posts.\n";
                    }
                }
            }
        }
        curl_multi_close($multi_curl);
    }
    
    echo "\nTotal execution time: " . round(microtime(true) - $start_time, 3) . " seconds.\n";
    echo "Total posts loaded: " . count($all_posts) . "\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
