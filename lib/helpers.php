<?php

/**
 * Shared security helpers: sessions, CSRF, client IP detection, secret
 * encryption, login/API throttling and security headers.
 *
 * Every entry point loads config.php first and then this file.
 */

if (defined('SMTP_MAILER_HELPERS_LOADED')) {
    return;
}
define('SMTP_MAILER_HELPERS_LOADED', true);

// --- Tunable limits (override any of these in config.php) ---
$smtp_mailer_defaults = [
    'TRUSTED_PROXIES'         => [],              // IPs/CIDRs of reverse proxies allowed to set client-IP headers
    'API_MAX_BODY_BYTES'      => 30 * 1024 * 1024, // Max raw JSON request size
    'API_MAX_BODY_CHARS'      => 1000000,          // Max chars in body_html / body_text
    'API_MAX_ATTACHMENTS'     => 10,
    'API_MAX_ATTACHMENT_BYTES'=> 10 * 1024 * 1024, // Per attachment, after base64 decoding
    'API_MAX_TOTAL_ATTACHMENT_BYTES' => 20 * 1024 * 1024,
    'API_MAX_BCC'             => 50,
    'API_MAX_QUEUE_PER_PROFILE' => 5000,           // Pending emails allowed per profile
    'API_MAX_DELAY_HOURS'     => 24,               // Furthest a DELAY-strategy email may be pushed back
    'AUTH_MAX_FAILURES'       => 10,               // Failed logins/token checks per IP...
    'AUTH_WINDOW_MINUTES'     => 15,               // ...within this window before throttling
    'SESSION_IDLE_SECONDS'    => 1800,
    'SESSION_MAX_SECONDS'     => 28800,
    'QUEUE_MAX_ATTEMPTS'      => 5,
];
foreach ($smtp_mailer_defaults as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
unset($smtp_mailer_defaults, $name, $value);

// ---------------------------------------------------------------------
// Security headers
// ---------------------------------------------------------------------

function send_security_headers(bool $is_html = true): void {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    if ($is_html) {
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; "
            . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
            . "font-src 'self' https://cdnjs.cloudflare.com; "
            . "img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
        if (is_https_request()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    } else {
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    }
}

function is_https_request(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return isset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_PROTO'])
        && ip_in_list($_SERVER['REMOTE_ADDR'], TRUSTED_PROXIES)
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
}

// ---------------------------------------------------------------------
// Client IP detection
// ---------------------------------------------------------------------

/** True if $ip matches any entry (plain IP or CIDR) in $list. */
function ip_in_list(string $ip, array $list): bool {
    $ip_bin = @inet_pton($ip);
    if ($ip_bin === false) {
        return false;
    }
    foreach ($list as $entry) {
        $parts = explode('/', (string)$entry, 2);
        $net_bin = @inet_pton($parts[0]);
        if ($net_bin === false || strlen($net_bin) !== strlen($ip_bin)) {
            continue;
        }
        $bits = isset($parts[1]) ? (int)$parts[1] : strlen($net_bin) * 8;
        $full_bytes = intdiv($bits, 8);
        if (strncmp($ip_bin, $net_bin, $full_bytes) !== 0) {
            continue;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        if ((ord($ip_bin[$full_bytes]) & $mask) === (ord($net_bin[$full_bytes]) & $mask)) {
            return true;
        }
    }
    return false;
}

/**
 * Real client IP. Proxy headers are honoured only when the direct peer
 * (REMOTE_ADDR) is listed in TRUSTED_PROXIES; otherwise they are ignored,
 * so clients cannot spoof their address.
 */
function get_client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!ip_in_list($remote, TRUSTED_PROXIES)) {
        return $remote;
    }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Walk right-to-left, skipping our own proxies; the first other address is the client.
        $hops = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) && !ip_in_list($hop, TRUSTED_PROXIES)) {
                return $hop;
            }
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP']) && filter_var(trim($_SERVER['HTTP_X_REAL_IP']), FILTER_VALIDATE_IP)) {
        return trim($_SERVER['HTTP_X_REAL_IP']);
    }
    return $remote;
}

// ---------------------------------------------------------------------
// Sessions & CSRF (admin panel)
// ---------------------------------------------------------------------

