<?php
// Public viewer — read-only access, no authentication required
$matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : null;
$defaultMatchId = 0;
if (!$matchId) {
  require_once __DIR__ . '/../db.php';
  try {
    $dbPayload = null; $dbUpdated = null; $dbMatchId = 0;
    if (isset($pdo) && $pdo) {
      $st = $pdo->query("SELECT match_id, payload, updated_at FROM draft_match_states ORDER BY updated_at DESC LIMIT 1");
      if ($st) {
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['payload'])) {
          $dbPayload = json_decode($r['payload'], true);
          $dbUpdated = $r['updated_at'] ?? null;
          $dbMatchId = isset($r['match_id']) ? (int)$r['match_id'] : 0;
        }
      }
    }
    $pendingFile = __DIR__ . '/volleyball_pending_state.json';
    $pendingPayload = null; $pendingUpdated = null; $pendingMatchId = 0;
    if (file_exists($pendingFile)) {
      $raw = @file_get_contents($pendingFile);
      $decoded = $raw ? json_decode($raw, true) : null;
      if (is_array($decoded)) {
        if (isset($decoded['payload']) && isset($decoded['match_id'])) {
          $pendingPayload = $decoded['payload'];
          $pendingUpdated = $decoded['updated_at'] ?? null;
          $pendingMatchId = isset($decoded['match_id']) ? (int)$decoded['match_id'] : 0;
        } else {
          $pendingPayload = $decoded;
          $pendingUpdated = null;
          $pendingMatchId = 0;
        }
      }
    }
    if ($pendingPayload && $dbPayload) {
      $dbTs = $dbUpdated ? strtotime($dbUpdated) : 0;
      $pendingTs = $pendingUpdated ? strtotime($pendingUpdated) : 0;
      $pendingSsotTs = isset($pendingPayload['_ssot_ts']) ? (int)($pendingPayload['_ssot_ts'] / 1000) : $pendingTs;
      $dbSsotTs = isset($dbPayload['_ssot_ts']) ? (int)($dbPayload['_ssot_ts'] / 1000) : $dbTs;
      $defaultMatchId = ($pendingSsotTs >= $dbSsotTs) ? $pendingMatchId : $dbMatchId;
    } elseif ($pendingPayload) {
      $defaultMatchId = $pendingMatchId;
    } elseif ($dbPayload) {
      $defaultMatchId = $dbMatchId;
    }
    if ($defaultMatchId > 0) {
      $matchId = $defaultMatchId;
    }
  } catch (Throwable $_) {
    // ignore fallback lookup failures
  }
}
if ($matchId) {
  if (!isset($pdo)) {
    require_once __DIR__ . '/../db.php';
  }
  try {
    $chk = $pdo->prepare('SELECT match_id FROM volleyball_matches WHERE match_id = ? LIMIT 1');
    $chk->execute([$matchId]);
    if (!$chk->fetch()) {
      http_response_code(404);
      echo '<!DOCTYPE html><html><body style="background:#111;color:#FFD700;font-family:sans-serif;padding:60px;text-align:center"><h1>Match Not Found</h1><p>Match ID ' . (int)$matchId . ' does not exist.</p><a href="/" style="color:#FFD700">← Back to home</a></body></html>';
      exit;
    }
  } catch (Throwable $e) { /* ignore DB check failures, show page anyway */ }
}
// ── SportSync Guards ──────────────────────────────────────────
$_ss_db_loaded = false;
if (!isset($pdo)) {
    try {
        $__dbf = file_exists(__DIR__.'/db.php') ? __DIR__.'/db.php' : (file_exists(__DIR__.'/../db.php') ? __DIR__.'/../db.php' : null);
        if ($__dbf) { require_once $__dbf; $_ss_db_loaded = true; }
    } catch (Throwable $e) {}
} else { $_ss_db_loaded = true; }
if ($_ss_db_loaded && !empty($pdo)) {
    $__sg=null;foreach([__DIR__,__DIR__.'/..',__DIR__.'/../..'] as $__d){if(file_exists($__d.'/system_guard.php')){$__sg=$__d.'/system_guard.php';break;}}if($__sg) require_once $__sg;
    ss_check_maintenance($pdo);         // viewer: full block
    ss_check_sport($pdo, 'Volleyball');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Volleyball Viewer — Live</title>
<link rel="stylesheet" href="volleyball_viewer.css?t=<?= filemtime(__DIR__ . '/volleyball_viewer.css') ?>">
<script>
window.__matchId = <?= json_encode($matchId) ?>;
window.__defaultRoomId = <?= json_encode($defaultMatchId) ?>;
window.__wsToken = '';
</script>
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
      <span class="nav-title">Volleyball Viewer</span>
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
    <span class="nav-viewer-badge">&#128065; VIEWER VIEW</span>
  </div>
</nav>

<!-- COMMITTEE BAR -->
<div class="vbCommitteeBar">
  <span class="vbCommitteeLabel">Committee / Official:</span>
  <span class="vbCommitteeValue" id="vbCommitteeValue">—</span>
</div>

<!-- MAIN GRID -->
<div class="main-grid" id="mainGrid">

  <!-- TEAM A PANEL -->
  <div class="team-panel" id="panelA">
    <div class="col-header green">
      <span class="team-name-display" id="teamANameDisplay">TEAM A</span>
    </div>
    <div class="team-stats-bar" id="tsbA">
      <div class="tsb-item">
        <span class="tsb-label">Timeout</span>
        <div class="tsb-box"><span class="tsb-num" id="viewerTimeoutA">0</span></div>
      </div>
      <div class="tsb-sep"></div>
      <div class="tsb-item">
        <span class="tsb-label">Set</span>
        <div class="tsb-box"><span class="tsb-num" id="viewerSetA">1</span></div>
      </div>
    </div>
    <div class="col-body left-body">
      <table class="roster-table">
        <thead><tr>
          <th style="width:36px">No.</th>
          <th style="min-width:110px;text-align:left;padding-left:8px">Player Name</th>
          <th class="pts-head" style="min-width:52px">PTS</th>
          <th>SPIKE</th><th>ACE</th><th>EX SET</th><th>EX DIG</th>
        </tr></thead>
        <tbody id="tbodyA"></tbody>
      </table>
    </div>
  </div>

  <!-- TEAM B PANEL -->
  <div class="team-panel" id="panelB">
    <div class="col-header gray">
      <span class="team-name-display" id="teamBNameDisplay" style="color:#ccc">TEAM B</span>
    </div>
    <div class="team-stats-bar" id="tsbB">
      <div class="tsb-item">
        <span class="tsb-label">Timeout</span>
        <div class="tsb-box"><span class="tsb-num" id="viewerTimeoutB">0</span></div>
      </div>
      <div class="tsb-sep"></div>
      <div class="tsb-item">
        <span class="tsb-label">Set</span>
        <div class="tsb-box"><span class="tsb-num" id="viewerSetB">1</span></div>
      </div>
    </div>
    <div class="col-body center-body">
      <table class="roster-table">
        <thead><tr>
          <th style="width:36px">No.</th>
          <th style="min-width:110px;text-align:left;padding-left:8px">Player Name</th>
          <th class="pts-head" style="min-width:52px">PTS</th>
          <th>SPIKE</th><th>ACE</th><th>EX SET</th><th>EX DIG</th>
        </tr></thead>
        <tbody id="tbodyB"></tbody>
      </table>
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="right-panel" id="rightPanel">

    <!-- Team A Lineup Circle (read-only) -->
    <div class="lineup-section" id="lineupSectionA">
      <div class="lineup-team-label" id="viewerLineupLabelA">TEAM A — ACTIVE LINEUP</div>
      <div class="lineup-circle-wrap">
        <svg class="lineup-svg" id="viewerLineupSvgA" viewBox="0 0 140 140" xmlns="http://www.w3.org/2000/svg"></svg>
      </div>
    </div>

    <div class="rp-divider"></div>

    <!-- Team B Lineup Circle (read-only) -->
    <div class="lineup-section" id="lineupSectionB">
      <div class="lineup-team-label" id="viewerLineupLabelB">TEAM B — ACTIVE LINEUP</div>
      <div class="lineup-circle-wrap">
        <svg class="lineup-svg" id="viewerLineupSvgB" viewBox="0 0 140 140" xmlns="http://www.w3.org/2000/svg"></svg>
      </div>
    </div>

    <div class="rp-divider"></div>

    <!-- Set + Timeout display -->
    <div class="vb-counters-section">
      <div class="vb-counter-block">
        <div class="vb-counter-label">Current Set</div>
        <div class="vb-counter-box"><span class="vb-counter-num" id="viewerSet">1</span></div>
      </div>
      <div class="vb-counter-block">
        <div class="vb-counter-label">Timeouts Used</div>
        <div class="vb-timeout-teams">
          <div class="vb-timeout-team-block">
            <div class="vb-timeout-team-name" id="viewerToLabelA">TEAM A</div>
            <div class="vb-timeout-box"><span class="vb-timeout-num" id="viewerToA">0</span></div>
          </div>
          <div class="vb-timeout-team-block">
            <div class="vb-timeout-team-name" id="viewerToLabelB">TEAM B</div>
            <div class="vb-timeout-box"><span class="vb-timeout-num" id="viewerToB">0</span></div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /right-panel -->

</div><!-- /main-grid -->

<script src="volleyball_viewer.js?t=<?= filemtime(__DIR__ . '/volleyball_viewer.js') ?>"></script>
</body>
</html>