<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Clear remember token in DB before deleting the user to avoid
        // automatic re-authentication via the remember-me cookie. Prefer
        // the Authenticatable-compatible `setRememberToken` when available.
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
                // non-fatal
            }
        }

        // Determine any remember/recaller cookies to forget after logout
        $recallerCandidates = [];
        foreach ($_COOKIE as $ck => $cv) {
            if (stripos($ck, 'remember') !== false || stripos($ck, 'recaller') !== false) {
                $recallerCandidates[] = $ck;
            }
        }

        // Perform logout
        Auth::guard('web')->logout();

        // Delete the user record
        try {
            $user->delete();
        } catch (\Throwable $e) {
            // If deletion fails, continue with session invalidation to keep user signed out
        }

        // Invalidate session and regenerate CSRF token
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Forget only the remember-me recaller cookie; do not explicitly
        // forget the session cookie here. Let Laravel set and manage the
        // session cookie during the next request to avoid CSRF token issues.
        try {
            foreach ($recallerCandidates as $name) {
                try { Cookie::queue(Cookie::forget($name)); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Also clear legacy compatibility cookies so legacy public endpoints
        // will not treat a deleted user as logged in.
        try {
            Cookie::queue(Cookie::forget('SS_USER_ID'));
            Cookie::queue(Cookie::forget('SS_ROLE'));
        } catch (\Throwable $_) { /* non-fatal */ }

        // Return redirect with no-cache headers to discourage cached authenticated pages
        $noCacheHeaders = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
            'Pragma' => 'no-cache',
            'Expires' => 'Sat, 01 Jan 1990 00:00:00 GMT',
        ];

        return Redirect::to('/')->withHeaders($noCacheHeaders);
    }
}
