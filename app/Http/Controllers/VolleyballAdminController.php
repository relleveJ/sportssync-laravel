<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class VolleyballAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'ensure.role:admin']);
    }
    public function index(Request $request)
    {
        $legacyPath = public_path('Volleyball Admin UI/volleyball_admin.php');
        if (!file_exists($legacyPath)) return response('Legacy volleyball admin missing', 500);
        if (!defined('LARAVEL_WRAPPER')) {
            define('LARAVEL_WRAPPER', true);
        }
        $cfg = config('db_volleyball');
        try {
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset={$cfg['charset']}";
            $pdo = new \PDO($dsn, $cfg['username'], $cfg['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('Volleyball DB connect: ' . $e->getMessage());
            return response('Database connection failed', 500);
        }

        ob_start();
        include $legacyPath;
        $html = ob_get_clean();
        $html = str_replace('volleyball_admin.css', '/Volleyball Admin UI/volleyball_admin.css', $html);
        $html = str_replace('volleyball_viewer.js', '/Volleyball Admin UI/volleyball_viewer.js', $html);

        // Legacy session/cookie injection is handled by middleware `legacy.session`.

        return view('volleyball.admin', ['legacy_html' => $html]);
    }
}
