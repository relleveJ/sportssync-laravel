<?php
require_once __DIR__ . '/auth.php';
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SportSync — Data-Driven Sports Analytics</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="landingpage.css" />
</head>
<body>

  <!-- ═══════════════════════════════ NAVBAR ═══════════════════════════════ -->
  <nav class="navbar" id="navbar">
    <div class="nav-container">
      <a href="#hero" class="nav-logo">
        <span class="logo-bolt">⚡</span>SportSync
      </a>
      <button class="hamburger" id="hamburger" aria-label="Toggle navigation" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
      <ul class="nav-links" id="nav-links">
        <li><a href="#hero" class="nav-link active">Home</a></li>
        <li><a href="#sports" class="nav-link">Sports</a></li>
        <li><a href="#features" class="nav-link">Features</a></li>
        <li><a href="#about" class="nav-link">About</a></li>
        <li><a href="#contact" class="nav-link">Contact</a></li>
      </ul>
      <div class="nav-auth">
        <?php if ($user): ?>
          <span class="nav-user">👤 <?= htmlspecialchars($user['display_name'] ?: $user['username']) ?></span>
          <a href="logout.php" class="nav-auth-btn nav-logout">Sign Out</a>
        <?php else: ?>
          <a href="login.php"    class="nav-auth-btn nav-login">Sign In</a>
          <a href="register.php" class="nav-auth-btn nav-register">Register</a>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <!-- ════════════════════════════════ HERO ════════════════════════════════ -->
  <section class="hero" id="hero">
    <canvas class="hero-canvas" id="heroCanvas"></canvas>
    <div class="hero-grid-overlay"></div>
    <div class="hero-content reveal">
      <div class="hero-badge">LIVE ANALYTICS PLATFORM</div>
      <h1 class="hero-title">SportSync: A<br><span class="title-accent">Data-Driven</span><br>Sports Analytics System</h1>
      <p class="hero-sub">Real-time scores. Deep analytics.<br>Every sport. One platform.</p>
      <div class="hero-cta">
        <?php if ($user): ?>
          <a href="#sports" class="btn btn-primary">Open Dashboard</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-primary">Sign In to Score</a>
        <?php endif; ?>
        <a href="#sports" class="btn btn-outline">Explore Sports</a>
      </div>
    </div>
    <a href="#sports" class="scroll-indicator" aria-label="Scroll down">
      <div class="scroll-arrow"></div>
    </a>
  </section>

  <!-- ══════════════════════════ SPORTS SELECTION ══════════════════════════ -->
  <section class="sports-section" id="sports">
    <div class="section-container">
      <div class="section-header reveal">
        <span class="section-label">CHOOSE YOUR SPORT</span>
        <h2 class="section-title">Pick Your Arena</h2>
      </div>
      <div class="sports-grid">

        <a href="<?= $user ? 'Basketball%20Admin%20UI/index.php' : 'login.php?next=Basketball%20Admin%20UI%2Findex.php' ?>" class="sport-card reveal" data-sport="basketball">
          <div class="sport-icon">🏀</div>
          <h3 class="sport-name">Basketball</h3>
          <p class="sport-desc">Track points, assists & rebounds live</p>
          <div class="sport-arrow">→</div>
        </a>

        <a href="<?= $user ? 'Volleyball%20Admin%20UI/volleyball_admin.php' : 'login.php?next=volleyball.php' ?>" class="sport-card reveal" data-sport="volleyball">
          <div class="sport-icon">🏐</div>
          <h3 class="sport-name">Volleyball</h3>
          <p class="sport-desc">Set-by-set scoring & rally analytics</p>
          <div class="sport-arrow">→</div>
        </a>

        <a href="<?= $user ? 'Badminton%20Admin%20UI/badminton_admin.php' : 'login.php?next=Badminton%20Admin%20UI%2Fbadminton_admin.php' ?>" class="sport-card reveal" data-sport="badminton">
          <div class="sport-icon">🏸</div>
          <h3 class="sport-name">Badminton</h3>
          <p class="sport-desc">Shuttle speed, rally depth & match stats</p>
          <div class="sport-arrow">→</div>
        </a>

        <a href="<?= $user ? 'TABLE%20TENNIS%20ADMIN%20UI/tabletennis_admin.php' : 'login.php?next=TABLE%20TENNIS%20ADMIN%20UI%2Ftabletennis_admin.php' ?>" class="sport-card reveal" data-sport="tabletennis">
          <div class="sport-icon">🏓</div>
          <h3 class="sport-name">Table Tennis</h3>
          <p class="sport-desc">Per-game breakdowns & spin analytics</p>
          <div class="sport-arrow">→</div>
        </a>

        <a href="<?= $user ? 'DARTS%20ADMIN%20UI/index.php' : 'login.php?DARTS%20ADMIN%20UI/index.php' ?>" class="sport-card reveal" data-sport="darts">
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

        <div class="feature-card reveal">
          <div class="feature-icon">📊</div>
          <h3 class="feature-title">Analytics Dashboard</h3>
          <p class="feature-desc">Visual stats, trends, and performance charts that reveal patterns invisible to the naked eye.</p>
          <div class="feature-line"></div>
        </div>

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

        <div class="feature-card reveal">
          <div class="feature-icon">👤</div>
          <h3 class="feature-title">Player Profiles</h3>
          <p class="feature-desc">Track individual and team statistics across every match, tournament, and season.</p>
          <div class="feature-line"></div>
        </div>

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
      <h2 class="cta-title">Ready to Track<br>Every Point?</h2>
      <p class="cta-sub">Start scoring, analyzing, and winning with SportSync.</p>
      <a href="<?= $user ? '#sports' : 'register.php' ?>" class="btn btn-cta">Start Scoring Now</a>
    </div>
  </section>

  <!-- ════════════════════════════════ FOOTER ══════════════════════════════ -->
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
    </div>
    <div class="footer-bottom">
      <span>© 2025 SportSync. All rights reserved.</span>
    </div>
  </footer>

  <script src="landingpage.js"></script>
</body>
</html>