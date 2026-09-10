<?php
// ─── Normal page load — include header ────────────────────────────────────────
$current_page = 'tiktok_search';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ─── TikTok Search & Extension Integration ──────────────────────────────────── */
.tiktok-hero {
    background: linear-gradient(135deg, #010101 0%, #1a0533 40%, #2d0b55 100%);
    border-radius: 16px; padding: 28px 32px; margin-bottom: 24px;
    display: flex; align-items: center; gap: 20px;
    position: relative; overflow: hidden;
}
.tiktok-hero::before {
    content: ''; position: absolute; top: -30px; right: -30px;
    width: 180px; height: 180px;
    background: radial-gradient(circle, rgba(105,0,210,0.4) 0%, transparent 70%);
    pointer-events: none;
}
.tiktok-hero::after {
    content: ''; position: absolute; bottom: -20px; left: 40%;
    width: 120px; height: 120px;
    background: radial-gradient(circle, rgba(254,44,85,0.3) 0%, transparent 70%);
    pointer-events: none;
}
.tiktok-logo-wrap {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #fe2c55, #25f4ee);
    border-radius: 14px; display: flex; align-items: center; justify-content: center;
    font-size: 28px; flex-shrink: 0; box-shadow: 0 4px 16px rgba(254,44,85,0.5);
}
.tiktok-hero-text h1 { font-size: 22px; font-weight: 700; color: #fff; margin: 0 0 4px; }
.tiktok-hero-text p  { font-size: 13px; color: rgba(255,255,255,0.65); margin: 0; }

/* Extension Status Banner */
.ext-status-banner {
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 12px; padding: 14px 20px; margin-bottom: 20px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.ext-status-info { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; }
.ext-badge {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px;
    border-radius: 20px; font-size: 12px; font-weight: 700;
}
.ext-badge.active { background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3); }
.ext-badge.inactive { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
.ext-badge.scanning { background: rgba(254,44,85,0.15); color: #fe2c55; border: 1px solid rgba(254,44,85,0.3); }

/* ─── Mode Tabs ─── */
.mode-tabs {
    display: flex; gap: 6px; margin-bottom: 20px;
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 12px; padding: 6px; width: fit-content; flex-wrap: wrap;
}
.mode-tab {
    padding: 10px 20px; border-radius: 8px;
    border: none; background: transparent; color: var(--text-muted);
    font-size: 14px; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; gap: 8px; transition: all 0.2s;
}
.mode-tab.active-profile {
    background: linear-gradient(135deg, #06b6d4, #0891b2);
    color: #fff; box-shadow: 0 4px 12px rgba(6,182,212,0.35);
}
.mode-tab.active-keyword {
    background: linear-gradient(135deg, #fe2c55, #c9134c);
    color: #fff; box-shadow: 0 4px 12px rgba(254,44,85,0.35);
}
.mode-tab.active-hashtag {
    background: linear-gradient(135deg, #6d28d9, #4c1d95);
    color: #fff; box-shadow: 0 4px 12px rgba(109,40,217,0.35);
}
.mode-tab:not([class*="active-"]):hover { color: var(--text-main); }

/* ─── Search Card ─── */
.search-form-card {
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 14px; padding: 24px; margin-bottom: 20px;
}
.search-row  { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.search-field { display: flex; flex-direction: column; gap: 6px; }
.search-field label {
    font-size: 12px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.search-field input[type="text"],
.search-field input[type="number"] {
    padding: 11px 16px; border: 1px solid var(--border-color); border-radius: 8px;
    background: var(--bg-color); color: var(--text-main);
    font-size: 14px; outline: none; transition: border-color 0.2s, box-shadow 0.2s;
}
.search-field input:focus { border-color: #fe2c55; box-shadow: 0 0 0 3px rgba(254,44,85,0.12); }
.search-field.grow { flex: 1; min-width: 260px; }

.btn-start-scan {
    padding: 11px 26px; background: linear-gradient(135deg,#fe2c55,#c9134c);
    color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700;
    cursor:pointer; white-space:nowrap; display:flex; align-items:center; gap:8px;
    transition: transform .15s, box-shadow .15s; box-shadow:0 4px 14px rgba(254,44,85,.4);
}
.btn-start-scan:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(254,44,85,.55); }
.btn-start-scan.profile { background:linear-gradient(135deg,#06b6d4,#0891b2); box-shadow:0 4px 14px rgba(6,182,212,.4); }
.btn-start-scan.hashtag { background:linear-gradient(135deg,#7c3aed,#5b21b6); box-shadow:0 4px 14px rgba(124,58,237,.4); }

.btn-stop-scan {
    padding: 11px 20px; background: linear-gradient(135deg, #ef4444, #dc2626);
    color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700;
    cursor:pointer; display:none; align-items:center; gap:8px;
}

/* ─── URL Import Box ─── */
.import-card {
    background: var(--card-bg); border: 1px solid var(--border-color);
    border-radius: 14px; padding: 20px 24px; margin-bottom: 20px;
}
.import-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 10px; flex-wrap: wrap; gap: 10px;
}
.import-title { font-size: 14px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
.import-textarea {
    width: 100%; height: 90px; padding: 12px;
    border: 1px solid var(--border-color); border-radius: 10px;
    background: var(--bg-color); color: var(--text-main);
    font-family: monospace; font-size: 13px; outline: none;
    resize: vertical; transition: border-color 0.2s;
}
.import-textarea:focus { border-color: #25f4ee; box-shadow: 0 0 0 3px rgba(37,244,238,0.12); }

.btn-import {
    padding: 9px 20px; background: linear-gradient(135deg, #25f4ee, #0dcfca);
    color: #010101; border: none; border-radius: 8px; font-size: 13px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.15s;
    margin-top: 10px; box-shadow: 0 3px 10px rgba(37,244,238,0.3);
}

/* ─── Column Filter ─── */
.col-filter-wrap {
    margin-bottom: 16px; background: var(--card-bg);
    border: 1px solid var(--border-color); border-radius: 10px; padding: 14px 18px;
}
.col-filter-label { font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; }
.col-filter-list  { display:flex; flex-wrap:wrap; gap:8px; }
.col-chip {
    display:flex; align-items:center; gap:5px; padding:5px 12px;
    border:1px solid var(--border-color); border-radius:20px;
    font-size:12px; cursor:pointer; background:var(--bg-color); color:var(--text-main);
    transition:all .15s; user-select:none;
}
.col-chip.active { background:linear-gradient(135deg,#1a0533,#2d0b55); border-color:#8b5cf6; color:#fff; }
.col-chip input[type="checkbox"] { display:none; }

/* ─── Results toolbar ─── */
.results-toolbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
.results-info { font-size:13px; color:var(--text-muted); }
.results-info strong { color:var(--text-main); }
.toolbar-btns { display: flex; gap: 8px; flex-wrap: wrap; }

.btn-action-tool {
    padding:8px 16px; background:var(--card-bg); color:var(--text-main);
    border:1px solid var(--border-color); border-radius:8px; font-size:13px; font-weight:600;
    cursor:pointer; display:flex; align-items:center; gap:6px; transition:all .15s;
}
.btn-action-tool:hover { border-color:#25f4ee; color:#25f4ee; }
.btn-action-tool.primary {
    background: linear-gradient(135deg, #fe2c55, #c9134c); color:#fff; border:none;
    box-shadow: 0 3px 10px rgba(254,44,85,0.3);
}
.btn-action-tool.primary:hover { box-shadow: 0 5px 14px rgba(254,44,85,0.5); }
.btn-action-tool:disabled { opacity:.5; cursor:not-allowed; }

/* ─── Table ─── */
.tiktok-table-wrap { overflow-x:auto; border:1px solid var(--border-color); border-radius:12px; background:var(--card-bg); }
.tiktok-table { width:100%; border-collapse:collapse; min-width:700px; font-size:13px; }
.tiktok-table thead th {
    padding:12px 14px; background:linear-gradient(135deg,#0f0f0f,#1e0340);
    color:#ccc; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;
    border-bottom:1px solid var(--border-color); white-space:nowrap; cursor:pointer; user-select:none;
}
.tiktok-table thead th:hover { color:#fe2c55; }
.tiktok-table thead th.sort-asc::after  { content:' ▲'; color:#fe2c55; font-size:10px; }
.tiktok-table thead th.sort-desc::after { content:' ▼'; color:#fe2c55; font-size:10px; }
.tiktok-table tbody tr { border-bottom:1px solid var(--border-color); transition:background .1s; }
.tiktok-table tbody tr:hover { background:rgba(254,44,85,.04); }
.tiktok-table tbody tr:last-child { border-bottom:none; }
.tiktok-table td { padding:11px 14px; color:var(--text-main); vertical-align:middle; }
.tiktok-table td.col-checkbox { width:38px; text-align:center; }
.tiktok-table td.col-title { max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tiktok-table td.col-title a { color:var(--primary-color); text-decoration:none; font-weight:500; }
.tiktok-table td.col-title a:hover { text-decoration:underline; }
.tiktok-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-views    { background:rgba(139,92,246,.15); color:#8b5cf6; }
.badge-likes    { background:rgba(254,44,85,.13);  color:#fe2c55; }
.badge-comments { background:rgba(59,130,246,.13); color:#3b82f6; }
.badge-shares   { background:rgba(16,185,129,.13); color:#10b981; }

.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state .empty-icon { font-size:48px; margin-bottom:12px; }

#copy-toast {
    position:fixed; bottom:28px; right:20px; background:#10b981; color:#fff;
    padding:12px 22px; border-radius:10px; font-size:14px; font-weight:600;
    box-shadow:0 4px 18px rgba(16,185,129,.45); display:none; z-index:9999;
}
</style>

<!-- Hero -->
<div class="tiktok-hero">
    <div class="tiktok-logo-wrap">🎵</div>
    <div class="tiktok-hero-text">
        <h1>TikTok URL Collector & Search</h1>
        <p>Tự động mở trình duyệt & cuộn trang thu thập URL video TikTok (Kênh, Từ khóa, Hashtag) · Quản lý & Xuất dữ liệu</p>
    </div>
</div>

<!-- Extension Status Banner -->
<div class="ext-status-banner">
    <div class="ext-status-info">
        <span>Trạng thái Extension:</span>
        <span id="ext-status-badge" class="ext-badge inactive">⚪ Đang kiểm tra kết nối Extension...</span>
    </div>
    <div id="ext-help-text" style="font-size:12px;color:var(--text-muted);">
        Chưa phát hiện Extension <code>exgeturltiktok</code>. Hãy cài đặt Extension để sử dụng tính năng quét tự động.
    </div>
</div>

<!-- Mode Tabs -->
<div class="mode-tabs">
    <button class="mode-tab active-profile" id="tab-profile" onclick="switchMode('profile')">👤 1. Kênh (Profile)</button>
    <button class="mode-tab" id="tab-keyword" onclick="switchMode('keyword')">🔍 2. Từ khóa (Search)</button>
    <button class="mode-tab" id="tab-hashtag" onclick="switchMode('hashtag')">🏷️ 3. Hashtag</button>
</div>

<!-- Search Card: PROFILE -->
<div class="search-form-card" id="form-profile">
    <div class="search-row">
        <div class="search-field grow">
            <label for="profile-input">Username Kênh TikTok (ví dụ: copphavietcom)</label>
            <input type="text" id="profile-input" placeholder="Nhập username..." value="copphavietcom" autocomplete="off">
        </div>
        <div class="search-field">
            <label for="profile-limit">Số bài viết / URL muốn quét</label>
            <input type="number" id="profile-limit" value="50" min="1" max="500" style="width:140px;">
        </div>
        <div style="display:flex; align-items:flex-end; gap:8px;">
            <button class="btn-start-scan profile" id="btn-start-profile" onclick="startScan('profile')">
                <span>🚀</span> Bắt đầu quét URL
            </button>
            <button class="btn-stop-scan" id="btn-stop-profile" onclick="stopScan()">
                <span>⏸</span> Dừng quét
            </button>
        </div>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0;">
        Link quét mục tiêu: <code id="profile-url-preview" style="color:#06b6d4;">https://www.tiktok.com/@copphavietcom</code>
    </p>
</div>

<!-- Search Card: KEYWORD -->
<div class="search-form-card" id="form-keyword" style="display:none;">
    <div class="search-row">
        <div class="search-field grow">
            <label for="kw-input">Từ khóa tìm kiếm (ví dụ: giàn giáo)</label>
            <input type="text" id="kw-input" placeholder="Nhập từ khóa tìm kiếm..." value="giàn giáo" autocomplete="off">
        </div>
        <div class="search-field">
            <label for="kw-limit">Số bài viết / URL muốn quét</label>
            <input type="number" id="kw-limit" value="50" min="1" max="500" style="width:140px;">
        </div>
        <div style="display:flex; align-items:flex-end; gap:8px;">
            <button class="btn-start-scan" id="btn-start-keyword" onclick="startScan('keyword')">
                <span>🚀</span> Bắt đầu quét URL
            </button>
            <button class="btn-stop-scan" id="btn-stop-keyword" onclick="stopScan()">
                <span>⏸</span> Dừng quét
            </button>
        </div>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0;">
        Link quét mục tiêu: <code id="kw-url-preview" style="color:#fe2c55;">https://www.tiktok.com/search/video?q=gi%C3%A0n%20gi%C3%A1o</code>
    </p>
</div>

<!-- Search Card: HASHTAG -->
<div class="search-form-card" id="form-hashtag" style="display:none;">
    <div class="search-row">
        <div class="search-field grow">
            <label for="ht-input">Tên Hashtag (ví dụ: copphaviet - không cần #)</label>
            <input type="text" id="ht-input" placeholder="Nhập hashtag..." value="copphaviet" autocomplete="off">
        </div>
        <div class="search-field">
            <label for="ht-limit">Số bài viết / URL muốn quét</label>
            <input type="number" id="ht-limit" value="50" min="1" max="500" style="width:140px;">
        </div>
        <div style="display:flex; align-items:flex-end; gap:8px;">
            <button class="btn-start-scan hashtag" id="btn-start-hashtag" onclick="startScan('hashtag')">
                <span>🚀</span> Bắt đầu quét URL
            </button>
            <button class="btn-stop-scan" id="btn-stop-hashtag" onclick="stopScan()">
                <span>⏸</span> Dừng quét
            </button>
        </div>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0;">
        Link quét mục tiêu: <code id="ht-url-preview" style="color:#8b5cf6;">https://www.tiktok.com/tag/copphaviet</code>
    </p>
</div>

<!-- URL Import Box (Fallback/Manual Paste) -->
<div class="import-card">
    <div class="import-head">
        <div class="import-title">
            <span>📥</span> Nhập / Dán thủ công danh sách URL (Nếu không dùng Extension)
        </div>
    </div>
    <textarea id="import-textarea" class="import-textarea" placeholder="Dán danh sách URL TikTok vào đây (mỗi URL một dòng)..."></textarea>
    <button class="btn-import" onclick="parseAndAddUrls()">⚡ Phân tích & Thêm vào danh sách</button>
</div>

<!-- Column Filter Chips -->
<div class="col-filter-wrap" id="col-filter-wrap" style="display:none;">
    <div class="col-filter-label">🎛 Hiển thị cột</div>
    <div class="col-filter-list" id="col-chips"></div>
</div>

<!-- Results Toolbar & Table -->
<div id="results-section" style="display:none;">
    <div class="results-toolbar">
        <div class="results-info" id="result-count">Đã quét <strong>0</strong> video</div>
        <div class="toolbar-btns">
            <button class="btn-action-tool primary" id="btn-copy" onclick="copySelectedUrls()" disabled>
                📋 Copy URL đã chọn (<span id="selected-count">0</span>)
            </button>
            <button class="btn-action-tool" onclick="exportTXT()">📥 Xuất TXT</button>
            <button class="btn-action-tool" onclick="exportCSV()">📊 Xuất CSV</button>
            <button class="btn-action-tool" onclick="clearAllTableData()" style="color:#ef4444;">🗑️ Xóa tất cả</button>
        </div>
    </div>
    <div class="tiktok-table-wrap">
        <table class="tiktok-table" id="tiktok-table">
            <thead id="table-head"></thead>
            <tbody id="table-body"></tbody>
        </table>
    </div>
</div>

<!-- Empty State -->
<div id="empty-state" class="empty-state">
    <div class="empty-icon">🎵</div>
    <p>Chưa có URL video nào trong danh sách. Hãy nhập thông tin Kênh, Từ khóa hoặc Hashtag ở trên rồi bấm <strong>🚀 Bắt đầu quét URL</strong>.</p>
</div>

<!-- Toast -->
<div id="copy-toast">✅ Đã copy URL vào clipboard!</div>

<script>
// ─── Column definitions ────────────────────────────────────────────────────────
const COLUMNS = [
    { key: 'checkbox',      label: '☑',                  sortable: false, visible: true, special: 'checkbox' },
    { key: 'title',         label: 'Tiêu đề / Nội dung', sortable: true,  visible: true },
    { key: 'author',        label: 'Tác giả',            sortable: true,  visible: true },
    { key: 'play_count',    label: '▶ Views',             sortable: true,  visible: true },
    { key: 'digg_count',    label: '❤ Thích',            sortable: true,  visible: true },
    { key: 'comment_count', label: '💬 Bình luận',        sortable: true,  visible: true },
    { key: 'share_count',   label: '🔗 Chia sẻ',         sortable: true,  visible: true },
    { key: 'url',           label: 'Link Video',         sortable: false, visible: true },
    { key: 'create_time',   label: '📅 Ngày đăng',       sortable: true,  visible: true },
];

// ─── State ────────────────────────────────────────────────────────────────────
let allVideos    = [];
let sortKey      = null;
let sortDir      = 'desc';
let colVisible   = {};
let currentMode  = 'profile'; // 'profile' | 'keyword' | 'hashtag'
let isExtConnected = false;
let isScanning     = false;

COLUMNS.forEach(c => { colVisible[c.key] = c.visible; });

// ─── Check Extension Connection ────────────────────────────────────────────────
function checkExtensionConnection() {
    window.postMessage({ source: 'TIKTOK_SEARCH_PAGE', type: 'CHECK_EXT' }, '*');
    setTimeout(() => {
        if (!isExtConnected) {
            const hasAttr = document.documentElement.getAttribute('data-tiktok-ext-installed') === 'true';
            if (hasAttr) setExtensionStatus(true);
        }
    }, 400);
}

function setExtensionStatus(connected, scanning = false) {
    isExtConnected = connected;
    const badge = document.getElementById('ext-status-badge');
    const help = document.getElementById('ext-help-text');

    if (scanning) {
        badge.className = 'ext-badge scanning';
        badge.innerHTML = '🟢 ĐANG QUÉT URL TIKTOK TỰ ĐỘNG...';
        help.textContent = 'Extension đang tự động cuộn trang TikTok để thu thập danh sách URL...';
    } else if (connected) {
        badge.className = 'ext-badge active';
        badge.innerHTML = '🟢 Extension Đã Kết Nối Sẵn Sàng';
        help.textContent = 'Extension exgeturltiktok đã kết nối thành công. Bấm Bắt đầu quét để tự động mở TikTok và cuộn trang!';
    } else {
        badge.className = 'ext-badge inactive';
        badge.innerHTML = '🔴 Chưa Phát Hiện Extension';
        help.textContent = 'Extension exgeturltiktok chưa được tải hoặc chưa kích hoạt. Hãy load extension trong chrome://extensions.';
    }
}

window.addEventListener('message', (event) => {
    if (!event.data || event.data.source !== 'EX_TIKTOK_EXTENSION') return;

    if (event.data.type === 'EXT_PONG') {
        setExtensionStatus(true, isScanning);
    }

    if (event.data.type === 'LIVE_VIDEOS_UPDATE') {
        const liveList = event.data.videos || [];
        updateVideosFromList(liveList);
        setExtensionStatus(true, true);
    }

    if (event.data.type === 'SCAN_FINISHED') {
        isScanning = false;
        const finalContent = event.data.videos || [];
        updateVideosFromList(finalContent);
        setExtensionStatus(true, false);
        toggleScanButtons(false);
        showToast(`✅ Đã hoàn thành quét ${allVideos.length} URL TikTok!`);
    }
});

// ─── Mode Switcher ─────────────────────────────────────────────────────────────
function switchMode(mode) {
    currentMode = mode;
    document.getElementById('form-profile').style.display = mode === 'profile' ? 'block' : 'none';
    document.getElementById('form-keyword').style.display = mode === 'keyword' ? 'block' : 'none';
    document.getElementById('form-hashtag').style.display = mode === 'hashtag' ? 'block' : 'none';

    document.getElementById('tab-profile').className = 'mode-tab' + (mode === 'profile' ? ' active-profile' : '');
    document.getElementById('tab-keyword').className = 'mode-tab' + (mode === 'keyword' ? ' active-keyword' : '');
    document.getElementById('tab-hashtag').className = 'mode-tab' + (mode === 'hashtag' ? ' active-hashtag' : '');
}

// Dynamic preview text
document.getElementById('profile-input').addEventListener('input', e => {
    let val = e.target.value.trim().replace(/^@/, '') || 'copphavietcom';
    document.getElementById('profile-url-preview').textContent = `https://www.tiktok.com/@${val}`;
});
document.getElementById('kw-input').addEventListener('input', e => {
    let val = e.target.value.trim() || 'giàn giáo';
    document.getElementById('kw-url-preview').textContent = `https://www.tiktok.com/search/video?q=${encodeURIComponent(val)}`;
});
document.getElementById('ht-input').addEventListener('input', e => {
    let val = e.target.value.trim().replace(/^#/, '') || 'copphaviet';
    document.getElementById('ht-url-preview').textContent = `https://www.tiktok.com/tag/${encodeURIComponent(val)}`;
});

// ─── Start / Stop Scan via Extension ──────────────────────────────────────────
function startScan(mode) {
    let targetUrl = '';
    let limit = 50;

    if (mode === 'profile') {
        let val = document.getElementById('profile-input').value.trim().replace(/^@/, '') || 'copphavietcom';
        targetUrl = `https://www.tiktok.com/@${encodeURIComponent(val)}`;
        limit = parseInt(document.getElementById('profile-limit').value) || 50;
    } else if (mode === 'keyword') {
        let val = document.getElementById('kw-input').value.trim() || 'giàn giáo';
        targetUrl = `https://www.tiktok.com/search/video?q=${encodeURIComponent(val)}`;
        limit = parseInt(document.getElementById('kw-limit').value) || 50;
    } else if (mode === 'hashtag') {
        let val = document.getElementById('ht-input').value.trim().replace(/^#/, '') || 'copphaviet';
        targetUrl = `https://www.tiktok.com/tag/${encodeURIComponent(val)}`;
        limit = parseInt(document.getElementById('ht-limit').value) || 50;
    }

    isScanning = true;
    toggleScanButtons(true);
    setExtensionStatus(true, true);

    // Send START_SCAN message to bridge.js
    window.postMessage({
        source: 'TIKTOK_SEARCH_PAGE',
        type: 'START_SCAN',
        mode: mode,
        targetUrl: targetUrl,
        limit: limit
    }, '*');

    showToast(`🚀 Extension đang mở TikTok và quét tự động...`);
}

function stopScan() {
    isScanning = false;
    toggleScanButtons(false);
    setExtensionStatus(true, false);

    window.postMessage({
        source: 'TIKTOK_SEARCH_PAGE',
        type: 'STOP_SCAN'
    }, '*');

    showToast('⏸ Đã yêu cầu dừng quét.');
}

function toggleScanButtons(scanning) {
    const startBtns = document.querySelectorAll('.btn-start-scan');
    const stopBtns = document.querySelectorAll('.btn-stop-scan');

    startBtns.forEach(b => b.style.display = scanning ? 'none' : 'flex');
    stopBtns.forEach(b => b.style.display = scanning ? 'flex' : 'none');
}

// ─── Update Table Videos ──────────────────────────────────────────────────────
function updateVideosFromList(list) {
    if (!Array.isArray(list) || list.length === 0) return;

    let added = 0;
    const existingMap = new Map();
    allVideos.forEach(v => existingMap.set(v.url, v));

    list.forEach(v => {
        if (!v || !v.url) return;
        const prev = existingMap.get(v.url);
        const merged = prev ? {
            ...prev,
            title: (v.title && !v.title.startsWith('TikTok Video')) ? v.title : prev.title,
            play_count: Math.max(v.play_count || 0, prev.play_count || 0),
            digg_count: Math.max(v.digg_count || 0, prev.digg_count || 0),
            comment_count: Math.max(v.comment_count || 0, prev.comment_count || 0),
            share_count: Math.max(v.share_count || 0, prev.share_count || 0)
        } : v;

        existingMap.set(v.url, merged);
        if (!prev) added++;
    });

    allVideos = Array.from(existingMap.values());

    if (allVideos.length > 0) {
        document.getElementById('empty-state').style.display = 'none';
        document.getElementById('col-filter-wrap').style.display = 'block';
        document.getElementById('results-section').style.display = 'block';
        buildChips();
        renderTable();
    }
}

// ─── Manual Paste Parser ──────────────────────────────────────────────────────
function parseAndAddUrls() {
    const raw = document.getElementById('import-textarea').value.trim();
    if (!raw) { alert('Vui lòng dán danh sách URL!'); return; }

    const lines = raw.split(/\r?\n/);
    const newList = [];

    lines.forEach(line => {
        const m = line.match(/https?:\/\/(?:www\.)?tiktok\.com\/@([^/]+)\/video\/(\d+)/i);
        if (m) {
            const authorId = m[1];
            const videoId = m[2];
            const cleanUrl = `https://www.tiktok.com/@${authorId}/video/${videoId}`;
            newList.push({
                url: cleanUrl,
                video_id: videoId,
                title: `TikTok Video ${videoId}`,
                author: { unique_id: authorId, nickname: authorId },
                play_count: 0,
                digg_count: 0,
                comment_count: 0,
                share_count: 0,
                create_time: Math.floor(Date.now() / 1000)
            });
        }
    });

    if (newList.length > 0) {
        updateVideosFromList(newList);
        document.getElementById('import-textarea').value = '';
        showToast(`✅ Đã thêm ${newList.length} URL từ ô dán!`);
    } else {
        alert('Không tìm thấy URL TikTok hợp lệ nào!');
    }
}

// ─── Table & Column Management ────────────────────────────────────────────────
function buildChips() {
    const wrap = document.getElementById('col-chips');
    wrap.innerHTML = '';
    COLUMNS.forEach(col => {
        if (col.special === 'checkbox') return;

        const chip = document.createElement('label');
        chip.className = 'col-chip' + (colVisible[col.key] ? ' active' : '');

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = !!colVisible[col.key];

        cb.addEventListener('change', () => {
            colVisible[col.key] = cb.checked;
            chip.classList.toggle('active', cb.checked);
            renderTable();
        });

        chip.appendChild(cb);
        chip.appendChild(document.createTextNode(col.label));
        wrap.appendChild(chip);
    });
}

function buildHeader() {
    const thead = document.getElementById('table-head');
    let html = '<tr>';
    COLUMNS.forEach(col => {
        if (!colVisible[col.key]) return;
        if (col.special === 'checkbox') {
            html += `<th class="col-checkbox">
                <label class="check-all-wrap" title="Chọn tất cả">
                    <input type="checkbox" id="check-all" onchange="toggleAll(this.checked)">
                </label></th>`;
        } else {
            const cls = col.sortable ? (sortKey === col.key ? (sortDir === 'asc' ? 'sort-asc' : 'sort-desc') : '') : '';
            html += `<th class="${cls}" onclick="${col.sortable ? `doSort('${col.key}')` : ''}">${col.label}</th>`;
        }
    });
    html += '</tr>';
    thead.innerHTML = html;
}

function renderTable() {
    buildHeader();
    let videos = [...allVideos];

    if (sortKey) {
        videos.sort((a, b) => {
            let va = a[sortKey], vb = b[sortKey];
            if (sortKey === 'author') { va = a.author?.nickname || a.author?.unique_id || ''; vb = b.author?.nickname || b.author?.unique_id || ''; }
            if (typeof va === 'string') return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
            return sortDir === 'asc' ? va - vb : vb - va;
        });
    }

    const tbody = document.getElementById('table-body');
    if (videos.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${getVisibleCount()}" style="text-align:center;padding:30px;color:var(--text-muted);">Danh sách trống.</td></tr>`;
        document.getElementById('result-count').innerHTML = `Danh sách <strong>0</strong> video`;
        return;
    }

    document.getElementById('result-count').innerHTML = `Đã thu thập <strong>${videos.length}</strong> video TikTok`;

    let html = '';
    videos.forEach((v, idx) => {
        const tiktokUrl  = v.url || (v.video_id ? `https://www.tiktok.com/@${v.author?.unique_id || 'user'}/video/${v.video_id}` : '');
        const title      = (v.title || '').trim() || `TikTok Video ${v.video_id || ''}`;
        const authorName = v.author?.nickname || v.author?.unique_id || '—';
        const dateStr    = v.create_time ? new Date(v.create_time * 1000).toLocaleDateString('vi-VN') : '—';

        html += `<tr data-idx="${idx}">`;
        COLUMNS.forEach(col => {
            if (!colVisible[col.key]) return;
            if (col.special === 'checkbox') {
                html += `<td class="col-checkbox"><input type="checkbox" class="row-check" data-url="${escHtml(tiktokUrl)}" onchange="updateSelectedCount()"></td>`;
            } else if (col.key === 'title') {
                html += `<td class="col-title">${tiktokUrl ? `<a href="${escHtml(tiktokUrl)}" target="_blank" title="${escHtml(title)}">${escHtml(title)}</a>` : escHtml(title)}</td>`;
            } else if (col.key === 'author') {
                html += `<td>@${escHtml(authorName)}</td>`;
            } else if (col.key === 'play_count') {
                html += `<td><span class="tiktok-badge badge-views">${fmtNum(v.play_count)}</span></td>`;
            } else if (col.key === 'digg_count') {
                html += `<td><span class="tiktok-badge badge-likes">${fmtNum(v.digg_count)}</span></td>`;
            } else if (col.key === 'comment_count') {
                html += `<td><span class="tiktok-badge badge-comments">${fmtNum(v.comment_count)}</span></td>`;
            } else if (col.key === 'share_count') {
                html += `<td><span class="tiktok-badge badge-shares">${fmtNum(v.share_count)}</span></td>`;
            } else if (col.key === 'url') {
                html += `<td><a href="${escHtml(tiktokUrl)}" target="_blank" style="color:var(--primary-color);">Xem ↗</a></td>`;
            } else if (col.key === 'create_time') {
                html += `<td style="white-space:nowrap;font-size:12px;color:var(--text-muted);">${dateStr}</td>`;
            } else {
                html += `<td>—</td>`;
            }
        });
        html += '</tr>';
    });

    tbody.innerHTML = html;
    updateSelectedCount();
}

function getVisibleCount() { return COLUMNS.filter(c => colVisible[c.key]).length; }

function doSort(key) {
    if (sortKey === key) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
    else { sortKey = key; sortDir = 'desc'; }
    renderTable();
}

function toggleAll(checked) {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const n = document.querySelectorAll('.row-check:checked').length;
    document.getElementById('selected-count').textContent = n;
    document.getElementById('btn-copy').disabled = n === 0;
}

// ─── Export Utilities ────────────────────────────────────────────────────────
function copySelectedUrls() {
    const urls = [];
    document.querySelectorAll('.row-check:checked').forEach(cb => { if (cb.dataset.url) urls.push(cb.dataset.url); });
    if (!urls.length) return;
    const text = urls.join('\n');
    navigator.clipboard.writeText(text).then(() => {
        showToast(`✅ Đã copy ${urls.length} URL vào clipboard!`);
    }).catch(() => {
        showToast('Lỗi truy cập clipboard!');
    });
}

function exportTXT() {
    if (!allVideos.length) { showToast('Chưa có dữ liệu để xuất!'); return; }
    const text = allVideos.map(v => v.url).join('\n');
    downloadBlob(text, 'tiktok_urls.txt', 'text/plain');
}

function exportCSV() {
    if (!allVideos.length) { showToast('Chưa có dữ liệu để xuất!'); return; }
    let csv = 'STT,URL,Title,Author,Views,Likes,Comments,Shares\n';
    allVideos.forEach((v, idx) => {
        const title = `"${(v.title || '').replace(/"/g, '""')}"`;
        const author = `"${(v.author?.unique_id || '').replace(/"/g, '""')}"`;
        csv += `${idx + 1},"${v.url}",${title},${author},${v.play_count || 0},${v.digg_count || 0},${v.comment_count || 0},${v.share_count || 0}\n`;
    });
    downloadBlob('\uFEFF' + csv, 'tiktok_videos.csv', 'text/csv;charset=utf-8');
}

function clearAllTableData() {
    if (confirm('Bạn có chắc muốn xóa tất cả bài viết trong danh sách?')) {
        allVideos = [];
        renderTable();
        document.getElementById('results-section').style.display = 'none';
        document.getElementById('col-filter-wrap').style.display = 'none';
        document.getElementById('empty-state').style.display = 'block';
        showToast('Đã xóa dữ liệu!');
    }
}

function downloadBlob(content, fileName, mimeType) {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function showToast(msg) {
    const t = document.getElementById('copy-toast');
    t.textContent = msg; t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3000);
}

function fmtNum(n) {
    if (n == null) return '—';
    n = parseInt(n) || 0;
    if (n >= 1000000) return (n/1000000).toFixed(1) + 'M';
    if (n >= 1000)    return (n/1000).toFixed(1) + 'K';
    return n.toLocaleString();
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Initialize Extension check
document.addEventListener('DOMContentLoaded', () => {
    checkExtensionConnection();
});
</script>

<?php include 'includes/footer.php'; ?>
