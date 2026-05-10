<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DartsAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'ensure.role:admin']);
    }
    public function index(Request $request)
    {
        $legacyPath = public_path('DARTS ADMIN UI/index.php');
        if (!file_exists($legacyPath)) return response('Legacy darts admin missing', 500);
        if (!defined('LARAVEL_WRAPPER')) {
            define('LARAVEL_WRAPPER', true);
        }
        $cfg = config('db_darts');
        $mysqli = @new \mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database']);
        if ($mysqli->connect_errno) {
            Log::error('Darts DB connect: ' . $mysqli->connect_error);
            return response('Database connection failed', 500);
        }
        $mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');
        ob_start();
        include $legacyPath;
        $html = ob_get_clean();
        // Expose a JS global so embedded legacy pages can resolve sibling URLs
        $legacyDir = '/DARTS ADMIN UI/';
        $script = '<script>window.LEGACY_BASE_PATH = ' . json_encode($legacyDir) . ';</script>';
        $html = $script . $html;
        // Fix a couple of resource paths that expect the legacy folder root
        $html = str_replace('darts.sql', $legacyDir . 'darts.sql', $html);

        // Legacy session/cookie injection is handled by middleware `legacy.session`.

        return view('darts.admin', ['legacy_html' => $html]);
    }
}
