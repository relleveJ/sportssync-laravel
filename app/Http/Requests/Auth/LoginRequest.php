<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $identifier = $this->input('identifier');
        $password = $this->input('password');

        $attempted = false;
        // Try login by email
        if (Auth::attempt(['email' => $identifier, 'password' => $password], $this->boolean('remember'))) {
            $attempted = true;
        }
        // If not, try login by username, then fallback to name
        if (! $attempted && Auth::attempt(['username' => $identifier, 'password' => $password], $this->boolean('remember'))) {
            $attempted = true;
        }
        if (! $attempted && Auth::attempt(['name' => $identifier, 'password' => $password], $this->boolean('remember'))) {
            $attempted = true;
        }

        if (! $attempted) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'identifier' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // Enforce account status: block login if account is pending/rejected/inactive
        $user = Auth::user();
        if ($user) {
            $status = strtolower((string)($user->status ?? ''));
            if (in_array($status, ['pending', 'rejected'], true) || (isset($user->is_active) && !(int)$user->is_active)) {
                Auth::guard()->logout();
                throw ValidationException::withMessages([
                    'identifier' => 'Account not approved.',
                ]);
            }
        }
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'identifier' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('identifier')).'|'.$this->ip());
    }
}
