<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config.php';

$pageTitle = "Application Settings";

// Fetch current settings
$stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_user = trim($_POST['admin_user']);
    $new_password = $_POST['admin_password'];
    $log_retention_days = filter_input(INPUT_POST, 'log_retention_days', FILTER_VALIDATE_INT);
    $rate_limit_count = filter_input(INPUT_POST, 'rate_limit_count', FILTER_VALIDATE_INT);
    $rate_limit_minutes = filter_input(INPUT_POST, 'rate_limit_minutes', FILTER_VALIDATE_INT);

    // Basic validation
    if (empty($admin_user) || $log_retention_days === false || $rate_limit_count === false || $rate_limit_minutes === false) {
        $error_message = "Please fill in all fields with valid values.";
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();

            if (!empty($new_password)) {
                // Hash the new password if it's provided
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare(
                    "UPDATE settings SET admin_user = ?, admin_password = ?, log_retention_days = ?, rate_limit_count = ?, rate_limit_minutes = ? WHERE id = 1"
                );
                $update_stmt->execute([$admin_user, $password_hash, $log_retention_days, $rate_limit_count, $rate_limit_minutes]);
            } else {
                // Update without changing the password
                $update_stmt = $pdo->prepare(
                    "UPDATE settings SET admin_user = ?, log_retention_days = ?, rate_limit_count = ?, rate_limit_minutes = ? WHERE id = 1"
                );
                $update_stmt->execute([$admin_user, $log_retention_days, $rate_limit_count, $rate_limit_minutes]);
            }

            $pdo->commit();
            $success_message = "Settings updated successfully!";
            // Re-fetch settings to display updated values
            $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 py-8 max-w-2xl">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Application Settings</h1>

    <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($success_message) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($error_message) ?></span>
        </div>
    <?php endif; ?>


    <div class="bg-white p-8 rounded-lg shadow-lg">
        <form action="settings.php" method="POST">
            <!-- Admin Credentials -->
            <fieldset class="mb-8">
                <legend class="text-xl font-semibold text-gray-700 border-b-2 border-gray-200 pb-2 mb-4">Admin Credentials</legend>
                <div class="mb-4">
                    <label for="admin_user" class="block text-gray-700 text-sm font-bold mb-2">Admin Username:</label>
                    <input type="text" id="admin_user" name="admin_user" value="<?= htmlspecialchars($settings['admin_user']) ?>" required class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring focus:ring-blue-300">
                </div>
                <div class="mb-4">
                    <label for="admin_password" class="block text-gray-700 text-sm font-bold mb-2">New Password:</label>
                    <input type="password" id="admin_password" name="admin_password" placeholder="Leave blank to keep current password" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:ring focus:ring-blue-300">
                    <p class="text-xs text-gray-500">Only enter a value here if you want to change the password.</p>
                </div>
            </fieldset>

            <!-- Data Retention -->
            <fieldset class="mb-8">
                <legend class="text-xl font-semibold text-gray-700 border-b-2 border-gray-200 pb-2 mb-4">Data Retention</legend>
                <div class="mb-4">
                    <label for="log_retention_days" class="block text-gray-700 text-sm font-bold mb-2">Email Log Retention (Days):</label>
                    <input type="number" id="log_retention_days" name="log_retention_days" value="<?= htmlspecialchars($settings['log_retention_days']) ?>" required min="1" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring focus:ring-blue-300">
                    <p class="text-xs text-gray-500">Email history logs older than this will be deleted automatically.</p>
                </div>
            </fieldset>

            <!-- API Rate Limiting -->
            <fieldset class="mb-8">
                <legend class="text-xl font-semibold text-gray-700 border-b-2 border-gray-200 pb-2 mb-4">API Rate Limiting</legend>
                <p class="text-sm text-gray-600 mb-4">This sets a global rate limit for the send API. The limit is per IP address per sending profile.</p>
                <div class="flex gap-4">
                    <div class="flex-1">
                         <label for="rate_limit_count" class="block text-gray-700 text-sm font-bold mb-2">Max Emails:</label>
                         <input type="number" id="rate_limit_count" name="rate_limit_count" value="<?= htmlspecialchars($settings['rate_limit_count']) ?>" required min="0" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring focus:ring-blue-300">
                    </div>
                     <div class="flex-1">
                         <label for="rate_limit_minutes" class="block text-gray-700 text-sm font-bold mb-2">Per # of Minutes:</label>
                         <input type="number" id="rate_limit_minutes" name="rate_limit_minutes" value="<?= htmlspecialchars($settings['rate_limit_minutes']) ?>" required min="1" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring focus:ring-blue-300">
                    </div>
                </div>
            </fieldset>

            <div class="flex items-center justify-end">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-6 rounded-lg shadow-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-300">
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
