<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Badminton Live Score — Viewer</title>
<link rel="stylesheet" href="badminton_viewer.css">
</head>
<body>

<nav class="top-nav">
  <div class="logo">🏸 <span style="color:#111">SPORTSSYNC</span></div>
  <div class="nav-title">BADMINTON MATCH — VIEWER</div>
  <div class="nav-right">👁 LIVE VIEW <a href="../landingpage.php" style="color:#FFD700;margin-left:12px;text-decoration:none">← Back to sports</a></div>
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
    <div class="player-display" id="playersA"></div>

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
    <div class="player-display" id="playersB"></div>

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
</div>

<script src="badminton_viewer.js"></script>
</body>
</html>
