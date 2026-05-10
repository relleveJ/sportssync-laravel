<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('auth.css') }}">
    <!-- Prevent caching of the login page so back-button doesn't show stale auth page -->
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <script>
        // Use server-side /auth/check to validate session state. This avoids
        // relying solely on cookies or client-side state and prevents cached
        // pages from allowing access after logout.
        (function(){
            var checkUrl = '{{ url('/auth/check') }}';
            function redirectToDashboard() {
                try { window.location.replace("{{ route('dashboard') }}"); } catch(e) {}
            }
            function doCheck() {
                try {
                    fetch(checkUrl, { credentials: 'same-origin', cache: 'no-store' })
                      .then(function(res){ return res.ok ? res.json() : null; })
                      .then(function(js){ if (js && js.authenticated) redirectToDashboard(); })
                      .catch(function(){
                        // fallback to legacy cookie if fetch fails
                        try { if (document.cookie && /(?:^|;\s*)SS_USER_ID=/.test(document.cookie)) redirectToDashboard(); } catch(e) {}
                      });
                } catch (e) {
                    try { if (document.cookie && /(?:^|;\s*)SS_USER_ID=/.test(document.cookie)) redirectToDashboard(); } catch(e) {}
                }
            }
            // Check immediately on load
            doCheck();
            // Also re-check on pageshow (bfcache/back navigation)
            window.addEventListener('pageshow', function(ev){ if (ev && ev.persisted) doCheck(); });
        })();
    </script>
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
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-sub">Sign in to your account to continue</p>
        </div>

        @if ($errors->any())
            <div class="auth-alert">{{ $errors->first() }}</div>
        @endif
        @if (session('status'))
            <div class="auth-success">{{ session('status') }}</div>
        @endif

        <form class="auth-form" method="POST" action="{{ route('login') }}">
            @csrf

            <div class="form-group">
                <label for="identifier">Username or Email</label>
                <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    placeholder="Enter username or email"
                    value="{{ old('identifier') }}"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="toggle-pw" onclick="togglePw('password', this)" aria-label="Show/hide password">👁</button>
                </div>
            </div>

            <div class="block mt-4" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <label for="remember_me" class="inline-flex items-center" style="display:flex;align-items:center;gap:8px;">
                    <input id="remember_me" type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                    <span class="ms-2 text-sm text-gray-600">Remember me</span>
                </label>

                <div>
                    <a href="{{ route('password.request') }}" class="auth-forgot">Forgot your password?</a>
                </div>
            </div>

            <button type="submit" class="auth-btn">Sign In</button>
        </form>

        <div id="wsStatusMsg" style="margin-top:12px;font-size:0.9rem;color:#999;"></div>

        <p class="auth-switch">
            Don't have an account? <a href="{{ route('register') }}">Create one</a>
        </p>
    </div>

</div>

<script src="{{ asset('auth.js') }}"></script>
<script>
    (function(){
        try {
            var ID = document.getElementById('identifier');
            var MSG = document.getElementById('wsStatusMsg');
            function showMsg(t, color){ if (!MSG) return; MSG.textContent = t || ''; MSG.style.color = color || '#999'; }
            if (!ID) return;
            var wsUrl = ((location.protocol === 'https:') ? 'wss://' : 'ws://') + (location.hostname || '127.0.0.1') + ':3000';
            var ws = null;
            function init(){ try { ws = new WebSocket(wsUrl); ws.addEventListener('message', onMsg); ws.addEventListener('close', function(){ setTimeout(init,3000); }); } catch(e){ setTimeout(init,3000); } }
            function onMsg(ev){ try { var msg = JSON.parse(ev.data); if (!msg) return; if (msg.type === 'user_status_change' || (msg.payload && msg.payload.type === 'user_status_change')) { var d = msg.payload || msg; var p = d.payload || d; var uname = (p.username || p.email || '').toLowerCase(); var cur = ID.value.trim().toLowerCase(); if (!cur || !uname) return; if (cur === uname) { var st = (p.new_status || p.status || '').toLowerCase(); if (st === 'approved' || st === 'active') showMsg('Account approved — you may sign in now.', '#00c853'); else showMsg('Account status: ' + (p.new_status || p.status || 'unknown'), '#ff5252'); } } } catch (e){} }
            try { var bc = new BroadcastChannel('sportssync:user_status'); bc.onmessage = function(e){ if (!e.data) return; var d = e.data; if (d && d.type === 'user_status_change') { var p = d.payload || {}; var uname = (p.username || p.email || '').toLowerCase(); var cur = ID.value.trim().toLowerCase(); if (cur && uname && cur === uname) { var st = (p.new_status || p.status || '').toLowerCase(); if (st === 'approved' || st === 'active') showMsg('Account approved — you may sign in now.', '#00c853'); else showMsg('Account status: ' + (p.new_status || p.status || 'unknown'), '#ff5252'); } } }; } catch (e) {}
            ID.addEventListener('input', function(){ if (MSG) MSG.textContent = ''; });
            init();
        } catch (e) {}
    })();
</script>
</body>
</html>
