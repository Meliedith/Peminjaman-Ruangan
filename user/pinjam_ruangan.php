<?php
// user/pinjam_ruangan.php
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

// Fetch all active tables ordered numerically (Meja 1 to 25)
$stmt = $pdo->query("SELECT id, name FROM tables WHERE is_active = 1 ORDER BY CAST(SUBSTRING(name, 5) AS UNSIGNED) ASC");
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

$slots = getTimeSlots(); // from functions.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$has_phone) {
        setFlashMessage('danger', 'Anda wajib melengkapi nomor telepon di profil terlebih dahulu sebelum dapat mengajukan peminjaman meja.');
        redirect('/profile.php?redirect=pinjam_ruangan');
    }

    $table_ids = $_POST['table_ids'] ?? [];
    if (!is_array($table_ids)) {
        $table_ids = !empty($_POST['table_id']) ? [$_POST['table_id']] : [];
    }
    // Clean and validate table IDs
    $table_ids = array_unique(array_filter(array_map('intval', $table_ids)));

    $booking_date = $_POST['booking_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    // Basic Validation
    $today = date('Y-m-d');
    $max_date = date('Y-m-d', strtotime('+30 days'));

    if (empty($table_ids)) {
        setFlashMessage('danger', 'Silakan pilih minimal 1 meja pada denah.');
    } elseif ($booking_date < $today || $booking_date > $max_date) {
        setFlashMessage('danger', 'Tanggal booking harus hari ini atau maksimal 30 hari ke depan.');
    } elseif (date('N', strtotime($booking_date)) >= 6) {
        setFlashMessage('danger', 'Peminjaman ruangan tidak diizinkan pada hari Sabtu dan Minggu.');
    } elseif ($start_time >= $end_time) {
        setFlashMessage('danger', 'Jam mulai harus sebelum jam selesai.');
    } elseif ($booking_date === $today && $end_time <= date('H:i')) {
        setFlashMessage('danger', 'Waktu peminjaman sudah berlalu. Anda hanya bisa memesan slot yang belum berakhir.');
    } else {
        // Overlap Validation for all chosen tables
        $placeholders = implode(',', array_fill(0, count($table_ids), '?'));
        $stmt_check = $pdo->prepare("
            SELECT rb.table_id, t.name as table_name 
            FROM room_bookings rb 
            JOIN tables t ON rb.table_id = t.id
            WHERE rb.table_id IN ($placeholders) 
            AND rb.booking_date = ? 
            AND rb.status IN ('pending', 'approved')
            AND rb.start_time < ? 
            AND rb.end_time > ?
        ");
        $params = array_merge($table_ids, [$booking_date, $end_time, $start_time]);
        $stmt_check->execute($params);
        $conflicts = $stmt_check->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($conflicts)) {
            $conflict_names = implode(', ', array_unique(array_column($conflicts, 'table_name')));
            setFlashMessage('danger', "Peminjaman gagal: $conflict_names bentrok dengan peminjaman lain pada jam tersebut (sudah terisi atau menunggu persetujuan).");
        } else {
            // Insert Bookings in Transaction
            try {
                $pdo->beginTransaction();
                $now = date('Y-m-d H:i:s');
                $stmt_insert = $pdo->prepare("
                    INSERT INTO room_bookings (user_id, table_id, booking_date, start_time, end_time, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?)
                ");
                foreach ($table_ids as $tid) {
                    $stmt_insert->execute([$_SESSION['user_id'], $tid, $booking_date, $start_time, $end_time, $now]);
                }
                $pdo->commit();
                $count = count($table_ids);
                setFlashMessage('success', "Pengajuan peminjaman $count meja berhasil dikirim dan menunggu persetujuan admin.");
                redirect('/user/riwayat.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlashMessage('danger', 'Terjadi kesalahan sistem saat memproses peminjaman.');
            }
        }
    }
}

// Geometry helper for annular sector
function getDeskArc($cx, $cy, $r, $R, $startAngle, $endAngle) {
    $startRad = deg2rad($startAngle);
    $endRad = deg2rad($endAngle);
    
    $x1 = $cx + $R * cos($startRad);
    $y1 = $cy + $R * sin($startRad);
    $x2 = $cx + $R * cos($endRad);
    $y2 = $cy + $R * sin($endRad);
    
    $x3 = $cx + $r * cos($endRad);
    $y3 = $cy + $r * sin($endRad);
    $x4 = $cx + $r * cos($startRad);
    $y4 = $cy + $r * sin($startRad);
    
    $arcAngle = $endAngle - $startAngle;
    if ($arcAngle < 0) $arcAngle += 360;
    $largeArc = ($arcAngle > 180) ? 1 : 0;
    
    return sprintf(
        "M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 0 %.2f %.2f Z",
        $x1, $y1, $R, $R, $largeArc, $x2, $y2,
        $x3, $y3, $r, $r, $largeArc, $x4, $y4
    );
}

function getMidCoords($cx, $cy, $radius, $startAngle, $endAngle) {
    $arcAngle = $endAngle - $startAngle;
    if ($arcAngle < 0) $arcAngle += 360;
    $midAngle = $startAngle + ($arcAngle / 2);
    if ($midAngle >= 360) $midAngle -= 360;
    $midRad = deg2rad($midAngle);
    return [
        'x' => $cx + $radius * cos($midRad),
        'y' => $cy + $radius * sin($midRad),
        'angle' => $midAngle
    ];
}

// 5 Circular Clusters configuration matching physical lab layout
$clusters = [
    1 => ['name' => 'Lingkaran 1', 'desc' => 'Kiri Depan', 'cx' => 240, 'cy' => 170, 'start' => 1],
    2 => ['name' => 'Lingkaran 2', 'desc' => 'Kanan Depan', 'cx' => 660, 'cy' => 170, 'start' => 6],
    3 => ['name' => 'Lingkaran 3', 'desc' => 'Kiri Tengah', 'cx' => 240, 'cy' => 450, 'start' => 11],
    4 => ['name' => 'Lingkaran 4', 'desc' => 'Kanan Tengah', 'cx' => 660, 'cy' => 450, 'start' => 16],
    5 => ['name' => 'Lingkaran 5', 'desc' => 'Bawah Tengah', 'cx' => 450, 'cy' => 700, 'start' => 21]
];

// 5 Horseshoe Desks angles per cluster (Opening at top 252 - 288 deg)
$desk_segments = [
    ['start' => 188.0, 'end' => 250.0], // 1: Ujung Kiri
    ['start' => 124.0, 'end' => 186.0], // 2: Kiri Bawah
    ['start' => 60.0,  'end' => 122.0], // 3: Bawah Tengah
    ['start' => 356.0, 'end' => 58.0],  // 4: Kanan Bawah
    ['start' => 292.0, 'end' => 354.0], // 5: Ujung Kanan
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Interactive Floor Plan Map Styling */
#svg-viewport-wrapper {
    position: relative;
    overflow: hidden;
    touch-action: none;
    user-select: none;
    border-radius: 12px;
    background: #f8fafc;
    cursor: grab;
    border: 1px solid #cbd5e1;
}
#svg-viewport-wrapper:active {
    cursor: grabbing;
}

#lab-floor-plan {
    display: block;
    width: 100%;
    height: auto;
    user-select: none;
}

