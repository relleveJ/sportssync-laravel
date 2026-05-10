<?php
// timer.php
// GET  ?match_id=N -> returns JSON timer payload for match
// POST { match_id, gameTimer, shotClock, meta? } -> upsert timer state

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../auth.php';

function require_api_user_for_post() {
    try { $u = currentUser(); } catch (Throwable $_) { $u = null; }
    if (!$u) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Authentication required']);
        exit;
    }
    return $u;
}

if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Database unavailable']);
    exit;
}

function bad($msg, $code = 400) { http_response_code($code); echo json_encode(['success'=>false,'error'=>$msg]); exit; }

// GET: return timer-only payload
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mid = isset($_GET['match_id']) ? (int) $_GET['match_id'] : 0;
    if ($mid <= 0) bad('missing match_id');
    try {
        $st = $pdo->prepare('SELECT * FROM match_timers WHERE match_id = :id LIMIT 1');
        $st->execute([':id' => $mid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            echo json_encode(['success'=>true,'payload'=>null]);
            exit;
        }
        // Convert database INT columns to float for client compatibility
        $payload = [
            'gameTimer' => [
                'total'     => isset($r['game_total']) ? (int)$r['game_total'] : 0,
                'remaining' => isset($r['game_remaining']) ? (float)$r['game_remaining'] : 0,
                'running'   => isset($r['game_running']) ? (bool)$r['game_running'] : false,
                'ts'        => isset($r['game_ts']) && $r['game_ts'] ? (int)$r['game_ts'] : null,
            ],
            'shotClock' => [
                'total'     => isset($r['shot_total']) ? (int)$r['shot_total'] : 0,
                'remaining' => isset($r['shot_remaining']) ? (float)$r['shot_remaining'] : 0,
                'running'   => isset($r['shot_running']) ? (bool)$r['shot_running'] : false,
                'ts'        => isset($r['shot_ts']) && $r['shot_ts'] ? (int)$r['shot_ts'] : null,
            ]
        ];
        echo json_encode(['success'=>true,'payload'=>$payload,'updated_at'=>$r['updated_at']]);
        exit;
    } catch (Exception $e) { 
        error_log('[timer.php] GET error: ' . $e->getMessage());
        bad('server error',500); 
    }
}

