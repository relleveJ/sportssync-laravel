<?php
// ============================================================
// report.php — Match Report
// Usage: report.php?match_id=N
// ============================================================

require_once __DIR__ . '/db.php';

$matchId = isset($_GET['match_id']) ? (int) $_GET['match_id'] : 0;

if ($matchId <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><body style="background:#111;color:#f00;font-family:monospace;padding:40px">Invalid or missing match_id parameter.</body></html>';
    exit;
}

// Fetch match row
$stmtMatch = $pdo->prepare('SELECT * FROM `matches` WHERE match_id = :id LIMIT 1');
$stmtMatch->execute([':id' => $matchId]);
$match = $stmtMatch->fetch();

if (!$match) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="background:#111;color:#f00;font-family:monospace;padding:40px">Match ID ' . htmlspecialchars((string)$matchId) . ' not found.</body></html>';
    exit;
}

// Fetch all players ordered by team, then pts desc
$stmtPlayers = $pdo->prepare(
    'SELECT * FROM `match_players` WHERE match_id = :id ORDER BY team ASC, pts DESC'
);
$stmtPlayers->execute([':id' => $matchId]);
$allPlayers = $stmtPlayers->fetchAll();

// Split by team
$playersA = array_values(array_filter($allPlayers, fn($p) => $p['team'] === 'A'));
$playersB = array_values(array_filter($allPlayers, fn($p) => $p['team'] === 'B'));

// Compute MVP score: (pts×2) + (reb×1.2) + (ast×1.5) + (blk×1) + (stl×1) − (foul×0.5) − (tech_foul×1)
$mvp = null;
$mvpScore = PHP_INT_MIN;
foreach ($allPlayers as $p) {
    $score = ($p['pts'] * 2) + ($p['reb'] * 1.2) + ($p['ast'] * 1.5)
           + ($p['blk'] * 1) + ($p['stl'] * 1)
           - ($p['foul'] * 0.5) - ($p['tech_foul'] * 1);
    if ($score > $mvpScore) {
        $mvpScore  = $score;
        $mvp       = $p;
        $mvp['mvp_score'] = round($score, 2);
    }
}

// Helpers
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function teamLabel(string $t, array $match): string {
    return $t === 'A' ? h($match['team_a_name']) : h($match['team_b_name']);
}

$savedAt   = date('F j, Y  •  g:i A', strtotime($match['saved_at']));
$committee = h($match['committee'] ?? '');
$result    = h($match['match_result']);
$teamAName = h($match['team_a_name']);
$teamBName = h($match['team_b_name']);
$scoreA    = (int)$match['team_a_score'];
$scoreB    = (int)$match['team_b_score'];

// Friendly label for the result area: winning team name or Draw
$resultLabel = ($match['match_result'] === 'TEAM A WINS') ? $teamAName
             : (($match['match_result'] === 'TEAM B WINS') ? $teamBName : 'Draw');

// Totals
function sumCols(array $players, array $cols): array {
    $t = array_fill_keys($cols, 0);
    foreach ($players as $p) {
        foreach ($cols as $c) { $t[$c] += (int)$p[$c]; }
    }
    return $t;
}
$statCols = ['pts','foul','reb','ast','blk','stl','tech_foul'];
$totalsA  = sumCols($playersA, $statCols);
$totalsB  = sumCols($playersB, $statCols);

