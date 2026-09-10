// TikTok URL Collector Content Script (Runs on tiktok.com)
(function () {
  'use strict';
  if (window.__tptContentLoaded) return;
  window.__tptContentLoaded = true;

  let isAutoScrolling = true;
  let scrollInterval = null;
  let scrollDelayMs = 1200;
  const collectedVideosMap = new Map(); // videoId -> video object

  // Start auto-scroll by default when tab opens
  startAutoScroll();

  function sanitizeAuthorHandle(handle, nickname) {
    if (!handle) return nickname || 'user';
    // If handle is a raw 15+ digit user ID (e.g. 7426562186972218376) and nickname exists
    if (/^\d{12,}$/.test(handle) && nickname && !/^\d{12,}$/.test(nickname)) {
      return nickname.replace(/\s+/g, '').toLowerCase();
    }
    return handle;
  }

  // ─── Scan TikTok links from DOM ──────────────────────────────────────────────
  function scanDOMForVideos() {
    const links = document.querySelectorAll('a[href*="/video/"]');
    let added = false;

    links.forEach(a => {
      let href = a.getAttribute('href') || '';
      if (!href) return;

      if (href.startsWith('/')) {
        href = 'https://www.tiktok.com' + href;
      }

      // Match /video/1234567890123456789
      const match = href.match(/\/video\/(\d+)/i);
      if (match) {
        const videoId = match[1];

        // Extract author handle from URL if present e.g. /@username/video/123...
        const authorMatch = href.match(/@([^/]+)\/video/i);
        let authorHandle = authorMatch ? authorMatch[1] : 'user';

        let title = (a.innerText || a.getAttribute('aria-label') || '').trim();
        if (title.length > 200) title = title.substring(0, 200);

        if (!collectedVideosMap.has(videoId)) {
          const cleanUrl = `https://www.tiktok.com/@${authorHandle}/video/${videoId}`;
          collectedVideosMap.set(videoId, {
            video_id: videoId,
            url: cleanUrl,
            title: title || `TikTok Video ${videoId}`,
            author: { unique_id: authorHandle, nickname: authorHandle },
            play_count: 0,
            digg_count: 0,
            comment_count: 0,
            share_count: 0,
            create_time: Math.floor(Date.now() / 1000)
          });
          added = true;
        } else {
          // If existing item has numeric raw ID handle and we found a better one or title
          const existing = collectedVideosMap.get(videoId);
          if (/^\d{12,}$/.test(existing.author.unique_id) && !/^\d{12,}$/.test(authorHandle)) {
            existing.author.unique_id = authorHandle;
            existing.url = `https://www.tiktok.com/@${authorHandle}/video/${videoId}`;
            added = true;
          }
        }
      }
    });

    if (added) {
      reportToBackground();
    }
  }

  // ─── Listen to network feed data from hook.js ────────────────────────────────
  window.addEventListener('tpt-video-map-update', (ev) => {
    const snap = ev.detail || {};
    let addedOrUpdated = false;

    Object.keys(snap).forEach(awemeId => {
      const item = snap[awemeId];
      if (!item || !awemeId) return;

      const rawHandle = item.author?.uniqueId || item.author?.nickname || 'user';
      const authorHandle = sanitizeAuthorHandle(rawHandle, item.author?.nickname);
      const cleanUrl = `https://www.tiktok.com/@${authorHandle}/video/${awemeId}`;

      const existing = collectedVideosMap.get(awemeId) || {};
      const updatedObj = {
        video_id: awemeId,
        url: cleanUrl,
        title: (item.desc && !item.desc.startsWith('TikTok Video')) ? item.desc : (existing.title || `TikTok Video ${awemeId}`),
        author: {
          unique_id: authorHandle,
          nickname: item.author?.nickname || authorHandle
        },
        play_count: Math.max(item.play || 0, existing.play_count || 0),
        digg_count: Math.max(item.digg || 0, existing.digg_count || 0),
        comment_count: Math.max(item.comment || 0, existing.comment_count || 0),
        share_count: Math.max(item.share || 0, existing.share_count || 0),
        create_time: item.createTime || existing.create_time || Math.floor(Date.now() / 1000)
      };

      collectedVideosMap.set(awemeId, updatedObj);
      addedOrUpdated = true;
    });

    if (addedOrUpdated) {
      reportToBackground();
    }
  });

  // Request snapshot from hook.js
  setTimeout(() => {
    try { window.dispatchEvent(new CustomEvent('tpt-request-video-map')); } catch (_) { }
  }, 600);

  // Periodic DOM scan
  setInterval(scanDOMForVideos, 1200);

  // ─── Auto-Scroll Engine ──────────────────────────────────────────────────────
  function startAutoScroll() {
    if (scrollInterval) clearInterval(scrollInterval);
    isAutoScrolling = true;

    scrollInterval = setInterval(() => {
      if (!isAutoScrolling) return;
      const distance = Math.floor(Math.random() * 300) + 650;
      window.scrollBy({ top: distance, behavior: 'smooth' });
      scanDOMForVideos();
    }, scrollDelayMs);
  }

  function stopAutoScroll() {
    isAutoScrolling = false;
    if (scrollInterval) {
      clearInterval(scrollInterval);
      scrollInterval = null;
    }
  }

  function reportToBackground(forceUpdate = false) {
    const list = Array.from(collectedVideosMap.values());
    try {
      chrome.runtime.sendMessage({
        type: 'VIDEOS_COLLECTED',
        videos: list,
        forceUpdate
      });
    } catch (_) { }
  }

  // Listen to messages from Background Worker
  chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (!msg || !msg.type) return;

    if (msg.type === 'START_AUTOSCROLL') {
      startAutoScroll();
      sendResponse({ status: 'started' });
      return true;
    }

    if (msg.type === 'STOP_AUTOSCROLL') {
      stopAutoScroll();
      sendResponse({ status: 'stopped' });
      return true;
    }
  });

  // Floating indicator on TikTok tab
  createFloatingBadge();

  function createFloatingBadge() {
    if (document.getElementById('tpt-floating-badge')) return;
    const badge = document.createElement('div');
    badge.id = 'tpt-floating-badge';
    badge.style.cssText = `
      position: fixed;
      bottom: 24px;
      right: 24px;
      z-index: 999999;
      background: rgba(18, 18, 24, 0.92);
      border: 1px solid rgba(254, 44, 85, 0.4);
      backdrop-filter: blur(12px);
      border-radius: 14px;
      padding: 10px 16px;
      color: #ffffff;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      font-size: 13px;
      font-weight: 600;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      gap: 12px;
      user-select: none;
    `;

    badge.innerHTML = `
      <div style="display:flex;align-items:center;gap:6px;">
        <span style="font-size:16px;">🎵</span>
        <span>Đã quét: <strong id="tpt-badge-count" style="color:#fe2c55;font-size:15px;">0</strong> URL</span>
      </div>
      <button id="tpt-badge-toggle-scroll" style="
        background: linear-gradient(135deg, #fe2c55, #c9134c);
        border: none;
        color: #fff;
        padding: 5px 10px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        outline: none;
      ">⏸ Dừng cuộn</button>
    `;

    document.body.appendChild(badge);

    const countEl = document.getElementById('tpt-badge-count');
    const scrollBtn = document.getElementById('tpt-badge-toggle-scroll');

    function updateBadgeUI() {
      if (countEl) countEl.textContent = collectedVideosMap.size;
      if (scrollBtn) {
        if (isAutoScrolling) {
          scrollBtn.textContent = '⏸ Dừng cuộn';
          scrollBtn.style.background = 'linear-gradient(135deg, #e11d48, #be123c)';
        } else {
          scrollBtn.textContent = '▶ Tự cuộn';
          scrollBtn.style.background = 'linear-gradient(135deg, #fe2c55, #c9134c)';
        }
      }
    }

    scrollBtn.addEventListener('click', () => {
      if (isAutoScrolling) stopAutoScroll();
      else startAutoScroll();
      updateBadgeUI();
    });

    setInterval(updateBadgeUI, 800);
  }
})();