// POST: upsert timer fields and notify ws relay
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $poster = require_api_user_for_post();
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['match_id'])) bad('invalid body');
    $mid = (int)$data['match_id'];
    if ($mid <= 0) bad('invalid match_id');

    // ensure table exists
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS match_timers (
                match_id INT PRIMARY KEY,
                game_total INT DEFAULT 0,
                game_remaining DOUBLE DEFAULT 0,
                game_running TINYINT(1) DEFAULT 0,
                game_ts BIGINT DEFAULT NULL,
                shot_total INT DEFAULT 0,
                shot_remaining DOUBLE DEFAULT 0,
                shot_running TINYINT(1) DEFAULT 0,
                shot_ts BIGINT DEFAULT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) { /* non-fatal */ }

    // parse incoming timer objects
    $gt = isset($data['gameTimer']) && is_array($data['gameTimer']) ? $data['gameTimer'] : [];
    $sc = isset($data['shotClock']) && is_array($data['shotClock']) ? $data['shotClock'] : [];
    
    $gt_total = isset($gt['total']) && is_numeric($gt['total']) ? (int)$gt['total'] : 0;
    $gt_remaining = isset($gt['remaining']) && is_numeric($gt['remaining']) ? (int)round((float)$gt['remaining']) : 0;
    $gt_running = isset($gt['running']) ? ($gt['running'] ? 1 : 0) : 0;
    $gt_ts = null;
    if (isset($gt['ts']) && is_numeric($gt['ts'])) {
        $gt_ts = (int)$gt['ts'];
        if ($gt_ts === 0) $gt_ts = null;
    }

    $sc_total = isset($sc['total']) && is_numeric($sc['total']) ? (int)$sc['total'] : 0;
    $sc_remaining = isset($sc['remaining']) && is_numeric($sc['remaining']) ? (int)round((float)$sc['remaining']) : 0;
    $sc_running = isset($sc['running']) ? ($sc['running'] ? 1 : 0) : 0;
    $sc_ts = null;
    if (isset($sc['ts']) && is_numeric($sc['ts'])) {
        $sc_ts = (int)$sc['ts'];
        if ($sc_ts === 0) $sc_ts = null;
    }

    // Gentle guard similar to state.php to avoid stale clients flipping flags
    $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
    try {
        $sel = $pdo->prepare('SELECT * FROM match_timers WHERE match_id = :id LIMIT 1');
        $sel->execute([':id' => $mid]);
        $cur = $sel->fetch(PDO::FETCH_ASSOC);
        if ($cur) {
            $existingTs = !empty($cur['updated_at']) ? strtotime($cur['updated_at']) : null;
            $now = time();
            // if row was updated recently, avoid flipping stopped->running without explicit control
            if ($existingTs && ($now - $existingTs) < 5) {
                if (isset($cur['game_running']) && !$cur['game_running'] && $gt_running) {
                    if (empty($meta) || !isset($meta['control'])) $gt_running = 0;
                }
                if (isset($cur['shot_running']) && !$cur['shot_running'] && $sc_running) {
                    if (empty($meta) || !isset($meta['control'])) $sc_running = 0;
                }
            }

            $allowGameStopWithoutControl = false;
            if (isset($cur['game_running']) && $cur['game_running']) {
                if (!$gt_running) {
                    $currentRemaining = isset($cur['game_remaining']) ? (float)$cur['game_remaining'] : null;
                    $currentTotal = isset($cur['game_total']) ? (int)$cur['game_total'] : null;
                    $currentTs = isset($cur['game_ts']) && is_numeric($cur['game_ts']) ? (int)$cur['game_ts'] : null;
                    if ($gt_ts === null && $currentTotal !== null && $gt_total !== $currentTotal) {
                        $allowGameStopWithoutControl = true;
                    }
                    if ($gt_ts === null && $currentRemaining !== null && abs($gt_remaining - $currentRemaining) > 0.5) {
                        $allowGameStopWithoutControl = true;
                    }
                    if ($gt_ts === null && $currentRemaining !== null && $currentTs !== null) {
                        $expectedRemaining = max(0, $currentRemaining - max(0, ((microtime(true) * 1000) - $currentTs) / 1000));
                        if (abs($gt_remaining - $expectedRemaining) > 0.2) {
                            $allowGameStopWithoutControl = true;
                        }
                    }
                    if (empty($meta) || !isset($meta['control'])) {
                        if (!$allowGameStopWithoutControl) $gt_running = 1;
                    }
                }
            }

            $allowShotStopWithoutControl = false;
            if (isset($cur['shot_running']) && $cur['shot_running']) {
                if (!$sc_running) {
                    $currentRemaining = isset($cur['shot_remaining']) ? (float)$cur['shot_remaining'] : null;
                    $currentTotal = isset($cur['shot_total']) ? (int)$cur['shot_total'] : null;
                    if ($sc_ts === null && $currentTotal !== null && $sc_total !== $currentTotal) {
                        $allowShotStopWithoutControl = true;
                    }
                    if ($sc_ts === null && $currentRemaining !== null && abs($sc_remaining - $currentRemaining) > 0.5) {
                        $allowShotStopWithoutControl = true;
                    }
                    if (empty($meta) || !isset($meta['control'])) {
                        if (!$allowShotStopWithoutControl) $sc_running = 1;
                    }
                }
            }

            // Preserve numeric timer values (remaining/ts/total) for
            // currently-running timers when the incoming request is a
            // passive persist (no explicit meta.control). This avoids
            // a reloading/stale client from accidentally clobbering the
            // canonical remaining time while the server timer is active.
            if (isset($cur['game_running']) && $cur['game_running']) {
                if ((empty($meta) || !isset($meta['control'])) && !$allowGameStopWithoutControl) {
                    $gt_remaining = isset($cur['game_remaining']) ? (float)$cur['game_remaining'] : $gt_remaining;
                    $gt_ts = isset($cur['game_ts']) ? (int)$cur['game_ts'] : $gt_ts;
                    $gt_total = isset($cur['game_total']) ? (int)$cur['game_total'] : $gt_total;
                    $gt_running = 1;
                }
            }
            if (isset($cur['shot_running']) && $cur['shot_running']) {
                if ((empty($meta) || !isset($meta['control'])) && !$allowShotStopWithoutControl) {
                    $sc_remaining = isset($cur['shot_remaining']) ? (float)$cur['shot_remaining'] : $sc_remaining;
                    $sc_ts = isset($cur['shot_ts']) ? (int)$cur['shot_ts'] : $sc_ts;
                    $sc_total = isset($cur['shot_total']) ? (int)$cur['shot_total'] : $sc_total;
                    $sc_running = 1;
                }
            }
        }
    } catch (Throwable $_) { /* non-fatal */ }

    // Upsert into match_timers
    try {
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both insert and update
        // The table has columns: game_total, game_remaining, game_running, game_ts, shot_total, shot_remaining, shot_running, shot_ts, updated_at
        $sql = 'INSERT INTO match_timers 
                (match_id, game_total, game_remaining, game_running, game_ts, shot_total, shot_remaining, shot_running, shot_ts, updated_at) 
                VALUES (:mid, :game_total, :game_remaining, :game_running, :game_ts, :shot_total, :shot_remaining, :shot_running, :shot_ts, NOW()) 
                ON DUPLICATE KEY UPDATE 
                game_total = VALUES(game_total),
                game_remaining = VALUES(game_remaining),
                game_running = VALUES(game_running),
                game_ts = VALUES(game_ts),
                shot_total = VALUES(shot_total),
                shot_remaining = VALUES(shot_remaining),
                shot_running = VALUES(shot_running),
                shot_ts = VALUES(shot_ts),
                updated_at = NOW()';
        
        $st = $pdo->prepare($sql);
        $st->execute([
            ':mid' => $mid,
            ':game_total' => (int)$gt_total,
            ':game_remaining' => (int)$gt_remaining,
            ':game_running' => (int)$gt_running,
            ':game_ts' => $gt_ts === null ? null : (int)$gt_ts,
            ':shot_total' => (int)$sc_total,
            ':shot_remaining' => (int)$sc_remaining,
            ':shot_running' => (int)$sc_running,
            ':shot_ts' => $sc_ts === null ? null : (int)$sc_ts,
        ]);
    } catch (Exception $e) {
        error_log('[timer.php] DB upsert error: ' . $e->getMessage());
        error_log('[timer.php] SQL: ' . $sql);
        error_log('[timer.php] Values: match_id=' . $mid . ', gt_total=' . $gt_total . ', gt_remaining=' . $gt_remaining . ', gt_running=' . $gt_running . ', gt_ts=' . $gt_ts . ', sc_total=' . $sc_total . ', sc_remaining=' . $sc_remaining . ', sc_running=' . $sc_running . ', sc_ts=' . $sc_ts);
        $err_msg = 'db error: ' . substr($e->getMessage(), 0, 80);
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>$err_msg]);
        exit;
    }

    // Notify ws-server relay (best-effort) so remote viewers/admins receive timer update
    try {
        @require_once __DIR__ . '/../ws-server/ws_relay.php';
        if (function_exists('ss_ws_relay_notify_state')) {
            // Build payload including canonical snake_case timers (ms) plus legacy camelCase
            $payloadArr = [
                'gameTimer' => [ 'total' => $gt_total, 'remaining' => $gt_remaining, 'running' => (bool)$gt_running, 'ts' => $gt_ts ],
                'shotClock' => [ 'total' => $sc_total, 'remaining' => $sc_remaining, 'running' => (bool)$sc_running, 'ts' => $sc_ts ],
                'game_timer' => [ 'total_ms' => ($gt_total !== null ? ($gt_total * 1000) : null), 'remaining_ms' => (int)round($gt_remaining * 1000.0), 'running' => (bool)$gt_running, 'start_timestamp' => $gt_ts, 'last_started_at' => $gt_ts ],
                'shot_clock' => [ 'total_ms' => ($sc_total !== null ? ($sc_total * 1000) : null), 'remaining_ms' => (int)round($sc_remaining * 1000.0), 'running' => (bool)$sc_running, 'start_timestamp' => $sc_ts, 'last_started_at' => $sc_ts ]
            ];
            // Include control metadata so the client knows if this is an explicit control or passive tick
            $metaWithControl = $meta;
            if (empty($metaWithControl) || !isset($metaWithControl['control'])) {
                $metaWithControl = [ 'control' => null ];
            }
            ss_ws_relay_notify_state($mid, $payloadArr, max($gt_ts, $sc_ts, (int)round(microtime(true) * 1000)), $metaWithControl);
        } elseif (function_exists('ss_ws_relay_post')) {
            $out = [
                'type' => 'timer_update',
                'match_id' => $mid,
                'gameTimer' => [ 'total' => $gt_total, 'remaining' => $gt_remaining, 'running' => (bool)$gt_running, 'ts' => $gt_ts ],
                'shotClock' => [ 'total' => $sc_total, 'remaining' => $sc_remaining, 'running' => (bool)$sc_running, 'ts' => $sc_ts ],
                'ts' => max($gt_ts, $sc_ts, (int)round(microtime(true) * 1000)),
                'meta' => (empty($meta) ? [ 'control' => null ] : $meta)
            ];
            ss_ws_relay_post($out);
        }
    } catch (Throwable $_) { /* best-effort */ }

    try {
        $st3 = $pdo->prepare('SELECT * FROM match_timers WHERE match_id = :id LIMIT 1');
        $st3->execute([':id' => $mid]);
        $row = $st3->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $payload = [
                'gameTimer' => [
                    'total' => isset($row['game_total']) ? (int)$row['game_total'] : 0,
                    'remaining' => isset($row['game_remaining']) ? (float)$row['game_remaining'] : 0,
                    'running' => isset($row['game_running']) ? (bool)$row['game_running'] : false,
                    'ts' => isset($row['game_ts']) ? (int)$row['game_ts'] : null,
                ],
                'shotClock' => [
                    'total' => isset($row['shot_total']) ? (int)$row['shot_total'] : 0,
                    'remaining' => isset($row['shot_remaining']) ? (float)$row['shot_remaining'] : 0,
                    'running' => isset($row['shot_running']) ? (bool)$row['shot_running'] : false,
                    'ts' => isset($row['shot_ts']) ? (int)$row['shot_ts'] : null,
                ]
            ];
            echo json_encode(['success' => true, 'payload' => $payload, 'updated_at' => $row['updated_at']]);
            exit;
        }
    } catch (Throwable $_) { /* non-fatal */ }

    echo json_encode(['success'=>true, 'payload' => null]);
    exit;
}

http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']);
