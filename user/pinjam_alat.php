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
            AND booking_date = CURDATE() 
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
        $max_date = date('Y-m-d', strtotime('+30 days'));

        if ($booking_type === 'harian') {
            $booking_date = $_POST['booking_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $end_date = $booking_date;
            
            if ($booking_date < $today || $booking_date > $max_date) {
                setFlashMessage('danger', 'Tanggal booking harus hari ini atau maksimal 30 hari ke depan.');
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
            
            if ($booking_date < $today || $booking_date > $max_date || $end_date < $today || $end_date > $max_date) {
                setFlashMessage('danger', 'Tanggal booking harus hari ini atau maksimal 30 hari ke depan.');
                $valid = false;
            } elseif ($booking_date > $end_date) {
                setFlashMessage('danger', 'Tanggal mulai harus sebelum tanggal selesai.');
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
                    <li>Pada mode <strong>Rentang Waktu</strong>, alat dipinjam secara penuh (24 jam/hari). Pastikan alat tersebut tersedia di seluruh tanggal dalam rentang tersebut.</li>
                </ul>
            </div>
            
            <form method="GET" action="" class="row g-3 mb-4 bg-light p-3 rounded border">
                <div class="col-md-6">
                    <label class="form-label">Cari Nama Barang</label>
                    <input type="text" name="search" class="form-control" placeholder="Ketik nama alat..." value="<?= htmlspecialchars($search_query) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Jenis Barang</label>
                    <select name="jenis" class="form-select">
                        <option value="">Semua Jenis</option>
                        <?php foreach($list_jenis as $j): ?>
                            <option value="<?= htmlspecialchars($j['name']) ?>" <?= $filter_jenis === $j['name'] ? 'selected' : '' ?>><?= htmlspecialchars($j['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-1">Cari</button>
                    <a href="pinjam_alat.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
            
            <form method="POST" action="">
                <h5 class="mb-3">Daftar Barang Tersedia</h5>
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
                        <tbody>
                            <?php if (count($tools) === 0): ?>
                                <tr><td colspan="8" class="text-center">Tidak ada alat yang tersedia.</td></tr>
                            <?php endif; ?>
                            <?php foreach($tools as $t): 
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
                                    <input type="checkbox" name="tools[<?= $t['id'] ?>][selected]" value="1" class="form-check-input tool-checkbox" data-id="<?= $t['id'] ?>">
                                </td>
                                <td>
                                    <input type="number" name="tools[<?= $t['id'] ?>][quantity]" class="form-control form-control-sm tool-qty" id="qty_<?= $t['id'] ?>" min="1" max="<?= $t['total_stock'] ?>" disabled placeholder="Jml">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mb-4">* Sisa stok riil pada tanggal/jam spesifik dapat dilihat melalui tombol <strong>🔍 Cek Jadwal</strong>.</small>

                <?php if ($total_pages > 1): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                    <span class="text-muted small">Menampilkan <?= count($tools) > 0 ? $offset + 1 : 0 ?> sampai <?= $offset + count($tools) ?> dari <?= $total_records ?> alat</span>
                    <?php 
                        renderPagination($page, $total_pages, [
                            'search' => $search_query,
                            'jenis' => $filter_jenis,
                            'sort' => $sort
                        ]);
                    ?>
                </div>
                <?php endif; ?>

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
                        <strong>Perhatian Mode Rentang Waktu:</strong> Peminjaman ini mengunci alat selama <strong>24 jam penuh per hari</strong> dari tanggal mulai s/d tanggal selesai. Jika ada pemohon lain yang meminjam di jam berapa pun pada rentang tanggal tersebut, pengajuan akan otomatis tertolak karena stok tidak mencukupi.
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Tanggal Mulai</label>
                            <input type="date" name="start_date" class="form-control" onclick="this.showPicker()"
                                   min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Tanggal Selesai</label>
                            <input type="date" name="end_date" class="form-control" onclick="this.showPicker()"
                                   min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+30 days')) ?>">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss ONLY success flash messages after 5 seconds (keep error messages visible for user to read)
    setTimeout(() => {
        document.querySelectorAll('.container > .alert.alert-success').forEach(alert => {
            alert.remove();
        });
    }, 5000);

    // Toggle Quantity Input based on Checkbox
    const checkboxes = document.querySelectorAll('.tool-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const id = this.getAttribute('data-id');
            const qtyInput = document.getElementById('qty_' + id);
            if (this.checked) {
                qtyInput.disabled = false;
                qtyInput.required = true;
                if (!qtyInput.value) qtyInput.value = 1;
            } else {
                qtyInput.disabled = true;
                qtyInput.required = false;
                qtyInput.value = '';
            }
        });
    });

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

    document.querySelectorAll('.btn-jadwal').forEach(btn => {
        btn.addEventListener('click', function() {
            scheduleToolId.value = this.getAttribute('data-id');
            scheduleToolName.textContent = this.getAttribute('data-name');
            // reset date to today on open
            const today = new Date().toISOString().split('T')[0];
            scheduleDate.value = today;
            loadSchedule();
            scheduleModal.show();
        });
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

    // Modal preview gambar alat (ukuran kecil tetap)
    document.querySelectorAll('.btn-preview-tool').forEach(el => {
        el.addEventListener('click', function() {
            const imgUrl = this.getAttribute('data-img');
            const name = this.getAttribute('data-name');
            if (imgUrl) {
                document.getElementById('toolPreviewImg').src = imgUrl;
                document.getElementById('toolPreviewTitle').textContent = name || 'Preview Alat';
                const modalEl = document.getElementById('toolPreviewModal');
                const previewModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                previewModal.show();
            }
        });
    });
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

