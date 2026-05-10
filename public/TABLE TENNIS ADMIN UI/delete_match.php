<?php
require_once 'db_config.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

// Require admin role for bulk deletion of matches
try { $user = requireLogin(); } catch (Throwable $_) { $user = null; }
if (!$user || !in_array(($user['role'] ?? ''), ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['match_ids']) || !is_array($data['match_ids'])) { echo json_encode(['success' => false, 'message' => 'match_ids array required']); exit; }
$ids = array_map('intval', $data['match_ids']); if (empty($ids)) { echo json_encode(['success'=>false,'message'=>'No match ids provided']); exit; }
try {
    $mysqli->begin_transaction();
    $stmt = $mysqli->prepare('DELETE FROM table_tennis_matches WHERE id = ?');
    if (!$stmt) throw new Exception($mysqli->error);
    foreach ($ids as $id) { $stmt->bind_param('i', $id); if (!$stmt->execute()) throw new Exception($stmt->error); }
    $stmt->close(); $mysqli->commit(); echo json_encode(['success'=>true,'deleted'=>count($ids)]); exit;
} catch (Exception $e) {
    $mysqli->rollback();
    $logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/tabletennis_debug.log') : __DIR__ . '/tabletennis_debug.log';
    @file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "delete_match error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
