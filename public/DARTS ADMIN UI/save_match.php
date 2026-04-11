<?php
header('Content-Type: application/json');
require_once 'db_config.php';
session_start();

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$match_id        = intval($data['match_id'] ?? 0);
$total_legs      = intval($data['total_legs'] ?? 0);
$legs_won        = $data['legs_won'] ?? [];
$winner_pid      = isset($data['winner_player_id']) ? intval($data['winner_player_id']) : null;
$winner_name     = $data['winner_name'] ?? null;

if (!$match_id) {
    echo json_encode(['success' => false, 'message' => 'match_id required']);
    exit;
}

// Update match status and updated_at
$stmt = $conn->prepare("UPDATE matches SET status='completed', winner_name=?, updated_at=NOW() WHERE id=?");
$stmt->bind_param('si', $winner_name, $match_id);
$stmt->execute();
$stmt->close();

// Insert or update match_summary
$p1 = intval($legs_won['p1'] ?? 0);
$p2 = intval($legs_won['p2'] ?? 0);
$p3 = intval($legs_won['p3'] ?? 0);
$p4 = intval($legs_won['p4'] ?? 0);

$stmt2 = $conn->prepare(
    "INSERT INTO match_summary
       (match_id, total_legs, player1_legs_won, player2_legs_won, player3_legs_won, player4_legs_won, winner_player_id)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       total_legs=VALUES(total_legs),
       player1_legs_won=VALUES(player1_legs_won),
       player2_legs_won=VALUES(player2_legs_won),
       player3_legs_won=VALUES(player3_legs_won),
       player4_legs_won=VALUES(player4_legs_won),
       winner_player_id=VALUES(winner_player_id)"
);
$stmt2->bind_param('iiiiiii', $match_id, $total_legs, $p1, $p2, $p3, $p4, $winner_pid);
$stmt2->execute();
$stmt2->close();

echo json_encode(['success' => true, 'message' => 'Match saved.']);