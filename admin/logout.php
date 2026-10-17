<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';
secure_session_start();

// Logging out changes state, so it requires a POST with a valid CSRF token.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_logged_in()) {
    csrf_require();
    destroy_session();
    header('Location: login.php?logged_out=true');
    exit;
}

header('Location: ' . (is_logged_in() ? 'dashboard.php' : 'login.php'));
exit;
