<?php
$current_page = 'ai_settings';
require_once __DIR__ . '/includes/header.php';

$account_id = $_SESSION['account_id'];
$alert_type = '';
$alert_message = '';

// Check DB schema for new youtube prompt columns
try {
    $col_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='ai_configs' AND COLUMN_NAME='prompt_youtube_title'");
    if ($col_chk && $col_chk->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_title TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_desc TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_tags TEXT DEFAULT NULL");
    }
} catch (Exception $e) {}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_config'])) {
        $provider = $_POST['provider'];
        if (!in_array($provider, ['OpenAI', 'Gemini', 'Claude'])) {
            $provider = 'Gemini';
        }
        $endpoint = trim($_POST['endpoint']);
        $api_keys = trim($_POST['api_keys']);
        $model = trim($_POST['model']);
        $prompt_content = trim($_POST['prompt_content']);
        $prompt_title = trim($_POST['prompt_title']);
        $prompt_youtube_title = trim($_POST['prompt_youtube_title']);
        $prompt_youtube_desc = trim($_POST['prompt_youtube_desc']);
        $prompt_youtube_tags = trim($_POST['prompt_youtube_tags']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $max_retries = isset($_POST['max_retries']) ? intval($_POST['max_retries']) : 2;
        $timeout_seconds = isset($_POST['timeout_seconds']) ? intval($_POST['timeout_seconds']) : 120;
        
        // Upsert logic for current account and provider
        $check_stmt = $pdo->prepare("SELECT id FROM ai_configs WHERE account_id = ? AND provider = ?");
        $check_stmt->execute([$account_id, $provider]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $u_stmt = $pdo->prepare("UPDATE ai_configs SET endpoint=?, api_keys=?, model=?, prompt_content=?, prompt_title=?, prompt_youtube_title=?, prompt_youtube_desc=?, prompt_youtube_tags=?, max_retries=?, timeout_seconds=? WHERE id=?");
            $u_stmt->execute([$endpoint, encryptData($api_keys), $model, $prompt_content, $prompt_title, $prompt_youtube_title, $prompt_youtube_desc, $prompt_youtube_tags, $max_retries, $timeout_seconds, $existing['id']]);
        } else {
            $i_stmt = $pdo->prepare("INSERT INTO ai_configs (account_id, provider, endpoint, api_keys, model, prompt_content, prompt_title, prompt_youtube_title, prompt_youtube_desc, prompt_youtube_tags, max_retries, timeout_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $i_stmt->execute([$account_id, $provider, $endpoint, encryptData($api_keys), $model, $prompt_content, $prompt_title, $prompt_youtube_title, $prompt_youtube_desc, $prompt_youtube_tags, $max_retries, $timeout_seconds]);
        }
        
        // If this one is set as active, deactivate the other
        if ($is_active) {
            $pdo->prepare("UPDATE ai_configs SET is_active = 0 WHERE account_id = ? AND provider != ?")->execute([$account_id, $provider]);
            $pdo->prepare("UPDATE ai_configs SET is_active = 1 WHERE account_id = ? AND provider = ?")->execute([$account_id, $provider]);
        }
        
        $alert_type = 'success';
        $alert_message = 'Đã lưu cấu hình ' . $provider . ' thành công.';
    }
}

// Fetch Configurations
$stmt = $pdo->prepare("SELECT * FROM ai_configs WHERE account_id = ?");
$stmt->execute([$account_id]);
$configs_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

