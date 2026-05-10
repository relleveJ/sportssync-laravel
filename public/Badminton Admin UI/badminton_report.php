<?php
// ============================================================
// badminton_report.php — Badminton Match Report
// Usage: badminton_report.php?match_id=N
// ============================================================

$require_line = true;
require_once __DIR__ . '/db_config.php';

$matchId = isset($_GET['match_id']) ? (int) $_GET['match_id'] : 0;

if ($matchId <= 0) {
  http_response_code(400);
  echo '<!DOCTYPE html><html><body style="background:#111;color:#f00;font-family:monospace;padding:40px">Invalid or missing match_id parameter.</body></html>';
  exit;
}

// ── Fetch match row ──────────────────────────────────────────
$stmt = $mysqli->prepare('SELECT * FROM badminton_matches WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $matchId);
$stmt->execute();
$match = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$match) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="background:#111;color:#f00;font-family:monospace;padding:40px">Match ID ' . htmlspecialchars((string)$matchId) . ' not found.</body></html>';
    exit;
}

// ── Fetch all sets for this match ────────────────────────────
$stmt = $mysqli->prepare('SELECT * FROM badminton_sets WHERE match_id = ? ORDER BY set_number ASC');
$stmt->bind_param('i', $matchId);
$stmt->execute();
$sets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Normalize sets: assign missing set numbers sequentially, dedupe by set_number (keep last), ensure integer fields
if (!empty($sets) && is_array($sets)) {
  // find current max positive set_number
  $maxNum = 0;
  foreach ($sets as $s) {
    $n = isset($s['set_number']) ? (int)$s['set_number'] : 0;
    if ($n > $maxNum) $maxNum = $n;
  }
  $auto = $maxNum;
  $byNum = [];
  foreach ($sets as $s) {
    $sn = isset($s['set_number']) ? (int)$s['set_number'] : 0;
    if ($sn <= 0) { $auto++; $sn = $auto; }
    // normalize numeric fields to ints and flags to 0/1
    $s['set_number'] = $sn;
    $s['team_a_score'] = isset($s['team_a_score']) ? (int)$s['team_a_score'] : 0;
    $s['team_b_score'] = isset($s['team_b_score']) ? (int)$s['team_b_score'] : 0;
    $s['team_a_timeout_used'] = !empty($s['team_a_timeout_used']) ? 1 : 0;
    $s['team_b_timeout_used'] = !empty($s['team_b_timeout_used']) ? 1 : 0;
    $s['serving_team'] = ($s['serving_team'] ?? 'A') === 'B' ? 'B' : 'A';
    $s['set_winner'] = in_array($s['set_winner'] ?? null, ['A','B']) ? $s['set_winner'] : null;
    // keep last occurrence for a given set_number (overwrites earlier duplicates)
    $byNum[$sn] = $s;
  }
  ksort($byNum, SORT_NUMERIC);
  $sets = array_values($byNum);
  $logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/badminton_debug.log') : __DIR__ . '/badminton_debug.log';
  @file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "normalized sets for match {$matchId}: " . print_r($sets, true) . "\n", FILE_APPEND);
}

// Ensure we have a full contiguous list of sets for display (1..max(best_of, observed))
// and fill missing set numbers with empty placeholders to avoid missing entries in the report.
$bestOf = isset($match['best_of']) ? (int)$match['best_of'] : 3;
$indexed = [];
if (!empty($sets) && is_array($sets)) {
  foreach ($sets as $s) {
    $indexed[(int)$s['set_number']] = $s;
  }
}
$observedMax = 0;
if (!empty($indexed)) $observedMax = max(array_keys($indexed));
$expectedMax = max(1, $bestOf, $observedMax);
$complete = [];
for ($i = 1; $i <= $expectedMax; $i++) {
  if (isset($indexed[$i])) {
    $complete[] = $indexed[$i];
  } else {
    $complete[] = [
      'set_number' => $i,
      'team_a_score' => 0,
      'team_b_score' => 0,
      'team_a_timeout_used' => 0,
      'team_b_timeout_used' => 0,
      'serving_team' => 'A',
      'set_winner' => null
    ];
  }
}
$sets = $complete;
$logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/badminton_debug.log') : __DIR__ . '/badminton_debug.log';
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "expanded sets for match {$matchId} up to best_of={$bestOf}: " . print_r($sets, true) . "\n", FILE_APPEND);

// ── Fetch match summary (if declared) ───────────────────────
$stmt = $mysqli->prepare('SELECT * FROM badminton_match_summary WHERE match_id = ? LIMIT 1');
$stmt->bind_param('i', $matchId);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Compute set wins ─────────────────────────────────────────
$teamASetWins = 0;
$teamBSetWins = 0;
foreach ($sets as $s) {
  if (($s['set_winner'] ?? null) === 'A') $teamASetWins++;
  elseif (($s['set_winner'] ?? null) === 'B') $teamBSetWins++;
}
// Prefer live set counts unless the stored summary clearly reflects the same or more recorded sets
if ($summary) {
    $summarySetsPlayed = isset($summary['total_sets_played']) ? (int)$summary['total_sets_played'] : 0;
    if ($summarySetsPlayed > 0 && $summarySetsPlayed >= $observedMax) {
        $teamASetWins = (int)$summary['team_a_sets_won'];
        $teamBSetWins = (int)$summary['team_b_sets_won'];
    }
}

