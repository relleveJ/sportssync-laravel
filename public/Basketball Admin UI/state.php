<?php
// state.php
// GET ?match_id=N -> returns JSON payload saved for match
// POST -> accepts JSON { match_id: N, payload: { ... } } and upserts to match_state

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../auth.php';

// API-style auth: return JSON 403 instead of redirect for unauthenticated requests
$user = currentUser();
if (!$user) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Authentication required']);
    exit;
}

// Ensure DB connection available for API
if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Database unavailable']);
    exit;
}

function bad($msg, $code = 400) { http_response_code($code); echo json_encode(['success'=>false,'error'=>$msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mid = isset($_GET['match_id']) ? (int) $_GET['match_id'] : 0;
    if ($mid <= 0) bad('missing match_id');
    // ownership check: only owner or admin can access state
    try {
        $stOwner = $pdo->prepare('SELECT owner_user_id FROM `matches` WHERE match_id = :id LIMIT 1');
        $stOwner->execute([':id' => $mid]);
        $rOwner = $stOwner->fetch(PDO::FETCH_ASSOC);
        $owner = $rOwner ? ($rOwner['owner_user_id'] ?? null) : null;
        if ($owner && $owner != ($user['id'] ?? null) && ($user['role'] ?? '') !== 'admin') bad('permission denied',403);
    } catch (Throwable $e) { /* ignore owner check failures */ }
    try {
        $st = $pdo->prepare('SELECT payload, updated_at FROM match_state WHERE match_id = :id LIMIT 1');
        $st->execute([':id'=>$mid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            echo json_encode(['success'=>true,'payload'=>null]);
            exit;
        }
        $payload = json_decode($r['payload'], true);
        echo json_encode(['success'=>true,'payload'=>$payload,'updated_at'=>$r['updated_at']]);
        exit;
    } catch (Exception $e) { bad('server error',500); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['match_id']) || !isset($data['payload'])) bad('invalid body');
    $mid = (int)$data['match_id'];
    if ($mid <= 0) bad('invalid match_id');
    // ownership check on POST
    try {
        $stOwner = $pdo->prepare('SELECT owner_user_id FROM `matches` WHERE match_id = :id LIMIT 1');
        $stOwner->execute([':id' => $mid]);
        $rOwner = $stOwner->fetch(PDO::FETCH_ASSOC);
        $owner = $rOwner ? ($rOwner['owner_user_id'] ?? null) : null;
        if ($owner && $owner != ($user['id'] ?? null) && ($user['role'] ?? '') !== 'admin') bad('permission denied',403);
    } catch (Throwable $e) { /* ignore */ }
    try {
        // ensure table exists
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS match_state (
                match_id INT PRIMARY KEY,
                payload LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $json = json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st = $pdo->prepare('INSERT INTO match_state (match_id,payload,updated_at) VALUES (:id,:payload,NOW()) ON DUPLICATE KEY UPDATE payload = :payload, updated_at = NOW()');
        $st->execute([':id'=>$mid, ':payload'=>$json]);
        echo json_encode(['success'=>true]);
        exit;
    } catch (Exception $e) { bad('db error: '.$e->getMessage(),500); }
}

http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']);
