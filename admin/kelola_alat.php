<?php
// admin/kelola_alat.php
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
                setFlashMessage('danger', 'Alasan penolakan harus diisi untuk barang yang tidak dicentang.');
            } else {
                $processed = 0;
                foreach ($group_items as $id) {
                    $stmt_check = $pdo->prepare("SELECT status FROM tool_bookings WHERE id = ? FOR UPDATE");
                    $stmt_check->execute([$id]);
                    $booking = $stmt_check->fetch();
                    
                    if ($booking && $booking['status'] === 'pending') {
                        if (in_array($id, $approved_items)) {
                            $stmt = $pdo->prepare("UPDATE tool_bookings SET status = 'approved' WHERE id = ?");
                            $stmt->execute([$id]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE tool_bookings SET status = 'rejected', rejection_reason = ? WHERE id = ?");
                            $stmt->execute([$reason, $id]);
                        }
                        $processed++;
                    }
                }
                if ($processed > 0) {
                    setFlashMessage('success', 'Pengajuan alat berhasil diproses.');
                } else {
                    setFlashMessage('danger', 'Tidak ada pengajuan valid yang diproses.');
                }
            }
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
    SELECT tb.*, u.name as user_name, u.phone as user_phone, u.email as user_email, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.status = 'pending'
    ORDER BY tb.booking_date ASC, tb.start_time ASC, tb.created_at ASC
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
            'end_date' => $b['end_date'],
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
    FROM tool_bookings tb 
    WHERE tb.status != 'pending'
");
$history_total_records = $stmt_history_count->fetchColumn();
$history_total_pages = ceil($history_total_records / $limit);

// Fetch All Bookings (History)
$stmt_history = $pdo->query("
    SELECT tb.*, u.name as user_name, u.phone as user_phone, t.name as tool_name 
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
                        <th>Waktu Pengajuan</th>
                        <th>Tanggal Booking</th>
                        <th>Waktu</th>
                        <th>Total Barang</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($grouped_pending) === 0): ?>
                        <tr><td colspan="6" class="text-center">Tidak ada pengajuan yang menunggu persetujuan.</td></tr>
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
                                <td><?= htmlspecialchars($group['created_at']) ?></td>
                                <td>
                                    <?= htmlspecialchars($group['booking_date']) ?>
                                    <?php if (!empty($group['end_date']) && $group['end_date'] !== $group['booking_date']): ?>
                                        s/d <?= htmlspecialchars($group['end_date']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($group['start_time'] === '00:00:00' && $group['end_time'] === '23:59:59'): ?>
                                        <span class="text-muted">Seharian</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($group['start_time']) ?> - <?= htmlspecialchars($group['end_time']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= count($group['items']) ?> Jenis</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#processModal<?= $modal_id ?>">Review & Proses</button>
                                    
                                    <!-- Modal Review -->
                                    <div class="modal fade" id="processModal<?= $modal_id ?>" tabindex="-1">
                                      <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                          <form method="POST" action="">
                                              <div class="modal-header">
                                                <h5 class="modal-title">Review Pengajuan Alat</h5>
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
                                                            <strong>Tanggal Booking:</strong> <?= htmlspecialchars($group['booking_date']) ?>
                                                            <?php if (!empty($group['end_date']) && $group['end_date'] !== $group['booking_date']): ?>
                                                                s/d <?= htmlspecialchars($group['end_date']) ?>
                                                            <?php endif; ?><br>
                                                            <strong>Waktu:</strong> 
                                                            <?php if ($group['start_time'] === '00:00:00' && $group['end_time'] === '23:59:59'): ?>
                                                                Seharian
                                                            <?php else: ?>
                                                                <?= htmlspecialchars(substr($group['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($group['end_time'], 0, 5)) ?> WIB
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <table class="table table-bordered table-sm mb-3 text-start align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Nama Alat</th>
                                                            <th>Jumlah</th>
                                                            <th style="width:100px;" class="text-center">Setujui?</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($group['items'] as $item): ?>
                                                        <tr>
                                                            <td><strong><?= htmlspecialchars($item['tool_name']) ?></strong></td>
                                                            <td><?= htmlspecialchars($item['quantity']) ?> Unit</td>
                                                            <td class="text-center">
                                                                <input type="hidden" name="group_items[]" value="<?= $item['id'] ?>">
                                                                <input type="checkbox" name="approved_items[]" value="<?= $item['id'] ?>" class="form-check-input" checked>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                
                                                <div class="mb-2 text-start">
                                                    <label class="form-label text-danger">Alasan Penolakan <em>(Wajib diisi jika ada barang yang tidak dicentang)</em></label>
                                                    <textarea name="rejection_reason" class="form-control" rows="2" placeholder="Contoh: Stok tidak mencukupi / Sedang perbaikan"></textarea>
                                                </div>
                                              </div>
                                              <div class="modal-footer">
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
                                <td>
                                    <strong><?= htmlspecialchars($b['user_name']) ?></strong>
                                    <?php if (!empty($b['user_phone'])): ?>
                                        <div class="small text-muted">📞 <?= htmlspecialchars($b['user_phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($b['booking_date']) ?>
                                    <?php if (!empty($b['end_date']) && $b['end_date'] !== $b['booking_date']): ?>
                                        s/d <?= htmlspecialchars($b['end_date']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($b['tool_name']) ?></td>
                                <td><?= htmlspecialchars($b['quantity']) ?></td>
                                <td>
                                    <?php if ($b['start_time'] === '00:00:00' && $b['end_time'] === '23:59:59'): ?>
                                        <span class="text-muted">Seharian</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($b['start_time']) ?> - <?= htmlspecialchars($b['end_time']) ?>
                                    <?php endif; ?>
                                </td>
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
