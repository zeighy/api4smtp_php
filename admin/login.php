<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';
secure_session_start();

// If the user is already logged in, redirect to the dashboard
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';
$client_ip = get_client_ip();

// A password hash that never matches; verified against when the account/hash is missing
// so response time does not reveal whether the username exists.
const LOGIN_DUMMY_HASH = '$2y$10$Y/Kx8NM01BqUlo01OG9j8up.AcSJIHnpPgwwJUar1ybO2nUnp9C6i';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($username === '' || $password === '') {
        $error_message = 'Please enter both username and password.';
    } elseif (auth_is_throttled($pdo, 'login', $client_ip)) {
        $error_message = 'Too many failed login attempts. Please wait ' . AUTH_WINDOW_MINUTES . ' minutes and try again.';
    } else {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('admin_user', 'admin_pass_hash')");
        $stmt->execute();
        $creds = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $correct_username = $creds['admin_user'] ?? '';
        $correct_pass_hash = $creds['admin_pass_hash'] ?? '';

        $hash_to_check = $correct_pass_hash !== '' ? $correct_pass_hash : LOGIN_DUMMY_HASH;
        $password_ok = password_verify($password, $hash_to_check);
        $user_ok = hash_equals((string)$correct_username, $username);

        if ($correct_pass_hash === '') {
            $error_message = 'No admin password has been set. Run "php bin/set_admin_password.php" on the server.';
        } elseif ($user_ok && $password_ok && $password === 'password') {
            // The shipped default credential must never grant access.
            $error_message = 'The default admin password is no longer accepted. Run "php bin/set_admin_password.php" on the server to set a new one.';
        } elseif ($user_ok && $password_ok) {
            session_regenerate_id(true);
            $_SESSION['user_logged_in'] = true;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            auth_clear_failures($pdo, 'login', $client_ip);
            if (password_needs_rehash($correct_pass_hash, PASSWORD_DEFAULT)) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'admin_pass_hash'")
                    ->execute([password_hash($password, PASSWORD_DEFAULT)]);
            }
            header('Location: dashboard.php');
            exit;
        } else {
            auth_record_failure($pdo, 'login', $client_ip);
            $error_message = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <!-- Using Tailwind CSS for styling from a CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">

    <div class="w-full max-w-md bg-white rounded-lg shadow-md p-8">
        <h2 class="text-2xl font-bold text-center text-gray-800 mb-8">Admin Login</h2>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= e($error_message) ?></span>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <?= csrf_field() ?>
            <div class="mb-4">
                <label for="username" class="block text-gray-700 text-sm font-bold mb-2">Username</label>
                <input type="text" id="username" name="username" required
                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <input type="password" id="password" name="password" required
                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex items-center justify-between">
                <button type="submit"
                        class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">
                    Sign In
                </button>
            </div>
        </form>
        <div class="text-center mt-4">
            <a href="help.php" class="text-sm text-blue-500 hover:underline">Need Help?</a>
        </div>
    </div>

</body>
</html>
