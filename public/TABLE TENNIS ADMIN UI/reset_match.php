<?php
require_once 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit; }

$match_id = isset($data['match_id']) ? intval($data['match_id']) : 0;
if (!$match_id) { echo json_encode(['success' => false, 'message' => 'match_id required']); exit; }

$stmt = $mysqli->prepare("UPDATE table_tennis_matches SET status='reset', winner_name=NULL WHERE id=?");
$stmt->bind_param('i', $match_id);
if (!$stmt->execute()) { http_response_code(500); echo json_encode(['success' => false, 'message' => $stmt->error]); exit; }
$stmt->close();

$stmt = $mysqli->prepare("DELETE FROM table_tennis_sets WHERE match_id=?");
$stmt->bind_param('i', $match_id);
if (!$stmt->execute()) { http_response_code(500); echo json_encode(['success' => false, 'message' => $stmt->error]); exit; }
$stmt->close();

$stmt = $mysqli->prepare("DELETE FROM table_tennis_match_summary WHERE match_id=?");
$stmt->bind_param('i', $match_id);
if (!$stmt->execute()) { http_response_code(500); echo json_encode(['success' => false, 'message' => $stmt->error]); exit; }
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Match reset. All set records cleared.']);
exit;
