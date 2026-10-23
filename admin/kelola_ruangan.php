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

// Filter configuration for history
$filter_type = $_GET['filter_type'] ?? 'all';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_week = $_GET['week'] ?? date('Y-\WW');
$selected_month = $_GET['month'] ?? date('Y-m');

$history_filter_sql = "";
$history_filter_params = [];
$filter_label = "Keseluruhan";

if ($filter_type === 'daily') {
    if (!empty($selected_date)) {
        $history_filter_sql = " AND rb.booking_date = ? ";
        $history_filter_params[] = $selected_date;
        $filter_label = "Harian (" . date('d M Y', strtotime($selected_date)) . ")";
    }
} elseif ($filter_type === 'weekly') {
    if (preg_match('/^(\d{4})-W(\d{2})$/', $selected_week, $matches)) {
        $dto = new DateTime();
        $dto->setISODate((int)$matches[1], (int)$matches[2], 1);
        $week_start = $dto->format('Y-m-d');
        $dto->setISODate((int)$matches[1], (int)$matches[2], 7);
        $week_end = $dto->format('Y-m-d');
    } else {
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
    }
    $history_filter_sql = " AND rb.booking_date BETWEEN ? AND ? ";
    $history_filter_params[] = $week_start;
    $history_filter_params[] = $week_end;
    $filter_label = "Mingguan (" . date('d M Y', strtotime($week_start)) . " - " . date('d M Y', strtotime($week_end)) . ")";
} elseif ($filter_type === 'monthly') {
    if (preg_match('/^(\d{4})-(\d{2})$/', $selected_month)) {
        $month_start = $selected_month . '-01';
        $month_end = date('Y-m-t', strtotime($month_start));
    } else {
        $selected_month = date('Y-m');
        $month_start = date('Y-m-01');
        $month_end = date('Y-m-t');
    }
    $history_filter_sql = " AND rb.booking_date BETWEEN ? AND ? ";
    $history_filter_params[] = $month_start;
    $history_filter_params[] = $month_end;
    $filter_label = "Bulanan (" . date('F Y', strtotime($month_start)) . ")";
}

// Handle Export XLSX
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require_once __DIR__ . '/../includes/libs/SimpleXLSXGen.php';
    $stmt_export = $pdo->prepare("
        SELECT rb.*, u.name as user_name, u.email as user_email, u.phone as user_phone, t.name as table_name 
        FROM room_bookings rb 
        JOIN users u ON rb.user_id = u.id 
        JOIN tables t ON rb.table_id = t.id 
        WHERE rb.status != 'pending' $history_filter_sql
        ORDER BY rb.created_at DESC
    ");
    $stmt_export->execute($history_filter_params);
    $rows = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    $excelData = [
        ['<b>No</b>', '<b>Pemohon</b>', '<b>Email</b>', '<b>No. HP</b>', '<b>Waktu Pengajuan</b>', '<b>Tanggal Booking</b>', '<b>Jam Mulai</b>', '<b>Jam Selesai</b>', '<b>Meja</b>', '<b>Status</b>', '<b>Alasan Penolakan</b>']
    ];
    $no = 1;
    foreach ($rows as $r) {
        $excelData[] = [
            $no++,
            $r['user_name'],
            $r['user_email'] ?? '-',
            $r['user_phone'] ?? '-',
            date('d/m/Y H:i', strtotime($r['created_at'])),
            date('d/m/Y', strtotime($r['booking_date'])),
            substr($r['start_time'], 0, 5) . ' WIB',
            substr($r['end_time'], 0, 5) . ' WIB',
            $r['table_name'],
            ucfirst($r['status']),
            $r['rejection_reason'] ?: '-'
        ];
    }
    if (count($excelData) === 1) {
        $excelData[] = ['-', '-', '-', '-', '-', 'Tidak ada data histori peminjaman meja untuk periode ini', '-', '-', '-', '-', '-'];
    }
    $filter_tag = match($filter_type) {
        'daily' => 'Harian_' . $selected_date,
        'weekly' => 'Mingguan_' . str_replace('-', '_', $selected_week),
        'monthly' => 'Bulanan_' . $selected_month,
        default => 'Semua'
    };
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excelData, 'Histori Peminjaman Ruangan');
    $xlsx->downloadAs('Histori_Peminjaman_Ruangan_' . $filter_tag . '_' . date('Ymd_His') . '.xlsx');
    exit;
}

// Pagination configuration for history
$limit = 10;
$history_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$history_offset = ($history_page - 1) * $limit;

