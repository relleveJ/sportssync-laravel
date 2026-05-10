<?php
// report_export.php
require_once 'db_config.php';

$match_id = intval($_GET['match_id'] ?? 0);
$format   = $_GET['format'] ?? 'html';

if (!$match_id) {
    die('match_id required');
}

// Detect darts_ prefixed tables
$prefix = '';
$r = $conn->query("SHOW TABLES LIKE 'darts_matches'");
if ($r && $r->num_rows) $prefix = 'darts_';
$matchesTable = $prefix . 'matches';
$playersTable = $prefix . 'players';
$legsTable = $prefix . 'legs';
$throwsTable = $prefix . 'throws';
$summaryTable = $prefix . 'match_summary';

// Fetch data (same as get_match.php)
$match = $conn->query("SELECT * FROM `{$matchesTable}` WHERE id=$match_id")->fetch_assoc();
if (!$match) die('Match not found');
$summary = $conn->query("SELECT * FROM `{$summaryTable}` WHERE match_id=$match_id")->fetch_assoc();

$players = [];
$pres = $conn->query("SELECT * FROM `{$playersTable}` WHERE match_id=$match_id ORDER BY player_number");
while ($r = $pres->fetch_assoc()) $players[$r['id']] = $r;
$legs_res = $conn->query("SELECT * FROM `{$legsTable}` WHERE match_id=$match_id ORDER BY leg_number");
$legs = [];
while ($leg = $legs_res->fetch_assoc()) {
    $lid = $leg['id'];
  $tres = $conn->query("SELECT * FROM `{$throwsTable}` WHERE leg_id=$lid ORDER BY player_id, throw_number");
  $tbp = [];
  while ($t = $tres->fetch_assoc()) $tbp[$t['player_id']][] = $t;
  // ensure throws included for players with no throws
  $throws_full = [];
  foreach ($players as $pid => $pinfo) {
    $throws_full[$pid] = $tbp[$pid] ?? [];
  }
  $leg['throws'] = $throws_full;
  // compute per-player avg for this leg (exclude busts)
  $leg_avg = [];
  foreach ($throws_full as $pid => $tarr) {
    $sum = 0; $count = 0;
    foreach ($tarr as $tt) {
      if (empty($tt['is_bust'])) { $sum += intval($tt['throw_value']); $count++; }
    }
    $leg_avg[$pid] = $count > 0 ? round($sum / $count, 1) : 0;
  }
  $leg['avg_per_player'] = $leg_avg;
    $legs[] = $leg;
}

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
    if ($leg['winner_player_id']) $legs_won[$leg['winner_player_id']] = ($legs_won[$leg['winner_player_id']] ?? 0) + 1;
    foreach ($leg['throws'] as $pid => $throws) {
        foreach ($throws as $t) {
            if (!$t['is_bust']) {
                $throw_totals[$pid] = ($throw_totals[$pid] ?? 0) + $t['throw_value'];
                $throw_counts[$pid] = ($throw_counts[$pid] ?? 0) + 1;
            }
        }
    }
}

// Sort players by legs won
$sorted_players = array_values($players);
usort($sorted_players, fn($a,$b) => ($legs_won[$b['id']] ?? 0) <=> ($legs_won[$a['id']] ?? 0));

$date = date('Y-m-d H:i', strtotime($match['created_at']));

// Determine match winner by legs_to_win threshold if present
$overallWinner = $match['winner_name'] ?? null;
$legsToWin = intval($match['legs_to_win'] ?? 0);
if (!$overallWinner && $legsToWin > 0) {
  foreach ($legs_won as $pid => $count) {
    if ($count >= $legsToWin && isset($players[$pid])) {
      $overallWinner = $players[$pid]['player_name'];
      break;
    }
  }
}

