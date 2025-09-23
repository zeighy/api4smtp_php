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
if (sscanf($auth_header, 'Bearer %s', $token) !== 1) {
    send_json_error(401, 'Invalid Authorization header format. Expected: Bearer <token>');
}

// --- 3. Find the email and its associated profile ---
$email_data = null;
$profile_id = null;

// First, check the queue
$stmt = $pdo->prepare("SELECT * FROM email_queue WHERE message_id = ?");
$stmt->execute([$message_id]);
$email_data = $stmt->fetch(PDO::FETCH_ASSOC);

if ($email_data) {
    $profile_id = $email_data['profile_id'];
    $email_data['current_status'] = 'queued';
} else {
    // If not in queue, check history
    $stmt = $pdo->prepare("SELECT * FROM email_history WHERE message_id = ?");
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
$stmt = $pdo->prepare("SELECT token_hash FROM api_tokens WHERE profile_id = ?");
$stmt->execute([$profile_id]);
$token_hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$is_authorized = false;
if ($token_hashes) {
    foreach ($token_hashes as $hash) {
        if (password_verify($token, $hash)) {
            $is_authorized = true;
            break;
        }
    }
}

if (!$is_authorized) {
    send_json_error(403, 'Forbidden. The provided token is not authorized to query the status of this email.');
}

// --- 5. Return the Status ---
$response = [
    'message_id' => $email_data['message_id'],
    'status' => $email_data['current_status'],
    'recipient' => $email_data['to_email']
];

if ($email_data['current_status'] === 'queued') {
    $response['queued_at'] = $email_data['created_at'];
} elseif ($email_data['current_status'] === 'sent') {
    $response['sent_at'] = $email_data['processed_at'];
} elseif ($email_data['current_status'] === 'failed') {
    $response['failed_at'] = $email_data['processed_at'];
    $response['error_message'] = $email_data['error_message'];
}

http_response_code(200);
echo json_encode($response);
?>
