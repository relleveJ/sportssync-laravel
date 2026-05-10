<?php
require_once 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit; }

$match_id = isset($data['match_id']) ? intval($data['match_id']) : 0;
$total_sets_played = isset($data['total_sets_played']) ? intval($data['total_sets_played']) : 0;
$team_a_sets_won = isset($data['team_a_sets_won']) ? intval($data['team_a_sets_won']) : 0;
$team_b_sets_won = isset($data['team_b_sets_won']) ? intval($data['team_b_sets_won']) : 0;
$winner_team = isset($data['winner_team']) && in_array($data['winner_team'], ['A','B']) ? $data['winner_team'] : null;
$winner_name = isset($data['winner_name']) ? $data['winner_name'] : null;

if (!$match_id) { echo json_encode(['success' => false, 'message' => 'match_id required']); exit; }

// Update matches table
$stmt = $mysqli->prepare("UPDATE badminton_matches SET status='completed', winner_name=? WHERE id=?");
$stmt->bind_param('si', $winner_name, $match_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $stmt->error]);
    exit;
}
$stmt->close();

// Insert or update summary (use INSERT ... ON DUPLICATE KEY UPDATE)
$stmt = $mysqli->prepare("INSERT INTO badminton_match_summary (match_id, total_sets_played, team_a_sets_won, team_b_sets_won, winner_team, winner_name) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_sets_played=VALUES(total_sets_played), team_a_sets_won=VALUES(team_a_sets_won), team_b_sets_won=VALUES(team_b_sets_won), winner_team=VALUES(winner_team), winner_name=VALUES(winner_name), declared_at=CURRENT_TIMESTAMP");
$stmt->bind_param('iiiiss', $match_id, $total_sets_played, $team_a_sets_won, $team_b_sets_won, $winner_team, $winner_name);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $stmt->error]);
    exit;
}
$stmt->close();

// Best-effort: notify ws-relay about declared winner so viewers/admins update
try {
    $teamA = null; $teamB = null;
    try {
        $mstmt = $mysqli->prepare('SELECT team_a_name, team_b_name, committee_official FROM badminton_matches WHERE id = ? LIMIT 1');
        if ($mstmt) {
            $mstmt->bind_param('i', $match_id);
            $mstmt->execute();
            $res = $mstmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($row) { $teamA = $row['team_a_name'] ?? null; $teamB = $row['team_b_name'] ?? null; $committee = $row['committee_official'] ?? null; }
            $mstmt->close();
        }
    } catch (Throwable $_) { }

    $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
    $wsToken = getenv('WS_TOKEN') ?: null;
    $payload = ['match_id' => $match_id, 'winner_name' => $winner_name];
    if ($teamA) $payload['team_a_name'] = $teamA;
    if ($teamB) $payload['team_b_name'] = $teamB;
    if (!empty($committee)) $payload['committee'] = $committee;
    $emit = json_encode(['type' => 'new_match', 'match_id' => $match_id, 'sport' => 'badminton', 'payload' => $payload]);
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

echo json_encode(['success' => true, 'message' => "$winner_name declared as winner."]); 
exit;
