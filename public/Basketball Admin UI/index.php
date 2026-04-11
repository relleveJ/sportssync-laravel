<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Basketball Iskorsit</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<!-- NAV -->
<nav>
  <div class="nav-score-left">
    <div class="nav-left-actions">
      <button class="btn-reset" onclick="resetMatch()" title="Reset match and clear all data">Reset</button>
      <button class="btn-delete-server" onclick="deleteSavedMatch()" title="Delete saved match from server database">Delete Saved Match</button>
    </div>
  </div>
  <div class="nav-center">
    <div class="nav-score-pill team-a">
      <span class="nav-score-team" id="labelA">TEAM A</span>
      <span class="nav-score-num"  id="scoreA">0</span>
      <span class="nav-live-badge">&#9679; LIVE</span>
    </div>
    <span class="nav-vs">VS</span>
    <div class="nav-title-stack">
      <span class="nav-title">Basketball Iskorsit</span>
      <span class="nav-subtitle">&#127944; Scoresheet</span>
    </div>
    <span class="nav-vs">VS</span>
    <div class="nav-score-pill team-b">
      <span class="nav-score-team" id="labelB">TEAM B</span>
      <span class="nav-score-num"  id="scoreB">0</span>
      <span class="nav-live-badge">&#9679; LIVE</span>
    </div>
  </div>
  <div class="nav-score-right">
    <button class="btn-view-toggle two-sided" id="viewToggleBtn" onclick="toggleViewMode()" title="Switch between one-sided and two-sided view">&#8644; Two-Sided</button>
    <button class="btn-save" onclick="saveFile()">&#128190; Save</button>
    <a href="../landingpage.php" class="back-btn">← Back to sports</a>
  </div>
</nav>

<!-- COMMITTEE / OFFICIAL BAR -->
<div class="committee-bar">
  <label class="committee-label" for="committeeInput">Committee / Official:</label>
  <input type="text" id="committeeInput" class="committee-input" placeholder="Enter committee or official name&#8230;" />
</div>

