<?php
// auth/logout.php
require_once __DIR__ . '/../config/config.php';

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to home page
header("Location: " . BASE_URL . "/");
exit;
?>
