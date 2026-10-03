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

// Pagination for history
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Fetch history count
$stmt_history_count = $pdo->query("SELECT COUNT(*) FROM tool_bookings WHERE status = 'completed'");
$total_history_records = $stmt_history_count->fetchColumn();
$total_history_pages = ceil($total_history_records / $limit);

// Fetch history records
$stmt_history = $pdo->prepare("
    SELECT tb.*, u.name as user_name, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.status = 'completed'
    ORDER BY tb.updated_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt_history->execute();
$history_bookings = $stmt_history->fetchAll();

$active_tab = isset($_GET['page']) ? 'history' : 'active';

require_once __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-4">Pengembalian Alat Lab</h3>

<ul class="nav nav-tabs" id="myTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'active' ? 'active' : '' ?>" id="active-tab" data-bs-toggle="tab" data-bs-target="#active" type="button" role="tab">Sedang Dipinjam</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'history' ? 'active' : '' ?>" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">Histori Pengembalian</button>
    </li>
</ul>

<div class="tab-content mt-3" id="myTabContent">
    <!-- Sedang Dipinjam -->
    <div class="tab-pane fade <?= $active_tab === 'active' ? 'show active' : '' ?>" id="active" role="tabpanel">
        <div class="table-responsive bg-white shadow-sm rounded p-3">
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

    <!-- Histori Pengembalian -->
    <div class="tab-pane fade <?= $active_tab === 'history' ? 'show active' : '' ?>" id="history" role="tabpanel">
        <div class="table-responsive bg-white shadow-sm rounded p-3">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Pemohon</th>
                        <th>Alat</th>
                        <th>Jumlah</th>
                        <th>Tanggal Booking</th>
                        <th>Waktu Pengembalian</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($history_bookings) === 0): ?>
                        <tr><td colspan="6" class="text-center">Belum ada histori pengembalian alat.</td></tr>
                    <?php else: ?>
                        <?php foreach($history_bookings as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars($h['user_name']) ?></td>
                                <td><?= htmlspecialchars($h['tool_name']) ?></td>
                                <td><?= htmlspecialchars($h['quantity']) ?></td>
                                <td><?= htmlspecialchars($h['booking_date']) ?></td>
                                <td><?= htmlspecialchars($h['updated_at']) ?></td>
                                <td><span class="badge bg-success">Dikembalikan</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_history_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <span class="text-muted small">Menampilkan <?= count($history_bookings) > 0 ? $offset + 1 : 0 ?> sampai <?= $offset + count($history_bookings) ?> dari <?= $total_history_records ?> entri</span>
                <nav>
                    <ul class="pagination pagination-sm m-0">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= max(1, $page - 1) ?>">Sebelumnya</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_history_pages; $i++): ?>
                            <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($page >= $total_history_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= min($total_history_pages, $page + 1) ?>">Selanjutnya</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
