<?php
// user/riwayat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('user');
$user_id = $_SESSION['user_id'];

// Filter configuration
$filter_type = $_GET['filter_type'] ?? 'all';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_week = $_GET['week'] ?? date('Y-\WW');
$selected_month = $_GET['month'] ?? date('Y-m');

$room_filter_sql = "";
$room_filter_params = [$user_id];

$tool_filter_sql = "";
$tool_filter_params = [$user_id];

$filter_label = "Keseluruhan";

if ($filter_type === 'daily') {
    if (!empty($selected_date)) {
        $room_filter_sql = " AND rb.booking_date = ? ";
        $room_filter_params[] = $selected_date;

        $tool_filter_sql = " AND (? BETWEEN tb.booking_date AND COALESCE(tb.end_date, tb.booking_date)) ";
        $tool_filter_params[] = $selected_date;

        $filter_label = "Harian (" . date('d M Y', strtotime($selected_date)) . ")";
    }
} elseif ($filter_type === 'weekly') {
    if (preg_match('/^(\d{4})-W(\d{2})$/', $selected_week, $matches)) {
        $year = (int)$matches[1];
        $week = (int)$matches[2];
        $dto = new DateTime();
        $dto->setISODate($year, $week, 1);
        $week_start = $dto->format('Y-m-d');
        $dto->setISODate($year, $week, 7);
        $week_end = $dto->format('Y-m-d');
    } else {
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
    }

    $room_filter_sql = " AND rb.booking_date BETWEEN ? AND ? ";
    $room_filter_params[] = $week_start;
    $room_filter_params[] = $week_end;

    $tool_filter_sql = " AND (tb.booking_date <= ? AND COALESCE(tb.end_date, tb.booking_date) >= ?) ";
    $tool_filter_params[] = $week_end;
    $tool_filter_params[] = $week_start;

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

    $room_filter_sql = " AND rb.booking_date BETWEEN ? AND ? ";
    $room_filter_params[] = $month_start;
    $room_filter_params[] = $month_end;

    $tool_filter_sql = " AND (tb.booking_date <= ? AND COALESCE(tb.end_date, tb.booking_date) >= ?) ";
    $tool_filter_params[] = $month_end;
    $tool_filter_params[] = $month_start;

    $filter_label = "Bulanan (" . date('F Y', strtotime($month_start)) . ")";
} else {
    $filter_type = 'all';
    $filter_label = "Keseluruhan";
}

// Determine Active Tab
$active_tab = $_GET['tab'] ?? (isset($_GET['tool_page']) && !isset($_GET['room_page']) ? 'tool' : 'room');
if (!in_array($active_tab, ['room', 'tool'])) {
    $active_tab = 'room';
}

