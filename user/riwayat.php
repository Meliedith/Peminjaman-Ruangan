<?php
// user/riwayat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('user');
$user_id = $_SESSION['user_id'];

// Handle Cancel Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $type = $_POST['type'] ?? '';
    $id = $_POST['id'] ?? '';
    
    if ($type === 'room') {
        $stmt = $pdo->prepare("UPDATE room_bookings SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
        if ($stmt->execute([$id, $user_id]) && $stmt->rowCount() > 0) {
            setFlashMessage('success', 'Pengajuan ruangan berhasil dibatalkan.');
        } else {
            setFlashMessage('danger', 'Gagal membatalkan. Status mungkin sudah berubah.');
        }
    } elseif ($type === 'tool') {
        $stmt = $pdo->prepare("UPDATE tool_bookings SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
        if ($stmt->execute([$id, $user_id]) && $stmt->rowCount() > 0) {
            setFlashMessage('success', 'Pengajuan alat berhasil dibatalkan.');
        } else {
            setFlashMessage('danger', 'Gagal membatalkan. Status mungkin sudah berubah.');
        }
    }
    redirect('/user/riwayat.php');
}

// Pagination configuration
$limit = 10;
$room_page = isset($_GET['room_page']) ? max(1, (int)$_GET['room_page']) : 1;
$room_offset = ($room_page - 1) * $limit;

$tool_page = isset($_GET['tool_page']) ? max(1, (int)$_GET['tool_page']) : 1;
$tool_offset = ($tool_page - 1) * $limit;

// Fetch Room Bookings Count
$stmt_room_count = $pdo->prepare("SELECT COUNT(*) FROM room_bookings WHERE user_id = ?");
$stmt_room_count->execute([$user_id]);
$room_total_records = $stmt_room_count->fetchColumn();
$room_total_pages = ceil($room_total_records / $limit);

// Fetch Room Bookings
$stmt_room = $pdo->prepare("
    SELECT rb.*, t.name as table_name 
    FROM room_bookings rb 
    JOIN tables t ON rb.table_id = t.id 
    WHERE rb.user_id = ? 
    ORDER BY rb.created_at DESC
    LIMIT $limit OFFSET $room_offset
");
$stmt_room->execute([$user_id]);
$room_bookings = $stmt_room->fetchAll();

// Fetch Tool Bookings Count
$stmt_tool_count = $pdo->prepare("SELECT COUNT(*) FROM tool_bookings WHERE user_id = ?");
$stmt_tool_count->execute([$user_id]);
$tool_total_records = $stmt_tool_count->fetchColumn();
$tool_total_pages = ceil($tool_total_records / $limit);

// Fetch Tool Bookings
$stmt_tool = $pdo->prepare("
    SELECT tb.*, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.user_id = ? 
    ORDER BY tb.created_at DESC
    LIMIT $limit OFFSET $tool_offset
");
$stmt_tool->execute([$user_id]);
$tool_bookings = $stmt_tool->fetchAll();

// Determine Active Tab based on query param
$active_tab = isset($_GET['tool_page']) && !isset($_GET['room_page']) ? 'tool' : 'room';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <h3 class="mb-4">Riwayat & Status Pengajuan Saya</h3>
        
        <ul class="nav nav-tabs" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'room' ? 'active' : '' ?>" id="room-tab" data-bs-toggle="tab" data-bs-target="#room" type="button" role="tab">Ruangan (Lab)</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'tool' ? 'active' : '' ?>" id="tool-tab" data-bs-toggle="tab" data-bs-target="#tool" type="button" role="tab">Alat Lab</button>
            </li>
        </ul>
        
        <div class="tab-content mt-3" id="myTabContent">
            <!-- Room Bookings -->
            <div class="tab-pane fade <?= $active_tab === 'room' ? 'show active' : '' ?>" id="room" role="tabpanel">
                <div class="table-responsive bg-white shadow-sm rounded p-3">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal Booking</th>
                                <th>Meja</th>
                                <th>Waktu</th>
                                <th>Status</th>
                                <th>Alasan (Jika Ditolak)</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($room_bookings) === 0): ?>
                                <tr><td colspan="6" class="text-center">Belum ada pengajuan ruangan.</td></tr>
                            <?php else: ?>
                                <?php foreach($room_bookings as $rb): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($rb['booking_date']) ?></td>
                                        <td><?= htmlspecialchars($rb['table_name']) ?></td>
                                        <td><?= htmlspecialchars($rb['start_time']) ?> - <?= htmlspecialchars($rb['end_time']) ?></td>
                                        <td>
                                            <span class="status-<?= htmlspecialchars($rb['status']) ?>">
                                                <?= ucfirst(htmlspecialchars($rb['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($rb['rejection_reason'] ?? '-') ?></td>
                                        <td>
                                            <?php if($rb['status'] === 'pending'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Yakin ingin membatalkan pengajuan ini?');">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="type" value="room">
                                                <input type="hidden" name="id" value="<?= $rb['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                            </form>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($room_total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="text-muted small">Menampilkan <?= count($room_bookings) > 0 ? $room_offset + 1 : 0 ?> sampai <?= $room_offset + count($room_bookings) ?> dari <?= $room_total_records ?> entri</span>
                        <nav>
                            <ul class="pagination pagination-sm m-0">
                                <li class="page-item <?= ($room_page <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?room_page=<?= max(1, $room_page - 1) ?>&tool_page=<?= $tool_page ?>">Sebelumnya</a>
                                </li>
                                <?php for ($i = 1; $i <= $room_total_pages; $i++): ?>
                                    <li class="page-item <?= ($i === $room_page) ? 'active' : '' ?>">
                                        <a class="page-link" href="?room_page=<?= $i ?>&tool_page=<?= $tool_page ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= ($room_page >= $room_total_pages) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?room_page=<?= min($room_total_pages, $room_page + 1) ?>&tool_page=<?= $tool_page ?>">Selanjutnya</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tool Bookings -->
            <div class="tab-pane fade <?= $active_tab === 'tool' ? 'show active' : '' ?>" id="tool" role="tabpanel">
                <div class="table-responsive bg-white shadow-sm rounded p-3">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal Booking</th>
                                <th>Alat</th>
                                <th>Jumlah</th>
                                <th>Waktu</th>
                                <th>Status</th>
                                <th>Alasan (Jika Ditolak)</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($tool_bookings) === 0): ?>
                                <tr><td colspan="7" class="text-center">Belum ada pengajuan alat.</td></tr>
                            <?php else: ?>
                                <?php foreach($tool_bookings as $tb): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($tb['booking_date']) ?></td>
                                        <td><?= htmlspecialchars($tb['tool_name']) ?></td>
                                        <td><?= htmlspecialchars($tb['quantity']) ?></td>
                                        <td><?= htmlspecialchars($tb['start_time']) ?> - <?= htmlspecialchars($tb['end_time']) ?></td>
                                        <td>
                                            <span class="status-<?= htmlspecialchars($tb['status']) ?>">
                                                <?= ucfirst(htmlspecialchars($tb['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($tb['rejection_reason'] ?? '-') ?></td>
                                        <td>
                                            <?php if($tb['status'] === 'pending'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Yakin ingin membatalkan pengajuan ini?');">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="type" value="tool">
                                                <input type="hidden" name="id" value="<?= $tb['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                            </form>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($tool_total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="text-muted small">Menampilkan <?= count($tool_bookings) > 0 ? $tool_offset + 1 : 0 ?> sampai <?= $tool_offset + count($tool_bookings) ?> dari <?= $tool_total_records ?> entri</span>
                        <nav>
                            <ul class="pagination pagination-sm m-0">
                                <li class="page-item <?= ($tool_page <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?tool_page=<?= max(1, $tool_page - 1) ?>&room_page=<?= $room_page ?>">Sebelumnya</a>
                                </li>
                                <?php for ($i = 1; $i <= $tool_total_pages; $i++): ?>
                                    <li class="page-item <?= ($i === $tool_page) ? 'active' : '' ?>">
                                        <a class="page-link" href="?tool_page=<?= $i ?>&room_page=<?= $room_page ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= ($tool_page >= $tool_total_pages) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?tool_page=<?= min($tool_total_pages, $tool_page + 1) ?>&room_page=<?= $room_page ?>">Selanjutnya</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
