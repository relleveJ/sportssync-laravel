<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LegacyProxyController extends Controller
{
    /**
     * Handle proxied requests to legacy PHP files under /public.
     * Middleware should ensure auth and legacy session injection when needed.
     */
    public function handle(Request $request, $sport, $path = '')
    {
        $allowed = config('legacy.allowed_folders', []);
        if (! in_array($sport, $allowed, true)) {
            abort(404);
        }

        if ($path === '' || $path === null) {
            $path = $sport === 'TABLE TENNIS ADMIN UI' ? 'tabletennis_admin.php' : 'index.php';
        }

        // Basic sanitization
        $path = str_replace("\0", '', $path);
        if (strpos($path, '..') !== false || preg_match('#(^/|\\\\)#', $path)) {
            abort(400);
        }

        $legacyFile = public_path($sport . '/' . $path);
        if (! file_exists($legacyFile) || ! is_file($legacyFile)) {
            abort(404);
        }

        if (! defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        chdir(dirname($legacyFile));

        ob_start();
        include $legacyFile;
        $content = ob_get_clean();

        $ext = strtolower(pathinfo($legacyFile, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            default => 'text/html',
        };

        return response($content, 200)->header('Content-Type', $mime);
    }
}