<!-- MAIN GRID -->
<div class="main-grid" id="mainGrid">

  <div id="rosterWrapper">

    <div class="team-tab-bar">
      <button class="team-tab active-a" id="tabA" onclick="switchTab('A')">&#128994; Team A</button>
      <button class="team-tab"          id="tabB" onclick="switchTab('B')">&#128309; Team B</button>
    </div>

    <!-- TEAM A PANEL -->
    <div class="team-panel visible" id="panelA">
      <div class="col-header green">
        <input class="team-name-input" id="teamAName" placeholder="TEAM A NAME" value="TEAM A" oninput="onTeamName('A')" />
      </div>
      <div class="team-stats-bar" id="tsbA">
        <div class="tsb-item">
          <span class="tsb-label">Team Foul</span>
          <div class="tsb-controls">
            <button class="tsb-btn minus" onclick="adjustTsb('A','foul',-1)">&#8722;</button>
            <div class="tsb-box"><span class="tsb-num" id="tsbA_foul">0</span></div>
            <button class="tsb-btn plus"  onclick="adjustTsb('A','foul', 1)">+</button>
          </div>
        </div>
        <div class="tsb-sep"></div>
        <div class="tsb-item">
          <span class="tsb-label">Timeout</span>
          <div class="tsb-controls">
            <button class="tsb-btn minus" onclick="adjustTsb('A','timeout',-1)">&#8722;</button>
            <div class="tsb-box"><span class="tsb-num" id="tsbA_timeout">0</span></div>
            <button class="tsb-btn plus"  onclick="adjustTsb('A','timeout', 1)">+</button>
          </div>
        </div>
        
      </div>
      <div class="col-body left-body">
        <table class="roster-table">
          <thead><tr>
            <th class="th-select-all">
              <div class="th-select-all-inner">
                <input type="checkbox" class="select-all-cb" id="selectAllA" onchange="toggleSelectAll('A', this)" title="Select / deselect all" />
                <button class="btn-del-selected" onclick="deleteSelected('A')" title="Delete selected players">&#128465; Del</button>
              </div>
            </th>
            <th style="width:36px">No.</th>
            <th style="min-width:96px;text-align:left;padding-left:8px">Player Name</th>
            <th class="pts-head" style="min-width:68px">PTS</th>
            <th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th>
            <th class="tech-head">TF</th>
            <th>DEL</th>
          </tr></thead>
          <tbody id="tbodyA"></tbody>
        </table>
        <div class="add-player-wrap">
          <button class="btn-add-player" onclick="addPlayer('A')">ADD PLAYER +</button>
        </div>
      </div>
    </div>

    <!-- TEAM B PANEL -->
    <div class="team-panel" id="panelB">
      <div class="col-header gray">
        <input class="team-name-input" id="teamBName" placeholder="OPPONENT NAME" value="TEAM B" style="color:#ccc" oninput="onTeamName('B')" />
      </div>
      <div class="team-stats-bar" id="tsbB">
        <div class="tsb-item">
          <span class="tsb-label">Team Foul</span>
          <div class="tsb-controls">
            <button class="tsb-btn minus" onclick="adjustTsb('B','foul',-1)">&#8722;</button>
            <div class="tsb-box"><span class="tsb-num" id="tsbB_foul">0</span></div>
            <button class="tsb-btn plus"  onclick="adjustTsb('B','foul', 1)">+</button>
          </div>
        </div>
        <div class="tsb-sep"></div>
        <div class="tsb-item">
          <span class="tsb-label">Timeout</span>
          <div class="tsb-controls">
            <button class="tsb-btn minus" onclick="adjustTsb('B','timeout',-1)">&#8722;</button>
            <div class="tsb-box"><span class="tsb-num" id="tsbB_timeout">0</span></div>
            <button class="tsb-btn plus"  onclick="adjustTsb('B','timeout', 1)">+</button>
          </div>
        </div>
        
      </div>
      <div class="col-body center-body">
        <table class="roster-table">
          <thead><tr>
            <th class="th-select-all">
              <div class="th-select-all-inner">
                <input type="checkbox" class="select-all-cb" id="selectAllB" onchange="toggleSelectAll('B', this)" title="Select / deselect all" />
                <button class="btn-del-selected" onclick="deleteSelected('B')" title="Delete selected players">&#128465; Del</button>
              </div>
            </th>
            <th style="width:36px">No.</th>
            <th style="min-width:96px;text-align:left;padding-left:8px">Player Name</th>
            <th class="pts-head" style="min-width:68px">PTS</th>
            <th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th>
            <th class="tech-head">TF</th>
            <th>DEL</th>
          </tr></thead>
          <tbody id="tbodyB"></tbody>
        </table>
        <div class="add-player-wrap">
          <button class="btn-add-player" onclick="addPlayer('B')">ADD PLAYER +</button>
        </div>
      </div>
    </div>

  </div><!-- /rosterWrapper -->

  <!-- RIGHT PANEL -->
  <div class="right-panel" id="rightPanel">
    <div class="game-timer-block" id="gtBlock">
      <div class="gt-header"><span class="gt-title">Game Timer</span></div>
      <div class="gt-config">
        <span class="gt-config-label">Set:</span>
        <input class="gt-time-input" id="gtInputMin" type="number" min="0" max="99" value="10" title="Minutes" />
        <span class="gt-colon">:</span>
        <input class="gt-time-input" id="gtInputSec" type="number" min="0" max="59" value="00" title="Seconds" />
        <button class="gt-set-btn" onclick="gtSetDuration()">Set</button>
      </div>
      <div class="gt-display"><span class="gt-time" id="gtTime">10:00</span></div>
      <div class="sc-controls">
        <button class="sc-btn play"  id="gtPlayBtn"  onclick="gtPlay()">&#9654; Play</button>
        <button class="sc-btn pause" id="gtPauseBtn" onclick="gtPause()" disabled>&#9208; Pause</button>
        <button class="sc-btn reset" id="gtResetBtn" onclick="gtReset()">&#8635; Reset</button>
      </div>
    </div>
    <div class="rp-divider" style="margin-top:2px; margin-bottom:14px;"></div>
    <div class="shot-clock-block" id="scBlock">
      <div class="sc-header">
        <span class="sc-title">Shot Clock</span>
        <div class="sc-preset-btns">
          <button class="sc-preset active" id="preset24" onclick="scPreset(24)">24s</button>
          <button class="sc-preset"        id="preset14" onclick="scPreset(14)">14s</button>
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
      <div class="sc-controls">
        <button class="sc-btn play"  id="scPlayBtn"  onclick="scPlay()">&#9654; Play</button>
        <button class="sc-btn pause" id="scPauseBtn" onclick="scPause()" disabled>&#9208; Pause</button>
        <button class="sc-btn reset" id="scResetBtn" onclick="scReset()">&#8635; Reset</button>
      </div>
    </div>
    <div class="rp-divider" style="margin-top:2px;"></div>
    <div class="rp-divider" id="sharedCounterDivider"></div>
    <div class="counters-section" id="perTeamCounters" style="display:none;">
      <div class="per-team-block">
        <div class="per-team-title">Team A</div>
        <div class="counter-label">Team Foul</div>
        <div class="counter-row">
          <button class="rbtn minus" onclick="adjustTsb('A','foul',-1)">&#8722;</button>
          <div class="counter-box"><span class="counter-num" id="right_tsbA_foul">0</span></div>
          <button class="rbtn plus"  onclick="adjustTsb('A','foul', 1)">+</button>
        </div>
        <div class="counter-label">Timeout</div>
        <div class="counter-row">
          <button class="rbtn minus" onclick="adjustTsb('A','timeout',-1)">&#8722;</button>
          <div class="counter-box"><span class="counter-num" id="right_tsbA_timeout">0</span></div>
          <button class="rbtn plus"  onclick="adjustTsb('A','timeout', 1)">+</button>
        </div>
      </div>
      <div class="per-team-block">
        <div class="per-team-title">Team B</div>
        <div class="counter-label">Team Foul</div>
        <div class="counter-row">
          <button class="rbtn minus" onclick="adjustTsb('B','foul',-1)">&#8722;</button>
          <div class="counter-box"><span class="counter-num" id="right_tsbB_foul">0</span></div>
          <button class="rbtn plus"  onclick="adjustTsb('B','foul', 1)">+</button>
        </div>
        <div class="counter-label">Timeout</div>
        <div class="counter-row">
          <button class="rbtn minus" onclick="adjustTsb('B','timeout',-1)">&#8722;</button>
          <div class="counter-box"><span class="counter-num" id="right_tsbB_timeout">0</span></div>
          <button class="rbtn plus"  onclick="adjustTsb('B','timeout', 1)">+</button>
        </div>
      </div>
      <div class="per-team-quarter">
        <div class="counter-label">Quarter</div>
        <div class="counter-row">
          <button class="rbtn minus" onclick="adjustShared('quarter',-1)">&#8722;</button>
          <div class="counter-box"><span class="counter-num" id="per_quarterVal">1</span></div>
          <button class="rbtn plus"  onclick="adjustShared('quarter', 1)">+</button>
        </div>
      </div>
    </div>

    <div class="counters-section" id="sharedCounters">
      <div>
        <div class="counter-label">Quarter</div>
        <div class="counter-row">
          <button class="rbtn minus" onclick="adjustShared('quarter',-1)">&#8722;</button>
          <div class="counter-box"><span class="counter-num" id="quarterVal">1</span></div>
          <button class="rbtn plus"  onclick="adjustShared('quarter', 1)">+</button>
        </div>
      </div>
    </div>
  </div><!-- /right-panel -->
</div><!-- /main-grid -->

<script src="app.js"></script>
</body>
</html>