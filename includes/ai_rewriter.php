<?php
// includes/ai_rewriter.php
require_once __DIR__ . '/db.php';

function clean_markdown($text) {
    if (empty($text)) return $text;

    // 1. Loại bỏ ký hiệu khung code block ``` nhưng giữ nguyên toàn bộ nội dung bên trong
    $text = preg_replace('/^```[a-zA-Z0-9_\-]*\s*/m', '', $text);
    $text = preg_replace('/```\s*$/m', '', $text);
    $text = str_replace('```', '', $text);

    // 2. Loại bỏ ký hiệu inline code `
    $text = str_replace('`', '', $text);

    // 3. Chuyển đổi toàn bộ link dạng markdown [Text/URL](URL) thành đường link URL thuần túy (cho Facebook, YouTube, Zalo...)
    // Ví dụ: [https://copphaviet.com](https://copphaviet.com) -> https://copphaviet.com
    // Ví dụ: [Báo giá](https://copphaviet.com/lien-he/) -> https://copphaviet.com/lien-he/
    $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/i', function($matches) {
        return trim($matches[2]);
    }, $text);

    // Xóa cú pháp link markdown tương đương nếu còn sót lại [text](url)
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$2', $text);

    // 4. Remove bold/italic markers (**text**, __text__)
    $text = preg_replace('/(\*\*|__)/', '', $text);
    // 5. Remove hashes for headers (# Header)
    $text = preg_replace('/^#+\s*/m', '', $text);

    $text = trim($text);
    return $text;
}

if (!function_exists('sanitize_youtube_tags')) {
    function sanitize_youtube_tags($input) {
        if (empty($input)) return [];

        if (is_array($input)) {
            $raw_tags = $input;
        } else {
            // Thay thế xuống dòng thành dấu phẩy
            $str = str_replace(["\r\n", "\r", "\n"], ',', (string)$input);
            $raw_tags = explode(',', $str);
        }

        $cleaned = [];
        $seen = [];
        $total_len = 0;

        foreach ($raw_tags as $tag) {
            // Loại bỏ ký tự cấm của YouTube (#, <, >, ", ', `, *, v.v.)
            $tag = preg_replace('/[#<>"\'`*]/u', '', $tag);
            // Loại bỏ dấu gạch đầu dòng dư thừa ở đầu tag
            $tag = preg_replace('/^[\s\-\─\•\*\.]+/u', '', $tag);
            $tag = trim($tag);

            if (empty($tag) || mb_strlen($tag, 'UTF-8') < 2) {
                continue;
            }

            // Cắt tag nếu vượt quá 90 ký tự (Giới hạn 1 tag của YouTube là 100 ký tự)
            if (mb_strlen($tag, 'UTF-8') > 90) {
                $tag = mb_substr($tag, 0, 90, 'UTF-8');
            }

            $tag_lower = mb_strtolower($tag, 'UTF-8');
            if (isset($seen[$tag_lower])) {
                continue; // Bỏ qua tag trùng lặp
            }
            $seen[$tag_lower] = true;

            // Giới hạn tổng độ dài tất cả các tag <= 400 ký tự (Giới hạn tổng của YouTube là 500 ký tự)
            $tag_len = mb_strlen($tag, 'UTF-8') + 1; // tính thêm dấu phẩy
            if ($total_len + $tag_len > 400) {
                break;
            }

            $cleaned[] = $tag;
            $total_len += $tag_len;
        }

        return $cleaned;
    }
}

function ai_log($msg) {
    file_put_contents(__DIR__ . '/../error_log', date('Y-m-d H:i:s') . " - AI DEBUG: " . $msg . "\n", FILE_APPEND);
}

