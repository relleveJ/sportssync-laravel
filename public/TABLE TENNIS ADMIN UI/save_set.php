<?php
require_once 'db_config.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

// Early debug: record incoming headers and cookies so we can diagnose 403
$logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/tabletennis_debug.log') : __DIR__ . '/tabletennis_debug.log';
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "REQUEST HEADERS: " . print_r(getallheaders(), true) . "\n", FILE_APPEND);
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "COOKIES: " . print_r($_COOKIE, true) . "\n", FILE_APPEND);
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "RAW INPUT: " . substr(file_get_contents('php://input'), 0, 2000) . "\n", FILE_APPEND);

// Always support legacy wrapper input first
$raw = $GLOBALS['__LEGACY_INPUT_JSON'] ?? file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

// Require authenticated admin for saving sets (allow legacy 'scorekeeper' and superadmin)
try { $poster = currentUser(); } catch (Throwable $_) { $poster = null; }
$allowed = ['admin','scorekeeper','superadmin'];
if (!$poster || !in_array($poster['role'] ?? '', $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/tabletennis_debug.log') : __DIR__ . '/tabletennis_debug.log';
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "save_set payload: " . print_r($data, true) . "\n", FILE_APPEND);

// Log detected poster and cookies for troubleshooting authentication/authorization
try {
    @file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "detected poster: " . print_r($poster ?? null, true) . "\n", FILE_APPEND);
    @file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "cookies: " . print_r($_COOKIE, true) . "\n", FILE_APPEND);
} catch (Throwable $_) { /* non-fatal */ }

