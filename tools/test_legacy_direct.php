<?php
// Direct legacy include test — do not bootstrap Laravel to avoid headers output
function tryIncludeDirect($file, $session = [], $cookies = []) {
    ob_start();
    // define wrapper before include
    if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
    // set session values into $_SESSION without calling session_start — legacy auth will start session
    foreach ($session as $k => $v) {
        $_SESSION[$k] = $v;
    }
    // set cookies
    foreach ($cookies as $k => $v) {
        $_COOKIE[$k] = $v;
    }
    chdir(dirname($file));
    try {
        include $file;
    } catch (Throwable $e) {
        echo "INCLUDE ERROR: " . $e->getMessage();
    }
    $html = ob_get_clean();
    echo "--- File: $file\n";
    if (trim($html) === '') echo "(empty output)\n";
    echo "Output length: " . strlen($html) . "\n";
    echo substr(strip_tags($html), 0, 400) . "\n\n";
}

$files = [
    __DIR__ . '/../public/Badminton Admin UI/badminton_admin.php',
    __DIR__ . '/../public/Basketball Admin UI/index.php',
    __DIR__ . '/../public/DARTS ADMIN UI/index.php',
    __DIR__ . '/../public/Volleyball Admin UI/volleyball_admin.php',
    __DIR__ . '/../public/adminlanding_page.php',
];

// find an admin id by reading DB directly via PDO (use legacy db.php defaults)
require __DIR__ . '/../app/Legacy/db.php';
$adminId = null;
try {
    $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('admin','scorekeeper') LIMIT 1");
    $r = $stmt->fetch();
    if ($r) $adminId = (int)$r['id'];
} catch (Throwable $_) {}

echo "Found admin id: " . ($adminId ?: 'none') . "\n\n";

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "Missing: $file\n\n";
        continue;
    }
    // no session/cookie
    tryIncludeDirect($file, [], []);
    // session set
    tryIncludeDirect($file, ['user_id' => $adminId, 'username' => 'test', 'role' => 'admin'], []);
    // cookie set
    tryIncludeDirect($file, [], ['SS_USER_ID' => (string)$adminId, 'SS_ROLE' => 'admin']);
}

echo "Done direct tests\n";
