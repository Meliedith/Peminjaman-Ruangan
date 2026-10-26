<?php
// admin/kelola_ruangan.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('admin');

// Handle Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlashMessage('danger', 'Sesi keamanan tidak valid (CSRF token mismatch). Silakan muat ulang halaman.');
        redirect('/admin/kelola_ruangan.php');
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
                setFlashMessage('danger', 'Alasan penolakan harus diisi untuk meja yang tidak dicentang.');
            } else {
                $processed = 0;
                foreach ($group_items as $id) {
                    $stmt_check = $pdo->prepare("SELECT * FROM room_bookings WHERE id = ? FOR UPDATE");
                    $stmt_check->execute([$id]);
                    $booking = $stmt_check->fetch();
                    
                    if ($booking && $booking['status'] === 'pending') {
                        if (in_array($id, $approved_items)) {
                            // Cek bentrok dengan booking approved lain
                            $stmt_conflict = $pdo->prepare("
                                SELECT id FROM room_bookings 
                                WHERE table_id = ? 
                                AND booking_date = ? 
                                AND status = 'approved' 
                                AND id != ? 
                                AND start_time < ? 
                                AND end_time > ?
                            ");
                            $stmt_conflict->execute([$booking['table_id'], $booking['booking_date'], $id, $booking['end_time'], $booking['start_time']]);
                            if ($stmt_conflict->fetch()) {
                                throw new Exception("Gagal: Meja telah terisi atau disetujui untuk pemohon lain pada tanggal dan jam tersebut.");
                            }

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
            $stmt_check = $pdo->prepare("SELECT * FROM room_bookings WHERE id = ? FOR UPDATE");
            $stmt_check->execute([$id]);
            $booking = $stmt_check->fetch();

            if ($booking && $booking['status'] === 'pending') {
                $stmt_conflict = $pdo->prepare("
                    SELECT id FROM room_bookings 
                    WHERE table_id = ? 
                    AND booking_date = ? 
                    AND status = 'approved' 
                    AND id != ? 
                    AND start_time < ? 
                    AND end_time > ?
                ");
                $stmt_conflict->execute([$booking['table_id'], $booking['booking_date'], $id, $booking['end_time'], $booking['start_time']]);
                if ($stmt_conflict->fetch()) {
                    throw new Exception("Gagal: Meja telah terisi atau disetujui untuk pemohon lain pada jam tersebut.");
                }

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
        setFlashMessage('danger', $e->getMessage() ?: 'Terjadi kesalahan sistem.');
    }
    
    redirect('/admin/kelola_ruangan.php');
}

// Pagination configuration for Pending Bookings (1 transaksi pengajuan multi-meja = 1 entri)
$pending_limit = 10;
$pending_page = isset($_GET['pending_page']) ? max(1, (int)$_GET['pending_page']) : 1;
$pending_offset = ($pending_page - 1) * $pending_limit;

// Count distinct pending groups
$stmt_pending_count = $pdo->query("
    SELECT COUNT(DISTINCT rb.user_id, rb.booking_date, rb.start_time, rb.end_time, rb.created_at) 
    FROM room_bookings rb 
    WHERE rb.status = 'pending'
");
$pending_total_records = (int)$stmt_pending_count->fetchColumn();
$pending_total_pages = max(1, ceil($pending_total_records / $pending_limit));

// Fetch pending groups for current page
$stmt_pending_groups = $pdo->prepare("
    SELECT rb.user_id, rb.booking_date, rb.start_time, rb.end_time, rb.created_at,
           rb.project_name, rb.dosen_pembimbing,
           u.name as user_name, u.phone as user_phone, u.email as user_email
    FROM room_bookings rb 
    JOIN users u ON rb.user_id = u.id 
    WHERE rb.status = 'pending'
    GROUP BY rb.user_id, rb.booking_date, rb.start_time, rb.end_time, rb.created_at, rb.project_name, rb.dosen_pembimbing, u.name, u.phone, u.email
    ORDER BY rb.created_at DESC, rb.booking_date DESC, rb.start_time DESC
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
            'start_time' => $pg['start_time'],
            'end_time' => $pg['end_time'],
            'project_name' => $pg['project_name'] ?? '',
            'dosen_pembimbing' => $pg['dosen_pembimbing'] ?? '',
            'created_at' => $pg['created_at'],
            'items' => []
        ];
    }

    $created_ats = array_unique(array_column($pending_page_groups, 'created_at'));
    $placeholders = implode(',', array_fill(0, count($created_ats), '?'));
    $stmt_items = $pdo->prepare("
        SELECT rb.*, t.name as table_name 
        FROM room_bookings rb 
        JOIN tables t ON rb.table_id = t.id 
        WHERE rb.status = 'pending' AND rb.created_at IN ($placeholders)
        ORDER BY rb.created_at ASC, CAST(SUBSTRING(t.name, 5) AS UNSIGNED) ASC
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
        ORDER BY rb.created_at DESC, CAST(SUBSTRING(t.name, 5) AS UNSIGNED) ASC
    ");
    $stmt_export->execute($history_filter_params);
    $rows = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    // Grouping by transaction
    $grouped_export = [];
    foreach ($rows as $r) {
        $key = $r['user_id'] . '_' . $r['booking_date'] . '_' . $r['start_time'] . '_' . $r['end_time'] . '_' . $r['created_at'];
        if (!isset($grouped_export[$key])) {
            $grouped_export[$key] = [
                'user_name' => $r['user_name'],
                'user_email' => $r['user_email'] ?? '-',
                'user_phone' => $r['user_phone'] ?? '-',
                'project_name' => $r['project_name'] ?? '-',
                'dosen_pembimbing' => $r['dosen_pembimbing'] ?? '-',
                'created_at' => $r['created_at'],
                'booking_date' => $r['booking_date'],
                'start_time' => $r['start_time'],
                'end_time' => $r['end_time'],
                'tables' => [],
                'statuses' => [],
                'reasons' => []
            ];
        }
        $grouped_export[$key]['tables'][] = $r['table_name'] . ' (' . ucfirst($r['status']) . ')';
        $grouped_export[$key]['statuses'][] = $r['status'];
        if (!empty($r['rejection_reason'])) {
            $grouped_export[$key]['reasons'][] = $r['table_name'] . ': ' . $r['rejection_reason'];
        }
    }

    $excelData = [
        ['<b>No</b>', '<b>Pemohon</b>', '<b>Email</b>', '<b>No. HP</b>', '<b>Project</b>', '<b>Dosen Pembimbing</b>', '<b>Waktu Pengajuan</b>', '<b>Tanggal Pinjam</b>', '<b>Jam Mulai</b>', '<b>Jam Selesai</b>', '<b>Total Meja</b>', '<b>Rincian Meja & Status</b>', '<b>Status Transaksi</b>', '<b>Alasan Penolakan</b>']
    ];
    $no = 1;
    foreach ($grouped_export as $g) {
        $unique_statuses = array_unique($g['statuses']);
        if (count($unique_statuses) === 1) {
            $st = reset($unique_statuses);
            $tx_status = match($st) {
                'approved' => 'Disetujui',
                'rejected' => 'Ditolak',
                'cancelled' => 'Dibatalkan',
                'expired' => 'Kedaluwarsa',
                default => ucfirst($st)
            };
        } else {
            if (in_array('approved', $unique_statuses) && in_array('rejected', $unique_statuses)) {
                $tx_status = 'Disetujui Sebagian';
            } else {
                $tx_status = 'Campuran';
            }
        }

        $excelData[] = [
            $no++,
            sanitizeExcelCell($g['user_name']),
            sanitizeExcelCell($g['user_email'] ?: '-'),
            sanitizeExcelCell($g['user_phone'] ?: '-'),
            sanitizeExcelCell($g['project_name'] ?: '-'),
            sanitizeExcelCell($g['dosen_pembimbing'] ?: '-'),
            date('d/m/Y H:i', strtotime($g['created_at'])),
            date('d/m/Y', strtotime($g['booking_date'])),
            substr($g['start_time'], 0, 5) . ' WIB',
            substr($g['end_time'], 0, 5) . ' WIB',
            count($g['tables']) . ' Meja',
            sanitizeExcelCell(implode(', ', $g['tables'])),
            $tx_status,
            sanitizeExcelCell(!empty($g['reasons']) ? implode('; ', $g['reasons']) : '-')
        ];
    }
    if (count($excelData) === 1) {
        $excelData[] = ['-', '-', '-', '-', '-', '-', '-', 'Tidak ada data histori peminjaman meja untuk periode ini', '-', '-', '-', '-', '-', '-'];
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

// Pagination configuration for history (1 transaksi pengajuan multi-meja = 1 entri)
$limit = 10;
$history_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$history_offset = ($history_page - 1) * $limit;

$stmt_history_count = $pdo->prepare("
    SELECT COUNT(DISTINCT rb.user_id, rb.booking_date, rb.start_time, rb.end_time, rb.created_at) 
    FROM room_bookings rb 
    WHERE rb.status != 'pending' $history_filter_sql
");
$stmt_history_count->execute($history_filter_params);
$history_total_records = (int)$stmt_history_count->fetchColumn();
$history_total_pages = max(1, ceil($history_total_records / $limit));

// Fetch distinct history groups for current page
$stmt_history_groups = $pdo->prepare("
    SELECT rb.user_id, rb.booking_date, rb.start_time, rb.end_time, rb.created_at,
           rb.project_name, rb.dosen_pembimbing,
           u.name as user_name, u.phone as user_phone, u.email as user_email
    FROM room_bookings rb 
    JOIN users u ON rb.user_id = u.id 
    WHERE rb.status != 'pending' $history_filter_sql
    GROUP BY rb.user_id, rb.booking_date, rb.start_time, rb.end_time, rb.created_at, rb.project_name, rb.dosen_pembimbing, u.name, u.phone, u.email
    ORDER BY rb.created_at DESC, rb.booking_date DESC, rb.start_time DESC
    LIMIT $limit OFFSET $history_offset
");
$stmt_history_groups->execute($history_filter_params);
$history_page_groups = $stmt_history_groups->fetchAll(PDO::FETCH_ASSOC);

$history_bookings = [];
if (!empty($history_page_groups)) {
    foreach ($history_page_groups as $hg) {
        $key = $hg['user_id'] . '_' . $hg['booking_date'] . '_' . $hg['start_time'] . '_' . $hg['end_time'] . '_' . $hg['created_at'];
        $history_bookings[$key] = [
            'user_id' => $hg['user_id'],
            'user_name' => $hg['user_name'],
            'user_phone' => $hg['user_phone'] ?? '',
            'user_email' => $hg['user_email'] ?? '',
            'booking_date' => $hg['booking_date'],
            'start_time' => $hg['start_time'],
            'end_time' => $hg['end_time'],
            'project_name' => $hg['project_name'] ?? '',
            'dosen_pembimbing' => $hg['dosen_pembimbing'] ?? '',
            'created_at' => $hg['created_at'],
            'items' => []
        ];
    }

    $created_ats = array_unique(array_column($history_page_groups, 'created_at'));
    $placeholders = implode(',', array_fill(0, count($created_ats), '?'));
    $stmt_items = $pdo->prepare("
        SELECT rb.*, t.name as table_name 
        FROM room_bookings rb 
        JOIN tables t ON rb.table_id = t.id 
        WHERE rb.status != 'pending' AND rb.created_at IN ($placeholders)
        ORDER BY rb.created_at DESC, CAST(SUBSTRING(t.name, 5) AS UNSIGNED) ASC
    ");
    $stmt_items->execute($created_ats);
    $all_history_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_history_items as $item) {
        $key = $item['user_id'] . '_' . $item['booking_date'] . '_' . $item['start_time'] . '_' . $item['end_time'] . '_' . $item['created_at'];
        if (isset($history_bookings[$key])) {
            $history_bookings[$key]['items'][] = $item;
        }
    }
}

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

<h3 class="mb-4">Kelola Pengajuan Ruangan</h3>

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
                                    <?php if (!empty($group['project_name'])): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-light text-dark border text-wrap text-start" title="Project">📌 <?= htmlspecialchars($group['project_name']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($group['dosen_pembimbing'])): ?>
                                        <div class="small text-muted mt-1">👨‍🏫 Dosen: <?= htmlspecialchars($group['dosen_pembimbing']) ?></div>
                                    <?php endif; ?>
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
                                              <?= csrfField() ?>
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
                                                            <strong>Email:</strong> <?= htmlspecialchars($group['user_email'] ?? '-') ?><br>
                                                            <strong>Project:</strong> <span class="badge bg-primary text-white text-wrap text-start"><?= htmlspecialchars($group['project_name'] ?: '-') ?></span><br>
                                                            <strong>Dosen Pembimbing:</strong> <?= htmlspecialchars($group['dosen_pembimbing'] ?: '-') ?>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <strong>Waktu Pengajuan:</strong> <?= date('d M Y H:i', strtotime($group['created_at'])) ?> WIB<br>
                                                            <strong>Tanggal Pinjam:</strong> <?= htmlspecialchars($group['booking_date']) ?><br>
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
                        <th>Waktu Pengajuan</th>
                        <th>Tanggal Pinjam</th>
                        <th>Waktu</th>
                        <th>Meja yang Dipinjam</th>
                        <th>Status Transaksi</th>
                        <th>Alasan Penolakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($history_bookings) === 0): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada histori<?= $filter_type !== 'all' ? ' untuk periode (' . htmlspecialchars($filter_label) . ')' : '' ?>.</td></tr>
                    <?php else: ?>
                        <?php foreach($history_bookings as $group): ?>
                            <?php 
                                $statuses = array_column($group['items'], 'status');
                                $unique_statuses = array_unique($statuses);
                                if (count($unique_statuses) === 1) {
                                    $st = reset($unique_statuses);
                                    if ($st === 'approved') {
                                        $overall_badge = '<span class="status-approved">Disetujui</span>';
                                    } elseif ($st === 'rejected') {
                                        $overall_badge = '<span class="status-rejected">Ditolak</span>';
                                    } elseif ($st === 'cancelled') {
                                        $overall_badge = '<span class="status-cancelled">Dibatalkan</span>';
                                    } elseif ($st === 'expired') {
                                        $overall_badge = '<span class="status-expired">Kedaluwarsa</span>';
                                    } else {
                                        $overall_badge = '<span class="badge bg-secondary">' . ucfirst($st) . '</span>';
                                    }
                                } else {
                                    if (in_array('approved', $unique_statuses) && in_array('rejected', $unique_statuses)) {
                                        $overall_badge = '<span class="badge bg-primary">Disetujui Sebagian</span>';
                                    } else {
                                        $overall_badge = '<span class="badge bg-info text-dark">Campuran</span>';
                                    }
                                }

                                $reasons = [];
                                foreach ($group['items'] as $item) {
                                    if (!empty($item['rejection_reason'])) {
                                        $reasons[] = '<strong>' . htmlspecialchars($item['table_name']) . ':</strong> ' . htmlspecialchars($item['rejection_reason']);
                                    }
                                }
                            ?>
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
                                        <?php endif; ?>
                                        <?php if (!empty($group['user_email'])): ?>
                                            <div class="small text-muted"><?= htmlspecialchars($group['user_email']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($group['project_name'])): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-light text-dark border text-wrap text-start" title="Project">📌 <?= htmlspecialchars($group['project_name']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($group['dosen_pembimbing'])): ?>
                                        <div class="small text-muted mt-1">👨‍🏫 Dosen: <?= htmlspecialchars($group['dosen_pembimbing']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= date('d M Y', strtotime($group['created_at'])) ?></div>
                                    <small class="text-muted"><?= date('H:i', strtotime($group['created_at'])) ?> WIB</small>
                                </td>
                                <td><?= htmlspecialchars($group['booking_date']) ?></td>
                                <td><?= htmlspecialchars(substr($group['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($group['end_time'], 0, 5)) ?> WIB</td>
                                <td>
                                    <span class="badge bg-secondary mb-1"><?= count($group['items']) ?> Meja</span>
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        <?php foreach($group['items'] as $item): ?>
                                            <?php 
                                                $badge_color = match($item['status']) {
                                                    'approved' => 'bg-success',
                                                    'rejected' => 'bg-danger',
                                                    'cancelled' => 'bg-secondary',
                                                    'expired' => 'bg-dark',
                                                    default => 'bg-secondary'
                                                };
                                            ?>
                                            <span class="badge <?= $badge_color ?>" title="Status: <?= ucfirst($item['status']) ?>">
                                                <?= htmlspecialchars($item['table_name']) ?>
                                                <?php if (count($unique_statuses) > 1): ?>
                                                    <span class="badge bg-white text-dark ms-1" style="font-size: 0.7em;"><?= ucfirst($item['status']) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <?= $overall_badge ?>
                                </td>
                                <td>
                                    <?= !empty($reasons) ? implode('<br>', $reasons) : '<span class="text-muted">-</span>' ?>
                                </td>
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