.table-interactive-group {
    cursor: pointer;
}

.table-interactive-group .desk-path {
    transition: fill 0.2s ease, stroke 0.2s ease, stroke-width 0.2s ease, filter 0.2s ease;
}

.desk-num-text {
    font-size: 14px;
    font-weight: 800;
    fill: #ffffff;
    pointer-events: none;
    text-shadow: 0 1px 3px rgba(0,0,0,0.6);
    user-select: none;
}

/* 1. Default Available: Biru */
.desk-available .desk-path {
    fill: #2b7cd3;
    stroke: #1d4ed8;
    stroke-width: 1.5;
}
.table-interactive-group.desk-available:hover .desk-path {
    fill: #3b82f6;
    stroke: #ffffff;
    stroke-width: 2.5;
    filter: drop-shadow(0 0 6px rgba(59, 130, 246, 0.6));
}

/* 2. Selected Desk: Glowing Hijau Terang */
.desk-selected .desk-path {
    fill: #10b981 !important;
    stroke: #ffffff !important;
    stroke-width: 3.5 !important;
    filter: url(#glow-selected) !important;
}
.desk-selected .desk-num-text {
    font-size: 15px !important;
    fill: #ffffff !important;
}

/* 3. Pending Desk: Kuning Emas */
.desk-pending .desk-path {
    fill: #f59e0b !important;
    stroke: #d97706 !important;
    stroke-width: 1.5;
}
.table-interactive-group.desk-pending:hover .desk-path {
    fill: #fbbf24;
    stroke: #ffffff;
    stroke-width: 2.5;
}

/* 4. Approved / Occupied: Merah */
.desk-approved .desk-path {
    fill: #ef4444 !important;
    stroke: #b91c1c !important;
    stroke-width: 1.5;
    opacity: 0.85;
}
.desk-approved {
    cursor: not-allowed !important;
}

/* 5. Inactive: Abu-abu */
.desk-inactive .desk-path {
    fill: #94a3b8 !important;
    stroke: #64748b !important;
    cursor: not-allowed !important;
}

/* Slot button in schedule */
.slot-badge-btn {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.slot-badge-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

/* Floating Zoom Controls */
.map-zoom-controls {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 10;
    display: flex;
    flex-direction: column;
    gap: 4px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(4px);
    padding: 4px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    border: 1px solid #cbd5e1;
}
.map-zoom-controls button {
    width: 38px;
    height: 38px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    border-radius: 6px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #334155;
    transition: all 0.15s ease;
    user-select: none;
}
.map-zoom-controls button:hover, .map-zoom-controls button:active {
    background: #f1f5f9;
    color: #0f172a;
    border-color: #94a3b8;
}
</style>

<div class="row">
    <div class="col-12">
        <div id="js-alert-container"></div>
        <?php if (!$has_phone): ?>
            <div class="alert alert-warning border-warning shadow-sm p-3 mb-4 rounded-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div class="d-flex align-items-center">
                    <span class="fs-3 me-3">⚠️</span>
                    <div>
                        <strong class="text-dark">Nomor Telepon Belum Diisi di Profil!</strong>
                        <div class="small text-muted">Sistem mewajibkan nomor telepon aktif (WhatsApp) agar Anda dapat mengajukan peminjaman meja. Nomor ini akan tercantum pada pengajuan agar admin dapat menghubungi Anda.</div>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/profile.php?redirect=pinjam_ruangan" class="btn btn-warning fw-semibold text-dark text-nowrap">
                    Lengkapi No. Telp Sekarang ➔
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- KOLOM KIRI: DENAH MEJA LAB (INTERAKTIF PAN & ZOOM) + JADWAL LANGSUNG DI BAWAHNYA -->
    <div class="col-lg-7 col-xl-8 mb-4">
        <!-- CARD DENAH -->
        <div class="card shadow-sm p-3 p-md-4 mb-3">
            <div class="mb-3">
                <h4 class="mb-1 fw-bold text-dark">
                    <span>🗺️ Denah Meja Laboratorium</span>
                </h4>
                <p class="text-muted small mb-0">Klik meja untuk memilih / membatalkan pilihan. Bisa memilih lebih dari 1 meja.</p>
            </div>

            <!-- VIEWPORT WRAPPER DENAH DENGAN KONTROL ZOOM SENDIRI -->
            <div id="svg-viewport-wrapper">
                <!-- Floating Zoom Controls -->
                <div class="map-zoom-controls">
                    <button type="button" id="btn-zoom-in" title="Perbesar Denah (Zoom In)">🔍＋</button>
                    <button type="button" id="btn-zoom-out" title="Perkecil Denah (Zoom Out)">🔍－</button>
                    <button type="button" id="btn-zoom-reset" title="Kembalikan Tampilan Penuh (Reset)">⟲</button>
                </div>

                <!-- SVG FLOOR PLAN -->
                <svg id="lab-floor-plan" viewBox="0 0 900 870" width="100%" height="auto" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <filter id="desk-shadow" x="-10%" y="-10%" width="120%" height="120%">
                            <feDropShadow dx="0" dy="2" stdDeviation="2" flood-opacity="0.12"/>
                        </filter>
                        <filter id="glow-selected" x="-30%" y="-30%" width="160%" height="160%">
                            <feDropShadow dx="0" dy="0" stdDeviation="6" flood-color="#10b981" flood-opacity="0.95"/>
                        </filter>
                    </defs>

                    <!-- Room Base Background -->
                    <rect x="15" y="15" width="870" height="840" rx="16" fill="#f8fafc" stroke="#cbd5e1" stroke-width="2"/>
                    


                    <?php
                    $R = 74;
                    $r = 38;
                    $R_chair = 96;

                    foreach ($clusters as $c_id => $c) {
                        $cx = $c['cx'];
                        $cy = $c['cy'];

                        // Circular boundary
                        echo "<circle cx='{$cx}' cy='{$cy}' r='118' fill='#f1f5f9' stroke='#e2e8f0' stroke-width='1.5' stroke-dasharray='5 4'/>";

                        // Center Pod Hole Badge
                        echo "<circle cx='{$cx}' cy='{$cy}' r='30' fill='#ffffff' stroke='#cbd5e1' stroke-width='1.5'/>";
                        echo "<text x='{$cx}' y='" . ($cy - 5) . "' text-anchor='middle' dominant-baseline='central' font-size='10' font-weight='800' fill='#1e293b'>POD {$c_id}</text>";
                        echo "<text x='{$cx}' y='" . ($cy + 8) . "' text-anchor='middle' dominant-baseline='central' font-size='7.5' font-weight='600' fill='#64748b'>{$c['desc']}</text>";

                        foreach ($desk_segments as $s_idx => $s) {
                            $table_num = $c['start'] + $s_idx;

                            $pathD = getDeskArc($cx, $cy, $r, $R, $s['start'], $s['end']);
                            $textPos = getMidCoords($cx, $cy, ($R + $r) / 2, $s['start'], $s['end']);
                            $chairPos = getMidCoords($cx, $cy, $R_chair, $s['start'], $s['end']);
                            $rotAngle = $chairPos['angle'] - 90;

                            // Realistic Chair Graphic
                            echo "<g class='chair-graphic' transform='translate({$chairPos['x']}, {$chairPos['y']}) rotate({$rotAngle})' pointer-events='none'>";
                            echo "<rect x='-13' y='-5' width='3' height='10' rx='1.5' fill='#475569'/>";
                            echo "<rect x='10' y='-5' width='3' height='10' rx='1.5' fill='#475569'/>";
                            echo "<rect x='-11' y='-8' width='22' height='16' rx='4' fill='#64748b' stroke='#475569' stroke-width='1'/>";
                            echo "<path d='M -11 8 Q 0 14 11 8' stroke='#334155' stroke-width='3.5' stroke-linecap='round' fill='none'/>";
                            echo "</g>";

                            // Interactive Desk Group (Default table 1 selected)
                            $isFirst = ($table_num === 1);
                            $deskClass = $isFirst ? 'table-interactive-group desk-available desk-selected' : 'table-interactive-group desk-available';

                            echo "<g class='{$deskClass}' id='desk-group-{$table_num}' data-table-id='{$table_num}' data-table-name='Meja {$table_num}' data-cluster-name='Lingkaran {$c_id} ({$c['desc']})' data-cluster-id='{$c_id}'>";
                            echo "<title>Meja {$table_num} (Lingkaran {$c_id}) - Klik untuk memilih / batal</title>";
                            echo "<path d='{$pathD}' class='desk-path' filter='url(#desk-shadow)'/>";
                            echo "<text x='{$textPos['x']}' y='{$textPos['y']}' class='desk-num-text' text-anchor='middle' dominant-baseline='central'>{$table_num}</text>";
                            echo "</g>";
                        }
                    }
                    ?>
                </svg>
            </div>
            
            <div class="d-flex align-items-center justify-content-between text-muted small mt-2">
                <span><em>💡 Geser denah untuk menggeser. Cubit (pinch) di HP atau gunakan tombol ＋/－ untuk memperbesar denah.</em></span>
                <span id="zoom-level-text" class="badge bg-light text-secondary border">Zoom: 100%</span>
            </div>
        </div>

        <!-- CARD JADWAL KETERSEDIAAN (LANGSUNG DITARUH DI BAWAH DENAH) -->
        <div class="card shadow-sm p-3 p-md-4" id="schedule-container">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div>
                    <h5 class="mb-1 fw-bold text-dark">
                        📅 Jadwal Ketersediaan Meja
                    </h5>
                    <p class="text-muted small mb-0">Pilih tab meja di bawah untuk memeriksa jadwal ketersediaan slot waktu:</p>
                </div>
                <div class="small text-muted">
                    Tanggal: <strong id="schedule-date-label"><?= date('Y-m-d') ?></strong>
                </div>
            </div>

            <!-- Tab Switcher Meja Terpilih untuk Jadwal -->
            <div class="d-flex flex-wrap gap-1 mb-3 pt-2" id="schedule-table-tabs">
                <!-- Injected via JS -->
            </div>

            <div class="row g-2" id="schedule-grid">
                <!-- Slots will be injected here via JS -->
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: FORM PENGAJUAN PEMINJAMAN MEJA (MULTI-MEJA) -->
    <div class="col-lg-5 col-xl-4 mb-4">
        <div class="card shadow-sm p-4 sticky-top" style="top: 20px; z-index: 20;">
            <h4 class="mb-3 fw-bold text-dark">Form Peminjaman</h4>

            <!-- KARTU DAFTAR MEJA TERPILIH (MULTI-MEJA) -->
            <div class="p-3 mb-3 rounded border bg-light shadow-sm" id="box-selected-tables">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase">Daftar Meja Terpilih:</span>
                    <span id="selected-count-badge" class="badge bg-primary fs-6">1 Meja</span>
                </div>

                <!-- Tag List Meja Terpilih -->
                <div class="d-flex flex-wrap gap-1 mb-2" id="selected-tables-tags">
                    <!-- Badges injected via JS -->
                </div>

                <small class="text-muted d-block" id="display-help-text">
                    Klik meja pada denah untuk menambah atau membatalkan pilihan meja.
                </small>
            </div>

            <form method="POST" action="" id="booking-form">
                <!-- Hidden Inputs container for table_ids[] -->
                <div id="hidden-table-inputs">
                    <input type="hidden" name="table_ids[]" value="1">
                </div>

                <!-- Dropdown Tambah Meja Cepat -->
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Atau Pilih Tambah dari Dropdown:</label>
                    <div class="input-group">
                        <select id="table_id_select" class="form-select form-select-sm">
                            <option value="">-- Pilih meja untuk ditambah --</option>
                            <?php foreach($clusters as $cid => $cl): ?>
                                <optgroup label="POD <?= $cid ?> - <?= $cl['name'] ?> (<?= $cl['desc'] ?>)">
                                    <?php for($i = $cl['start']; $i < $cl['start'] + 5; $i++): ?>
                                        <option value="<?= $i ?>">Meja <?= $i ?> (<?= $cl['name'] ?>)</option>
                                    <?php endfor; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-table-dropdown">
                            ＋ Tambah
                        </button>
                    </div>
                </div>

                <!-- Tanggal Booking -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Tanggal Peminjaman</label>
                    <input type="date" name="booking_date" id="booking_date_input" class="form-control" required onclick="this.showPicker()"
                           value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                    <div class="form-text small">Peminjaman berlaku pada hari kerja (Senin - Jumat).</div>
                </div>

                <!-- Waktu Booking -->
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">Jam Mulai</label>
                        <select name="start_time" id="start_time_select" class="form-select" required>
                            <option value="">-- Mulai --</option>
                            <?php foreach($slots as $idx => $s): ?>
                                <?php if($idx < count($slots)-1): ?>
                                <option value="<?= $s ?>"><?= $s ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">Jam Selesai</label>
                        <select name="end_time" id="end_time_select" class="form-select" required>
                            <option value="">-- Selesai --</option>
                            <?php foreach($slots as $idx => $s): ?>
                                <?php if($idx > 0): ?>
                                <option value="<?= $s ?>"><?= $s ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="alert <?= $has_phone ? 'alert-light border' : 'alert-danger' ?> small mb-3 p-2 px-3 rounded-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Kontak Pemohon:</strong><br>
                            <?= htmlspecialchars($current_user['name']) ?> 
                            <?php if ($has_phone): ?>
                                (📞 <?= htmlspecialchars($user_phone) ?>)
                            <?php else: ?>
                                <span class="text-danger fw-bold">(Belum ada No. HP)</span>
                            <?php endif; ?>
                        </div>
                        <a href="<?= BASE_URL ?>/profile.php?redirect=pinjam_ruangan" class="small text-decoration-none">
                            <?= $has_phone ? 'Ubah' : 'Isi Sekarang' ?>
                        </a>
                    </div>
                </div>

                <?php if ($has_phone): ?>
                    <button type="submit" id="btn-submit" class="btn btn-primary w-100 py-2 fs-6 fw-bold shadow-sm">
                        Ajukan Peminjaman Meja
                    </button>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/profile.php?redirect=pinjam_ruangan" class="btn btn-warning w-100 py-2 fs-6 fw-bold shadow-sm text-dark">
                        ⚠️ Lengkapi No. HP untuk Meminjam
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tableSelect = document.getElementById('table_id_select');
    const dateInput = document.getElementById('booking_date_input');
    const startTimeSelect = document.getElementById('start_time_select');
    const endTimeSelect = document.getElementById('end_time_select');
    const form = document.getElementById('booking-form');
    
    const scheduleDateLabel = document.getElementById('schedule-date-label');
    const scheduleGrid = document.getElementById('schedule-grid');
    const scheduleTabsContainer = document.getElementById('schedule-table-tabs');

    let currentScheduleData = [];

    // State for multi-table selection
    let selectedTables = [
        { id: 1, name: 'Meja 1', clusterName: 'Lingkaran 1 (Kiri Depan)' }
    ];
    let activeScheduleTableId = 1;

    // Auto-dismiss alert
    setTimeout(() => {
        document.querySelectorAll('.container > .alert.alert-success').forEach(alert => {
            alert.remove();
        });
    }, 5000);

    function showAlert(message) {
        document.getElementById('js-alert-container').innerHTML = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
    }

    // ==========================================
    // 1. PAN & ZOOM ENGINE (MOUSE & TOUCH PINCH)
    // ==========================================
    const svg = document.getElementById('lab-floor-plan');
    const wrapper = document.getElementById('svg-viewport-wrapper');
    const zoomLevelText = document.getElementById('zoom-level-text');

    const orig = { w: 900, h: 870 };
    let view = { x: 0, y: 0, w: 900, h: 870 };

    function updateViewBox() {
        // Clamp dimensions: max zoom in = 200px width, max zoom out = 900px
        view.w = Math.max(200, Math.min(orig.w, view.w));
        view.h = view.w * (orig.h / orig.w);

        // Clamp coordinates
        view.x = Math.max(-40, Math.min(orig.w - view.w + 40, view.x));
        view.y = Math.max(-40, Math.min(orig.h - view.h + 40, view.y));

        svg.setAttribute('viewBox', `${view.x.toFixed(1)} ${view.y.toFixed(1)} ${view.w.toFixed(1)} ${view.h.toFixed(1)}`);
        
        const zoomPercent = Math.round((orig.w / view.w) * 100);
        if (zoomLevelText) {
            zoomLevelText.textContent = `Zoom: ${zoomPercent}%`;
        }
    }

    function zoomByFactor(factor, centerX, centerY) {
        const rect = svg.getBoundingClientRect();
        const pivotX = (centerX !== undefined) ? centerX : rect.left + rect.width / 2;
        const pivotY = (centerY !== undefined) ? centerY : rect.top + rect.height / 2;

        const svgPivotX = view.x + ((pivotX - rect.left) / rect.width) * view.w;
        const svgPivotY = view.y + ((pivotY - rect.top) / rect.height) * view.h;

        const newW = view.w * factor;
        const newH = newW * (orig.h / orig.w);

        view.x = svgPivotX - ((pivotX - rect.left) / rect.width) * newW;
        view.y = svgPivotY - ((pivotY - rect.top) / rect.height) * newH;
        view.w = newW;
        updateViewBox();
    }

    document.getElementById('btn-zoom-in').addEventListener('click', (e) => {
        e.stopPropagation();
        zoomByFactor(0.75);
    });

    document.getElementById('btn-zoom-out').addEventListener('click', (e) => {
        e.stopPropagation();
        zoomByFactor(1.33);
    });

    document.getElementById('btn-zoom-reset').addEventListener('click', (e) => {
        e.stopPropagation();
        view = { x: 0, y: 0, w: 900, h: 870 };
        updateViewBox();
    });

    // Mouse Wheel Zoom
    wrapper.addEventListener('wheel', (e) => {
        e.preventDefault();
        const factor = e.deltaY < 0 ? 0.85 : 1.18;
        zoomByFactor(factor, e.clientX, e.clientY);
    }, { passive: false });

    // Pointer / Mouse Drag & Pan
    let isDragging = false;
    let hasMoved = false;
    let dragStart = { x: 0, y: 0 };

    wrapper.addEventListener('pointerdown', (e) => {
        if (e.target.closest('.map-zoom-controls')) return;
        isDragging = true;
        hasMoved = false;
        dragStart = { x: e.clientX, y: e.clientY };
    });

    window.addEventListener('pointermove', (e) => {
        if (!isDragging) return;
        const dx = e.clientX - dragStart.x;
        const dy = e.clientY - dragStart.y;
        if (Math.hypot(dx, dy) > 6) {
            hasMoved = true;
        }
        if (hasMoved) {
            const rect = svg.getBoundingClientRect();
            view.x -= dx * (view.w / rect.width);
            view.y -= dy * (view.h / rect.height);
            dragStart = { x: e.clientX, y: e.clientY };
            updateViewBox();
        }
    });

    window.addEventListener('pointerup', () => {
        isDragging = false;
    });

    // Touch Pinch-to-Zoom
    let initialPinchDist = null;
    let initialPinchW = 900;
    let pinchMid = { x: 0, y: 0 };

    wrapper.addEventListener('touchstart', (e) => {
        if (e.touches.length === 2) {
            isDragging = false;
            hasMoved = true;
            initialPinchDist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            initialPinchW = view.w;
            pinchMid = {
                x: (e.touches[0].clientX + e.touches[1].clientX) / 2,
                y: (e.touches[0].clientY + e.touches[1].clientY) / 2
            };
        }
    }, { passive: true });

    wrapper.addEventListener('touchmove', (e) => {
        if (e.touches.length === 2 && initialPinchDist) {
            hasMoved = true;
            const currentDist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            if (currentDist > 8) {
                const factor = initialPinchDist / currentDist;
                const rect = svg.getBoundingClientRect();
                const svgMidX = view.x + ((pinchMid.x - rect.left) / rect.width) * view.w;
                const svgMidY = view.y + ((pinchMid.y - rect.top) / rect.height) * view.h;

                const newW = initialPinchW * factor;
                const newH = newW * (orig.h / orig.w);

                view.w = newW;
                view.x = svgMidX - ((pinchMid.x - rect.left) / rect.width) * newW;
                view.y = svgMidY - ((pinchMid.y - rect.top) / rect.height) * newH;
                updateViewBox();
            }
        }
    }, { passive: true });

    wrapper.addEventListener('touchend', (e) => {
        if (e.touches.length < 2) {
            initialPinchDist = null;
        }
    });

    // ==========================================
    // 2. MULTI-TABLE SELECTION LOGIC
    // ==========================================
    function renderSelectedTablesUI() {
        const countBadge = document.getElementById('selected-count-badge');
        const tagsContainer = document.getElementById('selected-tables-tags');
        const hiddenContainer = document.getElementById('hidden-table-inputs');
        const submitBtn = document.getElementById('btn-submit');

        countBadge.textContent = `${selectedTables.length} Meja`;
        countBadge.className = selectedTables.length > 0 ? 'badge bg-primary fs-6' : 'badge bg-secondary fs-6';

        tagsContainer.innerHTML = '';
        hiddenContainer.innerHTML = '';

        if (selectedTables.length === 0) {
            tagsContainer.innerHTML = '<span class="text-danger small fst-italic">Belum ada meja dipilih. Klik meja pada denah untuk memilih.</span>';
            submitBtn.textContent = 'Pilih Meja Dahulu';
            submitBtn.disabled = true;
            scheduleTabsContainer.innerHTML = '<span class="text-muted small">Pilih meja pada denah untuk melihat jadwal ketersediaannya.</span>';
            scheduleGrid.innerHTML = '<div class="col-12 text-center py-3 text-muted">Belum ada meja yang dipilih.</div>';
            return;
        }

        submitBtn.disabled = false;
        submitBtn.textContent = `Ajukan Peminjaman (${selectedTables.length} Meja)`;

        // Render Badges and Hidden Inputs
        selectedTables.forEach(t => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'table_ids[]';
            inp.value = t.id;
            hiddenContainer.appendChild(inp);

            const tag = document.createElement('span');
            tag.className = 'badge bg-success py-2 px-3 fs-6 d-inline-flex align-items-center gap-2 shadow-sm rounded-pill';
            tag.innerHTML = `
                <span>${t.name}</span>
                <span role="button" class="cursor-pointer text-white fw-bold ms-1" title="Hapus ${t.name}" style="cursor:pointer; font-size:12px;">✕</span>
            `;
            tag.querySelector('span[role=button]').addEventListener('click', (e) => {
                e.stopPropagation();
                toggleTable(t.id, t.name, t.clusterName);
            });
            tagsContainer.appendChild(tag);
        });

        // Ensure activeScheduleTableId is valid
        if (!selectedTables.some(t => t.id === activeScheduleTableId)) {
            activeScheduleTableId = selectedTables[0].id;
        }

        // Render Schedule Table Switcher Tabs
        scheduleTabsContainer.innerHTML = '';
        selectedTables.forEach(t => {
            const isActive = (t.id === activeScheduleTableId);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `btn btn-sm ${isActive ? 'btn-primary' : 'btn-outline-secondary'}`;
            btn.innerHTML = `${t.name} ${isActive ? '✓ (Aktif)' : ''}`;
            btn.addEventListener('click', () => {
                activeScheduleTableId = t.id;
                renderSelectedTablesUI();
                loadTableSchedule();
            });
            scheduleTabsContainer.appendChild(btn);
        });
    }

    function toggleTable(id, name, clusterName) {
        const idx = selectedTables.findIndex(t => t.id === id);
        const group = document.getElementById('desk-group-' + id);

        if (idx > -1) {
            // Deselect
            selectedTables.splice(idx, 1);
            if (group) group.classList.remove('desk-selected');
        } else {
            // Select
            selectedTables.push({ id, name, clusterName });
            if (group) group.classList.add('desk-selected');
            activeScheduleTableId = id;
        }

        renderSelectedTablesUI();
        if (selectedTables.length > 0) {
            loadTableSchedule();
        }
    }

    // Attach click listeners to all desk groups in the SVG map
    document.querySelectorAll('.table-interactive-group').forEach(group => {
        group.addEventListener('click', function(e) {
            // If dragging or pinching map, don't trigger selection!
            if (hasMoved) return;

            const id = parseInt(this.getAttribute('data-table-id'));
            const name = this.getAttribute('data-table-name');
            const clusterName = this.getAttribute('data-cluster-name');

            // Prevent selecting if occupied/inactive
            if (this.classList.contains('desk-approved')) {
                showAlert(`${name} sudah terisi/disetujui pada jam tersebut. Silakan pilih meja lain yang berwarna biru.`);
                return;
            }
            if (this.classList.contains('desk-inactive')) {
                showAlert(`${name} sedang tidak aktif.`);
                return;
            }

            toggleTable(id, name, clusterName);
        });
    });

    // Add table from dropdown
    document.getElementById('btn-add-table-dropdown').addEventListener('click', function() {
        const id = parseInt(tableSelect.value);
        if (!id) return;
        const group = document.getElementById('desk-group-' + id);
        if (group) {
            if (group.classList.contains('desk-approved')) {
                showAlert(`Meja ${id} sudah terisi/disetujui pada jam tersebut.`);
                return;
            }
            const name = group.getAttribute('data-table-name');
            const clusterName = group.getAttribute('data-cluster-name');
            if (!selectedTables.some(t => t.id === id)) {
                toggleTable(id, name, clusterName);
            }
            tableSelect.value = '';
        }
    });

    // Load full-day schedule for the active schedule table
    function loadTableSchedule() {
        if (!activeScheduleTableId) return;
        const date = dateInput.value;
        if (!date) return;

        scheduleDateLabel.textContent = date;
        scheduleGrid.innerHTML = '<div class="col-12 text-center py-3 text-muted"><span class="spinner-border spinner-border-sm text-primary"></span> Memuat jadwal Meja ' + activeScheduleTableId + '...</div>';

        fetch(`<?= BASE_URL ?>/ajax/get_room_schedule.php?table_id=${activeScheduleTableId}&date=${date}`)
            .then(res => res.json())
            .then(data => {
                scheduleGrid.innerHTML = '';
                currentScheduleData = data;

                if (data.length === 0) {
                    scheduleGrid.innerHTML = '<div class="col-12 text-center text-muted">Jadwal tidak tersedia.</div>';
                    return;
                }

                data.forEach(slot => {
                    const col = document.createElement('div');
                    col.className = 'col-6 col-md-4 col-lg-3';
                    const isKosong = slot.status === 'kosong';
                    col.innerHTML = `
                        <div class="p-2 rounded text-center small border ${slot.color} slot-badge-btn ${isKosong ? 'clickable' : ''}" 
                             data-time-start="${slot.time.split(' - ')[0]}" 
                             data-time-end="${slot.time.split(' - ')[1]}"
                             style="${isKosong ? 'cursor:pointer;' : ''}">
                            <strong class="d-block">${slot.time}</strong>
                            <span>${slot.label}</span>
                        </div>
                    `;

                    // If slot is kosong, clicking it auto-fills start & end time
                    if (isKosong) {
                        col.querySelector('.slot-badge-btn').addEventListener('click', function() {
                            const start = this.getAttribute('data-time-start');
                            const end = this.getAttribute('data-time-end');
                            startTimeSelect.value = start;
                            endTimeSelect.value = end;
                            updateMapAvailability();
                        });
                    }

                    scheduleGrid.appendChild(col);
                });
            })
            .catch(err => {
                scheduleGrid.innerHTML = '<div class="col-12 text-center text-danger">Gagal memuat jadwal meja.</div>';
            });
    }

    // Update real-time availability colors across all 25 tables on the floor plan
    function updateMapAvailability() {
        const date = dateInput.value;
        const startTime = startTimeSelect.value;
        const endTime = endTimeSelect.value;

        if (!date) return;

        fetch(`<?= BASE_URL ?>/ajax/get_tables_status.php?date=${date}&start_time=${startTime}&end_time=${endTime}`)
            .then(res => res.json())
            .then(data => {
                let countAvailable = 0;
                let countPending = 0;
                let countApproved = 0;

                document.querySelectorAll('.table-interactive-group').forEach(group => {
                    const id = parseInt(group.getAttribute('data-table-id'));
                    const info = data[id];
                    const status = info ? info.status : 'available';

                    group.classList.remove('desk-available', 'desk-pending', 'desk-approved', 'desk-inactive');
                    group.classList.add(`desk-${status}`);

                    if (status === 'available') countAvailable++;
                    else if (status === 'pending') countPending++;
                    else if (status === 'approved') countApproved++;
                });

                // Availability counts calculated
                const elAvail = document.getElementById('map-summary-available');
                if (elAvail) elAvail.textContent = countAvailable;
                const elPend = document.getElementById('map-summary-pending');
                if (elPend) elPend.textContent = countPending;
                const elAppr = document.getElementById('map-summary-approved');
                if (elAppr) elAppr.textContent = countApproved;

                // Re-apply selected class to all currently selected tables
                selectedTables.forEach(t => {
                    const selGroup = document.getElementById('desk-group-' + t.id);
                    if (selGroup) {
                        selGroup.classList.add('desk-selected');
                    }
                });
            })
            .catch(err => console.error(err));
    }

    // Date change listener
    dateInput.addEventListener('change', function() {
        if (this.value) {
            const dateObj = new Date(this.value);
            const day = dateObj.getDay();
            if (day === 0 || day === 6) {
                showAlert('Peminjaman ruangan tidak diizinkan pada hari Sabtu dan Minggu.');
                this.value = '';
                return;
            }
        }
        updateMapAvailability();
        loadTableSchedule();
    });

    // Time change listeners
    startTimeSelect.addEventListener('change', updateMapAvailability);
    endTimeSelect.addEventListener('change', updateMapAvailability);

    // Form submit validation
    form.addEventListener('submit', function(e) {
        if (selectedTables.length === 0) {
            e.preventDefault();
            showAlert('Silakan pilih minimal 1 meja terlebih dahulu pada denah.');
            return;
        }

        const startTime = startTimeSelect.value;
        const endTime = endTimeSelect.value;

        if (startTime >= endTime) {
            e.preventDefault();
            showAlert('Jam mulai harus sebelum jam selesai.');
            return;
        }

        // Check if any selected desk is busy
        let hasConflict = false;
        selectedTables.forEach(t => {
            const selGroup = document.getElementById('desk-group-' + t.id);
            if (selGroup && selGroup.classList.contains('desk-approved')) {
                hasConflict = true;
            }
        });

        if (hasConflict) {
            e.preventDefault();
            showAlert('Salah satu meja yang Anda pilih sudah terisi pada jam tersebut. Silakan batalkan pilihan meja tersebut.');
            return;
        }
    });

    // Initial render
    renderSelectedTablesUI();
    loadTableSchedule();
    updateMapAvailability();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
