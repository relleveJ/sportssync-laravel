<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DartsAdminController extends Controller
{
    public function index(Request $request)
    {
        $legacyPath = public_path('DARTS ADMIN UI/index.php');
        if (!file_exists($legacyPath)) return response('Legacy darts admin missing', 500);
        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        $cfg = config('db_darts');
        $mysqli = @new \mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database']);
        if ($mysqli->connect_errno) {
            \Illuminate\Support\Facades\Log::error('Darts DB connect: ' . $mysqli->connect_error);
            return response('Database connection failed', 500);
        }
        $mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');
        ob_start(); include $legacyPath; $html = ob_get_clean();
        $html = str_replace('darts.sql', '/DARTS ADMIN UI/darts.sql', $html);
        return view('darts.admin', ['legacy_html' => $html]);
    }
}
