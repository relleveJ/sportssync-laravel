<?php
// ============================================================
// volleyball_save_game.php — POST endpoint: save volleyball match
// Accepts: application/json body
// Returns: application/json { success, match_id } or { success, error }
// ============================================================

header('Content-Type: application/json');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['teamA'], $data['teamB'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing JSON payload.']);
    exit;
}

$teamA = $data['teamA'];
$teamB = $data['teamB'];

$teamAName    = isset($teamA['name'])    ? (string) $teamA['name']    : 'TEAM A';
$teamBName    = isset($teamB['name'])    ? (string) $teamB['name']    : 'TEAM B';
$teamAScore   = isset($teamA['score'])   ? (int)    $teamA['score']   : 0;
$teamBScore   = isset($teamB['score'])   ? (int)    $teamB['score']   : 0;
$teamATimeout = isset($teamA['timeout']) ? (int)    $teamA['timeout'] : 0;
$teamBTimeout = isset($teamB['timeout']) ? (int)    $teamB['timeout'] : 0;
$currentSet   = isset($data['shared']['set']) ? (int) $data['shared']['set'] : 1;
$committee    = isset($data['committee']) ? (string) $data['committee'] : '';

if ($teamAScore > $teamBScore) {
    $matchResult = 'TEAM A WINS';
} elseif ($teamBScore > $teamAScore) {
    $matchResult = 'TEAM B WINS';
} else {
    $matchResult = 'DRAW';
}

$playersA = isset($teamA['players']) && is_array($teamA['players']) ? $teamA['players'] : [];
$playersB = isset($teamB['players']) && is_array($teamB['players']) ? $teamB['players'] : [];

try {
    $stmtMatch = $pdo->prepare(
        'INSERT INTO `volleyball_matches`
            (team_a_name, team_b_name,
             team_a_score, team_b_score,
             team_a_timeout, team_b_timeout,
             current_set, match_result,
             committee, owner_user_id)
         VALUES
            (:team_a_name, :team_b_name,
             :team_a_score, :team_b_score,
             :team_a_timeout, :team_b_timeout,
             :current_set, :match_result,
             :committee, :owner_user_id)'
    );

    $stmtMatch->execute([
        ':team_a_name'    => $teamAName,
        ':team_b_name'    => $teamBName,
        ':team_a_score'   => $teamAScore,
        ':team_b_score'   => $teamBScore,
        ':team_a_timeout' => $teamATimeout,
        ':team_b_timeout' => $teamBTimeout,
        ':current_set'    => $currentSet,
        ':match_result'   => $matchResult,
        ':committee'      => $committee,
        ':owner_user_id'  => $user['id'],
    ]);

    $matchId = (int) $pdo->lastInsertId();

    $stmtPlayer = $pdo->prepare(
        'INSERT INTO `volleyball_players`
            (match_id, team, jersey_no, player_name,
             pts, spike, ace, ex_set, ex_dig)
         VALUES
            (:match_id, :team, :jersey_no, :player_name,
             :pts, :spike, :ace, :ex_set, :ex_dig)'
    );

    $insertPlayer = function (array $p, string $teamLetter) use ($stmtPlayer, $matchId) {
        $stmtPlayer->execute([
            ':match_id'    => $matchId,
            ':team'        => $teamLetter,
            ':jersey_no'   => isset($p['no'])     ? (string) $p['no']     : '',
            ':player_name' => isset($p['name'])   ? (string) $p['name']   : '',
            ':pts'         => isset($p['pts'])    ? (int)    $p['pts']    : 0,
            ':spike'       => isset($p['spike'])  ? (int)    $p['spike']  : 0,
            ':ace'         => isset($p['ace'])    ? (int)    $p['ace']    : 0,
            ':ex_set'      => isset($p['exSet'])  ? (int)    $p['exSet']  : 0,
            ':ex_dig'      => isset($p['exDig'])  ? (int)    $p['exDig']  : 0,
        ]);
    };

    foreach ($playersA as $player) { $insertPlayer($player, 'A'); }
    foreach ($playersB as $player) { $insertPlayer($player, 'B'); }

    echo json_encode(['success' => true, 'match_id' => $matchId]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
