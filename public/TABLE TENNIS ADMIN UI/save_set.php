<?php
require_once 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/tabletennis_debug.log') : __DIR__ . '/tabletennis_debug.log';
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "save_set payload: " . print_r($data, true) . "\n", FILE_APPEND);

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
        // UPDATE existing match
        $stmt = $mysqli->prepare("UPDATE table_tennis_matches SET match_type=?, best_of=?, team_a_name=?, team_b_name=?, team_a_player1=?, team_a_player2=?, team_b_player1=?, team_b_player2=?, committee=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('sisssssssi', $match_type, $best_of, $team_a_name, $team_b_name, $ta_p1, $ta_p2, $tb_p1, $tb_p2, $committee, $match_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare("UPDATE table_tennis_matches SET match_type=?, best_of=?, team_a_name=?, team_b_name=?, team_a_player1=?, team_a_player2=?, team_b_player1=?, team_b_player2=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->bind_param('sissssssi', $match_type, $best_of, $team_a_name, $team_b_name, $ta_p1, $ta_p2, $tb_p1, $tb_p2, $match_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();
        }
    }

    // If a full sets array is provided, replace existing sets
    if (!empty($data['sets']) && is_array($data['sets'])) {
        $del = $mysqli->prepare("DELETE FROM table_tennis_sets WHERE match_id = ?");
        if ($del) {
            $del->bind_param('i', $match_id);
            if (!$del->execute()) throw new Exception($del->error);
            $del->close();
        }

        $insertStmt = $mysqli->prepare("INSERT INTO table_tennis_sets (match_id, set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner, committee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $useCommittee = true;
        if (!$insertStmt) {
            $insertStmt = $mysqli->prepare("INSERT INTO table_tennis_sets (match_id, set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $useCommittee = false;
            if (!$insertStmt) throw new Exception($mysqli->error);
        }

        $count = 0;
        foreach ($data['sets'] as $s) {
            $sn    = isset($s['set_number'])   ? intval($s['set_number'])  : 1;
            $ta    = isset($s['team_a_score'])  ? intval($s['team_a_score']): 0;
            $tb    = isset($s['team_b_score'])  ? intval($s['team_b_score']): 0;
            $ta_to = !empty($s['team_a_timeout_used']) ? 1 : 0;
            $tb_to = !empty($s['team_b_timeout_used']) ? 1 : 0;
            $serve = (isset($s['serving_team']) && $s['serving_team'] === 'B') ? 'B' : 'A';
            $sw    = isset($s['set_winner']) && in_array($s['set_winner'], ['A','B']) ? $s['set_winner'] : null;
            if ($useCommittee) {
                $insertStmt->bind_param('iiiiiisss', $match_id, $sn, $ta, $tb, $ta_to, $tb_to, $serve, $sw, $committee);
            } else {
                $insertStmt->bind_param('iiiiiiss', $match_id, $sn, $ta, $tb, $ta_to, $tb_to, $serve, $sw);
            }
            if (!$insertStmt->execute()) throw new Exception($insertStmt->error);
            $count++;
        }
        $insertStmt->close();

        $mysqli->commit();
        echo json_encode(['success' => true, 'match_id' => $match_id, 'message' => "{$count} sets saved."]);
        exit;
    }

    // Fallback: single-set insert for older clients
    $set_number          = isset($data['set_number'])          ? intval($data['set_number'])          : 1;
    $team_a_score        = isset($data['team_a_score'])        ? intval($data['team_a_score'])        : 0;
    $team_b_score        = isset($data['team_b_score'])        ? intval($data['team_b_score'])        : 0;
    $team_a_timeout_used = !empty($data['team_a_timeout_used']) ? 1 : 0;
    $team_b_timeout_used = !empty($data['team_b_timeout_used']) ? 1 : 0;
    $serving_team        = ($data['serving_team'] === 'B') ? 'B' : 'A';
    $set_winner          = isset($data['set_winner']) && in_array($data['set_winner'], ['A','B']) ? $data['set_winner'] : null;

    $stmt = $mysqli->prepare("INSERT INTO table_tennis_sets (match_id, set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner, committee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('iiiiiisss', $match_id, $set_number, $team_a_score, $team_b_score, $team_a_timeout_used, $team_b_timeout_used, $serving_team, $set_winner, $committee);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();
    } else {
        $stmt = $mysqli->prepare("INSERT INTO table_tennis_sets (match_id, set_number, team_a_score, team_b_score, team_a_timeout_used, team_b_timeout_used, serving_team, set_winner) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiiiiiss', $match_id, $set_number, $team_a_score, $team_b_score, $team_a_timeout_used, $team_b_timeout_used, $serving_team, $set_winner);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();
    }

    $mysqli->commit();
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
