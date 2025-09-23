<?php
// Set the content type to JSON for all responses
header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';

/**
 * Get the real client IP address, considering proxies.
 * @return string The client's IP address.
 */
function get_client_ip() {
    if (isset($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // HTTP_X_FORWARDED_FOR can contain a comma-separated list of IPs. The client's IP is usually the first one.
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'];
}

/**
 * Send a JSON error response and exit.
 * @param int $status_code The HTTP status code.
 * @param string $message The error message.
 */
function send_json_error($status_code, $message) {
    http_response_code($status_code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// --- 1. Basic Request Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error(405, 'Method Not Allowed. Only POST requests are accepted.');
}

// --- 2. Authorization ---
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    send_json_error(401, 'Authorization header is missing.');
}

$auth_header = $_SERVER['HTTP_AUTHORIZATION'];
if (strpos($auth_header, 'Bearer ') !== 0) {
    send_json_error(401, 'Invalid Authorization header format. Expected: Bearer <token>');
}
$token = substr($auth_header, 7);

if (strpos($token, '.') === false) {
    send_json_error(401, 'Invalid token format. Expected: <prefix>.<secret>');
}
list($prefix, $secret) = explode('.', $token, 2);

// --- 3. Input Parsing & Validation ---
$json_data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    send_json_error(400, 'Invalid JSON payload.');
}

$profile_id = filter_var($json_data['profile_id'] ?? null, FILTER_VALIDATE_INT);
$to_email = filter_var($json_data['to_email'] ?? null, FILTER_VALIDATE_EMAIL);
$subject = trim($json_data['subject'] ?? '');
$body_html = trim($json_data['body_html'] ?? '');
$body_text = trim($json_data['body_text'] ?? '');
$cc_email = filter_var($json_data['cc_email'] ?? null, FILTER_VALIDATE_EMAIL);
$bcc_email = filter_var($json_data['bcc_email'] ?? null, FILTER_VALIDATE_EMAIL);

if (!$profile_id) {
    send_json_error(400, '`profile_id` is required and must be an integer.');
}
if (!$to_email) {
    send_json_error(400, 'A valid `to_email` is required.');
}
if (empty($subject)) {
    send_json_error(400, '`subject` is required.');
}
if (empty($body_html) && empty($body_text)) {
    send_json_error(400, 'Either `body_html` or `body_text` must be provided.');
}


// --- 4. Token and Profile Verification ---
$stmt = $pdo->prepare(
    "SELECT p.*, t.token_hash FROM sending_profiles p JOIN api_tokens t ON p.id = t.profile_id WHERE p.id = ? AND t.token_prefix = ?"
);
$stmt->execute([$profile_id, $prefix]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile || !password_verify($secret, $profile['token_hash'])) {
    send_json_error(403, 'Forbidden. The provided token is not valid for the specified profile_id.');
}

$client_ip = get_client_ip();

// --- 5. Rate Limiting ---
if ($profile['rate_limit_count'] > 0) {
    // First, check if this IP is already on a temporary block.
    $check_stmt = $pdo->prepare("SELECT id FROM rate_limit_tracker WHERE ip_address = ? AND profile_id = ? AND blocked_until > NOW()");
    $check_stmt->execute([$client_ip, $profile_id]);
    if ($check_stmt->fetch()) {
        send_json_error(429, 'Too Many Requests. This IP is temporarily blocked due to exceeding rate limits.');
    }

    // If not blocked, check the number of recent submissions.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM email_queue WHERE profile_id = ? AND ip_address = ? AND submitted_at >= NOW() - INTERVAL ? MINUTE"
    );
    $stmt->execute([$profile_id, $client_ip, $profile['rate_limit_interval']]);
    $queued_count = $stmt->fetchColumn();

    if ($queued_count >= $profile['rate_limit_count']) {
        // Log the rate limit violation and block the IP
        $block_duration_minutes = 5; // Block for 5 minutes by default
        $insert_stmt = $pdo->prepare(
            "INSERT INTO rate_limit_tracker (ip_address, profile_id, blocked_until) VALUES (?, ?, NOW() + INTERVAL ? MINUTE)"
        );
        $insert_stmt->execute([$client_ip, $profile_id, $block_duration_minutes]);

        send_json_error(429, 'Too Many Requests. Rate limit exceeded for this IP on this profile. Please try again later.');
    }
}

// --- 6. Queue the Email ---
$message_id = bin2hex(random_bytes(18)); // Generate a 36-char UUID

try {
    $stmt = $pdo->prepare(
        "INSERT INTO email_queue (id, profile_id, ip_address, recipient_email, subject, body_html, body_text) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $message_id,
        $profile_id,
        $client_ip,
        $to_email,
        $subject,
        $body_html ?: null,
        $body_text ?: null
    ]);

    http_response_code(202);
    echo json_encode(['status' => 'queued', 'message_id' => $message_id]);

} catch (PDOException $e) {
    // In a real app, log this error to a file
    error_log($e->getMessage());
    send_json_error(500, 'Internal Server Error. Could not queue the email.');
}
?>

