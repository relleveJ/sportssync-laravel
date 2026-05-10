<?php
namespace App\Http\Controllers\Superadmin\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use App\Models\User;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the superadmin password reset link request view.
     */
    public function create(): View
    {
        return view('superadmin.auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request for superadmin.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $email = $request->input('email');
        $user = User::where('email', $email)->where('role', 'superadmin')->first();
        if (! $user) {
            // Do not reveal whether the account exists; return the same success response
            return back()->with('status', trans(Password::RESET_LINK_SENT));
        }

        // Create a password reset token and send a custom superadmin reset notification
        $token = Password::broker()->createToken($user);
        $user->notify(new \App\Notifications\SuperadminResetPassword($token));

        return back()->with('status', trans(Password::RESET_LINK_SENT));
    }
}
