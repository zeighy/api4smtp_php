<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';
secure_session_start();

header('Location: ' . (is_logged_in() ? 'dashboard.php' : 'login.php'));
exit;
