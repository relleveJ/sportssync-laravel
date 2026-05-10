<?php
// ── SportSync Guards ──────────────────────────────────────────
$_ss_db_loaded = false;
try {
    $__dbf = file_exists(__DIR__.'/db.php') ? __DIR__.'/db.php' : (file_exists(__DIR__.'/../db.php') ? __DIR__.'/../db.php' : null);
    if ($__dbf) { require_once $__dbf; $_ss_db_loaded = true; }
} catch (Throwable $e) {}
if ($_ss_db_loaded && !empty($pdo)) {
    $__sg=null;foreach([__DIR__,__DIR__.'/..',__DIR__.'/../..'] as $__d){if(file_exists($__d.'/system_guard.php')){$__sg=$__d.'/system_guard.php';break;}}if($__sg) require_once $__sg;
    ss_check_maintenance($pdo);         // viewer: full block
    ss_check_sport($pdo, 'Darts');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__ws_token = getenv('WS_TOKEN') ?: ''; ?>
<meta name="ws-token" content="<?php echo htmlspecialchars($__ws_token, ENT_QUOTES); ?>">
<title>🎯 Darts Live Viewer — Iskorsit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:wght@400;600;700;900&family=Rajdhani:wght@400;600;700&display=swap" rel="stylesheet">
<style>
/* ============================================================
   DARTS LIVE VIEWER — Dark Scoreboard Aesthetic
   ============================================================ */
:root {
  --bg:        #0a0a0a;
  --surface:   #111;
  --surface2:  #181818;
  --border:    #2a2a2a;
  --yellow:    #FFE600;
  --yellow-d:  #c4b200;
  --green:     #00e64d;
  --red:       #ff2233;
  --blue:      #1a54ff;
  --orange:    #ff7700;
  --text:      #f0f0f0;
  --subtext:   #888;
  --p1:        #CC0000;
  --p2:        #003399;
  --p3:        #d4aa00;
  --p4:        #E65C00;
  --glow:      0 0 20px rgba(255,230,0,0.15);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Barlow Condensed', sans-serif;
  min-height: 100vh;
  overflow-x: hidden;
}

/* scanline texture overlay */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 2px,
    rgba(0,0,0,0.08) 2px,
    rgba(0,0,0,0.08) 4px
  );
  pointer-events: none;
  z-index: 9999;
}

/* ---- TOP BAR ---- */
#topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 20px;
  background: #000;
  border-bottom: 3px solid var(--yellow);
  position: sticky;
  top: 0;
  z-index: 100;
  gap: 12px;
  flex-wrap: wrap;
}

#topbar-title {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.6rem;
  color: var(--yellow);
  letter-spacing: 4px;
  text-shadow: 0 0 20px rgba(255,230,0,0.4);
}

#topbar-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}

.meta-pill {
  background: #1a1a1a;
  border: 1px solid #333;
  border-radius: 4px;
  padding: 4px 12px;
  font-size: .8rem;
  color: var(--subtext);
  letter-spacing: 1px;
  text-transform: uppercase;
}

.meta-pill span {
  color: var(--yellow);
  font-weight: 700;
}

#conn-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #444;
  display: inline-block;
  margin-right: 6px;
  transition: background .3s;
}
#conn-dot.live { background: var(--green); box-shadow: 0 0 8px var(--green); animation: pulse-dot 2s infinite; }
#conn-dot.poll { background: var(--yellow); box-shadow: 0 0 8px var(--yellow); }
#conn-dot.off  { background: var(--red); }

@keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.4} }

#conn-label {
  font-size: .75rem;
  color: var(--subtext);
  letter-spacing: 1px;
  text-transform: uppercase;
}

/* ---- MAIN LAYOUT ---- */
#main {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px 16px 40px;
}

/* ---- SCOREBOARD HEADER ---- */
#scoreboard-header {
  text-align: center;
  padding: 18px 0 10px;
  position: relative;
}

#game-type-display {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 4rem;
  color: var(--yellow);
  line-height: 1;
  text-shadow: 0 0 40px rgba(255,230,0,0.3);
  letter-spacing: 8px;
}

#leg-display {
  font-family: 'Rajdhani', sans-serif;
  font-size: 1rem;
  color: var(--subtext);
  letter-spacing: 3px;
  margin-top: 4px;
  text-transform: uppercase;
}

#legs-to-win-display {
  color: var(--yellow);
  font-weight: 700;
}

/* ---- PLAYERS GRID ---- */
#players-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
  margin-top: 24px;
}

