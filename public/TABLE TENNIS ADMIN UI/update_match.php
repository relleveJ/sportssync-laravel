<?php
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  mysqli_report(MYSQLI_REPORT_OFF);
  $mysqli = @new mysqli('localhost', 'root', '', 'sportssync');
  if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database connection failed']);
    exit;
  }
  $mysqli->set_charset('utf8mb4');
}

function tt_json(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function tt_text($value, int $max, string $fallback = ''): string {
  $value = trim((string)$value);
  if ($value === '') $value = $fallback;
  return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function tt_body(): array {
  $data = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($data)) tt_json(['success'=>false,'message'=>'Invalid JSON body'], 400);
  return $data;
}

function tt_stmt(mysqli $db, string $sql): mysqli_stmt {
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException($db->error ?: 'Database prepare failed');
  return $stmt;
}

function tt_has_col(mysqli $db, string $table, string $col): bool {
  $allowed = ['table_tennis_matches', 'table_tennis_sets', 'table_tennis_match_summary'];
  if (!in_array($table, $allowed, true)) return false;
  $stmt = tt_stmt($db, "SHOW COLUMNS FROM `{$table}` LIKE ?");
  $stmt->bind_param('s', $col);
  $stmt->execute();
  $ok = $stmt->get_result()->num_rows > 0;
  $stmt->close();
  return $ok;
}

function tt_type($value): string {
  $value = strtolower(trim((string)$value));
  if (strpos($value, 'mixed') !== false) return 'Mixed Doubles';
  if (strpos($value, 'double') !== false) return 'Doubles';
  return 'Singles';
}

function tt_set_winner($value, int $teamA, int $teamB): ?string {
  if (in_array($value, ['A', 'B'], true)) return $value;
  if ($teamA === $teamB) return null;
  return $teamA > $teamB ? 'A' : 'B';
}

try { $user = requireLogin(); } catch (Throwable $_) { $user = null; }
if (!$user || !in_array($user['role'] ?? '', ['admin','scorekeeper','superadmin'], true)) {
  tt_json(['success'=>false,'message'=>'Authentication required'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = (int)($_GET['match_id'] ?? 0);
  if ($id <= 0) tt_json(['success'=>false,'message'=>'match_id required'], 400);

  try {
    $stmt = tt_stmt($mysqli, 'SELECT * FROM table_tennis_matches WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $match = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$match) tt_json(['success'=>false,'message'=>'Match not found'], 404);

    $stmt = tt_stmt($mysqli, 'SELECT set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner FROM table_tennis_sets WHERE match_id=? ORDER BY set_number ASC, id ASC');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $sets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    tt_json(['success'=>true,'match'=>$match,'sets'=>$sets]);
  } catch (Throwable $e) {
    tt_json(['success'=>false,'message'=>'Unable to load match'], 500);
  }
}

$data = tt_body();
$id = (int)($data['match_id'] ?? 0);
if ($id <= 0) tt_json(['success'=>false,'message'=>'match_id required'], 400);

$matchType = tt_type($data['match_type'] ?? 'Singles');
$bestOf = max(1, min(9, (int)($data['best_of'] ?? 3)));
$teamA = tt_text($data['team_a_name'] ?? 'Team A', 100, 'Team A');
$teamB = tt_text($data['team_b_name'] ?? 'Team B', 100, 'Team B');
$pA1 = tt_text($data['team_a_player1'] ?? '', 100);
$pA2 = tt_text($data['team_a_player2'] ?? '', 100);
$pB1 = tt_text($data['team_b_player1'] ?? '', 100);
$pB2 = tt_text($data['team_b_player2'] ?? '', 100);
$status = in_array(($data['status'] ?? ''), ['ongoing','completed','reset'], true) ? $data['status'] : 'ongoing';
$winner = tt_text($data['winner_name'] ?? '', 100);
$winner = $winner === '' ? null : $winner;
$committee = tt_text($data['committee'] ?? ($data['committee_official'] ?? ''), 255);

$mysqli->begin_transaction();
try {
  $stmt = tt_stmt($mysqli, 'SELECT id FROM table_tennis_matches WHERE id=? LIMIT 1 FOR UPDATE');
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $exists = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$exists) {
    $mysqli->rollback();
    tt_json(['success'=>false,'message'=>'Match not found'], 404);
  }

  if (tt_has_col($mysqli, 'table_tennis_matches', 'committee')) {
    $stmt = tt_stmt($mysqli, 'UPDATE table_tennis_matches SET match_type=?, best_of=?, team_a_name=?, team_b_name=?, team_a_player1=?, team_a_player2=?, team_b_player1=?, team_b_player2=?, status=?, winner_name=?, committee=? WHERE id=?');
    $stmt->bind_param('sisssssssssi', $matchType, $bestOf, $teamA, $teamB, $pA1, $pA2, $pB1, $pB2, $status, $winner, $committee, $id);
  } else {
    $stmt = tt_stmt($mysqli, 'UPDATE table_tennis_matches SET match_type=?, best_of=?, team_a_name=?, team_b_name=?, team_a_player1=?, team_a_player2=?, team_b_player1=?, team_b_player2=?, status=?, winner_name=? WHERE id=?');
    $stmt->bind_param('sissssssssi', $matchType, $bestOf, $teamA, $teamB, $pA1, $pA2, $pB1, $pB2, $status, $winner, $id);
  }
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $stmt->close();

  $aWins = 0;
  $bWins = 0;
  $played = 0;

  if (is_array($data['sets'] ?? null)) {
    $sets = [];
    foreach ($data['sets'] as $set) {
      $setNumber = max(1, min(9, (int)($set['set_number'] ?? 1)));
      $sets[$setNumber] = $set;
    }

    $hasSetCommittee = tt_has_col($mysqli, 'table_tennis_sets', 'committee');
    $sel = tt_stmt($mysqli, 'SELECT id FROM table_tennis_sets WHERE match_id=? AND set_number=? LIMIT 1');
    $ins = $hasSetCommittee
      ? tt_stmt($mysqli, 'INSERT INTO table_tennis_sets (match_id,set_number,team_a_score,team_b_score,team_a_timeout_used,team_b_timeout_used,serving_team,set_winner,committee) VALUES (?,?,?,?,?,?,?,?,?)')
      : tt_stmt($mysqli, 'INSERT INTO table_tennis_sets (match_id,set_number,team_a_score,team_b_score,team_a_timeout_used,team_b_timeout_used,serving_team,set_winner) VALUES (?,?,?,?,?,?,?,?)');
    $upd = $hasSetCommittee
      ? tt_stmt($mysqli, 'UPDATE table_tennis_sets SET team_a_score=?, team_b_score=?, team_a_timeout_used=?, team_b_timeout_used=?, serving_team=?, set_winner=?, committee=? WHERE match_id=? AND set_number=?')
      : tt_stmt($mysqli, 'UPDATE table_tennis_sets SET team_a_score=?, team_b_score=?, team_a_timeout_used=?, team_b_timeout_used=?, serving_team=?, set_winner=? WHERE match_id=? AND set_number=?');

    foreach ($sets as $sn => $set) {
      $as = max(0, (int)($set['team_a_score'] ?? 0));
      $bs = max(0, (int)($set['team_b_score'] ?? 0));
      $ato = !empty($set['team_a_timeout_used']) ? 1 : 0;
      $bto = !empty($set['team_b_timeout_used']) ? 1 : 0;
      $serve = ($set['serving_team'] ?? 'A') === 'B' ? 'B' : 'A';
      $sw = tt_set_winner($set['set_winner'] ?? '', $as, $bs);
      if ($as > 0 || $bs > 0 || $sw !== null) $played++;
      if ($sw === 'A') $aWins++;
      if ($sw === 'B') $bWins++;

      $sel->bind_param('ii', $id, $sn);
      $sel->execute();
      // Use store_result + num_rows to avoid get_result() allocation and
      // allow re-executing the prepared statement safely in a loop.
      $sel->store_result();
      if ($sel->num_rows > 0) {
        if ($hasSetCommittee) $upd->bind_param('iiiisssii', $as, $bs, $ato, $bto, $serve, $sw, $committee, $id, $sn);
        else $upd->bind_param('iiiissii', $as, $bs, $ato, $bto, $serve, $sw, $id, $sn);
        if (!$upd->execute()) throw new RuntimeException($upd->error);
      } else {
        if ($hasSetCommittee) $ins->bind_param('iiiiiisss', $id, $sn, $as, $bs, $ato, $bto, $serve, $sw, $committee);
        else $ins->bind_param('iiiiiiss', $id, $sn, $as, $bs, $ato, $bto, $serve, $sw);
        if (!$ins->execute()) throw new RuntimeException($ins->error);
      }
      $sel->free_result();
    }
    $sel->close();
    $ins->close();
    $upd->close();

    $winnerTeam = null;
    if ($winner !== null) {
      if (strcasecmp($winner, $teamA) === 0 || $aWins > $bWins) $winnerTeam = 'A';
      elseif (strcasecmp($winner, $teamB) === 0 || $bWins > $aWins) $winnerTeam = 'B';
    }
    $sum = tt_stmt($mysqli, 'INSERT INTO table_tennis_match_summary (match_id,total_sets_played,team_a_sets_won,team_b_sets_won,winner_team,winner_name) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE total_sets_played=VALUES(total_sets_played), team_a_sets_won=VALUES(team_a_sets_won), team_b_sets_won=VALUES(team_b_sets_won), winner_team=VALUES(winner_team), winner_name=VALUES(winner_name)');
    $sum->bind_param('iiiiss', $id, $played, $aWins, $bWins, $winnerTeam, $winner);
    if (!$sum->execute()) throw new RuntimeException($sum->error);
    $sum->close();
  }

  $mysqli->commit();
  tt_json(['success'=>true,'match'=>['id'=>$id,'team_a_name'=>$teamA,'team_b_name'=>$teamB,'match_type'=>$matchType,'best_of'=>$bestOf,'status'=>$status,'winner_name'=>$winner,'committee'=>$committee,'committee_official'=>$committee]]);
} catch (Throwable $e) {
  $mysqli->rollback();
  // Provide the error message to the client for debugging — prevents silent failures.
  tt_json(['success'=>false,'message'=>'Unable to update match','error'=> $e->getMessage()], 500);
}
