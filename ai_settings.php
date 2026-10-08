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
    'prompt_content' => "Bạn là một trợ lý viết content Facebook xuất sắc...\n\n{prompt}",
    'prompt_title' => "Hãy sáng tạo một tiêu đề thu hút (title) ngắn gọn, bắt mắt dựa trên chủ đề sau:\n\n{prompt}",
    'prompt_youtube_title' => "- Dài từ 60-90 ký tự, chứa từ khóa chính ngay từ đầu.\n- Ngắn gọn, hấp dẫn, gây tò mò, không quá chung chung.",
    'prompt_youtube_desc' => "Bố cục description bắt buộc gồm Mô tả, Hashtags và Keywords...\n{prompt}",
    'prompt_youtube_tags' => "Tối thiểu 20 thẻ tags...",
    'formula' => 'aida',
    'style' => 'ban_hang',
    'is_active' => 0,
    'max_retries' => 2,
    'timeout_seconds' => 120
];
$openai_conf = [
    'endpoint' => '', 'api_keys' => '', 'model' => 'gpt-4o',
    'prompt_content' => "Bạn là một chuyên gia marketing. Hãy viết lại nội dung sau đây sao cho hấp dẫn người đọc nhất:\n\n{prompt}",
    'prompt_title' => "Tạo 1 tiêu đề thật ấn tượng cho chủ đề sau:\n\n{prompt}",
    'prompt_youtube_title' => "- Dài từ 60-90 ký tự, chứa từ khóa chính ngay từ đầu.\n- Ngắn gọn, hấp dẫn, gây tò mò, không quá chung chung.",
    'prompt_youtube_desc' => "Bố cục description bắt buộc gồm Mô tả, Hashtags và Keywords...\n{prompt}",
    'prompt_youtube_tags' => "Tối thiểu 20 thẻ tags...",
    'formula' => 'aida',
    'style' => 'ban_hang',
    'is_active' => 0,
    'max_retries' => 2,
    'timeout_seconds' => 120
];
$claude_conf = [
    'endpoint' => '', 'api_keys' => '', 'model' => 'claude-3-5-sonnet-20241022',
    'prompt_content' => "Bạn là một trợ lý viết content Facebook xuất sắc...\n\n{prompt}",
    'prompt_title' => "Hãy sáng tạo một tiêu đề thu hút (title) ngắn gọn, bắt mắt dựa trên chủ đề sau:\n\n{prompt}",
    'prompt_youtube_title' => "- Dài từ 60-90 ký tự, chứa từ khóa chính ngay từ đầu.\n- Ngắn gọn, hấp dẫn, gây tò mò, không quá chung chung.",
    'prompt_youtube_desc' => "Bố cục description bắt buộc gồm Mô tả, Hashtags và Keywords...\n{prompt}",
    'prompt_youtube_tags' => "Tối thiểu 20 thẻ tags...",
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
    <div class="ai-helper-card">
        <div class="ai-helper-header">
            <div class="ai-helper-title">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span>Thiết lập Công Thức & Phong Cách Nội Dung</span>
            </div>
            <span class="ai-helper-badge">Tự động áp dụng khi gửi Prompt</span>
        </div>

        <div class="ai-helper-grid">
            <div class="ai-helper-col">
                <label class="ai-field-label">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Công Thức Viết Bài (19 Công Thức)
                </label>
                <select name="formula" class="ai-select">
                    <?php foreach ($ai_formulas as $f): ?>
                        <option value="<?= htmlspecialchars($f['code']) ?>" <?= $current_formula === $f['code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ai-helper-col">
                <label class="ai-field-label">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                    Phong Cách Nội Dung (9 Phong Cách)
                </label>
                <select name="style" class="ai-select">
                    <?php foreach ($ai_styles as $s): ?>
                        <option value="<?= htmlspecialchars($s['code']) ?>" <?= $current_style === $s['code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p class="ai-helper-foot">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Hệ thống sẽ tự động ghép quy tắc công thức & phong cách được chọn khi gửi Prompt cho AI mà không cần thao tác chèn thủ công.
        </p>
    </div>
    <?php
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Modern Evondev Skill Styling for AI Settings */
:root {
    --ai-indigo: #6366f1;
    --ai-indigo-dark: #4f46e5;
    --ai-indigo-light: #eef2ff;
    --ai-emerald: #10b981;
    --ai-amber: #f59e0b;
}

.ai-settings-container {
    max-width: 1280px;
    margin: 0 auto;
    padding-bottom: 40px;
}

/* Header Banner */
.ai-header-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
    border: 1px solid #312e81;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(15, 23, 42, 0.15);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.ai-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.02em;
}

.ai-header-info p {
    font-size: 13.5px;
    color: #cbd5e1;
    margin: 0;
}

.active-provider-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 700;
    color: #c7d2fe;
}

.active-provider-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

/* Alert styling */
.ai-alert {
    padding: 14px 18px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.ai-alert-success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
}
.ai-alert-danger {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

/* Navigation Tabs */
.ai-nav-tabs {
    display: flex;
    gap: 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 6px;
    border-radius: 14px;
    margin-bottom: 24px;
}

.ai-tab-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ai-tab-btn:hover {
    color: #1e293b;
    background: rgba(255, 255, 255, 0.6);
}

.ai-tab-btn.active-gemini {
    background: #ffffff;
    color: #4f46e5;
    box-shadow: 0 2px 10px rgba(79, 70, 229, 0.12);
    border: 1px solid #c7d2fe;
}
.ai-tab-btn.active-openai {
    background: #ffffff;
    color: #059669;
    box-shadow: 0 2px 10px rgba(16, 185, 129, 0.12);
    border: 1px solid #a7f3d0;
}
.ai-tab-btn.active-claude {
    background: #ffffff;
    color: #d97706;
    box-shadow: 0 2px 10px rgba(245, 158, 11, 0.12);
    border: 1px solid #fde68a;
}

.tab-status-tag {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 9999px;
    font-weight: 700;
    text-transform: uppercase;
}
.active-gemini .tab-status-tag { background: #e0e7ff; color: #4338ca; }
.active-openai .tab-status-tag { background: #d1fae5; color: #065f46; }
.active-claude .tab-status-tag { background: #fef3c7; color: #92400e; }

/* Main Card Container */
.ai-form-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);
}

/* Card Section Titles */
.ai-section-title {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    margin: 24px 0 14px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ai-section-title:first-child {
    margin-top: 0;
}

/* Enable Switch Container */
.ai-active-switch-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.ai-active-switch-card label {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    font-weight: 700;
    font-size: 14px;
    color: #1e293b;
    user-select: none;
    margin: 0;
}
.ai-active-switch-card input[type="checkbox"] {
    width: 20px;
    height: 20px;
    accent-color: var(--ai-indigo);
    cursor: pointer;
}

/* Input Fields & Textareas */
.ai-form-group {
    margin-bottom: 20px;
}
.ai-form-group label {
    display: block;
    font-size: 13.5px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 8px;
}
.ai-input, .ai-textarea, .ai-select {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    box-sizing: border-box;
    transition: all 0.2s ease;
    font-family: inherit;
}
.ai-textarea {
    font-family: 'Consolas', 'Monaco', monospace;
    line-height: 1.5;
}
.ai-input:focus, .ai-textarea:focus, .ai-select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}
.ai-help-text {
    font-size: 12px;
    color: #64748b;
    margin-top: 6px;
    display: block;
}

/* Grid System */
.ai-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
.ai-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
@media (max-width: 768px) {
    .ai-grid-2, .ai-grid-3 {
        grid-template-columns: 1fr;
    }
}

/* Formula & Style Helper Box */
.ai-helper-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 20px;
    margin: 24px 0;
}
.ai-helper-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 10px;
}
.ai-helper-title {
    font-size: 14px;
    font-weight: 800;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ai-helper-title svg { color: #6366f1; }
.ai-helper-badge {
    font-size: 11.5px;
    font-weight: 700;
    background: #e0e7ff;
    color: #4338ca;
    padding: 3px 10px;
    border-radius: 9999px;
}
.ai-helper-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
@media (max-width: 768px) { .ai-helper-grid { grid-template-columns: 1fr; } }
.ai-field-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 6px;
}
.ai-helper-foot {
    font-size: 12px;
    color: #64748b;
    margin: 12px 0 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Variable Code Pills */
.var-pill {
    display: inline-block;
    padding: 2px 6px;
    background: #e2e8f0;
    color: #0f172a;
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
    font-weight: 700;
}

/* YouTube Container Block */
.youtube-block {
    background: #fff5f5;
    border: 1px solid #fed7d7;
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 24px;
}
.youtube-block-title {
    font-size: 14px;
    font-weight: 800;
    color: #9b2c2c;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Submit Buttons */
.ai-settings-container,
.ai-settings-container button,
.ai-settings-container input,
.ai-settings-container textarea,
.ai-settings-container select,
.ai-btn-submit,
.ai-tab-btn {
    font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.ai-btn-submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 28px;
    font-size: 14px;
    font-weight: 800;
    border-radius: 10px;
    color: #ffffff;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.ai-btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
}
.btn-gemini-submit { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }
.btn-openai-submit { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
.btn-claude-submit { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
</style>

<div class="ai-settings-container">
    <!-- Header Banner -->
    <div class="ai-header-card">
        <div class="ai-header-info">
            <h1>
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                Cấu hình AI Rewriter & Content Generator
            </h1>
            <p>Tùy chỉnh API Keys, Model, Công thức Marketing & Prompt tự động cho Gemini, OpenAI và Claude</p>
        </div>
        <div>
            <?php
                $active_provider_name = 'Gemini AI';
                if ($openai_conf['is_active']) $active_provider_name = 'OpenAI (ChatGPT)';
                elseif ($claude_conf['is_active']) $active_provider_name = 'Claude AI';
                elseif ($gemini_conf['is_active']) $active_provider_name = 'Google Gemini';
            ?>
            <div class="active-provider-pill">
                <span class="active-provider-dot"></span>
                <span>Đang sử dụng: <?= $active_provider_name ?></span>
            </div>
        </div>
    </div>

    <!-- Global Alert -->
    <?php if ($alert_message): ?>
        <div class="ai-alert ai-alert-<?= $alert_type === 'success' ? 'success' : 'danger' ?>">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><?= htmlspecialchars($alert_message) ?></span>
        </div>
    <?php endif; ?>

    <!-- Navigation Tab Buttons -->
    <div class="ai-nav-tabs">
        <button type="button" class="ai-tab-btn <?= $active_tab==='gemini' ? 'active-gemini' : '' ?>" onclick="switchTab('gemini')" id="tab_gemini">
            <span>✨ Google Gemini</span>
            <?php if ($gemini_conf['is_active']): ?>
                <span class="tab-status-tag">Mặc định</span>
            <?php endif; ?>
        </button>
        <button type="button" class="ai-tab-btn <?= $active_tab==='openai' ? 'active-openai' : '' ?>" onclick="switchTab('openai')" id="tab_openai">
            <span>🤖 OpenAI / ChatGPT</span>
            <?php if ($openai_conf['is_active']): ?>
                <span class="tab-status-tag">Mặc định</span>
            <?php endif; ?>
        </button>
        <button type="button" class="ai-tab-btn <?= $active_tab==='claude' ? 'active-claude' : '' ?>" onclick="switchTab('claude')" id="tab_claude">
            <span>🔮 Anthropic Claude</span>
            <?php if ($claude_conf['is_active']): ?>
                <span class="tab-status-tag">Mặc định</span>
            <?php endif; ?>
        </button>
    </div>

    <!-- Main Card Body -->
    <div class="ai-form-card">

        <!-- ==================== GEMINI FORM ==================== -->
        <div id="form_gemini" style="display: <?= $active_tab === 'gemini' ? 'block' : 'none' ?>;">
            <form method="POST" action="ai_settings.php">
                <input type="hidden" name="provider" value="Gemini">

                <!-- Active Toggle -->
                <div class="ai-active-switch-card">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?= $gemini_conf['is_active'] ? 'checked' : '' ?>>
                        <span>Kích hoạt Gemini làm AI mặc định cho hệ thống đăng bài</span>
                    </label>
                    <span class="tab-status-tag" style="background:#e0e7ff; color:#4338ca;">Google Gemini API</span>
                </div>

                <div class="ai-section-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 0121 9z"/></svg>
                    Cấu hình Kết nối & API Key
                </div>

                <div class="ai-form-group">
                    <label>API Endpoint (Tùy chọn)</label>
                    <input type="text" name="endpoint" value="<?= htmlspecialchars($gemini_conf['endpoint']) ?>" placeholder="Mặc định: https://generativelanguage.googleapis.com/v1beta/models" class="ai-input">
                </div>

                <div class="ai-form-group">
                    <label>Danh sách API Keys (Phân cách bằng dấu phẩy)</label>
                    <textarea name="api_keys" rows="3" class="ai-textarea" placeholder="AIzaSyA..., AIzaSyB..." required><?= htmlspecialchars($gemini_conf['api_keys']) ?></textarea>
                    <small class="ai-help-text">💡 Hệ thống sẽ tự động xoay vòng hoặc đổi Key nếu một Key bị lỗi hoặc hết hạn mức (Quota Rate Limit).</small>
                </div>

                <div class="ai-form-group">
                    <label>Model Engine (Ví dụ: gemini-1.5-pro, gemini-1.5-flash)</label>
                    <input type="text" name="model" value="<?= htmlspecialchars($gemini_conf['model']) ?>" placeholder="gemini-1.5-pro, gemini-1.5-flash" class="ai-input" required>
                    <small class="ai-help-text">Hỗ trợ nhập nhiều Model phân cách bằng dấu phẩy. Ưu tiên thử model đầu tiên, nếu lỗi tự động chuyển sang model kế tiếp.</small>
                </div>

                <div class="ai-grid-2">
                    <div class="ai-form-group">
                        <label>Số lần thử lại khi lỗi (Max Retries)</label>
                        <input type="number" name="max_retries" value="<?= htmlspecialchars($gemini_conf['max_retries'] ?? 2) ?>" min="0" max="10" required class="ai-input">
                    </div>
                    <div class="ai-form-group">
                        <label>Timeout kết nối (Giây)</label>
                        <input type="number" name="timeout_seconds" value="<?= htmlspecialchars($gemini_conf['timeout_seconds'] ?? 120) ?>" min="5" max="600" required class="ai-input">
                    </div>
                </div>

                <!-- Helper Box for Formula & Style -->
                <?php renderPromptHelper('gemini', $gemini_conf); ?>

                <div class="ai-section-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Prompt Tiêu Chuẩn Viết Bài Facebook
                </div>

                <div class="ai-grid-2">
                    <div class="ai-form-group">
                        <label>Prompt Viết Nội Dung (Thẻ: <span class="var-pill">{prompt}</span>, <span class="var-pill">{fanpage_name}</span>)</label>
                        <textarea name="prompt_content" rows="5" class="ai-textarea" required><?= htmlspecialchars($gemini_conf['prompt_content']) ?></textarea>
                    </div>
                    <div class="ai-form-group">
                        <label>Prompt Viết Tiêu Đề (Thẻ: <span class="var-pill">{prompt}</span>, <span class="var-pill">{fanpage_name}</span>)</label>
                        <textarea name="prompt_title" rows="5" class="ai-textarea" required><?= htmlspecialchars($gemini_conf['prompt_title']) ?></textarea>
                    </div>
                </div>

                <!-- YouTube Automation Prompts -->
                <div class="youtube-block">
                    <div class="youtube-block-title">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                        Cấu hình Prompt Tự Động Cho Lên Lịch YouTube (Chạy 3 Bước Nối Tiếp)
                    </div>
                    <div class="ai-grid-3">
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>1. Prompt Title (YouTube)</label>
                            <textarea name="prompt_youtube_title" rows="4" class="ai-textarea" placeholder="- Dài 60-90 ký tự...\nNội dung: {prompt}" required><?= htmlspecialchars($gemini_conf['prompt_youtube_title'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>2. Prompt Description (YouTube)</label>
                            <textarea name="prompt_youtube_desc" rows="4" class="ai-textarea" placeholder="Mô tả nội dung: {prompt}\nTiêu đề: {title}" required><?= htmlspecialchars($gemini_conf['prompt_youtube_desc'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{title}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>3. Prompt Tags (YouTube)</label>
                            <textarea name="prompt_youtube_tags" rows="4" class="ai-textarea" placeholder="Tối thiểu 20 thẻ tags...\nNội dung: {prompt}" required><?= htmlspecialchars($gemini_conf['prompt_youtube_tags'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                    </div>
                </div>

                <button type="submit" name="save_config" class="ai-btn-submit btn-gemini-submit">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Lưu Cấu Hình Gemini AI
                </button>
            </form>
        </div>


        <!-- ==================== OPENAI FORM ==================== -->
        <div id="form_openai" style="display: <?= $active_tab === 'openai' ? 'block' : 'none' ?>;">
            <form method="POST" action="ai_settings.php">
                <input type="hidden" name="provider" value="OpenAI">

                <!-- Active Toggle -->
                <div class="ai-active-switch-card">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?= $openai_conf['is_active'] ? 'checked' : '' ?>>
                        <span>Kích hoạt OpenAI (ChatGPT) làm AI mặc định cho hệ thống đăng bài</span>
                    </label>
                    <span class="tab-status-tag" style="background:#d1fae5; color:#065f46;">OpenAI ChatGPT</span>
                </div>

                <div class="ai-section-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 0121 9z"/></svg>
                    Cấu hình Kết nối & API Key (OpenAI / Proxy)
                </div>

                <div class="ai-form-group">
                    <label>API Endpoint (Tùy chọn - Dùng với Reverse Proxy/Yescale)</label>
                    <input type="text" name="endpoint" value="<?= htmlspecialchars($openai_conf['endpoint']) ?>" placeholder="Mặc định: https://api.openai.com/v1/chat/completions" class="ai-input">
                </div>

                <div class="ai-form-group">
                    <label>Danh sách API Keys (Bắt đầu bằng sk-..., phân cách bằng dấu phẩy)</label>
                    <textarea name="api_keys" rows="3" class="ai-textarea" placeholder="sk-123..., sk-456..." required><?= htmlspecialchars($openai_conf['api_keys']) ?></textarea>
                </div>

                <div class="ai-form-group">
                    <label>Model Engine (Ví dụ: gpt-4o, gpt-4o-mini, gpt-3.5-turbo)</label>
                    <input type="text" name="model" value="<?= htmlspecialchars($openai_conf['model']) ?>" placeholder="gpt-4o, gpt-4o-mini" class="ai-input" required>
                    <small class="ai-help-text">Hỗ trợ nhập nhiều Model phân cách bằng dấu phẩy. Ưu tiên thử model đầu tiên, nếu lỗi tự động chuyển sang model kế tiếp.</small>
                </div>

                <div class="ai-grid-2">
                    <div class="ai-form-group">
                        <label>Số lần thử lại khi lỗi (Max Retries)</label>
                        <input type="number" name="max_retries" value="<?= htmlspecialchars($openai_conf['max_retries'] ?? 2) ?>" min="0" max="10" required class="ai-input">
                    </div>
                    <div class="ai-form-group">
                        <label>Timeout kết nối (Giây)</label>
                        <input type="number" name="timeout_seconds" value="<?= htmlspecialchars($openai_conf['timeout_seconds'] ?? 120) ?>" min="5" max="600" required class="ai-input">
                    </div>
                </div>

                <!-- Helper Box for Formula & Style -->
                <?php renderPromptHelper('openai', $openai_conf); ?>

                <div class="ai-section-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Prompt Tiêu Chuẩn Viết Bài Facebook
                </div>

                <div class="ai-grid-2">
                    <div class="ai-form-group">
                        <label>Prompt Viết Nội Dung (Thẻ: <span class="var-pill">{prompt}</span>, <span class="var-pill">{fanpage_name}</span>)</label>
                        <textarea name="prompt_content" rows="5" class="ai-textarea" required><?= htmlspecialchars($openai_conf['prompt_content']) ?></textarea>
                    </div>
                    <div class="ai-form-group">
                        <label>Prompt Viết Tiêu Đề (Thẻ: <span class="var-pill">{prompt}</span>, <span class="var-pill">{fanpage_name}</span>)</label>
                        <textarea name="prompt_title" rows="5" class="ai-textarea" required><?= htmlspecialchars($openai_conf['prompt_title']) ?></textarea>
                    </div>
                </div>

                <!-- YouTube Automation Prompts -->
                <div class="youtube-block">
                    <div class="youtube-block-title">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                        Cấu hình Prompt Tự Động Cho Lên Lịch YouTube (Chạy 3 Bước Nối Tiếp)
                    </div>
                    <div class="ai-grid-3">
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>1. Prompt Title (YouTube)</label>
                            <textarea name="prompt_youtube_title" rows="4" class="ai-textarea" placeholder="- Dài 60-90 ký tự...\nNội dung: {prompt}" required><?= htmlspecialchars($openai_conf['prompt_youtube_title'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>2. Prompt Description (YouTube)</label>
                            <textarea name="prompt_youtube_desc" rows="4" class="ai-textarea" placeholder="Mô tả nội dung: {prompt}\nTiêu đề: {title}" required><?= htmlspecialchars($openai_conf['prompt_youtube_desc'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{title}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>3. Prompt Tags (YouTube)</label>
                            <textarea name="prompt_youtube_tags" rows="4" class="ai-textarea" placeholder="Tối thiểu 20 thẻ tags...\nNội dung: {prompt}" required><?= htmlspecialchars($openai_conf['prompt_youtube_tags'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                    </div>
                </div>

                <button type="submit" name="save_config" class="ai-btn-submit btn-openai-submit">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Lưu Cấu Hình OpenAI
                </button>
            </form>
        </div>


        <!-- ==================== CLAUDE FORM ==================== -->
        <div id="form_claude" style="display: <?= $active_tab === 'claude' ? 'block' : 'none' ?>;">
            <form method="POST" action="ai_settings.php">
                <input type="hidden" name="provider" value="Claude">

                <!-- Active Toggle -->
                <div class="ai-active-switch-card">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?= $claude_conf['is_active'] ? 'checked' : '' ?>>
                        <span>Kích hoạt Anthropic Claude làm AI mặc định cho hệ thống đăng bài</span>
                    </label>
                    <span class="tab-status-tag" style="background:#fef3c7; color:#92400e;">Anthropic Claude</span>
                </div>

                <div class="ai-section-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 0121 9z"/></svg>
                    Cấu hình Kết nối & API Key
                </div>

                <div class="ai-form-group">
                    <label>API Endpoint (Tùy chọn)</label>
                    <input type="text" name="endpoint" value="<?= htmlspecialchars($claude_conf['endpoint']) ?>" placeholder="Mặc định: https://api.anthropic.com/v1/messages" class="ai-input">
                </div>

                <div class="ai-form-group">
                    <label>Danh sách API Keys (Bắt đầu bằng sk-ant-..., phân cách bằng dấu phẩy)</label>
                    <textarea name="api_keys" rows="3" class="ai-textarea" placeholder="sk-ant-..., sk-ant-..." required><?= htmlspecialchars($claude_conf['api_keys']) ?></textarea>
                </div>

                <div class="ai-form-group">
                    <label>Model Engine (Ví dụ: claude-3-5-sonnet-20241022, claude-3-opus-20240229)</label>
                    <input type="text" name="model" value="<?= htmlspecialchars($claude_conf['model']) ?>" placeholder="claude-3-5-sonnet-20241022" class="ai-input" required>
                    <small class="ai-help-text">Hỗ trợ nhập nhiều Model phân cách bằng dấu phẩy. Ưu tiên thử model đầu tiên, nếu lỗi tự động chuyển sang model kế tiếp.</small>
                </div>

                <div class="ai-grid-2">
                    <div class="ai-form-group">
                        <label>Số lần thử lại khi lỗi (Max Retries)</label>
                        <input type="number" name="max_retries" value="<?= htmlspecialchars($claude_conf['max_retries'] ?? 2) ?>" min="0" max="10" required class="ai-input">
                    </div>
                    <div class="ai-form-group">
                        <label>Timeout kết nối (Giây)</label>
                        <input type="number" name="timeout_seconds" value="<?= htmlspecialchars($claude_conf['timeout_seconds'] ?? 120) ?>" min="5" max="600" required class="ai-input">
                    </div>
                </div>

                <!-- Helper Box for Formula & Style -->
                <?php renderPromptHelper('claude', $claude_conf); ?>

                <div class="ai-section-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Prompt Tiêu Chuẩn Viết Bài Facebook
                </div>

                <div class="ai-grid-2">
                    <div class="ai-form-group">
                        <label>Prompt Viết Nội Dung (Thẻ: <span class="var-pill">{prompt}</span>, <span class="var-pill">{fanpage_name}</span>)</label>
                        <textarea name="prompt_content" rows="5" class="ai-textarea" required><?= htmlspecialchars($claude_conf['prompt_content']) ?></textarea>
                    </div>
                    <div class="ai-form-group">
                        <label>Prompt Viết Tiêu Đề (Thẻ: <span class="var-pill">{prompt}</span>, <span class="var-pill">{fanpage_name}</span>)</label>
                        <textarea name="prompt_title" rows="5" class="ai-textarea" required><?= htmlspecialchars($claude_conf['prompt_title']) ?></textarea>
                    </div>
                </div>

                <!-- YouTube Automation Prompts -->
                <div class="youtube-block">
                    <div class="youtube-block-title">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                        Cấu hình Prompt Tự Động Cho Lên Lịch YouTube (Chạy 3 Bước Nối Tiếp)
                    </div>
                    <div class="ai-grid-3">
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>1. Prompt Title (YouTube)</label>
                            <textarea name="prompt_youtube_title" rows="4" class="ai-textarea" placeholder="- Dài 60-90 ký tự...\nNội dung: {prompt}" required><?= htmlspecialchars($claude_conf['prompt_youtube_title'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>2. Prompt Description (YouTube)</label>
                            <textarea name="prompt_youtube_desc" rows="4" class="ai-textarea" placeholder="Mô tả nội dung: {prompt}\nTiêu đề: {title}" required><?= htmlspecialchars($claude_conf['prompt_youtube_desc'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{title}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                        <div class="ai-form-group" style="margin-bottom:0;">
                            <label>3. Prompt Tags (YouTube)</label>
                            <textarea name="prompt_youtube_tags" rows="4" class="ai-textarea" placeholder="Tối thiểu 20 thẻ tags...\nNội dung: {prompt}" required><?= htmlspecialchars($claude_conf['prompt_youtube_tags'] ?? '') ?></textarea>
                            <small class="ai-help-text">Dùng: <span class="var-pill">{prompt}</span>, <span class="var-pill">{channel_name}</span></small>
                        </div>
                    </div>
                </div>

                <button type="submit" name="save_config" class="ai-btn-submit btn-claude-submit">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Lưu Cấu Hình Claude AI
                </button>
            </form>
        </div>

    </div>
</div>

<script>
function switchTab(tab) {
    document.getElementById('form_gemini').style.display = tab === 'gemini' ? 'block' : 'none';
    document.getElementById('form_openai').style.display = tab === 'openai' ? 'block' : 'none';
    document.getElementById('form_claude').style.display = tab === 'claude' ? 'block' : 'none';

    var tabGemini = document.getElementById('tab_gemini');
    var tabOpenai = document.getElementById('tab_openai');
    var tabClaude = document.getElementById('tab_claude');

    tabGemini.className = 'ai-tab-btn' + (tab === 'gemini' ? ' active-gemini' : '');
    tabOpenai.className = 'ai-tab-btn' + (tab === 'openai' ? ' active-openai' : '');
    tabClaude.className = 'ai-tab-btn' + (tab === 'claude' ? ' active-claude' : '');

    // Update the browser URL dynamically without page reload
    history.replaceState(null, '', 'ai_settings.php?tab=' + tab);
}
</script>

<?php include 'includes/footer.php'; ?>
