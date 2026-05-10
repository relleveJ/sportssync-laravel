<?php
// ── SportSync Guards ──────────────────────────────────────────
$__base = file_exists(__DIR__.'/db.php') ? __DIR__.'/db.php' : (file_exists(__DIR__.'/../db.php') ? __DIR__.'/../db.php' : null);
if ($__base) {
    require_once $__base;
    $__sg=null;foreach([__DIR__,__DIR__.'/..',__DIR__.'/../..'] as $__d){if(file_exists($__d.'/system_guard.php')){$__sg=$__d.'/system_guard.php';break;}}if($__sg) require_once $__sg;
    if (!empty($pdo)) {
        ss_check_maintenance($pdo);        // viewer: full block
        ss_check_sport($pdo, 'Badminton');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Badminton Live Score — Viewer</title>
<link rel="stylesheet" href="badminton_viewer.css">
<style>
/* ── Status Bar ─────────────────────────────────────────────── */
.status-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 10px;
  padding: 10px 14px;
  background: #1a1a2e;
  border-top: 2px solid #FFE600;
  margin-top: 10px;
}

.status-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,230,0,0.18);
  border-radius: 8px;
  padding: 6px 10px;
  min-width: 80px;
  flex: 1 1 80px;
  max-width: 160px;
}

.si-label {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #aaa;
  margin-bottom: 3px;
  white-space: nowrap;
}

.si-value {
  font-size: 13px;
  font-weight: 700;
  color: #FFE600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
  text-align: center;
}

/* Mobile: stack 2-per-row on narrow screens */
@media (max-width: 480px) {
  .status-bar {
    gap: 5px 8px;
    padding: 8px 10px;
  }
  .status-item {
    flex: 1 1 calc(50% - 8px);
    max-width: calc(50% - 8px);
    padding: 5px 8px;
  }
  .si-label {
    font-size: 8px;
  }
  .si-value {
    font-size: 12px;
  }
}

@media (max-width: 360px) {
  .status-item {
    flex: 1 1 calc(50% - 6px);
    max-width: calc(50% - 6px);
  }
}
</style>
</head>
<body>

<nav class="top-nav">
  <a href="javascript:history.back()" class="back-btn" title="Go Back">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" width="18" height="18">
      <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
    </svg>
    <span class="back-btn-text">BACK</span>
  </a>
  <div class="nav-title">BADMINTON MATCH — VIEWER</div>
  <div class="nav-right">
    <span class="nav-live-badge">👁 LIVE VIEW</span>
  </div>
</nav>

<div class="match-type-bar" id="matchTypeBar">
  <div class="mt-label" id="mtSingles">Singles</div>
  <div class="mt-label" id="mtDoubles">Doubles</div>
  <div class="mt-label" id="mtMixed">Mixed Doubles</div>
</div>

<div class="main-area" id="mainArea">

  <!-- TEAM A -->
  <div class="team-panel" id="panelA">
    <div class="team-header team-header-a">
        <span id="teamAName">TEAM A</span>
        <button id="markWinnerA" class="winner-btn" title="Mark as winner" style="margin-left:8px;padding:4px 8px;font-size:14px;border-radius:6px;background:#ffe082;border:none;cursor:pointer">🏆</button>
    </div>
    <div class="player-display" id="bd-playersA"></div>

    <div class="section-label">Game Points</div>
    <div class="score-display">
      <div class="score-box" id="scoreA">0</div>
    </div>

    <div class="section-label">Games Won</div>
    <div class="score-display">
      <div class="score-box small" id="gamesA">0</div>
    </div>
  </div>

  <!-- CENTER -->
  <div class="center-panel">
    <div class="live-badge"><span class="live-dot"></span> LIVE</div>

    <div class="section-label">Serving</div>
    <div class="serving-indicator">
      <span class="shuttle-icon">🏸</span>
      <span class="serving-team" id="servingTeamLabel">TEAM A</span>
      <span class="shuttle-icon">🏸</span>
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
      <div class="timeouts-row">
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

  <!-- TEAM B -->
  <div class="team-panel" id="panelB">
    <div class="team-header team-header-b">
        <span id="teamBName">TEAM B</span>
        <button id="markWinnerB" class="winner-btn" title="Mark as winner" style="margin-left:8px;padding:4px 8px;font-size:14px;border-radius:6px;background:#e1f5fe;border:none;cursor:pointer">🏆</button>
    </div>
    <div class="player-display" id="bd-playersB"></div>

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
    <span class="si-label">Previous Set</span>
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
  <div class="status-item">
    <span class="si-label">Match ID</span>
    <span class="si-value" id="statusMatchId">—</span>
  </div>
  <div class="status-item">
    <span class="si-label">Last Updated</span>
    <span class="si-value" id="last-updated">—</span>
  </div>
</div>

<!-- ══ WINNER MODAL ══ -->
<div class="modal-overlay" id="winnerModal" style="display:none">
  <div class="modal-box">
    <div class="modal-title" id="winnerModalTitle">🏆 SET WINNER</div>
    <div id="winnerModalMsg"></div>
    <button class="bb-btn btn-newset" style="margin-top:20px;flex:none;padding:0 32px" onclick="closeWinnerModal()">OK</button>
  </div>
</div>

<script src="badminton_viewer.js"></script>
</body>
</html>