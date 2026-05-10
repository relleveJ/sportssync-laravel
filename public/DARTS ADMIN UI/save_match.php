<?php
header('Content-Type: application/json');
require_once 'db_config.php';
require_once __DIR__ . '/../auth.php';
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

// require authenticated admin (legacy 'scorekeeper' mapped to 'admin')
try { $poster = currentUser(); } catch (Throwable $_) { $poster = null; }
$allowed = ['admin'];
if (!$poster || !in_array($poster['role'] ?? '', $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// detect darts_ prefix and table names
$prefix = '';
$r = $conn->query("SHOW TABLES LIKE 'darts_matches'");
if ($r && $r->num_rows) $prefix = 'darts_';
$matchesTable = $prefix . 'matches';
$summaryTable = $prefix . 'match_summary';

// Update match status and updated_at
$ust = $conn->prepare("UPDATE `{$matchesTable}` SET status='completed', winner_name=?, updated_at=NOW() WHERE id=?");
$ust->bind_param('si', $winner_name, $match_id);
$ust->execute();
$ust->close();

// Insert or update match_summary
$p1 = intval($legs_won['p1'] ?? 0);
$p2 = intval($legs_won['p2'] ?? 0);
$p3 = intval($legs_won['p3'] ?? 0);
$p4 = intval($legs_won['p4'] ?? 0);

// Insert or update match_summary
$sql = "INSERT INTO `{$summaryTable}` (match_id, total_legs, player1_legs_won, player2_legs_won, player3_legs_won, player4_legs_won, winner_player_id)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                     total_legs=VALUES(total_legs),
                     player1_legs_won=VALUES(player1_legs_won),
                     player2_legs_won=VALUES(player2_legs_won),
                     player3_legs_won=VALUES(player3_legs_won),
                     player4_legs_won=VALUES(player4_legs_won),
                     winner_player_id=VALUES(winner_player_id)";
$stmt2 = $conn->prepare($sql);
$stmt2->bind_param('iiiiiii', $match_id, $total_legs, $p1, $p2, $p3, $p4, $winner_pid);
$stmt2->execute();
$stmt2->close();
// Return success and echo match id for client reference
if ($conn->errno) {
    echo json_encode(['success' => false, 'message' => 'DB error', 'error' => $conn->error]);
} else {
    // notify viewers via lightweight notify file
    try {
        $notifyPath = __DIR__ . '/darts_notify.json';
        @file_put_contents($notifyPath, json_encode(['match_id' => $match_id, 'ts' => time()]), LOCK_EX);
    } catch (Exception $e) {}
    // Set current match ID for all admins
    try {
        $currentMatchPath = __DIR__ . '/current_match_id.json';
        @file_put_contents($currentMatchPath, json_encode(['match_id' => $match_id]), LOCK_EX);
    } catch (Exception $e) {}
    // Try server-side emit to WS relay so viewers on other devices update immediately.
    try {
        $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
        $wsToken = getenv('WS_TOKEN') ?: null;
        $payload = json_encode([
            'type' => 'new_match',
            'match_id' => $match_id,
            'sport' => 'darts',
            'payload' => ['match_id' => $match_id, 'total_legs' => $total_legs]
        ]);
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
    } catch (Exception $_) {}

    echo json_encode(['success' => true, 'message' => 'Match saved.', 'match_id' => $match_id, 'total_legs' => $total_legs]);
}