// ── Determine overall winner ─────────────────────────────────
$overallWinner = '';
$matchStatus   = $match['status'] ?? 'ongoing';
if ($summary && !empty($summary['winner_name'])) {
    $overallWinner = $summary['winner_name'];
} elseif ($match['winner_name']) {
    $overallWinner = $match['winner_name'];
} elseif ($teamASetWins !== $teamBSetWins) {
    $overallWinner = $teamASetWins > $teamBSetWins ? $match['team_a_name'] : $match['team_b_name'];
}

// ── Compute additional aggregates and persist summary ─────────
$totalSetsPlayed = 0;
// Total sets played should reflect sets with recorded activity (non-zero scores or declared winner)
foreach ($sets as $s) {
  if (!empty($s) && ((int)($s['team_a_score'] ?? 0) !== 0 || (int)($s['team_b_score'] ?? 0) !== 0 || !empty($s['set_winner']))) {
    $totalSetsPlayed++;
  }
}
$teamATotalPts = $teamATotalPts ?? 0; // ensure defined
$teamBTotalPts = $teamBTotalPts ?? 0;

// Upsert into badminton_match_summary and update matches.winner_name/status
if (isset($mysqli) && $mysqli) {
  try {
    $winnerTeam = null;
    if ($overallWinner) {
      $winnerTeam = ($overallWinner === $match['team_a_name']) ? 'A' : 'B';
    }

    $up = $mysqli->prepare("INSERT INTO badminton_match_summary (match_id, total_sets_played, team_a_sets_won, team_b_sets_won, winner_team, winner_name) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_sets_played=VALUES(total_sets_played), team_a_sets_won=VALUES(team_a_sets_won), team_b_sets_won=VALUES(team_b_sets_won), winner_team=VALUES(winner_team), winner_name=VALUES(winner_name), declared_at=CURRENT_TIMESTAMP");
    if ($up) {
      $up->bind_param('iiisss', $matchId, $totalSetsPlayed, $teamASetWins, $teamBSetWins, $winnerTeam, $overallWinner);
      $up->execute();
      $up->close();
    }

    // Update matches table with winner_name and status if winner determined
    if ($overallWinner) {
      $st = $mysqli->prepare('UPDATE badminton_matches SET winner_name = ?, status = ? WHERE id = ?');
      if ($st) {
        $newStatus = 'completed';
        $st->bind_param('ssi', $overallWinner, $newStatus, $matchId);
        $st->execute();
        $st->close();
      }
    }
  } catch (Throwable $e) {
    $logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/badminton_debug.log') : __DIR__ . '/badminton_debug.log';
    @file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "summary upsert error for match {$matchId}: " . $e->getMessage() . "\n", FILE_APPEND);
  }
}

// ── Helpers ──────────────────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$exportedAt  = date('F j, Y  •  g:i A', strtotime($match['created_at']));
$committee   = h($match['committee_official'] ?? '');
$teamAName   = h($match['team_a_name']);
$teamBName   = h($match['team_b_name']);
$matchType   = h($match['match_type']);
$bestOf      = (int)$match['best_of'];

// Player roster per match type
$playersA = [];
$playersB = [];
$type = strtolower($match['match_type']);
if ($type === 'singles') {
    $playersA[] = ['no' => 1, 'role' => 'Singles', 'name' => $match['team_a_player1'] ?? ''];
    $playersB[] = ['no' => 1, 'role' => 'Singles', 'name' => $match['team_b_player1'] ?? ''];
} elseif ($type === 'doubles') {
    $playersA[] = ['no' => 1, 'role' => 'Player 1', 'name' => $match['team_a_player1'] ?? ''];
    $playersA[] = ['no' => 2, 'role' => 'Player 2', 'name' => $match['team_a_player2'] ?? ''];
    $playersB[] = ['no' => 1, 'role' => 'Player 1', 'name' => $match['team_b_player1'] ?? ''];
    $playersB[] = ['no' => 2, 'role' => 'Player 2', 'name' => $match['team_b_player2'] ?? ''];
} else { // mixed doubles
    $playersA[] = ['no' => 1, 'role' => 'Male Player',   'name' => $match['team_a_player1'] ?? ''];
    $playersA[] = ['no' => 2, 'role' => 'Female Player', 'name' => $match['team_a_player2'] ?? ''];
    $playersB[] = ['no' => 1, 'role' => 'Male Player',   'name' => $match['team_b_player1'] ?? ''];
    $playersB[] = ['no' => 2, 'role' => 'Female Player', 'name' => $match['team_b_player2'] ?? ''];
}

