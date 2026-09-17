<!-- Emoji Picker Component -->
<script src="emoji-data.js"></script>
<style>
.emoji-picker-trigger {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border: 1px solid var(--border-color, #cbd5e1);
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    font-size: 13px;
    color: #475569;
    transition: all 0.15s;
    user-select: none;
    vertical-align: middle;
    white-space: nowrap;
}
.emoji-picker-trigger:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

.emoji-picker-popup {
    display: none;
    position: fixed;
    z-index: 9999;
    width: 372px;
    max-height: 400px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.15), 0 4px 12px rgba(0,0,0,0.08);
    overflow: hidden;
    animation: emojiPopIn 0.15s ease-out;
}
@keyframes emojiPopIn {
    from { opacity: 0; transform: translateY(-6px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.emoji-picker-popup.active { display: flex; flex-direction: column; }

.emoji-tabs {
    display: flex;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    padding: 4px 6px 0;
    gap: 1px;
    overflow-x: auto;
    flex-shrink: 0;
}
.emoji-tabs::-webkit-scrollbar { height: 3px; }
.emoji-tabs::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

.emoji-tab {
    flex-shrink: 0;
    padding: 6px 8px;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 17px;
    border-radius: 6px 6px 0 0;
    transition: background 0.1s;
    line-height: 1;
}
.emoji-tab:hover { background: #e2e8f0; }
.emoji-tab.active { background: #fff; border-bottom: 2px solid #6366f1; }

.emoji-search-box {
    padding: 6px 10px;
    flex-shrink: 0;
    border-bottom: 1px solid #f1f5f9;
}
.emoji-search-box input {
    width: 100%;
    padding: 7px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
}
.emoji-search-box input:focus { border-color: #6366f1; }

.emoji-grid-wrap {
    overflow-y: auto;
    flex: 1;
    padding: 6px 10px 10px;
    min-height: 0;
}
.emoji-grid-wrap::-webkit-scrollbar { width: 5px; }
.emoji-grid-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

.emoji-cat-label {
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 6px 2px 4px;
    margin-top: 4px;
}

/* Fixed grid: exactly 10 columns, perfectly aligned */
.emoji-grid {
    display: grid;
    grid-template-columns: repeat(10, 1fr);
    gap: 1px;
}
.emoji-grid span {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    aspect-ratio: 1;
    font-size: 22px;
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.1s, transform 0.1s;
    line-height: 1;
    overflow: hidden;
}
.emoji-grid span:hover {
    background: #ede9fe;
    transform: scale(1.15);
}
</style>

<script>
(function() {
    let activePopup = null;

    document.addEventListener('click', function(e) {
        if (activePopup && !activePopup.contains(e.target) && !e.target.closest('.emoji-picker-trigger')) {
            activePopup.classList.remove('active');
            activePopup = null;
        }
    });

    /**
     * Split emoji string into individual emoji characters correctly
     * (handles multi-codepoint emoji like flags, skin tones, ZWJ sequences)
     */
    function splitEmojis(str) {
        const segmenter = typeof Intl !== 'undefined' && Intl.Segmenter
            ? new Intl.Segmenter('en', { granularity: 'grapheme' })
            : null;
        if (segmenter) {
            return [...segmenter.segment(str)].map(s => s.segment);
        }
        // Fallback: use spread (works for most emoji)
        return [...str];
    }

    /**
     * Initialize an emoji picker for a target textarea/input
     */
    window.initEmojiPicker = function(triggerId, popupId, targetId) {
        const trigger = document.getElementById(triggerId);
        const popup = document.getElementById(popupId);
        const target = document.getElementById(targetId);
        if (!trigger || !popup || !target || typeof EMOJI_CATEGORIES === 'undefined') return;

        const tabsEl = popup.querySelector('.emoji-tabs');
        const gridWrap = popup.querySelector('.emoji-grid-wrap');
        const searchInput = popup.querySelector('.emoji-search-input');

        // Pre-split all emoji for each category
        const categoryEmojis = EMOJI_CATEGORIES.map(cat => splitEmojis(cat.emojis));

        EMOJI_CATEGORIES.forEach((cat, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'emoji-tab' + (idx === 0 ? ' active' : '');
            btn.textContent = cat.icon;
            btn.title = cat.name;
            btn.addEventListener('click', () => {
                tabsEl.querySelectorAll('.emoji-tab').forEach(t => t.classList.remove('active'));
                btn.classList.add('active');
                renderCategory(idx);
                if (searchInput) searchInput.value = '';
            });
            tabsEl.appendChild(btn);
        });

        function renderCategory(idx) {
            gridWrap.innerHTML = '';
            const cat = EMOJI_CATEGORIES[idx];
            const emojis = categoryEmojis[idx];

            const label = document.createElement('div');
            label.className = 'emoji-cat-label';
            label.textContent = cat.name;
            gridWrap.appendChild(label);

            const grid = document.createElement('div');
            grid.className = 'emoji-grid';
            emojis.forEach(em => {
                const sp = document.createElement('span');
                sp.textContent = em;
                sp.addEventListener('click', () => insertEmoji(em));
                grid.appendChild(sp);
            });
            gridWrap.appendChild(grid);
        }

        function renderSearch(query) {
            gridWrap.innerHTML = '';
            const q = query.toLowerCase();
            EMOJI_CATEGORIES.forEach((cat, idx) => {
                const emojis = categoryEmojis[idx];
                const nameMatch = cat.name.toLowerCase().includes(q);
                const matchedEmojis = nameMatch ? emojis : emojis.filter(e => e.includes(q));
                if (matchedEmojis.length === 0) return;

                const label = document.createElement('div');
                label.className = 'emoji-cat-label';
                label.textContent = cat.name;
                gridWrap.appendChild(label);

                const grid = document.createElement('div');
                grid.className = 'emoji-grid';
                matchedEmojis.forEach(em => {
                    const sp = document.createElement('span');
                    sp.textContent = em;
                    sp.addEventListener('click', () => insertEmoji(em));
                    grid.appendChild(sp);
                });
                gridWrap.appendChild(grid);
            });
            if (gridWrap.children.length === 0) {
                gridWrap.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px;">Không tìm thấy emoji</div>';
            }
        }

        function insertEmoji(emoji) {
            const start = target.selectionStart;
            const end = target.selectionEnd;
            const val = target.value;
            target.value = val.substring(0, start) + emoji + val.substring(end);
            target.selectionStart = target.selectionEnd = start + emoji.length;
            target.focus();
            target.dispatchEvent(new Event('input', { bubbles: true }));
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const q = this.value.trim();
                if (q.length === 0) {
                    tabsEl.querySelectorAll('.emoji-tab').forEach((t, i) => t.classList.toggle('active', i === 0));
                    renderCategory(0);
                } else {
                    tabsEl.querySelectorAll('.emoji-tab').forEach(t => t.classList.remove('active'));
                    renderSearch(q);
                }
            });
        }

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            if (activePopup && activePopup !== popup) {
                activePopup.classList.remove('active');
            }
            const isVisible = popup.classList.contains('active');
            if (isVisible) {
                popup.classList.remove('active');
                activePopup = null;
            } else {
                const rect = trigger.getBoundingClientRect();
                popup.style.top = (rect.bottom + 4) + 'px';
                popup.style.left = Math.max(4, Math.min(rect.left, window.innerWidth - 380)) + 'px';
                popup.classList.add('active');
                activePopup = popup;
                if (gridWrap.children.length === 0) renderCategory(0);
            }
        });
    };
})();
</script>
