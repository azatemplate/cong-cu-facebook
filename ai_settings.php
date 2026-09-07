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

// Check DB schema for new youtube prompt columns
try {
    $col_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='ai_configs' AND COLUMN_NAME='prompt_youtube_title'");
    if ($col_chk && $col_chk->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_title TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_desc TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE ai_configs ADD COLUMN prompt_youtube_tags TEXT DEFAULT NULL");
    }
} catch (Exception $e) {}

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
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $max_retries = isset($_POST['max_retries']) ? intval($_POST['max_retries']) : 2;
        $timeout_seconds = isset($_POST['timeout_seconds']) ? intval($_POST['timeout_seconds']) : 120;
        
        // Upsert logic for current account and provider
        $check_stmt = $pdo->prepare("SELECT id FROM ai_configs WHERE account_id = ? AND provider = ?");
        $check_stmt->execute([$account_id, $provider]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $u_stmt = $pdo->prepare("UPDATE ai_configs SET endpoint=?, api_keys=?, cookie=?, model=?, prompt_content=?, prompt_title=?, prompt_youtube_title=?, prompt_youtube_desc=?, prompt_youtube_tags=?, max_retries=?, timeout_seconds=? WHERE id=?");
            $u_stmt->execute([$endpoint, encryptData($api_keys), encryptData($cookie), $model, $prompt_content, $prompt_title, $prompt_youtube_title, $prompt_youtube_desc, $prompt_youtube_tags, $max_retries, $timeout_seconds, $existing['id']]);
        } else {
            $i_stmt = $pdo->prepare("INSERT INTO ai_configs (account_id, provider, endpoint, api_keys, cookie, model, prompt_content, prompt_title, prompt_youtube_title, prompt_youtube_desc, prompt_youtube_tags, max_retries, timeout_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $i_stmt->execute([$account_id, $provider, $endpoint, encryptData($api_keys), encryptData($cookie), $model, $prompt_content, $prompt_title, $prompt_youtube_title, $prompt_youtube_desc, $prompt_youtube_tags, $max_retries, $timeout_seconds]);
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
    ['code' => 'storytelling', 'name' => '19. Storytelling (Dẫn dắt bằng câu chuyện chân thực)'],
    ['code' => 'spin', 'name' => '20. Spin Content (Đa phiên bản chống trùng lặp)']
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

function renderPromptHelper($provider) {
    global $ai_formulas, $ai_styles;
    ?>
    <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 14px 16px; margin-bottom: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
            <div style="font-weight: 700; font-size: 13.5px; color: var(--primary-color); display: flex; align-items: center; gap: 6px;">
                💡 <span>Bộ Hỗ Trợ Nâng Cao Prompt (20 Công Thức Viết Bài & 9 Phong Cách)</span>
            </div>
            <small style="color: var(--text-muted); font-size: 12px;">Chọn từ 2 menu bên dưới rồi nhấn <b>Chèn vào Prompt</b></small>
        </div>

        <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
            <!-- Menu 1: 20 Công thức -->
            <div style="flex: 1; min-width: 220px;">
                <label style="font-size: 12px; font-weight: 600; color: var(--text-main); margin-bottom: 4px; display: block;">
                    🎯 Chọn Công Thức Viết Bài (20 Công Thức)
                </label>
                <select id="select_formula_<?= $provider ?>" onchange="previewPromptHelper('<?= $provider ?>')" class="form-control" style="width:100%; padding: 8px 10px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 12.5px; background: var(--card-bg); color: var(--text-main);">
                    <option value="">-- Chọn 1 trong 20 Công thức Viết Bài --</option>
                    <?php foreach ($ai_formulas as $f): ?>
                        <option value="<?= htmlspecialchars($f['code']) ?>"><?= htmlspecialchars($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Menu 2: 9 Phong cách -->
            <div style="flex: 1; min-width: 220px;">
                <label style="font-size: 12px; font-weight: 600; color: var(--text-main); margin-bottom: 4px; display: block;">
                    🎭 Chọn Phong Cách Nội Dung (9 Phong Cách)
                </label>
                <select id="select_style_<?= $provider ?>" onchange="previewPromptHelper('<?= $provider ?>')" class="form-control" style="width:100%; padding: 8px 10px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 12.5px; background: var(--card-bg); color: var(--text-main);">
                    <option value="">-- Chọn 1 trong 9 Phong cách Nội dung --</option>
                    <?php foreach ($ai_styles as $s): ?>
                        <option value="<?= htmlspecialchars($s['code']) ?>"><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Nút chèn -->
            <div style="display: flex; gap: 6px;">
                <button type="button" onclick="insertPromptHelper('<?= $provider ?>', 'content')" class="btn btn-primary" style="padding: 8px 12px; font-size: 12px; font-weight: 600; white-space: nowrap;">
                    ⚡ Chèn vào Prompt Nội Dung
                </button>
                <button type="button" onclick="insertPromptHelper('<?= $provider ?>', 'title')" class="btn btn-secondary" style="padding: 8px 12px; font-size: 12px; font-weight: 600; white-space: nowrap;">
                    🏷️ Chèn vào Tiêu Đề
                </button>
            </div>
        </div>

        <!-- Preview Box -->
        <div id="helper_preview_box_<?= $provider ?>" style="display: none; margin-top: 10px; padding: 10px 12px; background: rgba(59, 130, 246, 0.05); border: 1px dashed #93c5fd; border-radius: 6px; font-size: 12px; color: var(--text-main);">
            <div style="font-weight: 600; margin-bottom: 4px; color: var(--primary-color);">📌 Nội dung xem trước sẽ chèn vào Prompt:</div>
            <div id="helper_preview_text_<?= $provider ?>" style="white-space: pre-wrap; font-family: monospace; font-size: 11.5px; color: var(--text-main);"></div>
        </div>
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
            
            <?php renderPromptHelper('gemini'); ?>
            
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
            
            <?php renderPromptHelper('openai'); ?>
            
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
            
            <?php renderPromptHelper('claude'); ?>
            
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

const AI_FORMULAS_MAP = {
    'aida': { name: 'AIDA', prompt: "Áp dụng Công thức AIDA:\n- Attention: Giật tiêu đề gây tò mò, giật gân.\n- Interest: Nêu thông tin thú vị hoặc nỗi đau nhức nhối.\n- Desire: Đưa ra lợi ích vượt trội của giải pháp.\n- Action: Kêu gọi hành động (CTA) chốt đơn." },
    'pas': { name: 'PAS', prompt: "Áp dụng Công thức PAS:\n- Problem: Nêu rõ vấn đề/nỗi đau khách hàng gặp phải.\n- Agitate: Xoáy sâu vào tác hại và cảm giác khó chịu.\n- Solve: Đưa ra giải pháp tối ưu xử lý triệt để." },
    'bab': { name: 'BAB', prompt: "Áp dụng Công thức BAB:\n- Before: Thực trạng khó khăn hiện tại.\n- After: Bức tranh kết quả hoàn hảo sau khi giải quyết.\n- Bridge: Giới thiệu sản phẩm/dịch vụ là cây cầu nối." },
    '4p': { name: '4P', prompt: "Áp dụng Công thức 4P:\n- Picture: Vẽ ra bức tranh trải nghiệm tuyệt vời.\n- Promise: Cam kết lợi ích thực tế.\n- Prove: Đưa ra bằng chứng, số liệu uy tín.\n- Push: Thúc đẩy chốt đơn bằng ưu đãi." },
    '4c': { name: '4C', prompt: "Áp dụng Công thức 4C: Viết bài Clear (Rõ ràng) - Concise (Súc tích) - Compelling (Thuyết phục) - Credible (Đáng tin cậy)." },
    '4u': { name: '4U', prompt: "Áp dụng Công thức 4U: Nội dung Useful (Hữu ích) - Urgent (Cấp bách) - Unique (Độc đáo) - Ultra-specific (Cụ thể chi tiết)." },
    'quest': { name: 'QUEST', prompt: "Áp dụng Công thức QUEST:\n- Qualify: Phân loại đối tượng.\n- Understand: Thấu hiểu nỗi niềm.\n- Educate: Giáo dục giá trị mới.\n- Stimulate: Kích thích khao khát.\n- Transition: Chuyển đổi mua hàng." },
    'fab': { name: 'FAB', prompt: "Áp dụng Công thức FAB:\n- Features: Tính năng nổi bật.\n- Advantages: Ưu điểm vượt trội.\n- Benefits: Lợi ích thực tế người dùng nhận được." },
    'acca': { name: 'ACCA', prompt: "Áp dụng Công thức ACCA: Awareness (Nhận thức) -> Comprehension (Thấu hiểu) -> Conviction (Tin tưởng) -> Action (Hành động)." },
    'pastor': { name: 'PASTOR', prompt: "Áp dụng Công thức PASTOR: Problem -> Amplify -> Story -> Testimony -> Offer -> Response." },
    'slap': { name: 'SLAP', prompt: "Áp dụng Công thức SLAP: Stop (Dừng lướt) -> Look (Quan sát) -> Act (Hành động) -> Purchase (Mua hàng)." },
    'sss': { name: 'SSS', prompt: "Áp dụng Công thức SSS: Star (Nhân vật truyền cảm hứng) -> Story (Câu chuyện thử thách) -> Solution (Giải pháp đột phá)." },
    'app': { name: 'APP', prompt: "Áp dụng Công thức APP: Agree (Tạo sự đồng ý) -> Promise (Hứa hẹn giá trị) -> Preview (Xem trước kết quả)." },
    'pppp': { name: 'PPPP', prompt: "Áp dụng Công thức PPPP: Picture (Bức tranh tương lai) -> Promise (Lời hứa) -> Prove (Chứng minh) -> Push (Chốt đơn)." },
    'hero': { name: 'HERO', prompt: "Áp dụng Công thức HERO: Hook (Giật tiêu đề) -> Empathy (Thấu hiểu đồng cảm) -> Remedy (Giải pháp) -> Outcome (Kết quả mỹ mãn)." },
    'epic': { name: 'EPIC', prompt: "Áp dụng Công thức EPIC: Engage (Lôi cuốn) -> Purpose (Mục tiêu) -> Inspire (Truyền cảm hứng) -> Convert (Chuyển đổi)." },
    '5w1h': { name: '5W1H', prompt: "Áp dụng Công thức 5W1H: Làm rõ Who (Ai) - What (Cái gì) - Where (Ở đâu) - When (Khi nào) - Why (Tại sao) - How (Làm như thế nào)." },
    '5a': { name: '5A', prompt: "Áp dụng Công thức 5A: Awareness (Nhận biết) -> Appeal (Thu hút) -> Ask (Tìm hiểu) -> Act (Hành động) -> Advocate (Lan tỏa)." },
    'storytelling': { name: 'Storytelling', prompt: "Áp dụng Công thức Storytelling: Dẫn dắt bằng câu chuyện chân thực, có bối cảnh, thử thách, bài học và thông điệp thương hiệu." },
    'spin': { name: 'Spin Content', prompt: "Áp dụng Spin Content: Viết bài thành nhiều biến thể phân cách bằng dấu | để hệ thống tự động xoay tua." }
};

const AI_STYLES_MAP = {
    'ban_hang': { name: 'Bán hàng / Hard Sale', prompt: "Viết theo phong cách BÁN HÀNG TRỰC TIẾP (Hard Sale): Giọng văn thuyết phục, tập trung vào ưu đãi, tính năng vượt trội và lời kêu gọi mua hàng (CTA) quyết liệt." },
    'chia_se': { name: 'Chia sẻ kiến thức', prompt: "Viết theo phong cách CHIA SẺ KIẾN THỨC (Educational): Giọng văn hữu ích, khách quan, cung cấp các mẹo hay, hướng dẫn thực tế trước khi nhắc nhẹ đến giải pháp." },
    'ke_chuyen': { name: 'Kể chuyện / Storytelling', prompt: "Viết theo phong cách KỂ CHUYỆN (Storytelling): Dẫn dắt người đọc bằng câu chuyện chân thực, giàu cảm xúc và kết nối đồng cảm." },
    'giat_gan': { name: 'Giật gân / Bắt mắt', prompt: "Viết theo phong cách GIẬT GÂN (Viral/Hook): Giật tiêu đề tò mò, kịch tính, tạo cảm giác bất ngờ khiến người đọc phải theo dõi hết bài." },
    'hai_huoc': { name: 'Hài hước / Trendy', prompt: "Viết theo phong cách HÀI HƯỚC (Humorous): Lồng ghép các câu từ bắt trend, ví von dí dỏm tạo tiếng cười tự nhiên và gần gũi." },
    'chuyen_gia': { name: 'Chuyên gia / Uy tín', prompt: "Viết theo phong cách CHUYÊN GIA (Expert): Giọng văn chuẩn mực, lập luận sắc bén, trích dẫn số liệu hoặc căn cứ uy tín để tạo niềm tin tối đa." },
    'tam_su': { name: 'Tâm sự / Đồng cảm', prompt: "Viết theo phong cách TÂM SỰ (Empathetic): Giọng văn nhẹ nhàng, sâu lắng, chia sẻ khó khăn chung để chạm tới cảm xúc người đọc." },
    'so_sanh': { name: 'So sánh / Đánh giá', prompt: "Viết theo phong cách SO SÁNH & ĐÁNH GIÁ (Review): Đặt lên bàn cân ưu - nhược điểm minh bạch giúp khách hàng tự tin đưa ra lựa chọn." },
    'toi_gian': { name: 'Tối giản / Súc tích', prompt: "Viết theo phong cách TỐI GIẢN (Minimalist): Đi thẳng vào trọng tâm, trình bày gạch đầu dòng rõ ràng, súc tích và dễ hiểu." }
};

function getPromptText(provider) {
    const formulaVal = document.getElementById('select_formula_' + provider)?.value || '';
    const styleVal = document.getElementById('select_style_' + provider)?.value || '';
    let parts = [];
    if (formulaVal && AI_FORMULAS_MAP[formulaVal]) {
        parts.push(AI_FORMULAS_MAP[formulaVal].prompt);
    }
    if (styleVal && AI_STYLES_MAP[styleVal]) {
        parts.push(AI_STYLES_MAP[styleVal].prompt);
    }
    return parts.join("\n\n");
}

function previewPromptHelper(provider) {
    const text = getPromptText(provider);
    const box = document.getElementById('helper_preview_box_' + provider);
    const textElem = document.getElementById('helper_preview_text_' + provider);
    if (text) {
        textElem.textContent = text;
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}

function insertPromptHelper(provider, targetField) {
    const text = getPromptText(provider);
    if (!text) {
        alert('Vui lòng chọn ít nhất 1 Công thức hoặc 1 Phong cách từ menu xổ xuống!');
        return;
    }
    const formElem = document.getElementById('form_' + provider);
    if (!formElem) return;
    
    const fieldName = (targetField === 'title') ? 'prompt_title' : 'prompt_content';
    const textarea = formElem.querySelector('textarea[name="' + fieldName + '"]');
    
    if (textarea) {
        if (textarea.value.trim() === '') {
            textarea.value = text + "\n\n{prompt}";
        } else {
            textarea.value = textarea.value.trim() + "\n\n" + text;
        }
        alert('✅ Đã chèn thành công vào ' + (targetField === 'title' ? 'Prompt Tiêu Đề' : 'Prompt Nội Dung') + '!');
    }
}
</script>

<?php include 'includes/footer.php'; ?>