// Handle Export to XLSX
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require_once __DIR__ . '/../includes/libs/SimpleXLSXGen.php';
    
    $export_tab = $_GET['tab'] ?? 'room';
    
    // Build Room Data if needed
    $excel_room = [];
    if ($export_tab === 'room' || $export_tab === 'all') {
        $stmt_export_room = $pdo->prepare("
            SELECT rb.*, t.name as table_name, u.name as user_name
            FROM room_bookings rb 
            JOIN tables t ON rb.table_id = t.id 
            JOIN users u ON rb.user_id = u.id
            WHERE rb.user_id = ? $room_filter_sql
            ORDER BY rb.created_at DESC, CAST(SUBSTRING(t.name, 5) AS UNSIGNED) ASC
        ");
        $stmt_export_room->execute($room_filter_params);
        $room_rows = $stmt_export_room->fetchAll(PDO::FETCH_ASSOC);

        $excel_room[] = ['<b>No</b>', '<b>Waktu Pengajuan</b>', '<b>Tanggal Peminjaman</b>', '<b>Jam Mulai</b>', '<b>Jam Selesai</b>', '<b>Meja / Ruangan</b>', '<b>Status</b>', '<b>Alasan Penolakan</b>'];
        $no = 1;
        foreach ($room_rows as $row) {
            $status_label = match($row['status']) {
                'pending' => 'Menunggu Persetujuan',
                'approved' => 'Disetujui',
                'rejected' => 'Ditolak',
                'cancelled' => 'Dibatalkan',
                'expired' => 'Kedaluwarsa',
                default => ucfirst($row['status'])
            };
            $excel_room[] = [
                $no++,
                date('d/m/Y H:i', strtotime($row['created_at'])),
                date('d/m/Y', strtotime($row['booking_date'])),
                substr($row['start_time'], 0, 5) . ' WIB',
                substr($row['end_time'], 0, 5) . ' WIB',
                $row['table_name'],
                $status_label,
                $row['rejection_reason'] ?: '-'
            ];
        }
        if (count($excel_room) === 1) {
            $excel_room[] = ['-', '-', 'Tidak ada data peminjaman meja/ruangan untuk periode ini', '-', '-', '-', '-', '-'];
        }
    }

    // Build Tool Data if needed
    $excel_tool = [];
    if ($export_tab === 'tool' || $export_tab === 'all') {
        $stmt_export_tool = $pdo->prepare("
            SELECT tb.*, t.name as tool_name, u.name as user_name
            FROM tool_bookings tb 
            JOIN tools t ON tb.tool_id = t.id 
            JOIN users u ON tb.user_id = u.id
            WHERE tb.user_id = ? $tool_filter_sql
            ORDER BY tb.created_at DESC, t.name ASC
        ");
        $stmt_export_tool->execute($tool_filter_params);
        $tool_rows = $stmt_export_tool->fetchAll(PDO::FETCH_ASSOC);

        $excel_tool[] = ['<b>No</b>', '<b>Waktu Pengajuan</b>', '<b>Tanggal Mulai</b>', '<b>Tanggal Selesai</b>', '<b>Durasi / Jam</b>', '<b>Nama Alat</b>', '<b>Jumlah Unit</b>', '<b>Status</b>', '<b>Alasan Penolakan</b>'];
        $no = 1;
        foreach ($tool_rows as $row) {
            $status_label = match($row['status']) {
                'pending' => 'Menunggu Persetujuan',
                'approved' => 'Disetujui',
                'rejected' => 'Ditolak',
                'completed' => 'Selesai / Kembali',
                'cancelled' => 'Dibatalkan',
                'expired' => 'Kedaluwarsa',
                'no_show' => 'Tidak Diambil',
                default => ucfirst($row['status'])
            };

            $end_date_str = (!empty($row['end_date']) && $row['end_date'] !== $row['booking_date']) 
                ? date('d/m/Y', strtotime($row['end_date'])) 
                : date('d/m/Y', strtotime($row['booking_date']));

            if ($row['start_time'] === '00:00:00' && $row['end_time'] === '23:59:59') {
                $jam_str = (!empty($row['end_date']) && $row['end_date'] !== $row['booking_date']) ? 'Rentang Waktu (24 Jam)' : 'Seharian (24 Jam)';
            } else {
                $jam_str = substr($row['start_time'], 0, 5) . ' - ' . substr($row['end_time'], 0, 5) . ' WIB';
            }

            $excel_tool[] = [
                $no++,
                date('d/m/Y H:i', strtotime($row['created_at'])),
                date('d/m/Y', strtotime($row['booking_date'])),
                $end_date_str,
                $jam_str,
                $row['tool_name'],
                (int)$row['quantity'],
                $status_label,
                $row['rejection_reason'] ?: '-'
            ];
        }
        if (count($excel_tool) === 1) {
            $excel_tool[] = ['-', '-', 'Tidak ada data peminjaman alat lab untuk periode ini', '-', '-', '-', '-', '-', '-'];
        }
    }

    $filter_tag = match($filter_type) {
        'daily' => 'Harian_' . $selected_date,
        'weekly' => 'Mingguan_' . str_replace('-', '_', $selected_week),
        'monthly' => 'Bulanan_' . $selected_month,
        default => 'Semua'
    };

    if ($export_tab === 'tool') {
        $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excel_tool, 'Peminjaman Alat');
        $filename = 'Riwayat_Peminjaman_Alat_' . $filter_tag . '_' . date('Ymd_His') . '.xlsx';
    } elseif ($export_tab === 'all') {
        $xlsx = new Shuchkin\SimpleXLSXGen();
        $xlsx->addSheet($excel_room, 'Ruangan & Meja');
        $xlsx->addSheet($excel_tool, 'Alat Lab');
        $filename = 'Riwayat_Peminjaman_' . $filter_tag . '_' . date('Ymd_His') . '.xlsx';
    } else {
        $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excel_room, 'Ruangan & Meja');
        $filename = 'Riwayat_Peminjaman_Ruangan_' . $filter_tag . '_' . date('Ymd_His') . '.xlsx';
    }

    $xlsx->downloadAs($filename);
    exit;
}

