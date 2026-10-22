<?php
// admin/kelola_ruangan.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('admin');

// Handle Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();

        if ($action === 'process_group') {
            $group_items = $_POST['group_items'] ?? [];
            $approved_items = $_POST['approved_items'] ?? [];
            $reason = trim($_POST['rejection_reason'] ?? '');
            
            $has_rejection = count($approved_items) < count($group_items);
            
            if ($has_rejection && empty($reason)) {
                setFlashMessage('danger', 'Alasan penolakan harus diisi untuk meja yang tidak dicentang.');
            } else {
                $processed = 0;
                foreach ($group_items as $id) {
                    $stmt_check = $pdo->prepare("SELECT status FROM room_bookings WHERE id = ? FOR UPDATE");
                    $stmt_check->execute([$id]);
                    $booking = $stmt_check->fetch();
                    
                    if ($booking && $booking['status'] === 'pending') {
                        if (in_array($id, $approved_items)) {
                            $stmt = $pdo->prepare("UPDATE room_bookings SET status = 'approved' WHERE id = ?");
                            $stmt->execute([$id]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE room_bookings SET status = 'rejected', rejection_reason = ? WHERE id = ?");
                            $stmt->execute([$reason, $id]);
                        }
                        $processed++;
                    }
                }
                if ($processed > 0) {
                    setFlashMessage('success', 'Pengajuan ruangan berhasil diproses.');
                } else {
                    setFlashMessage('danger', 'Tidak ada pengajuan valid yang diproses.');
                }
            }
        } elseif ($action === 'approve') {
            $id = $_POST['id'] ?? '';
            $stmt_check = $pdo->prepare("SELECT status FROM room_bookings WHERE id = ? FOR UPDATE");
            $stmt_check->execute([$id]);
            $booking = $stmt_check->fetch();

            if ($booking && $booking['status'] === 'pending') {
                $stmt = $pdo->prepare("UPDATE room_bookings SET status = 'approved' WHERE id = ?");
                $stmt->execute([$id]);
                setFlashMessage('success', 'Pengajuan berhasil disetujui.');
            } else {
                setFlashMessage('danger', 'Pengajuan tidak valid atau status sudah berubah.');
            }
        } elseif ($action === 'reject') {
            $id = $_POST['id'] ?? '';
            $reason = trim($_POST['rejection_reason'] ?? '');
            if (empty($reason)) {
                setFlashMessage('danger', 'Alasan penolakan harus diisi.');
            } else {
                $stmt_check = $pdo->prepare("SELECT status FROM room_bookings WHERE id = ? FOR UPDATE");
                $stmt_check->execute([$id]);
                $booking = $stmt_check->fetch();

                if ($booking && $booking['status'] === 'pending') {
                    $stmt = $pdo->prepare("UPDATE room_bookings SET status = 'rejected', rejection_reason = ? WHERE id = ?");
                    $stmt->execute([$reason, $id]);
                    setFlashMessage('success', 'Pengajuan berhasil ditolak.');
                } else {
                    setFlashMessage('danger', 'Pengajuan tidak valid atau status sudah berubah.');
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlashMessage('danger', 'Terjadi kesalahan sistem.');
    }
    
    redirect('/admin/kelola_ruangan.php');
}

// Fetch Pending Bookings
$stmt = $pdo->query("
    SELECT rb.*, u.name as user_name, u.phone as user_phone, u.email as user_email, t.name as table_name 
    FROM room_bookings rb 
    JOIN users u ON rb.user_id = u.id 
    JOIN tables t ON rb.table_id = t.id 
    WHERE rb.status = 'pending'
    ORDER BY rb.booking_date ASC, rb.start_time ASC, rb.created_at ASC, CAST(SUBSTRING(t.name, 5) AS UNSIGNED) ASC
");
$pending_bookings = $stmt->fetchAll();

$grouped_pending = [];
foreach ($pending_bookings as $b) {
    // Generate a unique key for the request group
    $key = $b['user_id'] . '_' . $b['booking_date'] . '_' . $b['start_time'] . '_' . $b['end_time'] . '_' . $b['created_at'];
    if (!isset($grouped_pending[$key])) {
        $grouped_pending[$key] = [
            'user_name' => $b['user_name'],
            'user_phone' => $b['user_phone'] ?? '',
            'user_email' => $b['user_email'] ?? '',
            'booking_date' => $b['booking_date'],
            'start_time' => $b['start_time'],
            'end_time' => $b['end_time'],
            'created_at' => $b['created_at'],
            'items' => []
        ];
    }
    $grouped_pending[$key]['items'][] = $b;
}

// Pagination configuration for history
$limit = 10;
$history_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$history_offset = ($history_page - 1) * $limit;

$stmt_history_count = $pdo->query("
    SELECT COUNT(*) 
    FROM room_bookings rb 
    WHERE rb.status != 'pending'
");
$history_total_records = $stmt_history_count->fetchColumn();
$history_total_pages = ceil($history_total_records / $limit);

// Fetch All Bookings (History)
$stmt_history = $pdo->query("
    SELECT rb.*, u.name as user_name, u.phone as user_phone, t.name as table_name 
    FROM room_bookings rb 
    JOIN users u ON rb.user_id = u.id 
    JOIN tables t ON rb.table_id = t.id 
    WHERE rb.status != 'pending'
    ORDER BY rb.created_at DESC
    LIMIT $limit OFFSET $history_offset
");
$history_bookings = $stmt_history->fetchAll();

$active_tab = isset($_GET['page']) ? 'history' : 'pending';

require_once __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-4">Kelola Pengajuan Ruangan</h3>

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
                        <th>Waktu Pengajuan</th>
                        <th>Tanggal Booking</th>
                        <th>Waktu</th>
                        <th>Total Meja</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($grouped_pending) === 0): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">Tidak ada pengajuan yang menunggu persetujuan.</td></tr>
                    <?php else: ?>
                        <?php $modal_id = 0; ?>
                        <?php foreach($grouped_pending as $key => $group): ?>
                            <?php $modal_id++; ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($group['user_name']) ?></strong>
                                    <div class="mt-1">
                                        <?php if (!empty($group['user_phone'])): ?>
                                            <?php 
                                                $wa_phone = preg_replace('/[^0-9]/', '', $group['user_phone']);
                                                if (str_starts_with($wa_phone, '0')) {
                                                    $wa_phone = '62' . substr($wa_phone, 1);
                                                }
                                            ?>
                                            <a href="https://wa.me/<?= $wa_phone ?>" target="_blank" class="badge bg-success text-decoration-none" title="Hubungi via WhatsApp">
                                                📞 <?= htmlspecialchars($group['user_phone']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No. Telp (-)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?= date('d M Y', strtotime($group['created_at'])) ?></div>
                                    <small class="text-muted"><?= date('H:i', strtotime($group['created_at'])) ?> WIB</small>
                                </td>
                                <td><?= htmlspecialchars($group['booking_date']) ?></td>
                                <td><?= htmlspecialchars(substr($group['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($group['end_time'], 0, 5)) ?> WIB</td>
                                <td>
                                    <span class="badge bg-secondary"><?= count($group['items']) ?> Meja</span>
                                    <div class="small text-muted mt-1">
                                        <?= htmlspecialchars(implode(', ', array_column($group['items'], 'table_name'))) ?>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#processModal<?= $modal_id ?>">Review & Proses</button>
                                    
                                    <!-- Modal Review -->
                                    <div class="modal fade" id="processModal<?= $modal_id ?>" tabindex="-1">
                                      <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                          <form method="POST" action="">
                                              <div class="modal-header">
                                                <h5 class="modal-title">Review Pengajuan Ruangan / Meja</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                              </div>
                                              <div class="modal-body">
                                                <input type="hidden" name="action" value="process_group">
                                                <div class="card bg-light p-3 mb-3 border-0">
                                                    <div class="row">
                                                        <div class="col-sm-6">
                                                            <strong>Pemohon:</strong> <?= htmlspecialchars($group['user_name']) ?><br>
                                                            <strong>No. Telp / WA:</strong> 
                                                            <?php if (!empty($group['user_phone'])): ?>
                                                                <?php 
                                                                    $wa_phone = preg_replace('/[^0-9]/', '', $group['user_phone']);
                                                                    if (str_starts_with($wa_phone, '0')) {
                                                                        $wa_phone = '62' . substr($wa_phone, 1);
                                                                    }
                                                                ?>
                                                                <a href="https://wa.me/<?= $wa_phone ?>" target="_blank" class="fw-bold text-success text-decoration-none">
                                                                    <?= htmlspecialchars($group['user_phone']) ?> 📱 (Chat WA)
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="text-danger small">Belum diisi</span>
                                                            <?php endif; ?><br>
                                                            <strong>Email:</strong> <?= htmlspecialchars($group['user_email'] ?? '-') ?>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <strong>Waktu Pengajuan:</strong> <?= date('d M Y H:i', strtotime($group['created_at'])) ?> WIB<br>
                                                            <strong>Tanggal Booking:</strong> <?= htmlspecialchars($group['booking_date']) ?><br>
                                                            <strong>Jam:</strong> <?= htmlspecialchars(substr($group['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($group['end_time'], 0, 5)) ?> WIB
                                                        </div>
                                                    </div>
                                                </div>
                                                <p class="text-muted small mb-2">Pilih meja yang disetujui (centang) atau ditolak (hilangkan centang). Jika ada meja yang ditolak, alasan penolakan wajib diisi.</p>
                                                <table class="table table-bordered table-sm mb-3 text-start">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Nama Meja</th>
                                                            <th style="width:110px;" class="text-center">Setujui?</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($group['items'] as $item): ?>
                                                        <tr>
                                                            <td class="align-middle">
                                                                <span class="badge bg-primary me-2"><?= htmlspecialchars($item['table_name']) ?></span>
                                                            </td>
                                                            <td class="text-center align-middle">
                                                                <input type="hidden" name="group_items[]" value="<?= $item['id'] ?>">
                                                                <input type="checkbox" name="approved_items[]" value="<?= $item['id'] ?>" class="form-check-input" checked style="cursor: pointer; transform: scale(1.2);">
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                
                                                <div class="mb-2 text-start">
                                                    <label class="form-label text-danger">Alasan Penolakan <em>(Wajib diisi jika ada meja yang tidak dicentang)</em></label>
                                                    <textarea name="rejection_reason" class="form-control" rows="2" placeholder="Contoh: Meja sedang dalam pemeliharaan / Jadwal bentrok"></textarea>
                                                </div>
                                              </div>
                                              <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                <button type="submit" class="btn btn-success">Proses Pengajuan</button>
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
                        <th>Meja</th>
                        <th>Waktu</th>
                        <th>Status</th>
                        <th>Alasan Penolakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($history_bookings) === 0): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">Belum ada histori.</td></tr>
                    <?php else: ?>
                        <?php foreach($history_bookings as $b): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($b['user_name']) ?></strong>
                                    <?php if (!empty($b['user_phone'])): ?>
                                        <div class="small text-muted">📞 <?= htmlspecialchars($b['user_phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($b['booking_date']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($b['table_name']) ?></span></td>
                                <td><?= htmlspecialchars(substr($b['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($b['end_time'], 0, 5)) ?> WIB</td>
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
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <span class="text-muted small">Menampilkan <?= count($history_bookings) > 0 ? $history_offset + 1 : 0 ?> sampai <?= $history_offset + count($history_bookings) ?> dari <?= $history_total_records ?> entri</span>
                <?php renderPagination($history_page, $history_total_pages); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
