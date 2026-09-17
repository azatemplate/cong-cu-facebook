<?php
// includes/security.php
// ============================================================
// Centralized Security Helpers
// CSRF protection, authentication guards, rate limiting,
// and HTTP security headers.
// ============================================================

// ── CSRF Token Management ────────────────────────────────────────────────────

/**
 * Generate or retrieve the current CSRF token for this session.
 */
function csrf_token(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Output a hidden input field with the CSRF token.
 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

/**
 * Verify the CSRF token from the request.
 * Call this at the top of every POST handler.
 * For AJAX requests, also accepts token via X-CSRF-Token header.
 */
function verify_csrf(): void {
    $token = $_POST['_csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        if (is_ajax_request()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.']);
        } else {
            echo '<h2 style="text-align:center;color:red;margin-top:50px;">403 — CSRF Token không hợp lệ. Vui lòng tải lại trang.</h2>';
        }
        exit;
    }
}

// ── Authentication Guards ────────────────────────────────────────────────────

/**
 * Require the user to be logged in.
 * Redirects to login page for web requests, returns 401 JSON for AJAX.
 */
function require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['account_id'])) {
        if (is_ajax_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
            exit;
        }
        header('Location: ' . get_base_url() . 'login.php');
        exit;
    }
}

/**
 * Require the user to be an admin.
 */
function require_admin(): void {
    require_auth();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        if (is_ajax_request()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền thực hiện thao tác này.']);
            exit;
        }
        http_response_code(403);
        echo '<h2 style="text-align:center;color:red;margin-top:50px;">403 — Truy cập bị từ chối</h2>';
        exit;
    }
}

// ── Rate Limiting (file-based, no external dependency) ───────────────────────

/**
 * Simple file-based rate limiter for login attempts.
 * Returns true if the request is allowed, false if rate-limited.
 *
 * @param string $identifier  IP address or username
 * @param int    $maxAttempts Maximum attempts allowed
 * @param int    $windowSecs  Time window in seconds
 */
function rate_limit_check(string $identifier, int $maxAttempts = 5, int $windowSecs = 900): bool {
    $rate_dir = __DIR__ . '/../uploads/rate_limits';
    if (!is_dir($rate_dir)) {
        @mkdir($rate_dir, 0755, true);
    }

    $file = $rate_dir . '/' . md5($identifier) . '.json';

    $attempts = [];
    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            // Filter out expired attempts
            $cutoff = time() - $windowSecs;
            $attempts = array_filter($data, fn($ts) => $ts > $cutoff);
        }
    }

    if (count($attempts) >= $maxAttempts) {
        // Still save the cleaned-up list
        file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
        return false; // Rate limited
    }

    return true; // Allowed
}

/**
 * Record a failed login attempt.
 */
function rate_limit_record(string $identifier): void {
    $rate_dir = __DIR__ . '/../uploads/rate_limits';
    if (!is_dir($rate_dir)) {
        @mkdir($rate_dir, 0755, true);
    }

    $file = $rate_dir . '/' . md5($identifier) . '.json';

    $attempts = [];
    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            $cutoff = time() - 900; // 15 minute window
            $attempts = array_filter($data, fn($ts) => $ts > $cutoff);
        }
    }

    $attempts[] = time();
    file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
}

/**
 * Clear rate limit for an identifier (called on successful login).
 */
function rate_limit_clear(string $identifier): void {
    $rate_dir = __DIR__ . '/../uploads/rate_limits';
    $file = $rate_dir . '/' . md5($identifier) . '.json';
    if (file_exists($file)) {
        @unlink($file);
    }
}

// ── HTTP Security Headers ────────────────────────────────────────────────────

/**
 * Set security-related HTTP headers.
 * Call early in the request lifecycle, before any output.
 */
function set_security_headers(): void {
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // XSS protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy — don't leak full URL to third parties
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions Policy — disable unused browser features
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // Prevent caching of sensitive pages
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// ── Input Sanitization Helpers ───────────────────────────────────────────────

/**
 * Sanitize a string for safe output (shorthand for htmlspecialchars).
 */
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ── Utility ──────────────────────────────────────────────────────────────────

/**
 * Check if the current request is an AJAX/API request.
 */
function is_ajax_request(): bool {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

/**
 * Get the base URL path for redirects.
 */
function get_base_url(): string {
    $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    // Ensure trailing slash
    return rtrim($script_dir, '/') . '/';
}
?>
