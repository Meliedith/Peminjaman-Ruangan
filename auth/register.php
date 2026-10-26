<?php
// auth/register.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Akun dibuat secara otomatis saat pertama kali login menggunakan Google SSO
redirect('/auth/login.php');
exit;