// Build per-team set score / timeout summary strings (mirrors JS logic)
$teamASetStr     = '';
$teamBSetStr     = '';
$teamATimeoutStr = '';
$teamBTimeoutStr = '';
$teamATotalPts   = 0;
$teamBTotalPts   = 0;
$teamATotalTO    = 0;
$teamBTotalTO    = 0;
$lastSetAScore   = 0;
$lastSetBScore   = 0;
$lastPlayedSet   = null;
foreach ($sets as $i => $s) {
  $sep = ($i < count($sets) - 1) ? ' | ' : '';
  $teamASetStr     .= 'Set' . ((int)$s['set_number']) . ': ' . ((int)$s['team_a_score']) . $sep;
  $teamBSetStr     .= 'Set' . ((int)$s['set_number']) . ': ' . ((int)$s['team_b_score']) . $sep;
  $teamATimeoutStr .= 'Set' . ((int)$s['set_number']) . ': ' . ((int)$s['team_a_timeout_used']) . $sep;
  $teamBTimeoutStr .= 'Set' . ((int)$s['set_number']) . ': ' . ((int)$s['team_b_timeout_used']) . $sep;
  $teamATotalPts   += (int)$s['team_a_score'];
  $teamBTotalPts   += (int)$s['team_b_score'];
  $teamATotalTO    += (int)$s['team_a_timeout_used'];
  $teamBTotalTO    += (int)$s['team_b_timeout_used'];
  if (((int)$s['team_a_score'] !== 0) || ((int)$s['team_b_score'] !== 0) || !empty($s['set_winner'])) {
    $lastPlayedSet = $s;
  }
}
if ($lastPlayedSet) {
  $lastSetAScore = (int)$lastPlayedSet['team_a_score'];
  $lastSetBScore = (int)$lastPlayedSet['team_b_score'];
}
// Current/last set point display (mirroring JS "score" = current set score)
$currentSetNum  = $lastPlayedSet ? (int)$lastPlayedSet['set_number'] : 1;
$servingTeam    = $lastPlayedSet ? ($lastPlayedSet['serving_team'] ?? 'A') : 'A';
$servingName    = $servingTeam === 'A' ? $match['team_a_name'] : $match['team_b_name'];

