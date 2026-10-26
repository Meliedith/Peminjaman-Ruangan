<?php
// admin/pengembalian_alat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete') {
    if (!validateCsrfToken()) {
        setFlashMessage('danger', 'Sesi keamanan tidak valid (CSRF token mismatch). Silakan muat ulang halaman.');
        redirect('/admin/pengembalian_alat.php');
    }

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

// Pagination for Active Bookings (Sedang Dipinjam)
$active_limit = 10;
$active_page = isset($_GET['active_page']) ? max(1, (int)$_GET['active_page']) : 1;
$active_offset = ($active_page - 1) * $active_limit;

$stmt_active_count = $pdo->query("SELECT COUNT(*) FROM tool_bookings WHERE status = 'approved'");
$total_active_records = (int)$stmt_active_count->fetchColumn();
$total_active_pages = max(1, ceil($total_active_records / $active_limit));

$stmt = $pdo->prepare("
    SELECT tb.*, u.name as user_name, u.phone as user_phone, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.status = 'approved'
    ORDER BY tb.booking_date ASC, tb.start_time ASC
    LIMIT $active_limit OFFSET $active_offset
");
$stmt->execute();
$active_bookings = $stmt->fetchAll();

// Filter configuration for return history
$filter_type = $_GET['filter_type'] ?? 'all';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_week = $_GET['week'] ?? date('Y-\WW');
$selected_month = $_GET['month'] ?? date('Y-m');

$history_filter_sql = "";
$history_filter_params = [];
$filter_label = "Keseluruhan";

if ($filter_type === 'daily') {
    if (!empty($selected_date)) {
        $history_filter_sql = " AND (DATE(tb.updated_at) = ? OR (? BETWEEN tb.booking_date AND COALESCE(tb.end_date, tb.booking_date))) ";
        $history_filter_params[] = $selected_date;
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
    $history_filter_sql = " AND ((DATE(tb.updated_at) BETWEEN ? AND ?) OR (tb.booking_date <= ? AND COALESCE(tb.end_date, tb.booking_date) >= ?)) ";
    $history_filter_params[] = $week_start;
    $history_filter_params[] = $week_end;
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
    $history_filter_sql = " AND ((DATE(tb.updated_at) BETWEEN ? AND ?) OR (tb.booking_date <= ? AND COALESCE(tb.end_date, tb.booking_date) >= ?)) ";
    $history_filter_params[] = $month_start;
    $history_filter_params[] = $month_end;
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
        WHERE tb.status = 'completed' $history_filter_sql
        ORDER BY tb.updated_at DESC
    ");
    $stmt_export->execute($history_filter_params);
    $rows = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    $excelData = [
        ['<b>No</b>', '<b>Pemohon</b>', '<b>Email</b>', '<b>No. HP</b>', '<b>Alat Lab</b>', '<b>Jumlah</b>', '<b>Tanggal Pinjam</b>', '<b>Waktu Pinjam</b>', '<b>Waktu Pengembalian</b>', '<b>Status</b>']
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
            sanitizeExcelCell($r['tool_name']),
            (int)$r['quantity'],
            date('d/m/Y', strtotime($r['booking_date'])) . (($r['booking_date'] !== $r['end_date'] && !empty($r['end_date'])) ? ' s/d ' . sanitizeExcelCell($end_date_str) : ''),
            $jam_str,
            date('d/m/Y H:i', strtotime($r['updated_at'])),
            'Dikembalikan'
        ];
    }
    if (count($excelData) === 1) {
        $excelData[] = ['-', '-', '-', '-', 'Tidak ada data histori pengembalian alat untuk periode ini', '-', '-', '-', '-', '-'];
    }
    $filter_tag = match($filter_type) {
        'daily' => 'Harian_' . $selected_date,
        'weekly' => 'Mingguan_' . str_replace('-', '_', $selected_week),
        'monthly' => 'Bulanan_' . $selected_month,
        default => 'Semua'
    };
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excelData, 'Histori Pengembalian Alat');
    $xlsx->downloadAs('Histori_Pengembalian_Alat_' . $filter_tag . '_' . date('Ymd_His') . '.xlsx');
    exit;
}

