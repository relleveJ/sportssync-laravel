<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SuperadminController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

Route::get('/', function () {
    return view('dashboard');
});

// Legacy routes are defined in `routes/legacy.php` and are proxied through
// Laravel so that authentication and the `legacy.session` middleware run.
// This ensures legacy pages execute within the Laravel request lifecycle
// and receive server-side session/cookie injection for compatibility.

Route::get('/about', function () {
    return view('about');
})->name('about');

Route::get('/contact', function () {
    return view('contact');
})->name('contact');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified', \App\Http\Middleware\PreventBackHistory::class])->name('dashboard');

Route::middleware(['auth', \App\Http\Middleware\PreventBackHistory::class])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Superadmin routes: full access, protected by auth + ensure.role:superadmin
Route::middleware(['auth', 'ensure.role:superadmin', \App\Http\Middleware\PreventBackHistory::class])->group(function () {
    Route::get('/superadmin', [SuperadminController::class, 'index'])->name('superadmin.dashboard');
    Route::get('/superadmin/users', [SuperadminController::class, 'users'])->name('superadmin.users');
    Route::post('/superadmin/users/promote', [SuperadminController::class, 'promote'])->name('superadmin.users.promote');
});

// Legacy admin landing proxied through Laravel so middleware can enforce auth + role
Route::get('/adminlanding_page.php', function () {
    ob_start();
    try {
        include public_path('adminlanding_page.php');
        $content = ob_get_clean();
    } catch (\Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        abort(500, 'Legacy admin page error');
    }
    return response($content);
})->middleware(['auth', 'superadmin'])->name('legacy.adminlanding');

// Friendly alias without .php for proxied legacy admin landing
Route::get('/adminlanding', function () {
    ob_start();
    try {
        include public_path('adminlanding_page.php');
        $content = ob_get_clean();
    } catch (\Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        abort(500, 'Legacy admin page error');
    }
    return response($content);
})->middleware(['auth', 'superadmin'])->name('legacy.adminlanding.short');

require __DIR__.'/legacy.php';
require __DIR__.'/auth.php';
require __DIR__.'/superadmin_auth.php';

// Server-side session check endpoint used by client-side pages to validate
// whether the user session is still valid. Returns JSON and sets no-cache
// headers so responses are never served from browser cache.
Route::get('/auth/check', function (Request $request) {
    $isAuth = Auth::check();
    $uid = $isAuth ? (int) Auth::id() : null;
    $status = null;
    try {
        if ($isAuth && Auth::user()) {
            $status = Auth::user()->status ?? null;
        }
    } catch (Throwable $_) { $status = null; }
    $resp = response()->json(['authenticated' => $isAuth, 'user_id' => $uid, 'status' => $status]);
    $resp->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
    $resp->headers->set('Pragma', 'no-cache');
    $resp->headers->set('Expires', '0');
    return $resp;
});

// Logout endpoint that clears both Laravel and legacy sessions/cookies
Route::get('/legacy-logout', function (Request $request) {
    // Laravel logout
    try {
        if (Auth::check()) {
            Auth::logout();
        }
    } catch (\Throwable $_) { }

    try {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    } catch (\Throwable $_) { }

    // Destroy native PHP session (legacy) if present
    try {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? false);
        }
        @session_destroy();
    } catch (\Throwable $_) { }

    // Remove legacy compatibility cookies
    try {
        Cookie::queue(Cookie::forget('SS_USER_ID'));
        Cookie::queue(Cookie::forget('SS_ROLE'));
        setcookie('SS_USER_ID', '', time() - 3600, '/');
        setcookie('SS_ROLE', '', time() - 3600, '/');
    } catch (\Throwable $_) { }

    return redirect()->route('superadmin.login');
})->name('legacy.logout');
