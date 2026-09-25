<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$tool_id = $_GET['tool_id'] ?? '';
$date = $_GET['date'] ?? '';

if (empty($tool_id) || empty($date)) {
    exit(json_encode([]));
}

// Get total stock
$stmt_tool = $pdo->prepare("SELECT total_stock FROM tools WHERE id = ?");
$stmt_tool->execute([$tool_id]);
$tool = $stmt_tool->fetch();
$total_stock = $tool ? (int)$tool['total_stock'] : 0;

// Generate time slots
$slots = getTimeSlots();

// Initialize slots
$schedule = [];
foreach ($slots as $idx => $slot) {
    if ($idx < count($slots) - 1) { 
        $schedule[$slot] = [
            'time' => $slot . ' - ' . $slots[$idx+1],
            'booked_pending' => 0,
            'booked_approved' => 0,
            'available' => $total_stock,
            'total' => $total_stock,
            'color' => 'bg-success text-white' // Hijau untuk full tersedia
        ];
    }
}

// Fetch bookings
$stmt = $pdo->prepare("
    SELECT start_time, end_time, status, quantity 
    FROM tool_bookings 
    WHERE tool_id = ? AND booking_date = ? AND status IN ('pending', 'approved')
");
$stmt->execute([$tool_id, $date]);
$bookings = $stmt->fetchAll();

foreach ($bookings as $b) {
    $start = substr($b['start_time'], 0, 5); 
    $end = substr($b['end_time'], 0, 5); 
    $status = $b['status'];
    $qty = (int)$b['quantity'];

    $in_range = false;
    foreach ($slots as $idx => $slot) {
        if ($idx >= count($slots) - 1) continue;
        
        if ($slot === $start) {
            $in_range = true;
        }
        
        if ($in_range) {
            if ($status === 'pending') {
                $schedule[$slot]['booked_pending'] += $qty;
            } else {
                $schedule[$slot]['booked_approved'] += $qty;
            }
            $schedule[$slot]['available'] = $total_stock - $schedule[$slot]['booked_pending'] - $schedule[$slot]['booked_approved'];
            
            // Set color based on availability
            if ($schedule[$slot]['available'] <= 0) {
                $schedule[$slot]['color'] = 'bg-danger text-white'; // Merah jika habis
            } elseif ($schedule[$slot]['booked_pending'] > 0 || $schedule[$slot]['booked_approved'] > 0) {
                $schedule[$slot]['color'] = 'bg-warning text-dark'; // Kuning jika sebagian dipinjam/pending
            }
        }
        
        if ($slots[$idx+1] === $end) {
            $in_range = false;
        }
    }
}

header('Content-Type: application/json');
echo json_encode(array_values($schedule));
