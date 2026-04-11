<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BasketballAdminController extends Controller
{
    public function index(Request $request)
    {
        $legacyPath = public_path('Basketball Admin UI/index.php');
        if (!file_exists($legacyPath)) {
            return response('Legacy basketball admin file missing', 500);
        }
        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        $cfg = config('db_basketball');
        try {
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset={$cfg['charset']}";
            $pdo = new \PDO($dsn, $cfg['username'], $cfg['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Basketball DB connect: ' . $e->getMessage());
            return response('Database connection failed', 500);
        }
        ob_start(); include $legacyPath; $html = ob_get_clean();
        $html = str_replace('basketball_viewer.css', '/Basketball Admin UI/basketball_viewer.css', $html);
        $html = str_replace('basketball_viewer.js', '/Basketball Admin UI/basketball_viewer.js', $html);
        $html = str_replace('style.css', '/Basketball Admin UI/style.css', $html);
        return view('basketball.admin', ['legacy_html' => $html]);
    }
}
