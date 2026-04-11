<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TableTennisAdminController extends Controller
{
    public function index(Request $request)
    {
        $legacyPath = public_path('TABLE TENNIS ADMIN UI/tabletennis_admin.php');
        if (!file_exists($legacyPath)) return response('Legacy TT admin missing', 500);
        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        // create mysqli for legacy include
        $cfg = config('db_tabletennis');
        $mysqli = @new \mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database']);
        if ($mysqli->connect_errno) {
            \Illuminate\Support\Facades\Log::error('TableTennis DB connect: ' . $mysqli->connect_error);
            return response('Database connection failed', 500);
        }
        $mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');
        ob_start(); include $legacyPath; $html = ob_get_clean();
        $html = str_replace('tabletennis_admin.css', '/TABLE TENNIS ADMIN UI/tabletennis_admin.css', $html);
        $html = str_replace('tabletennis_admin.js', '/TABLE TENNIS ADMIN UI/tabletennis_admin.js', $html);
        return view('tabletennis.admin', ['legacy_html' => $html]);
    }
}
