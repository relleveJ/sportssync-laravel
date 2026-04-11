<?php
// delete_match.php
// Accepts JSON POST { match_id: N } and deletes match and match_players rows.
// WARNING: This permanently deletes data. Protect this endpoint appropriately in production.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../auth.php';

// API-style auth: return JSON 403 instead of redirect for unauthenticated requests
$user = currentUser();
if (!$user) {
    http_response_code(403);
    echo json_encode([ 'success' => false, 'error' => 'Authentication required' ]);
    exit;
}

// Ensure DB connection available
if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode([ 'success' => false, 'error' => 'Database unavailable' ]);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([ 'success' => false, 'error' => 'Method not allowed' ]);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode([ 'success' => false, 'error' => 'Empty request' ]);
    exit;
}

$body = json_decode($raw, true);
if (!is_array($body) || empty($body['match_id'])) {
    http_response_code(400);
    echo json_encode([ 'success' => false, 'error' => 'Missing match_id' ]);
    exit;
}

$match_id = (int)$body['match_id'];
if ($match_id <= 0) {
    http_response_code(400);
    echo json_encode([ 'success' => false, 'error' => 'Invalid match_id' ]);
    exit;
}

try {
    // Verify ownership: only owner or admin may delete
    try {
        if (isset($pdo) && $pdo) {
            $stOwner = $pdo->prepare('SELECT owner_user_id FROM `matches` WHERE match_id = :id LIMIT 1');
            $stOwner->execute([':id' => $match_id]);
            $row = $stOwner->fetch();
            $owner = $row ? ($row['owner_user_id'] ?? null) : null;
            if ($owner && $owner != $user['id'] && ($user['role'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode([ 'success' => false, 'error' => 'Permission denied' ]);
                exit;
            }
        }
    } catch (Throwable $e) {
        // if column missing or error, fall through (best-effort)
    }
    $pdo->beginTransaction();
    // Delete players first
    $st1 = $pdo->prepare('DELETE FROM `match_players` WHERE match_id = :id');
    $st1->execute([':id' => $match_id]);
    // Delete match row
    $st2 = $pdo->prepare('DELETE FROM `matches` WHERE match_id = :id');
    $st2->execute([':id' => $match_id]);
    $pdo->commit();
    echo json_encode([ 'success' => true ]);
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode([ 'success' => false, 'error' => $e->getMessage() ]);
    exit;
}
