<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VolleyballViewerController extends Controller
{
    public function show(Request $request)
    {
        $legacyPath = public_path('Volleyball Admin UI/volleyball_viewer.php');
        if (!file_exists($legacyPath)) return response('Legacy volleyball viewer missing', 500);
        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        $cfg = config('db_volleyball');
        try {
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset={$cfg['charset']}";
            $pdo = new \PDO($dsn, $cfg['username'], $cfg['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Volleyball viewer DB connect: ' . $e->getMessage());
            return response('Database connection failed', 500);
        }
        ob_start(); include $legacyPath; $html = ob_get_clean();
        $html = str_replace('volleyball_viewer.css', '/Volleyball Admin UI/volleyball_viewer.css', $html);
        $html = str_replace('volleyball_viewer.js', '/Volleyball Admin UI/volleyball_viewer.js', $html);
        return view('volleyball.viewer', ['legacy_html' => $html]);
    }
}
