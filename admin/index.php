<?php
session_start();

// Check if the user is logged in and redirect them to the appropriate page.
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    // User is logged in, redirect to the dashboard.
    header('Location: dashboard.php');
    exit;
} else {
    // User is not logged in, redirect to the login page.
    header('Location: login.php');
    exit;
}
?>
