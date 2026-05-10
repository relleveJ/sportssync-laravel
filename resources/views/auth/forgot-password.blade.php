<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('auth.css') }}">
<style>
/* ── STEPS INDICATOR ── */
.reset-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 28px;
}
.step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    position: relative;
}
.step-circle {
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem;
    font-family: 'Oswald', sans-serif;
    font-weight: 600;
    letter-spacing: .5px;
    border: 2px solid;
    transition: all .3s;
}
.step.active .step-circle {
    background: #f5c500;
    border-color: #f5c500;
    color: #000;
    box-shadow: 0 0 18px rgba(245,197,0,.4);
}
.step.inactive .step-circle {
    background: transparent;
    border-color: rgba(255,255,255,.15);
    color: rgba(255,255,255,.3);
}
.step-label {
    font-size: .62rem;
    text-transform: uppercase;
    letter-spacing: .8px;
    font-family: 'Oswald', sans-serif;
}
.step.active .step-label  { color: #f5c500; }
.step.inactive .step-label { color: rgba(255,255,255,.25); }
.step-connector {
    width: 48px; height: 2px;
    background: rgba(255,255,255,.1);
    margin-bottom: 18px;
}

/* ── ICON BLOCK ── */
.icon-wrap {
    width: 64px; height: 64px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(245,197,0,.15), rgba(245,197,0,.04));
    border: 1px solid rgba(245,197,0,.3);
    font-size: 1.8rem;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 22px;
    box-shadow: 0 0 28px rgba(245,197,0,.12);
}

/* ── INFO NOTICE ── */
.auth-notice {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.09);
    border-left: 3px solid #f5c500;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: .82rem;
    color: rgba(255,255,255,.55);
    line-height: 1.6;
    margin-bottom: 22px;
}

/* ── SUCCESS STATE ── */
.auth-success {
    background: rgba(34,197,94,.08);
    border: 1px solid rgba(34,197,94,.3);
    border-radius: 8px;
    padding: 12px 16px;
    font-size: .84rem;
    color: #4ade80;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.auth-success::before { content: '✅'; font-size: 1rem; }
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

        {{-- Step indicator --}}
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
            <h1 class="auth-title">Forgot Password?</h1>
            <p class="auth-sub">No worries — we'll send you a reset link</p>
        </div>

        @if ($errors->any())
            <div class="auth-alert">{{ $errors->first() }}</div>
        @endif

        @if (session('status'))
            <div class="auth-success">{{ session('status') }}</div>
        @endif

        <div class="auth-notice">
            Enter the email address tied to your SportSync account and we'll send you a password reset link.
        </div>

        <form class="auth-form" method="POST" action="{{ route('password.email') }}">
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
            Remember your password? <a href="{{ route('login') }}">Sign in</a>
        </p>

    </div>
</div>

<script src="{{ asset('auth.js') }}"></script>
</body>
</html>