<?php

use Illuminate\Support\Facades\Route;

// Superadmin authentication routes (separate from normal auth)
Route::prefix('superadmin')->name('superadmin.')->group(function () {
    Route::middleware(['guest', \App\Http\Middleware\PreventBackHistory::class])->group(function () {
        Route::get('login', [\App\Http\Controllers\Superadmin\Auth\AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('login', [\App\Http\Controllers\Superadmin\Auth\AuthenticatedSessionController::class, 'store']);

        Route::get('forgot-password', [\App\Http\Controllers\Superadmin\Auth\PasswordResetLinkController::class, 'create'])->name('password.request');
        Route::post('forgot-password', [\App\Http\Controllers\Superadmin\Auth\PasswordResetLinkController::class, 'store'])->name('password.email');

        Route::get('reset-password/{token}', [\App\Http\Controllers\Superadmin\Auth\NewPasswordController::class, 'create'])->name('password.reset');
        Route::post('reset-password', [\App\Http\Controllers\Superadmin\Auth\NewPasswordController::class, 'store'])->name('password.store');
    });

    // Use shared logout (POST) from standard Auth controller, but require superadmin role to call it
    Route::middleware(['auth', 'ensure.role:superadmin', \App\Http\Middleware\PreventBackHistory::class])->group(function () {
        Route::post('logout', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });

    // Superadmin proxied admin landing — provide shortcut under /superadmin/adminlanding
    Route::middleware(['auth', 'superadmin', \App\Http\Middleware\PreventBackHistory::class])->group(function () {
        Route::get('adminlanding', function () {
            // Redirect to proxied legacy admin landing handled elsewhere
            return redirect('/adminlanding');
        })->name('adminlanding');
    });
});
