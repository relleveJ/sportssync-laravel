<?php
// new_match.php — create a fresh match_id and initialize canonical SSOT state
header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';
try { $user = requireLogin(); } catch (Exception $e) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Authentication required']); exit; }
$allowed = ['admin','scorekeeper','superadmin'];
if (!in_array($user['role'] ?? '', $allowed, true)) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Permission denied']); exit; }

try {
    require_once __DIR__ . '/db.php';
    // Insert a minimal match row (owner assigned)
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO `matches`
                (team_a_name, team_b_name,
                 team_a_score, team_b_score,
                 team_a_foul, team_a_timeout, team_a_quarter,
                 team_b_foul, team_b_timeout, team_b_quarter,
                 match_result, committee, owner_user_id)
             VALUES
                (:team_a_name, :team_b_name,
                 0, 0,
                 0, 0, 1,
                 0, 0, 1,
                 :match_result, :committee, :owner_user_id)'
        );
        // Use empty string for match_result to avoid NOT NULL issues
        $stmt->execute([
            ':team_a_name' => 'TEAM A',
            ':team_b_name' => 'TEAM B',
            ':match_result' => '',
            ':committee' => '',
            ':owner_user_id' => $user['id'] ?? null
        ]);
        $matchId = (int) $pdo->lastInsertId();
    } catch (Exception $_e) {
        // Fallback: try a minimal insert with only essential columns
        try {
            $stmt2 = $pdo->prepare('INSERT INTO `matches` (team_a_name, team_b_name, owner_user_id) VALUES (:a,:b,:o)');
            $stmt2->execute([':a' => 'TEAM A', ':b' => 'TEAM B', ':o' => $user['id'] ?? null]);
            $matchId = (int) $pdo->lastInsertId();
        } catch (Exception $e2) {
            // rethrow original for outer catch
            throw $_e;
        }
    }

    // Build initial canonical payload
    $payload = [
        'teamA' => [ 'name' => 'TEAM A', 'score' => 0, 'foul' => 0, 'timeout' => 0, 'manualScore' => 0, 'quarter' => 1, 'players' => [] ],
        'teamB' => [ 'name' => 'TEAM B', 'score' => 0, 'foul' => 0, 'timeout' => 0, 'manualScore' => 0, 'quarter' => 1, 'players' => [] ],
        'shared' => ['quarter' => 1, 'foul' => 0, 'timeout' => 0],
        'committee' => '',
        'gameTimer' => ['remaining' => 600, 'total' => 600, 'running' => false, 'ts' => date('U')*1000],
        'shotClock' => ['remaining' => 24, 'total' => 24, 'running' => false, 'ts' => date('U')*1000],
        // Canonical snake_case timer objects (milliseconds)
        'game_timer' => [ 'total_ms' => 600 * 1000, 'remaining_ms' => 600 * 1000, 'running' => false, 'start_timestamp' => (int)date('U')*1000, 'last_started_at' => (int)date('U')*1000 ],
        'shot_clock' => [ 'total_ms' => 24 * 1000, 'remaining_ms' => 24 * 1000, 'running' => false, 'start_timestamp' => (int)date('U')*1000, 'last_started_at' => (int)date('U')*1000 ]
    ];

    // Upsert into canonical match_states table so canonical state is persisted
    $last_user = null;
    $last_role = null;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS match_states (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                match_id INT NOT NULL,
                payload LONGTEXT NOT NULL,
                last_user_id INT NULL,
                last_role VARCHAR(50) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_match (match_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_array($user) && array_key_exists('id', $user)) {
            $last_user = (int) $user['id'];
        }
        if (is_array($user) && array_key_exists('role', $user)) {
            $last_role = (string) $user['role'];
        }
        $st = $pdo->prepare('INSERT INTO match_states (match_id,payload,last_user_id,last_role,created_at,updated_at) VALUES (:id,:payload,:last_user,:last_role,NOW(),NOW()) ON DUPLICATE KEY UPDATE payload = :payload_upd, last_user_id = :last_user_upd, last_role = :last_role_upd, updated_at = NOW()');
        $st->execute([':id' => $matchId, ':payload' => $json, ':last_user' => $last_user, ':last_role' => $last_role, ':payload_upd' => $json, ':last_user_upd' => $last_user, ':last_role_upd' => $last_role]);
    } catch (Throwable $_) { /* non-fatal */ }

    // Notify WS relay about new match (best-effort)
    try {
        @require_once __DIR__ . '/../ws-server/ws_relay.php';
        if (function_exists('ss_ws_relay_emit')) {
            ss_ws_relay_emit(['type' => 'new_match', 'match_id' => $matchId, 'payload' => $payload, 'ts' => date('c')]);
        }
        if (function_exists('ss_ws_relay_notify_state')) {
            $payload['_meta'] = ['last_user_id' => $last_user, 'last_role' => $last_role];
            ss_ws_relay_notify_state($matchId, $payload, date('c'));
        }
    } catch (Throwable $_) { /* ignore */ }

    echo json_encode(['success' => true, 'match_id' => $matchId, 'payload' => $payload]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
