<?php
namespace App\Http\Controllers\Superadmin\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the superadmin password reset view.
     */
    public function create(Request $request): View
    {
        return view('superadmin.auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request for superadmin.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Ensure the target email belongs to a superadmin
        $userCheck = User::where('email', $request->email)->where('role', 'superadmin')->first();
        if (! $userCheck) {
            return back()->withErrors(['email' => 'Invalid password reset request.'])->withInput($request->only('email'));
        }

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                if (($user->role ?? '') !== 'superadmin') {
                    return; // Do not reset non-superadmin accounts here
                }

                if (method_exists($user, 'forceFill')) {
                    $user->forceFill([
                        'password' => Hash::make($request->password),
                        'remember_token' => Str::random(60),
                    ])->save();
                } else {
                    $user->password = Hash::make($request->password);
                    $user->remember_token = Str::random(60);
                    $user->save();
                }

                event(new PasswordReset($user));
            }
        );

        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('superadmin.login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
