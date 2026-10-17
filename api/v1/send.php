<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/api.php';

api_init();

$client_ip = get_client_ip();

// --- 1. Basic Request Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_json_error(405, 'Method Not Allowed. Only POST requests are accepted.');
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > API_MAX_BODY_BYTES) {
    send_json_error(413, 'Request body too large.');
}

// --- 2. Authorization (before any payload processing) ---
$auth = api_authenticate($pdo, $client_ip);
$profile_id = (int)$auth['profile_id'];

// --- 3. Input Parsing & Validation ---
$raw_body = file_get_contents('php://input', false, null, 0, API_MAX_BODY_BYTES + 1);
if ($raw_body === false || strlen($raw_body) > API_MAX_BODY_BYTES) {
    send_json_error(413, 'Request body too large.');
}
$json_data = json_decode($raw_body, true);
unset($raw_body);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($json_data)) {
    send_json_error(400, 'Invalid JSON payload.');
}

// `profile_id` is optional (the token already identifies the profile) but must match if sent.
if (isset($json_data['profile_id']) && (int)$json_data['profile_id'] !== $profile_id) {
    send_json_error(403, 'Forbidden. The provided token is invalid, expired or not authorized for this resource.');
}

/** Returns a trimmed string for a scalar-string field, or null if missing/not a string. */
function str_field(array $data, string $key): ?string {
    return isset($data[$key]) && is_string($data[$key]) ? trim($data[$key]) : null;
}

function valid_address($value): bool {
    return is_string($value) && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

$to_email  = str_field($json_data, 'to_email');
$subject   = str_field($json_data, 'subject');
$body_html = str_field($json_data, 'body_html') ?? '';
$body_text = str_field($json_data, 'body_text') ?? '';

if ($to_email === null || !valid_address($to_email)) {
    send_json_error(400, 'A valid `to_email` is required.');
}
if ($subject === null || $subject === '') {
    send_json_error(400, '`subject` is required.');
}
if (mb_strlen($subject) > 255) {
    send_json_error(400, '`subject` must be 255 characters or fewer.');
}
if (preg_match('/[\r\n\0]/', $subject)) {
    send_json_error(400, '`subject` must not contain line breaks.');
}
if ($body_html === '' && $body_text === '') {
    send_json_error(400, 'Either `body_html` or `body_text` must be provided.');
}
if (mb_strlen($body_html) > API_MAX_BODY_CHARS || mb_strlen($body_text) > API_MAX_BODY_CHARS) {
    send_json_error(400, '`body_html` and `body_text` must each be ' . API_MAX_BODY_CHARS . ' characters or fewer.');
}

$cc_email = null;
if (array_key_exists('cc_email', $json_data) && $json_data['cc_email'] !== null) {
    if (!valid_address($json_data['cc_email'])) {
        send_json_error(400, 'A valid `cc_email` must be provided if the key exists.');
    }
    $cc_email = $json_data['cc_email'];
}

$bcc_emails = $json_data['bcc_emails'] ?? [];
if (!is_array($bcc_emails) || count($bcc_emails) > API_MAX_BCC) {
    send_json_error(400, '`bcc_emails` must be an array of at most ' . API_MAX_BCC . ' email addresses.');
}
foreach ($bcc_emails as $bcc_email) {
    if (!valid_address($bcc_email)) {
        send_json_error(400, '`bcc_emails` contains an invalid email address.');
    }
}
$bcc_emails = array_values(array_unique($bcc_emails));

$attachments = $json_data['attachments'] ?? [];
if (!is_array($attachments) || count($attachments) > API_MAX_ATTACHMENTS) {
    send_json_error(400, '`attachments` must be an array of at most ' . API_MAX_ATTACHMENTS . ' attachment objects.');
}

$clean_attachments = [];
$total_bytes = 0;
foreach ($attachments as $attachment) {
    if (!is_array($attachment)) {
        send_json_error(400, 'Each attachment must be an object.');
    }
    $filename = $attachment['filename'] ?? null;
    $content_type = $attachment['content_type'] ?? null;
    $content = $attachment['content'] ?? null;
    if (!is_string($filename) || $filename === '') {
        send_json_error(400, 'Each attachment must have a `filename` string.');
    }
    // Drop any path component and control characters from the filename.
    $filename = preg_replace('/[\x00-\x1f\x7f"\\\\\/]+/', '_', basename(str_replace('\\', '/', $filename)));
    if ($filename === '' || mb_strlen($filename) > 255) {
        send_json_error(400, 'Attachment `filename` is invalid or longer than 255 characters.');
    }
    if (!is_string($content_type) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]{0,126}/[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]{0,126}$#', $content_type)) {
        send_json_error(400, 'Each attachment must have a valid `content_type` string.');
    }
    if (!is_string($content) || $content === '') {
        send_json_error(400, 'Each attachment must have `content` as a base64 encoded string.');
    }
    // Reject oversized payloads before allocating the decoded copy.
    if ((int)(strlen($content) * 3 / 4) > API_MAX_ATTACHMENT_BYTES) {
        send_json_error(413, 'An attachment exceeds the maximum size of ' . (API_MAX_ATTACHMENT_BYTES / 1048576) . ' MB.');
    }
    $decoded = base64_decode($content, true);
    if ($decoded === false) {
        send_json_error(400, 'Attachment `content` must be a valid base64 encoded string.');
    }
    $total_bytes += strlen($decoded);
    if (strlen($decoded) > API_MAX_ATTACHMENT_BYTES || $total_bytes > API_MAX_TOTAL_ATTACHMENT_BYTES) {
        send_json_error(413, 'Attachment size limit exceeded.');
    }
    $inline = false;
    $cid = null;
    if (array_key_exists('inline', $attachment)) {
        if (!is_bool($attachment['inline'])) {
            send_json_error(400, 'Attachment `inline` flag must be a boolean.');
        }
        $inline = $attachment['inline'];
    }
    if ($inline) {
        $cid = $attachment['cid'] ?? null;
        if (!is_string($cid) || !preg_match('/^[A-Za-z0-9._@+-]{1,200}$/', $cid)) {
            send_json_error(400, 'Inline attachments must have a valid `cid` string.');
        }
    }
    $clean_attachments[] = [$filename, $content_type, $decoded, $inline ? 1 : 0, $cid];
}
unset($attachments, $json_data);

