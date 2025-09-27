<?php
session_start();

// Unset all of the session variables
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to login page with a success message
header('Location: login.php?logged_out=true');
exit;
?>