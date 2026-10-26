<?php
// admin/kelola_alat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('admin');

// Handle Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlashMessage('danger', 'Sesi keamanan tidak valid (CSRF token mismatch). Silakan muat ulang halaman.');
        redirect('/admin/kelola_alat.php');
    }

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
                    $stmt_check = $pdo->prepare("SELECT * FROM tool_bookings WHERE id = ? FOR UPDATE");
                    $stmt_check->execute([$id]);
                    $booking = $stmt_check->fetch();
                    
                    if ($booking && $booking['status'] === 'pending') {
                        if (in_array($id, $approved_items)) {
                            // Validasi sisa kuota stok alat sebelum menyetujui
                            $stmt_tool = $pdo->prepare("SELECT total_stock, name FROM tools WHERE id = ? AND deleted_at IS NULL");
                            $stmt_tool->execute([$booking['tool_id']]);
                            $tool = $stmt_tool->fetch();
                            if (!$tool) {
                                throw new Exception("Alat tidak ditemukan atau telah dihapus.");
                            }
                            $tool_end_date = !empty($booking['end_date']) ? $booking['end_date'] : $booking['booking_date'];

                            $stmt_booked = $pdo->prepare("
                                SELECT SUM(quantity) as booked_qty FROM tool_bookings 
                                WHERE tool_id = ? 
                                AND status = 'approved' 
                                AND id != ?
                                AND booking_date <= ? 
                                AND IFNULL(end_date, booking_date) >= ?
                                AND start_time < ? 
                                AND end_time > ?
                            ");
                            $stmt_booked->execute([$booking['tool_id'], $id, $tool_end_date, $booking['booking_date'], $booking['end_time'], $booking['start_time']]);
                            $booked_res = $stmt_booked->fetch();
                            $currently_approved = (int)($booked_res['booked_qty'] ?? 0);
                            $avail = (int)$tool['total_stock'] - $currently_approved;

                            if ((int)$booking['quantity'] > $avail) {
                                throw new Exception("Gagal menyetujui: Stok untuk '" . htmlspecialchars($tool['name']) . "' tidak mencukupi (Sisa tersedia: {$avail} unit).");
                            }

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
        setFlashMessage('danger', $e->getMessage() ?: 'Terjadi kesalahan sistem.');
    }
    
    redirect('/admin/kelola_alat.php');
}

// Pagination configuration for Pending Bookings (1 transaksi pengajuan multi-barang = 1 entri)
$pending_limit = 10;
$pending_page = isset($_GET['pending_page']) ? max(1, (int)$_GET['pending_page']) : 1;
$pending_offset = ($pending_page - 1) * $pending_limit;

// Count distinct pending groups
$stmt_pending_count = $pdo->query("
    SELECT COUNT(DISTINCT tb.user_id, tb.booking_date, tb.start_time, tb.end_time, tb.created_at) 
    FROM tool_bookings tb 
    WHERE tb.status = 'pending'
");
$pending_total_records = (int)$stmt_pending_count->fetchColumn();
$pending_total_pages = max(1, ceil($pending_total_records / $pending_limit));

// Fetch pending groups for current page
$stmt_pending_groups = $pdo->prepare("
    SELECT tb.user_id, tb.booking_date, tb.end_date, tb.start_time, tb.end_time, tb.created_at,
           u.name as user_name, u.phone as user_phone, u.email as user_email
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    WHERE tb.status = 'pending'
    GROUP BY tb.user_id, tb.booking_date, tb.end_date, tb.start_time, tb.end_time, tb.created_at, u.name, u.phone, u.email
    ORDER BY tb.created_at DESC, tb.booking_date DESC, tb.start_time DESC
    LIMIT $pending_limit OFFSET $pending_offset
");
$stmt_pending_groups->execute();
$pending_page_groups = $stmt_pending_groups->fetchAll(PDO::FETCH_ASSOC);

$grouped_pending = [];
if (!empty($pending_page_groups)) {
    foreach ($pending_page_groups as $pg) {
        $key = $pg['user_id'] . '_' . $pg['booking_date'] . '_' . $pg['start_time'] . '_' . $pg['end_time'] . '_' . $pg['created_at'];
        $grouped_pending[$key] = [
            'user_name' => $pg['user_name'],
            'user_phone' => $pg['user_phone'] ?? '',
            'user_email' => $pg['user_email'] ?? '',
            'booking_date' => $pg['booking_date'],
            'end_date' => $pg['end_date'],
            'start_time' => $pg['start_time'],
            'end_time' => $pg['end_time'],
            'created_at' => $pg['created_at'],
            'items' => []
        ];
    }

    $created_ats = array_unique(array_column($pending_page_groups, 'created_at'));
    $placeholders = implode(',', array_fill(0, count($created_ats), '?'));
    $stmt_items = $pdo->prepare("
        SELECT tb.*, t.name as tool_name 
        FROM tool_bookings tb 
        JOIN tools t ON tb.tool_id = t.id 
        WHERE tb.status = 'pending' AND tb.created_at IN ($placeholders)
        ORDER BY tb.created_at ASC, t.name ASC
    ");
    $stmt_items->execute($created_ats);
    $all_pending_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_pending_items as $b) {
        $key = $b['user_id'] . '_' . $b['booking_date'] . '_' . $b['start_time'] . '_' . $b['end_time'] . '_' . $b['created_at'];
        if (isset($grouped_pending[$key])) {
            $grouped_pending[$key]['items'][] = $b;
        }
    }
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
        $history_filter_sql = " AND (? BETWEEN tb.booking_date AND COALESCE(tb.end_date, tb.booking_date)) ";
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
    $history_filter_sql = " AND (tb.booking_date <= ? AND COALESCE(tb.end_date, tb.booking_date) >= ?) ";
    $history_filter_params[] = $week_end;
    $history_filter_params[] = $week_start;
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
    $history_filter_sql = " AND (tb.booking_date <= ? AND COALESCE(tb.end_date, tb.booking_date) >= ?) ";
    $history_filter_params[] = $month_end;
    $history_filter_params[] = $month_start;
    $filter_label = "Bulanan (" . date('F Y', strtotime($month_start)) . ")";
}

// Handle Export XLSX
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require_once __DIR__ . '/../includes/libs/SimpleXLSXGen.php';
    $stmt_export = $pdo->prepare("
        SELECT tb.*, u.name as user_name, u.email as user_email, u.phone as user_phone, t.name as tool_name, t.kode as tool_code 
        FROM tool_bookings tb 
        JOIN users u ON tb.user_id = u.id 
        JOIN tools t ON tb.tool_id = t.id 
        WHERE tb.status != 'pending' $history_filter_sql
        ORDER BY tb.created_at DESC
    ");
    $stmt_export->execute($history_filter_params);
    $rows = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    $excelData = [
        ['<b>No</b>', '<b>Pemohon</b>', '<b>Email</b>', '<b>No. HP</b>', '<b>Waktu Pengajuan</b>', '<b>Tanggal Mulai</b>', '<b>Tanggal Selesai</b>', '<b>Waktu/Durasi</b>', '<b>Barang</b>', '<b>Jumlah</b>', '<b>Status</b>', '<b>Alasan Penolakan</b>']
    ];
    $no = 1;
    foreach ($rows as $r) {
        $end_date_str = (!empty($r['end_date']) && $r['end_date'] !== $r['booking_date']) ? date('d/m/Y', strtotime($r['end_date'])) : date('d/m/Y', strtotime($r['booking_date']));
        if ($r['start_time'] === '00:00:00' && $r['end_time'] === '23:59:59') {
            $jam_str = (!empty($r['end_date']) && $r['end_date'] !== $r['booking_date']) ? 'Rentang Waktu (24 Jam)' : 'Seharian (24 Jam)';
        } else {
            $jam_str = substr($r['start_time'], 0, 5) . ' - ' . substr($r['end_time'], 0, 5) . ' WIB';
        }
        $excelData[] = [
            $no++,
            sanitizeExcelCell($r['user_name']),
            sanitizeExcelCell($r['user_email'] ?? '-'),
            sanitizeExcelCell($r['user_phone'] ?? '-'),
            date('d/m/Y H:i', strtotime($r['created_at'])),
            date('d/m/Y', strtotime($r['booking_date'])),
            sanitizeExcelCell($end_date_str),
            $jam_str,
            sanitizeExcelCell($r['tool_name']),
            (int)$r['quantity'],
            ucfirst($r['status']),
            sanitizeExcelCell($r['rejection_reason'] ?: '-')
        ];
    }
    if (count($excelData) === 1) {
        $excelData[] = ['-', '-', '-', '-', '-', 'Tidak ada data histori peminjaman alat untuk periode ini', '-', '-', '-', '-', '-', '-'];
    }
    $filter_tag = match($filter_type) {
        'daily' => 'Harian_' . $selected_date,
        'weekly' => 'Mingguan_' . str_replace('-', '_', $selected_week),
        'monthly' => 'Bulanan_' . $selected_month,
        default => 'Semua'
    };
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excelData, 'Histori Peminjaman Alat');
    $xlsx->downloadAs('Histori_Peminjaman_Alat_' . $filter_tag . '_' . date('Ymd_His') . '.xlsx');
    exit;
}

// Pagination configuration for history
$limit = 10;
$history_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$history_offset = ($history_page - 1) * $limit;

$stmt_history_count = $pdo->prepare("
    SELECT COUNT(*) 
    FROM tool_bookings tb 
    WHERE tb.status != 'pending' $history_filter_sql
");
$stmt_history_count->execute($history_filter_params);
$history_total_records = (int)$stmt_history_count->fetchColumn();
$history_total_pages = max(1, ceil($history_total_records / $limit));

// Fetch All Bookings (History)
$stmt_history = $pdo->prepare("
    SELECT tb.*, u.name as user_name, u.phone as user_phone, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.status != 'pending' $history_filter_sql
    ORDER BY tb.created_at DESC
    LIMIT $limit OFFSET $history_offset
");
$stmt_history->execute($history_filter_params);
$history_bookings = $stmt_history->fetchAll();

if (isset($_GET['tab'])) {
    $active_tab = $_GET['tab'] === 'history' ? 'history' : 'pending';
} elseif (isset($_GET['page']) || isset($_GET['filter_type'])) {
    $active_tab = 'history';
} elseif (isset($_GET['pending_page'])) {
    $active_tab = 'pending';
} else {
    $active_tab = 'pending';
}

require_once __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-4">Kelola Pengajuan Alat Lab</h3>

<ul class="nav nav-tabs" id="myTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'pending' ? 'active' : '' ?>" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
            Menunggu Persetujuan (<?= $pending_total_records ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'history' ? 'active' : '' ?>" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
            Histori Pengajuan (<?= $history_total_records ?>)
        </button>
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
                        <th>Tanggal Pinjam</th>
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
                                        <?php 
                                            $d1 = new DateTime($group['booking_date']);
                                            $d2 = new DateTime($group['end_date']);
                                            $diff_days = $d1->diff($d2)->days + 1;
                                        ?>
                                        <br><small class="text-muted">s/d <?= htmlspecialchars($group['end_date']) ?> (<?= $diff_days ?> hari)</small>
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
                                              <?= csrfField() ?>
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
                                                            <strong>Tanggal Pinjam:</strong> <?= htmlspecialchars($group['booking_date']) ?>
                                                            <?php if (!empty($group['end_date']) && $group['end_date'] !== $group['booking_date']): ?>
                                                                <?php 
                                                                    $d1 = new DateTime($group['booking_date']);
                                                                    $d2 = new DateTime($group['end_date']);
                                                                    $diff_days = $d1->diff($d2)->days + 1;
                                                                ?>
                                                                s/d <?= htmlspecialchars($group['end_date']) ?> (<?= $diff_days ?> hari)
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

            <!-- Pagination Menunggu Persetujuan -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <?php 
                    $start_record = ($pending_total_records > 0) ? $pending_offset + 1 : 0;
                    $end_record = min($pending_offset + $pending_limit, $pending_total_records);
                ?>
                <div class="text-muted small">
                    Menampilkan <?= $start_record ?> sampai <?= $end_record ?> dari <?= $pending_total_records ?> entri
                </div>
                
                <?php if ($pending_total_pages > 1): ?>
                    <?php renderPagination($pending_page, $pending_total_pages, ['tab' => 'pending'], 'pending_page'); ?>
                <?php endif; ?>
            </div>
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
                        <th>Tanggal Pinjam</th>
                        <th>Alat</th>
                        <th>Jumlah</th>
                        <th>Waktu</th>
                        <th>Status</th>
                        <th>Alasan Penolakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($history_bookings) === 0): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada histori<?= $filter_type !== 'all' ? ' untuk periode (' . htmlspecialchars($filter_label) . ')' : '' ?>.</td></tr>
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
                                        <?php 
                                            $d1 = new DateTime($b['booking_date']);
                                            $d2 = new DateTime($b['end_date']);
                                            $diff_days = $d1->diff($d2)->days + 1;
                                        ?>
                                        <br><small class="text-muted">s/d <?= htmlspecialchars($b['end_date']) ?> (<?= $diff_days ?> hari)</small>
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

            <!-- Pagination Histori -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <?php 
                    $start_record = ($history_total_records > 0) ? $history_offset + 1 : 0;
                    $end_record = min($history_offset + $limit, $history_total_records);
                ?>
                <div class="text-muted small">
                    Menampilkan <?= $start_record ?> sampai <?= $end_record ?> dari <?= $history_total_records ?> entri
                </div>
                
                <?php if ($history_total_pages > 1): ?>
                    <?php renderPagination($history_page, $history_total_pages, ['tab' => 'history', 'filter_type' => $filter_type, 'date' => $selected_date, 'week' => $selected_week, 'month' => $selected_month]); ?>
                <?php endif; ?>
            </div>
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
