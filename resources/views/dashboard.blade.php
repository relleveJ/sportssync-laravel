@extends('layouts.landing')

@section('title', 'SportSync — Data-Driven Sports Analytics')

@section('main')
<!-- ══════════════════════════ SPORTS SELECTION ══════════════════════════ -->
<section class="sports-section" id="sports">
  <div class="section-container">
    <div class="section-header reveal">
      <span class="section-label">CHOOSE YOUR SPORT</span>
      <h2 class="section-title">Pick Your Arena</h2>
    </div>
    <div class="sports-grid">

      @php
        $user = Auth::user();
        // Treat superadmin (and legacy scorekeeper) as admin for admin UI access (case-insensitive)
        $isAdmin = false;
        if ($user) {
          $r = strtolower((string)($user->role ?? ''));
          $isAdmin = in_array($r, ['admin', 'superadmin', 'scorekeeper'], true);
        }
        $bbLink = $isAdmin ? 'Basketball%20Admin%20UI/index.php' : 'Basketball%20Admin%20UI/basketball_viewer.php';
        $bbNext = $user ? $bbLink : route('login') . '?next=' . urlencode('Basketball Admin UI/basketball_viewer.php');
        $vbLink = $isAdmin ? 'Volleyball%20Admin%20UI/volleyball_admin.php' : 'Volleyball%20Admin%20UI/volleyball_viewer.php';
        $vbNext = $user ? $vbLink : route('login') . '?next=' . urlencode('Volleyball Admin UI/volleyball_viewer.php');
        $bdLink = $isAdmin ? 'Badminton%20Admin%20UI/badminton_admin.php' : 'Badminton%20Admin%20UI/badminton_viewer.php';
        $bdNext = $user ? $bdLink : route('login') . '?next=' . urlencode('Badminton Admin UI/badminton_viewer.php');
        $ttLink = $isAdmin ? 'TABLE%20TENNIS%20ADMIN%20UI/tabletennis_admin.php' : 'TABLE%20TENNIS%20ADMIN%20UI/tabletennis_viewer.php';
        $ttNext = $user ? $ttLink : route('login') . '?next=' . urlencode('TABLE TENNIS ADMIN UI/tabletennis_viewer.php');
        $dartsViewerPath = public_path('DARTS ADMIN UI/viewer.php');
        if (file_exists($dartsViewerPath)) {
            $viewerHref = 'DARTS%20ADMIN%20UI/viewer.php';
            $adminHref  = 'DARTS%20ADMIN%20UI/index.php';
        } else {
            $viewerHref = '/admin/darts/viewer';
            $adminHref  = '/admin/darts';
        }
        $drLink = $isAdmin ? $adminHref : $viewerHref;
        $drNext = $user ? $drLink : route('login') . '?next=' . urlencode(ltrim($viewerHref, '/'));

        $analyticsHref = $user ? 'analytics/analytics.php' : route('login') . '?next=' . urlencode('analytics/analytics.php');
        $profilesHref  = $user ? 'analytics/players.php' : route('login') . '?next=' . urlencode('analytics/players.php');
      @endphp

      <a href="{{ $bbNext }}" class="sport-card reveal" data-sport="basketball">
        <div class="sport-icon">🏀</div>
        <h3 class="sport-name">Basketball</h3>
        <p class="sport-desc">Track points, assists & rebounds live</p>
        <div class="sport-arrow">→</div>
      </a>

      <a href="{{ $vbNext }}" class="sport-card reveal" data-sport="volleyball">
        <div class="sport-icon">🏐</div>
        <h3 class="sport-name">Volleyball</h3>
        <p class="sport-desc">Set-by-set scoring & rally analytics</p>
        <div class="sport-arrow">→</div>
      </a>

      <a href="{{ $bdNext }}" class="sport-card reveal" data-sport="badminton">
        <div class="sport-icon">🏸</div>
        <h3 class="sport-name">Badminton</h3>
        <p class="sport-desc">Shuttle speed, rally depth & match stats</p>
        <div class="sport-arrow">→</div>
      </a>

      <a href="{{ $ttNext }}" class="sport-card reveal" data-sport="tabletennis">
        <div class="sport-icon">🏓</div>
        <h3 class="sport-name">Table Tennis</h3>
        <p class="sport-desc">Per-game breakdowns & spin analytics</p>
        <div class="sport-arrow">→</div>
      </a>

      <a href="{{ $drNext }}" class="sport-card reveal" data-sport="darts">
        <div class="sport-icon">🎯</div>
        <h3 class="sport-name">Darts</h3>
        <p class="sport-desc">Leg averages, finishes & checkout %</p>
        <div class="sport-arrow">→</div>
      </a>

    </div>
  </div>