// Pagination for history
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Fetch history count
$stmt_history_count = $pdo->prepare("
    SELECT COUNT(*) 
    FROM tool_bookings tb 
    WHERE tb.status = 'completed' $history_filter_sql
");
$stmt_history_count->execute($history_filter_params);
$total_history_records = (int)$stmt_history_count->fetchColumn();
$total_history_pages = max(1, ceil($total_history_records / $limit));

// Fetch history records
$stmt_history = $pdo->prepare("
    SELECT tb.*, u.name as user_name, u.phone as user_phone, t.name as tool_name 
    FROM tool_bookings tb 
    JOIN users u ON tb.user_id = u.id 
    JOIN tools t ON tb.tool_id = t.id 
    WHERE tb.status = 'completed' $history_filter_sql
    ORDER BY tb.updated_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt_history->execute($history_filter_params);
$history_bookings = $stmt_history->fetchAll();

if (isset($_GET['tab'])) {
    $active_tab = $_GET['tab'] === 'history' ? 'history' : 'active';
} elseif (isset($_GET['page']) || isset($_GET['filter_type'])) {
    $active_tab = 'history';
} elseif (isset($_GET['active_page'])) {
    $active_tab = 'active';
} else {
    $active_tab = 'active';
}

require_once __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-4">Pengembalian Alat Lab</h3>

<ul class="nav nav-tabs" id="myTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'active' ? 'active' : '' ?>" id="active-tab" data-bs-toggle="tab" data-bs-target="#active" type="button" role="tab">
            Sedang Dipinjam (<?= $total_active_records ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'history' ? 'active' : '' ?>" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
            Histori Pengembalian (<?= $total_history_records ?>)
        </button>
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
                        <tr><td colspan="6" class="text-center py-4 text-muted">Tidak ada alat yang sedang dipinjam (status approved).</td></tr>
                    <?php else: ?>
                        <?php foreach($active_bookings as $b): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($b['user_name']) ?></strong>
                                    <?php if (!empty($b['user_phone'])): ?>
                                        <?php 
                                            $wa_phone = preg_replace('/[^0-9]/', '', $b['user_phone']);
                                            if (str_starts_with($wa_phone, '0')) {
                                                $wa_phone = '62' . substr($wa_phone, 1);
                                            }
                                        ?>
                                        <div class="mt-1">
                                            <a href="https://wa.me/<?= $wa_phone ?>" target="_blank" class="badge bg-success text-decoration-none" title="Hubungi via WhatsApp">
                                                📞 <?= htmlspecialchars($b['user_phone']) ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($b['tool_name']) ?></td>
                                <td><?= htmlspecialchars($b['quantity']) ?> unit</td>
                                <td>
                                    <?= htmlspecialchars(date('d/m/Y', strtotime($b['booking_date']))) ?>
                                    <?php if (!empty($b['end_date']) && $b['end_date'] !== $b['booking_date']): ?>
                                        <?php 
                                            $d1 = new DateTime($b['booking_date']);
                                            $d2 = new DateTime($b['end_date']);
                                            $diff_days = $d1->diff($d2)->days + 1;
                                        ?>
                                        <br><small class="text-muted">s/d <?= htmlspecialchars(date('d/m/Y', strtotime($b['end_date']))) ?> (<?= $diff_days ?> hari)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($b['start_time'] === '00:00:00' && $b['end_time'] === '23:59:59'): ?>
                                        <span class="text-muted">Seharian</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars(substr($b['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($b['end_time'], 0, 5)) ?> WIB
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('Tandai alat ini sudah selesai / dikembalikan?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-info text-white">Selesai / Kembalikan</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination Sedang Dipinjam -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <?php 
                    $start_record = ($total_active_records > 0) ? $active_offset + 1 : 0;
                    $end_record = min($active_offset + $active_limit, $total_active_records);
                ?>
                <div class="text-muted small">
                    Menampilkan <?= $start_record ?> sampai <?= $end_record ?> dari <?= $total_active_records ?> entri
                </div>
                
                <?php if ($total_active_pages > 1): ?>
                    <?php renderPagination($active_page, $total_active_pages, ['tab' => 'active'], 'active_page'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Histori Pengembalian -->
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
                        <th>Alat</th>
                        <th>Jumlah</th>
                        <th>Tanggal Pinjam</th>
                        <th>Waktu Pengembalian</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($history_bookings) === 0): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">Belum ada histori pengembalian alat untuk periode ini.</td></tr>
                    <?php else: ?>
                        <?php foreach($history_bookings as $h): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($h['user_name']) ?></strong>
                                    <?php if (!empty($h['user_phone'])): ?>
                                        <?php 
                                            $wa_phone = preg_replace('/[^0-9]/', '', $h['user_phone']);
                                            if (str_starts_with($wa_phone, '0')) {
                                                $wa_phone = '62' . substr($wa_phone, 1);
                                            }
                                        ?>
                                        <div class="mt-1">
                                            <a href="https://wa.me/<?= $wa_phone ?>" target="_blank" class="badge bg-success text-decoration-none" title="Hubungi via WhatsApp">
                                                📞 <?= htmlspecialchars($h['user_phone']) ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($h['tool_name']) ?></td>
                                <td><?= htmlspecialchars($h['quantity']) ?> unit</td>
                                <td>
                                    <?= htmlspecialchars(date('d/m/Y', strtotime($h['booking_date']))) ?>
                                    <?php if (!empty($h['end_date']) && $h['end_date'] !== $h['booking_date']): ?>
                                        <?php 
                                            $d1 = new DateTime($h['booking_date']);
                                            $d2 = new DateTime($h['end_date']);
                                            $diff_days = $d1->diff($d2)->days + 1;
                                        ?>
                                        <br><small class="text-muted">s/d <?= htmlspecialchars(date('d/m/Y', strtotime($h['end_date']))) ?> (<?= $diff_days ?> hari)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($h['updated_at'])) ?> WIB
                                </td>
                                <td><span class="badge bg-success">Dikembalikan</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination Histori Pengembalian -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <?php 
                    $start_record = ($total_history_records > 0) ? $offset + 1 : 0;
                    $end_record = min($offset + $limit, $total_history_records);
                ?>
                <div class="text-muted small">
                    Menampilkan <?= $start_record ?> sampai <?= $end_record ?> dari <?= $total_history_records ?> entri
                </div>
                
                <?php if ($total_history_pages > 1): ?>
                    <?php renderPagination($page, $total_history_pages, ['tab' => 'history', 'filter_type' => $filter_type, 'date' => $selected_date, 'week' => $selected_week, 'month' => $selected_month], 'page'); ?>
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
