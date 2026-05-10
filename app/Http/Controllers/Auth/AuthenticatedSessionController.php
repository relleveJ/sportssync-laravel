<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Prevent superadmin accounts from signing in via the regular login page.
        try {
            $user = Auth::guard('web')->user();
            if ($user && (($user->role ?? '') === 'superadmin')) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return back()->withErrors(['identifier' => 'Use the Superadmin login page to sign in.']);
            }
        } catch (\Throwable $_) {
            // Non-fatal: continue if role check fails for any reason.
        }

        $request->session()->regenerate();

        // Do not set legacy SS_* cookies on login. Compatibility is provided
        // server-side by the `legacy.session` middleware for proxied requests.

        return redirect()->intended(route('dashboard', [], false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Capture current user before logging out
        $user = Auth::guard('web')->user();
        /** @var User|null $user */
        // Determine any remember/recaller cookies to forget later (robust across Laravel versions)
        $recallerCandidates = [];
        foreach ($_COOKIE as $ck => $cv) {
            if (stripos($ck, 'remember') !== false || stripos($ck, 'recaller') !== false) {
                $recallerCandidates[] = $ck;
            }
        }

        // Clear the remember token in the database so the user won't be
        // re-authenticated automatically after logout. Prefer the
        // Authenticatable-compatible `setRememberToken` when available.
        if ($user) {
            try {
                if (method_exists($user, 'setRememberToken')) {
                    $user->setRememberToken(null);
                    if (method_exists($user, 'save')) $user->save();
                } elseif (method_exists($user, 'forceFill')) {
                    $user->forceFill(['remember_token' => null])->save();
                } else {
                    $user->remember_token = null;
                    if (method_exists($user, 'save')) $user->save();
                }
            } catch (\Throwable $e) {
                // Non-fatal: continue with logout even if DB write fails.
            }
        }

        // Perform the standard logout
        Auth::guard('web')->logout();

        // Invalidate session and regenerate CSRF token
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Remove the remember-me recaller cookie so users are not immediately
        // re-authenticated after logging out if they had checked "remember me".
        try {
            foreach ($recallerCandidates as $name) {
                try { Cookie::queue(Cookie::forget($name)); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $e) {
            // Non-fatal: if cookie forget fails for any reason, continue logout.
        }

        // Also clear legacy compatibility cookies
        try {
            Cookie::queue(Cookie::forget('SS_USER_ID'));
            Cookie::queue(Cookie::forget('SS_ROLE'));
        } catch (\Throwable $e) { /* non-fatal */ }
        // Avoid direct setcookie() calls; rely on queued cookie forget for any
        // previously-set legacy cookies.

        // Do not force-forget the session cookie here; let Laravel manage the
        // session cookie lifecycle. Explicitly removing the session cookie in
        // the same response can interfere with a fresh session being issued
        // and lead to CSRF token mismatches (419 errors) on subsequent login.

        // Add no-cache headers on the redirect response to discourage browsers
        // from serving cached authenticated pages when navigating back.
        $noCacheHeaders = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
            'Pragma' => 'no-cache',
            'Expires' => 'Sat, 01 Jan 1990 00:00:00 GMT',
        ];

        return redirect('/')->withHeaders($noCacheHeaders);
    }
}
