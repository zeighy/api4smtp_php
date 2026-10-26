<?php
// Sets (or resets) the admin username/password. CLI only.
// Usage: php bin/set_admin_password.php [username]
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';

$username = $argv[1] ?? 'admin';

function read_hidden(string $prompt): string {
    echo $prompt;
    $tty = DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) { system('stty -echo'); }
    $line = trim((string)fgets(STDIN));
    if ($tty) { system('stty echo'); echo "\n"; }
    return $line;
}

$pass = read_hidden("New password (min 12 chars): ");
if (strlen($pass) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}
if ($pass !== read_hidden("Repeat password: ")) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$upsert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$upsert->execute(['admin_user', $username]);
$upsert->execute(['admin_pass_hash', password_hash($pass, PASSWORD_DEFAULT)]);
echo "Admin credentials updated for user '{$username}'.\n";
