<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TableTennisViewerController extends Controller
{
    public function show(Request $request)
    {
        $legacyPath = public_path('TABLE TENNIS ADMIN UI/tabletennis_viewer.php');
        if (!file_exists($legacyPath)) return response('Legacy TT viewer missing', 500);
        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        $cfg = config('db_tabletennis');
        $mysqli = @new \mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database']);
        if ($mysqli->connect_errno) {
            \Illuminate\Support\Facades\Log::error('TableTennis viewer DB connect: ' . $mysqli->connect_error);
            return response('Database connection failed', 500);
        }
        $mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');
        ob_start(); include $legacyPath; $html = ob_get_clean();
        $html = str_replace('tabletennis_viewer.css', '/TABLE TENNIS ADMIN UI/tabletennis_viewer.css', $html);
        $html = str_replace('tabletennis_viewer.js', '/TABLE TENNIS ADMIN UI/tabletennis_viewer.js', $html);
        return view('tabletennis.viewer', ['legacy_html' => $html]);
    }
}
