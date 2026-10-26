<?php
// user/riwayat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('user');
$user_id = $_SESSION['user_id'];

// Determine Active Tab
$active_tab = $_GET['tab'] ?? (isset($_GET['tool_page']) && !isset($_GET['room_page']) ? 'tool' : 'room');
if (!in_array($active_tab, ['room', 'tool'])) {
    $active_tab = 'room';
}

// Handle Cancel Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    if (!validateCsrfToken()) {
        setFlashMessage('danger', 'Sesi keamanan tidak valid (CSRF token mismatch). Silakan coba lagi.');
        redirect('/user/riwayat.php?tab=' . urlencode($_POST['tab'] ?? $active_tab));
    }

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
    SELECT COUNT(DISTINCT rb.booking_date, rb.start_time, rb.end_time, rb.created_at, rb.project_name, rb.dosen_pembimbing) 
    FROM room_bookings rb 
    WHERE rb.user_id = ?
");
$stmt_room_count->execute([$user_id]);
$room_total_records = (int)$stmt_room_count->fetchColumn();
$room_total_pages = max(1, ceil($room_total_records / $limit));

// Fetch Room Booking Groups for current page
$stmt_room_groups = $pdo->prepare("
    SELECT rb.booking_date, rb.start_time, rb.end_time, rb.created_at,
           rb.project_name, rb.dosen_pembimbing 
    FROM room_bookings rb 
    WHERE rb.user_id = ? 
    GROUP BY rb.booking_date, rb.start_time, rb.end_time, rb.created_at, rb.project_name, rb.dosen_pembimbing 
    ORDER BY rb.created_at DESC 
    LIMIT $limit OFFSET $room_offset
");
$stmt_room_groups->execute([$user_id]);
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
            'project_name' => $pg['project_name'] ?? '',
            'dosen_pembimbing' => $pg['dosen_pembimbing'] ?? '',
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
    WHERE tb.user_id = ?
");
$stmt_tool_count->execute([$user_id]);
$tool_total_records = (int)$stmt_tool_count->fetchColumn();
$tool_total_pages = max(1, ceil($tool_total_records / $limit));

// Fetch Tool Booking Groups for current page
$stmt_tool_groups = $pdo->prepare("
    SELECT tb.booking_date, tb.end_date, tb.start_time, tb.end_time, tb.created_at 
    FROM tool_bookings tb 
    WHERE tb.user_id = ? 
    GROUP BY tb.booking_date, tb.end_date, tb.start_time, tb.end_time, tb.created_at 
    ORDER BY tb.created_at DESC 
    LIMIT $limit OFFSET $tool_offset
");
$stmt_tool_groups->execute([$user_id]);
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
        <h3 class="mb-4">Riwayat & Status Pengajuan Saya</h3>
        
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
                                <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat pengajuan ruangan/meja.</td></tr>
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
                                            $st = reset($unique_statuses);
                                            if ($st === 'approved') {
                                                $overall_badge = '<span class="status-approved">Disetujui</span>';
                                            } elseif ($st === 'rejected') {
                                                $overall_badge = '<span class="status-rejected">Ditolak</span>';
                                            } elseif ($st === 'pending') {
                                                $overall_badge = '<span class="status-pending">Menunggu Review</span>';
                                            } elseif ($st === 'cancelled') {
                                                $overall_badge = '<span class="status-cancelled">Dibatalkan</span>';
                                            } elseif ($st === 'expired') {
                                                $overall_badge = '<span class="status-expired">Kedaluwarsa</span>';
                                            } else {
                                                $overall_badge = '<span class="badge bg-secondary">' . ucfirst($st) . '</span>';
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
                                            <strong><?= htmlspecialchars(date('d/m/Y', strtotime($group['booking_date']))) ?></strong>
                                            <div class="text-secondary small mt-1">
                                                <?= htmlspecialchars(substr($group['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($group['end_time'], 0, 5)) ?> WIB
                                            </div>
                                            <?php if (!empty($group['project_name'])): ?>
                                                <div class="mt-2">
                                                    <span class="badge bg-light text-dark border text-wrap text-start" title="Project">📌 <?= htmlspecialchars($group['project_name']) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($group['dosen_pembimbing'])): ?>
                                                <div class="small text-muted mt-1">👨‍🏫 Dosen: <?= htmlspecialchars($group['dosen_pembimbing']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="bg-light p-2 rounded border">
                                                <table class="table table-sm table-borderless mb-0">
                                                    <tbody>
                                                        <?php foreach($group['items'] as $item): ?>
                                                        <tr class="border-bottom border-light">
                                                            <td class="py-2">
                                                                <strong><?= htmlspecialchars($item['table_name']) ?></strong>
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
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Yakin ingin membatalkan pengajuan ini?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="type" value="room_group">
                                                <input type="hidden" name="booking_date" value="<?= htmlspecialchars($group['booking_date']) ?>">
                                                <input type="hidden" name="created_at" value="<?= htmlspecialchars($group['created_at']) ?>">
                                                <input type="hidden" name="tab" value="room">
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

                    <!-- Pagination Ruangan -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <?php 
                            $start_record = ($room_total_records > 0) ? $room_offset + 1 : 0;
                            $end_record = min($room_offset + $limit, $room_total_records);
                        ?>
                        <div class="text-muted small">
                            Menampilkan <?= $start_record ?> sampai <?= $end_record ?> dari <?= $room_total_records ?> entri
                        </div>
                        
                        <?php if ($room_total_pages > 1): ?>
                            <?php renderPagination($room_page, $room_total_pages, ['tab' => 'room', 'tool_page' => $tool_page], 'room_page'); ?>
                        <?php endif; ?>
                    </div>
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
                                <th>Rincian Alat & Status ACC</th>
                                <th style="width: 160px;" class="text-center">Status Transaksi</th>
                                <th style="width: 90px;" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($grouped_tools) === 0): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat pengajuan alat lab.</td></tr>
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
                                            $st = reset($unique_statuses);
                                            if ($st === 'approved') {
                                                $overall_badge = '<span class="status-approved">Disetujui</span>';
                                            } elseif ($st === 'rejected') {
                                                $overall_badge = '<span class="status-rejected">Ditolak</span>';
                                            } elseif ($st === 'pending') {
                                                $overall_badge = '<span class="status-pending">Menunggu Review</span>';
                                            } elseif ($st === 'completed') {
                                                $overall_badge = '<span class="badge bg-success">Selesai / Dikembalikan</span>';
                                            } elseif ($st === 'cancelled') {
                                                $overall_badge = '<span class="status-cancelled">Dibatalkan</span>';
                                            } elseif ($st === 'expired') {
                                                $overall_badge = '<span class="status-expired">Kedaluwarsa</span>';
                                            } else {
                                                $overall_badge = '<span class="badge bg-secondary">' . ucfirst($st) . '</span>';
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
                                                <strong><?= htmlspecialchars(date('d/m/Y', strtotime($group['booking_date']))) ?></strong>
                                                <?php if (!empty($group['end_date']) && $group['end_date'] !== $group['booking_date']): ?>
                                                    <?php 
                                                        $d1 = new DateTime($group['booking_date']);
                                                        $d2 = new DateTime($group['end_date']);
                                                        $diff_days = $d1->diff($d2)->days + 1;
                                                    ?>
                                                    <span class="text-muted small d-block">s/d <?= htmlspecialchars(date('d/m/Y', strtotime($group['end_date']))) ?> (<?= $diff_days ?> hari)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small mt-1">
                                                <?php if ($group['start_time'] === '00:00:00' && $group['end_time'] === '23:59:59'): ?>
                                                    <span class="badge bg-light text-dark border"><?= (!empty($group['end_date']) && $group['end_date'] !== $group['booking_date']) ? 'Rentang Waktu (24 Jam)' : 'Seharian (24 Jam)' ?></span>
                                                <?php else: ?>
                                                    <span class="text-secondary"><?= htmlspecialchars(substr($group['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($group['end_time'], 0, 5)) ?> WIB</span>
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
                                                                <span class="badge bg-secondary ms-1"><?= (int)$item['quantity'] ?> unit</span>
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
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="type" value="tool_group">
                                                <input type="hidden" name="booking_date" value="<?= htmlspecialchars($group['booking_date']) ?>">
                                                <input type="hidden" name="created_at" value="<?= htmlspecialchars($group['created_at']) ?>">
                                                <input type="hidden" name="tab" value="tool">
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

                    <!-- Pagination Alat -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <?php 
                            $start_record = ($tool_total_records > 0) ? $tool_offset + 1 : 0;
                            $end_record = min($tool_offset + $limit, $tool_total_records);
                        ?>
                        <div class="text-muted small">
                            Menampilkan <?= $start_record ?> sampai <?= $end_record ?> dari <?= $tool_total_records ?> entri
                        </div>
                        
                        <?php if ($tool_total_pages > 1): ?>
                            <?php renderPagination($tool_page, $tool_total_pages, ['tab' => 'tool', 'room_page' => $room_page], 'tool_page'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-clear tool borrowing cart when user is on history page
    try {
        sessionStorage.removeItem('pinjam_alat_cart_' + <?= (int)$user_id ?>);
        sessionStorage.removeItem('pinjam_alat_cart');
    } catch(e) {}
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
