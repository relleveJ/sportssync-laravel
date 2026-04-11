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

echo json_encode(['success' => true, 'message' => "$winner_name declared as winner."]); 
exit;
