<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TableTennisReportController extends Controller
{
    public function show(Request $request)
    {
        $legacyPath = public_path('TABLE TENNIS ADMIN UI/tabletennis_report.php');
        if (!file_exists($legacyPath)) return response('Legacy report missing', 500);
        if (!defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);

        $cfg = config('db_tabletennis');
        $mysqli = null;
        if (is_array($cfg) && !empty($cfg)) {
            $mysqli = @new \mysqli($cfg['host'] ?? '127.0.0.1', $cfg['username'] ?? 'root', $cfg['password'] ?? '', $cfg['database'] ?? '');
            if ($mysqli->connect_errno) {
                \Illuminate\Support\Facades\Log::error('TableTennis report DB connect: ' . $mysqli->connect_error);
            } else {
                $mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');
            }
        }

        // Include legacy report (legacy script expects $mysqli)
        ob_start(); include $legacyPath; $html = ob_get_clean();
        return view('tabletennis.report', ['legacy_html' => $html]);
    }
}
