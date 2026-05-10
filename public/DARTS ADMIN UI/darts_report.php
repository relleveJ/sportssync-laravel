<?php
// darts_report.php — Darts Match Report (Pink Theme)
// Usage: darts_report.php?match_id=N

require_once 'db_config.php';

$match_id = intval($_GET['match_id'] ?? 0);
if ($match_id <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><body style="background:#111;color:#f0f0f0;font-family:monospace;padding:40px">Invalid or missing match_id parameter.</body></html>';
    exit;
}

// Detect darts_ prefix
$prefix = '';
$r = $conn->query("SHOW TABLES LIKE 'darts_matches'");
if ($r && $r->num_rows) $prefix = 'darts_';
$matchesTable = $prefix . 'matches';
$playersTable = $prefix . 'players';
$legsTable = $prefix . 'legs';
$throwsTable = $prefix . 'throws';
$summaryTable = $prefix . 'match_summary';

// Fetch match
$mstmt = $conn->prepare("SELECT * FROM `{$matchesTable}` WHERE id=? LIMIT 1");
$mstmt->bind_param('i', $match_id);
$mstmt->execute();
$match = $mstmt->get_result()->fetch_assoc();
$mstmt->close();

if (!$match) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="background:#111;color:#f0f0f0;font-family:monospace;padding:40px">Match not found.</body></html>';
    exit;
}

// Players
$pstmt = $conn->prepare("SELECT * FROM `{$playersTable}` WHERE match_id=? ORDER BY player_number");
$pstmt->bind_param('i', $match_id);
$pstmt->execute();
$players_res = $pstmt->get_result();
$players = [];
while ($row = $players_res->fetch_assoc()) $players[$row['id']] = $row;
$pstmt->close();

// Legs
$lstmt = $conn->prepare("SELECT * FROM `{$legsTable}` WHERE match_id=? ORDER BY leg_number");
$lstmt->bind_param('i', $match_id);
$lstmt->execute();
$legs_res = $lstmt->get_result();
$legs = [];
while ($leg = $legs_res->fetch_assoc()) {
    $lid = $leg['id'];
    $tres = $conn->query("SELECT * FROM `{$throwsTable}` WHERE leg_id=" . intval($lid) . " ORDER BY player_id, throw_number");
    $tbp = [];
    while ($t = $tres->fetch_assoc()) $tbp[$t['player_id']][] = $t;
  // Ensure throws are present per player (empty arrays for players with no throws)
  $throws_full = [];
  foreach ($players as $pid => $pinfo) {
    $throws_full[$pid] = $tbp[$pid] ?? [];
  }
  $leg['throws'] = $throws_full;

  // compute per-player average (exclude busts) for this leg
  $leg_avg = [];
  foreach ($throws_full as $pid => $tarr) {
    $sum = 0; $count = 0;
    foreach ($tarr as $tt) {
      if (empty($tt['is_bust'])) {
        $sum += intval($tt['throw_value']);
        $count++;
      }
    }
    $leg_avg[$pid] = $count > 0 ? round($sum / $count, 1) : 0;
  }
  $leg['avg_per_player'] = $leg_avg;
    $legs[] = $leg;
}
$lstmt->close();

// Compute stats
$legs_won = [];
$throw_totals = [];
$throw_counts = [];
foreach ($players as $pid => $p) {
    $legs_won[$pid] = 0;
    $throw_totals[$pid] = 0;
    $throw_counts[$pid] = 0;
}
foreach ($legs as $leg) {
    if (!empty($leg['winner_player_id'])) $legs_won[$leg['winner_player_id']] = ($legs_won[$leg['winner_player_id']] ?? 0) + 1;
    foreach ($leg['throws'] as $pid => $throws) {
        foreach ($throws as $t) {
            if (empty($t['is_bust'])) {
                $throw_totals[$pid] = ($throw_totals[$pid] ?? 0) + intval($t['throw_value']);
                $throw_counts[$pid] = ($throw_counts[$pid] ?? 0) + 1;
            }
        }
    }
}

// Determine winner by legs and by legs_to_win threshold
$winner_player_id = null;
if (!empty($legs_won)) {
  // if any player reached legs_to_win, prefer that
  $legsToWin = intval($match['legs_to_win'] ?? 0);
  if ($legsToWin > 0) {
    foreach ($legs_won as $pid => $cnt) {
      if ($cnt >= $legsToWin) { $winner_player_id = intval($pid); break; }
    }
  }
  // otherwise use max legs
  if ($winner_player_id === null) {
    arsort($legs_won);
    $winner_player_id = intval(array_key_first($legs_won));
  }
}
// If summary exists prefer declared winner
$sres = $conn->query("SELECT * FROM `{$summaryTable}` WHERE match_id=" . intval($match_id) . " LIMIT 1");
$summary = $sres ? $sres->fetch_assoc() : null;
if ($summary && !empty($summary['winner_player_id'])) {
  $winner_player_id = intval($summary['winner_player_id']);
}

