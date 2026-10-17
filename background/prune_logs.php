<?php
// This script should only be run from the command line (CLI)
if (php_sapi_name() !== 'cli') {
    die("Access Denied. This script can only be run from the command line.");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';

echo "Starting email history pruning process... (" . date('Y-m-d H:i:s') . ")\n";

try {
    // Settings are stored as key/value rows.
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'log_retention_days'");
    $stmt->execute();
    $value = $stmt->fetchColumn();

    if ($value === false) {
        echo "ERROR: Could not retrieve log retention settings from the database.\n";
        exit(1);
    }

    $retention_days = (int)$value;

    if ($retention_days > 0) {
        echo "Log retention period is set to {$retention_days} days.\n";
        $delete = $pdo->prepare("DELETE FROM email_logs WHERE COALESCE(sent_at, submitted_at) < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $delete->execute([$retention_days]);
        echo "Pruned {$delete->rowCount()} old log entries.\n";
    } else {
        echo "Log retention is disabled or invalid (<= 0). Email logs will not be pruned.\n";
    }

    // Housekeeping for tables that should never grow unbounded.
    $n = $pdo->exec("DELETE FROM rate_limit_tracker WHERE blocked_until < NOW()");
    echo "Removed {$n} expired rate-limit blocks.\n";
    $n = $pdo->exec("DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL 1 DAY");
    echo "Removed {$n} old authentication-attempt records.\n";

    echo "Pruning process finished successfully.\n";
    exit(0);

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
