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
    redirect('/user/riwayat.php?room_page=' . (int)($_GET['room_page'] ?? 1) . '&tool_page=' . (int)($_GET['tool_page'] ?? 1));
}

// Pagination configuration
$limit = 10;
$room_page = isset($_GET['room_page']) ? max(1, (int)$_GET['room_page']) : 1;
$room_offset = ($room_page - 1) * $limit;

$tool_page = isset($_GET['tool_page']) ? max(1, (int)$_GET['tool_page']) : 1;
$tool_offset = ($tool_page - 1) * $limit;

// Fetch Room Bookings Group Count (1 pengajuan multi-meja = 1x transaksi)
$stmt_room_count = $pdo->prepare("
    SELECT COUNT(DISTINCT booking_date, start_time, end_time, created_at) 
    FROM room_bookings 
    WHERE user_id = ?
");
$stmt_room_count->execute([$user_id]);
$room_total_records = (int)$stmt_room_count->fetchColumn();
$room_total_pages = max(1, ceil($room_total_records / $limit));

// Fetch Room Booking Groups for current page
$stmt_room_groups = $pdo->prepare("
    SELECT booking_date, start_time, end_time, created_at 
    FROM room_bookings 
    WHERE user_id = ? 
    GROUP BY booking_date, start_time, end_time, created_at 
    ORDER BY created_at DESC 
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
    SELECT COUNT(DISTINCT booking_date, start_time, end_time, created_at) 
    FROM tool_bookings 
    WHERE user_id = ?
");
$stmt_tool_count->execute([$user_id]);
$tool_total_records = (int)$stmt_tool_count->fetchColumn();
$tool_total_pages = max(1, ceil($tool_total_records / $limit));

// Fetch Tool Booking Groups for current page
$stmt_tool_groups = $pdo->prepare("
    SELECT booking_date, end_date, start_time, end_time, created_at 
    FROM tool_bookings 
    WHERE user_id = ? 
    GROUP BY booking_date, end_date, start_time, end_time, created_at 
    ORDER BY created_at DESC 
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
                                <th style="width: 140px;">Waktu Pengajuan</th>
                                <th style="width: 180px;">Jadwal Peminjaman</th>
                                <th>Rincian Meja & Status ACC</th>
                                <th style="width: 160px;" class="text-center">Status Transaksi</th>
                                <th style="width: 90px;" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($grouped_rooms) === 0): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada pengajuan ruangan/meja.</td></tr>
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
                        <?php renderPagination($room_page, $room_total_pages, ['tool_page' => $tool_page], 'room_page'); ?>
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
                                <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada pengajuan alat.</td></tr>
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
                        <?php renderPagination($tool_page, $tool_total_pages, ['room_page' => $room_page], 'tool_page'); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