// Upsert minimal summary fields
$haveSummary = false;
$r = $conn->query("SHOW TABLES LIKE '{$summaryTable}'");
if ($r && $r->num_rows) $haveSummary = true;
if ($haveSummary) {
    $total_legs = count($legs);
    // build per-player legs_won array in order p1..p4 if columns exist
    $cols = [];
    $cres = $conn->query("SHOW COLUMNS FROM `{$summaryTable}`");
    while ($c = $cres->fetch_assoc()) $cols[] = $c['Field'];

    // check existing row
    $sstmt = $conn->prepare("SELECT id FROM `{$summaryTable}` WHERE match_id=?");
    $sstmt->bind_param('i', $match_id);
    $sstmt->execute();
    $srow = $sstmt->get_result()->fetch_assoc();
    $sstmt->close();

    if ($srow) {
        $updates = [];
        $bind = [];
        $types = '';
        if (in_array('total_legs', $cols)) { $updates[] = 'total_legs=?'; $bind[] = $total_legs; $types .= 'i'; }
        // player1_legs_won..player4_legs_won
        for ($i = 1; $i <= 4; $i++) {
            $col = 'player' . $i . '_legs_won';
            if (in_array($col, $cols)) { $updates[] = "$col=?"; $pid = null; foreach ($players as $pId => $p) { if (intval($p['player_number']) === $i) { $pid = $pId; break; } } $bind[] = intval($legs_won[$pid] ?? 0); $types .= 'i'; }
        }
        if (in_array('winner_player_id', $cols)) { $updates[] = 'winner_player_id=?'; $bind[] = $winner_player_id; $types .= 'i'; }
        if (count($updates)) {
            $sql = "UPDATE `{$summaryTable}` SET " . implode(',', $updates) . " WHERE match_id=?";
            $stmtu = $conn->prepare($sql);
            $refs = [];
            foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
            $refs[] = &$match_id;
            $types_full = $types . 'i';
            array_unshift($refs, $types_full);
            call_user_func_array([$stmtu, 'bind_param'], $refs);
            $stmtu->execute();
            $stmtu->close();
        }
    } else {
        $insCols = ['match_id'];
        $insVals = ['?'];
        $bind = [$match_id];
        $types = 'i';
        if (in_array('total_legs', $cols)) { $insCols[] = 'total_legs'; $insVals[] = '?'; $bind[] = $total_legs; $types .= 'i'; }
        for ($i = 1; $i <= 4; $i++) {
            $col = 'player' . $i . '_legs_won';
            if (in_array($col, $cols)) { $insCols[] = $col; $insVals[] = '?'; $pid = null; foreach ($players as $pId => $p) { if (intval($p['player_number']) === $i) { $pid = $pId; break; } } $bind[] = intval($legs_won[$pid] ?? 0); $types .= 'i'; }
        }
        if (in_array('winner_player_id', $cols)) { $insCols[] = 'winner_player_id'; $insVals[] = '?'; $bind[] = $winner_player_id; $types .= 'i'; }
        $sql = "INSERT INTO `{$summaryTable}` (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")";
        $stins = $conn->prepare($sql);
        $refs = [];
        foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
        array_unshift($refs, $types);
        call_user_func_array([$stins, 'bind_param'], $refs);
        $stins->execute();
        $stins->close();
    }

    // Update matches.winner_name/status if winner resolved
    if ($winner_player_id) {
        $wname = $players[$winner_player_id]['player_name'] ?? null;
        if ($wname) {
            $ust = $conn->prepare("UPDATE `{$matchesTable}` SET winner_name=?, status='completed' WHERE id=?");
            $ust->bind_param('si', $wname, $match_id);
            $ust->execute();
            $ust->close();
        }
    }
}

// Sort players by legs won
$sorted_players = array_values($players);
usort($sorted_players, fn($a,$b) => ($legs_won[$b['id']] ?? 0) <=> ($legs_won[$a['id']] ?? 0));

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$date = date('Y-m-d H:i', strtotime($match['created_at'] ?? 'now'));

