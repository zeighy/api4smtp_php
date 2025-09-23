<?php
session_start();

// If the user session is not set or is not true,
// redirect them to the login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    // Append the originally requested page as a query parameter for potential future use,
    // though we are not using it for now.
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
?>
