<?php
// admin/kelola_alat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('admin');

// Handle Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? '';
    $reason = $_POST['rejection_reason'] ?? '';

    try {
        $pdo->beginTransaction();

        $stmt_check = $pdo->prepare("SELECT status FROM tool_bookings WHERE id = ? FOR UPDATE");
        $stmt_check->execute([$id]);
        $booking = $stmt_check->fetch();

        if ($booking && $booking['status'] === 'pending') {
            if ($action === 'approve') {
                $stmt = $pdo->prepare("UPDATE tool_bookings SET status = 'approved' WHERE id = ?");
                $stmt->execute([$id]);
                setFlashMessage('success', 'Pengajuan alat berhasil disetujui.');
            } elseif ($action === 'reject') {
                if (empty($reason)) {
                    setFlashMessage('danger', 'Alasan penolakan harus diisi.');
                } else {
                    $stmt = $pdo->prepare("UPDATE tool_bookings SET status = 'rejected', rejection_reason = ? WHERE id = ?");
                    $stmt->execute([$reason, $id]);
                    setFlashMessage('success', 'Pengajuan alat berhasil ditolak.');
                }
            }
        } else {
            setFlashMessage('danger', 'Pengajuan tidak valid atau status sudah berubah.');
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlashMessage('danger', 'Terjadi kesalahan sistem.');
    }
    
    redirect('/admin/kelola_alat.php');
}

// Fetch Pending Bookings
$stmt = $pdo->query("
    SELECT tb.*, u.name as user_name, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.status = 'pending'
    ORDER BY tb.booking_date ASC, tb.start_time ASC
");
$pending_bookings = $stmt->fetchAll();

// Pagination configuration for history
$limit = 10;
$history_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$history_offset = ($history_page - 1) * $limit;

$stmt_history_count = $pdo->query("
    SELECT COUNT(*) 
    FROM tool_bookings tb 
    WHERE tb.status != 'pending'
");
$history_total_records = $stmt_history_count->fetchColumn();
$history_total_pages = ceil($history_total_records / $limit);

// Fetch All Bookings (History)
$stmt_history = $pdo->query("
    SELECT tb.*, u.name as user_name, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.status != 'pending'
    ORDER BY tb.created_at DESC
    LIMIT $limit OFFSET $history_offset
");
$history_bookings = $stmt_history->fetchAll();

$active_tab = isset($_GET['page']) ? 'history' : 'pending';

require_once __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-4">Kelola Pengajuan Alat Lab</h3>

<ul class="nav nav-tabs" id="myTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'pending' ? 'active' : '' ?>" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">Menunggu Persetujuan</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'history' ? 'active' : '' ?>" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">Histori Pengajuan</button>
    </li>
</ul>

<div class="tab-content mt-3" id="myTabContent">
    <!-- Pending Bookings -->
    <div class="tab-pane fade <?= $active_tab === 'pending' ? 'show active' : '' ?>" id="pending" role="tabpanel">
        <div class="table-responsive bg-white shadow-sm rounded p-3">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Pemohon</th>
                        <th>Tanggal Booking</th>
                        <th>Alat</th>
                        <th>Jumlah</th>
                        <th>Waktu</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($pending_bookings) === 0): ?>
                        <tr><td colspan="6" class="text-center">Tidak ada pengajuan yang menunggu persetujuan.</td></tr>
                    <?php else: ?>
                        <?php foreach($pending_bookings as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['user_name']) ?></td>
                                <td><?= htmlspecialchars($b['booking_date']) ?></td>
                                <td><?= htmlspecialchars($b['tool_name']) ?></td>
                                <td><?= htmlspecialchars($b['quantity']) ?></td>
                                <td><?= htmlspecialchars($b['start_time']) ?> - <?= htmlspecialchars($b['end_time']) ?></td>
                                <td>
                                    <form method="POST" action="" class="d-inline">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success">Setujui</button>
                                    </form>
                                    
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $b['id'] ?>">Tolak</button>
                                    
                                    <!-- Modal Tolak -->
                                    <div class="modal fade" id="rejectModal<?= $b['id'] ?>" tabindex="-1">
                                      <div class="modal-dialog">
                                        <div class="modal-content">
                                          <form method="POST" action="">
                                              <div class="modal-header">
                                                <h5 class="modal-title">Tolak Pengajuan Alat</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                              </div>
                                              <div class="modal-body">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                                <div class="mb-3">
                                                    <label>Alasan Penolakan</label>
                                                    <textarea name="rejection_reason" class="form-control" required></textarea>
                                                </div>
                                              </div>
                                              <div class="modal-footer">
                                                <button type="submit" class="btn btn-danger">Tolak Pengajuan</button>
                                              </div>
                                          </form>
                                        </div>
                                      </div>
                                    </div>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- History Bookings -->
    <div class="tab-pane fade <?= $active_tab === 'history' ? 'show active' : '' ?>" id="history" role="tabpanel">
        <div class="table-responsive bg-white shadow-sm rounded p-3">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Pemohon</th>
                        <th>Tanggal Booking</th>
                        <th>Alat</th>
                        <th>Jumlah</th>
                        <th>Waktu</th>
                        <th>Status</th>
                        <th>Alasan Penolakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($history_bookings) === 0): ?>
                        <tr><td colspan="7" class="text-center">Belum ada histori.</td></tr>
                    <?php else: ?>
                        <?php foreach($history_bookings as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['user_name']) ?></td>
                                <td><?= htmlspecialchars($b['booking_date']) ?></td>
                                <td><?= htmlspecialchars($b['tool_name']) ?></td>
                                <td><?= htmlspecialchars($b['quantity']) ?></td>
                                <td><?= htmlspecialchars($b['start_time']) ?> - <?= htmlspecialchars($b['end_time']) ?></td>
                                <td>
                                    <span class="status-<?= htmlspecialchars($b['status']) ?>">
                                        <?= ucfirst(htmlspecialchars($b['status'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($b['rejection_reason'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($history_total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <span class="text-muted small">Menampilkan <?= count($history_bookings) > 0 ? $history_offset + 1 : 0 ?> sampai <?= $history_offset + count($history_bookings) ?> dari <?= $history_total_records ?> entri</span>
                <nav>
                    <ul class="pagination pagination-sm m-0">
                        <li class="page-item <?= ($history_page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= max(1, $history_page - 1) ?>">Sebelumnya</a>
                        </li>
                        <?php for ($i = 1; $i <= $history_total_pages; $i++): ?>
                            <li class="page-item <?= ($i === $history_page) ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($history_page >= $history_total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= min($history_total_pages, $history_page + 1) ?>">Selanjutnya</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
