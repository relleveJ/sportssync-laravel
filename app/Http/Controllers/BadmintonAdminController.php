<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BadmintonAdminController extends Controller
{
    public function index(Request $request)
    {
        $legacyPath = public_path('Badminton Admin UI/badminton_admin.php');
        if (!file_exists($legacyPath)) {
            return response('Legacy admin file missing', 500);
        }

        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        // ensure $mysqli is available for legacy includes
        $cfg = config('db_badminton');
        $mysqli = @new \mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database']);
        if ($mysqli->connect_errno) {
            \Illuminate\Support\Facades\Log::error('Badminton DB connect: ' . $mysqli->connect_error);
            return response('Database connection failed', 500);
        }
        $mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');
        ob_start();
        include $legacyPath; // legacy file outputs HTML
        $html = ob_get_clean();

        // Fix relative asset paths to absolute public paths so links resolve correctly
        $html = str_replace('badminton_admin.css', '/Badminton Admin UI/badminton_admin.css', $html);
        $html = str_replace('badminton_admin.js', '/Badminton Admin UI/badminton_admin.js', $html);

        return view('badminton.admin', ['legacy_html' => $html]);
    }
}
