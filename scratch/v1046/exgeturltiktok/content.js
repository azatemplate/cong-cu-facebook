// TikTok URL Collector Content Script (Runs on tiktok.com)
(function () {
  'use strict';
  if (window.__tptContentLoaded) return;
  window.__tptContentLoaded = true;

  let isAutoScrolling = true;
  let scrollInterval = null;
  let scrollDelayMs = 1000;
  const collectedVideosMap = new Map(); // videoId -> video object

  // Start auto-scroll by default when tab opens
  startAutoScroll();

  function sanitizeAuthorHandle(handle, nickname) {
    if (!handle) return nickname || 'user';
    if (/^\d{12,}$/.test(handle) && nickname && !/^\d{12,}$/.test(nickname)) {
      return nickname.replace(/\s+/g, '').toLowerCase();
    }
    return handle;
  }

  // ─── Filter out Navigation & Sidebar Elements ───────────────────────────────
  function isInsideNavOrSidebar(el) {
    if (!el) return false;
    // Check if element is inside header, nav, sidebar, or user profile drawer
    const navSelector = 'header, nav, aside, [class*="sidebar" i], [class*="Sidebar" i], [class*="Header" i], [class*="Nav" i], [data-e2e*="nav"], [data-e2e*="sidebar"], [data-e2e="user-avatar"], [class*="DivSideNav"]';
    return !!el.closest(navSelector);
  }

  // ─── Scan TikTok links from DOM ──────────────────────────────────────────────
  function scanDOMForVideos() {
    // Only scan links inside main content areas, ignoring header & sidebar
    const links = document.querySelectorAll('a[href*="/video/"]');
    let added = false;

    links.forEach(a => {
      // Ignore links in header/nav/sidebar (prevents collecting logged-in user's own profile videos)
      if (isInsideNavOrSidebar(a)) return;

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

        // Ignore raw numeric secUid handles if possible
        if (/^\d{12,}$/.test(authorHandle)) {
          authorHandle = 'user';
        }

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
          // If existing item has generic 'user' handle and we found a real handle
          const existing = collectedVideosMap.get(videoId);
          if (existing.author.unique_id === 'user' && authorHandle !== 'user') {
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
  }, 500);

  // Periodic DOM scan
  setInterval(scanDOMForVideos, 1000);

  // ─── Universal Auto-Scroll Engine (Works for Search, Tag, and Profile) ───────
  function performScrollStep() {
    if (!isAutoScrolling) return;

    const scrollDistance = Math.floor(Math.random() * 300) + 700;

    // 1. Scroll window
    window.scrollBy({ top: scrollDistance, behavior: 'smooth' });

    // 2. Scroll documentElement & body
    if (document.documentElement) document.documentElement.scrollTop += scrollDistance;
    if (document.body) document.body.scrollTop += scrollDistance;

    // 3. Scroll any inner overflow scroll containers (TikTok Search / Tag containers)
    const scrollContainers = document.querySelectorAll('div[class*="Container"], div[class*="List"], div[class*="Feed"], div[class*="Search"], main');
    scrollContainers.forEach(container => {
      if (container.scrollHeight > container.clientHeight && container.clientHeight > 200) {
        container.scrollTop += scrollDistance;
      }
    });

    // 4. Dispatch synthetic scroll event to trigger TikTok infinite loading observers
    window.dispatchEvent(new Event('scroll'));

    scanDOMForVideos();
  }

  function startAutoScroll() {
    if (scrollInterval) clearInterval(scrollInterval);
    isAutoScrolling = true;

    scrollInterval = setInterval(performScrollStep, scrollDelayMs);
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
