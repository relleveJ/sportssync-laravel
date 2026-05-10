<?php
// ============================================================
// volleyball_save_game.php — POST endpoint: save volleyball match
// Accepts: application/json body
// Returns: application/json { success, match_id } or { success, error }
// ============================================================

header('Content-Type: application/json');

// Fail-safe: do not emit raw PHP errors to the client; always return JSON
ini_set('display_errors', '0');
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

// Include auth and DB shims from public/ (one level up)
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$user = requireLogin();
// Only admins may persist matches (allow legacy 'scorekeeper' and superadmin)
$allowed = ['admin','scorekeeper','superadmin'];
if (!in_array($user['role'] ?? '', $allowed, true)) {
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

// Support admin reset requests: { reset_match_id: <int> }
if (isset($data['reset_match_id'])) {
    $mid = (int) $data['reset_match_id'];
    if ($mid <= 0) {
        echo json_encode(['success' => false, 'error' => 'invalid match id']); exit;
    }
    try {
        // Reset the match row so fallback state does not restore old values
        $pdo->prepare(
            'UPDATE volleyball_matches SET
                team_a_name = ?, team_b_name = ?, team_a_score = 0, team_b_score = 0,
                team_a_timeout = 0, team_b_timeout = 0, current_set = 1,
                match_result = ?, committee = ?
             WHERE match_id = ?'
        )->execute(['TEAM A', 'TEAM B', 'RESET', '', $mid]);
        $pdo->prepare('DELETE FROM volleyball_players WHERE match_id = ?')
            ->execute([$mid]);
        $pdo->prepare('DELETE FROM draft_match_states WHERE match_id = ?')
            ->execute([$mid]);

        // Remove stale pending live state for this match if present
        $pendingFile = __DIR__ . '/volleyball_pending_state.json';
        if (file_exists($pendingFile)) {
            $raw = @file_get_contents($pendingFile);
            $pendingData = $raw ? json_decode($raw, true) : null;
            if (is_array($pendingData) && isset($pendingData['match_id']) && (string)$pendingData['match_id'] === (string)$mid) {
                @unlink($pendingFile);
            }
        }

        // Persist an explicit clean reset snapshot so reloads do not rehydrate stale state.
        $blankPayload = [
            'teamA' => [
                'name' => 'TEAM A',
                'score' => 0,
                'timeout' => 0,
                'set' => 1,
                'lineup' => array_fill(0, 6, null),
                'players' => [],
            ],
            'teamB' => [
                'name' => 'TEAM B',
                'score' => 0,
                'timeout' => 0,
                'set' => 1,
                'lineup' => array_fill(0, 6, null),
                'players' => [],
            ],
            'shared' => ['set' => 1],
            'committee' => '',
            '_ssot_ts' => round(microtime(true) * 1000),
            '_ssot_client' => 'server-reset',
            '_ssot_reset' => true,
        ];
        @file_put_contents($pendingFile, json_encode(['match_id' => $mid, 'payload' => $blankPayload, 'updated_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $pdo->prepare('INSERT INTO draft_match_states (match_id,payload,updated_at) VALUES (:id,:payload,NOW()) ON DUPLICATE KEY UPDATE payload = :payload, updated_at = NOW()')
            ->execute([':id' => $mid, ':payload' => json_encode($blankPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        echo json_encode(['success' => true]); exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Reset failed: ' . $e->getMessage()]); exit;
    }
}

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

    // Compute MVP automatically from player stats if present.
    // Formula (aligned with Basketball weighting idea):
    // mvp_score = pts*2 + spike*1.2 + ace*1.5 + ex_set*1 + ex_dig*1
    $mvp = null;
    $mvpScore = -INF;
    $makeText = function($p, $teamLetter, $score) {
        $no = isset($p['no']) && $p['no'] !== '' ? ('#' . $p['no'] . ' ') : '';
        $name = isset($p['name']) ? $p['name'] : '';
        $text = trim($no . $name);
        if ($text === '') $text = 'Player';
        $rubric = sprintf('pts:%d spike:%d ace:%d ex_set:%d ex_dig:%d score:%.2f',
            isset($p['pts']) ? (int)$p['pts'] : 0,
            isset($p['spike']) ? (int)$p['spike'] : 0,
            isset($p['ace']) ? (int)$p['ace'] : 0,
            isset($p['exSet']) ? (int)$p['exSet'] : 0,
            isset($p['exDig']) ? (int)$p['exDig'] : 0,
            $score
        );
        return [(string)$text . ' (' . $teamLetter . ')', $rubric];
    };
    foreach ($playersA as $p) {
        $score = ((isset($p['pts']) ? (int)$p['pts'] : 0) * 2)
               + ((isset($p['spike']) ? (int)$p['spike'] : 0) * 1.2)
               + ((isset($p['ace']) ? (int)$p['ace'] : 0) * 1.5)
               + ((isset($p['exSet']) ? (int)$p['exSet'] : 0) * 1)
               + ((isset($p['exDig']) ? (int)$p['exDig'] : 0) * 1);
        if ($score > $mvpScore) {
            $mvpScore = $score;
            list($mvp, $mvpRubric) = $makeText($p, 'A', $score);
        }
    }
    foreach ($playersB as $p) {
        $score = ((isset($p['pts']) ? (int)$p['pts'] : 0) * 2)
               + ((isset($p['spike']) ? (int)$p['spike'] : 0) * 1.2)
               + ((isset($p['ace']) ? (int)$p['ace'] : 0) * 1.5)
               + ((isset($p['exSet']) ? (int)$p['exSet'] : 0) * 1)
               + ((isset($p['exDig']) ? (int)$p['exDig'] : 0) * 1);
        if ($score > $mvpScore) {
            $mvpScore = $score;
            list($mvp, $mvpRubric) = $makeText($p, 'B', $score);
        }
    }
    // Ensure strings
    $mvp = isset($mvp) ? (string)$mvp : null;
    $mvpRubric = isset($mvpRubric) ? (string)$mvpRubric : null;

try {
    // Detect available columns in volleyball_matches so we only attempt to set columns that exist
    $colRows = $pdo->query("SHOW COLUMNS FROM volleyball_matches")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($colRows, 'Field');

    $insertCols = [
        'team_a_name','team_b_name','team_a_score','team_b_score',
        'team_a_timeout','team_b_timeout','current_set','match_result','committee','owner_user_id'
    ];

    // Optional MVP fields: allow storing if table has these columns
    $mvp = isset($data['mvp']) ? (string)$data['mvp'] : null;
    $mvpRubric = isset($data['mvp_rubric']) ? (string)$data['mvp_rubric'] : null;
    if (in_array('mvp', $colNames) && $mvp !== null) $insertCols[] = 'mvp';
    if (in_array('mvp_rubric', $colNames) && $mvpRubric !== null) $insertCols[] = 'mvp_rubric';

    $placeholders = array_map(fn($c)=>':'.$c, $insertCols);
    $sql = 'INSERT INTO `volleyball_matches` (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmtMatch = $pdo->prepare($sql);

    $bind = [
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
    ];
    if (in_array('mvp', $insertCols)) $bind[':mvp'] = $mvp;
    if (in_array('mvp_rubric', $insertCols)) $bind[':mvp_rubric'] = $mvpRubric;

    $stmtMatch->execute($bind);

    $matchId = (int) $pdo->lastInsertId();

    $stmtPlayer = $pdo->prepare(
        'INSERT INTO `volleyball_players`
            (match_id, team, jersey_no, player_name,
             pts, spike, ace, ex_set, ex_dig, blk)
         VALUES
            (:match_id, :team, :jersey_no, :player_name,
             :pts, :spike, :ace, :ex_set, :ex_dig, :blk)'
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
            ':blk'         => isset($p['blk'])    ? (int)    $p['blk']    : 0,
        ]);
    };

    foreach ($playersA as $player) { $insertPlayer($player, 'A'); }
    foreach ($playersB as $player) { $insertPlayer($player, 'B'); }

    // Notify lightweight file for non-WS viewers
    try {
        $notifyPath = __DIR__ . '/volleyball_notify.json';
        @file_put_contents($notifyPath, json_encode(['match_id' => $matchId, 'ts' => time()]), LOCK_EX);
    } catch (Throwable $_) { /* ignore */ }

    // Try to notify WS relay so connected viewers get the update with minimal delay
    try {
        $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
        $wsToken = getenv('WS_TOKEN') ?: null;

        $wsPayload = [
            'teamA' => [
                'name' => $teamAName,
                'score' => $teamAScore,
                'timeout' => $teamATimeout,
                'set' => $currentSet,
                'lineup' => array_fill(0, 6, null),
                'players' => $playersA
            ],
            'teamB' => [
                'name' => $teamBName,
                'score' => $teamBScore,
                'timeout' => $teamBTimeout,
                'set' => $currentSet,
                'lineup' => array_fill(0, 6, null),
                'players' => $playersB
            ],
            'shared' => ['set' => $currentSet],
            'committee' => $committee
        ];

        $emit = json_encode([
            'type' => 'room_state',
            'match_id' => $matchId,
            'payload' => ['volleyball' => $wsPayload]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($wsRelay);
        $headers = ['Content-Type: application/json'];
        if ($wsToken) $headers[] = 'X-WS-Token: ' . $wsToken;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $emit);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
        @curl_exec($ch);
        @curl_close($ch);
    } catch (Throwable $_) { /* non-fatal */ }

    echo json_encode(['success' => true, 'match_id' => $matchId]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
