<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Superadmin Forgot Password — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('auth.css') }}">
<style>
/* Re-use styles from main forgot-password view */
</style>
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
            <div class="step active">
                <div class="step-circle">1</div>
                <span class="step-label">Email</span>
            </div>
            <div class="step-connector"></div>
            <div class="step inactive">
                <div class="step-circle">2</div>
                <span class="step-label">Link</span>
            </div>
            <div class="step-connector"></div>
            <div class="step inactive">
                <div class="step-circle">3</div>
                <span class="step-label">Reset</span>
            </div>
        </div>

        <div class="icon-wrap">📧</div>

        <div class="auth-header">
            <h1 class="auth-title">Forgot Superadmin Password?</h1>
            <p class="auth-sub">No worries — we'll send a reset link</p>
        </div>

        @if ($errors->any())
            <div class="auth-alert">{{ $errors->first() }}</div>
        @endif

        @if (session('status'))
            <div class="auth-success">{{ session('status') }}</div>
        @endif

        <div class="auth-notice">
            Enter the email address tied to the superadmin account and we'll send a password reset link.
        </div>

        <form class="auth-form" method="POST" action="{{ route('superadmin.password.email') }}">
            @csrf

            <div class="form-group">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="you@example.com"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    autofocus
                    required
                >
                @if ($errors->get('email'))
                    <span class="form-hint" style="color:#ff6b6b;">{{ $errors->first('email') }}</span>
                @endif
            </div>

            <button type="submit" class="auth-btn">📨 Send Reset Link</button>
        </form>

        <p class="auth-switch">
            Remember your password? <a href="{{ route('superadmin.login') }}">Sign in</a>
        </p>

    </div>
</div>

<script src="{{ asset('auth.js') }}"></script>
</body>
</html>
