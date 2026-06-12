<?php
// includes/ai_rewriter.php
require_once __DIR__ . '/db.php';

function clean_markdown($text) {
    // Basic markdown clean (if needed based on Python original)
    // Remove code blocks
    $text = preg_replace('/```[\s\S]*?```/', '', $text);
    // Remove inline code
    $text = preg_replace('/`[^`]*`/', '', $text);
    // Remove bold/italic markers
    $text = preg_replace('/(\*\*|__|\*|_)/', '', $text);
    // Remove hashes for headers
    $text = preg_replace('/^#+\s*/m', '', $text);
    $text = trim($text);
    return $text;
}

function ai_log($msg) {
    file_put_contents(__DIR__ . '/../error_log', date('Y-m-d H:i:s') . " - AI DEBUG: " . $msg . "\n", FILE_APPEND);
}

function rewrite_content_with_gemini($prompt, $api_keys, $endpoint, $prompt_vaitro, $selected_model) {
    $base_url = rtrim($endpoint ?: "https://generativelanguage.googleapis.com/v1beta/models", '/');
    
    foreach ($api_keys as $key) {
        try {
            $url = $base_url . "/" . $selected_model . ":generateContent?key=" . $key;
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200) {
                $response_data = json_decode($response, true);
                $rewritten_text = $response_data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $rewritten_text = trim($rewritten_text);
                
                if (!empty($rewritten_text)) {
                    return $rewritten_text;
                }
            } else {
                ai_log("Gemini API error with key $key: HTTP $http_code - $response");
            }
        } catch (Exception $e) {
            ai_log("Gemini fetch exception with key $key: " . $e->getMessage());
            continue;
        }
    }
    return '';
}

function rewrite_content_with_openai($prompt, $api_keys, $endpoint, $prompt_vaitro, $selected_model) {
    $url = $endpoint ?: "https://api.openai.com/v1/chat/completions";
    
    foreach ($api_keys as $key) {
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
                "model" => $selected_model,
                "messages" => $messages
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200) {
                $response_data = json_decode($response, true);
                $rewritten_text = $response_data['choices'][0]['message']['content'] ?? '';
                $rewritten_text = trim($rewritten_text);
                
                if (!empty($rewritten_text)) {
                    return $rewritten_text;
                }
            } else {
                ai_log("OpenAI API error with key $key: HTTP $http_code - $response");
            }
        } catch (Exception $e) {
            ai_log("OpenAI fetch exception with key $key: " . $e->getMessage());
            continue;
        }
    }
    return '';
}

function rewrite_content_with_ai($content, $account_id, $is_title = false, $fanpage_name = '') {
    global $pdo;
    
    if (empty(trim($content))) return $content;

    try {
        $stmt = $pdo->prepare("SELECT provider, endpoint, api_keys, model, prompt_content, prompt_title FROM ai_configs WHERE account_id = ? AND is_active = 1 LIMIT 1");
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
            if ($selected_ai === "GeminiFree") {
                $api_keys = ['free']; // dummy key so it proceeds
            } else {
                ai_log("No API keys configured");
                return $content;
            }
        }
        
        $rewritten_text = "";
        if ($selected_ai === "Gemini") {
            $rewritten_text = rewrite_content_with_gemini($content, $api_keys, $endpoint, $prompt_vaitro, $selected_model);
        } elseif ($selected_ai === "OpenAI") {
            $rewritten_text = rewrite_content_with_openai($content, $api_keys, $endpoint, $prompt_vaitro, $selected_model);
        } elseif ($selected_ai === "GeminiFree") {
            $rewritten_text = rewrite_content_with_openai($content, $api_keys, $endpoint, $prompt_vaitro, $selected_model);
        } else {
            $rewritten_text = $content;
        }
        
        if (empty($rewritten_text)) {
            return $content;
        }
        
        return clean_markdown($rewritten_text);
        
    } catch (Exception $e) {
        ai_log("Error in rewrite_content_with_ai: " . $e->getMessage());
        return $content;
    }
}

