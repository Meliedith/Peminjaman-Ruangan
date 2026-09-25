<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$table_id = $_GET['table_id'] ?? '';
$date = $_GET['date'] ?? '';

if (empty($table_id) || empty($date)) {
    exit(json_encode([]));
}

// Generate time slots
$slots = getTimeSlots();

// Initialize all slots as 'kosong'
$schedule = [];
foreach ($slots as $idx => $slot) {
    if ($idx < count($slots) - 1) { // exclude 16:00 as start time
        $schedule[$slot] = [
            'time' => $slot . ' - ' . $slots[$idx+1],
            'status' => 'kosong',
            'label' => 'Kosong',
            'color' => 'bg-success text-white' // Hijau untuk kosong
        ];
    }
}

// Fetch bookings for this table on this date
$stmt = $pdo->prepare("
    SELECT start_time, end_time, status 
    FROM room_bookings 
    WHERE table_id = ? AND booking_date = ? AND status IN ('pending', 'approved')
");
$stmt->execute([$table_id, $date]);
$bookings = $stmt->fetchAll();

foreach ($bookings as $b) {
    $start = substr($b['start_time'], 0, 5); // 08:00
    $end = substr($b['end_time'], 0, 5); // 09:30
    $status = $b['status'];

    // Merah untuk terisi/approved, Kuning untuk pending
    $color = ($status === 'approved') ? 'bg-danger text-white' : 'bg-warning text-dark';
    $label = ($status === 'approved') ? 'Terisi' : 'Menunggu';

    // Mark the slots
    $in_range = false;
    foreach ($slots as $idx => $slot) {
        if ($idx >= count($slots) - 1) continue;
        
        if ($slot === $start) {
            $in_range = true;
        }
        
        if ($in_range) {
            $schedule[$slot]['status'] = $status;
            $schedule[$slot]['label'] = $label;
            $schedule[$slot]['color'] = $color;
        }
        
        if ($slots[$idx+1] === $end) {
            $in_range = false;
        }
    }
}

header('Content-Type: application/json');
echo json_encode(array_values($schedule));
