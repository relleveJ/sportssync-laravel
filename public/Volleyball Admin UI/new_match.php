<?php
// new_match.php — create a fresh volleyball match_id and initialize canonical SSOT state
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
header('Content-Type: application/json; charset=utf-8');
@ob_start();

function respondJson(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    @ob_end_clean();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respondError(string $message, int $status = 500): void {
    respondJson(['success' => false, 'error' => $message], $status);
}

set_exception_handler(function ($e) {
    respondError($e instanceof Throwable ? $e->getMessage() : 'Unknown server error', 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'error' => 'Server shutdown: ' . ($error['message'] ?? 'unknown error')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

require_once __DIR__ . '/../auth.php';

$user = currentUser();
if (!$user) {
    respondError('Authentication required', 403);
}

$allowed = ['admin', 'scorekeeper', 'superadmin'];
if (!in_array($user['role'] ?? '', $allowed, true)) {
    respondError('Permission denied', 403);
}

require_once __DIR__ . '/../db.php';
if (!isset($pdo) || !$pdo) {
    respondError('Database unavailable', 500);
}

try {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO volleyball_matches
                 (team_a_name, team_b_name,
                  team_a_score, team_b_score,
                  team_a_timeout, team_b_timeout,
                  current_set, match_result,
                  committee, owner_user_id)
             VALUES
                 (:team_a_name, :team_b_name,
                  0, 0,
                  0, 0,
                  1, :match_result,
                  :committee, :owner_user_id)'
        );
        $stmt->execute([
            ':team_a_name' => 'TEAM A',
            ':team_b_name' => 'TEAM B',
            ':match_result' => 'ONGOING',
            ':committee' => '',
            ':owner_user_id' => $user['id'] ?? null,
        ]);
        $matchId = (int)$pdo->lastInsertId();
    } catch (Throwable $_e) {
        $stmt2 = $pdo->prepare('INSERT INTO volleyball_matches (team_a_name, team_b_name, owner_user_id) VALUES (:a, :b, :o)');
        $stmt2->execute([':a' => 'TEAM A', ':b' => 'TEAM B', ':o' => $user['id'] ?? null]);
        $matchId = (int)$pdo->lastInsertId();
    }

    $payload = [
        'teamA' => [
            'name' => 'TEAM A',
            'score' => 0,
            'timeout' => 0,
            'set' => 1,
            'lineup' => array_fill(0, 6, null),
            'players' => []
        ],
        'teamB' => [
            'name' => 'TEAM B',
            'score' => 0,
            'timeout' => 0,
            'set' => 1,
            'lineup' => array_fill(0, 6, null),
            'players' => []
        ],
        'shared' => ['set' => 1],
        'committee' => '',
        '_ssot_ts' => (int)(microtime(true) * 1000)
    ];

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS draft_match_states (
                match_id INT PRIMARY KEY,
                payload LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st = $pdo->prepare('INSERT INTO draft_match_states (match_id, payload, updated_at) VALUES (:id, :payload, NOW()) ON DUPLICATE KEY UPDATE payload = :payload_upd, updated_at = NOW()');
        $st->execute([':id' => $matchId, ':payload' => $json, ':payload_upd' => $json]);
    } catch (Throwable $_) {
        // non-fatal
    }

    try {
        $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
        $wsToken = getenv('WS_TOKEN') ?: null;
        $emit = json_encode([
            'type' => 'new_match',
            'sport' => 'volleyball',
            'match_id' => $matchId,
            'payload' => $payload,
            'ts' => (int)(microtime(true) * 1000)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init($wsRelay);
        $headers = ['Content-Type: application/json'];
        if ($wsToken) {
            $headers[] = 'X-WS-Token: ' . $wsToken;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $emit);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
        @curl_exec($ch);
        @curl_close($ch);
    } catch (Throwable $_) {
        // ignore
    }

    respondJson(['success' => true, 'match_id' => $matchId, 'payload' => $payload]);
} catch (Throwable $e) {
    respondError($e->getMessage(), 500);
}
