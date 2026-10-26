<?php
// config/database.php
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config.php';

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'db_peminjaman_lab';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Set MySQL session timezone to WIB (+07:00)
    $pdo->exec("SET time_zone = '+07:00'");
} catch(PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("ERROR: Tidak dapat terhubung ke database. Silakan hubungi administrator.");
}
?>
