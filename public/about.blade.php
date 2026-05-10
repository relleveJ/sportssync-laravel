@extends('layouts.landing')

@section('title', 'About — SportSync')

@push('styles')
{{-- No extra CSS file needed — About Bot styles are now inside chatbot.css --}}
@endpush

@section('main')
<section class="section-container" id="about-page">

  {{-- ── Existing header (unchanged) ── --}}
  <div class="section-header reveal">
    <span class="section-label">ABOUT</span>
    <h2 class="section-title">What is SportSync?</h2>
  </div>

  {{-- ── Teaser + Open button ── --}}
  <div style="max-width:680px;margin:0 auto;text-align:center;">
    <p style="color:var(--gray-light);font-size:1.05rem;line-height:1.7;margin-bottom:24px;">
      SportsSync is a real-time sports management system — live scoring,
      player analytics, and match reports for every sport, all in one place.
      <br>
      <strong style="color:var(--white);">But why read about it when you can hear it straight from the bot? 😏</strong>
    </p>
    <button id="ab-open-btn">
      <span class="ab-btn-icon">🤖</span>
      Let the Bot Explain It
    </button>
  </div>

  {{-- ── Existing feature cards (unchanged) ── --}}
  <div class="features-grid" style="margin-top:56px;">
    <div class="feature-card reveal">
      <div class="feature-icon">⚡</div>
      <h3 class="feature-title">Live Scoring</h3>
      <p class="feature-desc">Update scores in real-time with instant broadcast and minimal latency.</p>
    </div>
    <div class="feature-card reveal">
      <div class="feature-icon">📊</div>
      <h3 class="feature-title">Analytics</h3>
      <p class="feature-desc">Visualize trends, player performance and match summaries with export options.</p>
    </div>
    <div class="feature-card reveal">
      <div class="feature-icon">👥</div>
      <h3 class="feature-title">Player Profiles</h3>
      <p class="feature-desc">Track individual and team stats across matches and seasons.</p>
    </div>
  </div>

  {{-- ── Existing mission + how it works (unchanged) ── --}}
  <div style="margin-top:56px;display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
    <div style="flex:1;min-width:280px;">
      <div class="sport-card" style="padding:28px;">
        <h3 class="sport-name">Our Mission</h3>
        <p class="form-hint">Bring accessible, accurate and immediate sports analytics to every level of competition. We focus on performance, reliability and simplicity.</p>
      </div>
    </div>
    <div style="flex:2;min-width:300px;">
      <div class="sport-card" style="padding:28px;">
        <h3 class="sport-name">How it works</h3>
        <ol style="text-align:left;color:var(--gray);line-height:1.8;padding-left:18px;">
          <li>Score events are recorded live via admin UIs.</li>
          <li>Events stream to viewers and analytics engines in real-time.</li>
          <li>Post-match reports and exports are generated automatically.</li>
        </ol>
      </div>
    </div>
  </div>

  <div style="text-align:center;margin-top:44px;">
    <a href="{{ route('contact') }}" class="btn btn-cta">Contact Us</a>
  </div>

</section>


{{-- ════════════════════════════════════════════
     ABOUT BOT MODAL
     Styles live in chatbot.css (already loaded by landing.blade.php)
     Logic lives in chatbot.js (already loaded by landing.blade.php)
     NO extra files needed.
════════════════════════════════════════════ --}}
<div id="ab-overlay">
  <div id="ab-modal">

    {{-- Header --}}
    <div id="ab-header-modal">
      <div class="ab-avatar-wrap">
        <div class="ab-avatar-modal">
          <span id="ab-avatar-face-modal">⚡</span>
        </div>
        <span class="ab-status-dot-modal"></span>
      </div>
      <div class="ab-header-info-modal">
        <p class="ab-bot-name-modal">SYNCBOT ⚡ — About Mode</p>
        <p class="ab-bot-sub-modal">Makulit but informative 😏</p>
      </div>

      {{-- Tabs --}}
      <div id="ab-tabs">
        <button class="ab-tab ab-tab-active" data-tab="about">About</button>
        <button class="ab-tab" data-tab="crew">Crew</button>
        <button class="ab-tab" data-tab="contrib">Contribs</button>
        <button class="ab-tab" data-tab="story">📖 Story</button>
      </div>

      <button id="ab-close-modal" aria-label="Close">✕</button>
    </div>

    {{-- Chat scroll area --}}
    <div id="ab-chat"></div>

    {{-- Footer quick actions --}}
    <div id="ab-footer">
      <button class="ab-action-btn ab-primary" id="ab-footer-story">
        📖 Tell me a story
      </button>
      <button class="ab-action-btn" id="ab-footer-crew">
        👥 Meet the Crew
      </button>
      <button class="ab-action-btn" id="ab-footer-contrib">
        🔧 Contributions
      </button>
    </div>

  </div>
</div>
{{-- chatbot.js is already loaded by landing.blade.php via @stack('scripts') --}}
@endsection
