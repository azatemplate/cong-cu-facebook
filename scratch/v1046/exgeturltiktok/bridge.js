// TikTok URL Collector Extension - Web Bridge Script
// Injected into web pages (tiktok_search.php) to bridge messaging with the Extension Service Worker.
(function () {
  'use strict';
  if (window.__tptBridgeLoaded) return;
  window.__tptBridgeLoaded = true;

  // Mark extension presence on DOM
  document.documentElement.setAttribute('data-tiktok-ext-installed', 'true');

  // Listen to messages from web page (tiktok_search.php)
  window.addEventListener('message', (event) => {
    if (!event.data || event.data.source !== 'TIKTOK_SEARCH_PAGE') return;

    const msg = event.data;

    if (msg.type === 'CHECK_EXT') {
      window.postMessage({ source: 'EX_TIKTOK_EXTENSION', type: 'EXT_PONG' }, '*');
      return;
    }

    if (msg.type === 'START_SCAN') {
      try {
        chrome.runtime.sendMessage({
          type: 'START_SCAN',
          mode: msg.mode,
          targetUrl: msg.targetUrl,
          limit: msg.limit || 50
        });
      } catch (_) { }
      return;
    }

    if (msg.type === 'STOP_SCAN') {
      try {
        chrome.runtime.sendMessage({ type: 'STOP_SCAN' });
      } catch (_) { }
      return;
    }
  });

  // Listen to messages from extension background service worker
  try {
    chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
      if (!msg || !msg.type) return;

      if (msg.type === 'LIVE_VIDEOS_UPDATE') {
        window.postMessage({
          source: 'EX_TIKTOK_EXTENSION',
          type: 'LIVE_VIDEOS_UPDATE',
          videos: msg.videos || [],
          count: msg.count || 0,
          isScanning: !!msg.isScanning
        }, '*');
      } else if (msg.type === 'SCAN_FINISHED') {
        window.postMessage({
          source: 'EX_TIKTOK_EXTENSION',
          type: 'SCAN_FINISHED',
          videos: msg.videos || [],
          count: msg.count || 0
        }, '*');
      }
    });
  } catch (_) { }

  // Initial announcement
  window.postMessage({ source: 'EX_TIKTOK_EXTENSION', type: 'EXT_PONG' }, '*');
})();
