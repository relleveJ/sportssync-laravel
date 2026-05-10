<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="auth-check-url" content="{{ url('/auth/check') }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            <script>
                // Lightweight client-side guard: check server session on page load for
                // pages that expect an authenticated user. If unauthenticated, redirect
                // to login. This improves consistency during bfcache/back navigation.
                (function(){
                    try {
                        var checkUrl = document.querySelector('meta[name="auth-check-url"]').getAttribute('content');
                        // Only run this check for pages that include the 'auth' middleware on server side.
                        // We detect presence of a body attribute or class if you want to scope checks; for
                        // simplicity this only runs on initial page load and won't interfere with guest pages.
                        fetch(checkUrl, { credentials: 'same-origin', cache: 'no-store' })
                          .then(function(res){ return res.ok ? res.json() : null; })
                          .then(function(js){ if (js && !js.authenticated) { window.location.replace("{{ route('login') }}"); } })
                          .catch(function(){ /* network failure — don't redirect */ });
                    } catch (e) {}
                })();
            
            // Real-time listener for user status updates — will force logout when
            // the current user's status becomes non-approved.
            (function(){
                try {
                    var authCheckMeta = document.querySelector('meta[name="auth-check-url"]');
                    var authCheckUrl = authCheckMeta ? authCheckMeta.getAttribute('content') : '/auth/check';
                    var WS_HOST = location.hostname || '127.0.0.1';
                    var WS_PORT = 3000;
                    var wsUrl = ((location.protocol === 'https:') ? 'wss://' : 'ws://') + WS_HOST + ':' + WS_PORT;
                    var ws = null;
                    function initWS(){
                        try { ws = new WebSocket(wsUrl); ws.addEventListener('message', onMessage); ws.addEventListener('error', function(){}); ws.addEventListener('close', function(){ setTimeout(initWS, 3000); }); } catch (e) { setTimeout(initWS, 3000); }
                    }
                    function onMessage(ev){
                        try {
                            var msg = JSON.parse(ev.data);
                            if (!msg) return;
                            var type = msg.type || (msg.payload && msg.payload.type) || null;
                            var payload = msg.payload || msg;
                            if (type === 'user_status_change' || (payload && payload.type === 'user_status_change')) {
                                var data = payload.payload || payload;
                                if (!data || !data.user_id) return;
                                // If affected user is current user, re-check auth and force logout
                                fetch(authCheckUrl, { credentials: 'same-origin', cache: 'no-store' })
                                  .then(function(res){ return res.ok ? res.json() : null; })
                                  .then(function(js){ if (js && js.authenticated && String(js.user_id) === String(data.user_id) && String((js.status||'').toLowerCase()) !== 'approved') { window.location.replace('/legacy-logout'); } })
                                  .catch(function(){});
                            }
                        } catch (e) {}
                    }
                    initWS();
                    try { var bc = new BroadcastChannel('sportssync:user_status'); bc.onmessage = function(e){ if (e.data && e.data.type === 'user_status_change') { var uid = e.data.payload && e.data.payload.user_id; if (uid) { fetch(authCheckUrl, { credentials: 'same-origin', cache: 'no-store' }).then(function(res){ return res.ok ? res.json() : null; }).then(function(js){ if (js && js.authenticated && String(js.user_id) === String(uid) && String((js.status||'').toLowerCase()) !== 'approved') { window.location.replace('/legacy-logout'); } }).catch(function(){}); } }; }; } catch (e) {}
                } catch (e) {}
            })();
            </script>

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                @isset($slot)
                    {{ $slot }}
                @else
                    @yield('content')
                @endisset
            </main>
        </div>
    </body>
</html>
