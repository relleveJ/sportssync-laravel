<?php
// Legacy DB config. When included from Laravel wrapper we skip creating globals
if (defined('LARAVEL_WRAPPER')) {
    // Laravel controller provides $mysqli when needed — do nothing here.
    return;
}

// Database configuration - standalone legacy mode
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sportssync');

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) {
    @file_put_contents(__DIR__ . '/badminton_debug.log', date('[Y-m-d H:i:s] ') . "DB connect error: " . $mysqli->connect_error . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}
$mysqli->set_charset('utf8mb4');
