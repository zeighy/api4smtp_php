<?php
// This script should only be run from the command line (CLI)
if (php_sapi_name() !== 'cli') {
    die("Access Denied. This script can only be run from the command line.");
}

require_once __DIR__ . '/../config.php';

echo "Starting email history pruning process... (" . date('Y-m-d H:i:s') . ")\n";

try {
    // 1. Fetch the log retention setting from the database
    $stmt = $pdo->query("SELECT log_retention_days FROM settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings || !isset($settings['log_retention_days'])) {
        echo "ERROR: Could not retrieve log retention settings from the database.\n";
        exit(1);
    }

    $retention_days = (int)$settings['log_retention_days'];

    if ($retention_days <= 0) {
        echo "Log retention is disabled or set to an invalid value (<= 0). No logs will be pruned.\n";
        exit(0);
    }

    echo "Log retention period is set to {$retention_days} days.\n";

    // 2. Prepare and execute the DELETE query
    // We use DATE_SUB() to calculate the cutoff date
    $delete_sql = "DELETE FROM email_history WHERE processed_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $delete_stmt = $pdo->prepare($delete_sql);
    $delete_stmt->execute([$retention_days]);

    // 3. Report the number of deleted rows
    $deleted_rows_count = $delete_stmt->rowCount();

    if ($deleted_rows_count > 0) {
        echo "Successfully pruned {$deleted_rows_count} old log entries.\n";
    } else {
        echo "No old log entries found to prune.\n";
    }

    echo "Pruning process finished successfully.\n";
    exit(0);

} catch (PDOException $e) {
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
    exit(1); // Exit with a non-zero status code to indicate failure
} catch (Exception $e) {
    echo "A general error occurred: " . $e->getMessage() . "\n";
    exit(1);
}
?>
