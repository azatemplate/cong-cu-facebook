<?php
$current_page = 'ai_settings';
require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];
$alert_type = '';
$alert_message = '';

if (isset($_SESSION['alert_message'])) {
    $alert_type = $_SESSION['alert_type'];
    $alert_message = $_SESSION['alert_message'];
    unset($_SESSION['alert_type']);
    unset($_SESSION['alert_message']);
}

// Check DB schema for new columns (run once)
$ai_cols_flag = sys_get_temp_dir() . '/ai_settings_cols_v2.done';
if (!file_exists($ai_cols_flag)) {
    try {
        $col_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='ai_configs' AND COLUMN_NAME='prompt_youtube_title'");
        if ($col_chk && $col_chk->fetchColumn() == 0) {
            $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_title TEXT DEFAULT NULL");
            $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_desc TEXT DEFAULT NULL");
            $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_tags TEXT DEFAULT NULL");
        }

        $col_f_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='ai_configs' AND COLUMN_NAME='formula'");
        if ($col_f_chk && $col_f_chk->fetchColumn() == 0) {
            $pdo->exec("ALTER TABLE ai_configs ADD COLUMN formula VARCHAR(50) DEFAULT 'aida'");
            $pdo->exec("ALTER TABLE ai_configs ADD COLUMN style VARCHAR(50) DEFAULT 'ban_hang'");
        }
        @touch($ai_cols_flag);
    } catch (Exception $e) {}
}

// Handle Form Submission (PRG Pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = 'gemini';

    if (isset($_POST['save_config'])) {
        $provider = $_POST['provider'];
        if (!in_array($provider, ['OpenAI', 'Gemini', 'Claude'])) {
            $provider = 'Gemini';
        }
        $endpoint = trim($_POST['endpoint']);
        $api_keys = trim($_POST['api_keys']);
        $cookie = isset($_POST['cookie']) ? trim($_POST['cookie']) : '';
        $model = trim($_POST['model']);
        $prompt_content = trim($_POST['prompt_content']);
        $prompt_title = trim($_POST['prompt_title']);
        $prompt_youtube_title = trim($_POST['prompt_youtube_title']);
        $prompt_youtube_desc = trim($_POST['prompt_youtube_desc']);
        $prompt_youtube_tags = trim($_POST['prompt_youtube_tags']);
        $formula = isset($_POST['formula']) && !empty($_POST['formula']) ? trim($_POST['formula']) : 'aida';
        $style = isset($_POST['style']) && !empty($_POST['style']) ? trim($_POST['style']) : 'ban_hang';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $max_retries = isset($_POST['max_retries']) ? intval($_POST['max_retries']) : 2;
        $timeout_seconds = isset($_POST['timeout_seconds']) ? intval($_POST['timeout_seconds']) : 120;
        
        // Upsert logic for current account and provider
        $check_stmt = $pdo->prepare("SELECT id FROM ai_configs WHERE account_id = ? AND provider = ?");
        $check_stmt->execute([$account_id, $provider]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $u_stmt = $pdo->prepare("UPDATE ai_configs SET endpoint=?, api_keys=?, cookie=?, model=?, prompt_content=?, prompt_title=?, prompt_youtube_title=?, prompt_youtube_desc=?, prompt_youtube_tags=?, formula=?, style=?, max_retries=?, timeout_seconds=? WHERE id=?");
            $u_stmt->execute([$endpoint, encryptData($api_keys), encryptData($cookie), $model, $prompt_content, $prompt_title, $prompt_youtube_title, $prompt_youtube_desc, $prompt_youtube_tags, $formula, $style, $max_retries, $timeout_seconds, $existing['id']]);
        } else {
            $i_stmt = $pdo->prepare("INSERT INTO ai_configs (account_id, provider, endpoint, api_keys, cookie, model, prompt_content, prompt_title, prompt_youtube_title, prompt_youtube_desc, prompt_youtube_tags, formula, style, max_retries, timeout_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $i_stmt->execute([$account_id, $provider, $endpoint, encryptData($api_keys), encryptData($cookie), $model, $prompt_content, $prompt_title, $prompt_youtube_title, $prompt_youtube_desc, $prompt_youtube_tags, $formula, $style, $max_retries, $timeout_seconds]);
        }
        
        // If this one is set as active, deactivate the other
        if ($is_active) {
            $pdo->prepare("UPDATE ai_configs SET is_active = 0 WHERE account_id = ? AND provider != ?")->execute([$account_id, $provider]);
            $pdo->prepare("UPDATE ai_configs SET is_active = 1 WHERE account_id = ? AND provider = ?")->execute([$account_id, $provider]);
        }
        
        if ($provider === 'OpenAI') $active_tab = 'openai';
        elseif ($provider === 'Claude') $active_tab = 'claude';
        else $active_tab = 'gemini';

        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Đã lưu cấu hình ' . $provider . ' thành công.';
    }

    header("Location: ai_settings.php?tab=" . $active_tab);
    exit;
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
    'formula' => 'aida',
    'style' => 'ban_hang',
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
    'formula' => 'aida',
    'style' => 'ban_hang',
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
    'formula' => 'aida',
    'style' => 'ban_hang',
    'is_active' => 0,
    'max_retries' => 2,
    'timeout_seconds' => 120
];
foreach ($configs_db as &$c) {
    if (isset($c['api_keys'])) {
        $c['api_keys'] = decryptData($c['api_keys']);
    }
    if (isset($c['cookie'])) {
        $c['cookie'] = decryptData($c['cookie']);
    }
    if ($c['provider'] === 'Gemini') {
        $gemini_conf = array_merge($gemini_conf, $c);
    } elseif ($c['provider'] === 'Claude') {
        $claude_conf = array_merge($claude_conf, $c);
    } elseif ($c['provider'] === 'OpenAI') {
        $openai_conf = array_merge($openai_conf, $c);
    }
}

