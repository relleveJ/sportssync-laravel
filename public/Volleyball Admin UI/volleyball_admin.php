<?php
  require_once __DIR__ . '/../auth.php';
  $user    = requireRole('admin', 'superadmin');
  $matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : null;
?>
<?php
if (!defined('LARAVEL_WRAPPER')) {
    require_once __DIR__ . '/../auth.php';
    requireRole('admin');
}
// ── SportSync Guards ──────────────────────────────────────────
$__sg=null;foreach([__DIR__,__DIR__.'/..',__DIR__.'/../..'] as $__d){if(file_exists($__d.'/system_guard.php')){$__sg=$__d.'/system_guard.php';break;}}if($__sg) require_once $__sg;
$_pdo_guard = null;
try {
    $__dbf = file_exists(__DIR__.'/db.php') ? __DIR__.'/db.php' : (file_exists(__DIR__.'/../db.php') ? __DIR__.'/../db.php' : null);
    if ($__dbf) { require_once $__dbf; $_pdo_guard = $pdo ?? null; }
} catch (Throwable $e) {}
if ($_pdo_guard) {
    ss_check_maintenance($_pdo_guard, true);
    ss_check_sport($_pdo_guard, 'Volleyball', true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Volleyball — SportSync</title>
<link rel="stylesheet" href="volleyball_admin.css">
<script>
window.__userId   = <?= (int)$user['id'] ?>;
window.__username = <?= json_encode($user['username']) ?>;
window.__role     = <?= json_encode($user['role']) ?>;
window.__matchId  = <?= json_encode($matchId) ?>;
window.__wsToken  = '';
</script>
<script>
// Ensure legacy-compatible SS_* cookies exist for raw PHP endpoints when page
// is opened directly (middleware may not have run). This is a safe client-side
// fallback to help state POSTs reach legacy endpoints that read $_COOKIE.
(function(){
  try {
    if (typeof window.__userId !== 'undefined' && window.__userId !== null) {
      var exp = new Date(Date.now() + 8*3600*1000).toUTCString();
      var secure = location.protocol === 'https:' ? '; Secure' : '';
      document.cookie = 'SS_USER_ID=' + encodeURIComponent(String(window.__userId)) + ';path=/;expires=' + exp + ';SameSite=Lax' + secure;
      if (typeof window.__role !== 'undefined') {
        document.cookie = 'SS_ROLE=' + encodeURIComponent(String(window.__role)) + ';path=/;expires=' + exp + ';SameSite=Lax' + secure;
      }
    }
  } catch (e) { try { console.warn('SS cookie fallback failed', e); } catch(_) {} }
})();
</script>
</head>
<body data-sport="volleyball">
<?php if (!empty($_pdo_guard)) ss_render_banners(); ?>

<!-- NAV -->
<nav>
  <div class="nav-score-left">
    <div class="nav-left-actions">
      <a href="/" class="back-btn">&#8592; Back to Dashboard</a>
      <button class="volleyball-btn-reset" onclick="resetMatch()" title="Reset match and clear all data">Reset</button>
      <button class="btn-new" onclick="newMatch()" title="Create a new match and broadcast to all admins">➕ New Match</button>
      <button class="btn-matches" onclick="window.open('volleyball_matches_admin.php','_blank')" title="Open match history">📚 Matches</button>
    </div>
  </div>
  <div class="nav-center">
    <div class="nav-score-pill team-a">
      <span class="nav-score-team" id="labelA">TEAM A</span>
      <div class="score-controls">
        <span class="nav-score-num" id="scoreA">0</span>
        <div class="score-buttons">
          <button class="score-btn plus" onclick="adjustTeamScore('teamA', 1)">+</button>
          <button class="score-btn minus" onclick="adjustTeamScore('teamA', -1)">−</button>
        </div>
      </div>
      <span class="nav-live-badge">&#9679; LIVE</span>
    </div>
    <span class="nav-vs">VS</span>
    <div class="nav-title-stack">
      <span class="nav-title">Volleyball</span>
      <span class="nav-subtitle">&#127944; Scoresheet</span>
    </div>
    <span class="nav-vs">VS</span>
    <div class="nav-score-pill team-b">
      <span class="nav-score-team" id="labelB">TEAM B</span>
      <div class="score-controls">
        <span class="nav-score-num" id="scoreB">0</span>
        <div class="score-buttons">
          <button class="score-btn plus" onclick="adjustTeamScore('teamB', 1)">+</button>
          <button class="score-btn minus" onclick="adjustTeamScore('teamB', -1)">−</button>
        </div>
      </div>
      <span class="nav-live-badge">&#9679; LIVE</span>
    </div>
  </div>
  <div class="nav-score-right">
    <button class="btn-view-toggle two-sided" id="viewToggleBtn" onclick="toggleViewMode()" title="Switch view">&#8644; Two-Sided</button>
    <button class="btn-save" onclick="saveFile()">&#128190; Save</button>
  </div>
</nav>

<!-- COMMITTEE BAR -->
<div class="vbCommitteeBar">
  <label class="vbCommitteeLabel" for="vbCommitteeInput">Committee / Official:</label>
  <input type="text" id="vbCommitteeInput" class="vbCommitteeInput" placeholder="Enter committee or official name&#8230;" />  <button class="btn-save" onclick="lockPlayers()" title="Lock current roster and lineup without leaving this page">🔒 LOCK IN PLAYERS</button></div>

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
          <span class="tsb-label">Timeout</span>
          <div class="tsb-controls">
            <button class="tsb-btn minus" onclick="adjustTimeout('A',-1)">&#8722;</button>
            <div class="tsb-box"><span class="tsb-num" id="tsbA_timeout">0</span></div>
            <button class="tsb-btn plus"  onclick="adjustTimeout('A', 1)">+</button>
          </div>
        </div>
        <div class="tsb-sep"></div>
        <div class="tsb-item">
          <span class="tsb-label">Set</span>
          <div class="tsb-controls">
            <button class="tsb-btn minus" onclick="adjustSet(-1)">&#8722;</button>
            <div class="tsb-box"><span class="tsb-num" id="tsbA_set">1</span></div>
            <button class="tsb-btn plus"  onclick="adjustSet( 1)">+</button>
          </div>
        </div>
      </div>
      <div class="col-body left-body">
        <table class="roster-table">
          <thead><tr>
            <th class="th-select-all">
              <div class="th-select-all-inner">
                <input type="checkbox" class="select-all-cb" id="selectAllA" onchange="toggleSelectAll('A', this)" title="Select / deselect all" />
                <button class="btn-del-selected" onclick="deleteSelected('A')" title="Delete selected">&#128465; Del</button>
              </div>
            </th>
            <th style="width:36px">No.</th>
            <th style="min-width:96px;text-align:left;padding-left:8px">Player Name</th>
            <th class="pts-head" style="min-width:58px">PTS</th>
            <th>SPIKE</th><th>ACE</th><th>EX SET</th><th>EX DIG</th><th>BLK</th>
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
          <span class="tsb-label">Timeout</span>
          <div class="tsb-controls">
            <button class="tsb-btn minus" onclick="adjustTimeout('B',-1)">&#8722;</button>
            <div class="tsb-box"><span class="tsb-num" id="tsbB_timeout">0</span></div>
            <button class="tsb-btn plus"  onclick="adjustTimeout('B', 1)">+</button>
          </div>
        </div>
        <div class="tsb-sep"></div>
        <div class="tsb-item">
          <span class="tsb-label">Set</span>
          <div class="tsb-controls">
            <button class="tsb-btn minus" onclick="adjustSet(-1)">&#8722;</button>
            <div class="tsb-box"><span class="tsb-num" id="tsbB_set">1</span></div>
            <button class="tsb-btn plus"  onclick="adjustSet( 1)">+</button>
          </div>
        </div>
      </div>
      <div class="col-body center-body">
        <table class="roster-table">
          <thead><tr>
            <th class="th-select-all">
              <div class="th-select-all-inner">
                <input type="checkbox" class="select-all-cb" id="selectAllB" onchange="toggleSelectAll('B', this)" title="Select / deselect all" />
                <button class="btn-del-selected" onclick="deleteSelected('B')" title="Delete selected">&#128465; Del</button>
              </div>
            </th>
            <th style="width:36px">No.</th>
            <th style="min-width:96px;text-align:left;padding-left:8px">Player Name</th>
            <th class="pts-head" style="min-width:58px">PTS</th>
            <th>SPIKE</th><th>ACE</th><th>EX SET</th><th>EX DIG</th><th>BLK</th>
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

    <!-- Team A Lineup -->
    <div class="lineup-section" id="lineupSectionA">
      <div class="lineup-team-label" id="lineupLabelA">TEAM A — ACTIVE LINEUP</div>
      <div class="lineup-circle-wrap">
        <svg class="lineup-svg" id="lineupSvgA" viewBox="0 0 140 140" xmlns="http://www.w3.org/2000/svg"></svg>
      </div>
      <div class="lineup-actions">
        <button class="btn-rotate" id="rotateA" onclick="rotateTeamClockwise('A')">Rotate (Clockwise)</button>
      </div>
      <div class="lineup-slots" id="lineupSlotsA"></div>
    </div>

    <div class="rp-divider"></div>

    <!-- Team B Lineup -->
    <div class="lineup-section" id="lineupSectionB">
      <div class="lineup-team-label" id="lineupLabelB">TEAM B — ACTIVE LINEUP</div>
      <div class="lineup-circle-wrap">
        <svg class="lineup-svg" id="lineupSvgB" viewBox="0 0 140 140" xmlns="http://www.w3.org/2000/svg"></svg>
      </div>
      <div class="lineup-actions">
        <button class="btn-rotate" id="rotateB" onclick="rotateTeamClockwise('B')">Rotate (Clockwise)</button>
      </div>
      <div class="lineup-slots" id="lineupSlotsB"></div>
    </div>

    <div class="rp-divider"></div>

    <!-- Set + Timeout counters -->
    <div class="vb-counters-section">
      <div class="vb-counter-block">
        <div class="vb-counter-label">Current Set</div>
        <div class="vb-counter-row">
          <button class="rbtn minus" onclick="adjustSet(-1)">&#8722;</button>
          <div class="vb-counter-box"><span class="vb-counter-num" id="setVal">1</span></div>
          <button class="rbtn plus"  onclick="adjustSet( 1)">+</button>
        </div>
      </div>
      <div class="vb-counter-block">
        <div class="vb-counter-label">Timeouts Used</div>
        <div class="vb-timeout-teams">
          <div class="vb-timeout-team-block">
            <div class="vb-timeout-team-name" id="toLabelA">TEAM A</div>
            <div class="vb-counter-row">
              <button class="rbtn minus" onclick="adjustTimeout('A',-1)">&#8722;</button>
              <div class="vb-timeout-box"><span class="vb-timeout-num" id="timeoutA">0</span></div>
              <button class="rbtn plus"  onclick="adjustTimeout('A', 1)">+</button>
            </div>
          </div>
          <div class="vb-timeout-team-block">
            <div class="vb-timeout-team-name" id="toLabelB">TEAM B</div>
            <div class="vb-counter-row">
              <button class="rbtn minus" onclick="adjustTimeout('B',-1)">&#8722;</button>
              <div class="vb-timeout-box"><span class="vb-timeout-num" id="timeoutB">0</span></div>
              <button class="rbtn plus"  onclick="adjustTimeout('B', 1)">+</button>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /right-panel -->

</div><!-- /main-grid -->

<script src="volleyball_app.js"></script>
</body>
</html>