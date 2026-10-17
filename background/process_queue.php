<?php
// This script should only be run from the command line (CLI)
if (php_sapi_name() !== 'cli') {
    die("Access Denied. This script can only be run from the command line.");
}

// Set a long execution time, as sending emails can be slow.
set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Emails claimed per run, so one run cannot take unbounded time.
define('QUEUE_BATCH_SIZE', 20);
// A row stuck in "processing" longer than this (crashed worker) is made available again.
define('QUEUE_STALE_MINUTES', 15);
// Cap on stored SMTP debug transcript size, in bytes.
define('DEBUG_INFO_MAX_BYTES', 20000);

echo "Starting email queue processing... (" . date('Y-m-d H:i:s') . ")\n";

/**
 * Build a debug-output callback that appends to $buffer while redacting
 * credentials exchanged during SMTP AUTH.
 */
function make_debug_collector(string &$buffer): callable {
    $skip_client_lines = 0;
    return function ($str, $level) use (&$buffer, &$skip_client_lines) {
        foreach (preg_split('/\r?\n/', rtrim($str)) as $line) {
            if (strpos($line, 'CLIENT -> SERVER:') !== false) {
                if (preg_match('/CLIENT -> SERVER:\s*AUTH\s+(PLAIN|XOAUTH2)\b/i', $line, $m)) {
                    $line = 'CLIENT -> SERVER: AUTH ' . strtoupper($m[1]) . ' [credentials redacted]';
                } elseif (preg_match('/CLIENT -> SERVER:\s*AUTH\s+LOGIN\s*$/i', $line)) {
                    $skip_client_lines = 2;
                } elseif (preg_match('/CLIENT -> SERVER:\s*AUTH\s+CRAM-MD5\s*$/i', $line)) {
                    $skip_client_lines = 1;
                } elseif ($skip_client_lines > 0) {
                    $skip_client_lines--;
                    $line = 'CLIENT -> SERVER: [credentials redacted]';
                }
            }
            $buffer .= $line . "\n";
        }
    };
}

/** True when the SMTP server gave a definitive (5xx) rejection that a retry will not fix. */
function is_permanent_failure(PHPMailer $mail): bool {
    $smtp = $mail->getSMTPInstance();
    $error = $smtp ? $smtp->getError() : [];
    $code = isset($error['smtp_code']) ? (int)$error['smtp_code'] : 0;
    if ($code >= 500 && $code < 600) {
        return true;
    }
    // Rejected recipients / addresses are reported in the error text, not in smtp_code.
    return (bool)preg_match('/recipients failed|SMTP code: 5\d\d|Invalid address|You must provide at least one recipient/i', (string)$mail->ErrorInfo);
}