function rewrite_content_with_gemini($prompt, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries = 2, $timeout_seconds = 120) {
    $base_url = rtrim($endpoint ?: "https://generativelanguage.googleapis.com/v1beta/models", '/');
    
    // Parse models list
    $models = array_map('trim', explode(',', $selected_model));
    $models = array_filter($models);
    if (empty($models)) {
        $models = [$selected_model];
    }
    
    $timeout = max(5, intval($timeout_seconds));
    $retries_limit = max(0, intval($max_retries));
    
    foreach ($models as $model) {
        foreach ($api_keys as $key) {
            for ($attempt = 0; $attempt <= $retries_limit; $attempt++) {
                try {
                    $url = $base_url . "/" . $model . ":generateContent?key=" . $key;
                    $combined_prompt = $prompt_vaitro ? str_replace("{prompt}", $prompt, $prompt_vaitro) : $prompt;
                    
                    $data = [
                        "contents" => [
                            ["parts" => [["text" => $combined_prompt]]]
                        ]
                    ];
                    
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    
                    if (curl_errno($ch)) {
                        $curl_err = curl_error($ch);
                        curl_close($ch);
                        throw new Exception("cURL error: " . $curl_err);
                    }
                    curl_close($ch);
                    
                    if ($http_code == 200) {
                        $response_data = json_decode($response, true);
                        $rewritten_text = $response_data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                        $rewritten_text = trim($rewritten_text);
                        
                        if (!empty($rewritten_text)) {
                            return $rewritten_text;
                        }
                    } else {
                        ai_log("Gemini API error with key $key, model $model (Attempt $attempt): HTTP $http_code - $response");
                    }
                } catch (Exception $e) {
                    ai_log("Gemini fetch exception with key $key, model $model (Attempt $attempt): " . $e->getMessage());
                }
                
                if ($attempt < $retries_limit) {
                    sleep(1);
                }
            }
        }
    }
    return '';
}

function rewrite_content_with_openai($prompt, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries = 2, $timeout_seconds = 120) {
    $url = $endpoint ?: "https://api.openai.com/v1/chat/completions";
    
    // Parse models list
    $models = array_map('trim', explode(',', $selected_model));
    $models = array_filter($models);
    if (empty($models)) {
        $models = [$selected_model];
    }
    
    $timeout = max(5, intval($timeout_seconds));
    $retries_limit = max(0, intval($max_retries));
    
    foreach ($models as $model) {
        foreach ($api_keys as $key) {
            for ($attempt = 0; $attempt <= $retries_limit; $attempt++) {
                try {
                    $headers = [
                        "Authorization: Bearer " . $key,
                        "Content-Type: application/json"
                    ];
                    
                    $messages = [];
                    if ($prompt_vaitro) {
                        $messages[] = ["role" => "system", "content" => str_replace("{prompt}", $prompt, $prompt_vaitro)];
                    }
                    $messages[] = ["role" => "user", "content" => $prompt];
                    
                    $data = [
                        "model" => $model,
                        "messages" => $messages
                    ];
                    
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    
                    if (curl_errno($ch)) {
                        $curl_err = curl_error($ch);
                        curl_close($ch);
                        throw new Exception("cURL error: " . $curl_err);
                    }
                    curl_close($ch);
                    
                    if ($http_code == 200) {
                        $response_data = json_decode($response, true);
                        $rewritten_text = $response_data['choices'][0]['message']['content'] ?? '';
                        $rewritten_text = trim($rewritten_text);
                        
                        if (!empty($rewritten_text)) {
                            return $rewritten_text;
                        }
                    } else {
                        ai_log("OpenAI API error with key $key, model $model (Attempt $attempt): HTTP $http_code - $response");
                        if ($http_code == 429) {
                            sleep(2);
                        }
                    }
                } catch (Exception $e) {
                    ai_log("OpenAI fetch exception with key $key, model $model (Attempt $attempt): " . $e->getMessage());
                }
                
                if ($attempt < $retries_limit) {
                    sleep(1);
                }
            }
        }
    }
    return '';
}

