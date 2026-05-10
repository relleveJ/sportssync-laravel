<?php
// state.php - MISSING - creating to match other sports pattern
require_once 'db_config.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

// Always support legacy wrapper input
$raw = $GLOBALS['__LEGACY_INPUT_JSON'] ?? file_get_contents('php://input');
$data = $raw ? json_decode($raw, true) : null;

// Early debug: record incoming headers and cookies for POST attempts
$logPath = defined('LARAVEL_WRAPPER') ? storage_path('logs/legacy/tabletennis_debug.log') : __DIR__ . '/tabletennis_debug.log';
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "STATE REQUEST HEADERS: " . print_r(getallheaders(), true) . "\n", FILE_APPEND);
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "STATE COOKIES: " . print_r($_COOKIE, true) . "\n", FILE_APPEND);
@file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "STATE RAW INPUT: " . substr($raw ?: '', 0, 2000) . "\n", FILE_APPEND);

$method = $_SERVER['REQUEST_METHOD'];
$match_id = $data['match_id'] ?? $_GET['match_id'] ?? null;

if ($method === 'GET' || !$data) {
    // Viewer read: no auth required
    if ($match_id) {
        $stmt = $mysqli->prepare("SELECT state_json FROM table_tennis_match_state WHERE match_id = ?");
        $stmt->bind_param('s', $match_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        echo $row ? $row['state_json'] : '{}';
    } else {
        // Latest state
        $result = $mysqli->query("SELECT state_json FROM table_tennis_match_state ORDER BY updated_at DESC LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        echo $row ? $row['state_json'] : '{}';
    }
    exit;
}

// POST - admin write: require auth
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

try {
    $poster = currentUser();
} catch (Throwable $_) {
    $poster = null;
}
$allowed = ['admin'];
if (!$poster || !in_array($poster['role'] ?? '', $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$state_json = json_encode($data);
$stmt = $mysqli->prepare("INSERT INTO table_tennis_match_state (match_id, state_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = NOW()");
$stmt->bind_param('ss', $match_id, $state_json);
$stmt->execute();
// Notify WS relay so connected clients receive this update immediately
try {
    $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
    $wsToken = getenv('WS_TOKEN') ?: null;
    $emit = json_encode([
        'type' => 'tabletennis_state',
        'match_id' => $match_id,
        'payload' => $data,
        'sport' => 'tabletennis'
    ], JSON_UNESCAPED_UNICODE);
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
echo json_encode(['success' => true]);
exit;
?>

