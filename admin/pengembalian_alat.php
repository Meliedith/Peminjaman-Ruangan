<?php
// admin/pengembalian_alat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete') {
    $id = $_POST['id'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        $stmt_check = $pdo->prepare("SELECT status FROM tool_bookings WHERE id = ? FOR UPDATE");
        $stmt_check->execute([$id]);
        $booking = $stmt_check->fetch();
        
        if ($booking && $booking['status'] === 'approved') {
            $stmt = $pdo->prepare("UPDATE tool_bookings SET status = 'completed' WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'Alat berhasil ditandai selesai/dikembalikan.');
        } else {
            setFlashMessage('danger', 'Data tidak valid atau status bukan approved.');
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlashMessage('danger', 'Terjadi kesalahan sistem.');
    }
    redirect('/admin/pengembalian_alat.php');
}

// Fetch active tools currently in use
$stmt = $pdo->query("
    SELECT tb.*, u.name as user_name, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.status = 'approved'
    ORDER BY tb.booking_date ASC, tb.start_time ASC
");
$active_bookings = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-4">Pengembalian Alat (Alat Sedang Dipinjam)</h3>

<div class="card shadow-sm p-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Pemohon</th>
                    <th>Alat</th>
                    <th>Jumlah</th>
                    <th>Tanggal</th>
                    <th>Waktu</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if(count($active_bookings) === 0): ?>
                    <tr><td colspan="6" class="text-center">Tidak ada alat yang sedang dipinjam (status approved).</td></tr>
                <?php else: ?>
                    <?php foreach($active_bookings as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['user_name']) ?></td>
                            <td><?= htmlspecialchars($b['tool_name']) ?></td>
                            <td><?= htmlspecialchars($b['quantity']) ?></td>
                            <td><?= htmlspecialchars($b['booking_date']) ?></td>
                            <td><?= htmlspecialchars($b['start_time']) ?> - <?= htmlspecialchars($b['end_time']) ?></td>
                            <td>
                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Tandai alat ini sudah selesai / dikembalikan?');">
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-info">Selesai / Kembalikan</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
