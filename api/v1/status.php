<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/api.php';

api_init();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    send_json_error(405, 'Method Not Allowed. Only GET requests are accepted.');
}

$client_ip = get_client_ip();
$auth = api_authenticate($pdo, $client_ip);

$message_id = $_GET['message_id'] ?? null;
if (!is_string($message_id) || !preg_match('/^[0-9a-f]{36}$/', $message_id)) {
    send_json_error(400, 'A valid `message_id` is required as a URL parameter.');
}

// Only look at messages that belong to the token's own profile, so other
// profiles' message IDs are indistinguishable from nonexistent ones.
$stmt = $pdo->prepare("SELECT id, status, recipient_email, submitted_at FROM email_queue WHERE id = ? AND profile_id = ?");
$stmt->execute([$message_id, $auth['profile_id']]);
$email = $stmt->fetch(PDO::FETCH_ASSOC);

if ($email) {
    $response = [
        'message_id' => $email['id'],
        'status'     => $email['status'],
        'recipient'  => $email['recipient_email'],
        'queued_at'  => $email['submitted_at'],
    ];
} else {
    $stmt = $pdo->prepare("SELECT id, status, recipient_email, sent_at FROM email_logs WHERE id = ? AND profile_id = ?");
    $stmt->execute([$message_id, $auth['profile_id']]);
    $email = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$email) {
        send_json_error(404, 'Not Found. No email found with the specified message_id.');
    }
    $response = [
        'message_id' => $email['id'],
        'status'     => $email['status'],
        'recipient'  => $email['recipient_email'],
    ];
    if ($email['status'] === 'sent') {
        $response['sent_at'] = $email['sent_at'];
    } else {
        // Raw SMTP error text can reveal internal mail-server details; it stays in the admin logs.
        $response['error_message'] = 'Delivery failed. See the admin email logs for details.';
    }
}

http_response_code(200);
echo json_encode($response);
