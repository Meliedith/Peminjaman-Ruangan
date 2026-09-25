<?php
// admin/dashboard.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('admin');

// 1. Check auto-expire fallback here (if cron doesn't run)
// Expire pending bookings where start_time has passed
$stmt_expire = $pdo->prepare("
    UPDATE room_bookings 
    SET status = 'expired' 
    WHERE status = 'pending' 
    AND (booking_date < CURDATE() OR (booking_date = CURDATE() AND end_time <= CURTIME()))
");
$stmt_expire->execute();

$stmt_expire_tool = $pdo->prepare("
    UPDATE tool_bookings 
    SET status = 'expired' 
    WHERE status = 'pending' 
    AND (booking_date < CURDATE() OR (booking_date = CURDATE() AND end_time <= CURTIME()))
");
$stmt_expire_tool->execute();

// No-show for approved tool bookings where end_time has passed but not completed
$stmt_noshow = $pdo->prepare("
    UPDATE tool_bookings 
    SET status = 'no_show' 
    WHERE status = 'approved' 
    AND (booking_date < CURDATE() OR (booking_date = CURDATE() AND end_time < CURTIME()))
");
$stmt_noshow->execute();

// Fetch statistics
$stmt1 = $pdo->query("SELECT COUNT(*) FROM room_bookings WHERE status = 'pending'");
$pending_room_count = $stmt1->fetchColumn();

$stmt2 = $pdo->query("SELECT COUNT(*) FROM tool_bookings WHERE status = 'pending'");
$pending_tool_count = $stmt2->fetchColumn();

$stmt3 = $pdo->query("SELECT COUNT(*) FROM tool_bookings WHERE status = 'approved'");
$active_tools_count = $stmt3->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4">Admin Dashboard</h2>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-4 bg-warning bg-opacity-25 border-warning">
            <h3 class="display-4 font-weight-bold text-warning"><?= $pending_room_count ?></h3>
            <p class="mb-0">Pending Ruangan</p>
            <a href="<?= BASE_URL ?>/admin/kelola_ruangan.php" class="stretched-link"></a>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-4 bg-warning bg-opacity-25 border-warning">
            <h3 class="display-4 font-weight-bold text-warning"><?= $pending_tool_count ?></h3>
            <p class="mb-0">Pending Alat</p>
            <a href="<?= BASE_URL ?>/admin/kelola_alat.php" class="stretched-link"></a>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm text-center p-4 bg-info bg-opacity-25 border-info">
            <h3 class="display-4 font-weight-bold text-info"><?= $active_tools_count ?></h3>
            <p class="mb-0">Alat Sedang Dipinjam</p>
            <a href="<?= BASE_URL ?>/admin/pengembalian_alat.php" class="stretched-link"></a>
        </div>
    </div>
</div>

<div class="row mt-5">
    <div class="col-12 text-center">
        <a href="<?= BASE_URL ?>/admin/master_data.php" class="btn btn-outline-secondary">Kelola Master Data (Alat & Meja)</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
