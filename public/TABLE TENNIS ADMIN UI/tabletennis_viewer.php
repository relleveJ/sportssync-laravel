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
    ss_check_sport($pdo, 'Table Tennis');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Table Tennis Live Score — Viewer</title>
<link rel="stylesheet" href="tabletennis_viewer.css">
</head>
<body>

<nav class="top-nav">
  <a href="javascript:history.back()" class="back-btn" title="Go Back">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" width="18" height="18">
      <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
    </svg>
    <span class="back-btn-text">BACK</span>
  </a>
  <div class="nav-title">TABLE TENNIS MATCH — VIEWER</div>
  <div class="nav-right">
    <span class="nav-live-badge">👁 LIVE VIEW</span>
  </div>
</nav>

<div class="match-type-bar" id="matchTypeBar">
  <div class="mt-label active" id="mtSingles">Singles</div>
  <div class="mt-label"        id="mtDoubles">Doubles</div>
  <div class="mt-label"        id="mtMixed">Mixed Doubles</div>
</div>

<div class="main-area" id="mainArea">

  <!-- ── TEAM A ── -->
  <div class="team-panel" id="panelA">
    <div class="team-header team-header-a">
      <span id="teamAName">TEAM A</span>
    </div>
    <div class="player-display" id="tt-playersA"></div>

    <div class="section-label">Game Points</div>
    <div class="score-display">
      <div class="score-box" id="scoreA">0</div>
    </div>

    <div class="section-label">Games Won</div>
    <div class="score-display">
      <div class="score-box small" id="gamesA">0</div>
    </div>
  </div>

  <!-- ── CENTER ── -->
  <div class="center-panel">
    <div class="live-badge"><span class="live-dot"></span> LIVE</div>

    <div class="section-label">Serving</div>
    <div class="serving-indicator">
      <span class="shuttle-icon">🏓</span>
      <span class="serving-team" id="servingTeamLabel">TEAM A</span>
      <span class="shuttle-icon">🏓</span>
    </div>

    <div class="center-section">
      <div class="section-label">Best Of</div>
      <div class="score-display">
        <div class="score-box small" id="bestOfBox">3</div>
      </div>
    </div>

    <div class="center-section">
      <div class="section-label">Current Set</div>
      <div class="score-display">
        <div class="score-box small" id="currentSetBox">1</div>
      </div>
    </div>

    <div class="center-section">
      <div class="section-label">Timeouts Used</div>
      <div class="timeouts-row" id="timeoutRow">
        <div class="timeout-group">
          <div class="tg-label" id="timeoutLabelA">TEAM A</div>
          <div class="score-box small" style="width:40px;height:40px;font-size:20px" id="timeoutA">0</div>
        </div>
        <div class="timeout-group">
          <div class="tg-label" id="timeoutLabelB">TEAM B</div>
          <div class="score-box small" style="width:40px;height:40px;font-size:20px" id="timeoutB">0</div>
        </div>
      </div>
    </div>

    <div class="center-section">
      <div class="section-label">Committee / Official</div>
      <div class="committee-display" id="committeeDisplay">—</div>
    </div>
  </div>

  <!-- ── TEAM B ── -->
  <div class="team-panel" id="panelB">
    <div class="team-header team-header-b">
      <span id="teamBName">TEAM B</span>
    </div>
    <div class="player-display" id="tt-playersB"></div>

    <div class="section-label">Game Points</div>
    <div class="score-display">
      <div class="score-box" id="scoreB">0</div>
    </div>

    <div class="section-label">Games Won</div>
    <div class="score-display">
      <div class="score-box small" id="gamesB">0</div>
    </div>
  </div>

</div>

<!-- ── STATUS BAR ── -->
<div class="status-bar">
  <div class="status-item">
    <span class="si-label">Match Type</span>
    <span class="si-value" id="statusMatchType">Singles</span>
  </div>
  <div class="status-item">
    <span class="si-label">Best Of</span>
    <span class="si-value" id="statusBestOf">3</span>
  </div>
  <div class="status-item">
    <span class="si-label">Current Set</span>
    <span class="si-value" id="statusCurrentSet">1</span>
  </div>
  <div class="status-item">
    <span class="si-label">Score</span>
    <span class="si-value" id="statusScore">0 — 0</span>
  </div>
  <div class="status-item">
    <span class="si-label">Sets Won</span>
    <span class="si-value" id="statusGames">0 — 0</span>
  </div>
  <div class="status-item">
    <span class="si-label">Prev Set</span>
    <span class="si-value" id="statusPrevSet">—</span>
  </div>
  <div class="status-item">
    <span class="si-label">Prev Winner</span>
    <span class="si-value" id="statusPrevWinner">—</span>
  </div>
  <div class="status-item">
    <span class="si-label">Serving</span>
    <span class="si-value" id="statusServing">TEAM A</span>
  </div>
</div>

<!-- ✅ SSOT WINNER MODAL — Table Tennis Only -->
<div id="winnerModal" class="modal" style="display: none;">
  <div class="modal-content">
    <h2 id="winnerModalTitle"></h2>
    <p id="winnerModalMsg"></p>
    <button onclick="closeWinnerModal()">OK</button>
  </div>
</div>

<script src="tabletennis_viewer.js"></script>
</body>
</html>