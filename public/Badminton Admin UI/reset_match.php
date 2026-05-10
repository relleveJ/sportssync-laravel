<?php
require_once 'db_config.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

// Require authenticated admin to perform resets (legacy 'scorekeeper' mapped to 'admin')
try { $poster = currentUser(); } catch (Throwable $_) { $poster = null; }
$allowed = ['admin','scorekeeper','superadmin'];
if (!$poster || !in_array($poster['role'] ?? '', $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit; }

$match_id = isset($data['match_id']) ? intval($data['match_id']) : 0;
if (!$match_id) { echo json_encode(['success' => false, 'message' => 'match_id required']); exit; }

// Set match status to 'reset'
$stmt = $mysqli->prepare("UPDATE badminton_matches SET status='reset', winner_name=NULL WHERE id=?");
$stmt->bind_param('i', $match_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $stmt->error]);
    exit;
}
$stmt->close();

// Delete sets and summary for the match
$stmt = $mysqli->prepare("DELETE FROM badminton_sets WHERE match_id=?");
$stmt->bind_param('i', $match_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $stmt->error]);
    exit;
}
$stmt->close();

$stmt = $mysqli->prepare("DELETE FROM badminton_match_summary WHERE match_id=?");
$stmt->bind_param('i', $match_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $stmt->error]);
    exit;
}
$stmt->close();

// Clear live-state cache so viewers using state.php?latest=1 cannot reload stale pre-reset data.
$mysqli->query("
  CREATE TABLE IF NOT EXISTS badminton_match_state (
    match_id   VARCHAR(64) NOT NULL PRIMARY KEY,
    state_json MEDIUMTEXT  NOT NULL,
    updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$match_state_id = (string)$match_id;
$live_state_id = 'live';
$stmt = $mysqli->prepare("DELETE FROM badminton_match_state WHERE match_id IN (?, ?)");
$stmt->bind_param('ss', $match_state_id, $live_state_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $stmt->error]);
    exit;
}
$stmt->close();

// Best-effort: emit new_match so clients can reset to this match context
try {
    $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
    $wsToken = getenv('WS_TOKEN') ?: null;
    $emit = json_encode(['type' => 'new_match', 'match_id' => $match_id, 'sport' => 'badminton', 'payload' => ['match_id' => $match_id, '_reset' => true]]);
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

echo json_encode(['success' => true, 'message' => 'Match reset. All set records cleared.']);
exit;