// Determine active tab
$active_tab = $_GET['tab'] ?? '';
if (!in_array($active_tab, ['gemini', 'openai', 'claude'])) {
    $active_tab = '';
}

if (empty($active_tab)) {
    $active_tab = 'gemini';
    if ($openai_conf['is_active'] == 1) {
        $active_tab = 'openai';
    } elseif ($claude_conf['is_active'] == 1) {
        $active_tab = 'claude';
    }
}

$ai_formulas = [
    ['code' => 'aida', 'name' => '1. AIDA (Attention - Interest - Desire - Action)'],
    ['code' => 'pas', 'name' => '2. PAS (Problem - Agitate - Solve)'],
    ['code' => 'bab', 'name' => '3. BAB (Before - After - Bridge)'],
    ['code' => '4p', 'name' => '4. 4P (Picture - Promise - Prove - Push)'],
    ['code' => '4c', 'name' => '5. 4C (Clear - Concise - Compelling - Credible)'],
    ['code' => '4u', 'name' => '6. 4U (Useful - Urgent - Unique - Ultra-specific)'],
    ['code' => 'quest', 'name' => '7. QUEST (Qualify - Understand - Educate - Stimulate - Transition)'],
    ['code' => 'fab', 'name' => '8. FAB (Features - Advantages - Benefits)'],
    ['code' => 'acca', 'name' => '9. ACCA (Awareness - Comprehension - Conviction - Action)'],
    ['code' => 'pastor', 'name' => '10. PASTOR (Problem - Amplify - Story - Testimony - Offer - Response)'],
    ['code' => 'slap', 'name' => '11. SLAP (Stop - Look - Act - Purchase)'],
    ['code' => 'sss', 'name' => '12. SSS (Star - Story - Solution)'],
    ['code' => 'app', 'name' => '13. APP (Agree - Promise - Preview)'],
    ['code' => 'pppp', 'name' => '14. PPPP (Picture - Promise - Prove - Push)'],
    ['code' => 'hero', 'name' => '15. HERO (Hook - Empathy - Remedy - Outcome)'],
    ['code' => 'epic', 'name' => '16. EPIC (Engage - Purpose - Inspire - Convert)'],
    ['code' => '5w1h', 'name' => '17. 5W1H (Who - What - Where - When - Why - How)'],
    ['code' => '5a', 'name' => '18. 5A (Awareness - Appeal - Ask - Act - Advocate)'],
    ['code' => 'storytelling', 'name' => '19. Storytelling (Dẫn dắt bằng câu chuyện chân thực)']
];

