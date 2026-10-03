<?php
// index.php
require_once __DIR__ . '/includes/header.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        redirect('/admin/dashboard.php');
    } else {
        redirect('/user/dashboard.php');
    }
}
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-8 text-center">
        <h1 class="mb-4">Sistem Informasi Peminjaman Ruangan & Alat Laboratorium</h1>
        <p class="lead mb-5">Silakan masuk untuk melanjutkan.</p>
        
        <div class="d-flex justify-content-center gap-4">
            <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary btn-lg px-5 py-3">Masuk / Login</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
