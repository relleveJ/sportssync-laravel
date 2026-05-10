<?php
namespace App\Http\Controllers\Superadmin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the superadmin login view.
     */
    public function create(): View
    {
        return view('superadmin.auth.login');
    }

    /**
     * Handle an incoming authentication request for superadmin.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Ensure the authenticated user is a superadmin
        $user = Auth::guard('web')->user();
        if (! $user || (strtolower((string)($user->role ?? '')) !== 'superadmin')) {
            try { Auth::guard('web')->logout(); } catch (\Throwable $_) {}
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return back()->withErrors(['identifier' => 'Invalid credentials or not authorized as superadmin.']);
        }

        $request->session()->regenerate();

        // Set lightweight legacy compatibility cookies so direct requests
        // to public legacy PHP files (e.g. /adminlanding_page.php) can
        // authenticate the Laravel user.
        try {
            $minutes = 60 * 8; // 8 hours
            Cookie::queue('SS_USER_ID', (string) intval($user->id), $minutes);
            Cookie::queue('SS_ROLE', $user->role ?? 'viewer', $minutes);
            try {
                $expire = time() + ($minutes * 60);
                setcookie('SS_USER_ID', (string) intval($user->id), $expire, '/');
                setcookie('SS_ROLE', $user->role ?? 'viewer', $expire, '/');
            } catch (\Throwable $_) { /* non-fatal */ }
        } catch (\Throwable $_) { /* non-fatal */ }

        // Honor legacy `next` parameter when present. If it references
        // the admin landing, send the user to the direct legacy file so
        // they land on the expected page.
        $next = trim((string) ($request->input('next') ?? $request->query('next') ?? ''));
        if ($next !== '') {
            $n = strtolower($next);
            if (str_contains($n, 'adminlanding')) {
                return redirect('/adminlanding_page.php');
            }
            if (preg_match('#admin ui|admin.php|viewer.php#i', $n)) {
                $clean = '/' . ltrim($next, '/');
                return redirect($clean);
            }
        }

        // Default: send superadmins to the proxied legacy admin landing.
        return redirect(route('legacy.adminlanding'));
    }
}
