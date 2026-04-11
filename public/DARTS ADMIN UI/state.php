<?php
// state.php - read/write live match state for viewers
require_once 'db_config.php';
session_start();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $match_id = isset($data['match_id']) ? intval($data['match_id']) : null;
    $state = isset($data['state']) ? json_encode($data['state']) : null;

    if (!$match_id || !$state) {
        echo json_encode(['success' => false, 'message' => 'match_id and state required']);
        exit;
    }

    // Ensure columns exist (best-effort)
    $conn->query("ALTER TABLE matches ADD COLUMN IF NOT EXISTS live_state LONGTEXT NULL");
    $conn->query("ALTER TABLE matches ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL");

    $stmt = $conn->prepare("UPDATE matches SET live_state = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $state, $match_id);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// GET: optionally accept match_id, else latest ongoing
$match_id = isset($_GET['match_id']) ? intval($_GET['match_id']) : null;
if ($match_id) {
    $stmt = $conn->prepare('SELECT id, live_state FROM matches WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $match_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    echo json_encode($res ?: []);
    exit;
}

$res = $conn->query("SELECT id, live_state FROM matches WHERE status='ongoing' ORDER BY updated_at DESC LIMIT 1");
$row = $res ? $res->fetch_assoc() : null;
echo json_encode($row ?: []);
exit;
?>