// --- 4. Rate limiting & queueing (serialised per profile) ---
$message_id = bin2hex(random_bytes(18));

try {
    $pdo->beginTransaction();

    // Lock the profile row so concurrent requests cannot all pass the same limit check.
    $pdo->prepare("SELECT id FROM sending_profiles WHERE id = ? FOR UPDATE")->execute([$profile_id]);

    // Backlog cap per profile.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE profile_id = ?");
    $stmt->execute([$profile_id]);
    if ((int)$stmt->fetchColumn() >= API_MAX_QUEUE_PER_PROFILE) {
        $pdo->rollBack();
        header('Retry-After: 300');
        send_json_error(503, 'The sending queue for this profile is full. Try again later.');
    }

    $limit = (int)$auth['rate_limit_count'];
    $interval = max(1, (int)$auth['rate_limit_interval']);
    $send_at_expr = 'NOW()';
    $send_at_params = [];

    if ($limit > 0) {
        // An IP that already tripped the REJECT limiter stays blocked until the block expires.
        $stmt = $pdo->prepare("SELECT 1 FROM rate_limit_tracker WHERE ip_address = ? AND profile_id = ? AND blocked_until > NOW() LIMIT 1");
        $stmt->execute([$client_ip, $profile_id]);
        if ($stmt->fetchColumn()) {
            $pdo->rollBack();
            send_json_error(429, 'Too Many Requests. This IP is temporarily blocked due to exceeding rate limits.');
        }

        // Count recent submissions from this IP, whether still queued or already processed.
        $stmt = $pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM email_queue WHERE profile_id = :p1 AND ip_address = :i1 AND submitted_at >= NOW() - INTERVAL :m1 MINUTE)
                  + (SELECT COUNT(*) FROM email_logs  WHERE profile_id = :p2 AND ip_address = :i2 AND submitted_at >= NOW() - INTERVAL :m2 MINUTE)"
        );
        $stmt->execute([':p1' => $profile_id, ':i1' => $client_ip, ':m1' => $interval, ':p2' => $profile_id, ':i2' => $client_ip, ':m2' => $interval]);
        $recent = (int)$stmt->fetchColumn();

        if ($recent >= $limit) {
            if ($auth['rate_limit_strategy'] === 'DELAY') {
                // Stagger: the next slot is one interval-share after the latest scheduled send for this IP.
                $stagger = max(1, (int)ceil(($interval * 60) / $limit));
                $stmt = $pdo->prepare(
                    "SELECT GREATEST(NOW(), COALESCE(MAX(send_at), NOW())) + INTERVAL ? SECOND,
                            TIMESTAMPDIFF(HOUR, NOW(), GREATEST(NOW(), COALESCE(MAX(send_at), NOW())))
                     FROM email_queue WHERE profile_id = ? AND ip_address = ?"
                );
                $stmt->execute([$stagger, $profile_id, $client_ip]);
                list($next_slot, $hours_ahead) = $stmt->fetch(PDO::FETCH_NUM);
                if ((int)$hours_ahead >= API_MAX_DELAY_HOURS) {
                    $pdo->rollBack();
                    header('Retry-After: 600');
                    send_json_error(429, 'Too Many Requests. The delayed-send backlog for this IP is full.');
                }
                $send_at_expr = '?';
                $send_at_params[] = $next_slot;
            } else {
                $pdo->prepare("INSERT INTO rate_limit_tracker (ip_address, profile_id, blocked_until) VALUES (?, ?, NOW() + INTERVAL 5 MINUTE)")
                    ->execute([$client_ip, $profile_id]);
                $pdo->commit();
                send_json_error(429, 'Too Many Requests. Rate limit exceeded for this IP on this profile. Please try again later.');
            }
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO email_queue (id, profile_id, ip_address, recipient_email, cc_email, bcc_email, subject, body_html, body_text, send_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, $send_at_expr)"
    );
    $stmt->execute(array_merge([
        $message_id,
        $profile_id,
        $client_ip,
        $to_email,
        $cc_email,
        empty($bcc_emails) ? null : json_encode($bcc_emails),
        $subject,
        $body_html !== '' ? $body_html : null,
        $body_text !== '' ? $body_text : null,
    ], $send_at_params));

    if (!empty($clean_attachments)) {
        $attachment_stmt = $pdo->prepare(
            "INSERT INTO email_attachments (email_id, filename, content_type, content, `inline`, cid) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($clean_attachments as $a) {
            $attachment_stmt->execute([$message_id, $a[0], $a[1], $a[2], $a[3], $a[4]]);
        }
    }

    $stmt = $pdo->prepare("SELECT send_at FROM email_queue WHERE id = ?");
    $stmt->execute([$message_id]);
    $send_at = $stmt->fetchColumn();
    $pdo->commit();

    http_response_code(202);
    echo json_encode(['status' => 'queued', 'message_id' => $message_id, 'send_at' => $send_at]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_exception($e, 'send.php');
    send_json_error(500, 'Internal Server Error. Could not queue the email.');
}
