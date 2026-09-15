// TikTok URL Collector Extension - Background Service Worker

let isScanning = false;
let searchPageTabId = null;
let tiktokTabId = null;
let targetLimit = 50;
const collectedVideosMap = new Map(); // video_id -> video object

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (!msg || !msg.type) return;

  if (msg.type === 'START_SCAN') {
    isScanning = true;
    collectedVideosMap.clear();
    targetLimit = parseInt(msg.limit) || 50;
    if (sender && sender.tab && sender.tab.id) {
      searchPageTabId = sender.tab.id;
    }

    const targetUrl = msg.targetUrl || 'https://www.tiktok.com';

    // Open target TikTok page in new tab
    chrome.tabs.create({ url: targetUrl, active: true }, (tab) => {
      if (tab && tab.id) {
        tiktokTabId = tab.id;
      }
    });

    sendResponse({ status: 'started', targetUrl });
    return true;
  }

  if (msg.type === 'STOP_SCAN') {
    isScanning = false;

    // Close the scanning TikTok tab and focus back to tiktok_search.php tab
    closeScanningTabAndReturn();

    notifySearchPage({
      type: 'SCAN_FINISHED',
      videos: Array.from(collectedVideosMap.values()),
      count: collectedVideosMap.size
    });

    sendResponse({ status: 'stopped' });
    return true;
  }

  if (msg.type === 'VIDEOS_COLLECTED') {
    const list = Array.isArray(msg.videos) ? msg.videos : [];
    let hasNew = false;

    list.forEach(v => {
      if (!v) return;
      const vId = v.video_id || extractVideoId(v.url);
      if (!vId) return;

      const existing = collectedVideosMap.get(vId);
      if (!existing) {
        collectedVideosMap.set(vId, v);
        hasNew = true;
      } else {
        // Merge updates
        const merged = {
          ...existing,
          title: (v.title && !v.title.startsWith('TikTok Video')) ? v.title : existing.title,
          url: v.url || existing.url,
          author: v.author || existing.author,
          play_count: Math.max(v.play_count || 0, existing.play_count || 0),
          digg_count: Math.max(v.digg_count || 0, existing.digg_count || 0),
          comment_count: Math.max(v.comment_count || 0, existing.comment_count || 0),
          share_count: Math.max(v.share_count || 0, existing.share_count || 0)
        };
        collectedVideosMap.set(vId, merged);
      }
    });

    const allList = Array.from(collectedVideosMap.values());

    if (hasNew || msg.forceUpdate) {
      // Relay live updates to tiktok_search.php tab
      notifySearchPage({
        type: 'LIVE_VIDEOS_UPDATE',
        videos: allList,
        count: allList.length,
        isScanning
      });
    }

    // Check if target limit is reached
    if (isScanning && targetLimit > 0 && allList.length >= targetLimit) {
      isScanning = false;

      // Close the TikTok tab and activate searchPageTabId
      closeScanningTabAndReturn();

      notifySearchPage({
        type: 'SCAN_FINISHED',
        videos: allList,
        count: allList.length
      });
    }

    sendResponse({ status: 'received', count: allList.length });
    return true;
  }

  if (msg.type === 'CHECK_SCAN_STATUS') {
    sendResponse({
      isScanning,
      count: collectedVideosMap.size,
      videos: Array.from(collectedVideosMap.values())
    });
    return true;
  }
});

function closeScanningTabAndReturn() {
  if (tiktokTabId) {
    const tabToClose = tiktokTabId;
    tiktokTabId = null;
    chrome.tabs.remove(tabToClose).catch(() => { });
  }

  if (searchPageTabId) {
    chrome.tabs.update(searchPageTabId, { active: true }).catch(() => { });
  }
}

function notifySearchPage(messagePayload) {
  if (searchPageTabId) {
    chrome.tabs.sendMessage(searchPageTabId, messagePayload).catch(() => {
      chrome.tabs.query({ url: '*://*/tiktok_search.php*' }, (tabs) => {
        (tabs || []).forEach(tab => {
          if (tab.id) chrome.tabs.sendMessage(tab.id, messagePayload).catch(() => { });
        });
      });
    });
  } else {
    chrome.tabs.query({}, (tabs) => {
      (tabs || []).forEach(tab => {
        if (tab.id && (tab.url || '').includes('tiktok_search.php')) {
          chrome.tabs.sendMessage(tab.id, messagePayload).catch(() => { });
        }
      });
    });
  }
}

function extractVideoId(url) {
  if (!url) return '';
  const m = url.match(/\/video\/(\d+)/i);
  return m ? m[1] : '';
}
