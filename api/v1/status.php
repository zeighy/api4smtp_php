<?php
// Set the content type to JSON for all responses
header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';

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
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_error(405, 'Method Not Allowed. Only GET requests are accepted.');
}

$message_id = $_GET['message_id'] ?? null;
if (empty($message_id) || !ctype_alnum($message_id)) { // Basic validation
    send_json_error(400, 'A valid `message_id` is required as a URL parameter.');
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


// --- 3. Find the email and its associated profile ---
$email_data = null;
$profile_id = null;

// First, check the queue
$stmt = $pdo->prepare("SELECT * FROM email_queue WHERE id = ?");
$stmt->execute([$message_id]);
$email_data = $stmt->fetch(PDO::FETCH_ASSOC);

if ($email_data) {
    $profile_id = $email_data['profile_id'];
    $email_data['current_status'] = $email_data['status'];
} else {
    // If not in queue, check history
    $stmt = $pdo->prepare("SELECT * FROM email_logs WHERE id = ?");
    $stmt->execute([$message_id]);
    $email_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($email_data) {
        $profile_id = $email_data['profile_id'];
        $email_data['current_status'] = $email_data['status']; // 'sent' or 'failed'
    }
}

if (!$email_data) {
    send_json_error(404, 'Not Found. No email found with the specified message_id.');
}

// --- 4. Verify Token against the email's profile_id ---
$stmt = $pdo->prepare("SELECT token_hash FROM api_tokens WHERE profile_id = ? AND token_prefix = ?");
$stmt->execute([$profile_id, $prefix]);
$token_hash = $stmt->fetchColumn();

if (!$token_hash || !password_verify($secret, $token_hash)) {
    send_json_error(403, 'Forbidden. The provided token is not authorized to query the status of this email.');
}

// --- 5. Return the Status ---
$response = [
    'message_id' => $email_data['id'],
    'status' => $email_data['current_status'],
    'recipient' => $email_data['recipient_email']
];

if ($email_data['current_status'] === 'queued' || $email_data['current_status'] === 'processing') {
    $response['queued_at'] = $email_data['submitted_at'];
} elseif ($email_data['current_status'] === 'sent') {
    $response['sent_at'] = $email_data['sent_at'];
} elseif ($email_data['current_status'] === 'failed') {
    $response['failed_at'] = $email_data['sent_at']; // sent_at is used to store the time of the last attempt
    $response['error_message'] = $email_data['status_info'];
}

http_response_code(200);
echo json_encode($response);
?>
