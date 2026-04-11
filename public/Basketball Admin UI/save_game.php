<?php
// ============================================================
// save_game.php — POST endpoint: save game to database
// Accepts: application/json body
// Returns: application/json { success, match_id } or { success, error }
// ============================================================

header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';

// Require authenticated user for saving matches
try {
    $user = requireLogin();
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

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

$teamAScore   = isset($teamA['score'])   ? (int)    $teamA['score']   : 0;
$teamBScore   = isset($teamB['score'])   ? (int)    $teamB['score']   : 0;
$teamAFoul    = isset($teamA['foul'])    ? (int)    $teamA['foul']    : 0;
$teamATimeout = isset($teamA['timeout']) ? (int)    $teamA['timeout'] : 0;
$teamAQuarter = isset($teamA['quarter']) ? (int)    $teamA['quarter'] : 0;
$teamBFoul    = isset($teamB['foul'])    ? (int)    $teamB['foul']    : 0;
$teamBTimeout = isset($teamB['timeout']) ? (int)    $teamB['timeout'] : 0;
$teamBQuarter = isset($teamB['quarter']) ? (int)    $teamB['quarter'] : 0;
$teamAName    = isset($teamA['name'])    ? (string) $teamA['name']    : 'TEAM A';
$teamBName    = isset($teamB['name'])    ? (string) $teamB['name']    : 'TEAM B';
$committee    = isset($data['committee'])? (string) $data['committee']: '';

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
    require_once __DIR__ . '/db.php';

    // Ensure matches table has owner_user_id column (add if missing)
    try {
        $colCheck = $pdo->prepare("SHOW COLUMNS FROM `matches` LIKE 'owner_user_id'");
        $colCheck->execute();
        if (!$colCheck->fetch()) {
            $pdo->exec("ALTER TABLE `matches` ADD COLUMN owner_user_id INT NULL AFTER match_id");
        }
    } catch (Throwable $e) {
        // Non-fatal: continue without altering
    }

    // Insert match with owner_user_id when available
    $stmtMatch = $pdo->prepare(
        'INSERT INTO `matches`
            (team_a_name, team_b_name,
             team_a_score, team_b_score,
             team_a_foul, team_a_timeout, team_a_quarter,
             team_b_foul, team_b_timeout, team_b_quarter,
             match_result, committee, owner_user_id)
         VALUES
            (:team_a_name, :team_b_name,
             :team_a_score, :team_b_score,
             :team_a_foul, :team_a_timeout, :team_a_quarter,
             :team_b_foul, :team_b_timeout, :team_b_quarter,
             :match_result, :committee, :owner_user_id)'
    );

    $stmtMatch->execute([
        ':team_a_name'    => $teamAName,
        ':team_b_name'    => $teamBName,
        ':team_a_score'   => $teamAScore,
        ':team_b_score'   => $teamBScore,
        ':team_a_foul'    => $teamAFoul,
        ':team_a_timeout' => $teamATimeout,
        ':team_a_quarter' => $teamAQuarter,
        ':team_b_foul'    => $teamBFoul,
        ':team_b_timeout' => $teamBTimeout,
        ':team_b_quarter' => $teamBQuarter,
        ':match_result'   => $matchResult,
        ':committee'      => $committee,
        ':owner_user_id'  => $user['id'] ?? null,
    ]);

    $matchId = (int) $pdo->lastInsertId();

    $stmtPlayer = $pdo->prepare(
        'INSERT INTO `match_players`
            (match_id, team, jersey_no, player_name,
             pts, foul, reb, ast, blk, stl,
             tech_foul, tech_reason)
         VALUES
            (:match_id, :team, :jersey_no, :player_name,
             :pts, :foul, :reb, :ast, :blk, :stl,
             :tech_foul, :tech_reason)'
    );

    $insertPlayer = function (array $p, string $teamLetter) use ($stmtPlayer, $matchId) {
        $stmtPlayer->execute([
            ':match_id'    => $matchId,
            ':team'        => $teamLetter,
            ':jersey_no'   => isset($p['no'])         ? (string) $p['no']         : '',
            ':player_name' => isset($p['name'])        ? (string) $p['name']       : '',
            ':pts'         => isset($p['pts'])         ? (int)    $p['pts']        : 0,
            ':foul'        => isset($p['foul'])        ? (int)    $p['foul']       : 0,
            ':reb'         => isset($p['reb'])         ? (int)    $p['reb']        : 0,
            ':ast'         => isset($p['ast'])         ? (int)    $p['ast']        : 0,
            ':blk'         => isset($p['blk'])         ? (int)    $p['blk']        : 0,
            ':stl'         => isset($p['stl'])         ? (int)    $p['stl']        : 0,
            ':tech_foul'   => isset($p['techFoul'])    ? (int)    $p['techFoul']   : 0,
            ':tech_reason' => isset($p['techReason'])  ? (string) $p['techReason'] : '',
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