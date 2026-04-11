<?php
// get_match.php
header('Content-Type: application/json');
require_once 'db_config.php';

$match_id = intval($_GET['match_id'] ?? 0);
if (!$match_id) {
    echo json_encode(['success' => false, 'message' => 'match_id required']);
    exit;
}

// Match row
$mstmt = $conn->prepare("SELECT * FROM matches WHERE id=?");
$mstmt->bind_param('i', $match_id);
$mstmt->execute();
$match = $mstmt->get_result()->fetch_assoc();
$mstmt->close();

if (!$match) {
    echo json_encode(['success' => false, 'message' => 'Match not found']);
    exit;
}

// Summary
$sstmt = $conn->prepare("SELECT * FROM match_summary WHERE match_id=?");
$sstmt->bind_param('i', $match_id);
$sstmt->execute();
$summary = $sstmt->get_result()->fetch_assoc();
$sstmt->close();

// Players
$pstmt = $conn->prepare("SELECT * FROM players WHERE match_id=? ORDER BY player_number");
$pstmt->bind_param('i', $match_id);
$pstmt->execute();
$players_res = $pstmt->get_result();
$players = [];
while ($row = $players_res->fetch_assoc()) {
    $players[$row['id']] = $row;
}
$pstmt->close();

// Legs + throws
$lstmt = $conn->prepare("SELECT * FROM legs WHERE match_id=? ORDER BY leg_number");
$lstmt->bind_param('i', $match_id);
$lstmt->execute();
$legs_res = $lstmt->get_result();
$legs = [];
while ($leg = $legs_res->fetch_assoc()) {
    $lid = $leg['id'];
    // Get throws for this leg
    $tstmt = $conn->prepare(
        "SELECT * FROM throws WHERE leg_id=? ORDER BY player_id, throw_number"
    );
    $tstmt->bind_param('i', $lid);
    $tstmt->execute();
    $throws_res = $tstmt->get_result();
    $throws_by_player = [];
    while ($t = $throws_res->fetch_assoc()) {
        $throws_by_player[$t['player_id']][] = $t;
    }
    $tstmt->close();
    $leg['throws'] = $throws_by_player;
    $legs[] = $leg;
}
$lstmt->close();

echo json_encode([
    'success' => true,
    'match'   => $match,
    'summary' => $summary,
    'players' => array_values($players),
    'legs'    => $legs,
]);