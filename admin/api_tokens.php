<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config.php';

$profile_id = filter_input(INPUT_GET, 'profile_id', FILTER_VALIDATE_INT);
if (!$profile_id) {
    header('Location: profiles.php');
    exit;
}

// Fetch the profile details to display its name
$profile_stmt = $pdo->prepare("SELECT profile_name FROM sending_profiles WHERE id = ?");
$profile_stmt->execute([$profile_id]);
$profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    header('Location: profiles.php');
    exit;
}

$feedback = [];
$new_token = null;

// Handle new token creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_token'])) {
    $prefix = 'st_' . bin2hex(random_bytes(4)); // "st" for "smtp-token", plus 8 random hex chars
    $secret = bin2hex(random_bytes(24)); // 48 random hex chars
    $full_token = $prefix . '.' . $secret;
    $secret_hash = password_hash($secret, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO api_tokens (profile_id, token_prefix, token_hash) VALUES (?, ?, ?)");
        $stmt->execute([$profile_id, $prefix, $secret_hash]);
        $feedback = ['type' => 'success', 'message' => 'New token created successfully. Please copy it now, you will not see it again.'];
        $new_token = $full_token;
    } catch (PDOException $e) {
        // Handle potential duplicate prefix error
        if ($e->errorInfo[1] == 1062) {
             $feedback = ['type' => 'error', 'message' => 'Error: A token with the same prefix was generated. Please try again.'];
        } else {
            $feedback = ['type' => 'error', 'message' => 'Error creating token: ' . $e->getMessage()];
        }
    }
}

// Handle token deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_token'])) {
    $token_id = filter_input(INPUT_POST, 'token_id', FILTER_VALIDATE_INT);
    if ($token_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE id = ? AND profile_id = ?");
            $stmt->execute([$token_id, $profile_id]);
            $feedback = ['type' => 'success', 'message' => 'Token deleted successfully.'];
        } catch (PDOException $e) {
            $feedback = ['type' => 'error', 'message' => 'Error deleting token: ' . $e->getMessage()];
        }
    }
}


// Fetch existing tokens for this profile
$tokens_stmt = $pdo->prepare("SELECT id, token_prefix, created_at, last_used_at FROM api_tokens WHERE profile_id = ? ORDER BY created_at DESC");
$tokens_stmt->execute([$profile_id]);
$tokens = $tokens_stmt->fetchAll(PDO::FETCH_ASSOC);


$pageTitle = "Manage API Tokens";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <a href="profiles.php" class="text-blue-500 hover:underline">&larr; Back to Profiles</a>
            <h1 class="text-3xl font-bold text-gray-800 mt-2">API Tokens for "<?= htmlspecialchars($profile['profile_name']) ?>"</h1>
        </div>
        <form action="" method="POST">
            <button type="submit" name="create_token" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">
                Generate New Token
            </button>
        </form>
    </div>

    <?php if (!empty($feedback)): ?>
        <div class="mb-4 p-4 rounded-lg <?= $feedback['type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
            <?= htmlspecialchars($feedback['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($new_token): ?>
        <div class="mb-6 p-4 rounded-lg bg-yellow-100 border border-yellow-400">
            <h3 class="font-bold text-yellow-800">Your New API Token:</h3>
            <p class="text-sm text-yellow-700">This is the only time you will see this token. Please copy and store it securely.</p>
            <div class="mt-2 p-3 bg-gray-800 text-white font-mono rounded-md break-all">
                <?= htmlspecialchars($new_token) ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="bg-white shadow-lg rounded-lg overflow-hidden">
        <table class="min-w-full leading-normal">
            <thead>
                <tr>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Token Prefix</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Created At</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Last Used</th>
                    <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tokens)): ?>
                    <tr>
                        <td colspan="4" class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">No tokens found for this profile.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tokens as $token): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <span class="font-mono text-gray-700"><?= htmlspecialchars($token['token_prefix']) ?></span>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= htmlspecialchars($token['created_at']) ?></td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?= htmlspecialchars($token['last_used_at'] ?? 'Never') ?></td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <form action="" method="POST" onsubmit="return confirm('Are you sure you want to delete this token? This action cannot be undone.');">
                                    <input type="hidden" name="token_id" value="<?= $token['id'] ?>">
                                    <button type="submit" name="delete_token" class="text-red-500 hover:text-red-700 font-semibold">Delete</button>
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

