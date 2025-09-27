<?php
// This script should only be run from the command line (CLI)
if (php_sapi_name() !== 'cli') {
    die("Access Denied. This script can only be run from the command line.");
}

// Set a long execution time, as sending emails can be slow.
set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Define how many emails to process in each run.
// This prevents the script from running for too long and consuming too many resources.
define('QUEUE_BATCH_SIZE', 20);

echo "Starting email queue processing... (" . date('Y-m-d H:i:s') . ")\n";

try {
    // Begin transaction for data consistency
    $pdo->beginTransaction();

    // Fetch a batch of emails from the queue
    $stmt = $pdo->prepare(
        "SELECT id, profile_id, ip_address, submitted_at, recipient_email, cc_email, subject, body_html, body_text
         FROM email_queue
         ORDER BY submitted_at ASC
         LIMIT " . QUEUE_BATCH_SIZE
    );
    $stmt->execute();
    $emails_to_process = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($emails_to_process) === 0) {
        echo "No emails in the queue to process.\n";
        $pdo->commit(); // Still need to commit to end the transaction
        exit;
    }

    echo "Found " . count($emails_to_process) . " emails to process in this batch.\n";

    // Prepare statements for reuse
    $profile_stmt = $pdo->prepare("SELECT * FROM sending_profiles WHERE id = ?");
    $log_stmt = $pdo->prepare(
        "INSERT INTO email_logs (id, profile_id, ip_address, submitted_at, sent_at, recipient_email, cc_email, subject, status, status_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $delete_stmt = $pdo->prepare("DELETE FROM email_queue WHERE id = ?");

    foreach ($emails_to_process as $email) {
        echo "Processing Message ID: {$email['id']}...\n";

        // 1. Fetch the sending profile for this email
        $profile_stmt->execute([$email['profile_id']]);
        $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

        $status = 'failed';
        $status_info = '';

        if (!$profile) {
            $status_info = 'Sending profile (ID: ' . $email['profile_id'] . ') not found. It may have been deleted.';
            echo " - FAILED: {$status_info}\n";
        } else {
            // 2. Decrypt the SMTP password
            // Note: The simple_decrypt function is defined in config.php.example
            // and must be present in your config.php file.
            $smtp_password = simple_decrypt($profile['smtp_pass']);
            if ($smtp_password === false) {
                $status_info = 'Failed to decrypt SMTP password. Check encryption key.';
                echo " - FAILED: {$status_info}\n";
                // Skip sending if decryption fails
                goto log_and_delete;
            }


            // 3. Configure and send with PHPMailer
            $mail = new PHPMailer(true);
            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host       = $profile['smtp_host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $profile['smtp_user'];
                $mail->Password   = $smtp_password;
                $mail->SMTPSecure = $profile['smtp_encryption'] === 'none' ? false : $profile['smtp_encryption'];
                $mail->Port       = $profile['smtp_port'];

                // Recipients
                $mail->setFrom($profile['from_email'], $profile['from_name']);
                $mail->addAddress($email['recipient_email']);
                if (!empty($email['cc_email'])) {
                    $mail->addCC($email['cc_email']);
                }

                // Content
                $mail->isHTML(!empty($email['body_html']));
                $mail->Subject = $email['subject'];
                $mail->Body    = $email['body_html'];
                $mail->AltBody = $email['body_text'];

                $mail->send();
                $status = 'sent';
                $status_info = null;
                echo " - SUCCESS: Email sent to {$email['recipient_email']}.\n";

            } catch (Exception $e) {
                $status = 'failed';
                $status_info = $mail->ErrorInfo;
                echo " - FAILED: {$status_info}\n";
            }
        }

        // 4. Log to history and delete from queue
        log_and_delete:
        $log_stmt->execute([
            $email['id'],
            $email['profile_id'],
            $email['ip_address'],
            $email['submitted_at'],
            $status === 'sent' ? date('Y-m-d H:i:s') : null,
            $email['recipient_email'],
            $email['cc_email'],
            $email['subject'],
            $status,
            $status_info
        ]);

        $delete_stmt->execute([$email['id']]);
    }

    // Commit all changes if everything went well
    $pdo->commit();
    echo "Batch processing finished.\n";

} catch (PDOException $e) {
    // If something goes wrong with the database, roll back any changes
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
    exit(1); // Exit with a non-zero status code to indicate failure
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "A general error occurred: " . $e->getMessage() . "\n";
    exit(1);
}
?>

