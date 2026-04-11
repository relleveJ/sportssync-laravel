<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BasketballReportController extends Controller
{
    public function show(Request $request)
    {
        $legacyPath = public_path('Basketball Admin UI/report.php');
        if (!file_exists($legacyPath)) return response('Legacy report missing', 500);
        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);

        // Provide a PDO connection to legacy script to preserve behavior
        $cfg = config('db_basketball');
        $pdo = null;
        if (is_array($cfg) && !empty($cfg)) {
            $host = $cfg['host'] ?? '127.0.0.1';
            $db   = $cfg['database'] ?? 'sportssync';
            $user = $cfg['username'] ?? 'root';
            $pass = $cfg['password'] ?? '';
            $charset = $cfg['charset'] ?? 'utf8mb4';
            try {
                $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
            } catch (\Throwable $e) {
                @file_put_contents(base_path('storage/logs/basketball_db_error.log'), date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", FILE_APPEND);
            }
        }

        // Make $pdo available to included legacy script
        ob_start(); include $legacyPath; $html = ob_get_clean();
        $html = str_replace('basketball_viewer.css', '/Basketball Admin UI/basketball_viewer.css', $html);
        $html = str_replace('basketball_viewer.js', '/Basketball Admin UI/basketball_viewer.js', $html);
        return view('basketball.report', ['legacy_html' => $html]);
    }
}
