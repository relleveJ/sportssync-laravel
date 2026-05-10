<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — SportSync</title>
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
}
.step.done .step-circle {
    background: rgba(34,197,94,.15);
    border-color: rgba(34,197,94,.5);
    color: #4ade80;
}
.step.active .step-circle {
    background: #f5c500;
    border-color: #f5c500;
    color: #000;
    box-shadow: 0 0 18px rgba(245,197,0,.4);
}
.step-label {
    font-size: .62rem;
    text-transform: uppercase;
    letter-spacing: .8px;
    font-family: 'Oswald', sans-serif;
}
.step.done .step-label   { color: #4ade80; }
.step.active .step-label { color: #f5c500; }
.step-connector {
    width: 48px; height: 2px;
    margin-bottom: 18px;
}
.step-connector.done-line { background: rgba(34,197,94,.3); }
.step-connector.active-line { background: rgba(245,197,0,.3); }

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

/* ── PASSWORD STRENGTH ── */
.pw-strength { height: 4px; background: rgba(255,255,255,.08); border-radius: 4px; margin-top: 8px; overflow: hidden; }
.pw-bar      { height: 100%; width: 0; border-radius: 4px; transition: width .4s, background .4s; }

/* ── REQUIREMENTS LIST ── */
.pw-reqs {
    margin: 10px 0 0;
    padding: 0;
    list-style: none;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 12px;
}
.pw-reqs li {
    font-size: .73rem;
    color: rgba(255,255,255,.35);
    display: flex;
    align-items: center;
    gap: 5px;
    transition: color .2s;
}
.pw-reqs li.met { color: #4ade80; }
.pw-reqs li::before { content: '○'; font-size: .65rem; }
.pw-reqs li.met::before { content: '●'; color: #4ade80; }
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

        {{-- Step indicator — step 3 active, 1+2 done --}}
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
            <h1 class="auth-title">Set New Password</h1>
            <p class="auth-sub">Choose a strong password for your account</p>
        </div>

        @if ($errors->any())
            <div class="auth-alert">{{ $errors->first() }}</div>
        @endif

        <form class="auth-form" method="POST" action="{{ route('password.store') }}">
            @csrf

            {{-- Hidden reset token --}}
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
                        oninput="checkStrength(this.value)"
                    >
                    <button type="button" class="toggle-pw" onclick="togglePw('password', this)" aria-label="Show password">👁</button>
                </div>
                <div class="pw-strength"><div class="pw-bar" id="pwBar"></div></div>
                <ul class="pw-reqs" id="pwReqs">
                    <li id="req-len">8+ characters</li>
                    <li id="req-upper">Uppercase letter</li>
                    <li id="req-digit">Number</li>
                    <li id="req-special">Special char</li>
                </ul>
                @if ($errors->get('password'))
                    <span class="form-hint" style="color:#ff6b6b;">{{ $errors->first('password') }}</span>
                @endif
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
                @if ($errors->get('password_confirmation'))
                    <span class="form-hint" style="color:#ff6b6b;">{{ $errors->first('password_confirmation') }}</span>
                @endif
            </div>

            <button type="submit" class="auth-btn">🔒 Reset Password</button>
        </form>

        <p class="auth-switch">
            Remembered it? <a href="{{ route('login') }}">Back to Sign In</a>
        </p>

    </div>
</div>

<script src="{{ asset('auth.js') }}"></script>
<script>
function checkStrength(val) {
    const bar = document.getElementById('pwBar');
    const reqs = {
        'req-len':     val.length >= 8,
        'req-upper':   /[A-Z]/.test(val),
        'req-digit':   /[0-9]/.test(val),
        'req-special': /[^A-Za-z0-9]/.test(val),
    };
    const score = Object.values(reqs).filter(Boolean).length;
    const colors = ['#ef4444','#f97316','#f5c500','#22c55e'];
    const widths = ['25%','50%','75%','100%'];
    bar.style.width  = score > 0 ? widths[score - 1] : '0';
    bar.style.background = score > 0 ? colors[score - 1] : 'transparent';
    Object.entries(reqs).forEach(([id, met]) => {
        document.getElementById(id)?.classList.toggle('met', met);
    });
}
</script>
</body>
</html>