// Inline JSON for Excel export
$jsonMatch = json_encode([
    'match_id'   => $matchId,
    'saved_at'   => $match['saved_at'],
    'committee'  => $match['committee'] ?? '',
    'team_a_name'=> $match['team_a_name'],
    'team_b_name'=> $match['team_b_name'],
    'team_a_score'=> $scoreA,
    'team_b_score'=> $scoreB,
    'team_a_foul' => (int)$match['team_a_foul'],
    'team_a_timeout'=> (int)$match['team_a_timeout'],
    'team_a_quarter'=> (int)$match['team_a_quarter'],
    'team_b_foul' => (int)$match['team_b_foul'],
    'team_b_timeout'=> (int)$match['team_b_timeout'],
    'team_b_quarter'=> (int)$match['team_b_quarter'],
    'match_result'=> $match['match_result'],
    'mvp' => $mvp,
    'players_a' => $playersA,
    'players_b' => $playersB,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Match Report #<?= $matchId ?> — Basketball Iskorsit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Barlow+Condensed:wght@400;500;600&display=swap">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #111;
    --surface: #181818;
    --surface2: #1a1a1a;
    --surface3: #141414;
    --border: #2a2a2a;
    --text: #f0f0f0;
    --text-muted: #888;
    --yellow: #F5C518;
    --green: #27ae60;
    --blue: #4a7cc7;
    --red: #c0392b;
  }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 14px;
    min-height: 100vh;
    padding-bottom: 60px;
  }

  /* ── EXPORT TOOLBAR ── */
  .export-bar {
    background: #0d0d0d;
    border-bottom: 1px solid var(--border);
    padding: 10px 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: flex-end;
    position: sticky;
    top: 0;
    z-index: 50;
  }
  .export-bar span {
    font-family: 'Oswald', sans-serif;
    font-size: 10px;
    letter-spacing: 1.5px;
    color: var(--text-muted);
    text-transform: uppercase;
    margin-right: auto;
  }
  .btn-export {
    border: none;
    cursor: pointer;
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 1px;
    padding: 8px 18px;
    border-radius: 5px;
    text-transform: uppercase;
    transition: filter 0.15s, transform 0.1s;
  }
  .btn-export:hover  { filter: brightness(1.15); }
  .btn-export:active { transform: scale(0.96); }
  .btn-excel { background: #1e6c35; color: #fff; }
  .btn-print { background: var(--yellow); color: #111; }

  /* ── PAGE CONTAINER ── */
  .report-page {
    max-width: 960px;
    margin: 0 auto;
    padding: 32px 24px;
  }

  /* ── HEADER ── */
  .report-header {
    text-align: center;
    padding-bottom: 24px;
    border-bottom: 2px solid var(--yellow);
    margin-bottom: 24px;
  }
  .report-header .app-name {
    font-family: 'Oswald', sans-serif;
    font-size: 32px;
    font-weight: 700;
    letter-spacing: 4px;
    color: var(--yellow);
    text-transform: uppercase;
    line-height: 1;
  }
  .report-header .report-title {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    letter-spacing: 3px;
    color: var(--text-muted);
    text-transform: uppercase;
    margin-top: 6px;
  }
  .report-header .match-id-badge {
    display: inline-block;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 4px;
    font-family: 'Oswald', sans-serif;
    font-size: 11px;
    letter-spacing: 1.5px;
    color: var(--text-muted);
    padding: 3px 10px;
    margin-top: 8px;
    text-transform: uppercase;
  }

  /* ── MATCH INFO GRID ── */
  .match-info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 24px;
  }
  .info-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px 16px;
  }
  .info-card .info-label {
    font-family: 'Oswald', sans-serif;
    font-size: 9px;
    letter-spacing: 2px;
    color: var(--text-muted);
    text-transform: uppercase;
    margin-bottom: 4px;
  }
  .info-card .info-value {
    font-family: 'Oswald', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    line-height: 1.2;
  }
  .info-card .info-value.result-win  { color: var(--green); }
  .info-card .info-value.result-draw { color: var(--yellow); }

  /* ── SCORE BANNER ── */
  .score-banner {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    padding: 20px 24px;
    margin-bottom: 24px;
    gap: 12px;
  }
  .score-team {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }
  .score-team.team-a .team-name { color: var(--green); }
  .score-team.team-b .team-name { color: var(--blue); }
  .score-team .team-name {
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
  }
  .score-team .score-big {
    font-family: 'Oswald', sans-serif;
    font-size: 72px;
    font-weight: 700;
    color: var(--yellow);
    line-height: 1;
    letter-spacing: -2px;
  }
  .score-team .team-stats-mini {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 11px;
    color: var(--text-muted);
    letter-spacing: 0.5px;
  }
  .score-vs {
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    font-weight: 700;
    color: var(--border);
    text-align: center;
  }
  .score-result-label {
    font-family: 'Oswald', sans-serif;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    text-align: center;
    padding: 6px 14px;
    border-radius: 4px;
    margin-top: 8px;
  }
  .score-result-label.win-a  { background: rgba(39,174,96,0.15); color: var(--green); }
  .score-result-label.win-b  { background: rgba(74,124,199,0.15); color: var(--blue); }
  .score-result-label.draw   { background: rgba(245,197,24,0.12); color: var(--yellow); }

  /* ── SECTION TITLE ── */
  .section-title {
    font-family: 'Oswald', sans-serif;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 3px;
    color: var(--text-muted);
    text-transform: uppercase;
    margin-bottom: 12px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--border);
  }

  /* ── MVP CARD ── */
  .mvp-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-left: 4px solid var(--yellow);
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 20px;
    align-items: center;
  }
  .mvp-badge-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
  }
  .mvp-crown {
    font-size: 32px;
    line-height: 1;
  }
  .mvp-label {
    font-family: 'Oswald', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 2px;
    color: var(--yellow);
    text-transform: uppercase;
    white-space: nowrap;
  }
  .mvp-jersey {
    font-family: 'Oswald', sans-serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--text-muted);
    background: var(--surface2);
    border-radius: 4px;
    padding: 4px 10px;
    line-height: 1;
  }
  .mvp-info-col { min-width: 0; }
  .mvp-name {
    font-family: 'Oswald', sans-serif;
    font-size: 24px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: 1px;
    text-transform: uppercase;
    line-height: 1;
    margin-bottom: 2px;
  }
  .mvp-team {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 10px;
  }
  .mvp-stats-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .mvp-stat {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 4px 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 44px;
  }
  .mvp-stat .s-val {
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 700;
    color: var(--yellow);
    line-height: 1;
  }
  .mvp-stat .s-lbl {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 9px;
    color: var(--text-muted);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-top: 1px;
  }
  .mvp-score-pill {
    margin-left: auto;
    background: rgba(245,197,24,0.12);
    border: 1px solid rgba(245,197,24,0.3);
    border-radius: 6px;
    padding: 6px 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
  }
  .mvp-score-pill .ms-val {
    font-family: 'Oswald', sans-serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--yellow);
    line-height: 1;
  }
  .mvp-score-pill .ms-lbl {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 9px;
    color: var(--text-muted);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-top: 2px;
  }
  .mvp-rubric {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    color: var(--text-muted);
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 12px;
    margin-bottom: 14px;
  }
  .mvp-rubric b { color: var(--text); font-family: 'Oswald', sans-serif; }

  /* ── ROSTER TABLES ── */
  .team-section { margin-bottom: 28px; }
  .team-section-header {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    padding: 8px 12px;
    border-radius: 6px 6px 0 0;
    margin-bottom: 0;
  }
  .team-section-header.hdr-a { background: rgba(39,174,96,0.15); color: var(--green); border: 1px solid rgba(39,174,96,0.25); border-bottom: none; }
  .team-section-header.hdr-b { background: rgba(74,124,199,0.15); color: var(--blue);  border: 1px solid rgba(74,124,199,0.25); border-bottom: none; }

  .roster-report-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid var(--border);
    border-radius: 0 0 6px 6px;
    overflow: hidden;
  }
  .roster-report-table thead th {
    background: #0d0d0d;
    color: var(--text-muted);
    font-family: 'Oswald', sans-serif;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 8px 10px;
    text-align: center;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }
  .roster-report-table thead th.th-pts { color: var(--yellow); }
  .roster-report-table thead th:first-child { text-align: left; padding-left: 14px; }
  .roster-report-table tbody tr:nth-child(odd)  { background: var(--surface2); }
  .roster-report-table tbody tr:nth-child(even) { background: var(--surface3); }
  .roster-report-table tbody tr.mvp-row { background: rgba(245,197,24,0.07); }
  .roster-report-table tbody td {
    padding: 7px 10px;
    text-align: center;
    border-bottom: 1px solid var(--border);
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 13px;
    color: var(--text);
  }
  .roster-report-table tbody td:first-child { text-align: left; padding-left: 14px; }
  .roster-report-table tbody td.td-pts { color: var(--yellow); font-family: 'Oswald', sans-serif; font-weight: 700; font-size: 14px; }
  .roster-report-table tfoot td {
    padding: 7px 10px;
    text-align: center;
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 700;
    color: var(--text);
    background: #0d0d0d;
    border-top: 2px solid var(--border);
  }
  .roster-report-table tfoot td:first-child { text-align: left; padding-left: 14px; color: var(--text-muted); letter-spacing: 1px; }
  .roster-report-table tfoot td.td-pts { color: var(--yellow); }
  .mvp-star-cell { color: var(--yellow); }

  /* ── FOOTER ── */
  .report-footer {
    text-align: center;
    padding-top: 24px;
    border-top: 1px solid var(--border);
    margin-top: 8px;
  }
  .report-footer p {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 11px;
    color: var(--text-muted);
    letter-spacing: 1px;
  }

  /* ── PRINT ── */
  @media print {
    body { background: #fff !important; color: #000 !important; }
    .export-bar { display: none !important; }
    :root {
      --bg: #fff;
      --surface: #f8f8f8;
      --surface2: #f0f0f0;
      --surface3: #f4f4f4;
      --border: #ccc;
      --text: #000;
      --text-muted: #555;
      --yellow: #b8940a;
      --green: #1a6b3a;
      --blue: #1a4080;
    }
    .report-header .app-name { color: #b8940a; }
    .score-team .score-big { color: #b8940a; }
    .roster-report-table thead th.th-pts { color: #b8940a; }
    .roster-report-table tbody td.td-pts { color: #b8940a; }
  }
</style>
</head>
<body>

<!-- EXPORT TOOLBAR -->
<div class="export-bar no-print">
  <span>Match Report #<?= $matchId ?></span>
  <button class="btn-export" onclick="if(confirm('Warning: data can be lost. Do you want to go back to the admin page?')) { window.location = 'index.php'; }">&#11013; Back</button>
  <button class="btn-export btn-excel" onclick="exportExcel()">&#11015; Export Excel</button>
  <button class="btn-export btn-print" onclick="window.print()">&#128438; Print PDF</button>
</div>

<div class="report-page">

  <!-- HEADER -->
  <div class="report-header">
    <div class="app-name">Basketball Iskorsit</div>
    <div class="report-title">Official Match Report</div>
    <div class="match-id-badge">Match ID: #<?= $matchId ?></div>
  </div>

  <!-- MATCH INFO -->
  <div class="match-info-grid">
    <div class="info-card">
      <div class="info-label">Date &amp; Time</div>
      <div class="info-value"><?= $savedAt ?></div>
    </div>
    <div class="info-card">
      <div class="info-label">Committee / Official</div>
      <div class="info-value"><?= $committee ?: '<span style="color:#555;font-size:13px">—</span>' ?></div>
    </div>
    <div class="info-card">
      <div class="info-label">Match Result</div>
      <div class="info-value <?= $match['match_result'] === 'DRAW' ? 'result-draw' : 'result-win' ?>"><?= h($resultLabel) ?></div>
    </div>
  </div>

  <!-- SCORE BANNER -->
  <div class="score-banner">
    <div class="score-team team-a">
      <div class="team-name"><?= $teamAName ?></div>
      <div class="score-big"><?= $scoreA ?></div>
      <div class="team-stats-mini">Foul: <?= (int)$match['team_a_foul'] ?> &nbsp;|&nbsp; Timeout: <?= (int)$match['team_a_timeout'] ?> &nbsp;|&nbsp; Q<?= (int)$match['team_a_quarter'] ?></div>
      <?php if ($match['match_result'] === 'TEAM A WINS'): ?>
        <div class="score-result-label win-a">&#127942; Winner</div>
      <?php elseif ($match['match_result'] === 'DRAW'): ?>
        <div class="score-result-label draw">Draw</div>
      <?php endif; ?>
    </div>
    <div class="score-vs">VS</div>
    <div class="score-team team-b">
      <div class="team-name"><?= $teamBName ?></div>
      <div class="score-big"><?= $scoreB ?></div>
      <div class="team-stats-mini">Foul: <?= (int)$match['team_b_foul'] ?> &nbsp;|&nbsp; Timeout: <?= (int)$match['team_b_timeout'] ?> &nbsp;|&nbsp; Q<?= (int)$match['team_b_quarter'] ?></div>
      <?php if ($match['match_result'] === 'TEAM B WINS'): ?>
        <div class="score-result-label win-b">&#127942; Winner</div>
      <?php elseif ($match['match_result'] === 'DRAW'): ?>
        <div class="score-result-label draw">Draw</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- MVP -->
  <?php if ($mvp): ?>
  <div class="section-title">MVP Rubric</div>
  <div class="mvp-rubric">
    <div><b>Simple formula:</b></div>
    <div style="margin-top:6px">Add positive contributions and subtract penalties:</div>
    <ul style="margin:8px 0 0 18px;color:var(--text-muted)">
      <li><b>PTS</b> — worth +2 each</li>
      <li><b>REB</b> — worth +1.2 each</li>
      <li><b>AST</b> — worth +1.5 each</li>
      <li><b>BLK</b> and <b>STL</b> — worth +1 each</li>
      <li><b>FOUL</b> — penalty −0.5 each; <b>Tech Foul</b> −1 each</li>
    </ul>
    <div style="margin-top:8px;color:var(--text-muted)">Interpretation: The formula weights scoring highest, with playmaking (assists) and rebounds also influential; fouls reduce the score. The MVP is the player with the highest resulting score.</div>
    <div style="margin-top:8px;color:var(--text-muted);font-size:12px">Reference: Inspired by common box-score weighting and player evaluation approaches (see Player Efficiency concepts — https://www.basketball-reference.com/about/per.html).</div>
  </div>
  <div class="section-title">Most Valuable Player (MVP)</div>
  <div class="mvp-card">
    <div class="mvp-badge-col">
      <div class="mvp-crown">&#127942;</div>
      <div class="mvp-label">MVP</div>
      <div class="mvp-jersey">#<?= h($mvp['jersey_no'] ?: '—') ?></div>
    </div>
    <div class="mvp-info-col">
      <div class="mvp-name"><?= h($mvp['player_name'] ?: 'Unknown Player') ?></div>
      <div class="mvp-team"><?= teamLabel($mvp['team'], $match) ?> &nbsp;·&nbsp; Team <?= h($mvp['team']) ?></div>
      <div style="display:flex;align-items:flex-start;gap:12px;">
        <div class="mvp-stats-row">
          <div class="mvp-stat"><span class="s-val"><?= (int)$mvp['pts'] ?></span><span class="s-lbl">PTS</span></div>
          <div class="mvp-stat"><span class="s-val"><?= (int)$mvp['reb'] ?></span><span class="s-lbl">REB</span></div>
          <div class="mvp-stat"><span class="s-val"><?= (int)$mvp['ast'] ?></span><span class="s-lbl">AST</span></div>
          <div class="mvp-stat"><span class="s-val"><?= (int)$mvp['blk'] ?></span><span class="s-lbl">BLK</span></div>
          <div class="mvp-stat"><span class="s-val"><?= (int)$mvp['stl'] ?></span><span class="s-lbl">STL</span></div>
          <div class="mvp-stat"><span class="s-val"><?= (int)$mvp['foul'] ?></span><span class="s-lbl">FOUL</span></div>
        </div>
        <div class="mvp-score-pill">
          <span class="ms-val"><?= $mvp['mvp_score'] ?></span>
          <span class="ms-lbl">MVP Score</span>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- TEAM A ROSTER -->
  <div class="team-section">
    <div class="team-section-header hdr-a"><?= $teamAName ?> — Players</div>
    <table class="roster-report-table">
      <thead><tr>
        <th style="min-width:130px;text-align:left">Player</th>
        <th style="width:44px">No.</th>
        <th class="th-pts">PTS</th>
        <th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>Technical Foul</th>
      </tr></thead>
      <tbody>
      <?php foreach ($playersA as $p): $isMvp = ($mvp && $p['player_id'] == $mvp['player_id']); ?>
            <tr<?= $isMvp ? ' class="mvp-row"' : '' ?>>
              <td style="text-align:left;padding-left:14px">
                <?= ($isMvp ? '<span class="mvp-star-cell">★</span> ' : '') . h($p['player_name'] ?: '—') ?>
                <?php if (!empty($p['tech_reason'])): ?>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Technical Foul — <?= h($p['tech_reason']) ?></div>
                <?php elseif ((int)$p['tech_foul'] > 0): ?>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Technical Foul</div>
                <?php endif; ?>
              </td>
          <td><?= h($p['jersey_no'] ?: '—') ?></td>
          <td class="td-pts"><?= (int)$p['pts'] ?></td>
          <td><?= (int)$p['foul'] ?></td>
          <td><?= (int)$p['reb'] ?></td>
          <td><?= (int)$p['ast'] ?></td>
          <td><?= (int)$p['blk'] ?></td>
          <td><?= (int)$p['stl'] ?></td>
          <td style="max-width:220px;text-align:left">
            <?php if (!empty($p['tech_reason'])): ?>
              <?= h($p['tech_reason']) ?>
            <?php elseif ((int)$p['tech_foul'] > 0): ?>
              <?= 'TF' ?>
            <?php else: ?>
              &nbsp;
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($playersA)): ?>
        <tr><td colspan="9" style="text-align:center;color:#555;padding:16px;">No players recorded</td></tr>
      <?php endif; ?>
      </tbody>
      <?php if (!empty($playersA)): ?>
      <tfoot><tr>
        <td>TOTALS</td><td>—</td>
        <td class="td-pts"><?= $totalsA['pts'] ?></td>
        <td><?= $totalsA['foul'] ?></td>
        <td><?= $totalsA['reb'] ?></td>
        <td><?= $totalsA['ast'] ?></td>
        <td><?= $totalsA['blk'] ?></td>
        <td><?= $totalsA['stl'] ?></td>
        <td><?= $totalsA['tech_foul'] ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
    <div style="font-size:12px;color:var(--text-muted);margin-top:8px">Technical Foul (TF): shows label or reason text per player; totals still show counts.</div>
  </div>

  <!-- TEAM B ROSTER -->
  <div class="team-section">
    <div class="team-section-header hdr-b"><?= $teamBName ?> — Players</div>
    <table class="roster-report-table">
      <thead><tr>
        <th style="min-width:130px;text-align:left">Player</th>
        <th style="width:44px">No.</th>
        <th class="th-pts">PTS</th>
        <th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>Technical Foul</th>
      </tr></thead>
      <tbody>
      <?php foreach ($playersB as $p): $isMvp = ($mvp && $p['player_id'] == $mvp['player_id']); ?>
            <tr<?= $isMvp ? ' class="mvp-row"' : '' ?>>
              <td style="text-align:left;padding-left:14px">
                <?= ($isMvp ? '<span class="mvp-star-cell">★</span> ' : '') . h($p['player_name'] ?: '—') ?>
                <?php if (!empty($p['tech_reason'])): ?>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Technical Foul — <?= h($p['tech_reason']) ?></div>
                <?php elseif ((int)$p['tech_foul'] > 0): ?>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Technical Foul</div>
                <?php endif; ?>
              </td>
              <td><?= h($p['jersey_no'] ?: '—') ?></td>
              <td class="td-pts"><?= (int)$p['pts'] ?></td>
              <td><?= (int)$p['foul'] ?></td>
              <td><?= (int)$p['reb'] ?></td>
              <td><?= (int)$p['ast'] ?></td>
              <td><?= (int)$p['blk'] ?></td>
              <td><?= (int)$p['stl'] ?></td>
              <td style="max-width:220px;text-align:left">
                <?php if (!empty($p['tech_reason'])): ?>
                  <?= h($p['tech_reason']) ?>
                <?php elseif ((int)$p['tech_foul'] > 0): ?>
                  <?= 'TF' ?>
                <?php else: ?>
                  &nbsp;
                <?php endif; ?>
              </td>
            </tr>
      <?php endforeach; ?>
      <?php if (empty($playersB)): ?>
        <tr><td colspan="9" style="text-align:center;color:#555;padding:16px;">No players recorded</td></tr>
      <?php endif; ?>
      </tbody>
      <?php if (!empty($playersB)): ?>
      <tfoot><tr>
        <td>TOTALS</td><td>—</td>
        <td class="td-pts"><?= $totalsB['pts'] ?></td>
        <td><?= $totalsB['foul'] ?></td>
        <td><?= $totalsB['reb'] ?></td>
        <td><?= $totalsB['ast'] ?></td>
        <td><?= $totalsB['blk'] ?></td>
        <td><?= $totalsB['stl'] ?></td>
        <td><?= $totalsB['tech_foul'] ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
    <div style="font-size:12px;color:var(--text-muted);margin-top:8px">Technical Foul (TF): shows label or reason text per player; totals still show counts.</div>
  </div>

  <!-- FOOTER -->
  <div class="report-footer">
    <p>Generated by Basketball Iskorsit &nbsp;·&nbsp; <?= date('F j, Y  g:i A') ?></p>
  </div>

</div><!-- /report-page -->

<!-- SheetJS CDN -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>
<script>
const MATCH_DATA = <?= $jsonMatch ?>;

function exportExcel() {
  const wb = XLSX.utils.book_new();

  // ── helper: style a cell with black borders ──
  function cs(bold, bg, color, border) {
    const b = { style: 'thin', color: { rgb: '000000' } };
    const useBorder = (typeof border === 'undefined') ? true : !!border;
    return {
      font:  { bold: !!bold, color: { rgb: color || '000000' }, name: 'Calibri', sz: 11 },
      fill:  { fgColor: { rgb: bg || 'FFFFFF' }, patternType: 'solid' },
      alignment: { horizontal: 'center', vertical: 'center', wrapText: false },
      border: useBorder ? { top: b, bottom: b, left: b, right: b } : undefined
    };
  }

  // Build a single-sheet report combining summary + Team A + Team B
  const rows = [];
  rows.push(['BASKETBALL ISKORSIT — MATCH REPORT']);
  rows.push([]);
  rows.push(['Match ID', MATCH_DATA.match_id, '', '']);
  rows.push(['Date / Time', MATCH_DATA.saved_at, '', '']);
  rows.push(['Committee/Official', MATCH_DATA.committee || '—', '', '']);
  rows.push(['', '', '', '']);
  rows.push(['Match Result', MATCH_DATA.match_result, 'Team A', MATCH_DATA.team_a_score]);
  rows.push(['', '', 'Team B', MATCH_DATA.team_b_score]);
  rows.push([]);

  // Team A block
  rows.push([MATCH_DATA.team_a_name + ' — Players']);
  rows.push(['No.', 'Player Name', 'PTS', 'FOUL', 'REB', 'AST', 'BLK', 'STL', 'Technical Foul']);
  MATCH_DATA.players_a.forEach(p => {
    const playerName = (MATCH_DATA.mvp && MATCH_DATA.mvp.player_id == p.player_id) ? '★ ' + (p.player_name || '—') : (p.player_name || '—');
    const techText = p.tech_reason && String(p.tech_reason).trim().length > 0 ? p.tech_reason : ((p.tech_foul || 0) > 0 ? 'TF' : '');
    rows.push([p.jersey_no || '—', playerName, p.pts || 0, p.foul || 0, p.reb || 0, p.ast || 0, p.blk || 0, p.stl || 0, techText]);
  });
  rows.push(['TOTALS', '', MATCH_DATA.players_a.reduce((s,p)=>s+(p.pts||0),0), MATCH_DATA.players_a.reduce((s,p)=>s+(p.foul||0),0), MATCH_DATA.players_a.reduce((s,p)=>s+(p.reb||0),0), MATCH_DATA.players_a.reduce((s,p)=>s+(p.ast||0),0), MATCH_DATA.players_a.reduce((s,p)=>s+(p.blk||0),0), MATCH_DATA.players_a.reduce((s,p)=>s+(p.stl||0),0), MATCH_DATA.players_a.reduce((s,p)=>s+(p.tech_foul||0),0)]);
  rows.push([]);

  // Team B block
  rows.push([MATCH_DATA.team_b_name + ' — Players']);
  rows.push(['No.', 'Player Name', 'PTS', 'FOUL', 'REB', 'AST', 'BLK', 'STL', 'Technical Foul']);
  MATCH_DATA.players_b.forEach(p => {
    const playerName = (MATCH_DATA.mvp && MATCH_DATA.mvp.player_id == p.player_id) ? '★ ' + (p.player_name || '—') : (p.player_name || '—');
    const techText = p.tech_reason && String(p.tech_reason).trim().length > 0 ? p.tech_reason : ((p.tech_foul || 0) > 0 ? 'TF' : '');
    rows.push([p.jersey_no || '—', playerName, p.pts || 0, p.foul || 0, p.reb || 0, p.ast || 0, p.blk || 0, p.stl || 0, techText]);
  });
  rows.push(['TOTALS', '', MATCH_DATA.players_b.reduce((s,p)=>s+(p.pts||0),0), MATCH_DATA.players_b.reduce((s,p)=>s+(p.foul||0),0), MATCH_DATA.players_b.reduce((s,p)=>s+(p.reb||0),0), MATCH_DATA.players_b.reduce((s,p)=>s+(p.ast||0),0), MATCH_DATA.players_b.reduce((s,p)=>s+(p.blk||0),0), MATCH_DATA.players_b.reduce((s,p)=>s+(p.stl||0),0), MATCH_DATA.players_b.reduce((s,p)=>s+(p.tech_foul||0),0)]);

  const ws = XLSX.utils.aoa_to_sheet(rows);
  // columns widths
  ws['!cols'] = [{wch:8},{wch:30},{wch:7},{wch:7},{wch:7},{wch:7},{wch:7},{wch:7},{wch:24}];

  // merges: title row A1:I1
  ws['!merges'] = ws['!merges'] || [];
  ws['!merges'].push({s:{r:0,c:0}, e:{r:0,c:8}});

  // style title
  if (ws['A1']) ws['A1'].s = cs(true, '000000', 'F5C518', true);

  // style summary rows (rows 3..8 zero-based)
  for (let r = 2; r <= 7; r++) {
    for (let c = 0; c <= 3; c++) {
      const ref = String.fromCharCode(65+c) + (r+1);
      if (ws[ref]) ws[ref].s = cs(false, 'FFFFFF', '000000', true);
    }
  }

  // find header rows (Team A header is row index where value equals team name)
  function styleTableFrom(startRow) {
    // header is startRow (0-based) +1 (next)
    const hdrIdx = startRow + 1;
    const hdrLetters = ['A','B','C','D','E','F','G','H','I'];
    hdrLetters.forEach((col,i)=>{
      const ref = col + (hdrIdx+1);
      if (ws[ref]) ws[ref].s = cs(true, '333333', 'FFFFFF', true);
    });
    // data rows until an empty row or a cell with 'TOTALS'
    let r = hdrIdx + 1;
    while (true) {
      const checkRef = 'A' + (r+1);
      if (!ws[checkRef]) break;
      const v = ws[checkRef].v;
      if (v === 'TOTALS') break;
      const bg = (r - (hdrIdx+1)) % 2 === 0 ? 'FFFFFF' : 'F7F7F7';
      hdrLetters.forEach(col=>{
        const ref = col + (r+1);
        if (ws[ref]) ws[ref].s = cs(false, bg, '000000', true);
      });
      // check MVP star in column B
      const nameRef = 'B' + (r+1);
      if (ws[nameRef] && String(ws[nameRef].v || '').startsWith('★')) {
        hdrLetters.forEach(col=>{ const ref = col + (r+1); if (ws[ref]) ws[ref].s = cs(true, 'FFF3D6', '000000', true); });
      }
      r++;
    }
    // style totals row
    const totalsRefRow = r+1;
    hdrLetters.forEach(col=>{ const ref = col + totalsRefRow; if (ws[ref]) ws[ref].s = cs(true, '000000', 'FFFFFF', true); });
  }

  // locate team A header row index
  let teamARow = rows.findIndex(r=>r[0] === MATCH_DATA.team_a_name + ' — Players');
  let teamBRow = rows.findIndex(r=>r[0] === MATCH_DATA.team_b_name + ' — Players');
  if (teamARow >= 0) styleTableFrom(teamARow);
  if (teamBRow >= 0) styleTableFrom(teamBRow);

  XLSX.utils.book_append_sheet(wb, ws, 'Match Report');
  XLSX.writeFile(wb, 'match_report_' + MATCH_DATA.match_id + '.xlsx');
}
</script>
</body>
</html>