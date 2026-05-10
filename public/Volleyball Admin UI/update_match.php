<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($pdo) || !($pdo instanceof PDO)) {
  try {
    $pdo = new PDO('mysql:host=localhost;dbname=sportssync;charset=utf8mb4', 'root', '', [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database connection failed']);
    exit;
  }
}

try { $user = requireLogin(); } catch (Throwable $_) { $user = null; }
if (!$user || !in_array($user['role'] ?? '', ['admin','scorekeeper','superadmin'], true)) {
  http_response_code(403); echo json_encode(['success'=>false,'message'=>'Authentication required']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = (int)($_GET['match_id'] ?? 0);
  if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'match_id required']); exit; }
  $stmt = $pdo->prepare('SELECT * FROM volleyball_matches WHERE match_id=:id LIMIT 1');
  $stmt->execute([':id'=>$id]);
  $match = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$match) { echo json_encode(['success'=>false,'message'=>'Match not found']); exit; }
  $stmt = $pdo->prepare('SELECT id, team, jersey_no, player_name FROM volleyball_players WHERE match_id=:id ORDER BY team ASC, id ASC');
  $stmt->execute([':id'=>$id]);
  echo json_encode(['success'=>true,'match'=>$match,'players'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['match_id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'match_id required']); exit; }
$teamA = trim((string)($data['team_a_name'] ?? 'TEAM A')) ?: 'TEAM A';
$teamB = trim((string)($data['team_b_name'] ?? 'TEAM B')) ?: 'TEAM B';
$scoreA = max(0, (int)($data['team_a_score'] ?? 0));
$scoreB = max(0, (int)($data['team_b_score'] ?? 0));
$set = max(1, min(9, (int)($data['current_set'] ?? 1)));
$committee = trim((string)($data['committee'] ?? ''));
$result = (string)($data['match_result'] ?? '');
if (!in_array($result, ['TEAM A WINS','TEAM B WINS','DRAW','IN PROGRESS','ONGOING'], true)) {
  $result = $scoreA > $scoreB ? 'TEAM A WINS' : ($scoreB > $scoreA ? 'TEAM B WINS' : 'DRAW');
}

try {
  $pdo->beginTransaction();
  $stmt = $pdo->prepare('UPDATE volleyball_matches SET team_a_name=:ta, team_b_name=:tb, team_a_score=:sa, team_b_score=:sb, current_set=:cs, match_result=:mr, committee=:c WHERE match_id=:id');
  $stmt->execute([':ta'=>$teamA, ':tb'=>$teamB, ':sa'=>$scoreA, ':sb'=>$scoreB, ':cs'=>$set, ':mr'=>$result, ':c'=>$committee, ':id'=>$id]);
  $up = $pdo->prepare('UPDATE volleyball_players SET player_name=:name, jersey_no=:jersey WHERE id=:pid AND match_id=:mid');
  foreach ((is_array($data['players'] ?? null) ? $data['players'] : []) as $p) {
    $pid = (int)($p['id'] ?? 0);
    if ($pid <= 0) continue;
    $up->execute([':name'=>trim((string)($p['player_name'] ?? '')), ':jersey'=>trim((string)($p['jersey_no'] ?? '')), ':pid'=>$pid, ':mid'=>$id]);
  }
  $pdo->commit();
  echo json_encode(['success'=>true,'match'=>['match_id'=>$id,'team_a_name'=>$teamA,'team_b_name'=>$teamB,'team_a_score'=>$scoreA,'team_b_score'=>$scoreB,'current_set'=>$set,'match_result'=>$result,'committee'=>$committee]]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500); echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
