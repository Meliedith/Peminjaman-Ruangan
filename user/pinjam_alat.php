<?php
// user/pinjam_alat.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('user');

// Fetch active tools and only show available/good ones
$stmt = $pdo->query("SELECT id, name, total_stock FROM tools WHERE is_active = 1 AND total_stock > 0 AND kondisi = 'Baik'");
$tools = $stmt->fetchAll();

$slots = getTimeSlots(); // from functions.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tool_id = $_POST['tool_id'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $booking_date = $_POST['booking_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    $today = date('Y-m-d');
    $max_date = date('Y-m-d', strtotime('+30 days'));

    if ($quantity <= 0) {
        setFlashMessage('danger', 'Jumlah pinjam harus lebih dari 0.');
    } elseif ($booking_date < $today || $booking_date > $max_date) {
        setFlashMessage('danger', 'Tanggal booking harus hari ini atau maksimal 30 hari ke depan.');
    } elseif (date('N', strtotime($booking_date)) >= 6) {
        setFlashMessage('danger', 'Peminjaman alat tidak diizinkan pada hari Sabtu dan Minggu.');
    } elseif ($start_time >= $end_time) {
        setFlashMessage('danger', 'Jam mulai harus sebelum jam selesai.');
    } elseif ($booking_date === $today && $end_time <= date('H:i')) {
        setFlashMessage('danger', 'Waktu peminjaman sudah berlalu. Anda hanya bisa memesan slot yang belum berakhir.');
    } else {
        // Dynamic stock calculation: total_stock - (sum of quantity of overlapping bookings)
        $stmt_tool = $pdo->prepare("SELECT total_stock FROM tools WHERE id = ?");
        $stmt_tool->execute([$tool_id]);
        $tool = $stmt_tool->fetch();
        
        if (!$tool) {
            setFlashMessage('danger', 'Alat tidak ditemukan.');
        } else {
            $total_stock = $tool['total_stock'];
            
            // Overlap check query
            $stmt_overlap = $pdo->prepare("
                SELECT SUM(quantity) as booked_qty FROM tool_bookings 
                WHERE tool_id = ? 
                AND booking_date = ? 
                AND status IN ('pending', 'approved')
                AND start_time < ? 
                AND end_time > ?
            ");
            $stmt_overlap->execute([$tool_id, $booking_date, $end_time, $start_time]);
            $booked_res = $stmt_overlap->fetch();
            $booked_qty = $booked_res['booked_qty'] ?? 0;
            
            $available_stock = $total_stock - $booked_qty;
            
            if ($quantity > $available_stock) {
                setFlashMessage('danger', "Stok tidak mencukupi untuk rentang waktu tersebut. Sisa stok: $available_stock.");
            } else {
                // Insert Booking
                $stmt_insert = $pdo->prepare("
                    INSERT INTO tool_bookings (user_id, tool_id, quantity, booking_date, start_time, end_time, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ");
                
                if ($stmt_insert->execute([$_SESSION['user_id'], $tool_id, $quantity, $booking_date, $start_time, $end_time])) {
                    setFlashMessage('success', 'Pengajuan peminjaman alat berhasil dikirim dan menunggu persetujuan.');
                    redirect('/user/riwayat.php');
                } else {
                    setFlashMessage('danger', 'Terjadi kesalahan sistem.');
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-md-8 offset-md-2">
        <div id="js-alert-container"></div>
        <div class="card shadow-sm p-4">
            <h3 class="mb-4">Pinjam Alat Lab</h3>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Pilih Alat</label>
                    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
                    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
                    <select name="tool_id" id="tool_id" class="form-select" required>
                        <option value="">-- Pilih Alat --</option>
                        <?php foreach($tools as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Tanggal</label>
                    <input type="date" name="booking_date" class="form-control" required onclick="this.showPicker()"
                           min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Jumlah Pinjam</label>
                    <input type="number" name="quantity" class="form-control" required min="1">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Jam Mulai</label>
                        <select name="start_time" class="form-select" required>
                            <option value="">-- Jam Mulai --</option>
                            <?php foreach($slots as $idx => $s): ?>
                                <?php if($idx < count($slots)-1): ?>
                                <option value="<?= $s ?>"><?= $s ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Jam Selesai</label>
                        <select name="end_time" class="form-select" required>
                            <option value="">-- Jam Selesai --</option>
                            <?php foreach($slots as $idx => $s): ?>
                                <?php if($idx > 0): ?>
                                <option value="<?= $s ?>"><?= $s ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success w-100 mt-3">Ajukan Peminjaman Alat</button>
            </form>
        </div>
        
        <!-- Schedule Visualizer Container -->
        <div class="card shadow-sm p-4 mt-4" id="schedule-container" style="display: none;">
            <h5 class="mb-3">Ketersediaan Alat (<span id="schedule-date-label"></span>)</h5>
            <div class="row g-2" id="schedule-grid">
                <!-- Slots will be injected here via JS -->
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toolSelect = document.querySelector('select[name="tool_id"]');
    const dateInput = document.querySelector('input[name="booking_date"]');
    const container = document.getElementById('schedule-container');
    const grid = document.getElementById('schedule-grid');
    const dateLabel = document.getElementById('schedule-date-label');
    const form = document.querySelector('form');
    
    // Initialize Tom Select for searchable dropdown
    let tomSelectInstance = new TomSelect('#tool_id', {
        create: false,
        sortField: {
            field: "text",
            direction: "asc"
        }
    });

    let currentScheduleData = [];

    // Auto-dismiss server-side flash messages after 3 seconds
    setTimeout(() => {
        document.querySelectorAll('.container > .alert').forEach(alert => {
            alert.remove();
        });
    }, 3000);

    function showAlert(message) {
        document.getElementById('js-alert-container').innerHTML = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        setTimeout(() => {
            document.getElementById('js-alert-container').innerHTML = '';
        }, 3000);
    }

    function loadSchedule() {
        const tool_id = toolSelect.value;
        const date = dateInput.value;
        currentScheduleData = [];

        if (tool_id && date) {
            dateLabel.textContent = date;
            container.style.display = 'block';
            grid.innerHTML = '<div class="col-12 text-center text-muted">Memuat jadwal ketersediaan...</div>';

            fetch(`<?= BASE_URL ?>/ajax/get_tool_schedule.php?tool_id=${tool_id}&date=${date}`)
                .then(res => res.json())
                .then(data => {
                    grid.innerHTML = '';
                    currentScheduleData = data;
                    
                    if (data.length === 0) {
                        grid.innerHTML = '<div class="col-12 text-center text-muted">Data jadwal tidak ditemukan.</div>';
                        return;
                    }
                    
                    data.forEach(slot => {
                        let label = `Tersedia: ${slot.available}/${slot.total}`;
                        
                        grid.innerHTML += `
                            <div class="col-md-4 col-sm-6">
                                <div class="p-2 rounded text-center small ${slot.color}">
                                    <strong>${slot.time}</strong><br>
                                    ${label}
                                </div>
                            </div>
                        `;
                    });
                })
                .catch(err => {
                    grid.innerHTML = '<div class="col-12 text-center text-danger">Gagal memuat jadwal.</div>';
                });
        } else {
            container.style.display = 'none';
        }
    }

    toolSelect.addEventListener('change', loadSchedule);
    
    dateInput.addEventListener('change', function() {
        if (this.value) {
            const dateObj = new Date(this.value);
            const day = dateObj.getDay();
            if (day === 0 || day === 6) {
                showAlert('Peminjaman alat tidak diizinkan pada hari Sabtu dan Minggu.');
                this.value = '';
                container.style.display = 'none';
                return;
            }
        }
        loadSchedule();
    });

    form.addEventListener('submit', function(e) {
        const startTime = document.querySelector('select[name="start_time"]').value;
        const endTime = document.querySelector('select[name="end_time"]').value;
        const quantity = parseInt(document.querySelector('input[name="quantity"]').value, 10);

        if (startTime >= endTime) {
            e.preventDefault();
            showAlert('Jam mulai harus sebelum jam selesai.');
            return;
        }

        // Check availability if schedule data is loaded
        if (currentScheduleData.length > 0) {
            let notEnoughStock = false;
            let minAvailable = null;
            let inRange = false;
            
            for (let i = 0; i < currentScheduleData.length; i++) {
                const slotStart = currentScheduleData[i].time.split(' - ')[0];
                const slotEnd = currentScheduleData[i].time.split(' - ')[1];
                
                if (slotStart === startTime) {
                    inRange = true;
                }
                
                if (inRange) {
                    if (currentScheduleData[i].available < quantity) {
                        notEnoughStock = true;
                        minAvailable = currentScheduleData[i].available;
                        break;
                    }
                }
                
                if (slotEnd === endTime) {
                    inRange = false;
                    break;
                }
            }

            if (notEnoughStock) {
                e.preventDefault();
                showAlert(`Stok tidak mencukupi untuk rentang waktu tersebut. Sisa stok: ${minAvailable}.`);
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
