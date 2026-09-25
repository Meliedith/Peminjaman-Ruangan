<?php
// user/dashboard.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

// Protect route
checkAuth('user');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mt-4">
    <div class="col-12">
        <h2 class="mb-4">Selamat datang, <?= htmlspecialchars($_SESSION['name']) ?>!</h2>
    </div>
</div>

<div class="row g-4 mt-2">
    <div class="col-md-6">
        <div class="card h-100 shadow-sm text-center p-5">
            <h3 class="card-title">Pinjam Ruangan (Lab)</h3>
            <p class="card-text text-muted">Booking meja/slot di laboratorium untuk keperluan praktikum atau penelitian.</p>
            <a href="<?= BASE_URL ?>/user/pinjam_ruangan.php" class="btn btn-primary btn-lg mt-auto">Pilih Jadwal Ruangan</a>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100 shadow-sm text-center p-5">
            <h3 class="card-title">Pinjam Alat</h3>
            <p class="card-text text-muted">Booking alat-alat laboratorium sesuai kebutuhan dan cek ketersediaan stok.</p>
            <a href="<?= BASE_URL ?>/user/pinjam_alat.php" class="btn btn-success btn-lg mt-auto">Pilih Alat Lab</a>
        </div>
    </div>
</div>

<div class="row mt-5">
    <div class="col-12 text-center">
        <a href="<?= BASE_URL ?>/user/riwayat.php" class="btn btn-outline-secondary">Lihat Riwayat & Status Pengajuan Saya</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
