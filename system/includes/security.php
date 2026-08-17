<?php
/**
 * CenLearn — Security Helpers
 * ============================
 * CSRF tokens, rate limiting, security headers, safe output helpers.
 * Include this file in every page that handles user input.
 */

// ── Security Headers (suppress clickjacking, MIME sniffing, XSS) ──────────
if(!headers_sent()){
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ── CSRF Token ────────────────────────────────────────────────────────────
/**
 * Generate or retrieve the session CSRF token.
 * Call csrf_token() to embed it in forms/AJAX.
 */
function csrf_token(): string {
    if(session_status() === PHP_SESSION_NONE) session_start();
    if(empty($_SESSION['csrf_token'])){
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token.
 * Call in every POST handler. Returns false on failure.
 */
function csrf_verify(string $submitted): bool {
    if(session_status() === PHP_SESSION_NONE) session_start();
    $stored = $_SESSION['csrf_token'] ?? '';
    if(!$stored || !$submitted) return false;
    return hash_equals($stored, $submitted);
}

/**
 * Shorthand: verify CSRF from POST and die with JSON error if invalid.
 * Use at the top of every AJAX handler.
 */
function csrf_guard(): void {
    $token = trim($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if(!csrf_verify($token)){
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success'=>false,'msg'=>'Invalid or expired security token. Please refresh the page.']);
        exit;
    }
}

// ── Login Rate Limiter ─────────────────────────────────────────────────────
/**
 * Check if an IP/username combo is rate-limited.
 * Stores attempt counts in DB table `login_attempts`.
 * Returns true if blocked, false if allowed.
 */
function rate_limit_check($conn, string $identifier): bool {
    $id  = $conn->real_escape_string(substr($identifier, 0, 100));
    $now = date('Y-m-d H:i:s');

    // Auto-create table if missing
    $conn->query("CREATE TABLE IF NOT EXISTS `login_attempts` (
        `id`         int(11)     NOT NULL AUTO_INCREMENT,
        `identifier` varchar(150) NOT NULL,
        `attempted_at` datetime  NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `identifier` (`identifier`),
        KEY `attempted_at` (`attempted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Clean up attempts older than 15 minutes
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");

    // Count recent attempts
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM login_attempts WHERE identifier='$id'");
    $cnt = (int)($res->fetch_assoc()['cnt'] ?? 0);

    return $cnt >= 5; // blocked after 5 attempts in 15 min
}

/**
 * Record a failed login attempt.
 */
function rate_limit_record($conn, string $identifier): void {
    $id = $conn->real_escape_string(substr($identifier, 0, 100));
    $conn->query("INSERT INTO login_attempts (identifier) VALUES ('$id')");
}

/**
 * Clear rate-limit record on successful login.
 */
function rate_limit_clear($conn, string $identifier): void {
    $id = $conn->real_escape_string(substr($identifier, 0, 100));
    $conn->query("DELETE FROM login_attempts WHERE identifier='$id'");
}

// ── Safe File Download Header ──────────────────────────────────────────────
/**
 * Set a safe Content-Disposition header (RFC 6266 compliant).
 */
function safe_download_header(string $filename): void {
    $ascii    = preg_replace('/[^\x20-\x7e]/', '_', $filename);
    $encoded  = rawurlencode($filename);
    header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''{$encoded}");
}
