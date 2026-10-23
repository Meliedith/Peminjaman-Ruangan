<?php
// user/pinjam_alat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('user');

// Check user profile for phone number
$stmt_curr_user = $pdo->prepare("SELECT id, name, phone FROM users WHERE id = ?");
$stmt_curr_user->execute([$_SESSION['user_id']]);
$current_user = $stmt_curr_user->fetch(PDO::FETCH_ASSOC);
$user_phone = trim($current_user['phone'] ?? '');
$has_phone = !empty($user_phone);

// Pagination configuration
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Fetch tool types for filter
$stmt_jenis = $pdo->query("SELECT id, name FROM tool_types ORDER BY name ASC");
$list_jenis = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Search, Filter, Sort params
$search_query = trim($_GET['search'] ?? '');
$filter_jenis = $_GET['jenis'] ?? '';
$sort = isset($_GET['sort']) && strtoupper($_GET['sort']) === 'DESC' ? 'DESC' : 'ASC';

$where_clauses = ["deleted_at IS NULL", "is_active = 1", "total_stock > 0", "kondisi = 'Baik'"];
$params = [];

if ($search_query !== '') {
    $where_clauses[] = "name LIKE ?";
    $params[] = "%$search_query%";
}

if ($filter_jenis !== '') {
    $where_clauses[] = "jenis = ?";
    $params[] = $filter_jenis;
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch total count
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM tools WHERE $where_sql");
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch active tools with dynamic current stock for display
$query = "
    SELECT tools.*, 
        (
            SELECT COALESCE(SUM(quantity), 0) 
            FROM tool_bookings 
            WHERE tool_bookings.tool_id = tools.id 
            AND status = 'approved' 
            AND CURDATE() BETWEEN booking_date AND IFNULL(end_date, booking_date) 
            AND CURTIME() BETWEEN start_time AND end_time
        ) as current_borrowed
    FROM tools 
    WHERE $where_sql
    ORDER BY name $sort
    LIMIT $limit OFFSET $offset
";
$stmt_tools = $pdo->prepare($query);
$stmt_tools->execute($params);
$tools = $stmt_tools->fetchAll();

$slots = getTimeSlots(); // from functions.php

function renderToolRows($tools) {
    if (count($tools) === 0) {
        return '<tr><td colspan="8" class="text-center py-4 text-muted">Tidak ada alat yang ditemukan.</td></tr>';
    }
    ob_start();
    foreach($tools as $t): 
        $img_url = getToolImageUrl($t['image'] ?? null);
    ?>
    <tr>
        <td class="text-center align-middle" style="width: 55px; min-width: 55px; max-width: 55px;">
            <?php if ($img_url): ?>
                <img src="<?= $img_url ?>" alt="<?= htmlspecialchars($t['name']) ?>" class="tool-thumb btn-preview-tool" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; max-width: 44px; max-height: 44px; object-fit: cover; border-radius: 6px; cursor: pointer; display: inline-block; border: 1px solid #dee2e6;" data-img="<?= $img_url ?>" data-name="<?= htmlspecialchars($t['name']) ?>" title="Klik untuk lihat preview">
            <?php else: ?>
                <span class="tool-thumb-placeholder" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; max-width: 44px; max-height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px dashed #ced4da; background: #f8f9fa; color: #adb5bd; font-size: 0.75rem;" title="Tidak ada foto">-</span>
            <?php endif; ?>
        </td>
        <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
        <td><?= htmlspecialchars($t['jenis'] ?? '-') ?></td>
        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($t['kondisi']) ?></span></td>
        <td><?= $t['total_stock'] ?> Unit</td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-primary btn-jadwal" data-id="<?= $t['id'] ?>" data-name="<?= htmlspecialchars($t['name']) ?>">🔍 Cek Jadwal</button>
        </td>
        <td>
            <input type="checkbox" name="tools[<?= $t['id'] ?>][selected]" value="1" 
                   class="form-check-input tool-checkbox" 
                   data-id="<?= $t['id'] ?>"
                   data-name="<?= htmlspecialchars($t['name']) ?>"
                   data-jenis="<?= htmlspecialchars($t['jenis'] ?? '-') ?>"
                   data-stock="<?= (int)$t['total_stock'] ?>"
                   data-img="<?= htmlspecialchars($img_url ?: '') ?>">
        </td>
        <td>
            <input type="number" name="tools[<?= $t['id'] ?>][quantity]" 
                   class="form-control form-control-sm tool-qty" 
                   id="qty_<?= $t['id'] ?>" 
                   data-id="<?= $t['id'] ?>" 
                   min="1" max="<?= $t['total_stock'] ?>" disabled placeholder="Jml">
        </td>
    </tr>
    <?php endforeach;
    return ob_get_clean();
}

function renderToolPaginationHtml($total_pages, $total_records, $count, $offset, $search_query, $filter_jenis, $sort, $page) {
    if ($total_pages <= 1) return '';
    ob_start();
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <span class="text-muted small">Menampilkan <?= $count > 0 ? $offset + 1 : 0 ?> sampai <?= $offset + $count ?> dari <?= $total_records ?> alat</span>
        <?php 
            renderPagination($page, $total_pages, [
                'search' => $search_query,
                'jenis' => $filter_jenis,
                'sort' => $sort
            ]);
        ?>
    </div>
    <?php
    return ob_get_clean();
}

// Handle Live AJAX Search Request
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'tbody' => renderToolRows($tools),
        'pagination' => renderToolPaginationHtml($total_pages, $total_records, count($tools), $offset, $search_query, $filter_jenis, $sort, $page),
        'total_records' => $total_records,
        'count' => count($tools)
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$has_phone) {
        setFlashMessage('danger', 'Anda wajib melengkapi nomor telepon di profil terlebih dahulu sebelum dapat mengajukan peminjaman alat lab.');
        redirect('/profile.php?redirect=pinjam_alat');
    }

    $tools_selected = $_POST['tools'] ?? [];
    $booking_type = $_POST['booking_type'] ?? 'harian';
    
    $selected_any = false;
    foreach ($tools_selected as $id => $data) {
        if (!empty($data['selected']) && (int)($data['quantity'] ?? 0) > 0) {
            $selected_any = true;
            break;
        }
    }
    
    if (!$selected_any) {
        setFlashMessage('danger', 'Silakan pilih minimal 1 barang dan isi jumlahnya.');
    } else {
        $valid = true;
        $booking_date = '';
        $end_date = '';
        $start_time = '';
        $end_time = '';
        
        $today = date('Y-m-d');
        $max_date_harian = date('Y-m-d', strtotime('+30 days'));
        $max_date_rentang = date('Y-m-d', strtotime('+1 year'));

        if ($booking_type === 'harian') {
            $booking_date = $_POST['booking_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $end_date = $booking_date;
            
            if ($booking_date < $today || $booking_date > $max_date_harian) {
                setFlashMessage('danger', 'Tanggal booking harian harus hari ini atau maksimal 30 hari ke depan.');
                $valid = false;
            } elseif (date('N', strtotime($booking_date)) >= 6) {
                setFlashMessage('danger', 'Peminjaman alat tidak diizinkan pada hari Sabtu dan Minggu.');
                $valid = false;
            } elseif ($start_time >= $end_time) {
                setFlashMessage('danger', 'Jam mulai harus sebelum jam selesai.');
                $valid = false;
            } elseif ($booking_date === $today && $end_time <= date('H:i')) {
                setFlashMessage('danger', 'Waktu peminjaman sudah berlalu. Anda hanya bisa memesan slot yang belum berakhir.');
                $valid = false;
            }
        } else {
            $booking_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';
            $start_time = '00:00:00';
            $end_time = '23:59:59';
            
            if ($booking_date < $today || $booking_date > $max_date_rentang || $end_date < $today || $end_date > $max_date_rentang) {
                setFlashMessage('danger', 'Tanggal peminjaman rentang waktu harus mulai hari ini hingga maksimal 1 tahun ke depan.');
                $valid = false;
            } elseif ($booking_date > $end_date) {
                setFlashMessage('danger', 'Tanggal mulai tidak boleh lebih besar dari tanggal selesai.');
                $valid = false;
            }
        }
        
        if ($valid) {
            $pdo->beginTransaction();
            try {
                $has_error = false;
                $submission_time = date('Y-m-d H:i:s');
                foreach ($tools_selected as $tool_id => $data) {
                    if (empty($data['selected'])) continue;
                    
                    $quantity = (int)($data['quantity'] ?? 0);
                    if ($quantity <= 0) continue;
                    
                    $stmt_tool = $pdo->prepare("SELECT total_stock, name FROM tools WHERE id = ? AND deleted_at IS NULL");
                    $stmt_tool->execute([$tool_id]);
                    $tool = $stmt_tool->fetch();
                    
                    if (!$tool) continue;
                    $total_stock = $tool['total_stock'];
                    $tool_name = $tool['name'];
                    
                    // Overlap check
                    $stmt_overlap = $pdo->prepare("
                        SELECT SUM(quantity) as booked_qty FROM tool_bookings 
                        WHERE tool_id = ? 
                        AND status IN ('pending', 'approved')
                        AND booking_date <= ? 
                        AND IFNULL(end_date, booking_date) >= ?
                        AND start_time < ? 
                        AND end_time > ?
                    ");
                    $stmt_overlap->execute([$tool_id, $end_date, $booking_date, $end_time, $start_time]);
                    $booked_res = $stmt_overlap->fetch();
                    $booked_qty = $booked_res['booked_qty'] ?? 0;
                    
                    $available_stock = $total_stock - $booked_qty;
                    if ($quantity > $available_stock) {
                        setFlashMessage('danger', "Stok tidak mencukupi untuk alat '{$tool_name}' pada waktu yang dipilih. Sisa stok tersedia: {$available_stock} unit (sebagian unit sudah dipinjam atau sedang menunggu persetujuan pemohon lain).");
                        $has_error = true;
                        break;
                    }
                    
                    $stmt_insert = $pdo->prepare("
                        INSERT INTO tool_bookings (user_id, tool_id, quantity, booking_date, end_date, start_time, end_time, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                    ");
                    $stmt_insert->execute([$_SESSION['user_id'], $tool_id, $quantity, $booking_date, $end_date, $start_time, $end_time, $submission_time]);
                }
                
                if ($has_error) {
                    $pdo->rollBack();
                } else {
                    $pdo->commit();
                    setFlashMessage('success', 'Pengajuan peminjaman alat berhasil dikirim dan menunggu persetujuan.');
                    redirect('/user/riwayat.php');
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlashMessage('danger', 'Terjadi kesalahan sistem.');
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-md-10 offset-md-1">
        <div id="js-alert-container"></div>
        <?php if (!$has_phone): ?>
            <div class="alert alert-warning border-warning shadow-sm p-3 mb-4 rounded-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <strong class="text-dark">Nomor Telepon Belum Diisi di Profil!</strong>
                    <div class="small text-muted">Sistem mewajibkan nomor telepon aktif (WhatsApp) agar Anda dapat mengajukan peminjaman alat lab. Nomor ini akan tercantum pada pengajuan agar admin lab dapat menghubungi Anda.</div>
                </div>
                <a href="<?= BASE_URL ?>/profile.php?redirect=pinjam_alat" class="btn btn-warning fw-semibold text-dark text-nowrap">
                    Lengkapi No. Telp Sekarang &rarr;
                </a>
            </div>
        <?php endif; ?>
        <div class="card shadow-sm p-4">
            <h3 class="mb-4">Pinjam Alat Lab</h3>

            <!-- Panduan Edukasi Aturan Stok & Pending -->
            <div class="alert alert-info border-info mb-4" role="alert">
                <div class="mb-1">
                    <strong class="text-dark">Petunjuk & Aturan Ketersediaan Stok</strong>
                </div>
                <ul class="mb-0 ps-3 small text-secondary">
                    <li>Pengajuan yang berstatus <strong>Menunggu Persetujuan (Pending)</strong> sudah langsung mengunci kuota stok sementara agar tidak terjadi tabrakan peminjaman (<em>overbooking</em>).</li>
                    <li>Gunakan tombol <strong>🔍 Cek Jadwal</strong> di samping nama alat untuk melihat ketersediaan unit pada tanggal dan jam tertentu sebelum mengajukan.</li>
                    <li>Pada mode <strong>Rentang Waktu</strong>, alat dipinjam secara penuh (24 jam/hari). Pastikan alat tersebut tersedia di seluruh tanggal dalam rentang tersebut (dapat dipinjam lebih dari 1 bulan hingga maksimal 1 tahun).</li>
                </ul>
            </div>
            
            <div class="row g-3 mb-4 bg-light p-3 rounded border align-items-center">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Cari Nama Barang</label>
                    <div class="position-relative">
                        <input type="text" name="search" id="searchInput" class="form-control pe-5" placeholder="Ketik nama alat untuk mencari otomatis..." value="<?= htmlspecialchars($search_query) ?>" autocomplete="off">
                        <span id="searchSpinner" class="spinner-border spinner-border-sm text-primary position-absolute top-50 end-0 translate-middle-y me-3" style="display: none;"></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Jenis Barang</label>
                    <select name="jenis" id="filterJenis" class="form-select">
                        <option value="">Semua Jenis</option>
                        <?php foreach($list_jenis as $j): ?>
                            <option value="<?= htmlspecialchars($j['name']) ?>" <?= $filter_jenis === $j['name'] ? 'selected' : '' ?>><?= htmlspecialchars($j['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" id="btnResetSearch" class="btn btn-outline-secondary w-100" title="Reset Pencarian">Reset</button>
                </div>
            </div>
            
            <form method="POST" action="" id="bookingForm">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <h5 class="mb-0">Daftar Barang Tersedia</h5>
                    <button type="button" class="btn btn-outline-primary btn-sm position-relative d-inline-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#cartModal" id="btnHeaderCart">
                        <span>🛒</span>
                        <span class="fw-semibold">Keranjang Peminjaman</span>
                        <span class="badge bg-danger rounded-pill" id="cartBadgeHeader">0</span>
                    </button>
                </div>
                <div class="table-responsive mb-2">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 55px;" class="text-center">Foto</th>
                                <th>
                                    <?php 
                                        $new_sort = ($sort === 'ASC') ? 'DESC' : 'ASC';
                                        $sort_icon = ($sort === 'ASC') ? '&#9650;' : '&#9660;';
                                        $sort_params = http_build_query(['search' => $search_query, 'jenis' => $filter_jenis, 'sort' => $new_sort]);
                                    ?>
                                    <a href="?<?= $sort_params ?>" class="text-dark text-decoration-none d-flex justify-content-between align-items-center">
                                        <span>Nama Barang</span>
                                        <small><?= $sort_icon ?></small>
                                    </a>
                                </th>
                                <th>Jenis</th>
                                <th>Kondisi</th>
                                <th>Total Stok <small class="text-muted d-block fw-normal">(Fisik)</small></th>
                                <th style="width: 130px;">Ketersediaan</th>
                                <th style="width: 50px;">Pilih</th>
                                <th style="width: 120px;">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody id="toolTableBody">
                            <?= renderToolRows($tools) ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mb-4">* Sisa stok riil pada tanggal/jam spesifik dapat dilihat melalui tombol <strong>🔍 Cek Jadwal</strong>.</small>

                <div id="toolPaginationWrapper">
                    <?= renderToolPaginationHtml($total_pages, $total_records, count($tools), $offset, $search_query, $filter_jenis, $sort, $page) ?>
                </div>

                <!-- Ringkasan Keranjang Peminjaman -->
                <div id="bookingDetailsSection" class="card border-primary border-opacity-25 bg-primary bg-opacity-10 mb-4 p-3 rounded-3 shadow-sm">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fs-4">🛒</span>
                            <div>
                                <strong class="text-primary d-block">Barang di Keranjang Peminjaman:</strong>
                                <span id="cartSummaryText" class="small text-secondary">Belum ada barang dipilih.</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" id="btnClearCartSummary" style="display: none;">Kosongkan</button>
                            <button type="button" class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#cartModal">
                                🔍 Buka Keranjang (<span id="cartSummaryBadge">0</span>)
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label d-block fw-bold">Pilihan Tipe Peminjaman</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input booking-type" type="radio" name="booking_type" id="type_harian" value="harian" checked>
                        <label class="form-check-label" for="type_harian">
                            <strong>Harian</strong> <span class="text-muted small">(Jam Kerja Lab: 08:00 - 16:00)</span>
                        </label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input booking-type" type="radio" name="booking_type" id="type_rentang" value="rentang">
                        <label class="form-check-label" for="type_rentang">
                            <strong>Rentang Waktu</strong> <span class="text-muted small">(Multi-Hari / 24 Jam Penuh)</span>
                        </label>
                    </div>
                </div>

                <div id="harian_fields" class="bg-light p-3 rounded mb-4 border">
                    <div class="text-muted small mb-3">
                        Peminjaman harian berlaku untuk hari kerja (Senin - Jumat) dalam slot waktu 30 menit antara pukul 08:00 - 16:00.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tanggal Peminjaman</label>
                        <input type="date" name="booking_date" class="form-control" onclick="this.showPicker()"
                               min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Jam Mulai</label>
                            <select name="start_time" class="form-select">
                                <option value="">-- Pilih Jam Mulai --</option>
                                <?php foreach($slots as $idx => $s): ?>
                                    <?php if($idx < count($slots)-1): ?>
                                    <option value="<?= $s ?>"><?= $s ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Jam Selesai</label>
                            <select name="end_time" class="form-select">
                                <option value="">-- Pilih Jam Selesai --</option>
                                <?php foreach($slots as $idx => $s): ?>
                                    <?php if($idx > 0): ?>
                                    <option value="<?= $s ?>"><?= $s ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="rentang_fields" class="bg-light p-3 rounded mb-4 border" style="display: none;">
                    <div class="alert alert-warning py-2 px-3 small mb-3 border-warning">
                        <strong>Perhatian Mode Rentang Waktu:</strong> Peminjaman ini mengunci alat selama <strong>24 jam penuh per hari</strong> dari tanggal mulai s/d tanggal selesai (dapat dipinjam hingga maksimal 1 tahun untuk riset/proyek panjang). Jika ada pemohon lain yang meminjam di jam berapa pun pada rentang tanggal tersebut, pengajuan akan otomatis tertolak karena stok tidak mencukupi.
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Tanggal Mulai</label>
                            <input type="date" name="start_date" id="start_date" class="form-control" onclick="this.showPicker()"
                                   min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Tanggal Selesai</label>
                            <input type="date" name="end_date" id="end_date" class="form-control" onclick="this.showPicker()"
                                   min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                        </div>
                    </div>
                </div>

                <div class="alert <?= $has_phone ? 'alert-light border' : 'alert-danger' ?> small mb-3 p-2 px-3 rounded-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Kontak Pemohon:</strong><br>
                            <?= htmlspecialchars($current_user['name']) ?> 
                            <?php if ($has_phone): ?>
                                (<?= htmlspecialchars($user_phone) ?>)
                            <?php else: ?>
                                <span class="text-danger fw-bold">(Belum ada No. HP)</span>
                            <?php endif; ?>
                        </div>
                        <a href="<?= BASE_URL ?>/profile.php?redirect=pinjam_alat" class="small text-decoration-none">
                            <?= $has_phone ? 'Ubah' : 'Isi Sekarang' ?>
                        </a>
                    </div>
                </div>

                <?php if ($has_phone): ?>
                    <button type="submit" class="btn btn-success w-100 py-2 fs-6 fw-semibold">Ajukan Peminjaman Barang</button>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/profile.php?redirect=pinjam_alat" class="btn btn-warning w-100 py-2 fs-6 fw-bold shadow-sm text-dark">
                        Lengkapi No. HP untuk Meminjam
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<!-- Modal Jadwal -->
<div class="modal fade" id="scheduleModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title d-flex align-items-center">
            <span>🔍 Ketersediaan Jadwal: </span>
            <span id="scheduleToolName" class="badge bg-light text-primary ms-2 fw-semibold"></span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="scheduleToolId">
        
        <!-- Date Navigator Bar -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 bg-light p-2 rounded border">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPrevDay" title="Hari Sebelumnya">&larr; Prev</button>
                <input type="date" id="scheduleDate" class="form-control form-control-sm w-auto" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnNextDay" title="Hari Berikutnya">Next &rarr;</button>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnToday">Hari Ini</button>
                <span id="selectedDayName" class="badge bg-info text-dark"></span>
            </div>
        </div>

        <!-- Legenda Warna -->
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-center mb-3 p-2 bg-white rounded border small">
            <span class="fw-bold text-muted">Keterangan:</span>
            <span class="d-flex align-items-center gap-1">
                <span class="badge bg-success">&nbsp;</span> <strong>Hijau:</strong> Tersedia Penuh
            </span>
            <span class="d-flex align-items-center gap-1">
                <span class="badge bg-warning text-dark">&nbsp;</span> <strong>Kuning:</strong> Terisi Sebagian
            </span>
            <span class="d-flex align-items-center gap-1">
                <span class="badge bg-danger">&nbsp;</span> <strong>Merah:</strong> Habis (Tidak Tersedia)
            </span>
        </div>

        <div id="scheduleContainer" class="d-flex flex-wrap gap-2 justify-content-center">
            <!-- Schedule grid goes here -->
        </div>

        <div class="alert alert-secondary py-2 px-3 small mt-3 mb-0 text-muted">
            <em>* Catatan: Kuota berkurang jika ada peminjaman berstatus <strong>Pending</strong> (menunggu persetujuan admin) atau <strong>Approved</strong> (disetujui).</em>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Floating Cart Button -->
<div id="floatingCartBtn" class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050; display: none;">
    <button type="button" class="btn btn-primary rounded-pill shadow-lg px-3 py-2 d-flex align-items-center gap-2 border-2 border-white" data-bs-toggle="modal" data-bs-target="#cartModal">
        <span class="fs-5">🛒</span>
        <span class="fw-bold">Keranjang</span>
        <span class="badge bg-danger rounded-pill fs-7" id="floatingCartBadge">0</span>
    </button>
</div>

<!-- Modal Keranjang Peminjaman -->
<div class="modal fade" id="cartModal" tabindex="-1" aria-labelledby="cartModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title d-flex align-items-center gap-2" id="cartModalLabel">
            <span>🛒</span>
            <span>Keranjang Peminjaman Alat</span>
            <span class="badge bg-light text-primary rounded-pill ms-2" id="modalCartCountBadge">0 Alat</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-3 p-md-4">
        <div id="cartEmptyState" class="text-center py-5" style="display: none;">
            <div class="display-3 text-muted mb-3 opacity-50">🛒</div>
            <h5 class="text-secondary fw-bold">Keranjang Masih Kosong</h5>
            <p class="text-muted small mb-3">Silakan centang alat yang ingin dipinjam pada tabel daftar barang.<br>Barang yang Anda pilih akan tetap tersimpan meskipun Anda berpindah halaman atau mencari alat lain.</p>
            <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Pilih Alat Sekarang</button>
        </div>

        <div id="cartContentWrapper">
            <div class="alert alert-info py-2 px-3 small border-info d-flex align-items-center gap-2 mb-3">
                <span class="fs-5">💡</span>
                <div>
                    Semua barang di keranjang ini akan diajukan sekaligus dalam satu jadwal peminjaman. Anda dapat mengubah jumlah pinjam atau menghapus alat sebelum mengajukan.
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 55px;" class="text-center">Foto</th>
                            <th>Nama Alat</th>
                            <th style="width: 100px;">Stok</th>
                            <th style="width: 140px;">Jumlah Pinjam</th>
                            <th style="width: 60px;" class="text-center">Hapus</th>
                        </tr>
                    </thead>
                    <tbody id="cartTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
      </div>
      <div class="modal-footer bg-light d-flex justify-content-between align-items-center">
        <div>
            <button type="button" class="btn btn-outline-danger btn-sm" id="modalClearCartBtn" style="display: none;">
                🗑 Kosongkan Keranjang
            </button>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="fw-semibold text-secondary small me-2" id="modalCartTotalUnits">Total: 0 unit</span>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
            <button type="button" class="btn btn-success btn-sm" data-bs-dismiss="modal" id="btnModalProceed">
                Lanjut ke Jadwal &rarr;
            </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss ONLY success flash messages after 5 seconds (keep error messages visible for user to read)
    setTimeout(() => {
        document.querySelectorAll('.container > .alert.alert-success').forEach(alert => {
            alert.remove();
        });
    }, 5000);

    // ==========================================
    // MANAJEMEN KERANJANG PEMINJAMAN ALAT
    // ==========================================
    const USER_ID = <?= (int)$_SESSION['user_id'] ?>;
    const CART_STORAGE_KEY = 'pinjam_alat_cart_' + USER_ID;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getCart() {
        try {
            const raw = sessionStorage.getItem(CART_STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    function saveCart(cart) {
        try {
            sessionStorage.setItem(CART_STORAGE_KEY, JSON.stringify(cart));
        } catch (e) {}
        renderCart();
    }

    function clearCart() {
        try {
            sessionStorage.removeItem(CART_STORAGE_KEY);
        } catch (e) {}
        
        // Uncheck all items on current page
        document.querySelectorAll('.tool-checkbox').forEach(cb => {
            cb.checked = false;
            const id = cb.getAttribute('data-id');
            const qtyInput = document.getElementById('qty_' + id);
            if (qtyInput) {
                qtyInput.disabled = true;
                qtyInput.required = false;
                qtyInput.value = '';
            }
        });

        renderCart();
    }

    function setCartItem(id, name, jenis, stock, img, quantity) {
        const cart = getCart();
        let qty = parseInt(quantity, 10);
        const maxStock = parseInt(stock, 10) || 1;
        if (isNaN(qty) || qty <= 0) qty = 1;
        if (qty > maxStock) qty = maxStock;

        cart[id] = {
            id: String(id),
            name: name || 'Alat',
            jenis: jenis || '-',
            stock: maxStock,
            img: img || '',
            quantity: qty
        };
        saveCart(cart);
    }

    function removeFromCart(id) {
        const cart = getCart();
        delete cart[id];
        saveCart(cart);

        // If currently visible on page, uncheck and disable
        const cb = document.querySelector(`.tool-checkbox[data-id="${id}"]`);
        if (cb) {
            cb.checked = false;
            const qtyInput = document.getElementById('qty_' + id);
            if (qtyInput) {
                qtyInput.disabled = true;
                qtyInput.required = false;
                qtyInput.value = '';
            }
        }
    }

    function renderCart() {
        const cart = getCart();
        const ids = Object.keys(cart);
        const count = ids.length;
        let totalUnits = 0;
        ids.forEach(id => {
            totalUnits += parseInt(cart[id].quantity, 10) || 0;
        });

        // Update badges
        const badgeHeader = document.getElementById('cartBadgeHeader');
        if (badgeHeader) badgeHeader.textContent = count;
        
        const floatingBadge = document.getElementById('floatingCartBadge');
        if (floatingBadge) floatingBadge.textContent = count;

        const floatingBtn = document.getElementById('floatingCartBtn');
        if (floatingBtn) {
            floatingBtn.style.display = count > 0 ? 'block' : 'none';
        }

        const summaryBadge = document.getElementById('cartSummaryBadge');
        if (summaryBadge) summaryBadge.textContent = count;

        const modalCountBadge = document.getElementById('modalCartCountBadge');
        if (modalCountBadge) modalCountBadge.textContent = `${count} Alat`;

        const modalUnits = document.getElementById('modalCartTotalUnits');
        if (modalUnits) modalUnits.textContent = `Total: ${totalUnits} unit`;

        // Summary text
        const summaryText = document.getElementById('cartSummaryText');
        const btnClearSummary = document.getElementById('btnClearCartSummary');
        if (summaryText) {
            if (count === 0) {
                summaryText.textContent = 'Belum ada barang dipilih.';
                if (btnClearSummary) btnClearSummary.style.display = 'none';
            } else {
                summaryText.innerHTML = `<strong>${count} jenis alat</strong> (${totalUnits} unit) siap diajukan.`;
                if (btnClearSummary) btnClearSummary.style.display = 'inline-block';
            }
        }

        // Modal Clear Button
        const modalClearBtn = document.getElementById('modalClearCartBtn');
        if (modalClearBtn) {
            modalClearBtn.style.display = count > 0 ? 'inline-block' : 'none';
        }

        // Render Modal Table
        const emptyState = document.getElementById('cartEmptyState');
        const contentWrapper = document.getElementById('cartContentWrapper');
        const tbody = document.getElementById('cartTableBody');

        if (count === 0) {
            if (emptyState) emptyState.style.display = 'block';
            if (contentWrapper) contentWrapper.style.display = 'none';
            if (tbody) tbody.innerHTML = '';
        } else {
            if (emptyState) emptyState.style.display = 'none';
            if (contentWrapper) contentWrapper.style.display = 'block';

            if (tbody) {
                tbody.innerHTML = '';
                ids.forEach(id => {
                    const item = cart[id];
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="text-center align-middle">
                            ${item.img ? `<img src="${escapeHtml(item.img)}" style="width: 42px; height: 42px; object-fit: cover; border-radius: 6px; border: 1px solid #dee2e6;">` : `<span style="width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px dashed #ced4da; background: #f8f9fa; color: #adb5bd; font-size: 0.75rem;">-</span>`}
                        </td>
                        <td>
                            <strong class="d-block text-dark">${escapeHtml(item.name)}</strong>
                            <span class="text-muted small">${escapeHtml(item.jenis)}</span>
                        </td>
                        <td>
                            <span class="badge bg-secondary">${item.stock} Unit</span>
                        </td>
                        <td>
                            <div class="input-group input-group-sm" style="max-width: 125px;">
                                <button type="button" class="btn btn-outline-secondary btn-cart-minus" data-id="${item.id}">-</button>
                                <input type="number" class="form-control text-center cart-qty-input" data-id="${item.id}" value="${item.quantity}" min="1" max="${item.stock}">
                                <button type="button" class="btn btn-outline-secondary btn-cart-plus" data-id="${item.id}">+</button>
                            </div>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger btn-cart-delete" data-id="${item.id}" title="Hapus alat ini dari keranjang">&times;</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        }
    }

    // Modal Table Event Delegation (Minus, Plus, Delete, Qty Change)
    const cartTableBody = document.getElementById('cartTableBody');
    if (cartTableBody) {
        cartTableBody.addEventListener('click', function(e) {
            const btnMinus = e.target.closest('.btn-cart-minus');
            const btnPlus = e.target.closest('.btn-cart-plus');
            const btnDelete = e.target.closest('.btn-cart-delete');

            if (btnMinus) {
                const id = btnMinus.getAttribute('data-id');
                const cart = getCart();
                if (cart[id]) {
                    let qty = parseInt(cart[id].quantity, 10) - 1;
                    if (qty < 1) qty = 1;
                    cart[id].quantity = qty;
                    saveCart(cart);
                    const domQty = document.getElementById('qty_' + id);
                    if (domQty) domQty.value = qty;
                }
            } else if (btnPlus) {
                const id = btnPlus.getAttribute('data-id');
                const cart = getCart();
                if (cart[id]) {
                    let qty = parseInt(cart[id].quantity, 10) + 1;
                    if (qty > cart[id].stock) qty = cart[id].stock;
                    cart[id].quantity = qty;
                    saveCart(cart);
                    const domQty = document.getElementById('qty_' + id);
                    if (domQty) domQty.value = qty;
                }
            } else if (btnDelete) {
                const id = btnDelete.getAttribute('data-id');
                removeFromCart(id);
            }
        });

        cartTableBody.addEventListener('change', function(e) {
            const inputQty = e.target.closest('.cart-qty-input');
            if (inputQty) {
                const id = inputQty.getAttribute('data-id');
                const cart = getCart();
                if (cart[id]) {
                    let qty = parseInt(inputQty.value, 10);
                    if (isNaN(qty) || qty < 1) qty = 1;
                    if (qty > cart[id].stock) qty = cart[id].stock;
                    inputQty.value = qty;
                    cart[id].quantity = qty;
                    saveCart(cart);
                    const domQty = document.getElementById('qty_' + id);
                    if (domQty) domQty.value = qty;
                }
            }
        });
    }

    // Clear cart confirmation
    function confirmClearCart() {
        if (confirm('Apakah Anda yakin ingin mengosongkan seluruh keranjang peminjaman alat?')) {
            clearCart();
        }
    }
    const modalClearBtn = document.getElementById('modalClearCartBtn');
    if (modalClearBtn) modalClearBtn.addEventListener('click', confirmClearCart);
    const summaryClearBtn = document.getElementById('btnClearCartSummary');
    if (summaryClearBtn) summaryClearBtn.addEventListener('click', confirmClearCart);

    // Proceed from modal to booking details
    const btnModalProceed = document.getElementById('btnModalProceed');
    if (btnModalProceed) {
        btnModalProceed.addEventListener('click', function() {
            const sec = document.getElementById('bookingDetailsSection');
            if (sec) sec.scrollIntoView({ behavior: 'smooth' });
        });
    }

    // Current Sort param for live search
    const currentSort = '<?= $sort ?>';

    // Synchronize checkboxes and inputs on current page with cart
    function syncTableWithCart() {
        const cart = getCart();
        document.querySelectorAll('.tool-checkbox').forEach(cb => {
            const id = cb.getAttribute('data-id');
            const qtyInput = document.getElementById('qty_' + id);
            if (cart[id]) {
                cb.checked = true;
                if (qtyInput) {
                    qtyInput.disabled = false;
                    qtyInput.required = true;
                    qtyInput.value = cart[id].quantity;
                }
            } else {
                cb.checked = false;
                if (qtyInput) {
                    qtyInput.disabled = true;
                    qtyInput.required = false;
                    qtyInput.value = '';
                }
            }
        });
    }

    // Initial sync on page load
    syncTableWithCart();

    // Event Delegation on toolTableBody for Checkboxes, Qty Inputs, Jadwal, and Preview
    const toolTableBody = document.getElementById('toolTableBody');
    if (toolTableBody) {
        // Checkbox toggle
        toolTableBody.addEventListener('change', function(e) {
            if (e.target.matches('.tool-checkbox')) {
                const cb = e.target;
                const id = cb.getAttribute('data-id');
                const qtyInput = document.getElementById('qty_' + id);
                if (cb.checked) {
                    if (qtyInput) {
                        qtyInput.disabled = false;
                        qtyInput.required = true;
                        if (!qtyInput.value) qtyInput.value = 1;
                    }
                    const name = cb.getAttribute('data-name');
                    const jenis = cb.getAttribute('data-jenis');
                    const stock = parseInt(cb.getAttribute('data-stock'), 10) || 1;
                    const img = cb.getAttribute('data-img') || '';
                    setCartItem(id, name, jenis, stock, img, qtyInput ? qtyInput.value : 1);
                } else {
                    if (qtyInput) {
                        qtyInput.disabled = true;
                        qtyInput.required = false;
                        qtyInput.value = '';
                    }
                    removeFromCart(id);
                }
            }
        });

        // Quantity input in table
        toolTableBody.addEventListener('input', function(e) {
            if (e.target.matches('.tool-qty')) {
                const qtyInput = e.target;
                const id = qtyInput.getAttribute('data-id');
                const cb = document.querySelector(`.tool-checkbox[data-id="${id}"]`);
                if (cb && cb.checked) {
                    const name = cb.getAttribute('data-name');
                    const jenis = cb.getAttribute('data-jenis');
                    const stock = parseInt(cb.getAttribute('data-stock'), 10) || 1;
                    const img = cb.getAttribute('data-img') || '';
                    setCartItem(id, name, jenis, stock, img, qtyInput.value);
                }
            }
        });

        // Click on Cek Jadwal and Preview tool
        toolTableBody.addEventListener('click', function(e) {
            const btnJadwal = e.target.closest('.btn-jadwal');
            const btnPreview = e.target.closest('.btn-preview-tool');

            if (btnJadwal) {
                scheduleToolId.value = btnJadwal.getAttribute('data-id');
                scheduleToolName.textContent = btnJadwal.getAttribute('data-name');
                const today = new Date().toISOString().split('T')[0];
                scheduleDate.value = today;
                loadSchedule();
                scheduleModal.show();
            } else if (btnPreview) {
                const imgUrl = btnPreview.getAttribute('data-img');
                const name = btnPreview.getAttribute('data-name');
                if (imgUrl) {
                    document.getElementById('toolPreviewImg').src = imgUrl;
                    document.getElementById('toolPreviewTitle').textContent = name || 'Preview Alat';
                    const modalEl = document.getElementById('toolPreviewModal');
                    const previewModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    previewModal.show();
                }
            }
        });
    }

    // Live Search Logic (Debounced input on search box)
    let searchDebounceTimer = null;
    const searchInput = document.getElementById('searchInput');
    const filterJenis = document.getElementById('filterJenis');
    const searchSpinner = document.getElementById('searchSpinner');
    const btnResetSearch = document.getElementById('btnResetSearch');
    const toolPaginationWrapper = document.getElementById('toolPaginationWrapper');

    function doLiveSearch(page = 1) {
        const query = searchInput ? searchInput.value.trim() : '';
        const jenis = filterJenis ? filterJenis.value : '';
        
        if (searchSpinner) searchSpinner.style.display = 'inline-block';

        const params = new URLSearchParams({
            ajax_search: '1',
            search: query,
            jenis: jenis,
            page: page,
            sort: currentSort
        });

        fetch(`pinjam_alat.php?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                if (toolTableBody) toolTableBody.innerHTML = data.tbody;
                if (toolPaginationWrapper) toolPaginationWrapper.innerHTML = data.pagination;
                
                // Sync new rows with Cart
                syncTableWithCart();

                // Update browser URL without full reload
                const urlParams = new URLSearchParams();
                if (query) urlParams.set('search', query);
                if (jenis) urlParams.set('jenis', jenis);
                if (page > 1) urlParams.set('page', page);
                if (currentSort !== 'ASC') urlParams.set('sort', currentSort);
                const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                window.history.replaceState({}, '', newUrl);
            })
            .catch(err => {
                console.error('Error in live search:', err);
            })
            .finally(() => {
                if (searchSpinner) searchSpinner.style.display = 'none';
            });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                doLiveSearch(1);
            }, 300);
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchDebounceTimer);
                doLiveSearch(1);
            }
        });
    }

    if (filterJenis) {
        filterJenis.addEventListener('change', function() {
            clearTimeout(searchDebounceTimer);
            doLiveSearch(1);
        });
    }

    if (btnResetSearch) {
        btnResetSearch.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (filterJenis) filterJenis.value = '';
            clearTimeout(searchDebounceTimer);
            doLiveSearch(1);
        });
    }

    // Intercept Pagination Clicks for smooth AJAX paging
    if (toolPaginationWrapper) {
        toolPaginationWrapper.addEventListener('click', function(e) {
            const link = e.target.closest('.page-link');
            if (link && !link.closest('.disabled') && link.getAttribute('href') && link.getAttribute('href') !== '#') {
                e.preventDefault();
                const href = link.getAttribute('href');
                const urlObj = new URL(href, window.location.href);
                const page = urlObj.searchParams.get('page') || 1;
                doLiveSearch(page);
                const tableContainer = document.querySelector('.table-responsive');
                if (tableContainer) tableContainer.scrollIntoView({ behavior: 'smooth' });
            }
        });
    }

    // Render initial Cart UI
    renderCart();

    // Form submit interception: Inject all items in cart before submitting
    const bookingForm = document.getElementById('bookingForm');
    if (bookingForm) {
        bookingForm.addEventListener('submit', function(e) {
            const cart = getCart();
            const ids = Object.keys(cart);
            
            if (ids.length === 0) {
                e.preventDefault();
                alert('Keranjang peminjaman masih kosong! Silakan pilih minimal 1 barang terlebih dahulu.');
                const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('cartModal'));
                modal.show();
                return false;
            }

            // Remove any previously injected inputs
            document.querySelectorAll('.cart-injected-input').forEach(el => el.remove());

            // Inject hidden inputs for all items in cart not currently present in table DOM
            ids.forEach(id => {
                const item = cart[id];
                const domCb = document.querySelector(`.tool-checkbox[data-id="${id}"]`);
                const domQty = document.getElementById('qty_' + id);

                if (domCb && domQty) {
                    domCb.checked = true;
                    domQty.disabled = false;
                    domQty.value = item.quantity;
                } else {
                    const hiddenSel = document.createElement('input');
                    hiddenSel.type = 'hidden';
                    hiddenSel.className = 'cart-injected-input';
                    hiddenSel.name = `tools[${id}][selected]`;
                    hiddenSel.value = '1';
                    bookingForm.appendChild(hiddenSel);

                    const hiddenQty = document.createElement('input');
                    hiddenQty.type = 'hidden';
                    hiddenQty.className = 'cart-injected-input';
                    hiddenQty.name = `tools[${id}][quantity]`;
                    hiddenQty.value = item.quantity;
                    bookingForm.appendChild(hiddenQty);
                }
            });
        });
    }

    // Toggle Date Form based on Booking Type
    const typeRadios = document.querySelectorAll('.booking-type');
    const harianFields = document.getElementById('harian_fields');
    const rentangFields = document.getElementById('rentang_fields');
    
    // Inputs inside sections
    const harianInputs = harianFields.querySelectorAll('input, select');
    const rentangInputs = rentangFields.querySelectorAll('input, select');

    function updateFields() {
        const type = document.querySelector('.booking-type:checked').value;
        if (type === 'harian') {
            harianFields.style.display = 'block';
            rentangFields.style.display = 'none';
            
            harianInputs.forEach(el => el.required = true);
            rentangInputs.forEach(el => el.required = false);
        } else {
            harianFields.style.display = 'none';
            rentangFields.style.display = 'block';
            
            harianInputs.forEach(el => el.required = false);
            rentangInputs.forEach(el => el.required = true);
        }
    }

    typeRadios.forEach(radio => {
        radio.addEventListener('change', updateFields);
    });
    
    // Initialize required fields
    updateFields();

    // Auto update min attribute of end_date when start_date changes
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    if (startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            if (this.value) {
                endDateInput.min = this.value;
                if (endDateInput.value && endDateInput.value < this.value) {
                    endDateInput.value = this.value;
                }
            } else {
                endDateInput.min = '<?= date('Y-m-d') ?>';
            }
        });
    }

    // Schedule check logic
    const scheduleModal = new bootstrap.Modal(document.getElementById('scheduleModal'));
    const scheduleDate = document.getElementById('scheduleDate');
    const scheduleToolId = document.getElementById('scheduleToolId');
    const scheduleToolName = document.getElementById('scheduleToolName');
    const scheduleContainer = document.getElementById('scheduleContainer');
    const selectedDayName = document.getElementById('selectedDayName');
    const btnPrevDay = document.getElementById('btnPrevDay');
    const btnNextDay = document.getElementById('btnNextDay');
    const btnToday = document.getElementById('btnToday');

    function updateDayLabel(dateStr) {
        if (!dateStr) return;
        const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        const parts = dateStr.split('-');
        const d = new Date(parts[0], parts[1] - 1, parts[2]);
        if (!isNaN(d.getTime())) {
            selectedDayName.textContent = `${days[d.getDay()]}, ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
        }
    }

    btnPrevDay.addEventListener('click', function() {
        if (!scheduleDate.value) return;
        const parts = scheduleDate.value.split('-');
        const d = new Date(parts[0], parts[1] - 1, parts[2]);
        d.setDate(d.getDate() - 1);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const prevStr = `${y}-${m}-${day}`;
        const minStr = scheduleDate.getAttribute('min');
        if (minStr && prevStr < minStr) return;
        scheduleDate.value = prevStr;
        loadSchedule();
    });

    btnNextDay.addEventListener('click', function() {
        if (!scheduleDate.value) return;
        const parts = scheduleDate.value.split('-');
        const d = new Date(parts[0], parts[1] - 1, parts[2]);
        d.setDate(d.getDate() + 1);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        scheduleDate.value = `${y}-${m}-${day}`;
        loadSchedule();
    });

    btnToday.addEventListener('click', function() {
        const today = new Date().toISOString().split('T')[0];
        scheduleDate.value = today;
        loadSchedule();
    });

    scheduleDate.addEventListener('change', loadSchedule);

    function loadSchedule() {
        const id = scheduleToolId.value;
        const date = scheduleDate.value;
        if (!id || !date) return;
        
        updateDayLabel(date);
        scheduleContainer.innerHTML = '<div class="text-center w-100 py-4"><span class="spinner-border spinner-border-sm text-primary"></span> Memuat jadwal ketersediaan...</div>';
        
        fetch(`../ajax/get_tool_schedule.php?tool_id=${id}&date=${date}`)
            .then(res => res.json())
            .then(data => {
                scheduleContainer.innerHTML = '';
                if(data.length === 0) {
                    scheduleContainer.innerHTML = '<div class="alert alert-warning w-100 text-center">Jadwal tidak tersedia.</div>';
                    return;
                }
                
                data.forEach(slot => {
                    const div = document.createElement('div');
                    div.className = `p-2 rounded text-center border shadow-sm ${slot.color}`;
                    div.style.minWidth = '105px';
                    div.style.fontSize = '0.85rem';
                    
                    let badgeText = '';
                    let notes = [];
                    const pendingCount = slot.booked_pending || 0;
                    const approvedCount = slot.booked_approved || 0;
                    
                    if (pendingCount > 0) notes.push(`${pendingCount} Pending`);
                    if (approvedCount > 0) notes.push(`${approvedCount} Disetujui`);
                    const noteStr = notes.length > 0 ? `<div style="font-size:0.72rem;" class="mt-1 opacity-75">${notes.join(', ')}</div>` : '';

                    if (slot.available <= 0) {
                        badgeText = `<span class="badge bg-dark text-white d-block mb-1">Habis (0/${slot.total})</span>`;
                    } else if (slot.available < slot.total) {
                        badgeText = `<span class="badge bg-light text-dark d-block mb-1">Sisa: ${slot.available} / ${slot.total}</span>`;
                    } else {
                        badgeText = `<span class="badge bg-light text-success d-block mb-1">Tersedia (${slot.total})</span>`;
                    }

                    div.innerHTML = `
                        <div class="fw-bold mb-1">${slot.time}</div>
                        ${badgeText}
                        ${noteStr}
                    `;
                    scheduleContainer.appendChild(div);
                });
            })
            .catch(err => {
                scheduleContainer.innerHTML = '<div class="alert alert-danger w-100 text-center">Gagal memuat jadwal. Silakan coba lagi.</div>';
            });
    }
});
</script>

<!-- Modal Preview Foto Alat (Ukuran Kecil) -->
<div class="modal fade" id="toolPreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" style="max-width: 220px; margin: 1.75rem auto;">
    <div class="modal-content shadow border-0" style="max-width: 220px;">
      <div class="modal-header py-1 px-3 bg-light">
        <h6 class="modal-title text-truncate fw-bold mb-0" id="toolPreviewTitle" style="font-size: 0.85rem;">Preview Alat</h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.7rem;"></button>
      </div>
      <div class="modal-body text-center p-3">
        <div class="tool-small-preview-box mx-auto" style="width: 140px; height: 140px; max-width: 140px; max-height: 140px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6; display: flex; align-items: center; justify-content: center; background: #f8f9fa;">
          <img id="toolPreviewImg" src="" alt="Preview Alat" style="width: 140px; height: 140px; max-width: 140px; max-height: 140px; object-fit: cover; display: block;">
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

