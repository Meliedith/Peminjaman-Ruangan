<?php
// index.php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        redirect('/admin/dashboard.php');
    } else {
        redirect('/user/dashboard.php');
    }
} else {
    redirect('/auth/login.php');
}
?>
