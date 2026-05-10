<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

// Ensure session is started before any output to avoid header errors
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tryInclude($file, $session = [], $cookies = []) {
    echo "--- Testing: $file (session=" . json_encode($session) . ", cookies=" . json_encode($cookies) . ")\n";
    // clear previous globals
    foreach (array_keys($_SESSION ?? []) as $k) unset($_SESSION[$k]);
    // set session
    foreach ($session as $k => $v) $_SESSION[$k] = $v;
    // set cookies
    foreach ($cookies as $k => $v) $_COOKIE[$k] = $v;
    // define wrapper
    if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
    chdir(dirname($file));
    ob_start();
    try {
        include $file;
        $out = ob_get_clean();
        echo "Included OK, output length: " . strlen($out) . "\n";
        // print snippet
        echo substr(preg_replace('/\s+/', ' ', strip_tags($out)), 0, 300) . "\n";
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        echo "Exception: $msg\n";
        ob_end_clean();
    }
    echo "\n";
}

$admin = DB::table('users')->where('role', 'admin')->orWhere('role','scorekeeper')->first();
$adminId = $admin ? $admin->id : null;
echo "Found admin id: " . ($adminId ?? 'none') . "\n\n";

$files = [
    __DIR__ . '/../public/Badminton Admin UI/badminton_admin.php',
    __DIR__ . '/../public/Basketball Admin UI/index.php',
    __DIR__ . '/../public/DARTS ADMIN UI/index.php',
    __DIR__ . '/../public/Volleyball Admin UI/volleyball_admin.php',
];

foreach ($files as $file) {
    if (!file_exists($file)) { echo "File missing: $file\n\n"; continue; }
    // 1) no session/cookies
    tryInclude($file, [], []);
    // 2) session user_id set
    if ($adminId) tryInclude($file, ['user_id' => $adminId, 'username' => 'test', 'role' => 'admin'], []);
    // 3) cookie SS_USER_ID numeric
    if ($adminId) tryInclude($file, [], ['SS_USER_ID' => (string)$adminId, 'SS_ROLE' => 'admin']);
}

echo "Done tests\n";
