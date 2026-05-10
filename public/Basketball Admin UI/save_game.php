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
    /** @var array $user */
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Ensure only admins (and legacy scorekeepers / superadmins) may persist matches
$allowedRoles = ['admin','scorekeeper','superadmin'];
if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
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
$teamAQuarter = isset($data['shared']['quarter']) ? (int) $data['shared']['quarter'] : 0;
$teamBFoul    = isset($teamB['foul'])    ? (int)    $teamB['foul']    : 0;
$teamBTimeout = isset($teamB['timeout']) ? (int)    $teamB['timeout'] : 0;
$teamBQuarter = $teamAQuarter;
$teamAName    = isset($teamA['name'])    ? (string) $teamA['name']    : 'TEAM A';
$teamBName    = isset($teamB['name'])    ? (string) $teamB['name']    : 'TEAM B';
$committee    = isset($data['committee'])? (string) $data['committee']: '';

$matchResult = 'draw';
if ($teamAScore > $teamBScore) {
    $matchResult = 'team_a_win';
} elseif ($teamBScore > $teamAScore) {
    $matchResult = 'team_b_win';
}

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

    // Determine whether client requested update of an existing match
    $incomingMid = isset($data['match_id']) ? (int)$data['match_id'] : 0;
    $matchId = 0;

    if ($incomingMid > 0) {
        // If match exists, update it instead of creating a new one.
        try {
            $chk = $pdo->prepare('SELECT match_id FROM matches WHERE match_id = :id LIMIT 1');
            $chk->execute([':id' => $incomingMid]);
            $exists = $chk->fetch(PDO::FETCH_ASSOC);
            if ($exists) {
                $up = $pdo->prepare(
                    'UPDATE `matches` SET
                        team_a_name = :team_a_name,
                        team_b_name = :team_b_name,
                        team_a_score = :team_a_score,
                        team_b_score = :team_b_score,
                        team_a_foul = :team_a_foul,
                        team_a_timeout = :team_a_timeout,
                        team_a_quarter = :team_a_quarter,
                        team_b_foul = :team_b_foul,
                        team_b_timeout = :team_b_timeout,
                        team_b_quarter = :team_b_quarter,
                        match_result = :match_result,
                        committee = :committee
                     WHERE match_id = :match_id'
                );
                $up->execute([
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
                    ':match_id'       => $incomingMid,
                ]);
                $matchId = $incomingMid;
            }
        } catch (Throwable $_) {
            // Fall through to create new match if update fails
            $matchId = 0;
        }
    }

    // If no existing match was updated, insert a new match row
    if (empty($matchId)) {
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
    }

    // Prepare player insert statement and refresh player rows for this match
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

    // Remove any existing players for the match to avoid duplicates
    try {
        $del = $pdo->prepare('DELETE FROM match_players WHERE match_id = :id');
        $del->execute([':id' => $matchId]);
    } catch (Throwable $_) { /* non-fatal */ }

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

    // Ensure player arrays exist (incoming payload may embed roster in teamA/teamB)
    $playersA = isset($teamA['players']) && is_array($teamA['players']) ? $teamA['players'] : [];
    $playersB = isset($teamB['players']) && is_array($teamB['players']) ? $teamB['players'] : [];

    foreach ($playersA as $player) { $insertPlayer($player, 'A'); }
    foreach ($playersB as $player) { $insertPlayer($player, 'B'); }

    // If the client included a live state snapshot (timers, players, etc.)
    // persist it into the canonical `match_state` row so returning admins
    // can rehydrate the exact live context for this saved report.
    try {
        if (isset($data['state']) && is_array($data['state'])) {
            // Build canonical payload using provided state and inserted players
            $state = $data['state'];
            $matchState = [];
            // Include full roster snapshots
            $matchState['teamA'] = isset($state['teamA']) ? $state['teamA'] : $teamA;
            $matchState['teamB'] = isset($state['teamB']) ? $state['teamB'] : $teamB;
            // Ensure players arrays reflect what was saved
            $matchState['teamA']['players'] = $playersA;
            $matchState['teamB']['players'] = $playersB;
            // Shared and committee
            $matchState['shared'] = isset($state['shared']) ? $state['shared'] : (isset($state['shared']) ? $state['shared'] : []);
            $matchState['committee'] = isset($state['committee']) ? $state['committee'] : $committee;

            // Helper to map camelCase timer -> canonical snake_case (ms)
            $mapTimer = function ($c) {
                $r = [];
                if (!is_array($c)) return $r;
                $r['running'] = isset($c['running']) ? (bool)$c['running'] : false;
                if (isset($c['remaining'])) $r['remaining_ms'] = (int) round(((float)$c['remaining']) * 1000.0);
                elseif (isset($c['remaining_ms'])) $r['remaining_ms'] = (int) round((float)$c['remaining_ms']);
                if (isset($c['paused_remaining'])) $r['paused_remaining_ms'] = (int) round(((float)$c['paused_remaining']) * 1000.0);
                elseif (isset($c['paused_remaining_ms'])) $r['paused_remaining_ms'] = (int) round((float)$c['paused_remaining_ms']);
                if (isset($c['total'])) $r['total_ms'] = (int) round(((float)$c['total']) * 1000.0);
                elseif (isset($c['total_ms'])) $r['total_ms'] = (int) round((float)$c['total_ms']);
                if (isset($c['ts'])) $r['start_timestamp'] = (int) $c['ts'];
                elseif (isset($c['start_timestamp'])) $r['start_timestamp'] = (int) $c['start_timestamp'];
                return $r;
            };

            if (isset($state['gameTimer']) && is_array($state['gameTimer'])) {
                $matchState['game_timer'] = $mapTimer($state['gameTimer']);
            }
            if (isset($state['shotClock']) && is_array($state['shotClock'])) {
                $matchState['shot_clock'] = $mapTimer($state['shotClock']);
            }

            // Persist into canonical match_states table (upsert)
            try {
                $json = json_encode($matchState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $last_user = null;
                if (is_array($user) && array_key_exists('id', $user)) {
                    $last_user = (int) $user['id'];
                }
                $last_role = null;
                if (is_array($user) && array_key_exists('role', $user)) {
                    $last_role = (string) $user['role'];
                }
                $up = $pdo->prepare('INSERT INTO match_states (match_id,payload,last_user_id,last_role,created_at,updated_at) VALUES (:id,:payload,:last_user,:last_role,NOW(),NOW()) ON DUPLICATE KEY UPDATE payload = :payload_upd, last_user_id = :last_user_upd, last_role = :last_role_upd, updated_at = NOW()');
                $up->execute([':id' => $matchId, ':payload' => $json, ':last_user' => $last_user, ':last_role' => $last_role, ':payload_upd' => $json, ':last_user_upd' => $last_user, ':last_role_upd' => $last_role]);
                // Notify ws-server relay (best-effort) so other admins rehydrate
                try {
                    @require_once __DIR__ . '/../ws-server/ws_relay.php';
                    if (function_exists('ss_ws_relay_notify_state')) {
                        try { $p = json_decode($json, true); $p['_meta'] = ['last_user_id'=>$last_user,'last_role'=>$last_role]; ss_ws_relay_notify_state($matchId, $p, (int)round(microtime(true) * 1000)); } catch (Throwable $_) {}
                    }
                } catch (Throwable $_) { /* non-fatal */ }
            } catch (Throwable $ex) {
                // Non-fatal: don't block saving the report if this fails.
                error_log('[save_game.php] failed to persist match_state: ' . $ex->getMessage());
            }
        }
    } catch (Throwable $_) { /* ignore */ }

    // Return success for saved match
    echo json_encode(['success' => true, 'match_id' => $matchId]);
    exit;