// JSON payload for Excel export
$jsonMatch = json_encode([
    'match_id' => $match_id,
    'saved_at' => $match['created_at'] ?? null,
    'game_type'=> $match['game_type'] ?? '',
    'total_legs'=> count($legs),
    'winner_player_id' => $winner_player_id,
    'players' => array_values($players),
    'legs' => $legs,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Darts Report #<?= $match_id ?> — SportSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <style>
    /* Pink theme inspired by badminton report layout */
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial, sans-serif;background:#fff0f8;color:#222;min-height:100vh;padding-bottom:60px}
    .export-bar{background:#6a0059;border-bottom:3px solid #ff66b2;padding:10px 20px;display:flex;align-items:center;gap:10px;justify-content:flex-end;position:sticky;top:0;z-index:50}
    .bar-title{font-weight:700;color:#ff66b2;margin-right:auto}
    .btn-export{border:none;cursor:pointer;padding:8px 16px;border-radius:4px;font-weight:700}
    .btn-excel{background:#c2185b;color:#fff}
    .btn-print{background:#ff66b2;color:#111}
    .container{max-width:960px;margin:24px auto;background:#fff;padding:22px;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,.06)}
    .report-header{display:flex;align-items:center;gap:12px}
    .report-title{font-size:28px;color:#6a0059;font-weight:800}
    .report-meta{color:#666}
    .info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:14px}
    .info-card{padding:12px;border-radius:8px;background:#fff0f6;border:1px solid #ffe0f0}
    .section-title{font-weight:800;color:#6a0059;margin-top:18px;margin-bottom:8px}
    table{width:100%;border-collapse:collapse;margin-top:8px}
    th,td{padding:8px;border:1px solid #f4d7e6}
    th{background:#fff0f6;color:#6a0059}
    .winner{color:#d81b60;font-weight:700}
  </style>
</head>
<body>
  <div class="export-bar">
    <div class="bar-title">Darts Match Report</div>
    <button class="btn-export" onclick="location.href='history.html'">← Back to Match History</button>
    <!-- Use an anchor for download so browser handles it natively -->
    <a class="btn-export btn-excel" href="report_export.php?match_id=<?= $match_id ?>&format=excel">⬇ Save as Excel</a>
    <button class="btn-export btn-print" onclick="window.print()">🖨 Print / PDF</button>
    <button class="btn-export" style="background:#ff66b2;color:#111;border:0;font-weight:700;margin-left:8px;padding:8px 14px;border-radius:4px" onclick="newMatch()">➕ New Match</button>
  </div>
  <div class="container">
    <div class="report-header">
      <div class="report-title">🎯 Darts Match Report</div>
      <div class="report-meta">Saved <?= h($date) ?></div>
    </div>

    <div class="info-grid">
      <div class="info-card"><div class="ic-label">Game Type</div><div class="ic-value"><?= h($match['game_type'] ?? '') ?></div></div>
      <div class="info-card"><div class="ic-label">Legs Recorded</div><div class="ic-value"><?= count($legs) ?></div></div>
      <div class="info-card"><div class="ic-label">Winner</div><div class="ic-value winner"><?= h($players[$winner_player_id]['player_name'] ?? '—') ?></div></div>
    </div>

    <div class="section-title">Player Roster</div>
    <table>
      <tr><th>#</th><th>Name</th><th>Team</th><th>Legs Won</th><th>Avg Throw</th></tr>
      <?php foreach ($sorted_players as $p): $pid = $p['id']; $avg = ($throw_counts[$pid] ?? 0) > 0 ? round(($throw_totals[$pid] ?? 0)/($throw_counts[$pid] ?? 1),1) : '—'; ?>
      <tr>
        <td><?= intval($p['player_number']) ?></td>
        <td><?= h($p['player_name']) ?></td>
        <td><?= h($p['team_name'] ?? '—') ?></td>
        <td><?= intval($legs_won[$pid] ?? 0) ?></td>
        <td><?= $avg ?></td>
      </tr>
      <?php endforeach; ?>
    </table>

    <div class="section-title">Leg-by-Leg</div>
    <table>
      <tr><th>Leg #</th><th>Winner</th><th>Throws</th></tr>
        <?php foreach ($legs as $leg): ?>
        <tr>
          <td><?= intval($leg['leg_number']) ?></td>
          <td><?= h($players[$leg['winner_player_id']]['player_name'] ?? '—') ?></td>
          <td>
            <?php
              // Show each player's throws for this leg (include players with no throws)
              foreach ($players as $pid => $pinfo) {
                  $tarr = $leg['throws'][$pid] ?? [];
                  $vals = array_map(fn($t)=> (empty($t['is_bust']) ? intval($t['throw_value']) : 'BUST('.intval($t['throw_value']).')'), $tarr);
                  $avg = $leg['avg_per_player'][$pid] ?? 0;
                  $displayAvg = $avg > 0 ? $avg : '—';
                  echo h($pinfo['player_name'] ?? 'Player') . ': ' . (count($vals) ? h(implode(', ', $vals)) . ' (avg ' . h($displayAvg) . ')' : '—') . '<br>';
              }
            ?>
          </td>
        </tr>
        <?php endforeach; ?>
    </table>
  </div>
  <script>
  (function(){
    try{
      if (sessionStorage.getItem('disableBackAfterSave_darts') === '1') {
        try { sessionStorage.removeItem('disableBackAfterSave_darts'); } catch(e){}
        try { localStorage.removeItem('darts_admin_state_v1'); } catch(e){}
        try { sessionStorage.removeItem('darts_match_id'); } catch(e){}
        history.pushState(null, null, location.href);
        window.addEventListener('popstate', function(){ history.pushState(null, null, location.href); });
      }
    }catch(e){}
  })();
  function newMatch(){
    try { localStorage.removeItem('darts_admin_state_v1'); } catch(e){}
    try { sessionStorage.removeItem('darts_match_id'); } catch(e){}
    try { sessionStorage.setItem('disableBackAfterSave_darts', '1'); } catch(e){}

    // Navigate to start a new match — no broadcast to avoid updating other admins prematurely
    window.location.href = 'index.php?new=1';
  }
  </script>
  </body>
</html>