try {
    $worker_id = bin2hex(random_bytes(8));

    // Return rows abandoned by a crashed worker to the queue.
    $pdo->exec(
        "UPDATE email_queue SET status = 'queued', locked_by = NULL, locked_at = NULL
         WHERE status = 'processing' AND locked_at < NOW() - INTERVAL " . QUEUE_STALE_MINUTES . " MINUTE"
    );

    // Atomically claim a batch. A single UPDATE means overlapping cron runs can never
    // pick up the same rows.
    $claim = $pdo->prepare(
        "UPDATE email_queue
         SET status = 'processing', locked_by = ?, locked_at = NOW(), processing_attempts = processing_attempts + 1
         WHERE status = 'queued' AND send_at <= NOW()
         ORDER BY send_at ASC
         LIMIT " . QUEUE_BATCH_SIZE
    );
    $claim->execute([$worker_id]);

    $stmt = $pdo->prepare(
        "SELECT id, profile_id, ip_address, submitted_at, recipient_email, cc_email, bcc_email, subject, body_html, body_text, processing_attempts
         FROM email_queue WHERE status = 'processing' AND locked_by = ? ORDER BY send_at ASC"
    );
    $stmt->execute([$worker_id]);
    $emails_to_process = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($emails_to_process) === 0) {
        echo "No emails in the queue to process.\n";
        exit(0);
    }
    echo "Claimed " . count($emails_to_process) . " emails in this batch.\n";

    $profile_stmt = $pdo->prepare("SELECT * FROM sending_profiles WHERE id = ?");
    $attachment_stmt = $pdo->prepare("SELECT * FROM email_attachments WHERE email_id = ?");
    $log_stmt = $pdo->prepare(
        "INSERT INTO email_logs (id, profile_id, ip_address, submitted_at, sent_at, recipient_email, cc_email, bcc_email, subject, status, status_info, debug_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $delete_stmt = $pdo->prepare("DELETE FROM email_queue WHERE id = ?");
    $retry_stmt = $pdo->prepare(
        "UPDATE email_queue SET status = 'queued', locked_by = NULL, locked_at = NULL, send_at = NOW() + INTERVAL ? MINUTE WHERE id = ?"
    );

    foreach ($emails_to_process as $email) {
        echo "Processing Message ID: {$email['id']}...\n";

        $status = 'failed';
        $status_info = '';
        $debug_info = '';
        $retryable = false;

        try {
            $profile_stmt->execute([$email['profile_id']]);
            $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$profile) {
                $status_info = 'Sending profile (ID: ' . $email['profile_id'] . ') not found. It may have been deleted.';
            } elseif (($smtp_password = decrypt_secret($profile['smtp_pass'])) === false) {
                $status_info = 'Failed to decrypt SMTP password. Check the encryption key.';
            } else {
                // Opportunistically migrate legacy (unauthenticated CBC) secrets to the current format.
                if (is_legacy_secret($profile['smtp_pass'])) {
                    $pdo->prepare("UPDATE sending_profiles SET smtp_pass = ? WHERE id = ?")
                        ->execute([encrypt_secret($smtp_password), $profile['id']]);
                }

                $mail = new PHPMailer(true);
                try {
                    $mail->CharSet = 'UTF-8';
                    $mail->Timeout = 30;
                    $mail->isSMTP();
                    $mail->SMTPDebug = 2;
                    $mail->Debugoutput = make_debug_collector($debug_info);
                    $mail->Host       = $profile['smtp_host'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $profile['smtp_user'];
                    $mail->Password   = $smtp_password;
                    $mail->SMTPSecure = $profile['smtp_encryption'] === 'none' ? false : $profile['smtp_encryption'];
                    $mail->Port       = $profile['smtp_port'];

                    $mail->setFrom($profile['from_email'], $profile['from_name']);
                    $mail->addAddress($email['recipient_email']);
                    if (!empty($email['cc_email'])) {
                        $mail->addCC($email['cc_email']);
                    }
                    if (!empty($email['bcc_email'])) {
                        $bcc_emails = json_decode($email['bcc_email'], true);
                        foreach (is_array($bcc_emails) ? $bcc_emails : [] as $bcc) {
                            $mail->addBCC($bcc);
                        }
                    }

                    $attachment_stmt->execute([$email['id']]);
                    foreach ($attachment_stmt->fetchAll(PDO::FETCH_ASSOC) as $attachment) {
                        if ($attachment['inline']) {
                            $mail->addStringEmbeddedImage($attachment['content'], $attachment['cid'], $attachment['filename'], PHPMailer::ENCODING_BASE64, $attachment['content_type']);
                        } else {
                            $mail->addStringAttachment($attachment['content'], $attachment['filename'], PHPMailer::ENCODING_BASE64, $attachment['content_type']);
                        }
                    }

                    $mail->isHTML(!empty($email['body_html']));
                    $mail->Subject = $email['subject'];
                    if (!empty($email['body_html'])) {
                        $mail->Body = $email['body_html'];
                        $mail->AltBody = (string)$email['body_text'];
                    } else {
                        $mail->Body = (string)$email['body_text'];
                    }

                    $mail->send();
                    $status = 'sent';
                    $status_info = 'OK';
                    echo " - SUCCESS: Email sent to {$email['recipient_email']}.\n";
                } catch (Exception $e) {
                    $status_info = $mail->ErrorInfo ?: $e->getMessage();
                    // Connection problems and 4xx responses are worth retrying; 5xx rejections are not.
                    $retryable = !is_permanent_failure($mail);
                }
            }
        } catch (Throwable $e) {
            log_exception($e, 'process_queue message ' . $email['id']);
            $status_info = 'Internal error while processing the message.';
            $retryable = true;
        }

        if ($status === 'failed' && $retryable && (int)$email['processing_attempts'] < QUEUE_MAX_ATTEMPTS) {
            // Exponential backoff: 5, 10, 20, 40 minutes.
            $delay = 5 * (2 ** ((int)$email['processing_attempts'] - 1));
            $retry_stmt->execute([$delay, $email['id']]);
            echo " - RETRY in {$delay} min (attempt {$email['processing_attempts']}/" . QUEUE_MAX_ATTEMPTS . "): {$status_info}\n";
            continue;
        }
        if ($status === 'failed') {
            echo " - FAILED: {$status_info}\n";
        }

        if (strlen($debug_info) > DEBUG_INFO_MAX_BYTES) {
            $debug_info = mb_strcut($debug_info, 0, DEBUG_INFO_MAX_BYTES) . "\n[truncated]";
        }

        // Log and remove atomically, per message, so a crash cannot re-send already-delivered mail.
        $pdo->beginTransaction();
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
            $debug_info,
        ]);
        $delete_stmt->execute([$email['id']]);
        $pdo->commit();
    }

    echo "Batch processing finished.\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "A general error occurred: " . $e->getMessage() . "\n";
    exit(1);
}