$gemini_conf = [
    'endpoint' => '', 'api_keys' => '', 'model' => 'gemini-1.5-pro',
    'prompt_content' => 'Bạn là một trợ lý viết content Facebook xuất sắc...\n\n{prompt}',
    'prompt_title' => 'Hãy sáng tạo một tiêu đề thu hút (title) ngắn gọn, bắt mắt dựa trên chủ đề sau:\n\n{prompt}',
    'prompt_youtube_title' => '- Dài từ 60-90 ký tự, chứa từ khóa chính ngay từ đầu.\n- Ngắn gọn, hấp dẫn, gây tò mò, không quá chung chung.',
    'prompt_youtube_desc' => 'Bố cục description bắt buộc gồm Mô tả, Hashtags và Keywords...\n{prompt}',
    'prompt_youtube_tags' => 'Tối thiểu 20 thẻ tags...',
    'is_active' => 0,
    'max_retries' => 2,
    'timeout_seconds' => 120
];
$openai_conf = [
    'endpoint' => '', 'api_keys' => '', 'model' => 'gpt-4o',
    'prompt_content' => 'Bạn là một chuyên gia marketing. Hãy viết lại nội dung sau đây sao cho hấp dẫn người đọc nhất:\n\n{prompt}',
    'prompt_title' => 'Tạo 1 tiêu đề thật ấn tượng cho chủ đề sau:\n\n{prompt}',
    'prompt_youtube_title' => '- Dài từ 60-90 ký tự, chứa từ khóa chính ngay từ đầu.\n- Ngắn gọn, hấp dẫn, gây tò mò, không quá chung chung.',
    'prompt_youtube_desc' => 'Bố cục description bắt buộc gồm Mô tả, Hashtags và Keywords...\n{prompt}',
    'prompt_youtube_tags' => 'Tối thiểu 20 thẻ tags...',
    'is_active' => 0,
    'max_retries' => 2,
    'timeout_seconds' => 120
];
$claude_conf = [
    'endpoint' => '', 'api_keys' => '', 'model' => 'claude-3-5-sonnet-20241022',
    'prompt_content' => 'Bạn là một trợ lý viết content Facebook xuất sắc...\n\n{prompt}',
    'prompt_title' => 'Hãy sáng tạo một tiêu đề thu hút (title) ngắn gọn, bắt mắt dựa trên chủ đề sau:\n\n{prompt}',
    'prompt_youtube_title' => '- Dài từ 60-90 ký tự, chứa từ khóa chính ngay từ đầu.\n- Ngắn gọn, hấp dẫn, gây tò mò, không quá chung chung.',
    'prompt_youtube_desc' => 'Bố cục description bắt buộc gồm Mô tả, Hashtags và Keywords...\n{prompt}',
    'prompt_youtube_tags' => 'Tối thiểu 20 thẻ tags...',
    'is_active' => 0,
    'max_retries' => 2,
    'timeout_seconds' => 120
];

foreach ($configs_db as &$c) {
    if (isset($c['api_keys'])) {
        $c['api_keys'] = decryptData($c['api_keys']);
    }
    if ($c['provider'] === 'Gemini') {
        $gemini_conf = array_merge($gemini_conf, $c);
    } elseif ($c['provider'] === 'Claude') {
        $claude_conf = array_merge($claude_conf, $c);
    } else {
        $openai_conf = array_merge($openai_conf, $c);
    }
}

// Determine active tab
$active_tab = 'gemini';
if ($openai_conf['is_active'] == 1) {
    $active_tab = 'openai';
} elseif ($claude_conf['is_active'] == 1) {
    $active_tab = 'claude';
}
?>

<div class="page-title">Cấu hình AI Rewriter</div>

