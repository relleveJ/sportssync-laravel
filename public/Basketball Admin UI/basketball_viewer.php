<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Basketball Viewer — Live</title>
<link rel="stylesheet" href="basketball_viewer.css">
</head>
<body>

<!-- NAV -->
<nav>
  <div class="nav-score-left">
    <a href="javascript:history.back()" class="back-btn" title="Go Back">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" width="18" height="18">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
      </svg>
      <span class="back-btn-text">BACK</span>
    </a>
  </div>
  <div class="nav-center">
    <div class="nav-score-pill team-a">
      <span class="nav-score-team" id="labelA">TEAM A</span>
      <span class="nav-score-num"  id="scoreA">0</span>
      <span class="nav-live-badge">&#9679; LIVE</span>
    </div>
    <span class="nav-vs">VS</span>
    <div class="nav-title-stack">
      <span class="nav-title">Basketball Viewer</span>
      <span class="nav-subtitle">&#127944; Live Scoresheet</span>
    </div>
    <span class="nav-vs">VS</span>
    <div class="nav-score-pill team-b">
      <span class="nav-score-team" id="labelB">TEAM B</span>
      <span class="nav-score-num"  id="scoreB">0</span>
      <span class="nav-live-badge">&#9679; LIVE</span>
    </div>
  </div>
  <div class="nav-score-right">
    <!-- Compact score bar for very small screens (phones) -->
    <div id="navCompact" class="nav-compact" aria-hidden="true">
      <span class="nav-compact-pill"><span class="nav-compact-team" id="compactLabelA">A</span><span class="nav-compact-score" id="compactScoreA">0</span></span>
      <span class="nav-compact-vs">VS</span>
      <span class="nav-compact-pill"><span class="nav-compact-team" id="compactLabelB">B</span><span class="nav-compact-score" id="compactScoreB">0</span></span>
    </div>
    <span class="nav-viewer-badge">&#128065; VIEWER VIEW</span>
  </div>
</nav>

<!-- COMMITTEE BAR -->
<div class="bbCommitteeBar">
  <span class="bbCommitteeLabel">Committee / Official:</span>
  <span class="bbCommitteeValue" id="bbCommitteeValue">—</span>
</div>

<!-- MAIN GRID -->
<div class="main-grid" id="mainGrid">

  <!-- TEAM A PANEL -->
  <div class="team-panel visible" id="panelA">
    <div class="col-header green">
      <span class="team-name-display" id="teamANameDisplay">TEAM A</span>
    </div>

    <!-- Team stats bar (always visible in viewer) -->
    <div class="team-stats-bar" id="tsbA">
      <div class="tsb-item">
        <span class="tsb-label">Team Foul</span>
        <div class="tsb-box"><span class="tsb-num" id="bbTsbAFoul">5</span></div>
      </div>
      <div class="tsb-sep"></div>
      <div class="tsb-item">
        <span class="tsb-label">Timeout</span>
        <div class="tsb-box"><span class="tsb-num" id="bbTsbATimeout">3</span></div>
      </div>
      <div class="tsb-sep"></div>
      <div class="tsb-item">
        <span class="tsb-label">Quarter</span>
        <div class="tsb-box"><span class="tsb-num" id="tsbA_quarter">1</span></div>
      </div>
      
    </div>

    <div class="col-body left-body">
      <table class="roster-table">
        <thead><tr>
          <th style="width:36px">No.</th>
          <th style="min-width:110px;text-align:left;padding-left:8px">Player Name</th>
          <th class="pts-head" style="min-width:52px">PTS</th>
          <th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th>
          <th class="tech-head">TF</th>
        </tr></thead>
        <tbody id="tbodyA"></tbody>
      </table>
    </div>
  </div>

  <!-- TEAM B PANEL -->
  <div class="team-panel visible" id="panelB">
    <div class="col-header gray">
      <span class="team-name-display" id="teamBNameDisplay" style="color:#ccc">TEAM B</span>
    </div>

    <div class="team-stats-bar" id="tsbB">
      <div class="tsb-item">
        <span class="tsb-label">Team Foul</span>
        <div class="tsb-box"><span class="tsb-num" id="bbTsbBFoul">5</span></div>
      </div>
      <div class="tsb-sep"></div>
      <div class="tsb-item">
        <span class="tsb-label">Timeout</span>
        <div class="tsb-box"><span class="tsb-num" id="bbTsbBTimeout">3</span></div>
      </div>
      <div class="tsb-sep"></div>
      <div class="tsb-item">
        <span class="tsb-label">Quarter</span>
        <div class="tsb-box"><span class="tsb-num" id="tsbB_quarter">1</span></div>
      </div>
      
    </div>

    <div class="col-body center-body">
      <table class="roster-table">
        <thead><tr>
          <th style="width:36px">No.</th>
          <th style="min-width:110px;text-align:left;padding-left:8px">Player Name</th>
          <th class="pts-head" style="min-width:52px">PTS</th>
          <th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th>
          <th class="tech-head">TF</th>
        </tr></thead>
        <tbody id="tbodyB"></tbody>
      </table>
    </div>
  </div>

  <!-- RIGHT PANEL — timers only, no controls -->
  <div class="right-panel" id="rightPanel">

    <!-- Game Timer display -->
    <div class="game-timer-block" id="gtBlock">
      <div class="gt-header"><span class="gt-title">Game Timer</span></div>
      <div class="gt-display"><span class="gt-time" id="gtTime">10:00</span></div>
    </div>

    <div class="rp-divider" style="margin-top:2px; margin-bottom:14px;"></div>

    <!-- Shot Clock display -->
    <div class="shot-clock-block" id="scBlock">
      <div class="sc-header">
        <span class="sc-title">Shot Clock</span>
        <div class="sc-preset-btns">
          <span class="sc-preset-label" id="presetLabel">24s</span>
        </div>
      </div>
      <div class="sc-ring-wrap">
        <svg class="sc-ring-svg" viewBox="0 0 120 120">
          <circle class="sc-ring-bg" cx="60" cy="60" r="52"/>
          <circle class="sc-ring-fg" id="scRing" cx="60" cy="60" r="52" stroke-dasharray="326.7" stroke-dashoffset="0"/>
        </svg>
        <div class="sc-ring-center">
          <span class="sc-time"  id="scTime">24</span>
          <span class="sc-tenth" id="scTenth"></span>
        </div>
      </div>
    </div>

    

  </div><!-- /right-panel -->
</div><!-- /main-grid -->

<script src="basketball_viewer.js"></script>
</body>
</html>
