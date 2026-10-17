<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config.php';

$pageTitle = "Application Settings";

// Fetch current settings into a key-value array
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settings_raw as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$success_message = '';
$error_message = '';

// Handle form submission (CSRF already verified by auth_check.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_user = isset($_POST['admin_user']) && is_string($_POST['admin_user']) ? trim($_POST['admin_user']) : '';
    $new_password = isset($_POST['admin_password']) && is_string($_POST['admin_password']) ? $_POST['admin_password'] : '';
    $log_retention_days = filter_input(INPUT_POST, 'log_retention_days', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);

    if ($admin_user === '' || mb_strlen($admin_user) > 100 || $log_retention_days === false || $log_retention_days === null) {
        $error_message = "Please fill in all fields with valid values (retention must be 1-3650 days).";
    } elseif ($new_password !== '' && (strlen($new_password) < 12 || $new_password === 'password')) {
        $error_message = "The new password must be at least 12 characters long.";
    } else {
        try {
            $pdo->beginTransaction();
            $upsert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $upsert->execute(['admin_user', $admin_user]);
            $upsert->execute(['log_retention_days', (string)$log_retention_days]);
            if ($new_password !== '') {
                $upsert->execute(['admin_pass_hash', password_hash($new_password, PASSWORD_DEFAULT)]);
            }
            $pdo->commit();

            if ($new_password !== '') {
                session_regenerate_id(true); // new credentials -> new session ID
            }
            $success_message = "Settings updated successfully!";

            $settings_raw = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
            $settings = [];
            foreach ($settings_raw as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_exception($e, 'settings.php');
            $error_message = "A database error occurred. Check the server log for details.";
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
            <?= csrf_field() ?>
            <!-- Admin Credentials -->
            <fieldset class="mb-8">
                <legend class="text-xl font-semibold text-gray-700 border-b-2 border-gray-200 pb-2 mb-4">Admin Credentials</legend>
                <div class="mb-4">
                    <label for="admin_user" class="block text-gray-700 text-sm font-bold mb-2">Admin Username:</label>
                    <input type="text" id="admin_user" name="admin_user" value="<?= htmlspecialchars($settings['admin_user'] ?? '') ?>" required class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring focus:ring-blue-300">
                </div>
                <div class="mb-4">
                    <label for="admin_password" class="block text-gray-700 text-sm font-bold mb-2">New Password:</label>
                    <input type="password" id="admin_password" name="admin_password" placeholder="Leave blank to keep current password" minlength="12" autocomplete="new-password" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:ring focus:ring-blue-300">
                    <p class="text-xs text-gray-500">Only enter a value here if you want to change the password.</p>
                </div>
            </fieldset>

            <!-- Data Retention -->
            <fieldset class="mb-8">
                <legend class="text-xl font-semibold text-gray-700 border-b-2 border-gray-200 pb-2 mb-4">Data Retention</legend>
                <div class="mb-4">
                    <label for="log_retention_days" class="block text-gray-700 text-sm font-bold mb-2">Email Log Retention (Days):</label>
                    <input type="number" id="log_retention_days" name="log_retention_days" value="<?= htmlspecialchars($settings['log_retention_days'] ?? '30') ?>" required min="1" max="3650" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring focus:ring-blue-300">
                    <p class="text-xs text-gray-500">Email history logs older than this will be deleted automatically.</p>
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