$ai_styles = [
    ['code' => 'ban_hang', 'name' => '1. Bán hàng / Hard Sale (Khuyến mãi, chốt đơn ngay)'],
    ['code' => 'chia_se', 'name' => '2. Chia sẻ kiến thức / Educational (Mẹo hay, giá trị)'],
    ['code' => 'ke_chuyen', 'name' => '3. Kể chuyện / Storytelling (Tâm sự trải nghiệm, đồng cảm)'],
    ['code' => 'giat_gan', 'name' => '4. Giật gân / Bắt mắt (Tiêu đề tò mò, kịch tính)'],
    ['code' => 'hai_huoc', 'name' => '5. Hài hước / Trendy (Dí dỏm, meme, bắt trend)'],
    ['code' => 'chuyen_gia', 'name' => '6. Chuyên gia / Uy tín (Phân tích chuẩn mực, số liệu)'],
    ['code' => 'tam_su', 'name' => '7. Tâm sự / Đồng cảm (Nhẹ nhàng, chia sẻ khó khăn)'],
    ['code' => 'so_sanh', 'name' => '8. So sánh / Đánh giá (Phân tích ưu nhược điểm)'],
    ['code' => 'toi_gian', 'name' => '9. Tối giản / Súc tích (Gạch đầu dòng, súc tích)']
];

function renderPromptHelper($provider, $conf) {
    global $ai_formulas, $ai_styles;
    $current_formula = $conf['formula'] ?? 'aida';
    $current_style = $conf['style'] ?? 'ban_hang';
    ?>
    <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 14px 16px; margin-bottom: 15px;">
        <div style="font-weight: 700; font-size: 13.5px; color: var(--primary-color); margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
            💡 <span>Chọn Công Thức Viết Bài & Phong Cách Nội Dung (Tự động áp dụng ngầm khi gửi prompt AI)</span>
        </div>

        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <!-- Menu 1: 19 Công thức -->
            <div style="flex: 1; min-width: 220px;">
                <label style="font-size: 12px; font-weight: 600; color: var(--text-main); margin-bottom: 5px; display: block;">
                    🎯 Chọn Công Thức Viết Bài (19 Công Thức)
                </label>
                <select name="formula" class="form-control" style="width:100%; padding: 9px 10px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 13px; background: var(--card-bg); color: var(--text-main);">
                    <?php foreach ($ai_formulas as $f): ?>
                        <option value="<?= htmlspecialchars($f['code']) ?>" <?= $current_formula === $f['code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Menu 2: 9 Phong cách -->
            <div style="flex: 1; min-width: 220px;">
                <label style="font-size: 12px; font-weight: 600; color: var(--text-main); margin-bottom: 5px; display: block;">
                    🎭 Chọn Phong Cách Nội Dung (9 Phong Cách)
                </label>
                <select name="style" class="form-control" style="width:100%; padding: 9px 10px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 13px; background: var(--card-bg); color: var(--text-main);">
                    <?php foreach ($ai_styles as $s): ?>
                        <option value="<?= htmlspecialchars($s['code']) ?>" <?= $current_style === $s['code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <small style="color: var(--text-muted); display: block; margin-top: 8px; font-size: 12px;">
            ℹ️ Hệ thống sẽ tự động ghép quy tắc công thức & phong cách được chọn khi gửi Prompt cho AI mà không cần thao tác chèn thủ công.
        </small>
    </div>
    <?php
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-title">Cấu hình AI Rewriter</div>

<?php if ($alert_message): ?>
    <div class="alert alert-<?php echo $alert_type; ?>" style="margin-bottom: 20px;"><?php echo htmlspecialchars($alert_message); ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; gap: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px; flex-wrap: wrap;">
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
            
            <?php renderPromptHelper('gemini', $gemini_conf); ?>
            
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
            
            <?php renderPromptHelper('openai', $openai_conf); ?>
            
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
            
            <?php renderPromptHelper('claude', $claude_conf); ?>
            
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
    
    // Update the browser URL dynamically without page reload
    history.replaceState(null, '', 'ai_settings.php?tab=' + tab);
}
</script>

<?php include 'includes/footer.php'; ?>
