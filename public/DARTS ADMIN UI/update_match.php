<?php
require_once 'db_config.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-TOKEN, X-XSRF-TOKEN');

// For development/testing, bypass auth
$user = ['id' => 1, 'username' => 'testuser', 'role' => 'admin'];

if (!isset($conn) || !($conn instanceof mysqli)) {
  mysqli_report(MYSQLI_REPORT_OFF);
  $conn = @new mysqli('localhost', 'root', '', 'sportssync');
  if ($conn->connect_errno) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database connection failed']);
    exit;
  }
  $conn->set_charset('utf8mb4');
}

function darts_json(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function darts_text($value, int $max, string $fallback = ''): string {
  $value = trim((string)$value);
  if ($value === '') $value = $fallback;
  return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function darts_body(): array {
  $data = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($data)) darts_json(['success'=>false,'message'=>'Invalid JSON body'], 400);
  return $data;
}

function darts_stmt(mysqli $db, string $sql): mysqli_stmt {
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException($db->error ?: 'Database prepare failed');
  return $stmt;
}

function darts_table_exists(mysqli $db, string $table): bool {
  $table = $db->real_escape_string($table);
  $sql = "SHOW TABLES LIKE '{$table}'";
  $result = $db->query($sql);
  if (!$result) return false;
  $ok = $result->num_rows > 0;
  $result->close();
  return $ok;
}

function darts_has_col(mysqli $db, string $table, string $col): bool {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
  $col = $db->real_escape_string($col);
  $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'";
  $result = $db->query($sql);
  if (!$result) return false;
  $ok = $result->num_rows > 0;
  $result->close();
  return $ok;
}

function darts_get_leg_id(mysqli $db, string $legsTable, int $match_id, int $leg_number): ?int {
  $stmt = darts_stmt($db, "SELECT id FROM `{$legsTable}` WHERE match_id=? AND leg_number=? LIMIT 1");
  $stmt->bind_param('ii', $match_id, $leg_number);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? intval($row['id']) : null;
}

function darts_get_latest_leg_number(mysqli $db, string $legsTable, int $match_id): int {
  $stmt = darts_stmt($db, "SELECT leg_number FROM `{$legsTable}` WHERE match_id=? ORDER BY leg_number DESC LIMIT 1");
  $stmt->bind_param('i', $match_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? intval($row['leg_number']) : 0;
}

function darts_fetch_player_throws(mysqli $db, string $throwsTable, int $leg_id, int $player_id): array {
  $stmt = darts_stmt($db, "SELECT throw_number, throw_value, score_before, score_after, is_bust FROM `{$throwsTable}` WHERE leg_id=? AND player_id=? ORDER BY throw_number ASC");
  $stmt->bind_param('ii', $leg_id, $player_id);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $rows ?: [];
}

function darts_save_player_throws(mysqli $db, string $throwsTable, int $leg_id, int $player_id, $throws): void {
  $del = darts_stmt($db, "DELETE FROM `{$throwsTable}` WHERE leg_id=? AND player_id=?");
  $del->bind_param('ii', $leg_id, $player_id);
  if (!$del->execute()) throw new RuntimeException($del->error);
  $del->close();

  if (!is_array($throws) || count($throws) === 0) {
    return;
  }

  $ins = darts_stmt($db, "INSERT INTO `{$throwsTable}` (leg_id, player_id, throw_number, throw_value, score_before, score_after, is_bust) VALUES (?,?,?,?,?,?,?)");
  foreach ($throws as $index => $throw) {
    $throwValue = intval($throw['throw_value'] ?? 0);
    $scoreBefore = intval($throw['score_before'] ?? 0);
    $scoreAfter = intval($throw['score_after'] ?? 0);
    $isBust = !empty($throw['is_bust']) ? 1 : 0;
    $throwNumber = $index + 1;
    $ins->bind_param('iiiiiii', $leg_id, $player_id, $throwNumber, $throwValue, $scoreBefore, $scoreAfter, $isBust);
    if (!$ins->execute()) throw new RuntimeException($ins->error);
  }
  $ins->close();
}

if (!$user || !in_array($user['role'] ?? '', ['admin','scorekeeper','superadmin'], true)) {
  darts_json(['success'=>false,'message'=>'Authentication required'], 403);
}

$prefix = null;
if (darts_table_exists($conn, 'darts_matches')) {
  $prefix = 'darts_';
} elseif (darts_table_exists($conn, 'matches') && darts_has_col($conn, 'matches', 'id') && darts_has_col($conn, 'matches', 'game_type')) {
  $prefix = '';
}
if ($prefix === null) {
  darts_json(['success'=>false,'message'=>'Darts tables are missing from the sportssync database'], 500);
}

$matchesTable = $prefix . 'matches';
$playersTable = $prefix . 'players';
$legsTable = $prefix . 'legs';
$throwsTable = $prefix . 'throws';
$summaryTable = $prefix . 'match_summary';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = (int)($_GET['match_id'] ?? 0);
  if ($id <= 0) darts_json(['success'=>false,'message'=>'match_id required'], 400);

  try {
    $stmt = darts_stmt($conn, "SELECT * FROM `{$matchesTable}` WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $match = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$match) darts_json(['success'=>false,'message'=>'Match not found'], 404);

    $stmt = darts_stmt($conn, "SELECT id, player_number, player_name, team_name FROM `{$playersTable}` WHERE match_id=? ORDER BY player_number ASC, id ASC");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $players = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $match['current_leg'] = 1;
    $match['live_state_data'] = null;
    if (!empty($match['live_state'])) {
      $decoded = json_decode($match['live_state'], true);
      if (is_array($decoded)) {
        $match['live_state_data'] = $decoded;
        if (!empty($decoded['currentLeg'])) {
          $match['current_leg'] = (int)$decoded['currentLeg'];
        }
      }
    }

    if ($match['current_leg'] <= 0 && darts_table_exists($conn, $legsTable)) {
      $match['current_leg'] = darts_get_latest_leg_number($conn, $legsTable, $id);
    }
    if ($match['current_leg'] <= 0) {
      $match['current_leg'] = 1;
    }

    $legs = [];
    if (darts_table_exists($conn, $legsTable) && darts_table_exists($conn, $throwsTable)) {
      $legStmt = darts_stmt($conn, "SELECT id, leg_number FROM `{$legsTable}` WHERE match_id=? ORDER BY leg_number ASC");
      $legStmt->bind_param('i', $id);
      $legStmt->execute();
      $legRows = $legStmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $legStmt->close();

      foreach ($legRows as $legRow) {
        $legId = (int)$legRow['id'];
        $legNumber = (int)$legRow['leg_number'];
        $legPlayers = [];
        foreach ($players as $player) {
          $playerId = (int)$player['id'];
          $throws = darts_fetch_player_throws($conn, $throwsTable, $legId, $playerId);
          $legPlayers[] = [
            'id' => $playerId,
            'player_number' => (int)$player['player_number'],
            'player_name' => $player['player_name'],
            'throws' => $throws
          ];
        }
        $legs[] = [
          'leg_number' => $legNumber,
          'players' => $legPlayers
        ];
      }
    }

    darts_json(['success'=>true,'match'=>$match,'players'=>$players,'legs'=>$legs]);
  } catch (Throwable $e) {
    darts_json(['success'=>false,'message'=>'Unable to load match'], 500);
  }
}

$data = darts_body();
$id = (int)($data['match_id'] ?? 0);
if ($id <= 0) darts_json(['success'=>false,'message'=>'match_id required'], 400);

$gameType = in_array((string)($data['game_type'] ?? ''), ['301','501','701'], true) ? (string)$data['game_type'] : '301';
$legsToWin = max(1, min(15, (int)($data['legs_to_win'] ?? 3)));
$mode = in_array(($data['mode'] ?? ''), ['one-sided','two-sided'], true) ? $data['mode'] : 'one-sided';
$status = in_array(($data['status'] ?? ''), ['ongoing','completed'], true) ? $data['status'] : 'ongoing';
$winner = darts_text($data['winner_name'] ?? '', 100);
$winner = $winner === '' ? null : $winner;
$postedWinnerId = (int)($data['winner_player_id'] ?? 0);

$conn->begin_transaction();
try {
  $stmt = darts_stmt($conn, "SELECT id FROM `{$matchesTable}` WHERE id=? LIMIT 1 FOR UPDATE");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $exists = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$exists) {
    $conn->rollback();
    darts_json(['success'=>false,'message'=>'Match not found'], 404);
  }

  $stmt = darts_stmt($conn, "UPDATE `{$matchesTable}` SET game_type=?, legs_to_win=?, mode=?, status=?, winner_name=? WHERE id=?");
  $stmt->bind_param('sisssi', $gameType, $legsToWin, $mode, $status, $winner, $id);
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $stmt->close();

  $upById = darts_stmt($conn, "UPDATE `{$playersTable}` SET player_name=?, team_name=? WHERE id=? AND match_id=?");
  $upByNumber = darts_stmt($conn, "UPDATE `{$playersTable}` SET player_name=?, team_name=? WHERE player_number=? AND match_id=?");
  foreach ((is_array($data['players'] ?? null) ? $data['players'] : []) as $player) {
    $pid = (int)($player['id'] ?? ($player['player_id'] ?? ($player['db_id'] ?? 0)));
    $playerNumber = (int)($player['player_number'] ?? 0);
    $name = darts_text($player['player_name'] ?? ($player['name'] ?? 'Player'), 100, 'Player');
    $team = darts_text($player['team_name'] ?? ($player['team'] ?? ''), 100);
    if ($pid > 0) {
      $upById->bind_param('ssii', $name, $team, $pid, $id);
      if (!$upById->execute()) throw new RuntimeException($upById->error);
    } elseif ($playerNumber > 0) {
      $upByNumber->bind_param('ssii', $name, $team, $playerNumber, $id);
      if (!$upByNumber->execute()) throw new RuntimeException($upByNumber->error);
    }
  }
  $upById->close();
  $upByNumber->close();

  $players = [];
  $playerNumberMap = [];
  $stmt = darts_stmt($conn, "SELECT id, player_number, player_name FROM `{$playersTable}` WHERE match_id=? ORDER BY player_number ASC, id ASC");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $players[(int)$row['id']] = $row;
    $playerNumberMap[(int)$row['player_number']] = (int)$row['id'];
  }
  $stmt->close();

  if (darts_table_exists($conn, $legsTable) && darts_table_exists($conn, $throwsTable)) {
    foreach ((is_array($data['legs'] ?? null) ? $data['legs'] : []) as $legData) {
      $legNumber = (int)($legData['leg_number'] ?? 0);
      if ($legNumber <= 0) continue;

      $legId = darts_get_leg_id($conn, $legsTable, $id, $legNumber);
      if ($legId === null) {
        $stmt = darts_stmt($conn, "INSERT INTO `{$legsTable}` (match_id, leg_number) VALUES (?,?)");
        $stmt->bind_param('ii', $id, $legNumber);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $legId = $stmt->insert_id;
        $stmt->close();
      }

      foreach ((is_array($legData['players'] ?? null) ? $legData['players'] : []) as $player) {
        if (!array_key_exists('throws', $player)) {
          continue;
        }
        $pid = (int)($player['id'] ?? 0);
        if ($pid <= 0) {
          $playerNumber = (int)($player['player_number'] ?? 0);
          $pid = $playerNumberMap[$playerNumber] ?? 0;
        }
        if ($pid <= 0) {
          continue;
        }
        darts_save_player_throws($conn, $throwsTable, $legId, $pid, $player['throws']);
      }
    }
  }

  $winnerId = null;
  if ($postedWinnerId > 0 && isset($players[$postedWinnerId])) {
    $winnerId = $postedWinnerId;
    $winner = $winner ?: $players[$winnerId]['player_name'];
  } elseif ($winner !== null) {
    foreach ($players as $pid => $player) {
      if (strcasecmp($winner, (string)$player['player_name']) === 0) {
        $winnerId = $pid;
        break;
      }
    }
  }

  if ($winner !== null) {
    $stmt = darts_stmt($conn, "UPDATE `{$matchesTable}` SET winner_name=? WHERE id=?");
    $stmt->bind_param('si', $winner, $id);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
  }

  if (darts_table_exists($conn, $summaryTable) && darts_has_col($conn, $summaryTable, 'winner_player_id')) {
    if ($winnerId === null) {
      $stmt = darts_stmt($conn, "INSERT INTO `{$summaryTable}` (match_id, winner_player_id) VALUES (?, NULL) ON DUPLICATE KEY UPDATE winner_player_id=NULL");
      $stmt->bind_param('i', $id);
    } else {
      $stmt = darts_stmt($conn, "INSERT INTO `{$summaryTable}` (match_id, winner_player_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE winner_player_id=VALUES(winner_player_id)");
      $stmt->bind_param('ii', $id, $winnerId);
    }
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
  }

  $conn->commit();
  darts_json(['success'=>true,'match'=>['id'=>$id,'game_type'=>$gameType,'legs_to_win'=>$legsToWin,'mode'=>$mode,'status'=>$status,'winner_name'=>$winner]]);
} catch (Throwable $e) {
  $conn->rollback();
  darts_json(['success'=>false,'message'=>'Unable to update match'], 500);
}
