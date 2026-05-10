<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Superadmin Password — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('auth.css') }}">
</head>
<body>

<div class="auth-bg">
    <canvas class="auth-canvas" id="authCanvas"></canvas>
    <div class="auth-grid-overlay"></div>
</div>

<div class="auth-wrap">
    <div class="auth-card">

        <a href="{{ url('/') }}" class="auth-logo">
            <span class="logo-bolt">⚡</span>SportSync
        </a>

        <div class="reset-steps">
            <div class="step done">
                <div class="step-circle">✓</div>
                <span class="step-label">Email</span>
            </div>
            <div class="step-connector done-line"></div>
            <div class="step done">
                <div class="step-circle">✓</div>
                <span class="step-label">Link</span>
            </div>
            <div class="step-connector active-line"></div>
            <div class="step active">
                <div class="step-circle">3</div>
                <span class="step-label">Reset</span>
            </div>
        </div>

        <div class="icon-wrap">🔑</div>

        <div class="auth-header">
            <h1 class="auth-title">Set New Superadmin Password</h1>
            <p class="auth-sub">Choose a strong password for the superadmin account</p>
        </div>

        @if ($errors->any())
            <div class="auth-alert">{{ $errors->first() }}</div>
        @endif

        <form class="auth-form" method="POST" action="{{ route('superadmin.password.store') }}">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="form-group">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="you@example.com"
                    value="{{ old('email', $request->email) }}"
                    autocomplete="username"
                    autofocus
                    required
                >
                @if ($errors->get('email'))
                    <span class="form-hint" style="color:#ff6b6b;">{{ $errors->first('email') }}</span>
                @endif
            </div>

            <div class="form-group">
                <label for="password">New Password</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="At least 8 chars, 1 uppercase, 1 digit"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="toggle-pw" onclick="togglePw('password', this)" aria-label="Show password">👁</button>
                </div>
            </div>

            <div class="form-group">
                <label for="password_confirmation">Confirm New Password</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        placeholder="Repeat new password"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="toggle-pw" onclick="togglePw('password_confirmation', this)" aria-label="Show password">👁</button>
                </div>
            </div>

            <button type="submit" class="auth-btn">🔒 Reset Password</button>
        </form>

        <p class="auth-switch">
            Remembered it? <a href="{{ route('superadmin.login') }}">Back to Sign In</a>
        </p>

    </div>
</div>

<script src="{{ asset('auth.js') }}"></script>
</body>
</html>
