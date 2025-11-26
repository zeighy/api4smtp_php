<?php
// A script to update the database schema for multiple recipients.

require_once __DIR__ . '/config.php';

try {
    // --- 1. Modify email_queue table ---
    echo "Updating `email_queue` table...\n";
    $pdo->exec("ALTER TABLE `email_queue` MODIFY `recipient_email` TEXT NOT NULL;");
    $pdo->exec("ALTER TABLE `email_queue` MODIFY `cc_email` TEXT NULL;");
    echo "`email_queue` table updated successfully.\n\n";

    // --- 2. Modify email_logs table ---
    echo "Updating `email_logs` table...\n";
    $pdo->exec("ALTER TABLE `email_logs` MODIFY `recipient_email` TEXT NOT NULL;");
    $pdo->exec("ALTER TABLE `email_logs` MODIFY `cc_email` TEXT NULL;");
    echo "`email_logs` table updated successfully.\n\n";

    echo "Database schema update complete.\n";

} catch (PDOException $e) {
    die("DATABASE ERROR: " . $e->getMessage() . "\n");
}
