<?php
if (!defined('LARAVEL_WRAPPER')) {
    require_once __DIR__ . '/../auth.php';
    requireRole('admin');
}
// ── SportSync Guards ──────────────────────────────────────────
$__sg=null;foreach([__DIR__,__DIR__.'/..',__DIR__.'/../..'] as $__d){if(file_exists($__d.'/system_guard.php')){$__sg=$__d.'/system_guard.php';break;}}if($__sg) require_once $__sg;
$_pdo_guard = null;
try {
    $__base = file_exists(__DIR__.'/db.php') ? __DIR__.'/db.php' : (file_exists(__DIR__.'/../db.php') ? __DIR__.'/../db.php' : null);
    if ($__base) { require_once $__base; $_pdo_guard = $pdo ?? null; }
} catch (Throwable $e) {}
if ($_pdo_guard) {
    ss_check_maintenance($_pdo_guard, true);   // admin: show banner, don't block
    ss_check_sport($_pdo_guard, 'Badminton', true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#FFE600">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Badminton Live Score</title>
<link rel="stylesheet" href="badminton_admin.css">
</head>
<body data-sport="badminton">
<?php ss_render_banners(); ?>

<!-- ══ TOP NAV ══ -->
<nav class="top-nav">
  <a href="/" class="back-btn">← BACK</a>
  <div class="logo"> 🏸 <span> SPORTSSYNC</span></div>
  <div class="nav-title">BADMINTON</div>
  <div class="nav-actions">
    <button class="save-btn" onclick="saveAndReport()">📊 SAVE &amp; REPORT</button>
    <button class="save-btn nav-matches-btn" onclick="window.location.href='badminton_matches_admin.php'">
    📚 MATCHES
    </button>
    <!-- ✅ SSOT NEW MATCH BUTTON -->
    <button class="save-btn btn-new-match" id="btnNewMatch" onclick="openNewMatchModal()" title="Start a brand-new match and broadcast to all viewers">
      ➕ NEW MATCH
    </button>
  </div>
</nav>

<!-- ══ MATCH TYPE ══ -->
<div class="match-type-bar">
  <button class="mt-btn active" data-type="singles" onclick="setMatchType('singles')">Singles</button>
  <button class="mt-btn" data-type="doubles" onclick="setMatchType('doubles')">Doubles</button>
  <button class="mt-btn" data-type="mixed" onclick="setMatchType('mixed')">Mixed</button>
</div>

<!-- ══ MAIN AREA ══ -->
<div class="main-area" id="mainArea">

  <!-- TEAM A -->
  <div class="team-panel" id="panelA">
    <div class="team-header team-header-a" id="headerA" onclick="editTeamName('A')">
      <span id="teamAName">TEAM A</span>
    </div>
    <div class="player-inputs" id="bd-playersA"></div>

    <div class="section-label">Game Points</div>
    <div class="score-row">
      <button class="btn-minus" onclick="changeScore('A', -1)" aria-label="Minus">−</button>
      <div class="score-box" id="scoreA" contenteditable="true" onblur="syncScore('A')" inputmode="numeric">0</div>
      <button class="btn-plus" onclick="changeScore('A', 1)" aria-label="Plus">+</button>
    </div>

    <div class="section-label">Games Won</div>
    <div class="score-row">
      <button class="btn-minus" onclick="changeGames('A', -1)" aria-label="Minus">−</button>
      <div class="score-box small" id="gamesA" contenteditable="true" onblur="syncGames('A')" inputmode="numeric">0</div>
      <button class="btn-plus" onclick="changeGames('A', 1)" aria-label="Plus">+</button>
    </div>
  </div>

  <!-- CENTER PANEL -->
  <div class="center-panel">

    <div class="section-label">Serving</div>
    <div class="serving-indicator" onclick="toggleServing()" role="button" aria-label="Toggle serving team">
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
            <button class="btn-minus" style="width:44px;height:44px;font-size:20px;min-width:44px" onclick="changeTimeout('A', -1)">−</button>
            <div class="score-box small" style="width:50px;height:44px;font-size:22px" id="timeoutA" contenteditable="true" onblur="syncTimeout('A')" inputmode="numeric">0</div>
            <button class="btn-plus" style="width:44px;height:44px;font-size:20px;min-width:44px" onclick="changeTimeout('A', 1)">+</button>
          </div>
        </div>
        <div class="timeout-group" id="toGroupB">
          <div class="tg-label" id="timeoutLabelB">TEAM B</div>
          <div class="score-row">
            <button class="btn-minus" style="width:44px;height:44px;font-size:20px;min-width:44px" onclick="changeTimeout('B', -1)">−</button>
            <div class="score-box small" style="width:50px;height:44px;font-size:22px" id="timeoutB" contenteditable="true" onblur="syncTimeout('B')" inputmode="numeric">0</div>
            <button class="btn-plus" style="width:44px;height:44px;font-size:20px;min-width:44px" onclick="changeTimeout('B', 1)">+</button>
          </div>
        </div>
      </div>
    </div>

    <div class="center-section">
      <div class="section-label">Committee / Official</div>
      <div class="committee-input-wrapper">
        <input id="bdCommitteeInput" type="text" placeholder="Committee / Official" autocomplete="off">
      </div>
    </div>

  </div><!-- /center-panel -->

  <!-- TEAM B -->
  <div class="team-panel" id="panelB">
    <div class="team-header team-header-b" id="headerB" onclick="editTeamName('B')">
      <span id="teamBName">TEAM B</span>
    </div>
    <div class="player-inputs" id="bd-playersB"></div>

    <div class="section-label">Game Points</div>
    <div class="score-row">
      <button class="btn-minus" onclick="changeScore('B', -1)">−</button>
      <div class="score-box" id="scoreB" contenteditable="true" onblur="syncScore('B')" inputmode="numeric">0</div>
      <button class="btn-plus" onclick="changeScore('B', 1)">+</button>
    </div>

    <div class="section-label">Games Won</div>
    <div class="score-row">
      <button class="btn-minus" onclick="changeGames('B', -1)">−</button>
      <div class="score-box small" id="gamesB" contenteditable="true" onblur="syncGames('B')" inputmode="numeric">0</div>
      <button class="btn-plus" onclick="changeGames('B', 1)">+</button>
    </div>
  </div>

</div><!-- /main-area -->

<!-- ══ BOTTOM BAR ══ -->
<div class="bottom-bar">
  <button class="bb-btn badminton-btn-reset"  onclick="resetMatch()">🧹 RESET</button>
  <button class="bb-btn btn-swap"   onclick="swapTeams()">⇄ SWAP</button>
  <button class="bb-btn btn-newset" onclick="startNewSet()">▶ NEW SET</button>
  <div class="bb-winner-btns">
    <button id="adminMarkWinnerA" class="bb-btn" onclick="toggleManualWinner('A')" title="Mark TEAM A as winner">🏆 A</button>
    <button id="adminMarkWinnerB" class="bb-btn" onclick="toggleManualWinner('B')" title="Mark TEAM B as winner">🏆 B</button>
  </div>
</div>

<!-- ══ MODAL ══ -->
<div class="modal-overlay" id="modal">
  <div class="modal-box">
    <div class="modal-title" id="modalTitle">Result</div>
    <div id="modalMsg"></div>
    <button class="bb-btn btn-newset" style="margin-top:20px;flex:none;padding:0 32px" onclick="closeModal()">OK</button>
  </div>
</div>

<!-- ══ WINNER MODAL ══ -->
<div id="winnerModal" class="modal" style="display: none;">
  <div class="modal-content">
    <h2 id="winnerModalTitle"></h2>
    <p id="winnerModalMsg"></p>
    <button onclick="closeWinnerModal()">OK</button>
  </div>
</div>

<!-- ✅ SSOT NEW MATCH MODAL -->
<div class="modal-overlay" id="newMatchModal" style="display:none">
  <div class="modal-box" style="min-width:320px;max-width:480px;width:92vw">
    <div class="modal-title">➕ NEW MATCH</div>
    <p style="font-size:13px;color:#ccc;margin:0 0 16px">
      Starting a new match will save &amp; finalize the current one, then broadcast a blank slate to all connected viewers instantly.
    </p>

    <div style="display:flex;flex-direction:column;gap:10px;text-align:left">
      <label style="font-size:12px;font-weight:700;color:#FFE600">Match Type</label>
      <div style="display:flex;gap:6px">
        <button class="nm-type-btn active" data-type="singles"  onclick="nmSetType('singles')">Singles</button>
        <button class="nm-type-btn"        data-type="doubles"  onclick="nmSetType('doubles')">Doubles</button>
        <button class="nm-type-btn"        data-type="mixed"    onclick="nmSetType('mixed')">Mixed</button>
      </div>

      <label style="font-size:12px;font-weight:700;color:#FFE600;margin-top:4px">Best Of</label>
      <div style="display:flex;align-items:center;gap:10px">
        <button class="bb-btn" onclick="nmChangeBestOf(-1)" style="width:36px;height:36px;font-size:20px;flex:none">−</button>
        <span id="nmBestOf" style="font-size:22px;font-weight:900;color:#fff;min-width:28px;text-align:center">3</span>
        <button class="bb-btn" onclick="nmChangeBestOf(1)"  style="width:36px;height:36px;font-size:20px;flex:none">+</button>
      </div>

      <label style="font-size:12px;font-weight:700;color:#FFE600;margin-top:4px">Team A Name</label>
      <input id="nmTeamA" type="text" placeholder="TEAM A" autocomplete="off"
             style="padding:8px;border-radius:6px;border:1px solid #444;background:#222;color:#fff;font-size:14px">

      <div id="nmPlayersA" style="display:flex;flex-direction:column;gap:6px"></div>

      <label style="font-size:12px;font-weight:700;color:#FFE600;margin-top:4px">Team B Name</label>
      <input id="nmTeamB" type="text" placeholder="TEAM B" autocomplete="off"
             style="padding:8px;border-radius:6px;border:1px solid #444;background:#222;color:#fff;font-size:14px">

      <div id="nmPlayersB" style="display:flex;flex-direction:column;gap:6px"></div>

      <label style="font-size:12px;font-weight:700;color:#FFE600;margin-top:4px">Committee / Official</label>
      <input id="nmCommittee" type="text" placeholder="Optional" autocomplete="off"
             style="padding:8px;border-radius:6px;border:1px solid #444;background:#222;color:#fff;font-size:14px">
    </div>

    <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
      <button class="bb-btn badminton-btn-reset" onclick="closeNewMatchModal()" style="flex:none;padding:0 20px">Cancel</button>
      <button class="bb-btn btn-newset" id="nmConfirmBtn" onclick="confirmNewMatch()" style="flex:none;padding:0 24px">✅ Start Match</button>
    </div>
  </div>
</div>

<style>
/* ── NEW MATCH modal extras ── */
.nm-type-btn {
  flex:1;padding:7px 4px;border:2px solid #555;background:#222;color:#ccc;
  border-radius:6px;cursor:pointer;font-weight:700;font-size:12px;transition:.15s;
}
.nm-type-btn.active { border-color:#FFE600;background:#3a3200;color:#FFE600; }
.nm-player-input {
  padding:8px;border-radius:6px;border:1px solid #444;background:#222;
  color:#fff;font-size:13px;width:100%;box-sizing:border-box;
}
#btnNewMatch { background:#1a5c1a;border-color:#2a8c2a; }
#btnNewMatch:hover { background:#236b23; }

/* Winner Modal Styles */
#winnerModal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.7);
  display: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  z-index: 99999;
}

#winnerModal .modal-content {
  background: linear-gradient(135deg, #FFE600 0%, #FFA500 100%);
  padding: 30px;
  border-radius: 12px;
  text-align: center;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
  animation: popIn 0.4s ease-out;
}

#winnerModal h2 {
  margin: 0 0 15px 0;
  color: #222;
  font-size: 28px;
  font-weight: 900;
  text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
}

#winnerModal p {
  margin: 0 0 25px 0;
  color: #222;
  font-size: 18px;
  font-weight: 700;
}

#winnerModal button {
  background: #1a5c1a;
  color: #FFE600;
  border: 2px solid #2a8c2a;
  padding: 10px 30px;
  border-radius: 6px;
  font-weight: 700;
  cursor: pointer;
  font-size: 14px;
  transition: 0.2s;
}

#winnerModal button:hover {
  background: #236b23;
  transform: scale(1.05);
}

@keyframes popIn {
  0% { transform: scale(0.5); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}

</style>

<script src="badminton_admin.js"></script>
</body>
</html>