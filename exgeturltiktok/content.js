// TikTok URL Collector Content Script (Runs on tiktok.com)
(function () {
  'use strict';
  if (window.__tptContentLoaded) return;
  window.__tptContentLoaded = true;

  let isAutoScrolling = true;
  let scrollInterval = null;
  let scrollDelayMs = 1200;
  const collectedVideosMap = new Map();

  // Start auto-scroll by default when opened
  startAutoScroll();

  // ─── Extract TikTok Video links from DOM ─────────────────────────────────────
  function scanDOMForVideos() {
    const links = document.querySelectorAll('a[href*="/video/"]');
    let added = false;
    links.forEach(a => {
      let href = a.getAttribute('href') || '';
      if (!href) return;

      if (href.startsWith('/')) {
        href = 'https://www.tiktok.com' + href;
      }

      const match = href.match(/https?:\/\/(?:www\.)?tiktok\.com\/@([^/]+)\/video\/(\d+)/i);
      if (match) {
        const authorId = match[1];
        const videoId = match[2];
        const cleanUrl = `https://www.tiktok.com/@${authorId}/video/${videoId}`;

        if (!collectedVideosMap.has(cleanUrl)) {
          let title = (a.innerText || a.getAttribute('aria-label') || '').trim();
          if (title.length > 200) title = title.substring(0, 200);

          collectedVideosMap.set(cleanUrl, {
            url: cleanUrl,
            video_id: videoId,
            title: title || `TikTok Video ${videoId}`,
            author: { unique_id: authorId, nickname: authorId },
            play_count: 0,
            digg_count: 0,
            comment_count: 0,
            share_count: 0,
            create_time: Math.floor(Date.now() / 1000)
          });
          added = true;
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
      if (!item) return;

      const authorId = item.author?.uniqueId || item.author?.nickname || 'user';
      const cleanUrl = `https://www.tiktok.com/@${authorId}/video/${awemeId}`;

      const existing = collectedVideosMap.get(cleanUrl) || {};
      const updatedObj = {
        url: cleanUrl,
        video_id: awemeId,
        title: item.desc || existing.title || `TikTok Video ${awemeId}`,
        author: {
          unique_id: authorId,
          nickname: item.author?.nickname || authorId
        },
        play_count: item.play || existing.play_count || 0,
        digg_count: item.digg || existing.digg_count || 0,
        comment_count: item.comment || existing.comment_count || 0,
        share_count: item.share || existing.share_count || 0,
        create_time: item.createTime || existing.create_time || Math.floor(Date.now() / 1000)
      };

      collectedVideosMap.set(cleanUrl, updatedObj);
      addedOrUpdated = true;
    });

    if (addedOrUpdated) {
      reportToBackground();
    }
  });

  // Request snapshot from hook.js
  setTimeout(() => {
    try { window.dispatchEvent(new CustomEvent('tpt-request-video-map')); } catch (_) { }
  }, 800);

  // Periodic DOM scan
  setInterval(scanDOMForVideos, 1500);

  // ─── Auto-Scroll Engine ──────────────────────────────────────────────────────
  function startAutoScroll() {
    if (scrollInterval) clearInterval(scrollInterval);
    isAutoScrolling = true;

    scrollInterval = setInterval(() => {
      if (!isAutoScrolling) return;
      const distance = Math.floor(Math.random() * 300) + 600;
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

  // ─── Listen to Extension Messages ───────────────────────────────────────────
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

  // Create floating indicator on TikTok page
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
        <span>Thu thập cho PHP: <strong id="tpt-badge-count" style="color:#fe2c55;font-size:15px;">0</strong> URL</span>
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
