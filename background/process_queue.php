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

    // Fetch a batch of emails from the queue that are ready to be sent
    $stmt = $pdo->prepare(
        "SELECT id, profile_id, ip_address, submitted_at, recipient_email, cc_email, bcc_email, subject, body_html, body_text
         FROM email_queue
         WHERE send_at <= NOW()
         ORDER BY send_at ASC
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
    $attachment_stmt = $pdo->prepare("SELECT * FROM email_attachments WHERE email_id = ?");
    $log_stmt = $pdo->prepare(
        "INSERT INTO email_logs (id, profile_id, ip_address, submitted_at, sent_at, recipient_email, cc_email, bcc_email, subject, status, status_info, debug_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $delete_stmt = $pdo->prepare("DELETE FROM email_queue WHERE id = ?");

    foreach ($emails_to_process as $email) {
        echo "Processing Message ID: {$email['id']}...\n";

        // 1. Fetch the sending profile for this email
        $profile_stmt->execute([$email['profile_id']]);
        $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

        $status = 'failed';
        $status_info = '';
        $debug_info = '';

        if (!$profile) {
            $status_info = 'Sending profile (ID: ' . $email['profile_id'] . ') not found. It may have been deleted.';
            echo " - FAILED: {$status_info}\n";
        } else {
            // 2. Decrypt the SMTP password
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
                // Server settings with debugging
                $mail->isSMTP();
                $mail->SMTPDebug = 2; // Enable verbose debug output
                $mail->Debugoutput = function($str, $level) use (&$debug_info) {
                    $debug_info .= $str . "\n";
                };
                $mail->Host       = $profile['smtp_host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $profile['smtp_user'];
                $mail->Password   = $smtp_password;
                $mail->SMTPSecure = $profile['smtp_encryption'] === 'none' ? false : $profile['smtp_encryption'];
                $mail->Port       = $profile['smtp_port'];

                // Recipients
                $mail->setFrom($profile['from_email'], $profile['from_name']);

                // Add To, CC, and BCC recipients
                // Handle both V1 (string) and V2 (JSON array) formats
                $recipients = json_decode($email['recipient_email'], true);
                if (is_array($recipients)) {
                    foreach ($recipients as $recipient) {
                        $mail->addAddress($recipient);
                    }
                } else {
                    $mail->addAddress($email['recipient_email']);
                }

                if (!empty($email['cc_email'])) {
                    $cc_emails = json_decode($email['cc_email'], true);
                    if (is_array($cc_emails)) {
                        foreach ($cc_emails as $cc) {
                            $mail->addCC($cc);
                        }
                    } else {
                        $mail->addCC($email['cc_email']);
                    }
                }

                if (!empty($email['bcc_email'])) {
                    $bcc_emails = json_decode($email['bcc_email'], true);
                    if (is_array($bcc_emails)) {
                        foreach ($bcc_emails as $bcc) {
                            $mail->addBCC($bcc);
                        }
                    } else {
                        $mail->addBCC($email['bcc_email']);
                    }
                }

                // Fetch and add attachments
                $attachment_stmt->execute([$email['id']]);
                $attachments = $attachment_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($attachments as $attachment) {
                    if ($attachment['inline']) {
                        $mail->addStringEmbeddedImage(
                            $attachment['content'],
                            $attachment['cid'],
                            $attachment['filename'],
                            PHPMailer::ENCODING_BINARY,
                            $attachment['content_type']
                        );
                    } else {
                        $mail->addStringAttachment(
                            $attachment['content'],
                            $attachment['filename'],
                            PHPMailer::ENCODING_BINARY,
                            $attachment['content_type']
                        );
                    }
                }
                // Content
                $mail->isHTML(!empty($email['body_html']));
                $mail->Subject = $email['subject'];
                $mail->Body    = $email['body_html'];
                $mail->AltBody = $email['body_text'];

                $mail->send();
                $status = 'sent';
                $status_info = 'OK';
                echo " - SUCCESS: Email sent to {$email['recipient_email']}.\n";

            } catch (Exception $e) {
                $status = 'failed';
                $status_info = $mail->ErrorInfo; // This contains the primary error message
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
            $email['bcc_email'],
            $email['subject'],
            $status,
            $status_info,
            $debug_info // Add the captured debug info to the log
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

