<?php
// ajax/get_tables_status.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$date = $_GET['date'] ?? '';
$start_time = $_GET['start_time'] ?? '';
$end_time = $_GET['end_time'] ?? '';

// Fetch all active tables
$stmt = $pdo->query("SELECT id, name, is_active FROM tables ORDER BY CAST(SUBSTRING(name, 5) AS UNSIGNED) ASC");
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

$booked = [];
if (!empty($date) && !empty($start_time) && !empty($end_time)) {
    $stmt_b = $pdo->prepare("
        SELECT table_id, status 
        FROM room_bookings 
        WHERE booking_date = ? 
        AND status IN ('pending', 'approved') 
        AND start_time < ? 
        AND end_time > ?
    ");
    $stmt_b->execute([$date, $end_time, $start_time]);
    $rows = $stmt_b->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $r) {
        $tid = (int)$r['table_id'];
        // Approved takes precedence over pending
        if (!isset($booked[$tid]) || $r['status'] === 'approved') {
            $booked[$tid] = $r['status'];
        }
    }
}

$response = [];
foreach ($tables as $t) {
    $tid = (int)$t['id'];
    $status = 'available';
    if ($t['is_active'] == 0) {
        $status = 'inactive';
    } elseif (isset($booked[$tid])) {
        $status = $booked[$tid]; // 'pending' or 'approved'
    }
    $response[$tid] = [
        'id' => $tid,
        'name' => $t['name'],
        'status' => $status
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