// Helper to POST to WS relay /emit endpoint (best-effort, non-blocking)
function emit_ws($obj) {
    try {
        $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
        $wsToken = getenv('WS_TOKEN') ?: null;
        $payload = json_encode($obj, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($wsRelay);
        $headers = ['Content-Type: application/json'];
        if ($wsToken) $headers[] = 'X-WS-Token: ' . $wsToken;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
        @curl_exec($ch);
        @curl_close($ch);
    } catch (Throwable $_) { /* non-fatal */ }
}

function normType($t) {
    if (!$t) return 'Singles';
    $s = strtolower($t);
    if (strpos($s,'double') !== false && strpos($s,'mixed') === false) return 'Doubles';
    if (strpos($s,'mixed') !== false) return 'Mixed Doubles';
    return 'Singles';
}

$match_id  = isset($data['match_id']) && $data['match_id'] !== '' ? intval($data['match_id']) : null;

// Validate match_id exists in DB (matches badminton pattern)
if ($match_id !== null) {
    $chk = $mysqli->prepare("SELECT id FROM table_tennis_matches WHERE id = ?");
    $chk->bind_param('i', $match_id);
    if ($chk->execute()) {
        $chk->store_result();
        if ($chk->num_rows === 0) { $match_id = null; }
    }
    $chk->close();
}

$match_type  = normType($data['match_type'] ?? 'singles');
$best_of     = isset($data['best_of']) ? intval($data['best_of']) : 3;
$team_a_name = $data['team_a_name'] ?? 'Team A';
$team_b_name = $data['team_b_name'] ?? 'Team B';
$ta_p1       = $data['team_a_player1'] ?? null;
$ta_p2       = $data['team_a_player2'] ?? null;
$tb_p1       = $data['team_b_player1'] ?? null;
$tb_p2       = $data['team_b_player2'] ?? null;

// Support both 'committee' and 'committee_official' keys (badminton parity)
$committee = null;
if (isset($data['committee_official']) && trim($data['committee_official']) !== '') {
    $committee = trim($data['committee_official']);
} elseif (isset($data['committee']) && trim($data['committee']) !== '') {
    $committee = trim($data['committee']);
}

$mysqli->begin_transaction();
try {
    if (empty($match_id)) {
        // INSERT new match
        $stmt = $mysqli->prepare("INSERT INTO table_tennis_matches (match_type, best_of, team_a_name, team_b_name, team_a_player1, team_a_player2, team_b_player1, team_b_player2, committee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('sisssssss', $match_type, $best_of, $team_a_name, $team_b_name, $ta_p1, $ta_p2, $tb_p1, $tb_p2, $committee);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $match_id = $mysqli->insert_id;
            $stmt->close();
        } else {
            // Fallback without committee column
            $stmt = $mysqli->prepare("INSERT INTO table_tennis_matches (match_type, best_of, team_a_name, team_b_name, team_a_player1, team_a_player2, team_b_player1, team_b_player2) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sissssss', $match_type, $best_of, $team_a_name, $team_b_name, $ta_p1, $ta_p2, $tb_p1, $tb_p2);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $match_id = $mysqli->insert_id;
            $stmt->close();
        }
    } else {
        // UPDATE existing match (avoid referencing updated_at column if absent)
        $stmt = $mysqli->prepare("UPDATE table_tennis_matches SET match_type=?, best_of=?, team_a_name=?, team_b_name=?, team_a_player1=?, team_a_player2=?, team_b_player1=?, team_b_player2=?, committee=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('sisssssssi', $match_type, $best_of, $team_a_name, $team_b_name, $ta_p1, $ta_p2, $tb_p1, $tb_p2, $committee, $match_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare("UPDATE table_tennis_matches SET match_type=?, best_of=?, team_a_name=?, team_b_name=?, team_a_player1=?, team_a_player2=?, team_b_player1=?, team_b_player2=? WHERE id=?");
            $stmt->bind_param('sissssssi', $match_type, $best_of, $team_a_name, $team_b_name, $ta_p1, $ta_p2, $tb_p1, $tb_p2, $match_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();
        }
    }

    // If a full sets array is provided, upsert each set (avoid mass DELETE which can lose data)
    if (!empty($data['sets']) && is_array($data['sets'])) {
        $selectStmt = $mysqli->prepare("SELECT id FROM table_tennis_sets WHERE match_id = ? AND set_number = ? LIMIT 1");
        if (!$selectStmt) throw new Exception($mysqli->error);

        $useCommittee = true;
        $insertStmt = $mysqli->prepare("INSERT INTO table_tennis_sets (match_id, set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner, committee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$insertStmt) {
            $insertStmt = $mysqli->prepare("INSERT INTO table_tennis_sets (match_id, set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $useCommittee = false;
            if (!$insertStmt) throw new Exception($mysqli->error);
        }

        if ($useCommittee) {
            $updateStmt = $mysqli->prepare("UPDATE table_tennis_sets SET team_a_score=?, team_b_score=?, team_a_timeout_used=?, team_b_timeout_used=?, serving_team=?, set_winner=?, committee=? WHERE match_id=? AND set_number=?");
        } else {
            $updateStmt = $mysqli->prepare("UPDATE table_tennis_sets SET team_a_score=?, team_b_score=?, team_a_timeout_used=?, team_b_timeout_used=?, serving_team=?, set_winner=? WHERE match_id=? AND set_number=?");
        }
        if (!$updateStmt) throw new Exception($mysqli->error);

        $count = 0;
        foreach ($data['sets'] as $s) {
            $sn    = isset($s['set_number'])   ? intval($s['set_number'])  : 1;
            $ta    = isset($s['team_a_score'])  ? intval($s['team_a_score']): 0;
            $tb    = isset($s['team_b_score'])  ? intval($s['team_b_score']): 0;
            $ta_to = !empty($s['team_a_timeout_used']) ? 1 : 0;
            $tb_to = !empty($s['team_b_timeout_used']) ? 1 : 0;
            $serve = (isset($s['serving_team']) && $s['serving_team'] === 'B') ? 'B' : 'A';
            $sw    = isset($s['set_winner']) && in_array($s['set_winner'], ['A','B']) ? $s['set_winner'] : null;

            // Check if set exists
            $selectStmt->bind_param('ii', $match_id, $sn);
            $selectStmt->execute();
            $res = $selectStmt->get_result();
            if ($res && $res->num_rows > 0) {
                // update existing set
                if ($useCommittee) {
                    $updateStmt->bind_param('iiiisssii', $ta, $tb, $ta_to, $tb_to, $serve, $sw, $committee, $match_id, $sn);
                } else {
                    $updateStmt->bind_param('iiiissii', $ta, $tb, $ta_to, $tb_to, $serve, $sw, $match_id, $sn);
                }
                if (!$updateStmt->execute()) throw new Exception($updateStmt->error);
            } else {
                // insert new set
                if ($useCommittee) {
                    $insertStmt->bind_param('iiiiiisss', $match_id, $sn, $ta, $tb, $ta_to, $tb_to, $serve, $sw, $committee);
                } else {
                    $insertStmt->bind_param('iiiiiiss', $match_id, $sn, $ta, $tb, $ta_to, $tb_to, $serve, $sw);
                }
                if (!$insertStmt->execute()) throw new Exception($insertStmt->error);
            }
            $count++;
        }

        $selectStmt->close();
        $updateStmt->close();
        $insertStmt->close();

        $mysqli->commit();
        // Notify WS relay about the new/updated match so other admins/viewers can sync (include metadata)
        try {
            $emitPayload = [
                'match_id' => $match_id,
                'match_type' => $match_type,
                'best_of' => $best_of,
                'team_a_name' => $team_a_name,
                'team_b_name' => $team_b_name,
                'team_a_player1' => $ta_p1,
                'team_a_player2' => $ta_p2,
                'team_b_player1' => $tb_p1,
                'team_b_player2' => $tb_p2,
                'committee' => $committee,
                'sets' => isset($data['sets']) ? $data['sets'] : null,
                'set_number' => isset($data['set_number']) ? $data['set_number'] : null,
                'team_a_score' => isset($data['team_a_score']) ? $data['team_a_score'] : null,
                'team_b_score' => isset($data['team_b_score']) ? $data['team_b_score'] : null,
                'teamA' => ['name' => $team_a_name, 'players' => [$ta_p1, $ta_p2]],
                'teamB' => ['name' => $team_b_name, 'players' => [$tb_p1, $tb_p2]]
            ];
            emit_ws(['type' => 'new_match', 'match_id' => $match_id, 'sport' => 'tabletennis', 'payload' => $emitPayload]);
        } catch (Throwable $_) { /* non-fatal */ }
        echo json_encode(['success' => true, 'match_id' => $match_id, 'message' => "{$count} sets saved."]);
        exit;
    }

    // Fallback: single-set upsert for older clients — check existence then UPDATE or INSERT
    $set_number          = isset($data['set_number'])          ? intval($data['set_number'])          : 1;
    $team_a_score        = isset($data['team_a_score'])        ? intval($data['team_a_score'])        : 0;
    $team_b_score        = isset($data['team_b_score'])        ? intval($data['team_b_score'])        : 0;
    $team_a_timeout_used = !empty($data['team_a_timeout_used']) ? 1 : 0;
    $team_b_timeout_used = !empty($data['team_b_timeout_used']) ? 1 : 0;
    $serving_team        = ($data['serving_team'] === 'B') ? 'B' : 'A';
    $set_winner          = isset($data['set_winner']) && in_array($data['set_winner'], ['A','B']) ? $data['set_winner'] : null;

    // Prepare a select to check for an existing row
    $selectSingle = $mysqli->prepare("SELECT id FROM table_tennis_sets WHERE match_id = ? AND set_number = ? LIMIT 1");
    if (!$selectSingle) throw new Exception($mysqli->error);
    $selectSingle->bind_param('ii', $match_id, $set_number);
    $selectSingle->execute();
    $resS = $selectSingle->get_result();

    if ($resS && $resS->num_rows > 0) {
        // existing row -> update it
        if ($committee !== null) {
            $stmt = $mysqli->prepare("UPDATE table_tennis_sets SET team_a_score=?, team_b_score=?, team_a_timeout_used=?, team_b_timeout_used=?, serving_team=?, set_winner=?, committee=? WHERE match_id=? AND set_number=?");
            if (!$stmt) throw new Exception($mysqli->error);
            $stmt->bind_param('iiiisssii', $team_a_score, $team_b_score, $team_a_timeout_used, $team_b_timeout_used, $serving_team, $set_winner, $committee, $match_id, $set_number);
        } else {
            $stmt = $mysqli->prepare("UPDATE table_tennis_sets SET team_a_score=?, team_b_score=?, team_a_timeout_used=?, team_b_timeout_used=?, serving_team=?, set_winner=? WHERE match_id=? AND set_number=?");
            if (!$stmt) throw new Exception($mysqli->error);
            $stmt->bind_param('iiiissii', $team_a_score, $team_b_score, $team_a_timeout_used, $team_b_timeout_used, $serving_team, $set_winner, $match_id, $set_number);
        }
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();
        $selectSingle->close();
        $mysqli->commit();
        try {
            $emitPayload = [
                'match_id' => $match_id,
                'match_type' => $match_type,
                'best_of' => $best_of,
                'team_a_name' => $team_a_name,
                'team_b_name' => $team_b_name,
                'team_a_player1' => $ta_p1,
                'team_a_player2' => $ta_p2,
                'team_b_player1' => $tb_p1,
                'team_b_player2' => $tb_p2,
                'committee' => $committee,
                'set_number' => $set_number,
                'team_a_score' => $team_a_score,
                'team_b_score' => $team_b_score,
                'teamA' => ['name' => $team_a_name, 'players' => [$ta_p1, $ta_p2]],
                'teamB' => ['name' => $team_b_name, 'players' => [$tb_p1, $tb_p2]]
            ];
            emit_ws(['type' => 'new_match', 'match_id' => $match_id, 'sport' => 'tabletennis', 'payload' => $emitPayload]);
        } catch (Throwable $_) { /* non-fatal */ }
        echo json_encode(['success' => true, 'match_id' => $match_id, 'message' => "Set {$set_number} updated."]);
        exit;
    }

    // No existing row -> insert
    $selectSingle->close();
    if ($committee !== null) {
        $stmt = $mysqli->prepare("INSERT INTO table_tennis_sets (match_id, set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner, committee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception($mysqli->error);
        $stmt->bind_param('iiiiiisss', $match_id, $set_number, $team_a_score, $team_b_score, $team_a_timeout_used, $team_b_timeout_used, $serving_team, $set_winner, $committee);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO table_tennis_sets (match_id, set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception($mysqli->error);
        $stmt->bind_param('iiiiiiss', $match_id, $set_number, $team_a_score, $team_b_score, $team_a_timeout_used, $team_b_timeout_used, $serving_team, $set_winner);
    }
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();
    $mysqli->commit();
    try {
        $emitPayload = [
            'match_id' => $match_id,
            'match_type' => $match_type,
            'best_of' => $best_of,
            'team_a_name' => $team_a_name,
            'team_b_name' => $team_b_name,
            'team_a_player1' => $ta_p1,
            'team_a_player2' => $ta_p2,
            'team_b_player1' => $tb_p1,
            'team_b_player2' => $tb_p2,
            'committee' => $committee,
            'set_number' => $set_number,
            'team_a_score' => $team_a_score,
            'team_b_score' => $team_b_score,
            'teamA' => ['name' => $team_a_name, 'players' => [$ta_p1, $ta_p2]],
            'teamB' => ['name' => $team_b_name, 'players' => [$tb_p1, $tb_p2]]
        ];
        emit_ws(['type' => 'new_match', 'match_id' => $match_id, 'sport' => 'tabletennis', 'payload' => $emitPayload]);
    } catch (Throwable $_) { /* non-fatal */ }
    echo json_encode(['success' => true, 'match_id' => $match_id, 'message' => "Set {$set_number} saved."]);
    exit;

} catch (Exception $e) {
    $mysqli->rollback();
    $logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/tabletennis_debug.log') : __DIR__ . '/tabletennis_debug.log';
    @file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "save_set error: " . $e->getMessage() . "\nmysqli error: " . $mysqli->error . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
