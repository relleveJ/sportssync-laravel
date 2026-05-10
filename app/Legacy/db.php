<?php
// ============================================================
// app/Legacy/db.php — PDO database connection (moved out of public)
// When included from Laravel wrappers, controllers may provide $pdo.
// ============================================================
if (defined('LARAVEL_WRAPPER')) {
    return;
}

$host   = 'localhost';
$dbname = 'sportssync';
$user   = 'root';
$pass   = '';
$dsn    = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, $user, $pass, $options);