// Build HTML report
function buildReportHTML($match, $summary, $players, $legs, $legs_won, $throw_totals, $throw_counts, $sorted_players, $date, $isPrint = false) {
    $printScript = $isPrint ? '<script>window.onload=function(){window.print();}</script>' : '';
    $title = htmlspecialchars($match['game_type']) . ' Darts Match Report';
    ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $title ?></title>
<style>
  body { font-family: Arial, sans-serif; background: #111; color: #f0f0f0; margin: 0; padding: 20px; }
  h1 { color: #FFE600; text-align: center; border-bottom: 2px solid #FFE600; padding-bottom: 10px; }
  h2 { color: #FFE600; margin-top: 30px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  th { background: #222; color: #FFE600; padding: 8px 12px; text-align: left; border: 1px solid #444; }
  td { padding: 7px 12px; border: 1px solid #333; }
  tr:nth-child(even) td { background: #1a1a1a; }
  .winner { color: #00cc44; font-weight: bold; }
  .export-btns { display: flex; gap: 10px; margin: 20px 0; flex-wrap: wrap; }
  .export-btns a { background: #CC0000; color: #fff; padding: 10px 18px; text-decoration: none; border-radius: 4px; font-weight: bold; }
  .export-btns a:hover { background: #aa0000; }
  @media print { .export-btns { display: none; } body { background: #fff; color: #000; } th { background: #eee; color: #000; } }
</style>
<?= $printScript ?>
</head>
<body>
<h1>🎯 <?= $title ?></h1>

<?php if (!$isPrint): ?>
<div class="export-btns">
  <a href="report_export.php?match_id=<?= $match['id'] ?>&format=html" download="match_<?= $match['id'] ?>_report.html">⬇ Save as HTML</a>
  <a href="report_export.php?match_id=<?= $match['id'] ?>&format=excel">⬇ Save as Excel</a>
  <a href="report_export.php?match_id=<?= $match['id'] ?>&format=print" target="_blank">🖨 Print / PDF</a>
</div>
<?php endif; ?>

<h2>Match Overview</h2>
<table>
  <tr><th>Game Type</th><th>Legs to Win</th><th>Mode</th><th>Date</th><th>Winner</th></tr>
  <tr>
    <td><?= htmlspecialchars($match['game_type']) ?></td>
    <td><?= intval($match['legs_to_win']) ?></td>
    <td><?= htmlspecialchars($match['mode']) ?></td>
    <td><?= htmlspecialchars($date) ?></td>
    <td class="winner"><?= htmlspecialchars($match['winner_name'] ?? '—') ?></td>
  </tr>
</table>

<h2>Player Roster</h2>
<table>
  <tr><th>Player #</th><th>Name</th><th>Team</th></tr>
  <?php foreach ($players as $p): ?>
  <tr>
    <td><?= intval($p['player_number']) ?></td>
    <td><?= htmlspecialchars($p['player_name']) ?></td>
    <td><?= htmlspecialchars($p['team_name'] ?? '—') ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Leg-by-Leg Results</h2>
<table>
  <tr>
    <th>Leg #</th><th>Winner</th>
    <?php foreach ($players as $p): ?>
    <th><?= htmlspecialchars($p['player_name']) ?> Throws</th>
    <?php endforeach; ?>
  </tr>
  <?php foreach ($legs as $leg): ?>
  <tr>
    <td><?= intval($leg['leg_number']) ?></td>
    <td><?= isset($players[$leg['winner_player_id']]) ? htmlspecialchars($players[$leg['winner_player_id']]['player_name']) : '—' ?></td>
    <?php foreach ($players as $pid => $p):
      $ts = $leg['throws'][$pid] ?? [];
      $vals = array_map(fn($t) => $t['is_bust'] ? '<span style="color:#e55">BUST('.$t['throw_value'].')</span>' : $t['throw_value'], $ts);
      $avg = $leg['avg_per_player'][$pid] ?? 0;
      $displayAvg = $avg > 0 ? $avg : '—';
    ?>
    <td><?= (count($vals) ? implode(', ', $vals) . ' (avg ' . htmlspecialchars((string)$displayAvg) . ')' : '—') ?></td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Final Standings</h2>
<table>
  <tr><th>Rank</th><th>Player</th><th>Team</th><th>Legs Won</th><th>Avg Throw/Leg</th></tr>
  <?php foreach ($sorted_players as $i => $p):
    $pid = $p['id'];
    $avg = ($throw_counts[$pid] ?? 0) > 0 ? round($throw_totals[$pid] / $throw_counts[$pid], 1) : '—';
  ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td class="<?= $i===0 ? 'winner' : '' ?>"><?= htmlspecialchars($p['player_name']) ?></td>
    <td><?= htmlspecialchars($p['team_name'] ?? '—') ?></td>
    <td><?= $legs_won[$pid] ?? 0 ?></td>
    <td><?= $avg ?></td>
  </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
<?php
    return ob_get_clean();
}

if ($format === 'excel') {
    // Output an HTML table which Excel will open; add pink header and borders
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="match_' . $match_id . '_report.xls"');
    echo "\xEF\xBB\xBF"; // BOM
    // Build a simple HTML with inline styles suitable for Excel
    $excelHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>';
    $excelHtml .= 'table{border-collapse:collapse;font-family:Arial,Helvetica,sans-serif}';
    $excelHtml .= 'th,td{border:1px solid #d99fbf;padding:6px;text-align:left}';
    $excelHtml .= 'th{background:#ffd6ec;color:#6a0059;font-weight:700}';
    $excelHtml .= '</style></head><body>';
    $excelHtml .= '<h2>Darts Match Report</h2>';
    // Match overview
    $excelHtml .= '<table><tr><th>Game Type</th><th>Legs to Win</th><th>Mode</th><th>Date</th><th>Winner</th></tr>';
    $excelHtml .= '<tr><td>' . htmlspecialchars($match['game_type']) . '</td><td>' . intval($match['legs_to_win']) . '</td><td>' . htmlspecialchars($match['mode']) . '</td><td>' . htmlspecialchars($date) . '</td><td>' . htmlspecialchars($match['winner_name'] ?? '') . '</td></tr></table>';
    // Player roster
    $excelHtml .= '<h3>Player Roster</h3><table><tr><th>Player #</th><th>Name</th><th>Team</th></tr>';
    foreach ($players as $p) {
        $excelHtml .= '<tr><td>' . intval($p['player_number']) . '</td><td>' . htmlspecialchars($p['player_name']) . '</td><td>' . htmlspecialchars($p['team_name'] ?? '') . '</td></tr>';
    }
    $excelHtml .= '</table>';
    // Leg-by-leg
    $excelHtml .= '<h3>Leg-by-Leg</h3><table><tr><th>Leg #</th><th>Winner</th>';
    foreach ($players as $p) { $excelHtml .= '<th>' . htmlspecialchars($p['player_name']) . ' Throws</th>'; }
    $excelHtml .= '</tr>';
    foreach ($legs as $leg) {
        $excelHtml .= '<tr><td>' . intval($leg['leg_number']) . '</td><td>' . htmlspecialchars($players[$leg['winner_player_id']]['player_name'] ?? '') . '</td>';
      foreach ($players as $pid => $p) {
        $ts = $leg['throws'][$pid] ?? [];
        $vals = array_map(fn($t) => empty($t['is_bust']) ? intval($t['throw_value']) : 'BUST(' . intval($t['throw_value']) . ')', $ts);
        $avg = $leg['avg_per_player'][$pid] ?? 0;
        $displayAvg = $avg > 0 ? $avg : '—';
        $excelHtml .= '<td>' . htmlspecialchars(implode(', ', $vals)) . (count($vals) ? ' (avg ' . htmlspecialchars((string)$displayAvg) . ')' : '') . '</td>';
      }
        $excelHtml .= '</tr>';
    }
    $excelHtml .= '</table>';
    // Final standings
    $excelHtml .= '<h3>Final Standings</h3><table><tr><th>Rank</th><th>Player</th><th>Team</th><th>Legs Won</th><th>Avg Throw/Leg</th></tr>';
    foreach ($sorted_players as $i => $p) {
        $pid = $p['id'];
        $avg = ($throw_counts[$pid] ?? 0) > 0 ? round($throw_totals[$pid] / $throw_counts[$pid], 1) : 0;
        $excelHtml .= '<tr><td>' . ($i+1) . '</td><td>' . htmlspecialchars($p['player_name']) . '</td><td>' . htmlspecialchars($p['team_name'] ?? '') . '</td><td>' . intval($legs_won[$pid] ?? 0) . '</td><td>' . $avg . '</td></tr>';
    }
    $excelHtml .= '</table></body></html>';
    echo $excelHtml;
} elseif ($format === 'print') {
    echo buildReportHTML($match, $summary, $players, $legs, $legs_won, $throw_totals, $throw_counts, $sorted_players, $date, true);
} else {
    // html download
    $html = buildReportHTML($match, $summary, $players, $legs, $legs_won, $throw_totals, $throw_counts, $sorted_players, $date, false);
    if (isset($_GET['download'])) {
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="match_' . $match_id . '_report.html"');
    }
    echo $html;
}