<?php
// includes/auth_middleware.php
require_once __DIR__ . '/functions.php';

function checkAuth($required_role = null) {
    if (!isset($_SESSION['user_id'])) {
        setFlashMessage('danger', 'Silakan login terlebih dahulu.');
        redirect('/auth/login.php');
    }

    if ($required_role && isset($_SESSION['role']) && $_SESSION['role'] !== $required_role) {
        setFlashMessage('danger', 'Anda tidak memiliki akses ke halaman tersebut.');
        // Redirect based on role
        if ($_SESSION['role'] === 'admin') {
            redirect('/admin/dashboard.php');
        } else {
            redirect('/user/dashboard.php');
        }
    }
}
?>