$stmt_history_count = $pdo->prepare("
    SELECT COUNT(*) 
    FROM room_bookings rb 
    WHERE rb.status != 'pending' $history_filter_sql
");
$stmt_history_count->execute($history_filter_params);
$history_total_records = (int)$stmt_history_count->fetchColumn();
$history_total_pages = max(1, ceil($history_total_records / $limit));

// Fetch All Bookings (History)
$stmt_history = $pdo->prepare("
    SELECT rb.*, u.name as user_name, u.phone as user_phone, t.name as table_name 
    FROM room_bookings rb 
    JOIN users u ON rb.user_id = u.id 
    JOIN tables t ON rb.table_id = t.id 
    WHERE rb.status != 'pending' $history_filter_sql
    ORDER BY rb.created_at DESC
    LIMIT $limit OFFSET $history_offset
");
$stmt_history->execute($history_filter_params);
$history_bookings = $stmt_history->fetchAll();

$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'history') || isset($_GET['page']) || isset($_GET['filter_type']) ? 'history' : 'pending';

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
        <!-- Filter Card -->
        <div class="card shadow-sm border-0 mb-3 bg-light">
            <div class="card-body p-3">
                <form method="GET" action="" id="historyFilterForm" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" value="history">
                    
                    <div class="col-12 col-md-auto">
                        <label class="form-label mb-1 small fw-semibold text-muted d-block">Pilihan Riwayat:</label>
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <input type="radio" class="btn-check" name="filter_type" id="filter_all" value="all" <?= $filter_type === 'all' ? 'checked' : '' ?> onchange="updateAdminFilterUI()">
                            <label class="btn btn-outline-primary" for="filter_all">Keseluruhan</label>

                            <input type="radio" class="btn-check" name="filter_type" id="filter_daily" value="daily" <?= $filter_type === 'daily' ? 'checked' : '' ?> onchange="updateAdminFilterUI()">
                            <label class="btn btn-outline-primary" for="filter_daily">Harian</label>

                            <input type="radio" class="btn-check" name="filter_type" id="filter_weekly" value="weekly" <?= $filter_type === 'weekly' ? 'checked' : '' ?> onchange="updateAdminFilterUI()">
                            <label class="btn btn-outline-primary" for="filter_weekly">Mingguan</label>

                            <input type="radio" class="btn-check" name="filter_type" id="filter_monthly" value="monthly" <?= $filter_type === 'monthly' ? 'checked' : '' ?> onchange="updateAdminFilterUI()">
                            <label class="btn btn-outline-primary" for="filter_monthly">Bulanan</label>
                        </div>
                    </div>

                    <!-- Input Date (Harian) -->
                    <div class="col-12 col-md-auto filter-input-box" id="box_daily" style="<?= $filter_type === 'daily' ? '' : 'display:none;' ?>">
                        <label class="form-label mb-1 small fw-semibold text-muted d-block">Pilih Tanggal:</label>
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_date) ?>">
                    </div>

                    <!-- Input Week (Mingguan) -->
                    <div class="col-12 col-md-auto filter-input-box" id="box_weekly" style="<?= $filter_type === 'weekly' ? '' : 'display:none;' ?>">
                        <label class="form-label mb-1 small fw-semibold text-muted d-block">Pilih Minggu:</label>
                        <input type="week" name="week" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_week) ?>">
                    </div>

                    <!-- Input Month (Bulanan) -->
                    <div class="col-12 col-md-auto filter-input-box" id="box_monthly" style="<?= $filter_type === 'monthly' ? '' : 'display:none;' ?>">
                        <label class="form-label mb-1 small fw-semibold text-muted d-block">Pilih Bulan:</label>
                        <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_month) ?>">
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-md-auto d-flex align-items-end gap-1 mt-2 mt-md-4">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-filter"></i> Terapkan
                        </button>
                        <a href="?tab=history" class="btn btn-sm btn-outline-secondary" title="Reset Filter">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </a>
                    </div>

                    <!-- Export Button -->
                    <div class="col-12 col-md d-flex justify-content-md-end align-items-end mt-2 mt-md-4">
                        <a href="?export=xlsx&tab=history&filter_type=<?= $filter_type ?>&date=<?= urlencode($selected_date) ?>&week=<?= urlencode($selected_week) ?>&month=<?= urlencode($selected_month) ?>" 
                           class="btn btn-sm btn-success d-inline-flex align-items-center gap-1 shadow-sm">
                            <i class="bi bi-file-earmark-excel-fill"></i> Download XLSX
                        </a>
                    </div>
                </form>
            </div>
        </div>

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
                        <tr><td colspan="6" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada histori<?= $filter_type !== 'all' ? ' untuk periode (' . htmlspecialchars($filter_label) . ')' : '' ?>.</td></tr>
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
                <?php renderPagination($history_page, $history_total_pages, ['tab' => 'history', 'filter_type' => $filter_type, 'date' => $selected_date, 'week' => $selected_week, 'month' => $selected_month]); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function updateAdminFilterUI() {
    const selected = document.querySelector('input[name="filter_type"]:checked')?.value || 'all';
    const boxDaily = document.getElementById('box_daily');
    const boxWeekly = document.getElementById('box_weekly');
    const boxMonthly = document.getElementById('box_monthly');
    
    if (boxDaily) boxDaily.style.display = (selected === 'daily') ? 'block' : 'none';
    if (boxWeekly) boxWeekly.style.display = (selected === 'weekly') ? 'block' : 'none';
    if (boxMonthly) boxMonthly.style.display = (selected === 'monthly') ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