function rewrite_content_with_claude($prompt, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries = 2, $timeout_seconds = 120) {
    $url = $endpoint ?: "https://api.anthropic.com/v1/messages";
    
    // Parse models list
    $models = array_map('trim', explode(',', $selected_model));
    $models = array_filter($models);
    if (empty($models)) {
        $models = [$selected_model];
    }
    
    $timeout = max(5, intval($timeout_seconds));
    $retries_limit = max(0, intval($max_retries));
    
    foreach ($models as $model) {
        foreach ($api_keys as $key) {
            for ($attempt = 0; $attempt <= $retries_limit; $attempt++) {
                try {
                    $headers = [
                        "x-api-key: " . $key,
                        "anthropic-version: 2023-06-01",
                        "content-type: application/json"
                    ];
                    
                    $system = "";
                    if ($prompt_vaitro) {
                        $system = str_replace("{prompt}", $prompt, $prompt_vaitro);
                    }
                    
                    $data = [
                        "model" => $model,
                        "max_tokens" => 4000,
                        "messages" => [
                            ["role" => "user", "content" => $prompt]
                        ]
                    ];
                    if ($system !== "") {
                        $data["system"] = $system;
                    }
                    
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    
                    if (curl_errno($ch)) {
                        $curl_err = curl_error($ch);
                        curl_close($ch);
                        throw new Exception("cURL error: " . $curl_err);
                    }
                    curl_close($ch);
                    
                    if ($http_code == 200) {
                        $response_data = json_decode($response, true);
                        $rewritten_text = $response_data['content'][0]['text'] ?? '';
                        $rewritten_text = trim($rewritten_text);
                        
                        if (!empty($rewritten_text)) {
                            return $rewritten_text;
                        }
                    } else {
                        ai_log("Claude API error with key $key, model $model (Attempt $attempt): HTTP $http_code - $response");
                    }
                } catch (Exception $e) {
                    ai_log("Claude fetch exception with key $key, model $model (Attempt $attempt): " . $e->getMessage());
                }
                
                if ($attempt < $retries_limit) {
                    sleep(1);
                }
            }
        }
    }
    return '';
}

