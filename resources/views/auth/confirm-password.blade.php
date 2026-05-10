<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirm Password — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('auth.css') }}">
<style>
/* ── SECURE AREA BADGE ── */
.secure-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(245,197,0,.08);
    border: 1px solid rgba(245,197,0,.25);
    border-radius: 20px;
    padding: 5px 14px;
    font-size: .72rem;
    font-family: 'Oswald', sans-serif;
    letter-spacing: 1.5px;
    color: #f5c500;
    text-transform: uppercase;
    margin-bottom: 20px;
}
.secure-badge .badge-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #f5c500;
    animation: pulse-dot 1.8s ease-in-out infinite;
}
@keyframes pulse-dot {
    0%,100% { opacity: 1; transform: scale(1); }
    50%      { opacity: .4; transform: scale(.6); }
}

/* ── LOCK ICON BLOCK ── */
.lock-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 64px; height: 64px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(245,197,0,.15), rgba(245,197,0,.04));
    border: 1px solid rgba(245,197,0,.3);
    font-size: 1.8rem;
    margin: 0 auto 20px;
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
    color: rgba(255,255,255,.6);
    line-height: 1.55;
    margin-bottom: 24px;
}

/* ── SHIELD DIVIDER ── */
.shield-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 6px 0 20px;
    color: rgba(255,255,255,.2);
    font-size: .7rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    font-family: 'Oswald', sans-serif;
}
.shield-divider::before,
.shield-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255,255,255,.1);
}
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

        <div class="lock-icon-wrap">🔐</div>

        <div style="text-align:center">
            <div class="secure-badge">
                <span class="badge-dot"></span> Secure Area
            </div>
        </div>

        <div class="auth-header" style="margin-top:4px">
            <h1 class="auth-title">Confirm Password</h1>
            <p class="auth-sub">Re-enter your password to continue</p>
        </div>

        @if ($errors->any())
            <div class="auth-alert">{{ $errors->first() }}</div>
        @endif

        <div class="auth-notice">
            You're accessing a protected area of SportSync. Please confirm your current password to proceed.
        </div>

        <div class="shield-divider">Verify Identity</div>

        <form class="auth-form" method="POST" action="{{ route('password.confirm') }}">
            @csrf

            <div class="form-group">
                <label for="password">Current Password</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your current password"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="toggle-pw" onclick="togglePw('password', this)" aria-label="Show password">👁</button>
                </div>
                @if ($errors->get('password'))
                    <span class="form-hint" style="color:#ff6b6b;">{{ $errors->first('password') }}</span>
                @endif
            </div>

            <button type="submit" class="auth-btn">🔓 Confirm &amp; Continue</button>
        </form>

        <p class="auth-switch" style="margin-top:18px;">
            <a href="{{ url()->previous() }}" style="color:rgba(255,255,255,.35);font-size:.8rem;">← Go back</a>
        </p>

    </div>
</div>

<script src="{{ asset('auth.js') }}"></script>
</body>
</html>