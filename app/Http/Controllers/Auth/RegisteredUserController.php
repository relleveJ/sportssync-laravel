<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => ['sometimes', 'string', 'in:admin,viewer'],
        ]);

        $userData = [
            'name' => $request->name,
            'username' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'password_hash' => Hash::make($request->password),
        ];
        // Default to conservative 'viewer' role when none provided.
        $userData['role'] = $request->input('role', 'viewer');

        // If users table has a `status` column, set admin applicants to 'pending'
        $roleLower = strtolower((string)$userData['role']);
        if ($roleLower === 'admin' && Schema::hasColumn('users', 'status')) {
            $userData['status'] = 'pending';
        }

        $user = User::create($userData);


        try {
            event(new Registered($user));
            session()->flash('status', 'verification-link-sent');
        } catch (\Throwable $e) {
            Log::error('Failed to send verification email: ' . $e->getMessage());
            session()->flash('status', 'verification-send-failed');
        }

        Auth::login($user);

        // Legacy compatibility cookies are intentionally NOT set here.
        // Legacy pages will be provided compatibility via server-side middleware.

        return redirect(route('verification.notice', [], false));
    }
}
