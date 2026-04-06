<?php
$current_page = 'special_watch';
require_once __DIR__ . '/includes/header.php';
?>
<style>
/* ═══════════════════════════════════════════════════════════════════
   THEO DÕI ĐẶC BIỆT — Main Styles
   ═══════════════════════════════════════════════════════════════════ */

/* Hero Banner */
.sw-hero {
    background: linear-gradient(135deg, #0a0a1a 0%, #1a0a2e 45%, #2d0b55 100%);
    border-radius: 16px; padding: 26px 32px; margin-bottom: 24px;
    display: flex; align-items: center; justify-content: space-between; gap: 20px;
    position: relative; overflow: hidden;
}
.sw-hero::before {
    content: ''; position: absolute; top: -40px; right: -40px;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(79,70,229,0.4) 0%, transparent 70%);
    pointer-events: none;
}
.sw-hero::after {
    content: ''; position: absolute; bottom: -20px; left: 30%;
    width: 140px; height: 140px;
    background: radial-gradient(circle, rgba(16,185,129,0.25) 0%, transparent 70%);
    pointer-events: none;
}
.sw-hero-left { display: flex; align-items: center; gap: 18px; }
.sw-hero-icon {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    border-radius: 14px; display: flex; align-items: center; justify-content: center;
    font-size: 26px; flex-shrink: 0; box-shadow: 0 4px 18px rgba(79,70,229,0.5);
}
.sw-hero-text h1 { font-size: 21px; font-weight: 700; color: #fff; margin: 0 0 4px; letter-spacing: -.3px; }
.sw-hero-text p  { font-size: 13px; color: rgba(255,255,255,0.6); margin: 0; }
.sw-hero-right { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
.sw-page-count { font-size: 13px; color: rgba(255,255,255,0.7); }
.sw-page-count strong { color: #a5f3fc; font-size: 15px; }

/* Add Button */
.btn-add-watch {
    padding: 10px 22px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff; border: none; border-radius: 10px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; gap: 8px;
    box-shadow: 0 4px 14px rgba(79,70,229,0.4);
    transition: all .2s;
}
.btn-add-watch:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(79,70,229,0.55); }

/* ── Watch List Table ── */
.sw-table-wrap {
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 14px; overflow: hidden;
}
.sw-table-header {
    padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid var(--border-color);
}
.sw-table-title { font-size: 15px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
.sw-table { width: 100%; border-collapse: collapse; }
.sw-table thead th {
    padding: 12px 16px; background: rgba(79,70,229,0.07);
    color: var(--text-muted); font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    border-bottom: 1px solid var(--border-color); white-space: nowrap; text-align: left;
}
.sw-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background .12s;
}
.sw-table tbody tr:last-child { border-bottom: none; }
.sw-table tbody tr:hover { background: rgba(79,70,229,0.04); }
.sw-table td { padding: 14px 16px; vertical-align: middle; }

/* Page info cell */
.sw-page-info { display: flex; align-items: center; gap: 12px; }
.sw-avatar {
    width: 44px; height: 44px; border-radius: 50%; object-fit: cover;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0; overflow: hidden;
    border: 2px solid rgba(79,70,229,0.3);
}
.sw-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.sw-avatar-fallback { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; background: linear-gradient(135deg, #4f46e5, #7c3aed); flex-shrink: 0; }
.sw-page-name { font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 2px; }
.sw-page-date { font-size: 11px; color: var(--text-muted); }

/* Post Count */
.sw-post-count strong { font-size: 18px; font-weight: 700; color: #4f46e5; }
.sw-post-count span   { font-size: 11px; color: var(--text-muted); display: block; }

/* Label Badge */
.sw-label-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
    background: linear-gradient(135deg, rgba(16,185,129,0.15), rgba(5,150,105,0.1));
    color: #10b981; border: 1px solid rgba(16,185,129,0.3);
}
.sw-label-badge::before { content: '🏷️'; font-size: 11px; }

/* Refresh Countdown */
.sw-refresh-info { font-size: 12px; color: var(--text-muted); white-space: nowrap; }
.sw-refresh-info .refresh-time { font-weight: 600; color: #f59e0b; font-size: 13px; }
.sw-refresh-off { font-size: 12px; color: var(--text-muted); }

/* Action Buttons */
.sw-actions { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.sw-btn {
    padding: 7px 14px; border: none; border-radius: 8px;
    font-size: 12px; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; gap: 5px;
    transition: all .15s; white-space: nowrap;
}
.sw-btn-view   { background: rgba(79,70,229,0.12); color: #6366f1; }
.sw-btn-view:hover   { background: rgba(79,70,229,0.22); transform: translateY(-1px); }
.sw-btn-scan   { background: rgba(16,185,129,0.12); color: #10b981; }
.sw-btn-scan:hover   { background: rgba(16,185,129,0.22); transform: translateY(-1px); }
.sw-btn-delete { background: rgba(239,68,68,0.1); color: #ef4444; }
.sw-btn-delete:hover { background: rgba(239,68,68,0.2); transform: translateY(-1px); }
.sw-btn:disabled { opacity: .55; cursor: not-allowed; transform: none !important; }

/* Empty State */
.sw-empty {
    text-align: center; padding: 60px 20px; color: var(--text-muted);
}
.sw-empty .sw-empty-icon { font-size: 52px; margin-bottom: 14px; opacity: .6; }
.sw-empty h3 { font-size: 16px; font-weight: 600; color: var(--text-main); margin: 0 0 8px; }
.sw-empty p  { font-size: 13px; margin: 0 0 20px; }

/* ── Modal Overlay ── */
.sw-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.65);
    backdrop-filter: blur(4px); z-index: 9000;
    display: none; align-items: center; justify-content: center;
}
.sw-modal-overlay.active { display: flex; }

/* Add Modal */
.sw-add-modal {
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 20px; padding: 32px; width: 540px; max-width: 95vw;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    position: relative;
    animation: modalIn .25s ease;
}
@keyframes modalIn { from { transform: scale(.95) translateY(16px); opacity: 0; } to { transform: scale(1) translateY(0); opacity: 1; } }

.sw-modal-close {
    position: absolute; top: 16px; right: 16px;
    width: 32px; height: 32px; border-radius: 50%;
    background: rgba(255,255,255,0.07); border: none;
    color: var(--text-muted); font-size: 16px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: all .15s;
}
.sw-modal-close:hover { background: rgba(239,68,68,0.15); color: #ef4444; }
.sw-add-modal h2 { font-size: 18px; font-weight: 700; color: var(--text-main); margin: 0 0 6px; }
.sw-add-modal .sw-modal-sub { font-size: 13px; color: var(--text-muted); margin: 0 0 24px; }

.sw-form-group { margin-bottom: 18px; }
.sw-form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 7px; }
.sw-form-group input[type="text"],
.sw-form-group input[type="url"],
.sw-form-group select,
.sw-form-group input[type="number"] {
    width: 100%; padding: 11px 14px; border: 1.5px solid var(--border-color);
    border-radius: 10px; background: var(--bg-color); color: var(--text-main);
    font-size: 14px; outline: none; box-sizing: border-box;
    transition: border-color .2s, box-shadow .2s;
}
.sw-form-group input:focus,
.sw-form-group select:focus {
    border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.12);
}
.sw-form-row { display: flex; gap: 12px; }
.sw-form-row .sw-form-group { flex: 1; }

/* Label with create new option */
.sw-label-row { display: flex; gap: 8px; align-items: flex-end; }
.sw-label-row select { flex: 1; }
.btn-new-label {
    padding: 11px 14px; border: 1.5px dashed var(--border-color);
    border-radius: 10px; background: transparent; color: var(--text-muted);
    font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;
    transition: all .15s;
}
.btn-new-label:hover { border-color: #4f46e5; color: #6366f1; }

/* New label input (hidden by default) */
.sw-new-label-input {
    display: none; width: 100%; padding: 11px 14px;
    border: 1.5px solid #4f46e5; border-radius: 10px;
    background: var(--bg-color); color: var(--text-main);
    font-size: 14px; outline: none; box-sizing: border-box; margin-top: 8px;
}

/* Post limit pills */
.sw-limit-pills { display: flex; gap: 8px; flex-wrap: wrap; }
.sw-limit-pill {
    padding: 8px 16px; border: 1.5px solid var(--border-color);
    border-radius: 20px; font-size: 13px; font-weight: 600;
    cursor: pointer; color: var(--text-muted);
    transition: all .15s; background: transparent;
}
.sw-limit-pill.selected {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    border-color: #4f46e5; color: #fff;
    box-shadow: 0 3px 10px rgba(79,70,229,0.4);
}
.sw-limit-pill:hover:not(.selected) { border-color: #4f46e5; color: #6366f1; }

/* Auto refresh toggle */
.sw-toggle-row { display: flex; align-items: center; gap: 12px; }
.sw-toggle-row input[type="checkbox"] {
    width: 18px; height: 18px; accent-color: #4f46e5; cursor: pointer;
}
.sw-toggle-label { font-size: 13px; color: var(--text-main); cursor: pointer; }
.sw-toggle-label span { color: var(--text-muted); font-size: 12px; }

/* Submit button */
.btn-submit-watch {
    width: 100%; padding: 13px; margin-top: 20px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff; border: none; border-radius: 12px;
    font-size: 15px; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 16px rgba(79,70,229,0.4);
    transition: all .2s; display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-submit-watch:hover { transform: translateY(-1px); box-shadow: 0 6px 22px rgba(79,70,229,0.55); }
.btn-submit-watch:disabled { opacity: .6; cursor: not-allowed; transform: none; }

/* ── Posts Viewer Modal ── */
.sw-viewer-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.75);
    backdrop-filter: blur(5px); z-index: 9100;
    display: none; align-items: flex-start; justify-content: center;
    padding: 20px 10px; overflow-y: auto;
}
.sw-viewer-overlay.active { display: flex; }
.sw-viewer-modal {
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 20px; width: 1000px; max-width: 98vw;
    animation: modalIn .25s ease; position: relative; margin: auto;
}
.sw-viewer-header {
    padding: 20px 24px; border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 14px; position: sticky; top: 0;
    background: var(--card-bg); border-radius: 20px 20px 0 0; z-index: 10;
}
.sw-viewer-avatar {
    width: 42px; height: 42px; border-radius: 50%; overflow: hidden;
    flex-shrink: 0; background: linear-gradient(135deg, #4f46e5, #7c3aed);
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.sw-viewer-avatar img { width: 100%; height: 100%; object-fit: cover; }
.sw-viewer-title { flex: 1; }
.sw-viewer-title h3 { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0; }
.sw-viewer-title p  { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }
.sw-viewer-close {
    width: 36px; height: 36px; border-radius: 50%;
    background: rgba(239,68,68,0.12); border: none; color: #ef4444;
    font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: all .15s;
}
.sw-viewer-close:hover { background: rgba(239,68,68,0.25); }

/* Search & Filter bar inside viewer */
.sw-viewer-filters {
    padding: 16px 24px; border-bottom: 1px solid var(--border-color);
    display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    background: rgba(79,70,229,0.03);
}
.sw-search-wrap {
    flex: 1; min-width: 240px; position: relative;
}
.sw-search-wrap input {
    width: 100%; padding: 10px 14px 10px 38px;
    border: 1.5px solid var(--border-color); border-radius: 10px;
    background: var(--bg-color); color: var(--text-main); font-size: 13px;
    outline: none; box-sizing: border-box; transition: border-color .2s;
}
.sw-search-wrap input:focus { border-color: #4f46e5; }
.sw-search-wrap::before {
    content: '🔍'; position: absolute; left: 10px; top: 50%;
    transform: translateY(-50%); font-size: 14px; pointer-events: none;
}
.sw-filter-group { display: flex; align-items: center; gap: 6px; }
.sw-filter-group label { font-size: 12px; font-weight: 600; color: var(--text-muted); white-space: nowrap; }
.sw-filter-group input[type="number"] {
    width: 80px; padding: 8px 10px;
    border: 1.5px solid var(--border-color); border-radius: 8px;
    background: var(--bg-color); color: var(--text-main); font-size: 13px; outline: none;
    transition: border-color .2s;
}
.sw-filter-group input:focus { border-color: #4f46e5; }
.btn-apply-filter {
    padding: 9px 18px; background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; white-space: nowrap; transition: all .15s;
}
.btn-apply-filter:hover { transform: translateY(-1px); }

/* Viewer Info Bar */
.sw-viewer-info {
    padding: 10px 24px; font-size: 12px; color: var(--text-muted);
    border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: space-between; align-items: center;
}
.sw-viewer-info strong { color: var(--text-main); }

/* Posts Grid */
.sw-posts-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
    padding: 20px 24px; min-height: 200px;
}
@media (max-width: 768px) { .sw-posts-grid { grid-template-columns: 1fr; } }
@media (max-width: 1000px) { .sw-posts-grid { grid-template-columns: repeat(2, 1fr); } }

/* Post Card */
.sw-post-card {
    background: var(--bg-color); border: 1px solid var(--border-color);
    border-radius: 12px; overflow: hidden; position: relative;
    transition: all .2s;
}
.sw-post-card:hover { border-color: rgba(79,70,229,0.4); box-shadow: 0 6px 20px rgba(79,70,229,0.12); transform: translateY(-2px); }

.sw-post-card-header {
    padding: 12px 14px; display: flex; align-items: center; gap: 10px;
    border-bottom: 1px solid var(--border-color);
}
.sw-post-card-avatar {
    width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.sw-post-card-avatar img { width: 100%; height: 100%; object-fit: cover; }
.sw-post-card-meta { flex: 1; min-width: 0; }
.sw-post-card-name { font-size: 13px; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sw-post-card-date { font-size: 11px; color: var(--text-muted); }
.sw-post-fb-icon { font-size: 16px; opacity: .6; }

.sw-post-content {
    padding: 10px 14px; font-size: 12px; color: var(--text-main);
    line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 3;
    -webkit-box-orient: vertical; overflow: hidden;
}

.sw-post-image {
    width: 100%; height: 160px; object-fit: cover;
    background: rgba(79,70,229,0.06);
    display: block;
}

.sw-post-stats {
    padding: 8px 14px; display: flex; gap: 12px;
    border-top: 1px solid var(--border-color);
    background: rgba(0,0,0,0.02);
}
.sw-stat-item { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-muted); }
.sw-stat-item strong { color: var(--text-main); font-size: 12px; }

.sw-post-actions {
    padding: 8px 14px; display: flex; gap: 6px;
    border-top: 1px solid var(--border-color);
    opacity: 0; transition: opacity .15s;
}
.sw-post-card:hover .sw-post-actions { opacity: 1; }
.sw-post-act-btn {
    flex: 1; padding: 6px 4px; border: 1px solid var(--border-color);
    border-radius: 6px; background: transparent; color: var(--text-muted);
    font-size: 11px; font-weight: 600; cursor: pointer; text-align: center;
    transition: all .12s; white-space: nowrap;
}
.sw-post-act-btn:hover { background: rgba(79,70,229,0.1); border-color: #6366f1; color: #6366f1; }

/* No posts state */
.sw-no-posts {
    grid-column: 1/-1; text-align: center; padding: 50px;
    color: var(--text-muted); font-size: 14px;
}
.sw-no-posts .icon { font-size: 40px; display: block; margin-bottom: 12px; }

/* Viewer footer */
.sw-viewer-footer {
    padding: 14px 24px; border-top: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: space-between;
    font-size: 12px; color: var(--text-muted);
}

/* Toast */
.sw-toast {
    position: fixed; bottom: 24px; right: 20px;
    padding: 12px 22px; border-radius: 12px;
    font-size: 14px; font-weight: 600;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    display: none; z-index: 99999;
    animation: slideUp .3s ease;
}
.sw-toast.success { background: #10b981; color: #fff; }
.sw-toast.error   { background: #ef4444; color: #fff; }
@keyframes slideUp { from { transform:translateY(16px); opacity:0; } to { transform:translateY(0); opacity:1; } }

/* Spinner */
.spin-ring {
    display: inline-block; width: 18px; height: 18px;
    border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff;
    border-radius: 50%; animation: spin .7s linear infinite;
}
.spin-ring-dark {
    display: inline-block; width: 18px; height: 18px;
    border: 2px solid var(--border-color); border-top-color: #4f46e5;
    border-radius: 50%; animation: spin .7s linear infinite;
}
@keyframes spin { 100% { transform: rotate(360deg); } }

.sw-loading {
    text-align: center; padding: 50px; color: var(--text-muted);
    font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 12px;
}
</style>

<!-- ──────────────────────────── HERO ──────────────────────────────── -->
<div class="sw-hero">
    <div class="sw-hero-left">
        <div class="sw-hero-icon">👁️</div>
        <div class="sw-hero-text">
            <h1>ĐANG THEO DÕI ĐẶC BIỆT</h1>
            <p>Theo dõi bài viết từ Fanpage, Profile hoặc Nhóm Facebook · Quét tự động mỗi 10 giờ</p>
        </div>
    </div>
    <div class="sw-hero-right">
        <div class="sw-page-count">Đã thêm: <strong id="total-count">0</strong> trang</div>
        <button class="btn-add-watch" onclick="openAddModal()">
            ➕ Thêm trang
        </button>
    </div>
</div>

<!-- ──────────────────── WATCH LIST TABLE ──────────────────────────── -->
<div class="sw-table-wrap">
    <div id="table-loading" class="sw-loading">
        <span class="spin-ring-dark"></span> Đang tải danh sách...
    </div>

    <div id="empty-state" class="sw-empty" style="display:none;">
        <div class="sw-empty-icon">👁️</div>
        <h3>Chưa theo dõi trang nào</h3>
        <p>Nhấn nút "+ Thêm trang" để bắt đầu theo dõi bài viết từ Fanpage, Profile hoặc Nhóm</p>
        <button class="btn-add-watch" onclick="openAddModal()">➕ Thêm trang đầu tiên</button>
    </div>

    <table class="sw-table" id="watch-table" style="display:none;">
        <thead>
            <tr>
                <th>Trang theo dõi</th>
                <th>Số bài viết</th>
                <th>Nhãn</th>
                <th>Làm mới sau</th>
                <th style="text-align:center;">Thao tác</th>
            </tr>
        </thead>
        <tbody id="watch-tbody"></tbody>
    </table>
</div>

<!-- ════════════════ ADD MODAL ════════════════════════════════════════ -->
<div class="sw-modal-overlay" id="add-overlay">
    <div class="sw-add-modal">
        <button class="sw-modal-close" onclick="closeAddModal()">✕</button>
        <h2>👁️ Thêm trang theo dõi</h2>
        <p class="sw-modal-sub">Nhập link Fanpage, Profile hoặc Nhóm Facebook để bắt đầu thu thập bài viết</p>

        <!-- URL Input -->
        <div class="sw-form-group">
            <label>🔗 Đường link trang Facebook</label>
            <input type="url" id="add-url" placeholder="https://www.facebook.com/pagename hoặc profile/group link...">
        </div>


        <!-- Label -->
        <div class="sw-form-group">
            <label>🏷️ Chọn nhãn</label>
            <div class="sw-label-row">
                <select id="add-label-select">
                    <option value="">-- Chọn nhãn --</option>
                </select>
                <button class="btn-new-label" onclick="toggleNewLabel()">✏️ Tạo mới</button>
            </div>
            <input type="text" id="add-label-new" class="sw-new-label-input" placeholder="Nhập tên nhãn mới (ví dụ: Marketing)">
        </div>

        <!-- Post Limit -->
        <div class="sw-form-group">
            <label>📊 Số bài muốn quét</label>
            <div class="sw-limit-pills">
                <button class="sw-limit-pill" onclick="selectLimit(10)">10 bài</button>
                <button class="sw-limit-pill selected" onclick="selectLimit(20)">20 bài</button>
                <button class="sw-limit-pill" onclick="selectLimit(50)">50 bài</button>
                <button class="sw-limit-pill" onclick="selectLimit(100)">100 bài</button>
                <button class="sw-limit-pill" id="pill-custom" onclick="selectLimit('custom')">Tùy chỉnh</button>
            </div>
            <input type="number" id="add-custom-limit" style="display:none; margin-top:10px; width:160px; padding:9px 12px; border:1.5px solid var(--border-color); border-radius:8px; background:var(--bg-color); color:var(--text-main); font-size:13px; outline:none;" min="1" max="500" placeholder="Số bài (1-500)">
            <input type="hidden" id="add-limit" value="20">
        </div>

        <!-- Auto Refresh -->
        <div class="sw-form-group">
            <div class="sw-toggle-row">
                <input type="checkbox" id="add-auto-refresh" checked>
                <label class="sw-toggle-label" for="add-auto-refresh">
                    🔄 Tự động làm mới
                    <span>— Hệ thống tự quét lại bài viết sau mỗi 10 giờ</span>
                </label>
            </div>
        </div>

        <button class="btn-submit-watch" id="btn-submit-add" onclick="submitAddWatch()">
            <span>➕</span> Thêm & Quét bài viết
        </button>
    </div>
</div>

<!-- ════════════════ POSTS VIEWER MODAL ══════════════════════════════ -->
<div class="sw-viewer-overlay" id="viewer-overlay">
    <div class="sw-viewer-modal">
        <!-- Header -->
        <div class="sw-viewer-header">
            <div class="sw-viewer-avatar" id="viewer-avatar">📄</div>
            <div class="sw-viewer-title">
                <h3 id="viewer-page-name">Trang đang tải...</h3>
                <p id="viewer-page-sub">Xem bài viết đã quét</p>
            </div>
            <button class="sw-viewer-close" onclick="closeViewer()">✕</button>
        </div>

        <!-- Filters -->
        <div class="sw-viewer-filters">
            <div class="sw-search-wrap">
                <input type="text" id="viewer-search" placeholder="Nhập nội dung fanpage cần tìm ..." oninput="applyViewerFilters()">
            </div>
            <div class="sw-filter-group">
                <label>❤️ Thích ≥</label>
                <input type="number" id="filter-likes" placeholder="0" min="0" oninput="applyViewerFilters()">
            </div>
            <div class="sw-filter-group">
                <label>💬 Bình luận ≥</label>
                <input type="number" id="filter-comments" placeholder="0" min="0" oninput="applyViewerFilters()">
            </div>
            <button class="btn-apply-filter" onclick="applyViewerFilters()">🔍 Lọc</button>
        </div>

        <!-- Info Bar -->
        <div class="sw-viewer-info">
            <span id="viewer-post-count">Đang tải...</span>
            <span id="viewer-filter-info" style="color:var(--text-muted);"></span>
        </div>

        <!-- Posts Grid -->
        <div class="sw-posts-grid" id="viewer-posts-grid">
            <div class="sw-no-posts">
                <span class="icon">📋</span>
                Chưa có bài viết nào
            </div>
        </div>

        <!-- Footer -->
        <div class="sw-viewer-footer">
            <span id="viewer-footer-info">—</span>
            <button class="sw-btn sw-btn-scan" id="viewer-scan-btn" onclick="rescanFromViewer()">
                🔄 Cập nhật bài mới
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="sw-toast" id="sw-toast"></div>

<script>
// ════════════════════════════════════════════════════════════════════
//  STATE
// ════════════════════════════════════════════════════════════════════
let watchTargets   = [];
let viewerTarget   = null;
let allViewerPosts = [];
let selectedLimit  = 20;

// ════════════════════════════════════════════════════════════════════
//  INIT — Load targets on page load
// ════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    loadTargets();
    loadLabels();
});

// ════════════════════════════════════════════════════════════════════
//  LOAD TARGETS
// ════════════════════════════════════════════════════════════════════
function loadTargets() {
    document.getElementById('table-loading').style.display = 'flex';
    document.getElementById('empty-state').style.display   = 'none';
    document.getElementById('watch-table').style.display   = 'none';

    fetchAction('list', {}, 'GET').then(res => {
        document.getElementById('table-loading').style.display = 'none';
        if (res.status !== 'success') { showToast('error', res.message); return; }

        watchTargets = res.targets || [];
        renderTargets(watchTargets);
        document.getElementById('total-count').textContent = watchTargets.length;
    }).catch(() => {
        document.getElementById('table-loading').style.display = 'none';
        showToast('error', 'Không thể tải danh sách theo dõi.');
    });
}

function renderTargets(targets) {
    if (!targets.length) {
        document.getElementById('empty-state').style.display = 'block';
        document.getElementById('watch-table').style.display = 'none';
        return;
    }
    document.getElementById('watch-table').style.display = 'table';

    const now   = Date.now();
    const tbody = document.getElementById('watch-tbody');
    tbody.innerHTML = targets.map(t => {
        const avatar    = t.page_avatar
            ? `<div class="sw-avatar"><img src="${esc(t.page_avatar)}" alt="${esc(t.page_name)}" onerror="this.parentElement.innerHTML='<span style=\\'font-size:20px;\\'>📄</span>'"></div>`
            : `<div class="sw-avatar-fallback">📄</div>`;
        const addedDate = t.created_at ? formatDate(t.created_at) : '';
        const labelHtml = t.label
            ? `<span class="sw-label-badge">${esc(t.label)}</span>`
            : `<span style="color:var(--text-muted);font-size:12px;">—</span>`;

        // Compute refresh info
        let refreshHtml = '';
        if (t.auto_refresh == 1 && t.last_scanned_at) {
            const scanned = new Date(t.last_scanned_at.replace(' ', 'T')).getTime();
            const next    = scanned + 10 * 3600 * 1000;
            const diff    = next - now;
            if (diff > 0) {
                const h = Math.floor(diff / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                refreshHtml = `<div class="sw-refresh-info">🔄 Còn <span class="refresh-time">${h}h ${m}m</span></div>`;
            } else {
                refreshHtml = `<div class="sw-refresh-info" style="color:#10b981;">✅ Sẵn sàng làm mới</div>`;
            }
        } else if (t.auto_refresh == 0) {
            refreshHtml = `<div class="sw-refresh-off">—</div>`;
        } else {
            refreshHtml = `<div class="sw-refresh-info">Chưa quét</div>`;
        }

        return `
        <tr id="row-${t.id}">
            <td>
                <div class="sw-page-info">
                    ${avatar}
                    <div>
                        <div class="sw-page-name">${esc(t.page_name)}</div>
                        <div class="sw-page-date">Ngày thêm: ${addedDate}</div>
                    </div>
                </div>
            </td>
            <td>
                <div class="sw-post-count">
                    <strong>${t.post_count || 0}</strong>
                    <span>bài viết</span>
                </div>
            </td>
            <td>${labelHtml}</td>
            <td>${refreshHtml}</td>
            <td>
                <div class="sw-actions">
                    <button class="sw-btn sw-btn-view" onclick="openViewer(${t.id})">
                        👁 Xem
                    </button>
                    <button class="sw-btn sw-btn-scan" id="scan-btn-${t.id}" onclick="scanTarget(${t.id})">
                        🔄 Cập nhật
                    </button>
                    <button class="sw-btn sw-btn-delete" onclick="deleteTarget(${t.id}, '${esc(t.page_name)}')">
                        🗑 Xóa
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ════════════════════════════════════════════════════════════════════
//  ADD MODAL
// ════════════════════════════════════════════════════════════════════
function openAddModal() {
    document.getElementById('add-overlay').classList.add('active');
    document.getElementById('add-url').focus();
}
function closeAddModal() {
    document.getElementById('add-overlay').classList.remove('active');
    resetAddForm();
}

function resetAddForm() {
    document.getElementById('add-url').value = '';
    document.getElementById('add-label-new').style.display = 'none';
    document.getElementById('add-label-new').value = '';
    document.getElementById('add-custom-limit').style.display = 'none';
    document.getElementById('add-limit').value = 20;
    document.getElementById('add-auto-refresh').checked = true;
    selectLimit(20);
}

// ── Label handling ──
function loadLabels() {
    fetchAction('get_labels', {}, 'GET').then(res => {
        if (res.status !== 'success') return;
        const sel = document.getElementById('add-label-select');
        sel.innerHTML = '<option value="">-- Chọn nhãn --</option>';
        (res.labels || []).forEach(lb => {
            sel.innerHTML += `<option value="${esc(lb)}">${esc(lb)}</option>`;
        });
    });
}

let showingNewLabel = false;
function toggleNewLabel() {
    showingNewLabel = !showingNewLabel;
    const inp = document.getElementById('add-label-new');
    const sel = document.getElementById('add-label-select');
    if (showingNewLabel) {
        inp.style.display = 'block';
        sel.style.display = 'none';
        inp.focus();
    } else {
        inp.style.display = 'none';
        sel.style.display = 'block';
    }
}

// ── Limit pills ──
function selectLimit(val) {
    document.querySelectorAll('.sw-limit-pill').forEach(p => p.classList.remove('selected'));
    const customInp = document.getElementById('add-custom-limit');

    if (val === 'custom') {
        document.getElementById('pill-custom').classList.add('selected');
        customInp.style.display = 'block';
        customInp.focus();
        customInp.oninput = () => {
            document.getElementById('add-limit').value = customInp.value;
        };
        return;
    }
    customInp.style.display = 'none';
    selectedLimit = val;
    document.getElementById('add-limit').value = val;

    document.querySelectorAll('.sw-limit-pill').forEach(p => {
        if (p.textContent.trim() === `${val} bài`) p.classList.add('selected');
    });
}

// ── Submit ──
function submitAddWatch() {
    const url = document.getElementById('add-url').value.trim();
    if (!url) { showToast('error', 'Vui lòng nhập đường link trang Facebook.'); return; }

    const labelSel = document.getElementById('add-label-select').value;
    const labelNew = document.getElementById('add-label-new').value.trim();
    const label    = showingNewLabel ? labelNew : labelSel;
    const limit    = document.getElementById('add-limit').value || 20;
    const autoRef  = document.getElementById('add-auto-refresh').checked ? 1 : 0;

    const btn = document.getElementById('btn-submit-add');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin-ring"></span> Đang quét bài viết...';

    fetchAction('add', {
        page_url: url, label, post_limit: limit,
        auto_refresh: autoRef ? 'on' : ''
    }).then(res => {
        btn.disabled = false;
        btn.innerHTML = '<span>➕</span> Thêm & Quét bài viết';
        if (res.status !== 'success') { showToast('error', res.message); return; }

        showToast('success', `✅ ${res.message}`);
        closeAddModal();
        loadTargets();
        loadLabels();
    }).catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<span>➕</span> Thêm & Quét bài viết';
        showToast('error', 'Lỗi kết nối. Vui lòng thử lại.');
    });
}

// Close modal on overlay click
document.getElementById('add-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});

// ════════════════════════════════════════════════════════════════════
//  SCAN (Update) Target
// ════════════════════════════════════════════════════════════════════
function scanTarget(targetId) {
    const btn = document.getElementById(`scan-btn-${targetId}`);
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spin-ring-dark"></span> Đang quét...';

    fetchAction('scan', { target_id: targetId }).then(res => {
        btn.disabled = false;
        btn.innerHTML = '🔄 Cập nhật';
        if (res.status !== 'success') { showToast('error', res.message); return; }
        showToast('success', `✅ ${res.message}`);
        loadTargets();
    }).catch(() => {
        btn.disabled = false;
        btn.innerHTML = '🔄 Cập nhật';
        showToast('error', 'Lỗi kết nối khi quét.');
    });
}

// ════════════════════════════════════════════════════════════════════
//  DELETE Target
// ════════════════════════════════════════════════════════════════════
function deleteTarget(targetId, pageName) {
    if (!confirm(`Xóa trang "${pageName}" khỏi danh sách theo dõi?\nTất cả bài viết đã quét cũng sẽ bị xóa.`)) return;

    fetchAction('delete', { target_id: targetId }).then(res => {
        if (res.status !== 'success') { showToast('error', res.message); return; }
        showToast('success', '🗑 Đã xóa trang theo dõi.');
        loadTargets();
    }).catch(() => showToast('error', 'Lỗi khi xóa.'));
}

// ════════════════════════════════════════════════════════════════════
//  POSTS VIEWER MODAL
// ════════════════════════════════════════════════════════════════════
function openViewer(targetId) {
    viewerTarget = targetId;
    allViewerPosts = [];

    // Reset UI
    document.getElementById('viewer-search').value = '';
    document.getElementById('filter-likes').value = '';
    document.getElementById('filter-comments').value = '';
    document.getElementById('viewer-posts-grid').innerHTML = `
        <div style="grid-column:1/-1;" class="sw-loading">
            <span class="spin-ring-dark"></span> Đang tải bài viết...
        </div>`;

    document.getElementById('viewer-overlay').classList.add('active');

    fetchAction('get_posts', { target_id: targetId }, 'GET').then(res => {
        if (res.status !== 'success') {
            showToast('error', res.message);
            closeViewer();
            return;
        }

        const target = res.target || {};
        allViewerPosts = res.posts || [];

        // Update header
        document.getElementById('viewer-page-name').textContent =
            target.page_name + ` (${allViewerPosts.length} bài viết)`;
        document.getElementById('viewer-page-sub').textContent =
            target.label ? `Nhãn: ${target.label}` : 'Không có nhãn';

        // Avatar
        const avatarEl = document.getElementById('viewer-avatar');
        if (target.avatar) {
            avatarEl.innerHTML = `<img src="${esc(target.avatar)}" alt="avatar" onerror="this.parentElement.innerHTML='📄'">`;
        } else {
            avatarEl.innerHTML = '📄';
        }

        document.getElementById('viewer-footer-info').textContent =
            `Tổng: ${allViewerPosts.length} bài viết`;
        document.getElementById('viewer-scan-btn').setAttribute('data-target-id', targetId);

        renderViewerPosts(allViewerPosts);
    }).catch(() => {
        showToast('error', 'Lỗi tải bài viết.');
        closeViewer();
    });
}

function closeViewer() {
    document.getElementById('viewer-overlay').classList.remove('active');
    viewerTarget = null;
}

// Close viewer on overlay click
document.getElementById('viewer-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeViewer();
});

function applyViewerFilters() {
    const kw       = (document.getElementById('viewer-search').value || '').toLowerCase().trim();
    const minLikes = parseInt(document.getElementById('filter-likes').value) || 0;
    const minComm  = parseInt(document.getElementById('filter-comments').value) || 0;

    let filtered = allViewerPosts;
    if (kw)       filtered = filtered.filter(p => (p.content || '').toLowerCase().includes(kw));
    if (minLikes) filtered = filtered.filter(p => (p.likes || 0) >= minLikes);
    if (minComm)  filtered = filtered.filter(p => (p.comments || 0) >= minComm);

    renderViewerPosts(filtered);
    document.getElementById('viewer-filter-info').textContent =
        filtered.length < allViewerPosts.length
            ? `Hiển thị ${filtered.length}/${allViewerPosts.length} bài`
            : '';
}

function renderViewerPosts(posts) {
    const grid = document.getElementById('viewer-posts-grid');
    document.getElementById('viewer-post-count').textContent =
        `Hiển thị ${posts.length} bài viết`;

    if (!posts.length) {
        grid.innerHTML = `
            <div class="sw-no-posts">
                <span class="icon">🔍</span>
                Không tìm thấy bài viết phù hợp. Thử thay đổi bộ lọc.
            </div>`;
        return;
    }

    const pageName = document.getElementById('viewer-page-name').textContent.replace(/\s*\(\d+ bài viết\)$/, '');

    grid.innerHTML = posts.map(p => {
        const dateStr = p.post_time ? formatDate(p.post_time) : '—';
        const imgHtml = p.image_url
            ? `<img class="sw-post-image" src="${esc(p.image_url)}" alt="post image" onerror="this.style.display='none'">`
            : '';
        const postUrl = p.post_url || '#';

        return `
        <div class="sw-post-card">
            <div class="sw-post-card-header">
                <div class="sw-post-card-avatar">📄</div>
                <div class="sw-post-card-meta">
                    <div class="sw-post-card-name">${esc(pageName)}</div>
                    <div class="sw-post-card-date">${esc(dateStr)}</div>
                </div>
                <span class="sw-post-fb-icon">ℹ️</span>
            </div>
            <div class="sw-post-content">${esc(p.content || '(Không có nội dung)')}</div>
            ${imgHtml}
            <div class="sw-post-stats">
                <div class="sw-stat-item">❤️ <strong>${fmtNum(p.likes)}</strong></div>
                <div class="sw-stat-item">💬 <strong>${fmtNum(p.comments)}</strong></div>
                <div class="sw-stat-item">🔗 <strong>${fmtNum(p.shares)}</strong></div>
            </div>
            <div class="sw-post-actions">
                <button class="sw-post-act-btn" onclick="window.open('${esc(postUrl)}','_blank')">👁 Xem chi tiết</button>
                <button class="sw-post-act-btn" onclick="draftPost(${JSON.stringify(esc(p.content))})">✏️ Soạn thảo</button>
                <button class="sw-post-act-btn" onclick="schedulePost(${JSON.stringify(esc(p.content))})">📅 Lên lịch</button>
            </div>
        </div>`;
    }).join('');
}

function rescanFromViewer() {
    const targetId = viewerTarget;
    if (!targetId) return;
    const btn = document.getElementById('viewer-scan-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin-ring-dark"></span> Đang quét...';

    fetchAction('scan', { target_id: targetId }).then(res => {
        btn.disabled = false;
        btn.innerHTML = '🔄 Cập nhật bài mới';
        if (res.status !== 'success') { showToast('error', res.message); return; }
        showToast('success', `✅ ${res.message}`);
        // Reload posts
        openViewer(targetId);
        // Also refresh main table in background
        loadTargets();
    }).catch(() => {
        btn.disabled = false;
        btn.innerHTML = '🔄 Cập nhật bài mới';
        showToast('error', 'Lỗi khi quét.');
    });
}

// ════════════════════════════════════════════════════════════════════
//  POST ACTIONS
// ════════════════════════════════════════════════════════════════════
function draftPost(content) {
    // Navigate to posts page with content pre-filled
    window.open(`posts.php?draft=${encodeURIComponent(content)}`, '_blank');
}
function schedulePost(content) {
    window.open(`posts.php`, '_blank');
}

// ════════════════════════════════════════════════════════════════════
//  UTILITIES
// ════════════════════════════════════════════════════════════════════
function fetchAction(action, data = {}, method = 'POST') {
    const url = 'actions/special_watch_action.php';
    const parseJSON = r => r.text().then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('[special_watch] Server response (not JSON):', text.substring(0, 500));
            // Trả về JSON lỗi thay vì throw để catch bên ngoài không bắt
            return { status: 'error', message: 'Lỗi server: ' + text.replace(/<[^>]*>/g, '').substring(0, 120).trim() };
        }
    });
    if (method === 'GET') {
        const params = new URLSearchParams({ action, ...data });
        return fetch(`${url}?${params}`).then(parseJSON);
    }
    const form = new FormData();
    form.append('action', action);
    for (const [k, v] of Object.entries(data)) form.append(k, v);
    return fetch(url, { method: 'POST', body: form }).then(parseJSON);
}

function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function fmtNum(n) {
    if (!n && n !== 0) return '—';
    n = parseInt(n);
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000)    return (n / 1000).toFixed(1) + 'K';
    return n.toString();
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d)) return dateStr;
    return d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function showToast(type, msg) {
    const t = document.getElementById('sw-toast');
    t.className = `sw-toast ${type}`;
    t.textContent = msg;
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3500);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
