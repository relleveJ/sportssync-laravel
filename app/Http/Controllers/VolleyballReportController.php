<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VolleyballReportController extends Controller
{
    public function show(Request $request)
    {
        $legacyPath = public_path('Volleyball Admin UI/volleyball_report.php');
        if (!file_exists($legacyPath)) return response('Legacy report missing', 500);
        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);

        $cfg = config('db_volleyball');
        try {
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset={$cfg['charset']}";
            $pdo = new \PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Volleyball report DB connect: ' . $e->getMessage());
        }

        ob_start(); include $legacyPath; $html = ob_get_clean();
        $html = str_replace('volleyball_viewer.css', '/Volleyball Admin UI/volleyball_viewer.css', $html);
        $html = str_replace('volleyball_viewer.js', '/Volleyball Admin UI/volleyball_viewer.js', $html);
        return view('volleyball.report', ['legacy_html' => $html]);
    }
}