function secure_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    send_security_headers(true);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Expire idle / over-age sessions.
    if (!empty($_SESSION['user_logged_in'])) {
        $now = time();
        if ($now - ($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_SECONDS
            || $now - ($_SESSION['login_time'] ?? 0) > SESSION_MAX_SECONDS) {
            destroy_session();
            session_start();
        } else {
            $_SESSION['last_activity'] = $now;
        }
    }
}

function destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Abort with 403 unless the request carries a valid CSRF token. */
function csrf_require(): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        exit('Invalid or missing CSRF token. Go back, reload the page and try again.');
    }
}

/** Escape for HTML output. */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// Failed-authentication throttling (admin login and API tokens)
// ---------------------------------------------------------------------

function auth_is_throttled(PDO $pdo, string $scope, string $ip): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM auth_attempts WHERE scope = ? AND ip_address = ? AND created_at >= NOW() - INTERVAL ? MINUTE"
    );
    $stmt->execute([$scope, $ip, AUTH_WINDOW_MINUTES]);
    return (int)$stmt->fetchColumn() >= AUTH_MAX_FAILURES;
}

function auth_record_failure(PDO $pdo, string $scope, string $ip): void {
    $pdo->prepare("INSERT INTO auth_attempts (scope, ip_address) VALUES (?, ?)")->execute([$scope, $ip]);
    if (random_int(1, 50) === 1) { // opportunistic cleanup
        $pdo->exec("DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL 1 DAY");
    }
}

function auth_clear_failures(PDO $pdo, string $scope, string $ip): void {
    $pdo->prepare("DELETE FROM auth_attempts WHERE scope = ? AND ip_address = ?")->execute([$scope, $ip]);
}

// ---------------------------------------------------------------------
// Secret encryption (SMTP passwords)
// ---------------------------------------------------------------------

const ENCRYPTION_PREFIX = 'v2:';

function encryption_key_raw(): string {
    $key = getenv('SMTP_MAILER_ENCRYPTION_KEY');
    if ($key === false || $key === '') {
        $key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '';
    }
    if ($key === '' || $key === 'change-this-to-a-random-32-char-string' || strlen($key) < 32) {
        throw new RuntimeException('ENCRYPTION_KEY is missing, still the placeholder, or shorter than 32 characters. Set a strong random key in config.php.');
    }
    return $key;
}

/** Authenticated encryption (libsodium secretbox). Output: "v2:" + base64(nonce . ciphertext). */
function encrypt_secret(string $plaintext): string {
    $key = hash('sha256', encryption_key_raw(), true);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return ENCRYPTION_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
}

/** Decrypts v2 values, and the legacy AES-256-CBC format. Returns false on failure. */
function decrypt_secret(string $stored) {
    $raw_key = encryption_key_raw();
    if (strncmp($stored, ENCRYPTION_PREFIX, strlen(ENCRYPTION_PREFIX)) === 0) {
        $bin = base64_decode(substr($stored, strlen(ENCRYPTION_PREFIX)), true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return sodium_crypto_secretbox_open($cipher, $nonce, hash('sha256', $raw_key, true));
    }
    // Legacy format: base64(openssl_base64_ciphertext . '::' . iv)
    $decoded = base64_decode($stored, true);
    if ($decoded === false || strpos($decoded, '::') === false) {
        return false;
    }
    list($data, $iv) = explode('::', $decoded, 2);
    if (strlen($iv) !== openssl_cipher_iv_length('aes-256-cbc')) {
        return false;
    }
    return openssl_decrypt($data, 'aes-256-cbc', $raw_key, 0, $iv);
}

function is_legacy_secret(string $stored): bool {
    return strncmp($stored, ENCRYPTION_PREFIX, strlen(ENCRYPTION_PREFIX)) !== 0;
}

// ---------------------------------------------------------------------
// Misc
// ---------------------------------------------------------------------

/** Log the real error server-side; the UI only gets a generic message. */
function log_exception(Throwable $e, string $context = ''): void {
    error_log('[smtp-mailer] ' . ($context ? $context . ': ' : '') . $e->getMessage());
}

/** Validate an SMTP host name or IP literal. */
function is_valid_smtp_host(string $host): bool {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return true;
    }
    return strlen($host) <= 253 && (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host);
}
