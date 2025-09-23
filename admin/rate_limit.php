<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config.php';

$pageTitle = "Rate Limit Management";
$success_message = '';

// --- Handle releasing a rate-limited IP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['release_id'])) {
    $release_id = filter_input(INPUT_POST, 'release_id', FILTER_VALIDATE_INT);
    if ($release_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM rate_limit_tracker WHERE id = ?");
            $stmt->execute([$release_id]);
            $success_message = "The IP address has been successfully released from the rate limit.";
        } catch (PDOException $e) {
            // In a real app, you might want a more robust error message system
            die("Database error: " . $e->getMessage());
        }
    }
}

// --- Clean up expired entries automatically on page load ---
// This is good practice to keep the table clean.
$pdo->query("DELETE FROM rate_limit_tracker WHERE blocked_until < NOW()");


// --- Fetch all currently rate-limited IPs ---
$stmt = $pdo->query(
    "SELECT rlt.id, rlt.ip_address, rlt.blocked_until, sp.profile_name 
     FROM rate_limit_tracker rlt
     JOIN sending_profiles sp ON rlt.profile_id = sp.id
     ORDER BY rlt.blocked_until DESC"
);
$limited_ips = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Rate Limit Management</h1>

    <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($success_message) ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow-lg mb-8">
        <p class="text-gray-600">This page lists all IP addresses that are currently being rate-limited because they exceeded the submission limits defined in the settings. The block is temporary and will automatically expire. You can manually release an IP before its expiration time by clicking the "Release" button.</p>
    </div>

    <!-- Rate Limited IPs Table -->
    <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
        <table class="min-w-full leading-normal">
            <thead>
                <tr>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">IP Address</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Blocked on Profile</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Block Expires</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($limited_ips)): ?>
                    <tr>
                        <td colspan="4" class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">No IPs are currently being rate-limited.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($limited_ips as $ip_info): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <p class="text-gray-900 whitespace-no-wrap"><?= htmlspecialchars($ip_info['ip_address']) ?></p>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <p class="text-gray-900 whitespace-no-wrap"><?= htmlspecialchars($ip_info['profile_name']) ?></p>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <p class="text-gray-900 whitespace-no-wrap"><?= htmlspecialchars($ip_info['blocked_until']) ?></p>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <form action="rate_limit.php" method="POST" onsubmit="return confirm('Are you sure you want to release this IP?');">
                                    <input type="hidden" name="release_id" value="<?= $ip_info['id'] ?>">
                                    <button type="submit" class="text-white bg-red-500 hover:bg-red-600 font-bold py-1 px-3 rounded-full text-xs transition duration-300">
                                        Release
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
