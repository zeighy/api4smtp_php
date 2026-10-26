<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/helpers.php';
secure_session_start();

// Redirect anonymous visitors to the login page.
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Every state-changing admin request must be a POST carrying a valid CSRF token.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}
