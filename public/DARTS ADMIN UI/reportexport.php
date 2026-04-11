<?php
// report_export.php
require_once 'db_config.php';

$match_id = intval($_GET['match_id'] ?? 0);
$format   = $_GET['format'] ?? 'html';

if (!$match_id) {
    die('match_id required');
}

// Fetch data (same as get_match.php)
$match = $conn->query("SELECT * FROM matches WHERE id=$match_id")->fetch_assoc();
if (!$match) die('Match not found');

$summary = $conn->query("SELECT * FROM match_summary WHERE match_id=$match_id")->fetch_assoc();

$players = [];
$pres = $conn->query("SELECT * FROM players WHERE match_id=$match_id ORDER BY player_number");
while ($r = $pres->fetch_assoc()) $players[$r['id']] = $r;

$legs_res = $conn->query("SELECT * FROM legs WHERE match_id=$match_id ORDER BY leg_number");
$legs = [];
while ($leg = $legs_res->fetch_assoc()) {
    $lid = $leg['id'];
    $tres = $conn->query("SELECT * FROM throws WHERE leg_id=$lid ORDER BY player_id, throw_number");
    $tbp = [];
    while ($t = $tres->fetch_assoc()) $tbp[$t['player_id']][] = $t;
    $leg['throws'] = $tbp;
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
    ?>
    <td><?= implode(', ', $vals) ?: '—' ?></td>
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
    header('Content-Type: text/tab-separated-values');
    header('Content-Disposition: attachment; filename="match_' . $match_id . '_report.xls"');
    echo "MATCH OVERVIEW\n";
    echo "Game Type\tLegs to Win\tMode\tDate\tWinner\n";
    echo "{$match['game_type']}\t{$match['legs_to_win']}\t{$match['mode']}\t{$date}\t" . ($match['winner_name'] ?? '') . "\n\n";
    echo "PLAYER ROSTER\n";
    echo "Player #\tName\tTeam\n";
    foreach ($players as $p) {
        echo "{$p['player_number']}\t{$p['player_name']}\t" . ($p['team_name'] ?? '') . "\n";
    }
    echo "\nFINAL STANDINGS\n";
    echo "Rank\tPlayer\tTeam\tLegs Won\tAvg Throw/Leg\n";
    foreach ($sorted_players as $i => $p) {
        $pid = $p['id'];
        $avg = ($throw_counts[$pid] ?? 0) > 0 ? round($throw_totals[$pid] / $throw_counts[$pid], 1) : 0;
        echo ($i+1) . "\t{$p['player_name']}\t" . ($p['team_name'] ?? '') . "\t" . ($legs_won[$pid] ?? 0) . "\t$avg\n";
    }
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