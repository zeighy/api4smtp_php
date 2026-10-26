<?php

/** Helpers shared by the JSON API endpoints. */

require_once __DIR__ . '/helpers.php';

function api_init(): void {
    header('Content-Type: application/json');
    send_security_headers(false);
}

function send_json_error(int $status_code, string $message): void {
    http_response_code($status_code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function get_authorization_header(): string {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) {
            return $_SERVER[$key];
        }
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return $value;
            }
        }
    }
    return '';
}

/**
 * Authenticate the bearer token ("<prefix>.<secret>"). Returns the token row
 * joined with its profile's rate-limit settings. Every failure looks the same
 * to the caller, and repeated failures from one IP are throttled.
 */
function api_authenticate(PDO $pdo, string $client_ip): array {
    if (auth_is_throttled($pdo, 'api', $client_ip)) {
        header('Retry-After: ' . (AUTH_WINDOW_MINUTES * 60));
        send_json_error(429, 'Too many failed authentication attempts. Try again later.');
    }

    $header = get_authorization_header();
    if ($header === '') {
        send_json_error(401, 'Authorization header is missing.');
    }
    if (stripos($header, 'Bearer ') !== 0) {
        send_json_error(401, 'Invalid Authorization header format. Expected: Bearer <token>');
    }
    $token = trim(substr($header, 7));
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || !preg_match('/^st_[0-9a-f]{6}$/', $parts[0]) || $parts[1] === '') {
        auth_record_failure($pdo, 'api', $client_ip);
        send_json_error(401, 'Invalid token format. Expected: <prefix>.<secret>');
    }
    list($prefix, $secret) = $parts;

    $stmt = $pdo->prepare(
        "SELECT t.id AS token_id, t.profile_id, t.token_hash, t.expires_at,
                (t.expires_at IS NOT NULL AND t.expires_at <= NOW()) AS expired,
                p.rate_limit_count, p.rate_limit_interval, p.rate_limit_strategy
         FROM api_tokens t
         JOIN sending_profiles p ON p.id = t.profile_id
         WHERE t.token_prefix = ?"
    );
    $stmt->execute([$prefix]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always run a bcrypt verification so unknown prefixes cost the same time.
    $hash = $row['token_hash'] ?? '$2y$10$Y/Kx8NM01BqUlo01OG9j8up.AcSJIHnpPgwwJUar1ybO2nUnp9C6i';
    $ok = password_verify($secret, $hash);

    if (!$row || !$ok || $row['expired']) {
        auth_record_failure($pdo, 'api', $client_ip);
        send_json_error(403, 'Forbidden. The provided token is invalid, expired or not authorized for this resource.');
    }

    $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?")->execute([$row['token_id']]);
    return $row;
}
