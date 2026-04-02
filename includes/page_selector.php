<?php
/**
 * Reusable Fanpage Selector
 * Required vars (set before including):
 *   $pages_json  — JSON string of all pages, each with {page_id, name, user_id}
 *
 * Renders:
 *  - Search input
 *  - "Select all visible" checkbox
 *  - Scrollable checkbox list
 *  - Hidden inputs page_ids[] submitted with the form
 *
 * JS API (global):
 *  window.pageSelectorFilterByUser(userId) — call after user dropdown changes
 */
?>
<style>
.ps-wrapper {
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
    background: var(--card-bg, #fff);
}
.ps-search-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-color);
    background: #f8fafc;
}
.ps-search-bar svg { flex-shrink: 0; color: #94a3b8; }
.ps-search-bar input {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    font-size: 13px;
    color: var(--text-main, #1e293b);
}
.ps-search-bar input::placeholder { color: #94a3b8; }
.ps-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 12px;
    border-bottom: 1px solid var(--border-color);
    background: #f1f5f9;
    font-size: 12px;
    color: var(--text-muted, #64748b);
}
.ps-toolbar label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 500; }
.ps-toolbar input[type=checkbox] { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary-color, #2563eb); }
#ps-count { font-size: 12px; color: var(--text-muted, #64748b); }
.ps-list {
    max-height: 220px;
    overflow-y: auto;
    padding: 4px 0;
}
.ps-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 12px;
    cursor: pointer;
    transition: background 0.12s;
    font-size: 13px;
    color: var(--text-main, #1e293b);
}
.ps-item:hover { background: #f0f7ff; }
.ps-item.ps-checked { background: #eff6ff; }
.ps-item input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; accent-color: var(--primary-color, #2563eb); cursor: pointer; }
.ps-item label { cursor: pointer; flex: 1; line-height: 1.35; }
.ps-empty {
    text-align: center;
    padding: 24px;
    color: #94a3b8;
    font-size: 13px;
    display: none;
}
</style>

<div class="ps-wrapper" id="ps-wrapper">
    <div class="ps-search-bar">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="ps-search" placeholder="Tìm kiếm fanpage..." autocomplete="off">
        <button type="button" id="ps-clear-search" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:0;display:none;">✕</button>
    </div>
    <div class="ps-toolbar">
        <label>
            <input type="checkbox" id="ps-select-all"> Chọn tất cả
        </label>
        <span id="ps-count">0 đã chọn</span>
    </div>
    <div class="ps-list" id="ps-list">
        <div class="ps-empty" id="ps-empty">Không tìm thấy fanpage nào</div>
    </div>
</div>
<!-- Hidden inputs submitted with form -->
<div id="ps-hidden-inputs"></div>

<script>
(function() {
    /* All pages injected from PHP */
    const ALL_PAGES = <?php echo $pages_json; ?>;

    let currentUserId = null;   // null = show all
    let currentPages  = [];     // filtered by user
    let checkedIds    = new Set();

    const listEl      = document.getElementById('ps-list');
    const emptyEl     = document.getElementById('ps-empty');
    const searchEl    = document.getElementById('ps-search');
    const clearBtn    = document.getElementById('ps-clear-search');
    const selectAllEl = document.getElementById('ps-select-all');
    const countEl     = document.getElementById('ps-count');
    const hiddenEl    = document.getElementById('ps-hidden-inputs');

    /* ── Public API ─────────────────────────────────────── */
    window.pageSelectorFilterByUser = function(userId) {
        currentUserId = userId || null;
        checkedIds.clear();
        searchEl.value = '';
        clearBtn.style.display = 'none';
        if (currentUserId) {
            currentPages = ALL_PAGES.filter(p => String(p.user_id) === String(currentUserId));
        } else {
            currentPages = [];
        }
        render('');
    };

    /* ── Render list ────────────────────────────────────── */
    function render(query) {
        const q = query.trim().toLowerCase();
        const visible = currentPages.filter(p => !q || p.name.toLowerCase().includes(q));

        // Remove old items (keep empty-msg)
        listEl.querySelectorAll('.ps-item').forEach(el => el.remove());

        if (visible.length === 0) {
            emptyEl.style.display = 'block';
        } else {
            emptyEl.style.display = 'none';
            visible.forEach(p => {
                const id  = 'ps-cb-' + p.page_id;
                const div = document.createElement('div');
                div.className = 'ps-item' + (checkedIds.has(p.page_id) ? ' ps-checked' : '');
                div.dataset.pageId = p.page_id;
                div.innerHTML = `<input type="checkbox" id="${id}" value="${p.page_id}"${checkedIds.has(p.page_id) ? ' checked' : ''}>
                                 <label for="${id}">${escHtml(p.name)}</label>`;
                div.querySelector('input').addEventListener('change', function() {
                    if (this.checked) { checkedIds.add(p.page_id); div.classList.add('ps-checked'); }
                    else              { checkedIds.delete(p.page_id); div.classList.remove('ps-checked'); }
                    syncSelectAll(visible);
                    updateCount();
                    updateHidden();
                });
                div.addEventListener('click', function(e) {
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
                    const cb = div.querySelector('input');
                    cb.checked = !cb.checked;
                    cb.dispatchEvent(new Event('change'));
                });
                listEl.appendChild(div);
            });
        }
        syncSelectAll(visible);
        updateCount();
        updateHidden();
    }

    function syncSelectAll(visible) {
        const allChecked = visible.length > 0 && visible.every(p => checkedIds.has(p.page_id));
        selectAllEl.checked       = allChecked;
        selectAllEl.indeterminate = !allChecked && visible.some(p => checkedIds.has(p.page_id));
    }

    function updateCount() {
        const n = checkedIds.size;
        countEl.textContent = n > 0 ? n + ' đã chọn' : '0 đã chọn';
        countEl.style.color = n > 0 ? 'var(--primary-color, #2563eb)' : '';
        countEl.style.fontWeight = n > 0 ? '600' : '';
    }

    function updateHidden() {
        hiddenEl.innerHTML = '';
        checkedIds.forEach(pid => {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'page_ids[]';
            inp.value = pid;
            hiddenEl.appendChild(inp);
        });
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── Select All ─────────────────────────────────────── */
    selectAllEl.addEventListener('change', function() {
        const q = searchEl.value.trim().toLowerCase();
        const visible = currentPages.filter(p => !q || p.name.toLowerCase().includes(q));
        if (this.checked) visible.forEach(p => checkedIds.add(p.page_id));
        else              visible.forEach(p => checkedIds.delete(p.page_id));
        render(q);
    });

    /* ── Search ─────────────────────────────────────────── */
    searchEl.addEventListener('input', function() {
        clearBtn.style.display = this.value ? 'block' : 'none';
        render(this.value);
    });
    clearBtn.addEventListener('click', function() {
        searchEl.value = '';
        this.style.display = 'none';
        render('');
        searchEl.focus();
    });

    /* ── Validation helper (call in form submit) ────────── */
    window.pageSelectorValidate = function() {
        if (checkedIds.size === 0) {
            alert('Vui lòng chọn ít nhất 1 Fanpage.');
            return false;
        }
        return true;
    };

    /* ── Init ───────────────────────────────────────────── */
    render('');
})();
</script>
