<?php
// cron/check_expired.php
// This script should be run via Cron job every 5 minutes.
// e.g. */5 * * * * php /path/to/cron/check_expired.php

require_once __DIR__ . '/../config/database.php';

echo "Running expiration check...\n";

try {
    $pdo->beginTransaction();

    // 1. Expire pending room bookings that have passed their end time
    $stmt1 = $pdo->prepare("
        UPDATE room_bookings 
        SET status = 'expired' 
        WHERE status = 'pending' 
        AND (booking_date < CURDATE() OR (booking_date = CURDATE() AND end_time <= CURTIME()))
    ");
    $stmt1->execute();
    $room_expired = $stmt1->rowCount();
    echo "- Room bookings expired: $room_expired\n";

    // 2. Expire pending tool bookings that have passed their end time
    $stmt2 = $pdo->prepare("
        UPDATE tool_bookings 
        SET status = 'expired' 
        WHERE status = 'pending' 
        AND (booking_date < CURDATE() OR (booking_date = CURDATE() AND end_time <= CURTIME()))
    ");
    $stmt2->execute();
    $tool_expired = $stmt2->rowCount();
    echo "- Tool bookings expired: $tool_expired\n";

    // 3. Mark approved tool bookings as no_show if they pass their end time without being completed
    // As requested: "pengajuan approved yang jam selesainya sudah lewat tapi belum ditandai selesai (untuk alat) -> tandai no_show"
    $stmt3 = $pdo->prepare("
        UPDATE tool_bookings 
        SET status = 'no_show' 
        WHERE status = 'approved' 
        AND (booking_date < CURDATE() OR (booking_date = CURDATE() AND end_time < CURTIME()))
    ");
    $stmt3->execute();
    $tool_noshow = $stmt3->rowCount();
    echo "- Tool bookings no-show: $tool_noshow\n";

    // 4. Room bookings no_show
    $stmt4 = $pdo->prepare("
        UPDATE room_bookings 
        SET status = 'no_show' 
        WHERE status = 'approved' 
        AND (booking_date < CURDATE() OR (booking_date = CURDATE() AND end_time < CURTIME()))
    ");
    $stmt4->execute();
    $room_noshow = $stmt4->rowCount();
    echo "- Room bookings no-show: $room_noshow\n";


    $pdo->commit();
    echo "Check completed successfully.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
