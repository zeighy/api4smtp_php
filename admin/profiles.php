<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth_check.php';

$action = $_POST['action'] ?? $_GET['action'] ?? 'view';
$action = is_string($action) ? $action : 'view';
$profile_id = filter_var($_POST['id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT);
$profile_id = $profile_id === false ? null : $profile_id;
$error_message = '';
$success_message = '';
$profile_to_edit = null;

/** Trimmed string from $_POST, or '' when absent / not a string. */
function post_str(string $key): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

// --- Handle Form Submissions (POST requests; CSRF already verified by auth_check.php) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile_name = post_str('profile_name');
    $from_name = post_str('from_name');
    $from_email = filter_var(post_str('from_email'), FILTER_VALIDATE_EMAIL);
    $smtp_host = post_str('smtp_host');
    $smtp_port = filter_var(post_str('smtp_port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $smtp_user = post_str('smtp_user');
    $smtp_pass = isset($_POST['smtp_pass']) && is_string($_POST['smtp_pass']) ? $_POST['smtp_pass'] : ''; // not trimmed: may be significant
    $smtp_encryption = in_array(post_str('smtp_encryption'), ['none', 'ssl', 'tls'], true) ? post_str('smtp_encryption') : 'tls';
    $rate_limit_count = filter_var(post_str('rate_limit_count'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
    $rate_limit_interval = filter_var(post_str('rate_limit_interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 525600]]);
    $rate_limit_strategy = in_array(post_str('rate_limit_strategy'), ['REJECT', 'DELAY'], true) ? post_str('rate_limit_strategy') : 'REJECT';

    $valid = $profile_name !== '' && mb_strlen($profile_name) <= 255
        && $from_name !== '' && mb_strlen($from_name) <= 255 && !preg_match('/[\r\n]/', $from_name)
        && $from_email && strlen($from_email) <= 255
        && is_valid_smtp_host($smtp_host)
        && $smtp_port !== false
        && $smtp_user !== '' && mb_strlen($smtp_user) <= 255
        && $rate_limit_count !== false && $rate_limit_interval !== false;

    try {
        if ($action === 'add') {
            if ($valid && $smtp_pass !== '') {
                $stmt = $pdo->prepare("INSERT INTO sending_profiles (profile_name, from_name, from_email, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_encryption, rate_limit_count, rate_limit_interval, rate_limit_strategy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$profile_name, $from_name, $from_email, $smtp_host, $smtp_port, $smtp_user, encrypt_secret($smtp_pass), $smtp_encryption, $rate_limit_count, $rate_limit_interval, $rate_limit_strategy]);
                $success_message = 'Profile created successfully!';
            } else {
                $error_message = 'Please fill in all required fields with valid data (valid host name, port 1-65535, valid email).';
            }
        } elseif ($action === 'edit' && $profile_id) {
            if ($valid) {
                $params = [$profile_name, $from_name, $from_email, $smtp_host, $smtp_port, $smtp_user, $smtp_encryption, $rate_limit_count, $rate_limit_interval, $rate_limit_strategy];
                if ($smtp_pass !== '') {
                    $stmt = $pdo->prepare("UPDATE sending_profiles SET profile_name=?, from_name=?, from_email=?, smtp_host=?, smtp_port=?, smtp_user=?, smtp_encryption=?, rate_limit_count=?, rate_limit_interval=?, rate_limit_strategy=?, smtp_pass=? WHERE id=?");
                    array_push($params, encrypt_secret($smtp_pass), $profile_id);
                } else {
                    $stmt = $pdo->prepare("UPDATE sending_profiles SET profile_name=?, from_name=?, from_email=?, smtp_host=?, smtp_port=?, smtp_user=?, smtp_encryption=?, rate_limit_count=?, rate_limit_interval=?, rate_limit_strategy=? WHERE id=?");
                    $params[] = $profile_id;
                }
                $stmt->execute($params);
                $success_message = 'Profile updated successfully!';
            } else {
                $error_message = 'Please fill in all required fields with valid data (valid host name, port 1-65535, valid email).';
            }
        } elseif ($action === 'delete' && $profile_id) {
            // Deleting cascades to the profile's tokens, queued emails and logs.
            $pdo->prepare("DELETE FROM sending_profiles WHERE id = ?")->execute([$profile_id]);
            $success_message = 'Profile deleted successfully!';
        }
    } catch (RuntimeException $e) {
        log_exception($e, 'profiles.php');
        $error_message = 'The server encryption key is not configured correctly. See config.php.';
    } catch (PDOException $e) {
        log_exception($e, 'profiles.php');
        $error_message = 'A database error occurred. Check the server log for details.';
    }
}

// --- Prepare data for the view ---
if ($action === 'edit' && $profile_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM sending_profiles WHERE id = ?");
    $stmt->execute([$profile_id]);
    $profile_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch all profiles to display in the table
$profiles = $pdo->query("SELECT id, profile_name, from_name, from_email, smtp_host, smtp_port FROM sending_profiles ORDER BY profile_name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Sending Profiles';
include __DIR__ . '/includes/header.php';
?>

<!-- Start of page content -->
<div class="p-4">

    <!-- Success and Error Messages -->
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

    <!-- Add/Edit Form Card -->
    <div class="bg-white p-6 rounded-lg shadow-md mb-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">
            <?= $profile_to_edit ? 'Edit Profile' : 'Add New Profile' ?>
        </h2>
        <form action="profiles.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $profile_to_edit ? 'edit' : 'add' ?>">
            <?php if ($profile_to_edit): ?>
                <input type="hidden" name="id" value="<?= (int)$profile_to_edit['id'] ?>">
            <?php endif; ?>
            
            <!-- Form Fields -->
            <div>
                <label for="profile_name" class="block text-sm font-medium text-gray-700">Profile Name *</label>
                <input type="text" id="profile_name" name="profile_name" required value="<?= htmlspecialchars($profile_to_edit['profile_name'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label for="from_name" class="block text-sm font-medium text-gray-700">From Name *</label>
                <input type="text" id="from_name" name="from_name" required value="<?= htmlspecialchars($profile_to_edit['from_name'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div class="md:col-span-2">
                <label for="from_email" class="block text-sm font-medium text-gray-700">From Email *</label>
                <input type="email" id="from_email" name="from_email" required value="<?= htmlspecialchars($profile_to_edit['from_email'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label for="smtp_host" class="block text-sm font-medium text-gray-700">SMTP Host *</label>
                <input type="text" id="smtp_host" name="smtp_host" required value="<?= htmlspecialchars($profile_to_edit['smtp_host'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label for="smtp_port" class="block text-sm font-medium text-gray-700">SMTP Port *</label>
                <input type="number" id="smtp_port" name="smtp_port" required value="<?= htmlspecialchars($profile_to_edit['smtp_port'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label for="smtp_user" class="block text-sm font-medium text-gray-700">SMTP Username *</label>
                <input type="text" id="smtp_user" name="smtp_user" required value="<?= htmlspecialchars($profile_to_edit['smtp_user'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label for="smtp_pass" class="block text-sm font-medium text-gray-700">SMTP Password</label>
                <input type="password" id="smtp_pass" name="smtp_pass" placeholder="<?= $profile_to_edit ? 'Leave blank to keep unchanged' : 'Required' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
             <div class="md:col-span-2">
                <label for="smtp_encryption" class="block text-sm font-medium text-gray-700">SMTP Encryption</label>
                <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="tls" <?= ($profile_to_edit['smtp_encryption'] ?? 'tls') == 'tls' ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl" <?= ($profile_to_edit['smtp_encryption'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="none" <?= ($profile_to_edit['smtp_encryption'] ?? '') == 'none' ? 'selected' : '' ?>>None</option>
                </select>
            </div>

            <!-- Rate Limiting -->
            <div class="md:col-span-2 border-t pt-6 mt-4">
                <h3 class="text-lg font-medium text-gray-800 mb-2">Rate Limiting</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="rate_limit_count" class="block text-sm font-medium text-gray-700">Requests per Interval</label>
                        <input type="number" id="rate_limit_count" name="rate_limit_count" value="<?= htmlspecialchars($profile_to_edit['rate_limit_count'] ?? '100') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Number of emails allowed per interval. Set to 0 to disable.</p>
                    </div>
                    <div>
                        <label for="rate_limit_interval" class="block text-sm font-medium text-gray-700">Interval (Minutes)</label>
                        <input type="number" id="rate_limit_interval" name="rate_limit_interval" value="<?= htmlspecialchars($profile_to_edit['rate_limit_interval'] ?? '60') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="text-xs text-gray-500 mt-1">The time frame for the rate limit in minutes.</p>
                    </div>
                    <div class="md:col-span-2">
                        <label for="rate_limit_strategy" class="block text-sm font-medium text-gray-700">Rate Limit Strategy</label>
                        <select id="rate_limit_strategy" name="rate_limit_strategy" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="REJECT" <?= ($profile_to_edit['rate_limit_strategy'] ?? 'REJECT') == 'REJECT' ? 'selected' : '' ?>>Reject</option>
                            <option value="DELAY" <?= ($profile_to_edit['rate_limit_strategy'] ?? '') == 'DELAY' ? 'selected' : '' ?>>Delay</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Choose whether to reject or delay emails that exceed the rate limit.</p>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="md:col-span-2 flex items-center justify-end space-x-4 mt-6">
                 <?php if ($profile_to_edit): ?>
                    <a href="profiles.php" class="bg-gray-200 hover:bg-gray-300 text-black font-bold py-2 px-4 rounded">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                    <?= $profile_to_edit ? 'Update Profile' : 'Save Profile' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Profiles List Card -->
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Existing Profiles</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profile Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">From</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SMTP Host</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($profiles)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">No profiles found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($profiles as $profile): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($profile['profile_name']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($profile['from_name']) ?> &lt;<?= htmlspecialchars($profile['from_email']) ?>&gt;</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($profile['smtp_host']) ?>:<?= htmlspecialchars($profile['smtp_port']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                    <a href="api_tokens.php?profile_id=<?= (int)$profile['id'] ?>" class="text-green-600 hover:text-green-900">Manage Tokens</a>
                                    <a href="profiles.php?action=edit&id=<?= (int)$profile['id'] ?>" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                    <form action="profiles.php" method="POST" class="inline" onsubmit="return confirm('Delete this profile? Its API tokens, queued emails and email logs will also be deleted. This cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$profile['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- End of page content -->

<?php
include __DIR__ . '/includes/footer.php';
?>