/* ---- PLAYER CARD ---- */
.pcard {
  background: var(--surface);
  border: 2px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: box-shadow .3s, border-color .3s, transform .2s;
  position: relative;
}

.pcard.active-turn {
  border-color: var(--yellow);
  box-shadow: 0 0 0 2px var(--yellow), 0 0 30px rgba(255,230,0,0.2);
  transform: translateY(-2px);
}

.pcard.match-winner {
  border-color: var(--green);
  box-shadow: 0 0 0 2px var(--green), 0 0 30px rgba(0,230,77,0.25);
}

/* Active turn indicator */
.pcard.active-turn::before {
  content: '▶ NOW THROWING';
  position: absolute;
  top: -1px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--yellow);
  color: #000;
  font-family: 'Bebas Neue', sans-serif;
  font-size: .65rem;
  letter-spacing: 2px;
  padding: 2px 10px;
  border-radius: 0 0 6px 6px;
  z-index: 2;
  white-space: nowrap;
}

.pcard.match-winner::before {
  content: '🏆 WINNER';
  background: var(--green);
  color: #000;
}

/* Card header strip */
.pcard-header {
  padding: 14px 16px 10px;
  color: #fff;
  position: relative;
}

.pcard-header.color-0 { background: linear-gradient(135deg, #8B0000, var(--p1)); }
.pcard-header.color-1 { background: linear-gradient(135deg, #001a6b, var(--p2)); }
.pcard-header.color-2 { background: linear-gradient(135deg, #6e5800, var(--p3)); }
.pcard-header.color-3 { background: linear-gradient(135deg, #8a3600, var(--p4)); }

.pcard-name {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.5rem;
  letter-spacing: 3px;
  line-height: 1;
  text-shadow: 0 1px 3px rgba(0,0,0,0.5);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pcard-team {
  font-size: .75rem;
  opacity: .8;
  letter-spacing: 2px;
  text-transform: uppercase;
  margin-top: 2px;
}

/* Big score */
.pcard-score-area {
  background: #0a0a0a;
  text-align: center;
  padding: 20px 12px 14px;
  position: relative;
}

.pcard-score {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 4.5rem;
  color: var(--yellow);
  line-height: 1;
  letter-spacing: 4px;
  text-shadow: 0 0 20px rgba(255,230,0,0.25);
  transition: all .3s;
}

.pcard-score.score-low {
  color: var(--red);
  text-shadow: 0 0 20px rgba(255,34,51,0.4);
  animation: urgency .6s ease;
}

@keyframes urgency {
  0%,100%  { transform: scale(1); }
  50% { transform: scale(1.06); }
}

.pcard-score-label {
  font-size: .65rem;
  color: var(--subtext);
  letter-spacing: 2px;
  text-transform: uppercase;
  margin-top: 2px;
}

/* Legs won */
.pcard-legs {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  border-top: 1px solid var(--border);
}

.pcard-legs-label {
  font-size: .7rem;
  color: var(--subtext);
  letter-spacing: 1px;
  text-transform: uppercase;
}

.pcard-legs-count {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.8rem;
  color: var(--yellow);
  letter-spacing: 2px;
}

/* Leg pips */
.pcard-pips {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.pip {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #333;
  border: 1px solid #444;
  transition: background .3s, box-shadow .3s;
}

.pip.won {
  background: var(--yellow);
  border-color: var(--yellow);
  box-shadow: 0 0 6px rgba(255,230,0,0.5);
}

/* Throw history */
.pcard-history {
  padding: 8px 12px 14px;
  min-height: 44px;
  border-top: 1px solid var(--border);
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  align-items: center;
}

.throw-chip {
  background: #1e1e1e;
  border: 1px solid #333;
  color: var(--text);
  font-size: .78rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 3px;
  letter-spacing: 1px;
}

.throw-chip.bust {
  background: rgba(204,0,0,0.2);
  border-color: var(--red);
  color: var(--red);
}

.throw-chip.recent {
  background: rgba(255,230,0,0.12);
  border-color: var(--yellow);
  color: var(--yellow);
}

/* ---- LEGS HISTORY STRIP ---- */
#leg-history-strip {
  margin-top: 28px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 14px 18px;
}

#leg-history-strip h3 {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1rem;
  color: var(--yellow);
  letter-spacing: 3px;
  margin-bottom: 10px;
}

#leg-history-items {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.leg-pill {
  background: #1a1a1a;
  border: 1px solid #2e2e2e;
  border-radius: 4px;
  padding: 5px 14px;
  font-size: .8rem;
  color: var(--subtext);
  letter-spacing: 1px;
}

.leg-pill .lp-winner {
  color: var(--yellow);
  font-weight: 700;
}

/* ---- WAITING SCREEN ---- */
#waiting-screen {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
  text-align: center;
  gap: 20px;
}

.dart-spinner {
  font-size: 3rem;
  animation: spin-dart 2s linear infinite;
}

@keyframes spin-dart { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }

.waiting-title {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 2rem;
  color: var(--yellow);
  letter-spacing: 4px;
}

.waiting-sub {
  font-size: 1rem;
  color: var(--subtext);
  letter-spacing: 1px;
}

/* ---- MATCH WINNER OVERLAY ---- */
#winner-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.88);
  z-index: 500;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 16px;
  text-align: center;
}

#winner-overlay.show { display: flex; }

.winner-crown { font-size: 4rem; animation: bounce-crown 1s ease infinite; }
@keyframes bounce-crown { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-12px)} }

.winner-title {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1rem;
  color: var(--subtext);
  letter-spacing: 6px;
  text-transform: uppercase;
}

.winner-name {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 4rem;
  color: var(--green);
  letter-spacing: 6px;
  text-shadow: 0 0 40px rgba(0,230,77,0.5);
}

.winner-close {
  margin-top: 10px;
  background: #222;
  color: var(--yellow);
  border: 1px solid var(--yellow);
  padding: 10px 28px;
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1rem;
  letter-spacing: 3px;
  cursor: pointer;
  border-radius: 4px;
}

/* ---- LAST UPDATED ---- */
#last-updated {
  text-align: center;
  font-size: .72rem;
  color: #444;
  padding: 12px 0 0;
  letter-spacing: 1px;
}

/* ---- RESPONSIVE ---- */
@media (max-width: 640px) {
  #game-type-display { font-size: 2.8rem; }
  .pcard-score { font-size: 3.5rem; }
  #players-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
}

@media (max-width: 420px) {
  #players-grid { grid-template-columns: 1fr; }
}

/* ---- SCORE CHANGE FLASH ---- */
@keyframes flash-update {
  0%   { background: rgba(255,230,0,0.18); }
  100% { background: transparent; }
}

.score-updated {
  animation: flash-update .5s ease;
}
</style>
</head>
<body>

<!-- TOP BAR -->
<div id="topbar">
  <a href="javascript:history.back()" class="back-btn" title="Go Back">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
      <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
    </svg>
    <span class="back-btn-text">BACK</span>
  </a>
  <div id="topbar-title">🎯 DARTS ISKORSIT — LIVE VIEWER</div>
  <div id="topbar-meta">
    <div class="meta-pill">GAME: <span id="meta-game">—</span></div>
    <div class="meta-pill">LEG: <span id="meta-leg">—</span></div>
    <div class="meta-pill">LEGS TO WIN: <span id="meta-ltw">—</span></div>
    <div style="display:flex;align-items:center;gap:5px">
      <span id="conn-dot"></span>
      <span id="conn-label">Connecting…</span>
    </div>
  </div>
</div>

<!-- MAIN -->
<div id="main">
  <div id="scoreboard-header">
    <div id="game-type-display">—</div>
    <div id="leg-display">LEG <span id="leg-num">—</span> &nbsp;|&nbsp; FIRST TO <span id="legs-to-win-display">—</span></div>
  </div>

  <!-- WAITING / PLAYERS -->
  <div id="waiting-screen">
    <div class="dart-spinner">🎯</div>
    <div class="waiting-title">Waiting for Game…</div>
    <div class="waiting-sub">The scoreboard will update automatically when a match starts.</div>
  </div>

  <div id="players-grid" style="display:none"></div>

  <!-- Leg history strip -->
  <div id="leg-history-strip" style="display:none">
    <h3>🏁 LEG RESULTS</h3>
    <div id="leg-history-items"></div>
  </div>

  <div id="last-updated"></div>
</div>

<!-- MATCH WINNER OVERLAY -->
<div id="winner-overlay">
  <div class="winner-crown">🏆</div>
  <div class="winner-title">Match Winner</div>
  <div class="winner-name" id="winner-name-display">—</div>
  <button class="winner-close" onclick="hideWinnerOverlay()">CLOSE</button>
</div>

<script src="darst_viewer.js?<?php echo time(); ?>"></script>

</body>
</html>