function log_ai_usage($account_id, $provider, $feature, $prompt_length, $response_length, $status, $error_message = null) {
    global $pdo;
    try {
        $username = null;
        if ($account_id) {
            $stmt = $pdo->prepare("SELECT username FROM system_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$account_id]);
            $username = $stmt->fetchColumn();
        }
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1 (Hệ thống)';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'System Cron / CLI';
        $log_stmt = $pdo->prepare("
            INSERT INTO ai_usage_logs (account_id, username, provider, feature, prompt_length, response_length, status, error_message, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $log_stmt->execute([
            $account_id,
            $username,
            $provider,
            $feature,
            $prompt_length,
            $response_length,
            $status,
            $error_message,
            $ip_address,
            $user_agent
        ]);
    } catch (Exception $e) {
        ai_log("Failed to write AI usage log: " . $e->getMessage());
    }
}


function rewrite_content_with_ai($content, $account_id, $is_title = false, $fanpage_name = '') {
    global $pdo;
    
    if (empty(trim($content))) return $content;

    $selected_ai = 'Unknown';
    try {
        $stmt = $pdo->prepare("SELECT provider, endpoint, api_keys, cookie, model, prompt_content, prompt_title, max_retries, timeout_seconds FROM ai_configs WHERE account_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$account_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            ai_log("AI Config is not active or not found for account $account_id");
            return $content; // fallback
        }
        
        $selected_ai = $config['provider'];
        $selected_model = $config['model'];
        $endpoint = $config['endpoint'];
        $api_keys_raw = decryptData($config['api_keys']);
        $cookie_raw = isset($config['cookie']) ? decryptData($config['cookie']) : '';
        $max_retries = isset($config['max_retries']) ? intval($config['max_retries']) : 2;
        $timeout_seconds = isset($config['timeout_seconds']) ? intval($config['timeout_seconds']) : 120;
        
        $prompt_vaitro = $is_title ? $config['prompt_title'] : $config['prompt_content'];
        
        if (!empty($fanpage_name)) {
            $prompt_vaitro = str_replace('{fanpage_name}', $fanpage_name, $prompt_vaitro);
        }
        
        if (empty($prompt_vaitro)) {
            ai_log("Missing prompt configuration (is_title=$is_title)");
            return $content;
        }
        
        // Parse API keys based on Python logic
        $api_keys = [];
        $api_keys_raw = trim($api_keys_raw);
        if (substr($api_keys_raw, 0, 1) === '[' || substr($api_keys_raw, 0, 1) === '(') {
            // Very simple bracket remover, assuming CSV inside
            $cleaned = trim($api_keys_raw, "()[]");
            $parts = explode(',', $cleaned);
            foreach ($parts as $p) {
                $p = trim($p, " '\"\n\r\t");
                if ($p) $api_keys[] = $p;
            }
        } else {
            $parts = explode(',', $api_keys_raw);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p) $api_keys[] = $p;
            }
        }
        
        if (empty($api_keys)) {
            ai_log("No API keys configured");
            return $content;
        }
        
        $rewritten_text = "";
        $status = "success";
        $error_message = null;

        if ($selected_ai === "Gemini") {
            $rewritten_text = rewrite_content_with_gemini($content, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries, $timeout_seconds);
        } elseif ($selected_ai === "OpenAI") {
            $rewritten_text = rewrite_content_with_openai($content, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries, $timeout_seconds);
        } elseif ($selected_ai === "Claude") {
            $rewritten_text = rewrite_content_with_claude($content, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries, $timeout_seconds);
        } else {
            $rewritten_text = $content;
        }
        
        if (empty($rewritten_text)) {
            $status = "failed";
            $error_message = "Empty response from AI Provider";
            log_ai_usage($account_id, $selected_ai, $is_title ? 'Tiêu đề bài viết' : 'Nội dung bài viết', strlen($content), 0, $status, $error_message);
            return $content;
        }
        
        log_ai_usage($account_id, $selected_ai, $is_title ? 'Tiêu đề bài viết' : 'Nội dung bài viết', strlen($content), strlen($rewritten_text), $status, $error_message);
        return clean_markdown($rewritten_text);
        
    } catch (Exception $e) {
        ai_log("Error in rewrite_content_with_ai: " . $e->getMessage());
        log_ai_usage($account_id, $selected_ai, $is_title ? 'Tiêu đề bài viết' : 'Nội dung bài viết', strlen($content), 0, "failed", $e->getMessage());
        return $content;
    }
}

function generate_chat_reply_with_ai($user_message, $system_prompt, $account_id, $fanpage_name = '', $history_text = '') {
    global $pdo;
    if (empty(trim($user_message))) return '';

    $selected_ai = 'Unknown';
    try {
        $stmt = $pdo->prepare("SELECT provider, endpoint, api_keys, cookie, model, max_retries, timeout_seconds FROM ai_configs WHERE account_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$account_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            ai_log("AI Config is not active or not found for account $account_id");
            return '';
        }
        
        $selected_ai = $config['provider'];
        $selected_model = $config['model'];
        $endpoint = $config['endpoint'];
        $api_keys_raw = decryptData($config['api_keys']);
        $cookie_raw = isset($config['cookie']) ? decryptData($config['cookie']) : '';
        $max_retries = isset($config['max_retries']) ? intval($config['max_retries']) : 2;
        $timeout_seconds = isset($config['timeout_seconds']) ? intval($config['timeout_seconds']) : 120;
        
        $prompt_vaitro = $system_prompt;
        if (!empty($fanpage_name)) {
            $prompt_vaitro = str_replace('{fanpage_name}', $fanpage_name, $prompt_vaitro);
        }
        
        if (strpos($prompt_vaitro, '{prompt}') === false) {
            $prompt_vaitro .= "\n\nTin nhắn của khách hàng:\n{prompt}";
        }
        
        if (!empty($history_text)) {
            if (strpos($prompt_vaitro, '{history}') !== false) {
                $prompt_vaitro = str_replace('{history}', $history_text, $prompt_vaitro);
            } else {
                $prompt_vaitro .= "\n\n--- LỊCH SỬ TRÒ CHUYỆN GẦN ĐÂY ĐỂ AI HIỂU NGỮ CẢNH ---\n" . $history_text . "--------------------------------------------------\nLưu ý: Chỉ dựa vào ngữ cảnh trên để trả lời câu hỏi hiện tại, không lặp lại lịch sử.";
            }
        }
        
        $api_keys = [];
        $api_keys_raw = trim($api_keys_raw);
        if (substr($api_keys_raw, 0, 1) === '[' || substr($api_keys_raw, 0, 1) === '(') {
            $cleaned = trim($api_keys_raw, "()[]");
            $parts = explode(',', $cleaned);
            foreach ($parts as $p) {
                $p = trim($p, " '\"\n\r\t");
                if ($p) $api_keys[] = $p;
            }
        } else {
            $parts = explode(',', $api_keys_raw);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p) $api_keys[] = $p;
            }
        }
        
        if (empty($api_keys)) {
            ai_log("No API keys configured");
            return '';
        }
        
        $rewritten_text = "";
        $status = "success";
        $error_message = null;

        if ($selected_ai === "Gemini") {
            $rewritten_text = rewrite_content_with_gemini($user_message, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries, $timeout_seconds);
        } elseif ($selected_ai === "OpenAI") {
            $rewritten_text = rewrite_content_with_openai($user_message, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries, $timeout_seconds);
        } elseif ($selected_ai === "Claude") {
            $rewritten_text = rewrite_content_with_claude($user_message, $api_keys, $endpoint, $prompt_vaitro, $selected_model, $max_retries, $timeout_seconds);
        }
        
        if (empty($rewritten_text)) {
            $status = "failed";
            $error_message = "Empty response from AI Provider";
            log_ai_usage($account_id, $selected_ai, 'Live Chat CSKH', strlen($user_message), 0, $status, $error_message);
            return '';
        }

        log_ai_usage($account_id, $selected_ai, 'Live Chat CSKH', strlen($user_message), strlen($rewritten_text), $status, $error_message);
        return clean_markdown($rewritten_text);
        
    } catch (Exception $e) {
        ai_log("Error in generate_chat_reply_with_ai: " . $e->getMessage());
        log_ai_usage($account_id, $selected_ai, 'Live Chat CSKH', strlen($user_message), 0, "failed", $e->getMessage());
        return '';
    }
}