// JSON payload for Excel export (SheetJS)
$jsonMatch = json_encode([
    'match_id'      => $matchId,
    'saved_at'      => $match['created_at'],
    'committee'     => $match['committee_official'] ?? '',
    'match_type'    => $match['match_type'],
    'best_of'       => $bestOf,
    'team_a_name'   => $match['team_a_name'],
    'team_b_name'   => $match['team_b_name'],
  'team_a_sets_won' => $teamASetWins,
  'team_b_sets_won' => $teamBSetWins,
    'overall_winner'  => $overallWinner,
    'match_status'    => $matchStatus,
    'players_a'     => $playersA,
    'players_b'     => $playersB,
  'sets'          => $sets,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Badminton Report #<?= $matchId ?> — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Barlow+Condensed:wght@400;500;600&display=swap">
<style>
  /* ── BASE ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: Arial, sans-serif;
    background: #f4f4f4;
    color: #111;
    min-height: 100vh;
    padding-bottom: 60px;
  }

  /* ── EXPORT TOOLBAR ── */
  .export-bar {
    background: #062a78;
    border-bottom: 3px solid #FFE600;
    padding: 10px 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: flex-end;
    position: sticky;
    top: 0;
    z-index: 50;
  }
  .export-bar .bar-title {
    font-family: 'Oswald', sans-serif;
    font-size: 13px;
    letter-spacing: 1.5px;
    color: #FFE600;
    text-transform: uppercase;
    margin-right: auto;
    font-weight: 700;
  }
  .btn-export {
    border: none;
    cursor: pointer;
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 8px 18px;
    border-radius: 4px;
    text-transform: uppercase;
    transition: filter 0.15s, transform 0.1s;
  }
  .btn-export:hover  { filter: brightness(1.15); }
  .btn-export:active { transform: scale(0.96); }
  .btn-excel { background: #1e6c35; color: #fff; }
  .btn-print { background: #FFE600; color: #111; }

  /* ── CONTAINER ── */
  .container {
    max-width: 960px;
    margin: 0 auto;
    background: #fff;
    padding: 24px 22px;
    border-radius: 8px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.08);
    margin-top: 24px;
    margin-bottom: 24px;
  }

  /* ── PAGE HEADER ── */
  .report-header-title {
    margin: 0;
    color: #062a78;
    font-family: 'Oswald', sans-serif;
    font-size: 30px;
    letter-spacing: 2px;
    font-weight: 700;
  }
  .report-meta { margin-top: 8px; color: #333; font-size: 14px; }
  .report-meta strong { color: #111; }
  .report-divider { border: none; border-top: 2px solid #FFE600; margin: 14px 0; }

  /* ── SECTION TITLES ── */
  .section-title {
    font-family: 'Oswald', sans-serif;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #062a78;
    text-transform: uppercase;
    margin-bottom: 10px;
    padding-bottom: 5px;
    border-bottom: 1px solid #e0e0e0;
  }

  /* ── MATCH INFO CARDS ── */
  .info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 20px;
  }
  .info-card {
    background: #f8f8f8;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 12px 14px;
  }
  .info-card .ic-label {
    font-family: 'Oswald', sans-serif;
    font-size: 10px;
    letter-spacing: 2px;
    color: #888;
    text-transform: uppercase;
    margin-bottom: 4px;
  }
  .info-card .ic-value {
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 700;
    color: #111;
  }
  .ic-value.winner-val { color: #006400; }

  /* ── SCORE BANNER ── */
  .score-banner {
    background: linear-gradient(90deg, #FFE600, #FFD166);
    border-radius: 8px;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    padding: 18px 24px;
    margin-bottom: 22px;
    gap: 12px;
  }
  .sb-team {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }
  .sb-team .sb-name {
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 800;
    color: #062a78;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  .sb-team .sb-score {
    font-family: 'Oswald', sans-serif;
    font-size: 68px;
    font-weight: 900;
    color: #111;
    line-height: 1;
    letter-spacing: -2px;
  }
  .sb-team .sb-sub {
    font-size: 12px;
    color: #555;
    letter-spacing: 0.5px;
  }
  .sb-winner-badge {
    display: inline-block;
    margin-top: 6px;
    background: #e6f7e6;
    color: #006400;
    padding: 4px 12px;
    border-radius: 4px;
    font-weight: 800;
    font-size: 13px;
    letter-spacing: 1px;
  }
  .sb-vs {
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    font-weight: 700;
    color: rgba(0,0,0,0.25);
    text-align: center;
  }

  /* ── SETS TABLE ── */
  .sets-section { margin-bottom: 22px; }
  table.report-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 10px;
  }
  table.report-table thead th {
    background: #333;
    color: #fff;
    padding: 10px;
    text-align: center;
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    letter-spacing: 1px;
    text-transform: uppercase;
  }
  table.report-table thead th:first-child { text-align: left; }
  table.report-table tbody tr:nth-child(even) { background: #fafafa; }
  table.report-table tbody td {
    padding: 9px 10px;
    border: 1px solid #e6e6e6;
    font-size: 13px;
    text-align: center;
  }
  table.report-table tbody td:first-child { text-align: left; }
  table.report-table tbody td.team-a-cell { background: #fff5f5; }
  table.report-table tbody td.team-b-cell { background: #f5fbff; }
  table.report-table tbody td.winner-cell {
    background: #e6f7e6;
    color: #006400;
    font-weight: 700;
  }
  table.report-table tbody td.tbd-cell { color: #999; }

  /* ── RESULT BADGE ── */
  .result-row { margin-bottom: 14px; font-size: 14px; }
  .result-badge {
    display: inline-block;
    background: #e6f7e6;
    color: #006400;
    padding: 6px 12px;
    border-radius: 4px;
    font-weight: 800;
    margin-left: 8px;
    letter-spacing: 0.5px;
  }

  /* ── TEAM BLOCKS (yellow) ── */
  .team-block {
    background: #FFE600;
    padding: 14px 16px;
    border-radius: 6px;
    margin-bottom: 14px;
  }
  .team-block .tb-name {
    font-family: 'Oswald', sans-serif;
    font-weight: 800;
    font-size: 18px;
    margin-bottom: 8px;
    color: #062a78;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  table.team-table {
    width: 100%;
    border-collapse: collapse;
  }
  table.team-table thead th {
    background: #333;
    color: #fff;
    padding: 10px;
    font-family: 'Oswald', sans-serif;
    font-size: 11px;
    letter-spacing: 1px;
    text-transform: uppercase;
    text-align: center;
  }
  table.team-table thead th:first-child,
  table.team-table thead th:nth-child(2) { text-align: left; }
  table.team-table tbody tr:nth-child(even) { background: #f6f6f6; }
  table.team-table tbody tr:nth-child(odd)  { background: #fff; }
  table.team-table tbody td {
    padding: 9px 10px;
    border: 1px solid #e0e0e0;
    font-size: 13px;
    text-align: center;
  }
  table.team-table tbody td:first-child,
  table.team-table tbody td:nth-child(2) { text-align: left; }

  /* ── FOOTER ── */
  .report-footer {
    margin-top: 20px;
    text-align: right;
    font-size: 13px;
    color: #666;
    border-top: 1px solid #e0e0e0;
    padding-top: 12px;
  }

  /* ── PRINT ── */
  @media print {
    body { background: #fff; padding: 0; }
    .export-bar { display: none !important; }
    .container { box-shadow: none; border-radius: 0; padding: 10px; margin: 0; max-width: 100%; }
    .score-banner { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    .team-block  { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  }
</style>
</head>
<body>

<!-- EXPORT TOOLBAR -->
<div class="export-bar">
  <a class="btn-export" href="/" style="background:transparent;color:#FFE600;border:0;font-family:'Oswald',sans-serif;font-weight:700;letter-spacing:1px;text-decoration:none;margin-right:8px">&#8592; Back to Dashboard</a>
  <button class="btn-export" style="background:transparent;color:#FFE600;border:0;font-family:'Oswald',sans-serif;font-weight:700;letter-spacing:1px;cursor:pointer;margin-right:6px" onclick="window.open('badminton_matches_admin.php','_blank')">📚 Match History</button>
  <button class="btn-export" style="background:transparent;color:#FFFFFF;border:0;font-family:'Oswald',sans-serif;font-weight:700;letter-spacing:1px;cursor:pointer;margin-right:6px" onclick="window.open('badminton_matches_admin.php','_blank')">📚 Match History</button>
  <button class="btn-export" style="background:transparent;color:#FFE600;border:0;font-family:'Oswald',sans-serif;font-weight:700;letter-spacing:1px;text-decoration:none;margin-left:6px;cursor:pointer" onclick="newMatch()">➕ New Match</button>
  <span class="bar-title">&#127944; Badminton Report — Match #<?= $matchId ?></span>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
    <button class="btn-export btn-excel" onclick="exportExcel()">&#11015; Export Excel</button>
    <button class="btn-export btn-print" onclick="window.print()">&#128438; Print PDF</button>
  </div>
</div>

<div class="container">

  <!-- ── PAGE HEADER ── -->
  <h1 class="report-header-title">SPORTSSYNC — BADMINTON RESULT</h1>
  <div class="report-meta">Date: <?= $exportedAt ?></div>
  <?php if ($committee): ?>
  <div class="report-meta"><strong>Committee / Referee:</strong> <?= $committee ?></div>
  <?php endif; ?>
  <hr class="report-divider">

  <!-- ── MATCH INFO CARDS ── -->
  <div class="section-title">Match Information</div>
  <div class="info-grid">
    <div class="info-card">
      <div class="ic-label">Match Type</div>
      <div class="ic-value"><?= $matchType ?></div>
    </div>

    <script>
    function newMatch(){
      try { localStorage.removeItem('badmintonMatchState'); } catch(e){}
      try { localStorage.removeItem('badmintonAdminState'); } catch(e){}
      try { sessionStorage.removeItem('badminton_match_id'); } catch(e){}
      window.location.href = 'badminton_admin.php';
    }
    </script>
    <div class="info-card">
      <div class="ic-label">Best Of</div>
      <div class="ic-value"><?= $bestOf ?></div>
    </div>
    <div class="info-card">
      <div class="ic-label">Status</div>
      <div class="ic-value <?= $matchStatus === 'completed' ? 'winner-val' : '' ?>"><?= h(ucfirst($matchStatus)) ?></div>
    </div>
  </div>

  <!-- ── SCORE BANNER ── -->
  <div class="score-banner">
    <div class="sb-team">
      <div class="sb-name"><?= $teamAName ?></div>
      <div class="sb-score"><?= $teamASetWins ?></div>
      <div class="sb-sub">Sets Won</div>
      <div class="sb-sub">Current Points: <?= $lastSetAScore ?> &nbsp;|&nbsp; Timeouts: <?= $teamATotalTO ?></div>
      <?php if ($overallWinner === $match['team_a_name']): ?>
        <div class="sb-winner-badge">&#127942; WINNER</div>
      <?php endif; ?>
    </div>
    <div class="sb-vs">VS</div>
    <div class="sb-team">
      <div class="sb-name"><?= $teamBName ?></div>
      <div class="sb-score"><?= $teamBSetWins ?></div>
      <div class="sb-sub">Sets Won</div>
      <div class="sb-sub">Current Points: <?= $lastSetBScore ?> &nbsp;|&nbsp; Timeouts: <?= $teamBTotalTO ?></div>
      <?php if ($overallWinner === $match['team_b_name']): ?>
        <div class="sb-winner-badge">&#127942; WINNER</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── SETS SUMMARY TABLE ── -->
  <div class="sets-section">
    <div class="section-title">Sets Summary</div>
    <table class="report-table">
      <thead>
        <tr>
          <th>Set #</th>
          <th><?= $teamAName ?></th>
          <th><?= $teamBName ?></th>
          <th>Winner</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sets)): ?>
          <tr><td colspan="4" style="text-align:center;color:#999;padding:16px;">No sets recorded yet.</td></tr>
        <?php else: ?>
          <?php foreach ($sets as $s):
            $setWinnerName = '';
            if ($s['set_winner'] === 'A') $setWinnerName = $match['team_a_name'];
            elseif ($s['set_winner'] === 'B') $setWinnerName = $match['team_b_name'];
          ?>
          <tr>
            <td>Set <?= (int)$s['set_number'] ?></td>
            <td class="team-a-cell"><?= (int)$s['team_a_score'] ?></td>
            <td class="team-b-cell"><?= (int)$s['team_b_score'] ?></td>
            <td class="<?= $setWinnerName ? 'winner-cell' : 'tbd-cell' ?>">
              <?= $setWinnerName ? h($setWinnerName) : 'TBD' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Match result row -->
    <div class="result-row">
      <strong>Overall Result:</strong>
      <?php if ($overallWinner): ?>
        <span class="result-badge"><?= h($overallWinner) ?></span>
      <?php else: ?>
        <span style="margin-left:8px;color:#666">TBD</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── TEAM A BLOCK ── -->
  <div class="team-block">
    <div class="tb-name"><?= $teamAName ?></div>
    <table class="team-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Role</th>
          <th>Game Points</th>
          <th>Timeouts Used</th>
          <th>Sets Won</th>
          <th>Set Scores</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($playersA as $pl): ?>
        <tr>
          <td><?= (int)$pl['no'] ?></td>
          <td><?= h($pl['name'] ?: ('—')) ?></td>
          <td><?= h($pl['role']) ?></td>
          <td><?= $lastSetAScore ?></td>
          <td><?= $teamATimeoutStr ?: '0' ?></td>
          <td><?= $teamASetWins ?></td>
          <td><?= $teamASetStr ?: '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($playersA)): ?>
          <tr><td colspan="7" style="text-align:center;color:#555;padding:12px;">No players recorded.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ── TEAM B BLOCK ── -->
  <div class="team-block">
    <div class="tb-name"><?= $teamBName ?></div>
    <table class="team-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Role</th>
          <th>Game Points</th>
          <th>Timeouts Used</th>
          <th>Sets Won</th>
          <th>Set Scores</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($playersB as $pl): ?>
        <tr>
          <td><?= (int)$pl['no'] ?></td>
          <td><?= h($pl['name'] ?: ('—')) ?></td>
          <td><?= h($pl['role']) ?></td>
          <td><?= $lastSetBScore ?></td>
          <td><?= $teamBTimeoutStr ?: '0' ?></td>
          <td><?= $teamBSetWins ?></td>
          <td><?= $teamBSetStr ?: '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($playersB)): ?>
          <tr><td colspan="7" style="text-align:center;color:#555;padding:12px;">No players recorded.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ── FOOTER ── -->
  <div class="report-footer">
    Generated by SportSync &nbsp;·&nbsp; <?= $exportedAt ?>
  </div>

</div><!-- /container -->

<!-- SheetJS CDN (kept from report.php Excel export) -->
<script src="https://unpkg.com/xlsx-style@0.8.13/dist/xlsx.full.min.js"></script>
<script>
const MATCH_DATA = <?= $jsonMatch ?>;

window.exportExcel = function exportExcel() {
  // Create workbook safely — some xlsx-style builds lack helper functions
  let wb;
  if (XLSX && XLSX.utils && typeof XLSX.utils.book_new === 'function') {
    wb = XLSX.utils.book_new();
  } else {
    wb = { SheetNames: [], Sheets: {} };
  }

  // ── Helper: style a cell ──────────────────────────────────
  function cs(bold, bg, color, border) {
    // Normalize hex to ARGB (SheetJS/xlsx-style expects 8-char ARGB strings)
    function norm(c, fallback) {
      if (!c) return fallback || 'FFFFFFFF';
      let s = String(c).replace('#', '').toUpperCase();
      if (s.length === 6) s = 'FF' + s; // add full alpha
      if (s.length === 3) { // expand shorthand e.g. FFF
        s = s.split('').map(ch => ch + ch).join('');
        s = 'FF' + s;
      }
      return s;
    }
    const bc = norm('000000');
    const ccol = norm(color || '111111');
    const bgcol = norm(bg || 'FFFFFF');
    const b = { style: 'thin', color: { rgb: bc } };
    return {
      font:  { bold: !!bold, color: { rgb: ccol }, name: 'Calibri', sz: 11 },
      fill:  { fgColor: { rgb: bgcol }, patternType: 'solid' },
      alignment: { horizontal: 'center', vertical: 'center', wrapText: false },
      border: border ? { top: b, bottom: b, left: b, right: b } : undefined
    };
  }

  // Build a single-sheet: Summary -> Sets -> Players -> Game Result
  const rows = [];
  rows.push(['SPORTSSYNC — BADMINTON MATCH REPORT']);
  rows.push([]);
  rows.push(['Field','Value']);
  rows.push(['Match ID', MATCH_DATA.match_id]);
  rows.push(['Date / Time', MATCH_DATA.saved_at]);
  rows.push(['Committee / Referee', MATCH_DATA.committee || '—']);
  rows.push(['Match Type', MATCH_DATA.match_type]);
  rows.push(['Best Of', MATCH_DATA.best_of]);
  rows.push(['Status', MATCH_DATA.match_status]);
  rows.push(['Team A', MATCH_DATA.team_a_name]);
  rows.push(['Team A Sets Won', MATCH_DATA.team_a_sets_won]);
  rows.push(['Team B', MATCH_DATA.team_b_name]);
  rows.push(['Team B Sets Won', MATCH_DATA.team_b_sets_won]);
  rows.push(['Overall Winner', MATCH_DATA.overall_winner || 'TBD']);
  rows.push([]);

  // Sets
  rows.push(['Sets Breakdown']);
  rows.push(['Set #', MATCH_DATA.team_a_name + ' Score', MATCH_DATA.team_b_name + ' Score', 'Team A Timeout', 'Team B Timeout', 'Serving', 'Winner']);
  (MATCH_DATA.sets || []).forEach(s => rows.push(['Set ' + s.set_number, s.team_a_score, s.team_b_score, s.team_a_timeout_used ? 'Yes' : 'No', s.team_b_timeout_used ? 'Yes' : 'No', s.serving_team === 'A' ? MATCH_DATA.team_a_name : MATCH_DATA.team_b_name, s.set_winner === 'A' ? MATCH_DATA.team_a_name : (s.set_winner === 'B' ? MATCH_DATA.team_b_name : 'TBD')]));
  rows.push([]);

  // Players
  rows.push(['Players']);
  rows.push(['Team','#','Name','Role','Game Points','Timeouts Used','Sets Won','Set Scores']);
  const lastSet = MATCH_DATA.sets && MATCH_DATA.sets.length ? MATCH_DATA.sets[MATCH_DATA.sets.length-1] : null;
  const lastPtsA = lastSet ? lastSet.team_a_score : 0;
  const lastPtsB = lastSet ? lastSet.team_b_score : 0;
  const setStrA = (MATCH_DATA.sets || []).map(s => 'Set' + s.set_number + ': ' + s.team_a_score).join(' | ');
  const setStrB = (MATCH_DATA.sets || []).map(s => 'Set' + s.set_number + ': ' + s.team_b_score).join(' | ');
  (MATCH_DATA.players_a || []).forEach((p,i) => rows.push([MATCH_DATA.team_a_name || 'Team A', p.no || i+1, p.name || '—', p.role || '', lastPtsA, MATCH_DATA.sets && MATCH_DATA.sets.length ? ((MATCH_DATA.sets.reduce((acc,s)=>acc + (s.team_a_timeout_used?1:0),0))||0) : 0, MATCH_DATA.team_a_sets_won || 0, setStrA || '—']));
  (MATCH_DATA.players_b || []).forEach((p,i) => rows.push([MATCH_DATA.team_b_name || 'Team B', p.no || i+1, p.name || '—', p.role || '', lastPtsB, MATCH_DATA.sets && MATCH_DATA.sets.length ? ((MATCH_DATA.sets.reduce((acc,s)=>acc + (s.team_b_timeout_used?1:0),0))||0) : 0, MATCH_DATA.team_b_sets_won || 0, setStrB || '—']));
  rows.push([]);

  // Game result
  rows.push(['Game Result', MATCH_DATA.overall_winner || (MATCH_DATA.team_a_sets_won > MATCH_DATA.team_b_sets_won ? MATCH_DATA.team_a_name : (MATCH_DATA.team_b_sets_won > MATCH_DATA.team_a_sets_won ? MATCH_DATA.team_b_name : 'TBD'))]);

  // Create worksheet using SheetJS helper if available, otherwise use a compatibility builder
  function encodeCellLocal(r, c) {
    let col = '';
    let cc = c;
    while (cc >= 0) {
      col = String.fromCharCode(65 + (cc % 26)) + col;
      cc = Math.floor(cc / 26) - 1;
    }
    return col + (r + 1);
  }
  function encodeRangeLocal(range) {
    return encodeCellLocal(range.s.r, range.s.c) + ':' + encodeCellLocal(range.e.r, range.e.c);
  }
  function aoaToSheetCompat(aoa) {
    const ws = {};
    const R = aoa.length;
    let start = { r: Infinity, c: Infinity };
    let end = { r: 0, c: 0 };
    for (let r = 0; r < R; ++r) {
      const row = aoa[r] || [];
      for (let c = 0; c < row.length; ++c) {
        const v = row[c];
        if (v == null) continue;
        const cell = { v: v };
        if (typeof v === 'number') cell.t = 'n';
        else if (typeof v === 'boolean') cell.t = 'b';
        else cell.t = 's';
        const key = (XLSX && XLSX.utils && typeof XLSX.utils.encode_cell === 'function') ? XLSX.utils.encode_cell({r: r, c: c}) : encodeCellLocal(r, c);
        ws[key] = cell;
        if (r < start.r) start.r = r;
        if (c < start.c) start.c = c;
        if (r > end.r) end.r = r;
        if (c > end.c) end.c = c;
      }
    }
    if (start.r === Infinity) { start = { r:0, c:0 }; }
    ws['!ref'] = (XLSX && XLSX.utils && typeof XLSX.utils.encode_range === 'function') ? XLSX.utils.encode_range({s:start,e:end}) : encodeRangeLocal({s:start,e:end});
    return ws;
  }

  const ws = (XLSX && XLSX.utils && typeof XLSX.utils.aoa_to_sheet === 'function') ? XLSX.utils.aoa_to_sheet(rows) : aoaToSheetCompat(rows);

  // Column widths for up to 8 columns
  ws['!cols'] = [{wch:22},{wch:8},{wch:28},{wch:14},{wch:12},{wch:14},{wch:12},{wch:32}];

  // Apply styles
  const range = XLSX.utils.decode_range(ws['!ref']);
  // Column fill colors (soft blue strips)
  const colColors = ['EAF3FF','D0E8FF','FFFFFF','D0E8FF','EAF3FF','D0E8FF','FFFFFF','FFFFFF'];
  for (let R = range.s.r; R <= range.e.r; ++R) {
    for (let C = range.s.c; C <= range.e.c; ++C) {
      const ref = XLSX.utils.encode_cell({r:R,c:C});
      const cell = ws[ref];
      if (!cell) continue;
      // Title row
      if (R === 0) { cell.s = cs(true, 'FFE600', '062A78', true); continue; }
      // Field header row (blue header)
      if (R === 2 && (cell.v === 'Field' || cell.v === 'Value')) { cell.s = cs(true, '062A78', 'FFFFFF', true); continue; }
      // Sets header row: find 'Set #' text (blue header)
      if (String(cell.v).toLowerCase().indexOf('set #') !== -1) { cell.s = cs(true, '062A78', 'FFFFFF', true); continue; }
      // Players header row
      if (cell.v === 'Team' || cell.v === '#') {
        const prow = R;
        for (let cc = range.s.c; cc <= range.e.c; ++cc) {
          const pref = XLSX.utils.encode_cell({r:prow,c:cc}); if (ws[pref]) ws[pref].s = cs(true, '062A78', 'FFFFFF', true);
        }
        break;
      }
      // Default per-column color
      const bg = colColors[C - range.s.c] || 'FFFFFF';
      ws[ref].s = cs(false, bg, '111111', true);
    }
  }

  // Highlight Game Result row
  for (let R = range.s.r; R <= range.e.r; ++R) {
    const ref0 = XLSX.utils.encode_cell({r:R,c:0});
    const cell0 = ws[ref0];
    if (!cell0) continue;
    const txt = String(cell0.v || '').toLowerCase();
    if (txt.indexOf('overall winner') !== -1 || txt.indexOf('game result') !== -1) {
      const ref1 = XLSX.utils.encode_cell({r:R,c:1});
      if (ws[ref0]) ws[ref0].s = cs(true, 'E6F7E6', '006400', true);
      if (ws[ref1]) ws[ref1].s = cs(true, 'E6F7E6', '006400', true);
    }
  }

  // Append sheet in a compatibility-safe way
  if (XLSX && XLSX.utils && typeof XLSX.utils.book_append_sheet === 'function') {
    XLSX.utils.book_append_sheet(wb, ws, 'Match Report');
  } else {
    wb.SheetNames.push('Match Report');
    wb.Sheets['Match Report'] = ws;
  }

  // Write file: prefer XLSX.write (returns binary) to avoid writeFile implementations that call Node APIs
  const filename = 'badminton_report_' + MATCH_DATA.match_id + '.xlsx';
  if (typeof XLSX.write === 'function') {
    try {
      const wbout = XLSX.write(wb, { bookType: 'xlsx', type: 'binary' });
      function s2ab(s) {
        const buf = new ArrayBuffer(s.length);
        const view = new Uint8Array(buf);
        for (let i = 0; i < s.length; ++i) view[i] = s.charCodeAt(i) & 0xFF;
        return buf;
      }
      const blob = new Blob([s2ab(wbout)], { type: 'application/octet-stream' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = filename; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
      return;
    } catch (e) {
      // fall through to try writeFile
      console.warn('XLSX.write failed, falling back to writeFile:', e);
    }
  }

  // Fallback: try writeFile if available (may call Node APIs in some builds)
  if (typeof XLSX.writeFile === 'function') {
    try {
      XLSX.writeFile(wb, filename);
      return;
    } catch (e) {
      console.error('XLSX.writeFile failed:', e);
    }
  }

  alert('Excel export is not supported in this browser build.');
}
// `exportExcel` assigned to `window` above.
</script>
</body>
</html>