</section>

<!-- ════════════════════════════ FEATURES ════════════════════════════════ -->
<section class="features-section" id="features">
  <div class="section-container">
    <div class="section-header reveal">
      <span class="section-label">CAPABILITIES</span>
      <h2 class="section-title">Powerful Features Built<br>for Competitors</h2>
    </div>
    <div class="features-grid">

      <div class="feature-card reveal">
        <div class="feature-icon">🏆</div>
        <h3 class="feature-title">Live Scoring</h3>
        <p class="feature-desc">Update scores in real-time during matches with instant broadcast to all connected devices.</p>
        <div class="feature-line"></div>
      </div>

      <a href="{{ $analyticsHref }}" class="feature-card reveal" data-feature="analytics">
        <div class="feature-icon">📊</div>
        <h3 class="feature-title">Analytics Dashboard</h3>
        <p class="feature-desc">Visual stats, trends, and performance charts that reveal patterns invisible to the naked eye.</p>
        <div class="feature-line"></div>
      </a>

      <div class="feature-card reveal">
        <div class="feature-icon">📋</div>
        <h3 class="feature-title">Match Reports</h3>
        <p class="feature-desc">Auto-generated post-match summaries delivered the moment the final buzzer sounds.</p>
        <div class="feature-line"></div>
      </div>

      <div class="feature-card reveal">
        <div class="feature-icon">📤</div>
        <h3 class="feature-title">Export Data</h3>
        <p class="feature-desc">Download results as PDF or Excel with one click — ready for coaches and sponsors.</p>
        <div class="feature-line"></div>
      </div>

      <a href="{{ $profilesHref }}" class="feature-card reveal" data-feature="profiles">
        <div class="feature-icon">👤</div>
        <h3 class="feature-title">Player Profiles</h3>
        <p class="feature-desc">Track individual and team statistics across every match, tournament, and season.</p>
        <div class="feature-line"></div>
      </a>

      <div class="feature-card reveal">
        <div class="feature-icon">🗓️</div>
        <h3 class="feature-title">Tournament Bracket</h3>
        <p class="feature-desc">Manage schedules and brackets automatically — from group stage to grand finals.</p>
        <div class="feature-line"></div>
      </div>

    </div>
  </div>
</section>

<!-- ═══════════════════════════════ CTA BANNER ═══════════════════════════ -->
<section class="cta-section" id="about">
  <div class="cta-bg-lines"></div>
  <div class="cta-content reveal">
    <div class="cta-eyebrow">GET STARTED TODAY</div>
    @if($user)
      @php $role = strtolower((string)($user->role ?? '')); @endphp
      @if($role === 'superadmin')
        <h2 class="cta-title">Superadmin Console</h2>
        <p class="cta-sub">Access the legacy admin landing page and full system controls.</p>
        <a href="/adminlanding_page.php" class="btn btn-cta">Open Admin Landing</a>
      @elseif(in_array($role, ['admin','scorekeeper']))
        <h2 class="cta-title">SportSync Admin Dashboard</h2>
        <p class="cta-sub">Open your dashboard to start scoring and analyzing matches.</p>
        <a href="#sports" class="btn btn-cta">Start Scoring Now</a>
      @elseif($role === 'viewer')
        <h2 class="cta-title">SportSync — Stay Updated. Stay Ahead.</h2>
        <p class="cta-sub">Explore live scores and analytics for your favorite sports.</p>
      <a href="#sports" class="btn btn-cta">Open Viewer</a>
      @else
        <a href="#sports" class="btn btn-cta">Open</a>
      @endif
    @else
      <a href="{{ route('register') }}" class="btn btn-cta">Start Scoring Now</a>
    @endif
  </div>
</section>



@endsection