// Handle Cancel Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $type = $_POST['type'] ?? '';
    $id = $_POST['id'] ?? '';
    
    if ($type === 'room_group') {
        $booking_date = $_POST['booking_date'] ?? '';
        $created_at = $_POST['created_at'] ?? '';
        $stmt = $pdo->prepare("UPDATE room_bookings SET status = 'cancelled' WHERE user_id = ? AND booking_date = ? AND created_at = ? AND status = 'pending'");
        if ($stmt->execute([$user_id, $booking_date, $created_at]) && $stmt->rowCount() > 0) {
            setFlashMessage('success', 'Pengajuan ruangan/meja berhasil dibatalkan.');
        } else {
            setFlashMessage('danger', 'Gagal membatalkan. Status mungkin sudah berubah.');
        }
    } elseif ($type === 'room') {
        $stmt = $pdo->prepare("UPDATE room_bookings SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
        if ($stmt->execute([$id, $user_id]) && $stmt->rowCount() > 0) {
            setFlashMessage('success', 'Pengajuan ruangan berhasil dibatalkan.');
        } else {
            setFlashMessage('danger', 'Gagal membatalkan. Status mungkin sudah berubah.');
        }
    } elseif ($type === 'tool_group') {
        $booking_date = $_POST['booking_date'] ?? '';
        $created_at = $_POST['created_at'] ?? '';
        $stmt = $pdo->prepare("UPDATE tool_bookings SET status = 'cancelled' WHERE user_id = ? AND booking_date = ? AND created_at = ? AND status = 'pending'");
        if ($stmt->execute([$user_id, $booking_date, $created_at]) && $stmt->rowCount() > 0) {
            setFlashMessage('success', 'Pengajuan alat berhasil dibatalkan.');
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
    
    $redirect_params = [
        'tab' => $_POST['tab'] ?? $active_tab,
        'filter_type' => $_POST['filter_type'] ?? $filter_type,
        'date' => $_POST['date'] ?? $selected_date,
        'week' => $_POST['week'] ?? $selected_week,
        'month' => $_POST['month'] ?? $selected_month,
        'room_page' => (int)($_GET['room_page'] ?? 1),
        'tool_page' => (int)($_GET['tool_page'] ?? 1)
    ];
    redirect('/user/riwayat.php?' . http_build_query(array_filter($redirect_params, fn($v) => $v !== '')));
}

// Pagination configuration
$limit = 10;
$room_page = isset($_GET['room_page']) ? max(1, (int)$_GET['room_page']) : 1;
$room_offset = ($room_page - 1) * $limit;

$tool_page = isset($_GET['tool_page']) ? max(1, (int)$_GET['tool_page']) : 1;
$tool_offset = ($tool_page - 1) * $limit;

// Fetch Room Bookings Group Count (1 pengajuan multi-meja = 1x transaksi)
$stmt_room_count = $pdo->prepare("
    SELECT COUNT(DISTINCT rb.booking_date, rb.start_time, rb.end_time, rb.created_at) 
    FROM room_bookings rb 
    WHERE rb.user_id = ? $room_filter_sql
");
$stmt_room_count->execute($room_filter_params);
$room_total_records = (int)$stmt_room_count->fetchColumn();
$room_total_pages = max(1, ceil($room_total_records / $limit));

// Fetch Room Booking Groups for current page
$stmt_room_groups = $pdo->prepare("
    SELECT rb.booking_date, rb.start_time, rb.end_time, rb.created_at 
    FROM room_bookings rb 
    WHERE rb.user_id = ? $room_filter_sql 
    GROUP BY rb.booking_date, rb.start_time, rb.end_time, rb.created_at 
    ORDER BY rb.created_at DESC 
    LIMIT $limit OFFSET $room_offset
");
$stmt_room_groups->execute($room_filter_params);
$room_page_groups = $stmt_room_groups->fetchAll(PDO::FETCH_ASSOC);

$grouped_rooms = [];
if (!empty($room_page_groups)) {
    $created_ats = array_column($room_page_groups, 'created_at');
    $placeholders = implode(',', array_fill(0, count($created_ats), '?'));

    $stmt_items = $pdo->prepare("
        SELECT rb.*, t.name as table_name 
        FROM room_bookings rb 
        JOIN tables t ON rb.table_id = t.id 
        WHERE rb.user_id = ? AND rb.created_at IN ($placeholders)
        ORDER BY rb.created_at DESC, CAST(SUBSTRING(t.name, 5) AS UNSIGNED) ASC
    ");
    $stmt_items->execute(array_merge([$user_id], $created_ats));
    $all_room_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    foreach ($room_page_groups as $pg) {
        $key = $pg['booking_date'] . '_' . $pg['start_time'] . '_' . $pg['end_time'] . '_' . $pg['created_at'];
        $grouped_rooms[$key] = [
            'booking_date' => $pg['booking_date'],
            'start_time' => $pg['start_time'],
            'end_time' => $pg['end_time'],
            'created_at' => $pg['created_at'],
            'items' => []
        ];
    }

    foreach ($all_room_items as $item) {
        $key = $item['booking_date'] . '_' . $item['start_time'] . '_' . $item['end_time'] . '_' . $item['created_at'];
        if (isset($grouped_rooms[$key])) {
            $grouped_rooms[$key]['items'][] = $item;
        }
    }
}

// Fetch Tool Bookings Group Count (1 pengajuan multi-barang = 1x transaksi)
$stmt_tool_count = $pdo->prepare("
    SELECT COUNT(DISTINCT tb.booking_date, tb.start_time, tb.end_time, tb.created_at) 
    FROM tool_bookings tb 
    WHERE tb.user_id = ? $tool_filter_sql
");
$stmt_tool_count->execute($tool_filter_params);
$tool_total_records = (int)$stmt_tool_count->fetchColumn();
$tool_total_pages = max(1, ceil($tool_total_records / $limit));

// Fetch Tool Booking Groups for current page
$stmt_tool_groups = $pdo->prepare("
    SELECT tb.booking_date, tb.end_date, tb.start_time, tb.end_time, tb.created_at 
    FROM tool_bookings tb 
    WHERE tb.user_id = ? $tool_filter_sql 
    GROUP BY tb.booking_date, tb.end_date, tb.start_time, tb.end_time, tb.created_at 
    ORDER BY tb.created_at DESC 
    LIMIT $limit OFFSET $tool_offset
");
$stmt_tool_groups->execute($tool_filter_params);
$page_groups = $stmt_tool_groups->fetchAll(PDO::FETCH_ASSOC);

$grouped_tools = [];
if (!empty($page_groups)) {
    $created_ats = array_column($page_groups, 'created_at');
    $placeholders = implode(',', array_fill(0, count($created_ats), '?'));
    
    $stmt_items = $pdo->prepare("
        SELECT tb.*, t.name as tool_name 
        FROM tool_bookings tb 
        JOIN tools t ON tb.tool_id = t.id 
        WHERE tb.user_id = ? AND tb.created_at IN ($placeholders)
        ORDER BY tb.created_at DESC, t.name ASC
    ");
    $stmt_items->execute(array_merge([$user_id], $created_ats));
    $all_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($page_groups as $pg) {
        $key = $pg['booking_date'] . '_' . $pg['start_time'] . '_' . $pg['end_time'] . '_' . $pg['created_at'];
        $grouped_tools[$key] = [
            'booking_date' => $pg['booking_date'],
            'end_date' => $pg['end_date'],
            'start_time' => $pg['start_time'],
            'end_time' => $pg['end_time'],
            'created_at' => $pg['created_at'],
            'items' => []
        ];
    }
    
    foreach ($all_items as $item) {
        $key = $item['booking_date'] . '_' . $item['start_time'] . '_' . $item['end_time'] . '_' . $item['created_at'];
        if (isset($grouped_tools[$key])) {
            $grouped_tools[$key]['items'][] = $item;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h3 class="mb-1">Riwayat & Status Pengajuan Saya</h3>
                <div class="text-muted small">
                    <i class="bi bi-funnel-fill text-primary me-1"></i> Periode Terpilih: 
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><?= htmlspecialchars($filter_label) ?></span>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card shadow-sm border-0 mb-4 bg-light">
            <div class="card-body p-3">
                <form method="GET" action="" id="filterForm" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" id="activeTabInput" value="<?= htmlspecialchars($active_tab) ?>">
                    
                    <div class="col-12 col-md-auto">
                        <label class="form-label mb-1 small fw-semibold text-muted d-block">Pilihan Riwayat:</label>
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <input type="radio" class="btn-check" name="filter_type" id="filter_all" value="all" <?= $filter_type === 'all' ? 'checked' : '' ?> onchange="updateFilterUI()">
                            <label class="btn btn-outline-primary" for="filter_all">Keseluruhan</label>

                            <input type="radio" class="btn-check" name="filter_type" id="filter_daily" value="daily" <?= $filter_type === 'daily' ? 'checked' : '' ?> onchange="updateFilterUI()">
                            <label class="btn btn-outline-primary" for="filter_daily">Harian</label>

                            <input type="radio" class="btn-check" name="filter_type" id="filter_weekly" value="weekly" <?= $filter_type === 'weekly' ? 'checked' : '' ?> onchange="updateFilterUI()">
                            <label class="btn btn-outline-primary" for="filter_weekly">Mingguan</label>

                            <input type="radio" class="btn-check" name="filter_type" id="filter_monthly" value="monthly" <?= $filter_type === 'monthly' ? 'checked' : '' ?> onchange="updateFilterUI()">
                            <label class="btn btn-outline-primary" for="filter_monthly">Bulanan</label>
                        </div>
                    </div>

                    <!-- Input Date (Harian) -->
                    <div class="col-12 col-md-auto filter-input-box" id="box_daily" style="<?= $filter_type === 'daily' ? '' : 'display:none;' ?>">
                        <label class="form-label mb-1 small fw-semibold text-muted d-block">Pilih Hari / Tanggal:</label>
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
                        <a href="?tab=<?= htmlspecialchars($active_tab) ?>" class="btn btn-sm btn-outline-secondary" title="Reset Filter ke Keseluruhan">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </a>
                    </div>

                    <!-- Export Buttons (Aligned Right) -->
                    <div class="col-12 col-md d-flex justify-content-md-end align-items-end mt-2 mt-md-4">
                        <div class="btn-group btn-group-sm">
                            <a href="?export=xlsx&tab=<?= htmlspecialchars($active_tab) ?>&filter_type=<?= $filter_type ?>&date=<?= urlencode($selected_date) ?>&week=<?= urlencode($selected_week) ?>&month=<?= urlencode($selected_month) ?>" 
                               id="btnDownloadCurrent" 
                               class="btn btn-success d-inline-flex align-items-center gap-1 shadow-sm">
                                <i class="bi bi-file-earmark-excel-fill"></i>
                                <span id="labelDownloadCurrent">Download XLSX (<?= $active_tab === 'tool' ? 'Alat Lab' : 'Ruangan/Meja' ?>)</span>
                            </a>
                            <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="visually-hidden">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><h6 class="dropdown-header">Pilihan Ekspor Excel (.xlsx)</h6></li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2" href="?export=xlsx&tab=room&filter_type=<?= $filter_type ?>&date=<?= urlencode($selected_date) ?>&week=<?= urlencode($selected_week) ?>&month=<?= urlencode($selected_month) ?>">
                                        <i class="bi bi-door-open text-primary"></i> Riwayat Ruangan & Meja (.xlsx)
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2" href="?export=xlsx&tab=tool&filter_type=<?= $filter_type ?>&date=<?= urlencode($selected_date) ?>&week=<?= urlencode($selected_week) ?>&month=<?= urlencode($selected_month) ?>">
                                        <i class="bi bi-tools text-warning"></i> Riwayat Alat Lab (.xlsx)
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2 fw-semibold" href="?export=xlsx&tab=all&filter_type=<?= $filter_type ?>&date=<?= urlencode($selected_date) ?>&week=<?= urlencode($selected_week) ?>&month=<?= urlencode($selected_month) ?>">
                                        <i class="bi bi-collection text-success"></i> Download Semua (2 Sheet dalam 1 File)
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <ul class="nav nav-tabs" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'room' ? 'active' : '' ?>" id="room-tab" data-bs-toggle="tab" data-bs-target="#room" type="button" role="tab">
                    <i class="bi bi-door-open me-1"></i> Ruangan & Meja (<?= $room_total_records ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'tool' ? 'active' : '' ?>" id="tool-tab" data-bs-toggle="tab" data-bs-target="#tool" type="button" role="tab">
                    <i class="bi bi-tools me-1"></i> Alat Lab (<?= $tool_total_records ?>)
                </button>
            </li>
        </ul>
        
        <div class="tab-content mt-3" id="myTabContent">
            <!-- Room Bookings -->
            <div class="tab-pane fade <?= $active_tab === 'room' ? 'show active' : '' ?>" id="room" role="tabpanel">
                <div class="table-responsive bg-white shadow-sm rounded p-3">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 140px;">Waktu Pengajuan</th>
                                <th style="width: 180px;">Jadwal Peminjaman</th>
                                <th>Rincian Meja & Status ACC</th>
                                <th style="width: 160px;" class="text-center">Status Transaksi</th>
                                <th style="width: 90px;" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($grouped_rooms) === 0): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada pengajuan ruangan/meja<?= $filter_type !== 'all' ? ' pada periode ini (' . htmlspecialchars($filter_label) . ')' : '' ?>.</td></tr>
                            <?php else: ?>
                                <?php foreach($grouped_rooms as $group): ?>
                                    <?php 
                                        $has_pending = false;
                                        $statuses = [];
                                        foreach($group['items'] as $it) {
                                            $statuses[] = $it['status'];
                                            if ($it['status'] === 'pending') $has_pending = true;
                                        }
                                        $unique_statuses = array_unique($statuses);
                                        
                                        // Summary status badge
                                        if (count($unique_statuses) === 1) {
                                            $single_status = $unique_statuses[0];
                                            if ($single_status === 'pending') {
                                                $overall_badge = '<span class="status-pending">Menunggu Persetujuan</span>';
                                            } elseif ($single_status === 'approved') {
                                                $overall_badge = '<span class="status-approved">Disetujui Semua</span>';
                                            } elseif ($single_status === 'rejected') {
                                                $overall_badge = '<span class="status-rejected">Ditolak Semua</span>';
                                            } elseif ($single_status === 'cancelled') {
                                                $overall_badge = '<span class="badge bg-secondary">Dibatalkan</span>';
                                            } else {
                                                $overall_badge = '<span class="badge bg-dark">' . ucfirst($single_status) . '</span>';
                                            }
                                        } else {
                                            if (in_array('approved', $unique_statuses) && in_array('rejected', $unique_statuses) && !in_array('pending', $unique_statuses)) {
                                                $overall_badge = '<span class="badge bg-primary">Disetujui Sebagian</span>';
                                            } elseif (in_array('pending', $unique_statuses)) {
                                                $overall_badge = '<span class="status-pending">Menunggu Review</span>';
                                            } else {
                                                $overall_badge = '<span class="badge bg-info text-dark">Campuran</span>';
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= date('d M Y', strtotime($group['created_at'])) ?></strong>
                                            <div class="text-muted small"><?= date('H:i', strtotime($group['created_at'])) ?> WIB</div>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($group['booking_date']) ?></strong>
                                            </div>
                                            <div class="small mt-1">
                                                <span class="text-secondary"><?= htmlspecialchars(substr($group['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($group['end_time'], 0, 5)) ?> WIB</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="bg-light p-2 rounded border">
                                                <table class="table table-sm table-borderless mb-0">
                                                    <tbody>
                                                        <?php foreach($group['items'] as $item): ?>
                                                         <tr class="border-bottom border-light">
                                                            <td class="py-1">
                                                                <strong><?= htmlspecialchars($item['table_name']) ?></strong>
                                                                <?php if(!empty($item['rejection_reason'])): ?>
                                                                    <div class="text-danger small mt-1">
                                                                        <em>Alasan Ditolak: <?= htmlspecialchars($item['rejection_reason']) ?></em>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end py-1" style="width: 140px;">
                                                                <span class="status-<?= htmlspecialchars($item['status']) ?>">
                                                                    <?= ucfirst(htmlspecialchars($item['status'])) ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?= $overall_badge ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if($has_pending): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Yakin ingin membatalkan pengajuan ruangan ini?');">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="type" value="room_group">
                                                <input type="hidden" name="tab" value="room">
                                                <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type) ?>">
                                                <input type="hidden" name="date" value="<?= htmlspecialchars($selected_date) ?>">
                                                <input type="hidden" name="week" value="<?= htmlspecialchars($selected_week) ?>">
                                                <input type="hidden" name="month" value="<?= htmlspecialchars($selected_month) ?>">
                                                <input type="hidden" name="booking_date" value="<?= htmlspecialchars($group['booking_date']) ?>">
                                                <input type="hidden" name="created_at" value="<?= htmlspecialchars($group['created_at']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Batalkan</button>
                                            </form>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($room_total_pages > 1): ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <span class="text-muted small">Menampilkan <?= count($grouped_rooms) > 0 ? $room_offset + 1 : 0 ?> sampai <?= $room_offset + count($grouped_rooms) ?> dari <?= $room_total_records ?> transaksi</span>
                        <?php renderPagination($room_page, $room_total_pages, ['tab' => 'room', 'filter_type' => $filter_type, 'date' => $selected_date, 'week' => $selected_week, 'month' => $selected_month, 'tool_page' => $tool_page], 'room_page'); ?>
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
                                <th style="width: 140px;">Waktu Pengajuan</th>
                                <th style="width: 180px;">Jadwal Peminjaman</th>
                                <th>Rincian Barang & Status ACC</th>
                                <th style="width: 160px;" class="text-center">Status Transaksi</th>
                                <th style="width: 90px;" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($grouped_tools) === 0): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada pengajuan alat lab<?= $filter_type !== 'all' ? ' pada periode ini (' . htmlspecialchars($filter_label) . ')' : '' ?>.</td></tr>
                            <?php else: ?>
                                <?php foreach($grouped_tools as $group): ?>
                                    <?php 
                                        $has_pending = false;
                                        $statuses = [];
                                        foreach($group['items'] as $it) {
                                            $statuses[] = $it['status'];
                                            if ($it['status'] === 'pending') $has_pending = true;
                                        }
                                        $unique_statuses = array_unique($statuses);
                                        
                                        // Summary status badge
                                        if (count($unique_statuses) === 1) {
                                            $single_status = $unique_statuses[0];
                                            if ($single_status === 'pending') {
                                                $overall_badge = '<span class="status-pending">Menunggu Persetujuan</span>';
                                            } elseif ($single_status === 'approved') {
                                                $overall_badge = '<span class="status-approved">Disetujui Semua</span>';
                                            } elseif ($single_status === 'rejected') {
                                                $overall_badge = '<span class="status-rejected">Ditolak Semua</span>';
                                            } elseif ($single_status === 'completed') {
                                                $overall_badge = '<span class="status-completed">Selesai / Kembali</span>';
                                            } elseif ($single_status === 'cancelled') {
                                                $overall_badge = '<span class="badge bg-secondary">Dibatalkan</span>';
                                            } else {
                                                $overall_badge = '<span class="badge bg-dark">' . ucfirst($single_status) . '</span>';
                                            }
                                        } else {
                                            if (in_array('approved', $unique_statuses) && in_array('rejected', $unique_statuses) && !in_array('pending', $unique_statuses)) {
                                                $overall_badge = '<span class="badge bg-primary">Disetujui Sebagian</span>';
                                            } elseif (in_array('pending', $unique_statuses)) {
                                                $overall_badge = '<span class="status-pending">Menunggu Review</span>';
                                            } else {
                                                $overall_badge = '<span class="badge bg-info text-dark">Campuran</span>';
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= date('d M Y', strtotime($group['created_at'])) ?></strong>
                                            <div class="text-muted small"><?= date('H:i', strtotime($group['created_at'])) ?> WIB</div>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($group['booking_date']) ?></strong>
                                                <?php if (!empty($group['end_date']) && $group['end_date'] !== $group['booking_date']): ?>
                                                    <?php 
                                                        $d1 = new DateTime($group['booking_date']);
                                                        $d2 = new DateTime($group['end_date']);
                                                        $diff_days = $d1->diff($d2)->days + 1;
                                                    ?>
                                                    <span class="text-muted small d-block">s/d <?= htmlspecialchars($group['end_date']) ?> (<?= $diff_days ?> hari)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small mt-1">
                                                <?php if ($group['start_time'] === '00:00:00' && $group['end_time'] === '23:59:59'): ?>
                                                    <span class="badge bg-light text-dark border"><?= (!empty($group['end_date']) && $group['end_date'] !== $group['booking_date']) ? 'Rentang Waktu (24 Jam)' : 'Seharian (24 Jam)' ?></span>
                                                <?php else: ?>
                                                    <span class="text-secondary"><?= htmlspecialchars(substr($group['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($group['end_time'], 0, 5)) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="bg-light p-2 rounded border">
                                                <table class="table table-sm table-borderless mb-0">
                                                    <tbody>
                                                        <?php foreach($group['items'] as $item): ?>
                                                        <tr class="border-bottom border-light">
                                                            <td class="py-2">
                                                                <strong><?= htmlspecialchars($item['tool_name']) ?></strong>
                                                                <span class="badge bg-secondary ms-1"><?= $item['quantity'] ?> unit</span>
                                                                <?php if(!empty($item['rejection_reason'])): ?>
                                                                    <div class="text-danger small mt-1">
                                                                        <em>Alasan Ditolak: <?= htmlspecialchars($item['rejection_reason']) ?></em>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end py-2 align-middle" style="width: 140px;">
                                                                <span class="status-<?= htmlspecialchars($item['status']) ?>">
                                                                    <?= ucfirst(htmlspecialchars($item['status'])) ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?= $overall_badge ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if($has_pending): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Yakin ingin membatalkan pengajuan alat ini?');">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="type" value="tool_group">
                                                <input type="hidden" name="tab" value="tool">
                                                <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type) ?>">
                                                <input type="hidden" name="date" value="<?= htmlspecialchars($selected_date) ?>">
                                                <input type="hidden" name="week" value="<?= htmlspecialchars($selected_week) ?>">
                                                <input type="hidden" name="month" value="<?= htmlspecialchars($selected_month) ?>">
                                                <input type="hidden" name="booking_date" value="<?= htmlspecialchars($group['booking_date']) ?>">
                                                <input type="hidden" name="created_at" value="<?= htmlspecialchars($group['created_at']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Batalkan</button>
                                            </form>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($tool_total_pages > 1): ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <span class="text-muted small">Menampilkan <?= count($grouped_tools) > 0 ? $tool_offset + 1 : 0 ?> sampai <?= $tool_offset + count($grouped_tools) ?> dari <?= $tool_total_records ?> pengajuan</span>
                        <?php renderPagination($tool_page, $tool_total_pages, ['tab' => 'tool', 'filter_type' => $filter_type, 'date' => $selected_date, 'week' => $selected_week, 'month' => $selected_month, 'room_page' => $room_page], 'tool_page'); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updateFilterUI() {
    const selected = document.querySelector('input[name="filter_type"]:checked')?.value || 'all';
    const boxDaily = document.getElementById('box_daily');
    const boxWeekly = document.getElementById('box_weekly');
    const boxMonthly = document.getElementById('box_monthly');
    
    if (boxDaily) boxDaily.style.display = (selected === 'daily') ? 'block' : 'none';
    if (boxWeekly) boxWeekly.style.display = (selected === 'weekly') ? 'block' : 'none';
    if (boxMonthly) boxMonthly.style.display = (selected === 'monthly') ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const tabElList = document.querySelectorAll('#myTab button[data-bs-toggle="tab"]');
    tabElList.forEach(function (tabEl) {
        tabEl.addEventListener('shown.bs.tab', function (event) {
            const targetId = event.target.id;
            const tabName = (targetId === 'tool-tab') ? 'tool' : 'room';
            
            // Update hidden tab input in filter form
            const activeInput = document.getElementById('activeTabInput');
            if (activeInput) activeInput.value = tabName;

            // Update main download button
            const btnDownload = document.getElementById('btnDownloadCurrent');
            const labelDownload = document.getElementById('labelDownloadCurrent');
            if (btnDownload && labelDownload) {
                const url = new URL(btnDownload.href, window.location.origin);
                url.searchParams.set('tab', tabName);
                btnDownload.href = url.pathname + url.search;
                labelDownload.textContent = 'Download XLSX (' + (tabName === 'tool' ? 'Alat Lab' : 'Ruangan/Meja') + ')';
            }
        });
    });
});
</script>
<?php if (isset($_SESSION['flash_message']) && strpos($_SESSION['flash_message']['message'], 'Pengajuan peminjaman alat berhasil') !== false): ?>
<script>
    try {
        sessionStorage.removeItem('pinjam_alat_cart_' + <?= (int)$user_id ?>);
    } catch(e) {}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