<?php if ($alert_message): ?>
    <div class="alert alert-<?php echo $alert_type; ?>" style="margin-bottom: 20px;"><?php echo htmlspecialchars($alert_message); ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; gap: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px;">
        <button class="btn <?= $active_tab==='gemini' ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('gemini')" id="tab_gemini">✨ Cấu hình Gemini <?= $gemini_conf['is_active'] ? '(Đang chọn)' : '' ?></button>
        <button class="btn <?= $active_tab==='openai' ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('openai')" id="tab_openai">🤖 Cấu hình OpenAI <?= $openai_conf['is_active'] ? '(Đang chọn)' : '' ?></button>
        <button class="btn <?= $active_tab==='claude' ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('claude')" id="tab_claude">🔮 Cấu hình Claude <?= $claude_conf['is_active'] ? '(Đang chọn)' : '' ?></button>
    </div>
    
    <!-- Gemini Form -->
    <div id="form_gemini" style="display: <?= $active_tab === 'gemini' ? 'block' : 'none' ?>;">
        <form method="POST" action="ai_settings.php">
            <input type="hidden" name="provider" value="Gemini">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Đặt làm AI mặc định sử dụng viết nội dung</label>
                <div style="margin-top: 5px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" <?= $gemini_conf['is_active'] ? 'checked' : '' ?> style="width:18px;height:18px;">
                        Kích hoạt Gemini cho toàn bộ quá trình đăng bài
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>API Endpoint (Tùy chọn)</label>
                <input type="text" name="endpoint" value="<?= htmlspecialchars($gemini_conf['endpoint']) ?>" placeholder="Mặc định: https://generativelanguage.googleapis.com/v1beta/models" class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
            </div>
            
            <div class="form-group">
                <label>Danh sách API Keys (Phân cách bằng dấu phẩy)</label>
                <textarea name="api_keys" rows="3" class="form-control" placeholder="AIz..., AIz..., AIz..." required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;"><?= htmlspecialchars($gemini_conf['api_keys']) ?></textarea>
                <small style="color:var(--text-muted); display:block; margin-top:5px;">Hệ thống sẽ tự động xoay vòng hoặc đổi Key nếu một Key bị lỗi/hết hạn mức.</small>
            </div>
            
            <div class="form-group">
                <label>Model</label>
                <input type="text" name="model" value="<?= htmlspecialchars($gemini_conf['model']) ?>" placeholder="gemini-1.5-pro, gemini-1.5-flash..." class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                <small style="color:var(--text-muted); display:block; margin-top:5px;">Hỗ trợ nhập nhiều Model phân cách bằng dấu phẩy. Ưu tiên thử model đầu tiên, nếu lỗi sẽ tự động chuyển sang model tiếp theo.</small>
            </div>
            
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Số lần thử lại khi lỗi</label>
                    <input type="number" name="max_retries" value="<?= htmlspecialchars($gemini_conf['max_retries'] ?? 2) ?>" min="0" max="10" required class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                </div>
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Timeout kết nối (giây)</label>
                    <input type="number" name="timeout_seconds" value="<?= htmlspecialchars($gemini_conf['timeout_seconds'] ?? 120) ?>" min="5" max="600" required class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                </div>
            </div>
            
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt viết Nội Dung (<b>{prompt}</b> là Input gốc, hỗ trợ <b>{fanpage_name}</b>)</label>
                    <textarea name="prompt_content" rows="4" class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($gemini_conf['prompt_content']) ?></textarea>
                </div>
                
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt viết Tiêu Đề (Hỗ trợ <b>{fanpage_name}</b>)</label>
                    <textarea name="prompt_title" rows="4" class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($gemini_conf['prompt_title']) ?></textarea>
                </div>
            </div>

            <div style="margin-bottom: 8px; font-weight: 500; color: var(--text-main);">Cấu hình AI cho Lên lịch YouTube (Hệ thống tự động chạy 3 bước riêng biệt)</div>
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Title Youtube (Hỗ trợ <b>{prompt}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_title" rows="4" class="form-control" placeholder="- Dài từ 60-90 ký tự...\nNội dung: {prompt}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($gemini_conf['prompt_youtube_title'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Nội dung Youtube (Hỗ trợ <b>{prompt}</b>, <b>{title}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_desc" rows="4" class="form-control" placeholder="Viết mô tả cho nội dung: {prompt}\nTiêu đề đã đặt là: {title}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($gemini_conf['prompt_youtube_desc'] ?? '') ?></textarea>
                </div>

                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Tag Youtube (Hỗ trợ <b>{prompt}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_tags" rows="4" class="form-control" placeholder="- Tối thiểu 20 thẻ tags...\nNội dung: {prompt}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($gemini_conf['prompt_youtube_tags'] ?? '') ?></textarea>
                </div>
            </div>
            
            <button type="submit" name="save_config" class="btn btn-primary">Lưu Cấu Hình Gemini</button>
        </form>
    </div>
    
    <!-- OpenAI Form -->
    <div id="form_openai" style="display: <?= $active_tab === 'openai' ? 'block' : 'none' ?>;">
        <form method="POST" action="ai_settings.php">
            <input type="hidden" name="provider" value="OpenAI">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Đặt làm AI mặc định sử dụng viết nội dung</label>
                <div style="margin-top: 5px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" <?= $openai_conf['is_active'] ? 'checked' : '' ?> style="width:18px;height:18px;">
                        Kích hoạt OpenAI cho toàn bộ quá trình đăng bài
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>API Endpoint (Tùy chọn - Dùng chung với Yescale/Custom Proxy)</label>
                <input type="text" name="endpoint" value="<?= htmlspecialchars($openai_conf['endpoint']) ?>" placeholder="Mặc định: https://api.openai.com/v1/chat/completions" class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
            </div>
            
            <div class="form-group">
                <label>Danh sách API Keys (Bắt đầu bằng sk-..., phân cách bằng dấu phẩy)</label>
                <textarea name="api_keys" rows="3" class="form-control" placeholder="sk-123..., sk-456..." required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;"><?= htmlspecialchars($openai_conf['api_keys']) ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Model (Ví dụ: gpt-4o, gpt-3.5-turbo)</label>
                <input type="text" name="model" value="<?= htmlspecialchars($openai_conf['model']) ?>" class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                <small style="color:var(--text-muted); display:block; margin-top:5px;">Hỗ trợ nhập nhiều Model phân cách bằng dấu phẩy. Ưu tiên thử model đầu tiên, nếu lỗi sẽ tự động chuyển sang model tiếp theo.</small>
            </div>
            
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Số lần thử lại khi lỗi</label>
                    <input type="number" name="max_retries" value="<?= htmlspecialchars($openai_conf['max_retries'] ?? 2) ?>" min="0" max="10" required class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                </div>
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Timeout kết nối (giây)</label>
                    <input type="number" name="timeout_seconds" value="<?= htmlspecialchars($openai_conf['timeout_seconds'] ?? 120) ?>" min="5" max="600" required class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                </div>
            </div>
            
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt viết Nội Dung (<b>{prompt}</b> là Input gốc, hỗ trợ <b>{fanpage_name}</b>)</label>
                    <textarea name="prompt_content" rows="4" class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($openai_conf['prompt_content']) ?></textarea>
                </div>
                
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt viết Tiêu Đề (Hỗ trợ <b>{fanpage_name}</b>)</label>
                    <textarea name="prompt_title" rows="4" class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($openai_conf['prompt_title']) ?></textarea>
                </div>
            </div>

            <div style="margin-bottom: 8px; font-weight: 500; color: var(--text-main);">Cấu hình AI cho Lên lịch YouTube (Hệ thống tự động chạy 3 bước riêng biệt)</div>
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Title Youtube (Hỗ trợ <b>{prompt}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_title" rows="4" class="form-control" placeholder="- Dài từ 60-90 ký tự...\nNội dung: {prompt}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($openai_conf['prompt_youtube_title'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Nội dung Youtube (Hỗ trợ <b>{prompt}</b>, <b>{title}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_desc" rows="4" class="form-control" placeholder="Viết mô tả cho nội dung: {prompt}\nTiêu đề đã đặt là: {title}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($openai_conf['prompt_youtube_desc'] ?? '') ?></textarea>
                </div>

                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Tag Youtube (Hỗ trợ <b>{prompt}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_tags" rows="4" class="form-control" placeholder="- Tối thiểu 20 thẻ tags...\nNội dung: {prompt}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($openai_conf['prompt_youtube_tags'] ?? '') ?></textarea>
                </div>
            </div>
            
            <button type="submit" name="save_config" class="btn btn-primary" style="background:#10b981; border-color:#10b981;">Lưu Cấu Hình OpenAI</button>
        </form>
    </div>
    
    <!-- Claude Form -->
    <div id="form_claude" style="display: <?= $active_tab === 'claude' ? 'block' : 'none' ?>;">
        <form method="POST" action="ai_settings.php">
            <input type="hidden" name="provider" value="Claude">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Đặt làm AI mặc định sử dụng viết nội dung</label>
                <div style="margin-top: 5px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" <?= $claude_conf['is_active'] ? 'checked' : '' ?> style="width:18px;height:18px;">
                        Kích hoạt Claude cho toàn bộ quá trình đăng bài
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>API Endpoint (Tùy chọn)</label>
                <input type="text" name="endpoint" value="<?= htmlspecialchars($claude_conf['endpoint']) ?>" placeholder="Mặc định: https://api.anthropic.com/v1/messages" class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
            </div>
            
            <div class="form-group">
                <label>Danh sách API Keys (Phân cách bằng dấu phẩy)</label>
                <textarea name="api_keys" rows="3" class="form-control" placeholder="sk-ant-..., sk-ant-..." required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;"><?= htmlspecialchars($claude_conf['api_keys']) ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Model (Ví dụ: claude-3-5-sonnet-20241022, claude-3-opus-20240229)</label>
                <input type="text" name="model" value="<?= htmlspecialchars($claude_conf['model']) ?>" class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                <small style="color:var(--text-muted); display:block; margin-top:5px;">Hỗ trợ nhập nhiều Model phân cách bằng dấu phẩy. Ưu tiên thử model đầu tiên, nếu lỗi sẽ tự động chuyển sang model tiếp theo.</small>
            </div>
            
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Số lần thử lại khi lỗi</label>
                    <input type="number" name="max_retries" value="<?= htmlspecialchars($claude_conf['max_retries'] ?? 2) ?>" min="0" max="10" required class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                </div>
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Timeout kết nối (giây)</label>
                    <input type="number" name="timeout_seconds" value="<?= htmlspecialchars($claude_conf['timeout_seconds'] ?? 120) ?>" min="5" max="600" required class="form-control" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
                </div>
            </div>
            
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt viết Nội Dung (<b>{prompt}</b> là Input gốc, hỗ trợ <b>{fanpage_name}</b>)</label>
                    <textarea name="prompt_content" rows="4" class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($claude_conf['prompt_content']) ?></textarea>
                </div>
                
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt viết Tiêu Đề (Hỗ trợ <b>{fanpage_name}</b>)</label>
                    <textarea name="prompt_title" rows="4" class="form-control" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($claude_conf['prompt_title']) ?></textarea>
                </div>
            </div>

            <div style="margin-bottom: 8px; font-weight: 500; color: var(--text-main);">Cấu hình AI cho Lên lịch YouTube (Hệ thống tự động chạy 3 bước riêng biệt)</div>
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Title Youtube (Hỗ trợ <b>{prompt}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_title" rows="4" class="form-control" placeholder="- Dài từ 60-90 ký tự...\nNội dung: {prompt}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($claude_conf['prompt_youtube_title'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Nội dung Youtube (Hỗ trợ <b>{prompt}</b>, <b>{title}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_desc" rows="4" class="form-control" placeholder="Viết mô tả cho nội dung: {prompt}\nTiêu đề đã đặt là: {title}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($claude_conf['prompt_youtube_desc'] ?? '') ?></textarea>
                </div>

                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label>Prompt Tag Youtube (Hỗ trợ <b>{prompt}</b>, <b>{channel_name}</b>)</label>
                    <textarea name="prompt_youtube_tags" rows="4" class="form-control" placeholder="- Tối thiểu 20 thẻ tags...\nNội dung: {prompt}" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace;"><?= htmlspecialchars($claude_conf['prompt_youtube_tags'] ?? '') ?></textarea>
                </div>
            </div>
            
            <button type="submit" name="save_config" class="btn btn-primary" style="background:#d97706; border-color:#d97706;">Lưu Cấu Hình Claude</button>
        </form>
    </div>
</div>

<script>
function switchTab(tab) {
    document.getElementById('form_gemini').style.display = tab === 'gemini' ? 'block' : 'none';
    document.getElementById('form_openai').style.display = tab === 'openai' ? 'block' : 'none';
    document.getElementById('form_claude').style.display = tab === 'claude' ? 'block' : 'none';
    
    document.getElementById('tab_gemini').className = 'btn ' + (tab === 'gemini' ? 'btn-primary' : 'btn-secondary');
    document.getElementById('tab_openai').className = 'btn ' + (tab === 'openai' ? 'btn-primary' : 'btn-secondary');
    document.getElementById('tab_claude').className = 'btn ' + (tab === 'claude' ? 'btn-primary' : 'btn-secondary');
}
</script>

<?php include 'includes/footer.php'; ?>
