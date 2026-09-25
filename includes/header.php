<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Peminjaman Lab</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark mb-4" style="background-color: #0b5b9e;">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center flex-wrap" href="<?= BASE_URL ?>/">
            <!-- Mobile Logos -->
            <img src="<?= BASE_URL ?>/assets/images/logo_unika.png" alt="Logo Unika" height="40" class="me-2 d-md-none">
            <img src="<?= BASE_URL ?>/assets/images/logo_siega.png" alt="Logo Siega" height="40" class="me-2 d-md-none">
            <!-- Desktop Logos -->
            <img src="<?= BASE_URL ?>/assets/images/logo_unika.png" alt="Logo Unika" height="85" class="me-3 d-none d-md-inline">
            <img src="<?= BASE_URL ?>/assets/images/logo_siega.png" alt="Logo Siega" height="85" class="me-3 d-none d-md-inline">
            <span class="ms-1 ms-md-2 fw-semibold" style="font-size: clamp(1rem, 3vw, 1.25rem);">Lab Peminjaman</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/admin/kelola_ruangan.php">Ruangan</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/admin/kelola_alat.php">Alat</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/admin/pengembalian_alat.php">Pengembalian</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/user/dashboard.php">Dashboard</a></li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="pinjamDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                Form Peminjaman
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="pinjamDropdown">
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>/user/pinjam_ruangan.php">Pinjam Ruangan (Lab)</a></li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>/user/pinjam_alat.php">Pinjam Alat Lab</a></li>
                            </ul>
                        </li>
                        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/user/riwayat.php">Riwayat</a></li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link text-danger" href="<?= BASE_URL ?>/auth/logout.php">Logout (<?= htmlspecialchars($_SESSION['name']) ?>)</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/auth/login.php?role=user">Login User</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/auth/login.php?role=admin">Login Admin</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <?php displayFlashMessage(); ?>
