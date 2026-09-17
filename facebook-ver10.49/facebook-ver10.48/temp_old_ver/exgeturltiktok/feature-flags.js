/**
 * feature-flags.js — HONGDOLABS - Trợ thủ TopTop
 *
 * Store feature flag thống nhất, đọc/ghi qua chrome.storage.local với schema và
 * giá trị mặc định rõ ràng. Mục tiêu: bật/tắt an toàn các tính năng rủi ro cao
 * (network hook, product hydration) và bật log debug mà không phải sửa code.
 *
 * Thiết kế để dùng được ở nhiều môi trường:
 *   - Service worker  : importScripts('feature-flags.js') rồi dùng self.TPTFlags
 *   - Popup / content  : <script src="feature-flags.js"> rồi dùng window.TPTFlags
 *
 * Không phụ thuộc module bundler. Không tự đổi hành vi extension: mọi flag mặc
 * định giữ nguyên trạng thái hiện tại của extension.
 */
(function (root) {
  'use strict';

  // Một key storage duy nhất, theo prefix tpt_ hiện có trong dự án.
  const STORAGE_KEY = 'tpt_feature_flags';

  /**
   * Schema flag. Thêm flag mới ở đây kèm giá trị mặc định an toàn.
   * QUY TẮC: default phải phản ánh đúng hành vi HIỆN TẠI của extension để bật
   * module này lên không làm đổi gì cả.
   */
  const DEFAULTS = Object.freeze({
    // Kill-switch cho feed network hook (patch fetch/XHR trong hook.js).
    // Hiện hook được content.js kích hoạt khi productViewer bật (mặc định true),
    // nên để GIỮ NGUYÊN hành vi, default phải là true. Đặt false = tắt hẳn hook
    // network dù productViewer có bật, dùng khi cần loại trừ rủi ro checkpoint.
    enableNetworkHook: true,
    // Cho phép hydrate dữ liệu product từ feed. Giữ đúng trạng thái hiện tại.
    enableProductHydration: true,
    // Log debug có prefix [TDE]. Mặc định tắt để console sạch.
    debugLogging: false,
    // Tính năng media/player tùy chọn (Phase 5). Mặc định tắt.
    enableMediaPlayer: false,
    // Kill-switch cho chế độ "Chat tab" của Image → Prompt: extension mở và điều
    // khiển tab Gemini/ChatGPT mà người dùng đã đăng nhập (gắn ảnh, gõ prompt,
    // bấm gửi, đọc kết quả). Đây là phần dễ vỡ nhất khi provider đổi UI, và rủi
    // ro rơi vào TÀI KHOẢN người dùng (có thể bị giới hạn hoặc khoá) vì tự động
    // hoá UI trái điều khoản của cả OpenAI lẫn Google.
    //
    // Việc BẬT tính năng là do người dùng tự chọn "Chat tab" trong cài đặt AI —
    // mặc định của cài đặt đó là "API key", nên bản cài mới không bao giờ tự động
    // điều khiển tab. Flag này chỉ là kill-switch: đặt false để tắt hẳn chat-tab
    // dù người dùng đã chọn, dùng khi provider đổi UI và automation gây hại.
    // Theo quy tắc ở trên, default là true để giữ nguyên hành vi hiện tại.
    enableChatTabAutomation: true,
  });

  function getExt() {
    if (typeof chrome !== 'undefined' && chrome) return chrome;
    if (typeof browser !== 'undefined' && browser) return browser;
    return null;
  }

  function hasStorage() {
    const ext = getExt();
    return !!(ext && ext.storage && ext.storage.local);
  }

  /** Trộn giá trị đã lưu với DEFAULTS, loại bỏ key lạ. */
  function normalize(stored) {
    const out = {};
    const src = (stored && typeof stored === 'object') ? stored : {};
    for (const key of Object.keys(DEFAULTS)) {
      out[key] = (key in src) ? src[key] : DEFAULTS[key];
    }
    return out;
  }

  /** Lấy toàn bộ flag. Trả về Promise<flags>. */
  function getFlags() {
    return new Promise((resolve) => {
      if (!hasStorage()) return resolve({ ...DEFAULTS });
      try {
        getExt().storage.local.get({ [STORAGE_KEY]: null }, (data) => {
          resolve(normalize(data && data[STORAGE_KEY]));
        });
      } catch (_) {
        resolve({ ...DEFAULTS });
      }
    });
  }

  /** Lấy một flag theo tên. Trả về Promise<value>. */
  function getFlag(name) {
    return getFlags().then((flags) =>
      (name in flags) ? flags[name] : DEFAULTS[name]
    );
  }

  /** Đặt một flag. Trả về Promise<flags-mới>. Bỏ qua key không hợp lệ. */
  function setFlag(name, value) {
    if (!(name in DEFAULTS)) {
      return Promise.reject(new Error('[TDE] Unknown feature flag: ' + name));
    }
    return getFlags().then((flags) => {
      const next = { ...flags, [name]: value };
      return new Promise((resolve) => {
        if (!hasStorage()) return resolve(next);
        try {
          getExt().storage.local.set({ [STORAGE_KEY]: next }, () => resolve(next));
        } catch (_) {
          resolve(next);
        }
      });
    });
  }

  /** Đặt nhiều flag cùng lúc. patch: {name: value}. Trả về Promise<flags-mới>. */
  function setFlags(patch) {
    return getFlags().then((flags) => {
      const next = { ...flags };
      for (const key of Object.keys(patch || {})) {
        if (key in DEFAULTS) next[key] = patch[key];
      }
      return new Promise((resolve) => {
        if (!hasStorage()) return resolve(next);
        try {
          getExt().storage.local.set({ [STORAGE_KEY]: next }, () => resolve(next));
        } catch (_) {
          resolve(next);
        }
      });
    });
  }

  /**
   * Lắng nghe thay đổi flag. cb nhận (newFlags, changedKeys).
   * Trả về hàm hủy đăng ký.
   */
  function onFlagsChanged(cb) {
    const ext = getExt();
    if (!ext || !ext.storage || !ext.storage.onChanged || typeof cb !== 'function') {
      return function () {};
    }
    const handler = (changes, area) => {
      if (area !== 'local' || !changes[STORAGE_KEY]) return;
      const newFlags = normalize(changes[STORAGE_KEY].newValue);
      const oldFlags = normalize(changes[STORAGE_KEY].oldValue);
      const changed = Object.keys(DEFAULTS).filter(
        (k) => newFlags[k] !== oldFlags[k]
      );
      try { cb(newFlags, changed); } catch (_) {}
    };
    ext.storage.onChanged.addListener(handler);
    return function () {
      try { ext.storage.onChanged.removeListener(handler); } catch (_) {}
    };
  }

  const TPTFlags = {
    STORAGE_KEY,
    DEFAULTS,
    getFlags,
    getFlag,
    setFlag,
    setFlags,
    onFlagsChanged,
  };

  // Export đa môi trường.
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = TPTFlags;
  }
  root.TPTFlags = TPTFlags;
})(typeof self !== 'undefined' ? self : this);
