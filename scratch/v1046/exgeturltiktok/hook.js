// TikTok Feed Collector - Web Context Hook
// Intercepts TikTok network feed JSON (Profile, Search, Hashtag) in the page context.
(function () {
  'use strict';
  if (window.__tptHookLoaded) return;
  window.__tptHookLoaded = true;

  const FEED_URL_RE = /\/api\/(recommend|item|post|related|challenge|music|effect|search|explore)\//i;

  function _numV(x) {
    const n = typeof x === 'number' ? x : parseInt(String(x == null ? '' : x).replace(/[^\d]/g, ''), 10);
    return isFinite(n) && n > 0 ? n : 0;
  }

  function _imgFromV(x) {
    if (!x) return '';
    if (typeof x === 'string') return /^https?:/.test(x) ? x : '';
    if (Array.isArray(x.url_list) && x.url_list.length) return String(x.url_list[0] || '');
    if (Array.isArray(x.urlList) && x.urlList.length) return String(x.urlList[0] || '');
    if (typeof x.url === 'string') return x.url;
    return '';
  }

  function _awemeIdFromItem(item) {
    if (!item || typeof item !== 'object') return null;
    const cand = item.id || item.aweme_id || item.awemeId;
    if (typeof cand === 'string' && /^\d{10,25}$/.test(cand)) return cand;
    if (typeof cand === 'number' && cand > 0) return String(cand);
    return null;
  }

  function _extractVideoMeta(node) {
    const stats = node.stats || node.statsV2 || node.statistics;
    const video = node.video;
    const author = node.author;
    if (!stats && !video && !author) return null;

    let cover = '';
    if (video) cover = _imgFromV(video.cover) || _imgFromV(video.originCover) || _imgFromV(video.dynamicCover);

    const g = (o, ...keys) => {
      if (!o) return 0;
      for (const k of keys) { if (o[k] != null) return _numV(o[k]); }
      return 0;
    };

    const uniqueId = author ? (author.uniqueId || author.unique_id || author.nickname || '') : '';
    const nickname = author ? (author.nickname || author.uniqueId || author.unique_id || '') : '';

    return {
      awemeId: _awemeIdFromItem(node) || '',
      desc: String(node.desc || node.description || node.title || ''),
      cover: cover || '',
      duration: video ? _numV(video.duration) : 0,
      createTime: _numV(node.createTime || node.create_time),
      play: g(stats, 'playCount', 'play_count'),
      digg: g(stats, 'diggCount', 'digg_count'),
      comment: g(stats, 'commentCount', 'comment_count'),
      share: g(stats, 'shareCount', 'share_count'),
      author: {
        uniqueId: uniqueId,
        nickname: nickname
      }
    };
  }

  const _awemeVideoMap = new Map();
  let _pendingVideoEmit = null;

  function _emitVideoForAweme(awemeId, meta) {
    if (!awemeId || !meta) return;
    const prev = _awemeVideoMap.get(awemeId);
    const merged = prev ? {
      awemeId: awemeId,
      desc: meta.desc || prev.desc,
      cover: meta.cover || prev.cover,
      duration: meta.duration || prev.duration,
      createTime: meta.createTime || prev.createTime,
      play: Math.max(meta.play, prev.play),
      digg: Math.max(meta.digg, prev.digg),
      comment: Math.max(meta.comment, prev.comment),
      share: Math.max(meta.share, prev.share),
      author: {
        uniqueId: meta.author?.uniqueId || prev.author?.uniqueId || '',
        nickname: meta.author?.nickname || prev.author?.nickname || ''
      }
    } : meta;

    _awemeVideoMap.set(awemeId, merged);

    if (_pendingVideoEmit) return;
    _pendingVideoEmit = setTimeout(() => {
      _pendingVideoEmit = null;
      const snap = {};
      _awemeVideoMap.forEach((v, k) => { snap[k] = v; });
      try {
        window.dispatchEvent(new CustomEvent('tpt-video-map-update', { detail: snap }));
      } catch (_) { }
    }, 100);
  }

  function _harvestFromJson(root, depth) {
    if (!root || typeof root !== 'object' || depth > 6) return;
    if (Array.isArray(root)) {
      for (const n of root) _harvestFromJson(n, depth + 1);
      return;
    }

    const awemeId = _awemeIdFromItem(root);
    if (awemeId) {
      const meta = _extractVideoMeta(root);
      if (meta) _emitVideoForAweme(awemeId, meta);
    }

    for (const k in root) {
      const v = root[k];
      if (v && typeof v === 'object') _harvestFromJson(v, depth + 1);
    }
  }

  function _processFeedResponseText(url, text) {
    if (!text || text.length < 64) return;
    let json;
    try { json = JSON.parse(text); } catch (_) { return; }
    _harvestFromJson(json, 0);
  }

  // Intercept fetch
  const _realFetch = window.fetch;
  if (typeof _realFetch === 'function') {
    window.fetch = function (input, init) {
      const p = _realFetch.apply(this, arguments);
      try {
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        if (url && FEED_URL_RE.test(url) && p && typeof p.then === 'function') {
          p.then(res => {
            try {
              if (!res || !res.ok) return;
              res.clone().text().then(t => _processFeedResponseText(url, t)).catch(() => { });
            } catch (_) { }
          }).catch(() => { });
        }
      } catch (_) { }
      return p;
    };
  }

  // Intercept XHR
  try {
    const _xhrOpen = XMLHttpRequest.prototype.open;
    const _xhrSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
      this.__tptUrl = url;
      return _xhrOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
      const url = this.__tptUrl || '';
      if (url && FEED_URL_RE.test(url)) {
        this.addEventListener('load', () => {
          try {
            if (this.status < 200 || this.status >= 300) return;
            const t = (this.responseType === '' || this.responseType === 'text')
              ? this.responseText
              : (this.response && typeof this.response === 'object' ? JSON.stringify(this.response) : null);
            if (t) _processFeedResponseText(url, t);
          } catch (_) { }
        });
      }
      return _xhrSend.apply(this, arguments);
    };
  } catch (_) { }

  // Initial hydration script tags
  function _harvestInitialHydration() {
    try {
      document.querySelectorAll(
        'script#__UNIVERSAL_DATA_FOR_REHYDRATION__, script#SIGI_STATE, script[id^="__UNIVERSAL_DATA_FOR"]'
      ).forEach(s => {
        const txt = s.textContent || '';
        if (!txt) return;
        let json;
        try { json = JSON.parse(txt); } catch (_) { return; }
        _harvestFromJson(json, 0);
      });
    } catch (_) { }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _harvestInitialHydration, { once: true });
  } else {
    _harvestInitialHydration();
  }
  setTimeout(_harvestInitialHydration, 1500);
  setTimeout(_harvestInitialHydration, 3000);

  window.addEventListener('tpt-request-video-map', () => {
    const snapshot = {};
    _awemeVideoMap.forEach((v, k) => { snapshot[k] = v; });
    try {
      window.dispatchEvent(new CustomEvent('tpt-video-map-update', { detail: snapshot }));
    } catch (_) { }
  });
})();
