// TikTok URL Collector Extension - Background Service Worker

let isScanning = false;
let searchPageTabId = null;
let tiktokTabId = null;
let targetLimit = 50;
const collectedVideosMap = new Map();

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

    // Open target TikTok page in active tab
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
    if (tiktokTabId) {
      chrome.tabs.sendMessage(tiktokTabId, { type: 'STOP_AUTOSCROLL' }).catch(() => { });
    }
    notifySearchPage({ type: 'SCAN_FINISHED', videos: Array.from(collectedVideosMap.values()), count: collectedVideosMap.size });
    sendResponse({ status: 'stopped' });
    return true;
  }

  if (msg.type === 'VIDEOS_COLLECTED') {
    const list = Array.isArray(msg.videos) ? msg.videos : [];
    let hasNew = false;

    list.forEach(v => {
      if (v && v.url && !collectedVideosMap.has(v.url)) {
        collectedVideosMap.set(v.url, v);
        hasNew = true;
      }
    });

    if (hasNew || msg.forceUpdate) {
      const allList = Array.from(collectedVideosMap.values());

      // Relay live updates back to tiktok_search.php tab
      notifySearchPage({
        type: 'LIVE_VIDEOS_UPDATE',
        videos: allList,
        count: allList.length,
        isScanning
      });

      // Check limit
      if (targetLimit > 0 && allList.length >= targetLimit) {
        isScanning = false;
        if (tiktokTabId) {
          chrome.tabs.sendMessage(tiktokTabId, { type: 'STOP_AUTOSCROLL' }).catch(() => { });
        }
        notifySearchPage({
          type: 'SCAN_FINISHED',
          videos: allList,
          count: allList.length
        });
      }
    }
    sendResponse({ status: 'received', count: collectedVideosMap.size });
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

function notifySearchPage(messagePayload) {
  if (searchPageTabId) {
    chrome.tabs.sendMessage(searchPageTabId, messagePayload).catch(() => {
      // If original tab ID fails, broadcast to all tabs matching search page
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
