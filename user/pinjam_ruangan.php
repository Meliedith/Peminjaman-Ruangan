<?php
// user/pinjam_ruangan.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('user');

// Fetch active tables
$stmt = $pdo->query("SELECT id, name FROM tables WHERE is_active = 1");
$tables = $stmt->fetchAll();

$slots = getTimeSlots(); // from functions.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_id = $_POST['table_id'] ?? '';
    $booking_date = $_POST['booking_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    // Basic Validation
    $today = date('Y-m-d');
    $max_date = date('Y-m-d', strtotime('+30 days'));

    if ($booking_date < $today || $booking_date > $max_date) {
        setFlashMessage('danger', 'Tanggal booking harus hari ini atau maksimal 30 hari ke depan.');
    } elseif (date('N', strtotime($booking_date)) >= 6) {
        setFlashMessage('danger', 'Peminjaman ruangan tidak diizinkan pada hari Sabtu dan Minggu.');
    } elseif ($start_time >= $end_time) {
        setFlashMessage('danger', 'Jam mulai harus sebelum jam selesai.');
    } elseif ($booking_date === $today && $end_time <= date('H:i')) {
        setFlashMessage('danger', 'Waktu peminjaman sudah berlalu. Anda hanya bisa memesan slot yang belum berakhir.');
    } else {
        // Overlap Validation
        // A booking overlaps if (new_start < existing_end) AND (new_end > existing_start)
        // Only check pending and approved
        $stmt_check = $pdo->prepare("
            SELECT id FROM room_bookings 
            WHERE table_id = ? 
            AND booking_date = ? 
            AND status IN ('pending', 'approved')
            AND start_time < ? 
            AND end_time > ?
        ");
        $stmt_check->execute([$table_id, $booking_date, $end_time, $start_time]);
        
        if ($stmt_check->rowCount() > 0) {
            setFlashMessage('danger', 'Jadwal yang dipilih bentrok dengan booking lain (sudah dipesan/menunggu persetujuan).');
        } else {
            // Insert Booking
            $stmt_insert = $pdo->prepare("
                INSERT INTO room_bookings (user_id, table_id, booking_date, start_time, end_time, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            
            if ($stmt_insert->execute([$_SESSION['user_id'], $table_id, $booking_date, $start_time, $end_time])) {
                setFlashMessage('success', 'Pengajuan peminjaman ruangan berhasil dikirim dan menunggu persetujuan.');
                redirect('/user/riwayat.php');
            } else {
                setFlashMessage('danger', 'Terjadi kesalahan sistem.');
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
            <h3 class="mb-4">Pinjam Ruangan (Meja Lab)</h3>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Pilih Meja</label>
                    <select name="table_id" class="form-select" required>
                        <option value="">-- Pilih Meja --</option>
                        <?php foreach($tables as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Tanggal</label>
                    <input type="date" name="booking_date" class="form-control" required 
                           min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+30 days')) ?>">
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

                <button type="submit" class="btn btn-primary w-100 mt-3">Ajukan Peminjaman</button>
            </form>
        </div>
        
        <!-- Schedule Visualizer Container -->
        <div class="card shadow-sm p-4 mt-4" id="schedule-container" style="display: none;">
            <h5 class="mb-3">Jadwal Meja (<span id="schedule-date-label"></span>)</h5>
            <div class="row g-2" id="schedule-grid">
                <!-- Slots will be injected here via JS -->
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tableSelect = document.querySelector('select[name="table_id"]');
    const dateInput = document.querySelector('input[name="booking_date"]');
    const container = document.getElementById('schedule-container');
    const grid = document.getElementById('schedule-grid');
    const dateLabel = document.getElementById('schedule-date-label');
    const form = document.querySelector('form');
    
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
        const table_id = tableSelect.value;
        const date = dateInput.value;
        currentScheduleData = [];

        if (table_id && date) {
            dateLabel.textContent = date;
            container.style.display = 'block';
            grid.innerHTML = '<div class="col-12 text-center text-muted">Memuat jadwal...</div>';

            fetch(`<?= BASE_URL ?>/ajax/get_room_schedule.php?table_id=${table_id}&date=${date}`)
                .then(res => res.json())
                .then(data => {
                    grid.innerHTML = '';
                    currentScheduleData = data;
                    
                    if (data.length === 0) {
                        grid.innerHTML = '<div class="col-12 text-center text-muted">Data jadwal tidak ditemukan.</div>';
                        return;
                    }
                    
                    data.forEach(slot => {
                        grid.innerHTML += `
                            <div class="col-md-4 col-sm-6">
                                <div class="p-2 rounded text-center small ${slot.color}">
                                    <strong>${slot.time}</strong><br>
                                    ${slot.label}
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

    tableSelect.addEventListener('change', loadSchedule);
    
    dateInput.addEventListener('change', function() {
        if (this.value) {
            const dateObj = new Date(this.value);
            const day = dateObj.getDay();
            if (day === 0 || day === 6) {
                showAlert('Peminjaman ruangan tidak diizinkan pada hari Sabtu dan Minggu.');
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

        if (startTime >= endTime) {
            e.preventDefault();
            showAlert('Jam mulai harus sebelum jam selesai.');
            return;
        }

        // Check overlap if schedule data is loaded
        if (currentScheduleData.length > 0) {
            let isOverlap = false;
            let inRange = false;
            
            for (let i = 0; i < currentScheduleData.length; i++) {
                const slotStart = currentScheduleData[i].time.split(' - ')[0];
                const slotEnd = currentScheduleData[i].time.split(' - ')[1];
                
                if (slotStart === startTime) {
                    inRange = true;
                }
                
                if (inRange) {
                    if (currentScheduleData[i].status !== 'kosong') {
                        isOverlap = true;
                        break;
                    }
                }
                
                if (slotEnd === endTime) {
                    inRange = false;
                    break;
                }
            }

            if (isOverlap) {
                e.preventDefault();
                showAlert('Jadwal yang dipilih bentrok dengan booking lain (sudah dipesan/menunggu persetujuan).');
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
