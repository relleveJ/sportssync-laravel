<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Badminton Live Score</title>
<link rel="stylesheet" href="badminton_admin.css">
</head>
<body>

<nav class="top-nav">
  <div class="logo">🏸 <span style="color:#111">SPORTSSYNC</span></div>
  <div class="nav-title">BADMINTON MATCH</div>
  <div style="display:flex;align-items:center;gap:8px"> 
    <button class="save-btn" onclick="saveAndReport()">📊 SAVE &amp; REPORT</button>
    <button class="save-btn" style="margin-left:6px;background:#1e6c35;color:#fff;border:0;padding:8px 12px;border-radius:6px;cursor:pointer" onclick="window.open('badminton_matches_admin.php','_blank')">📚 Matches</button>
    <a href="../landingpage.php" class="back-btn">← Back to sports</a>
</nav>

<div class="match-type-bar">
  <button class="mt-btn active" data-type="singles" onclick="setMatchType('singles')">Singles</button>
  <button class="mt-btn" data-type="doubles" onclick="setMatchType('doubles')">Doubles</button>
  <button class="mt-btn" data-type="mixed" onclick="setMatchType('mixed')">Mixed Doubles</button>
</div>

<div class="main-area" id="mainArea">

  <div class="team-panel" id="panelA">
    <div class="team-header team-header-a" id="headerA" onclick="editTeamName('A')">
      <span id="teamAName">TEAM A</span>
    </div>
    <div class="player-inputs" id="playersA"></div>
    
    <div class="section-label">Game Points</div>
    <div class="score-row">
      <button class="btn-minus" onclick="changeScore('A', -1)">−</button>
      <div class="score-box" id="scoreA" contenteditable="true" onblur="syncScore('A')">0</div>
      <button class="btn-plus" onclick="changeScore('A', 1)">+</button>
    </div>

    <div class="section-label">Games Won</div>
    <div class="score-row">
      <button class="btn-minus" onclick="changeGames('A', -1)">−</button>
      <div class="score-box small" id="gamesA" contenteditable="true" onblur="syncGames('A')">0</div>
      <button class="btn-plus" onclick="changeGames('A', 1)">+</button>
    </div>
  </div>

  <div class="center-panel">
    <div class="section-label">Serving</div>
    <div class="serving-indicator" onclick="toggleServing()">
      <span class="shuttle-icon">🏸</span>
      <span class="serving-team" id="servingTeamLabel">TEAM A</span>
      <span class="shuttle-icon">🏸</span>
    </div>

    <div class="center-section">
      <div class="section-label">Best Of</div>
      <div class="score-row">
        <button class="btn-minus" onclick="changeBestOf(-1)">−</button>
        <div class="score-box small" id="bestOfBox">3</div>
        <button class="btn-plus" onclick="changeBestOf(1)">+</button>
      </div>
    </div>

    <div class="center-section">
      <div class="section-label">Current Set</div>
      <div class="score-row">
        <button class="btn-minus" onclick="changeSet(-1)">−</button>
        <div class="score-box small" id="currentSetBox">1</div>
        <button class="btn-plus" onclick="changeSet(1)">+</button>
      </div>
    </div>

    <div class="center-section">
        <div class="section-label">Timeouts Used</div>
        <div class="timeouts-row" id="timeoutRow">
          <div class="timeout-group" id="toGroupA">
            <div class="tg-label" id="timeoutLabelA">TEAM A</div>
            <div class="score-row">
                <button class="btn-minus" style="width:30px; height:30px" onclick="changeTimeout('A', -1)">-</button>
                <div class="score-box small" style="width:40px; height:40px; font-size:20px" id="timeoutA" contenteditable="true" onblur="syncTimeout('A')">0</div>
                <button class="btn-plus" style="width:30px; height:30px" onclick="changeTimeout('A', 1)">+</button>
            </div>
          </div>
          <div class="timeout-group" id="toGroupB">
            <div class="tg-label" id="timeoutLabelB">TEAM B</div>
            <div class="score-row">
                <button class="btn-minus" style="width:30px; height:30px" onclick="changeTimeout('B', -1)">-</button>
                <div class="score-box small" style="width:40px; height:40px; font-size:20px" id="timeoutB" contenteditable="true" onblur="syncTimeout('B')">0</div>
                <button class="btn-plus" style="width:30px; height:30px" onclick="changeTimeout('B', 1)">+</button>
            </div>
          </div>
        </div>
    </div>
    <div class="center-section">
      <div class="section-label">Committee / Official</div>
      <div class="committee-input-wrapper">
        <input id="committeeInput" placeholder="Committee / Official">
      </div>
    </div>
  </div>

  <div class="team-panel" id="panelB">
    <div class="team-header team-header-b" id="headerB" onclick="editTeamName('B')">
      <span id="teamBName">TEAM B</span>
    </div>
    <div class="player-inputs" id="playersB"></div>
    
    <div class="section-label">Game Points</div>
    <div class="score-row">
      <button class="btn-minus" onclick="changeScore('B', -1)">−</button>
      <div class="score-box" id="scoreB" contenteditable="true" onblur="syncScore('B')">0</div>
      <button class="btn-plus" onclick="changeScore('B', 1)">+</button>
    </div>

    <div class="section-label">Games Won</div>
    <div class="score-row">
      <button class="btn-minus" onclick="changeGames('B', -1)">−</button>
      <div class="score-box small" id="gamesB" contenteditable="true" onblur="syncGames('B')">0</div>
      <button class="btn-plus" onclick="changeGames('B', 1)">+</button>
    </div>
  </div>

</div>

<div class="bottom-bar">
  <!-- Declare Winner moved into Save & Report button; UI control removed -->
  <button class="bb-btn btn-reset" onclick="resetMatch()">🧹 RESET MATCH</button>
  <button class="bb-btn btn-swap" onclick="swapTeams()">⇄ SWAP SIDES</button>
  <button class="bb-btn btn-newset" onclick="startNewSet()">▶ START NEW SET</button>
  <div style="margin-left:16px;display:inline-flex;gap:8px;align-items:center">
    <button id="adminMarkWinnerA" class="bb-btn" onclick="toggleManualWinner('A')" title="Mark TEAM A as winner for current set">🏆 A</button>
    <button id="adminMarkWinnerB" class="bb-btn" onclick="toggleManualWinner('B')" title="Mark TEAM B as winner for current set">🏆 B</button>
  </div>
</div>

<div class="modal-overlay" id="modal">
  <div class="modal-box">
    <div class="modal-title" id="modalTitle">Result</div>
    <div id="modalMsg"></div>
    <button class="bb-btn btn-newset" style="margin-top:20px" onclick="closeModal()">OK</button>
  </div>
</div>
<script src="badminton_admin.js"></script>