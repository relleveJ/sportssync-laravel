<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name', 'SportSync'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('landingpage.css') }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('chatbot.css') }}">
    <meta name="theme-color" content="#FFD700">
    <script>
        // Expose the current authenticated user ID to client JS (null when guest)
        window.SS_USER_ID = @json(Auth::id());
    </script>
    <style>
        /* Sign-out button: always red in light or dark themes */
        .nav-auth-btn.nav-logout,
        .nav-links .nav-link.nav-logout {
            color: #ff3b30 !important;
            border-color: transparent !important;
        }
        .nav-auth-btn.nav-logout:hover,
        .nav-links .nav-link.nav-logout:hover {
            color: #ff1a1a !important;
        }
    </style>
    @stack('styles')
</head>
{{--
=================================================================
  SportsSync Chatbot — FINAL HTML SNIPPET
  
  Paste ALL of this just before </body> in:
  resources/views/layouts/landing.blade.php
 
  Also make sure these two lines exist in landing.blade.php:
  
  In <head>:
    <link rel="stylesheet" href="{{ asset('chatbot.css') }}">
  
  Before </body> (after this snippet):
    <script src="{{ asset('chatbot.js') }}"></script>
=================================================================
--}}
 
{{-- Toast notification (must be OUTSIDE #cb-window) --}}
<div id="cb-toast"></div>
 
{{-- Floating trigger button --}}
<button id="cb-trigger" class="cb-mood-normal" aria-label="Open SyncBot" title="Chat with SyncBot">
    <span id="cb-trigger-face">😏</span>
    <span id="cb-badge"></span>
</button>
 
{{-- Chat window --}}
<div id="cb-window" role="dialog" aria-modal="true" aria-label="SyncBot Chat">
 
    {{-- Header --}}
    <div id="cb-header" data-mood="normal">
        <div class="cb-avatar">
            <span id="cb-avatar-face">😏</span>
        </div>
        <div class="cb-header-info">
            <p class="cb-header-name">SyncBot ⚡</p>
            <span class="cb-header-status">
                <span class="cb-status-dot"></span>
                Online — Laging handa! 😎
            </span>
        </div>
        <button id="cb-reset-lang" title="Change language">🌐</button>
        <button id="cb-close" aria-label="Close">✕</button>
    </div>
 
    {{-- Mood bar strip --}}
    <div id="cb-mood-bar"></div>
 
    {{-- Language picker --}}
    <div id="cb-lang-screen">
        <span class="cb-lang-mascot">🤖</span>
        <p class="cb-lang-title">
            🌐 Choose your language<br>
            <small style="font-weight:400;color:#888;font-size:.82rem;">Piliin ang wika</small>
        </p>
        <p class="cb-lang-subtitle">I'll chat with you in your preferred language!</p>
        <div class="cb-lang-buttons">
            <button class="cb-lang-btn" id="cb-lang-en">
                <span class="cb-lang-emoji">🇺🇸</span>
                English
            </button>
            <button class="cb-lang-btn" id="cb-lang-tl">
                <span class="cb-lang-emoji">🇵🇭</span>
                Tagalog
            </button>
        </div>
    </div>
 
    {{-- Chat screen --}}
    <div id="cb-chat-screen" class="cb-hidden">
        <div id="cb-messages"></div>
        <div id="cb-input-area">
            <textarea
                id="cb-input"
                placeholder="Ask about players, matches, standings…"
                rows="1"
                autocomplete="off"
                spellcheck="false"
            ></textarea>
            <button id="cb-send" aria-label="Send">➤</button>
        </div>
    </div>
 
</div>
<body>

<nav class="navbar" id="navbar">
    <div class="nav-container">
        <a href="{{ url('/') }}" class="nav-logo">
            <span class="logo-bolt">⚡</span>{{ config('app.name', 'SportSync') }}
        </a>
        <button class="hamburger" id="hamburger" aria-label="Toggle navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        @php
            $displayName = null;
            if (Auth::check()) {
                $rawDisplay = trim((string)(Auth::user()->display_name ?? ''));
                if ($rawDisplay !== '') {
                    $displayName = Auth::user()->display_name;
                } else {
                    $displayName = Auth::user()->username ?? Auth::user()->name ?? Auth::user()->email ?? 'User';
                }
            }
        @endphp

                <ul class="nav-links" id="nav-links">
                    <li><a href="#hero" class="nav-link active">Home</a></li>
                    <li><a href="#sports" class="nav-link">Sports</a></li>
                        <li><a href="{{ Auth::check() ? asset('analytics/analytics.php') : route('login') . '?next=' . urlencode('analytics/analytics.php') }}" class="nav-link">Analytics</a></li>
                        <li><a href="{{ Auth::check() ? asset('analytics/players.php') : route('login') . '?next=' . urlencode('analytics/players.php') }}" class="nav-link">Players</a></li>
                        <li><a href="#features" class="nav-link">Features</a></li>
                        <li><a href="{{ route('about') }}" class="nav-link">About</a></li>
                        <li><a href="{{ route('contact') }}" class="nav-link">Contact</a></li>

                        {{-- Mobile-only auth items (displayed inside the hamburger menu) --}}
                        @auth
                            <li class="mobile-only"><span class="nav-user">👤 {{ $displayName }}</span></li>
                            <li class="mobile-only"><a href="#" class="nav-link nav-logout" onclick="signOutConfirm(event)">Sign Out</a></li>
                        @else
                            <li class="mobile-only"><a href="{{ route('login') }}" class="nav-link">Sign In</a></li>
                            <li class="mobile-only"><a href="{{ route('register') }}" class="nav-link">Register</a></li>
                        @endauth
                </ul>

        <button id="theme-toggle" class="nav-theme-toggle" aria-label="Toggle color theme" aria-pressed="false">🌙</button>

        <div class="nav-auth">
            @auth
                <span class="nav-user">👤 {{ $displayName }}</span>
                <a href="#" class="nav-auth-btn nav-logout" onclick="signOutConfirm(event)">Sign Out</a>
                <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none">@csrf</form>
            @else
                <a href="{{ route('login') }}" class="nav-auth-btn nav-login">Sign In</a>
                <a href="{{ route('register') }}" class="nav-auth-btn nav-register">Register</a>
            @endauth
        </div>
    </div>
</nav>

<!-- HERO -->
<section class="hero" id="hero">
    <canvas class="hero-canvas" id="heroCanvas"></canvas>
    <div class="hero-grid-overlay"></div>
    <div class="hero-content reveal">
        <div class="hero-badge">LIVE ANALYTICS PLATFORM</div>
        <h1 class="hero-title">SportSync: A<br><span class="title-accent">Data-Driven</span><br>Sports Analytics System</h1>
        <p class="hero-sub">Real-time scores. Deep analytics.<br>Every sport. One platform.</p>
        <div class="hero-cta">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary">Open Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-primary">Sign In to Score</a>
            @endauth
            <a href="{{ Auth::check() ? asset('analytics/analytics.php') : route('login') . '?next=' . urlencode('analytics/analytics.php') }}" class="btn btn-outline">Analytics</a>
            <a href="{{ Auth::check() ? asset('analytics/players.php') : route('login') . '?next=' . urlencode('analytics/players.php') }}" class="btn btn-outline">Players</a>
            <a href="#sports" class="btn btn-outline">Explore Sports</a>
        </div>
    </div>
    <a href="#sports" class="scroll-indicator" aria-label="Scroll down">
        <div class="scroll-arrow"></div>
    </a>
</section>

<!-- Main dynamic area (landing page sections or dashboard content) -->
@yield('main')

<!-- Footer (kept from landing page) -->
<footer class="footer" id="contact">
    <div class="footer-main">
        <div class="footer-brand">
            <a href="#hero" class="footer-logo"><span class="logo-bolt">⚡</span>SportSync</a>
            <p class="footer-tagline">Real-time scores. Deep analytics.<br>Every sport. One platform.</p>
        </div>
        <nav class="footer-nav">
            <h4 class="footer-nav-title">Quick Links</h4>
            <ul>
                <li><a href="#hero">Home</a></li>
                <li><a href="#sports">Sports</a></li>
                <li><a href="#features">Features</a></li>
                <li><a href="#about">About</a></li>
            </ul>
        </nav>
        <div class="footer-contact">
            <h4 class="footer-nav-title">Contact</h4>
            <p class="footer-contact-line">Email: <a href="mailto:contact@sportssync.example">contact@sportssync.example</a></p>
            <p class="footer-contact-line">About: SportSync is a real-time sports analytics platform that delivers live scoring, player analytics and match reports.</p>
        </div>
    </div>
    <div class="footer-bottom">
        <span>© {{ date('Y') }} SportSync. All rights reserved.</span>
    </div>
</footer>

<script src="{{ asset('landingpage.js') }}"></script>
<script>
    (function() {
        function shouldReloadOnBack(event) {
            try {
                if (event && event.persisted) return true;
                var perf = window.performance || window.webkitPerformance || window.mozPerformance;
                if (perf && perf.getEntriesByType) {
                    var nav = perf.getEntriesByType('navigation')[0];
                    if (nav && nav.type === 'back_forward') return true;
                }
                if (performance && performance.navigation && performance.navigation.type === 2) return true;
            } catch (e) {
                // ignore
            }
            return false;
        }
        window.addEventListener('pageshow', function(e) {
            if (shouldReloadOnBack(e)) {
                window.location.reload();
            }
        });
    })();
</script>
<script>
    function signOutConfirm(e) {
        e.preventDefault();
        var ok = confirm('Are you sure you want to sign out? Any unsaved changes will be lost.');
        if (ok) {
            var f = document.getElementById('logout-form');
            if (f) f.submit();
        }
    }
</script>
@stack('scripts')

</body>
</html>
