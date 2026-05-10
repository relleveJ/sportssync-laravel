<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — SportSync</title>
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

        <div class="auth-header">
            <h1 class="auth-title">Create Account</h1>
            <p class="auth-sub">Join SportSync to start scoring live</p>
        </div>

        @if ($errors->any())
            <div class="auth-alert">{{ $errors->first() }}</div>
        @endif
        @if (session('status'))
            <div class="auth-success">{{ session('status') }}</div>
        @endif

        <form class="auth-form" method="POST" action="{{ route('register') }}" novalidate>
            @csrf

            <div class="form-group">
                <label for="name">Username</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    placeholder="Choose a username"
                    value="{{ old('name') }}"
                    autocomplete="username"
                    maxlength="40"
                    required
                >
                <span class="form-hint">Letters, digits, _ . − only.</span>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="you@example.com"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Committee</option>
                    <option value="viewer" {{ old('role') === 'viewer' ? 'selected' : '' }}>Viewer</option>
                </select>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
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
                <div class="pw-strength" id="pwStrength"><div class="pw-bar" id="pwBar"></div></div>
            </div>

            <div class="form-group">
                <label for="password_confirmation">Confirm Password</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        placeholder="Repeat password"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="toggle-pw" onclick="togglePw('password_confirmation', this)" aria-label="Show password">👁</button>
                </div>
            </div>

            <button type="submit" class="auth-btn">Create Account</button>
        </form>

        <p class="auth-switch">
            Already have an account? <a href="{{ route('login') }}">Sign in</a>
        </p>
    </div>

</div>

<script src="{{ asset('auth.js') }}"></script>
</body>
</html>
