<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register alias for role middleware so routes can reference `ensure.role:admin`
        Route::aliasMiddleware('ensure.role', \App\Http\Middleware\EnsureRole::class);
        // Register alias for legacy session injection middleware used by proxied legacy routes
        Route::aliasMiddleware('legacy.session', \App\Http\Middleware\LegacySessionMiddleware::class);
        // Register dedicated superadmin middleware alias for concise route protection
        Route::aliasMiddleware('superadmin', \App\Http\Middleware\SuperAdminMiddleware::class);
        // Ensure the lightweight legacy cookies used for compatibility are not encrypted
        // so legacy public endpoints can read them directly (SS_USER_ID, SS_ROLE).
        try {
            \Illuminate\Cookie\Middleware\EncryptCookies::except(['SS_USER_ID', 'SS_ROLE']);
        } catch (\Throwable $_) {
            // Non-fatal: if the class/method is unavailable, continue.
        }

        // Ensure a superadmin account exists if environment variables are provided.
        try {
            $email = env('SUPERADMIN_EMAIL');
            $password = env('SUPERADMIN_PASSWORD');
            if ($email && $password && Schema::hasTable('users')) {
                $user = User::where('email', $email)->first();
                if (! $user) {
                    $username = env('SUPERADMIN_USERNAME', strtok($email, '@'));
                    $hashed = Hash::make($password);
                    User::create([
                        'name' => 'Super Admin',
                        'username' => $username,
                        'email' => $email,
                        'password' => $hashed,
                        'password_hash' => $hashed,
                        'role' => 'superadmin',
                        'email_verified_at' => now(),
                    ]);
                } else {
                    $changed = false;
                    if ($user->role !== 'superadmin') { $user->role = 'superadmin'; $changed = true; }
                    // Only update password automatically if explicitly enabled to avoid accidental overwrites.
                    if (env('SUPERADMIN_UPDATE_PASSWORD', false)) {
                        $hashed = Hash::make($password);
                        $user->password = $hashed;
                        $user->password_hash = $hashed;
                        $changed = true;
                    }
                    if ($changed) $user->save();
                }
            }
        } catch (\Throwable $_) {
            // Non-fatal: avoid breaking the app if DB isn't ready yet.
        }
    }
}
