@extends('layouts.landing')

@section('title', 'About — SportSync')

@section('main')
<section class="section-container" id="about-page">
  <div class="section-header reveal">
    <span class="section-label">ABOUT</span>
    <h2 class="section-title">What is SportSync?</h2>
  </div>

  <div style="max-width:980px;margin:0 auto;text-align:center;">
    <p style="color:var(--gray-light);font-size:1.05rem;line-height:1.7;">SportSync is a real-time sports analytics platform that brings live scoring, player profiles, and match analytics together in one modern interface. Built for match officials, coaches and fans, SportSync provides instant insights and exportable reports so teams can make data-driven decisions.</p>
  </div>

  <div class="features-grid" style="margin-top:48px;">
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
@endsection
