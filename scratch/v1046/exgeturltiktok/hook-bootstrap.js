/* global chrome, window, CustomEvent */
// Enables the MAIN-world feed observer early enough to see TikTok's first feed
// response. Reading settings stays in the isolated world, where chrome.storage
// is available; the observer itself remains in hook.js.
(function () {
  'use strict';
  const fire = () => {
    try { window.dispatchEvent(new CustomEvent('tpt-enable-feed-hook')); } catch (_) { }
  };
  try {
    chrome.storage.local.get({ tpt_feature_flags: null }, local => {
      const flags = local && local.tpt_feature_flags;
      if (flags && flags.enableNetworkHook === false) return;
      chrome.storage.sync.get({ productViewer: true, commentStickers: true }, settings => {
        if (!settings || settings.productViewer !== false || settings.commentStickers !== false) fire();
      });
    });
  } catch (_) { }
})();
