<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Legacy UI wrappers and reports (incremental migration)
Route::prefix('admin')->group(function () {
    Route::get('badminton', [\App\Http\Controllers\BadmintonAdminController::class, 'index']);
    Route::get('badminton/viewer', [\App\Http\Controllers\BadmintonViewerController::class, 'show']);
    Route::get('badminton/report', [\App\Http\Controllers\BadmintonReportController::class, 'show']);

    Route::get('basketball', [\App\Http\Controllers\BasketballAdminController::class, 'index']);
    Route::get('basketball/viewer', [\App\Http\Controllers\BasketballViewerController::class, 'show']);
    Route::get('basketball/report', [\App\Http\Controllers\BasketballReportController::class, 'show']);

    Route::get('tabletennis', [\App\Http\Controllers\TableTennisAdminController::class, 'index']);
    Route::get('tabletennis/viewer', [\App\Http\Controllers\TableTennisViewerController::class, 'show']);
    // Backwards-compatible aliases used by legacy smoke tests and external links
    Route::get('tabletennis/report', [\App\Http\Controllers\TableTennisReportController::class, 'show']);

    Route::get('volleyball', [\App\Http\Controllers\VolleyballAdminController::class, 'index']);
    Route::get('volleyball/viewer', [\App\Http\Controllers\VolleyballViewerController::class, 'show']);
    // Admin and report routes
    Route::get('volleyball/admin', [\App\Http\Controllers\VolleyballAdminController::class, 'index']);
    Route::get('volleyball/report', [\App\Http\Controllers\VolleyballReportController::class, 'show']);

    Route::get('darts', [\App\Http\Controllers\DartsAdminController::class, 'index']);
    // Provide legacy-style admin/viewer aliases for darts
    Route::get('darts/admin', [\App\Http\Controllers\DartsAdminController::class, 'index']);
    Route::get('darts/viewer', [\App\Http\Controllers\DartsAdminController::class, 'index']);
});
