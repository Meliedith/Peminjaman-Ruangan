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
define('BASE_URL', $protocol . $domainName . '/Peminjaman_Ruangan');

// Load .env variables
if (file_exists(__DIR__ . '/../.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');
    foreach ($env as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '870441525376-opon2s5buo26ernbmod9qh9l7esq9b1a.apps.googleusercontent.com'); // fallback
?>
