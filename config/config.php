<?php
// config/config.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'] . '://';
}
$domainName = $_SERVER['HTTP_HOST'];
define('BASE_URL', $protocol . $domainName . '/peminjaman ruangan');
?>
