<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('auth.css') }}">
<style>
/* ── ENVELOPE ANIMATION ── */
.envelope-wrap {
    position: relative;
    width: 90px; height: 90px;
    margin: 0 auto 24px;
    display: flex; align-items: center; justify-content: center;
}
.envelope-ring {
    position: absolute; inset: 0;
    border-radius: 50%;
    border: 1.5px solid rgba(245,197,0,.2);
    animation: ring-pulse 2.4s ease-in-out infinite;
}
.envelope-ring:nth-child(2) {
    inset: -12px;
    border-color: rgba(245,197,0,.1);
    animation-delay: .6s;
}
.envelope-ring:nth-child(3) {
    inset: -24px;
    border-color: rgba(245,197,0,.05);
    animation-delay: 1.2s;
}
@keyframes ring-pulse {
    0%,100% { opacity: 1; transform: scale(1); }
    50%      { opacity: 0; transform: scale(1.15); }
}
.envelope-icon {
    position: relative; z-index: 1;
    width: 64px; height: 64px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(245,197,0,.18), rgba(245,197,0,.05));
    border: 1px solid rgba(245,197,0,.35);
    font-size: 1.9rem;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 0 30px rgba(245,197,0,.15);
    animation: envelope-float 3s ease-in-out infinite;
}
@keyframes envelope-float {
    0%,100% { transform: translateY(0); }
    50%      { transform: translateY(-5px); }
}

/* ── STATUS BLOCK ── */
.verify-status {
    border-radius: 10px;
    padding: 14px 18px;
    font-size: .84rem;
    line-height: 1.55;
    margin-bottom: 22px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.verify-status.success {
    background: rgba(34,197,94,.08);
    border: 1px solid rgba(34,197,94,.25);
    color: #4ade80;
}
.verify-status.warning {
    background: rgba(249,115,22,.08);
    border: 1px solid rgba(249,115,22,.25);
    color: #fb923c;
}
.verify-status.info {
    background: rgba(245,197,0,.07);
    border: 1px solid rgba(245,197,0,.2);
    color: rgba(255,255,255,.7);
}
.verify-status .vs-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }

/* ── TIPS LIST ── */
.tips-block {
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 22px;
}
.tips-title {
    font-family: 'Oswald', sans-serif;
    font-size: .72rem;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255,255,255,.3);
    margin-bottom: 10px;
}
.tips-list {
    list-style: none; padding: 0; margin: 0;
    display: flex; flex-direction: column; gap: 7px;
}
.tips-list li {
    font-size: .79rem;
    color: rgba(255,255,255,.45);
    display: flex; align-items: flex-start; gap: 8px;
    line-height: 1.4;
}
.tips-list li::before { content: '→'; color: #f5c500; flex-shrink: 0; font-size: .75rem; margin-top: 1px; }

/* ── RESEND BTN STATE ── */
.auth-btn-outline {
    width: 100%;
    padding: 13px;
    border: 1.5px solid rgba(245,197,0,.4);
    border-radius: 8px;
    background: transparent;
    color: #f5c500;
    font-family: 'Oswald', sans-serif;
    font-size: .95rem;
    font-weight: 600;
    letter-spacing: 1px;
    cursor: pointer;
    transition: all .2s;
    margin-bottom: 10px;
}
.auth-btn-outline:hover {
    background: rgba(245,197,0,.08);
    border-color: #f5c500;
}

/* ── LOGOUT LINK ── */
.logout-link {
    display: block;
    width: 100%;
    padding: 11px;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
    background: transparent;
    color: rgba(255,255,255,.35);
    font-family: 'DM Sans', sans-serif;
    font-size: .84rem;
    cursor: pointer;
    transition: all .2s;
    text-align: center;
    margin-top: 4px;
}
.logout-link:hover {
    color: rgba(255,80,80,.8);
    border-color: rgba(255,80,80,.25);
    background: rgba(255,80,80,.05);
}

/* ── COUNTDOWN ── */
.resend-timer {
    text-align: center;
    font-size: .78rem;
    color: rgba(255,255,255,.3);
    margin-top: 8px;
    min-height: 18px;
}
.resend-timer span { color: #f5c500; font-family: 'Oswald', sans-serif; }
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

        {{-- Animated envelope --}}
        <div class="envelope-wrap">
            <div class="envelope-ring"></div>
            <div class="envelope-ring"></div>
            <div class="envelope-ring"></div>
            <div class="envelope-icon">📬</div>
        </div>

        <div class="auth-header">
            <h1 class="auth-title">Verify Your Email</h1>
            <p class="auth-sub">One step away from SportSync access</p>
        </div>

        {{-- Dynamic status message --}}
        @if (session('status') == 'verification-link-sent')
            <div class="verify-status success">
                <span class="vs-icon">✅</span>
                <span>A new verification link has been sent to your email address. Check your inbox (and spam folder).</span>
            </div>
        @elseif (session('status') == 'verification-send-failed')
            <div class="verify-status warning">
                <span class="vs-icon">⚠️</span>
                <span>We couldn't send the verification email. Please check your mail settings or try again in a moment.</span>
            </div>
        @else
            <div class="verify-status info">
                <span class="vs-icon">📧</span>
                <span>Thanks for signing up! Please verify your email by clicking the link we sent you.</span>
            </div>
        @endif

        {{-- Tips --}}
        <div class="tips-block">
            <div class="tips-title">Didn't receive it?</div>
            <ul class="tips-list">
                <li>Check your spam or junk folder</li>
                <li>Make sure you signed up with the right email</li>
                <li>Wait a minute, then resend below</li>
            </ul>
        </div>

        {{-- Resend form --}}
        <form method="POST" action="{{ route('verification.send') }}" id="resendForm">
            @csrf
            <button type="submit" class="auth-btn" id="resendBtn">📨 Resend Verification Email</button>
        </form>

        <div class="resend-timer" id="resendTimer"></div>

        {{-- Logout --}}
        <form method="POST" action="{{ route('logout') }}" style="margin-top:12px">
            @csrf
            <button type="submit" class="logout-link">← Log out &amp; use different account</button>
        </form>

    </div>
</div>

<script src="{{ asset('auth.js') }}"></script>
<script>
(function () {
    // Resend cooldown — 60 seconds after a link-sent status
    var sent = {{ session('status') === 'verification-link-sent' ? 'true' : 'false' }};
    if (!sent) return;

    var btn   = document.getElementById('resendBtn');
    var timer = document.getElementById('resendTimer');
    var secs  = 60;

    btn.disabled = true;
    btn.style.opacity = '.5';
    btn.style.cursor  = 'not-allowed';

    var iv = setInterval(function () {
        secs--;
        if (secs <= 0) {
            clearInterval(iv);
            btn.disabled = false;
            btn.style.opacity = '';
            btn.style.cursor  = '';
            timer.textContent = '';
        } else {
            timer.innerHTML = 'You can resend in <span>' + secs + 's</span>';
        }
    }, 1000);
    timer.innerHTML = 'You can resend in <span>' + secs + 's</span>';
})();
</script>
</body>
</html>