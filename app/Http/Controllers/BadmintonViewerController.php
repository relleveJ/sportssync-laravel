<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BadmintonViewerController extends Controller
{
    public function show(Request $request)
    {
        $legacyPath = public_path('Badminton Admin UI/badminton_viewer.php');
        if (!file_exists($legacyPath)) {
            return response('Legacy viewer file missing', 500);
        }

        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        $cfg = config('db_badminton');
        $mysqli = @new \mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database']);
        if ($mysqli->connect_errno) {
            \Illuminate\Support\Facades\Log::error('Badminton viewer DB connect: ' . $mysqli->connect_error);
            return response('Database connection failed', 500);
        }
        $mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');
        ob_start();
        include $legacyPath; // outputs the viewer HTML
        $html = ob_get_clean();

        // Fix relative asset paths
        $html = str_replace('badminton_viewer.css', '/Badminton Admin UI/badminton_viewer.css', $html);
        $html = str_replace('badminton_viewer.js', '/Badminton Admin UI/badminton_viewer.js', $html);

        return view('badminton.viewer', ['legacy_html' => $html]);
    }
}
