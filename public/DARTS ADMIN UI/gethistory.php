<?php
// get_history.php
header('Content-Type: application/json');
require_once 'db_config.php';

$sql = "
  SELECT m.id, m.game_type, m.legs_to_win, m.mode, m.status, m.winner_name, m.created_at,
         ms.total_legs, ms.player1_legs_won, ms.player2_legs_won, ms.player3_legs_won, ms.player4_legs_won
  FROM matches m
  LEFT JOIN match_summary ms ON ms.match_id = m.id
  ORDER BY m.created_at DESC
";

$res = $conn->query($sql);
$matches = [];
while ($row = $res->fetch_assoc()) {
    $matches[] = $row;
}

echo json_encode($matches);