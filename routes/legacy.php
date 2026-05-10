<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LegacyProxyController;
use App\Http\Controllers\BadmintonAdminController;
use App\Http\Controllers\BadmintonViewerController;
use App\Http\Controllers\BasketballAdminController;
use App\Http\Controllers\BasketballViewerController;
use App\Http\Controllers\TableTennisAdminController;
use App\Http\Controllers\TableTennisViewerController;
use App\Http\Controllers\DartsAdminController;
use App\Http\Controllers\VolleyballAdminController;
use App\Http\Controllers\VolleyballViewerController;

// All legacy admin UI access must go through Laravel and require auth + legacy.session
Route::middleware(['auth', 'legacy.session'])->group(function () {
    // Top-level UI entry pages (wrapped views)
    Route::get('/Badminton Admin UI', [BadmintonAdminController::class, 'index'])->name('badminton.admin');
    Route::get('/Badminton Admin UI/viewer', [BadmintonViewerController::class, 'show'])->name('badminton.viewer');

    Route::get('/Basketball Admin UI', [BasketballAdminController::class, 'index'])->name('basketball.admin');
    Route::get('/Basketball Admin UI/viewer', [BasketballViewerController::class, 'show'])->name('basketball.viewer');

    Route::get('/TABLE TENNIS ADMIN UI', [TableTennisAdminController::class, 'index'])->name('tabletennis.admin');
    Route::get('/TABLE TENNIS ADMIN UI/viewer', [TableTennisViewerController::class, 'show'])->name('tabletennis.viewer');

    Route::get('/DARTS ADMIN UI', [DartsAdminController::class, 'index'])->name('darts.admin');

    Route::get('/Volleyball Admin UI', [VolleyballAdminController::class, 'index'])->name('volleyball.admin');
    Route::get('/Volleyball Admin UI/viewer', [VolleyballViewerController::class, 'show'])->name('volleyball.viewer');

    // Proxy any other legacy files (AJAX endpoints, PHP helpers, etc.) through the controller.
    Route::any('/{sport}/{path?}', [LegacyProxyController::class, 'handle'])
        ->where('sport', 'TABLE TENNIS ADMIN UI|Badminton Admin UI|Basketball Admin UI|DARTS ADMIN UI|Volleyball Admin UI|analytics')
        ->where('path', '.*');

    // Admin landing page (legacy) proxied for superadmins
    Route::get('/adminlanding', function () {
        $legacyFile = public_path('adminlanding_page.php');
        if (! file_exists($legacyFile) || ! is_file($legacyFile)) abort(404);
        if (! defined('LARAVEL_WRAPPER')) define('LARAVEL_WRAPPER', true);
        chdir(public_path());
        ob_start(); include $legacyFile; $content = ob_get_clean();
        return response($content, 200)->header('Content-Type', 'text/html');
    })->middleware('ensure.role:superadmin')->name('legacy.adminlanding');
});