function clean_json_response($text) {
    // Tìm đoạn text nằm giữa { và } hoặc [ và ]
    preg_match('/\{.*\}/s', $text, $matches);
    if (!empty($matches)) {
        return $matches[0];
    }
    return $text;
}

function rewrite_youtube_with_ai($content, $account_id, $channel_name = '') {
    global $pdo;
    
    if (empty(trim($content))) return null;

    $selected_ai = 'Unknown';
    try {
        $stmt = $pdo->prepare("SELECT provider, endpoint, api_keys, cookie, model, prompt_youtube_title, prompt_youtube_desc, prompt_youtube_tags, max_retries, timeout_seconds FROM ai_configs WHERE account_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$account_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            ai_log("AI Config not active for Youtube on account $account_id");
            return null;
        }
        
        $selected_ai = $config['provider'];
        $selected_model = $config['model'];
        $endpoint = $config['endpoint'];
        $api_keys_raw = decryptData($config['api_keys']);
        $cookie_raw = isset($config['cookie']) ? decryptData($config['cookie']) : '';
        $max_retries = isset($config['max_retries']) ? intval($config['max_retries']) : 2;
        $timeout_seconds = isset($config['timeout_seconds']) ? intval($config['timeout_seconds']) : 120;
        
        $api_keys = [];
        $api_keys_raw = trim($api_keys_raw);
        if (substr($api_keys_raw, 0, 1) === '[' || substr($api_keys_raw, 0, 1) === '(') {
            $cleaned = trim($api_keys_raw, "()[]");
            $parts = explode(',', $cleaned);
            foreach ($parts as $p) {
                $p = trim($p, " '\"\n\r\t");
                if ($p) $api_keys[] = $p;
            }
        } else {
            $parts = explode(',', $api_keys_raw);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p) $api_keys[] = $p;
            }
        }
        
        if (empty($api_keys)) return null;
        // --- {CHANNEL_NAME} REPLACEMENT ---
        if (!empty($config['prompt_youtube_title'])) $config['prompt_youtube_title'] = str_replace('{channel_name}', $channel_name, $config['prompt_youtube_title']);
        if (!empty($config['prompt_youtube_desc'])) $config['prompt_youtube_desc'] = str_replace('{channel_name}', $channel_name, $config['prompt_youtube_desc']);
        if (!empty($config['prompt_youtube_tags'])) $config['prompt_youtube_tags'] = str_replace('{channel_name}', $channel_name, $config['prompt_youtube_tags']);

        // --- {PROMPT} REPLACEMENT trong config của user ---
        if (!empty($config['prompt_youtube_title'])) $config['prompt_youtube_title'] = str_replace('{prompt}', $content, $config['prompt_youtube_title']);
        if (!empty($config['prompt_youtube_desc'])) $config['prompt_youtube_desc'] = str_replace('{prompt}', $content, $config['prompt_youtube_desc']);
        if (!empty($config['prompt_youtube_tags'])) $config['prompt_youtube_tags'] = str_replace('{prompt}', $content, $config['prompt_youtube_tags']);

        // --- BƯỚC 1: XÉT PROMPT CHO TITLE ---
        $title_prompt = !empty($config['prompt_youtube_title']) 
            ? "Bạn là một AI chuyên viết Tiêu đề Youtube chuẩn SEO.\nHãy viết tiêu đề dựa trên nội dung sau:\n{prompt}\n\nYÊU CẦU NGHIÊM NGẶT:\n" . $config['prompt_youtube_title'] . "\n\nCHỈ TRẢ VỀ DUY NHẤT ĐOẠN TEXT TIÊU ĐỀ, KHÔNG GIẢI THÍCH, KHÔNG BỔ SUNG."
            : "Viết 1 tiêu đề Youtube chuẩn SEO, ngắn gọn cho nội dung sau:\n{prompt}";
            
        // --- BƯỚC 2: XÉT PROMPT CHO TAGS ---
        $tags_prompt = !empty($config['prompt_youtube_tags']) 
            ? "Bạn là một AI chuyên phân tích từ khóa Youtube.\nHãy viết danh sách thẻ Tags dựa trên nội dung sau:\n{prompt}\n\nYÊU CẦU NGHIÊM NGẶT:\n" . $config['prompt_youtube_tags'] . "\n\nCHỈ TRẢ VỀ DUY NHẤT CHUỖI TEXT CHỨA CÁC TAGS TRÊN 1 DÒNG (CÁCH NHAU DẤU PHẨY), KHÔNG GIẢI THÍCH."
            : "Viết danh sách thẻ Tags cách nhau bằng dấu phẩy cho nội dung sau:\n{prompt}";

        // --- BƯỚC 3: XÉT PROMPT CHO DESCRIPTION ---
        $desc_prompt_base = !empty($config['prompt_youtube_desc']) 
            ? "Bạn là một AI chuyên viết Mô tả Youtube chuẩn SEO.\nHãy viết phần Mô tả dựa trên nội dung sau:\n{prompt}\n\nYÊU CẦU NGHIÊM NGẶT:\n" . $config['prompt_youtube_desc'] . "\n\nCHỈ TRẢ VỀ DUY NHẤT ĐOẠN TEXT MÔ TẢ, Tuyệt đối KHÔNG Bọc trong code markdown, không giải thích."
            : "Viết mô tả chi tiết cho video Youtube về nội dung sau:\n{prompt}";

        // HÀM CHẠY AI DÙNG CHUNG CHO 3 BƯỚC
        $run_ai_step = function($system_instruction, $content_input) use ($selected_ai, $api_keys, $endpoint, $selected_model, $max_retries, $timeout_seconds) {
            if ($selected_ai === "Gemini") {
                return rewrite_content_with_gemini($content_input, $api_keys, $endpoint, $system_instruction, $selected_model, $max_retries, $timeout_seconds);
            } elseif ($selected_ai === "OpenAI") {
                return rewrite_content_with_openai($content_input, $api_keys, $endpoint, $system_instruction, $selected_model, $max_retries, $timeout_seconds);
            } elseif ($selected_ai === "Claude") {
                return rewrite_content_with_claude($content_input, $api_keys, $endpoint, $system_instruction, $selected_model, $max_retries, $timeout_seconds);
            }
            return "";
        };

        // THỰC THI STEP 1 (TITLE)
        $ai_title = $run_ai_step($title_prompt, $content);
        $ai_title = trim(str_replace(['"', '`', '*'], '', $ai_title));
        
        usleep(500000); // Tránh nghẽn IP slot 429 trên Server AI Proxy

        // THỰC THI STEP 2 (TAGS)
        $ai_tags = $run_ai_step($tags_prompt, $content);
        $ai_tags = trim(str_replace(['"', '`', '*'], '', $ai_tags));

        usleep(500000); // Tránh nghẽn IP slot 429 trên Server AI Proxy

        // THỰC THI STEP 3 (MÔ TẢ - CÓ TRUYỀN {title})
        $final_desc_prompt = str_replace('{title}', $ai_title, $desc_prompt_base);
        $ai_desc = $run_ai_step($final_desc_prompt, $content);
        
        // Dọn dẹp Markdown & Chuẩn hóa link dạng Markdown thành URL thuần túy
        $ai_desc = clean_markdown($ai_desc);
        $ai_title = clean_markdown($ai_title);
        $ai_tags = implode(', ', sanitize_youtube_tags($ai_tags));

        // Thử lại Step 3 nếu mô tả bị rỗng do server AI bị nghẽn IP slot (429)
        if (empty($ai_desc) && !empty($ai_title)) {
            ai_log("Step 3 mô tả YouTube bị trống, thử lại sau 2 giây...");
            sleep(2);
            $ai_desc = $run_ai_step($final_desc_prompt, $content);
            $ai_desc = clean_markdown($ai_desc);
        }

        // Trả kết quả trực tiếp mà không cần giải mã JSON rủi ro
        if (empty($ai_title) && empty($ai_desc)) {
            ai_log("Failed to generate content in 3-step AI pipeline.");
            log_ai_usage($account_id, $selected_ai, 'YouTube SEO (3-step)', strlen($content), 0, "failed", "Failed to generate title and description");
            return null;
        }

        $resp_length = strlen($ai_title) + strlen($ai_desc) + strlen($ai_tags);
        log_ai_usage($account_id, $selected_ai, 'YouTube SEO (3-step)', strlen($content), $resp_length, "success");

        return [
            'title' => $ai_title,
            'description' => $ai_desc,
            'tags' => $ai_tags
        ];
        
    } catch (Exception $e) {
        ai_log("Error in rewrite_youtube_with_ai: " . $e->getMessage());
        log_ai_usage($account_id, $selected_ai, 'YouTube SEO (3-step)', strlen($content), 0, "failed", $e->getMessage());
        return null;
    }
}
?>
