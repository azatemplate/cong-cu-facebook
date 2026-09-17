/**
 * logger.js — HONGDOLABS - Trợ thủ TopTop
 *
 * Logger có kiểm soát. Mặc định IM LẶNG; chỉ in khi flag debugLogging bật.
 * Mọi log có prefix [TDE] để dễ lọc trong console. error() luôn in vì lỗi
 * cần thấy được kể cả khi debug tắt.
 *
 * Dùng đa môi trường:
 *   - Service worker : importScripts('feature-flags.js','logger.js'); self.TPTLog
 *   - Popup / content : <script src="logger.js"> sau feature-flags.js; window.TPTLog
 *
 * Logger tự đồng bộ trạng thái debug từ TPTFlags nếu có. Nếu TPTFlags chưa nạp,
 * logger vẫn hoạt động (mặc định tắt debug) và có thể bật thủ công qua setDebug().
 */
(function (root) {
  'use strict';

  const PREFIX = '[TDE]';
  let _debug = false;
  let _synced = false;

  const _console = (typeof console !== 'undefined') ? console : {
    log() {}, info() {}, warn() {}, error() {}, debug() {},
  };

  /** Đồng bộ trạng thái debug từ TPTFlags (nếu có) và theo dõi thay đổi. */
  function _syncFromFlags() {
    if (_synced) return;
    const flags = root.TPTFlags;
    if (!flags || typeof flags.getFlag !== 'function') return;
    _synced = true;
    try {
      flags.getFlag('debugLogging').then((v) => { _debug = !!v; }).catch(() => {});
      if (typeof flags.onFlagsChanged === 'function') {
        flags.onFlagsChanged((newFlags) => { _debug = !!newFlags.debugLogging; });
      }
    } catch (_) {}
  }

  /** Bật/tắt debug thủ công (bỏ qua flags). */
  function setDebug(on) { _debug = !!on; }

  /** Trạng thái debug hiện tại. */
  function isDebug() { return _debug; }

  function log(...args) { if (_debug) _console.log(PREFIX, ...args); }
  function info(...args) { if (_debug) _console.info(PREFIX, ...args); }
  function warn(...args) { if (_debug) _console.warn(PREFIX, ...args); }
  // error luôn in: lỗi cần thấy được ngay cả khi debug tắt.
  function error(...args) { _console.error(PREFIX, ...args); }

  /**
   * Tạo logger con có namespace phụ, ví dụ TPTLog.scope('hook').
   * Mọi log sẽ thành: [TDE][hook] ...
   */
  function scope(name) {
    const tag = '[' + name + ']';
    return {
      log: (...a) => { if (_debug) _console.log(PREFIX, tag, ...a); },
      info: (...a) => { if (_debug) _console.info(PREFIX, tag, ...a); },
      warn: (...a) => { if (_debug) _console.warn(PREFIX, tag, ...a); },
      error: (...a) => _console.error(PREFIX, tag, ...a),
    };
  }

  _syncFromFlags();

  const TPTLog = { log, info, warn, error, scope, setDebug, isDebug };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = TPTLog;
  }
  root.TPTLog = TPTLog;
})(typeof self !== 'undefined' ? self : this);
