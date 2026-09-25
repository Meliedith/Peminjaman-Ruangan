<?php
require 'd:/Kuliah/xampp/htdocs/peminjaman ruangan/config/config.php';
require 'd:/Kuliah/xampp/htdocs/peminjaman ruangan/config/database.php';

$stmt = $pdo->query("SELECT id, booking_date, CURDATE(), end_time, CURTIME(), (booking_date < CURDATE()) as cond1, (booking_date = CURDATE() AND end_time <= CURTIME()) as cond2 FROM tool_bookings WHERE id = 9");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
