<?php
// includes/kho_data_selector.php
// Selector Kho Data dạng nút bấm Inline & Modal popup
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($pdo)) {
    require_once __DIR__ . '/db.php';
}
$account_id = $account_id ?? ($_SESSION['account_id'] ?? 0);

$media_data_groups = [];
try {
    $stmt_mdg = $pdo->prepare("
        SELECT g.id, g.name,
               (SELECT COUNT(*) FROM media_data_items i WHERE i.group_id = g.id) AS total_items 
        FROM media_data_groups g 
        WHERE g.account_id = ? 
        ORDER BY g.name ASC
    ");
    $stmt_mdg->execute([$account_id]);
    $media_data_groups = $stmt_mdg->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $media_data_groups = [];
}
?>

<!-- Hidden Inputs for Selected Kho Data -->
<input type="hidden" id="data_group_id" name="data_group_id" value="">
<input type="hidden" id="data_mode" name="data_mode" value="dedup">

<!-- Inline Button (rendered in upload container) -->
<div style="font-weight: bold; color: #64748b;">HOẶC</div>
<button type="button" class="btn btn-secondary" onclick="openKhoDataModal()" style="background: #fdf2f8; border: 1px solid #f9a8d4; color: #be185d; font-weight: 600; display: flex; align-items: center; gap: 6px;">
    📁 Chọn từ Kho Data
</button>

<!-- Selection Info Container -->
<div id="khoDataSelectionInfo" style="margin-top: 10px; display: none; width: 100%; padding: 10px 15px; background: #fdf2f8; border: 1px solid #f9a8d4; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
    <div style="display: flex; justify-content: space-between; align-items: center; color: #be185d; font-weight: bold;">
        <span>📁 Đã chọn Kho Data: <span id="khoDataSelectedName">...</span> (<span id="khoDataSelectedMode">...</span>)</span>
        <button type="button" onclick="clearKhoDataSelection()" style="background: none; border: none; color: #dc2626; cursor: pointer; text-decoration: underline; font-size: 12px;">Hủy / Xoá chọn</button>
    </div>
</div>

<!-- Modal Chọn Kho Data -->
<div class="kd-modal-backdrop" id="modal-kho-data-selector" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); align-items: center; justify-content: center; z-index: 99999; padding: 20px;">
    <div class="kd-modal-content" style="background: #fff; border-radius: 14px; width: 100%; max-width: 520px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); border: 1px solid #f9a8d4;">
        <div style="padding: 16px 20px; background: #fdf2f8; border-bottom: 1px solid #fbcfe8; display: flex; align-items: center; justify-content: space-between;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #be185d; display: flex; align-items: center; gap: 8px;">
                <span>📁</span> Chọn Nguồn Video Từ Kho Data
            </h3>
            <button type="button" onclick="closeKhoDataModal()" style="background: none; border: none; font-size: 22px; color: #9d174d; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <div style="padding: 20px;">
            <?php if (empty($media_data_groups)): ?>
                <div style="padding: 16px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; color: #e11d48; font-size: 13px; text-align: center;">
                    <p style="margin: 0 0 10px 0; font-weight: 600;">⚠️ Bạn chưa có Nhóm Data nào trong Kho Data.</p>
                    <a href="tiktok_search.php" target="_blank" style="background: #be185d; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-weight: bold; font-size: 13px; display: inline-block;">
                        👉 Bấm vào đây để tới Kho Data tạo nhóm mới ↗
                    </a>
                </div>
            <?php else: ?>
                <div style="margin-bottom: 16px;">
                    <label style="font-size: 13px; font-weight: 700; color: #be185d; display: block; margin-bottom: 6px;">1. Chọn Nhóm Data:</label>
                    <select id="modal_select_data_group" style="width: 100%; padding: 10px; border: 1px solid #f9a8d4; border-radius: 6px; font-size: 14px; background: #fff; outline: none;">
                        <option value="">-- Chọn Nhóm Data --</option>
                        <?php foreach ($media_data_groups as $g): ?>
                            <option value="<?php echo $g['id']; ?>" data-name="<?php echo htmlspecialchars($g['name']); ?>" data-count="<?php echo $g['total_items']; ?>">
                                <?php echo htmlspecialchars($g['name']); ?> (<?php echo number_format($g['total_items']); ?> link)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="font-size: 13px; font-weight: 700; color: #be185d; display: block; margin-bottom: 6px;">2. Chế Độ Đăng:</label>
                    <select id="modal_select_data_mode" style="width: 100%; padding: 10px; border: 1px solid #f9a8d4; border-radius: 6px; font-size: 14px; background: #fff; outline: none;">
                        <option value="dedup">🛡️ Đăng chống trùng (Xóa URL khỏi Kho Data ngay sau khi lấy)</option>
                        <option value="random">🎲 Random 1 URL từ Kho Data (Giữ nguyên Kho Data)</option>
                    </select>
                </div>
                <p style="font-size: 12px; color: #9d174d; margin: 0; background: #fff5f8; padding: 10px; border-radius: 6px; border: 1px dashed #fbcfe8; line-height: 1.5;">
                    💡 <em>Khi chọn <strong>Đăng chống trùng</strong>, hệ thống sẽ lấy 1 URL và xóa ngay khỏi Kho Data để các bài sau không bị trùng lặp.</em>
                </p>
            <?php endif; ?>
        </div>
        <div style="padding: 12px 20px; background: #fafafa; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="btn btn-secondary" onclick="closeKhoDataModal()" style="padding: 8px 16px; font-size: 13px;">Hủy</button>
            <?php if (!empty($media_data_groups)): ?>
                <button type="button" class="btn" style="background: #be185d; color: #fff; font-weight: bold; padding: 8px 18px; border-radius: 6px; border: none; font-size: 13px; cursor: pointer;" onclick="confirmKhoDataSelection()">Xác Nhận Chọn</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function openKhoDataModal() {
    const modal = document.getElementById('modal-kho-data-selector');
    if (modal) modal.style.display = 'flex';
}
function closeKhoDataModal() {
    const modal = document.getElementById('modal-kho-data-selector');
    if (modal) modal.style.display = 'none';
}
function confirmKhoDataSelection() {
    const selGroup = document.getElementById('modal_select_data_group');
    const selMode = document.getElementById('modal_select_data_mode');
    
    if (!selGroup || !selGroup.value) {
        if (typeof window.showNotice === 'function') {
            window.showNotice('Vui lòng chọn 1 Nhóm Data!', 'warning');
        } else {
            alert('Vui lòng chọn 1 Nhóm Data!');
        }
        return;
    }
    
    const groupId = selGroup.value;
    const mode = selMode ? selMode.value : 'dedup';
    const selectedOption = selGroup.options[selGroup.selectedIndex];
    const groupName = selectedOption.getAttribute('data-name');
    const count = parseInt(selectedOption.getAttribute('data-count')) || 0;
    
    if (count <= 0) {
        if (typeof window.showNotice === 'function') {
            window.showNotice('Nhóm Data này hiện chưa có link video nào! Vui lòng chọn nhóm khác hoặc vào Kho Data để bổ sung link.', 'warning');
        } else {
            alert('Nhóm Data này hiện chưa có link video nào! Vui lòng chọn nhóm khác hoặc vào Kho Data để bổ sung link.');
        }
        return;
    }
    
    const groupInputs = document.querySelectorAll('input[name="data_group_id"]');
    if (groupInputs.length > 0) {
        groupInputs.forEach(el => el.value = groupId);
    } else {
        const el = document.getElementById('data_group_id');
        if (el) el.value = groupId;
    }
    
    const modeInputs = document.querySelectorAll('input[name="data_mode"]');
    if (modeInputs.length > 0) {
        modeInputs.forEach(el => el.value = mode);
    } else {
        const el = document.getElementById('data_mode');
        if (el) el.value = mode;
    }
    
    // Clear local file & Drive selections if any
    const videoEl = document.getElementById('video');
    if (videoEl) videoEl.value = '';
    const photosEl = document.getElementById('images');
    if (photosEl) photosEl.value = '';
    
    if (typeof clearDriveSelection === 'function') {
        clearDriveSelection();
    }
    
    const modeText = (mode === 'dedup') ? '🛡️ Đăng chống trùng' : '🎲 Random URL';
    const nameSpan = document.getElementById('khoDataSelectedName');
    if (nameSpan) nameSpan.textContent = `${groupName} (${count} link)`;
    const modeSpan = document.getElementById('khoDataSelectedMode');
    if (modeSpan) modeSpan.textContent = modeText;
    const infoBox = document.getElementById('khoDataSelectionInfo');
    if (infoBox) infoBox.style.display = 'block';
    
    closeKhoDataModal();
}

function clearKhoDataSelection() {
    document.querySelectorAll('input[name="data_group_id"]').forEach(el => el.value = '');
    document.querySelectorAll('input[name="data_mode"]').forEach(el => el.value = 'dedup');
    const infoBox = document.getElementById('khoDataSelectionInfo');
    if (infoBox) infoBox.style.display = 'none';
}
</script>