function generate_chat_reply_with_ai($user_message, $system_prompt, $account_id, $fanpage_name = '', $history_text = '') {
    global $pdo;
    if (empty(trim($user_message))) return '';

    try {
        $stmt = $pdo->prepare("SELECT provider, endpoint, api_keys, model FROM ai_configs WHERE account_id = ? AND is_active = 1 LIMIT 1");
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
            if ($selected_ai === "GeminiFree") {
                $api_keys = ['free'];
            } else {
                ai_log("No API keys configured");
                return '';
            }
        }
        
        $rewritten_text = "";
        if ($selected_ai === "Gemini") {
            $rewritten_text = rewrite_content_with_gemini($user_message, $api_keys, $endpoint, $prompt_vaitro, $selected_model);
        } elseif ($selected_ai === "OpenAI") {
            $rewritten_text = rewrite_content_with_openai($user_message, $api_keys, $endpoint, $prompt_vaitro, $selected_model);
        } elseif ($selected_ai === "GeminiFree") {
            $rewritten_text = rewrite_content_with_openai($user_message, $api_keys, $endpoint, $prompt_vaitro, $selected_model);
        }
        
        return clean_markdown($rewritten_text);
        
    } catch (Exception $e) {
        ai_log("Error in generate_chat_reply_with_ai: " . $e->getMessage());
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

    try {
        $stmt = $pdo->prepare("SELECT provider, endpoint, api_keys, model, prompt_youtube_title, prompt_youtube_desc, prompt_youtube_tags FROM ai_configs WHERE account_id = ? AND is_active = 1 LIMIT 1");
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
            if ($selected_ai === "GeminiFree") {
                $api_keys = ['free'];
            } else {
                return null;
            }
        }
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
        $run_ai_step = function($system_instruction, $content_input) use ($selected_ai, $api_keys, $endpoint, $selected_model) {
            if ($selected_ai === "Gemini") {
                return rewrite_content_with_gemini($content_input, $api_keys, $endpoint, $system_instruction, $selected_model);
            } elseif ($selected_ai === "OpenAI") {
                return rewrite_content_with_openai($content_input, $api_keys, $endpoint, $system_instruction, $selected_model);
            } elseif ($selected_ai === "GeminiFree") {
                return rewrite_content_with_openai($content_input, $api_keys, $endpoint, $system_instruction, $selected_model);
            }
            return "";
        };

        // THỰC THI STEP 1 (TITLE)
        $ai_title = $run_ai_step($title_prompt, $content);
        $ai_title = trim(str_replace(['"', '`', '*'], '', $ai_title));
        
        // THỰC THI STEP 2 (TAGS)
        $ai_tags = $run_ai_step($tags_prompt, $content);
        $ai_tags = trim(str_replace(['"', '`', '*'], '', $ai_tags));

        // THỰC THI STEP 3 (MÔ TẢ - CÓ TRUYỀN {title})
        $final_desc_prompt = str_replace('{title}', $ai_title, $desc_prompt_base);
        $ai_desc = $run_ai_step($final_desc_prompt, $content);
        
        // Xóa markdown thừa (nếu AI cố tình trả về)
        $ai_desc = preg_replace('/^```(\w+)?\s*/m', '', $ai_desc); 
        $ai_desc = preg_replace('/```$/m', '', $ai_desc);
        $ai_desc = trim($ai_desc);

        // Trả kết quả trực tiếp mà không cần giải mã JSON rủi ro
        if (empty($ai_title) && empty($ai_desc)) {
            ai_log("Failed to generate content in 3-step AI pipeline.");
            return null;
        }

        return [
            'title' => $ai_title,
            'description' => $ai_desc,
            'tags' => $ai_tags
        ];
        
    } catch (Exception $e) {
        ai_log("Error in rewrite_youtube_with_ai: " . $e->getMessage());
        return null;
    }
}
?>
