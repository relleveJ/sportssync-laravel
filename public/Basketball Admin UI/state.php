<?php
// state.php
// GET ?match_id=N -> returns JSON payload saved for match
// POST -> accepts JSON { match_id: N, payload: { ... } } and upserts to match_state

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../auth.php';

// Allow unauthenticated reads (GET). Require authentication and role for writes (POST).
function require_api_user_for_post() {
    try { $u = currentUser(); } catch (Throwable $_) { $u = null; }
    if (!$u) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Authentication required']);
        exit;
    }
    return $u;
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
    // Allow public viewers to fetch state (no ownership check for reads)
    try {
        // Always use match_states as canonical source of truth
        $stateTable = 'match_states';

        $st = $pdo->prepare("SELECT payload, updated_at FROM {$stateTable} WHERE match_id = :id LIMIT 1");
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
    // Require authenticated poster with proper role for writes
    $poster = require_api_user_for_post();
    $allowed = ['admin','scorekeeper','superadmin'];
    if (!in_array($poster['role'] ?? '', $allowed, true)) bad('permission denied',403);
    try {
        // ensure canonical match_states table exists (backwards-compatible schema)
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
        // default target table name - always use match_states as canonical source
        $stateTable = 'match_states';
        // Gentle guard: if the DB already contains a very recently-updated
        // canonical state (for example created by new_match.php), avoid
        // letting a slightly-stale client write flip stopped timers to
        // running. This prevents race conditions where a reset is shortly
        // followed by an older tick/persist that would restart timers.
        try {
            $sel = $pdo->prepare("SELECT payload, updated_at FROM {$stateTable} WHERE match_id = :id LIMIT 1");
            $sel->execute([':id' => $mid]);
            $cur = $sel->fetch(PDO::FETCH_ASSOC);
            if ($cur && !empty($cur['payload']) && !empty($cur['updated_at'])) {
                    $existingPayload = json_decode($cur['payload'], true);
                    $existingTs = strtotime($cur['updated_at']);
                    $now = time();
                    // If the DB row was updated within the last 5 seconds and
                    // its timers are stopped, don't allow an incoming write to
                    // flip running=false -> running=true (likely stale).
                    if ($existingTs && ($now - $existingTs) < 5) {
                        if (isset($existingPayload['gameTimer']['running']) && !$existingPayload['gameTimer']['running'] &&
                            isset($data['payload']['gameTimer']['running']) && $data['payload']['gameTimer']['running']) {
                            $data['payload']['gameTimer']['running'] = false;
                        }
                        if (isset($existingPayload['shotClock']['running']) && !$existingPayload['shotClock']['running'] &&
                            isset($data['payload']['shotClock']['running']) && $data['payload']['shotClock']['running']) {
                            $data['payload']['shotClock']['running'] = false;
                        }
                    }

                    // Protect against reloads or stale clients from turning a
                    // currently-running timer off. Only accept a flip true->false
                    // when the incoming request includes an explicit control
                    // (meta.control) indicating a user action (pause/reset/start).
                    $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : null;
                    // If canonical state shows running=true but incoming attempts
                    // to set running=false and there's no explicit control meta,
                    // preserve the running=true flag.
                    if (isset($existingPayload['gameTimer']['running']) && $existingPayload['gameTimer']['running']) {
                        if (isset($data['payload']['gameTimer']['running']) && !$data['payload']['gameTimer']['running']) {
                            if (empty($meta) || !isset($meta['control'])) {
                                $data['payload']['gameTimer']['running'] = true;
                            }
                        }
                    }
                    if (isset($existingPayload['shotClock']['running']) && $existingPayload['shotClock']['running']) {
                        if (isset($data['payload']['shotClock']['running']) && !$data['payload']['shotClock']['running']) {
                            if (empty($meta) || !isset($meta['control'])) {
                                $data['payload']['shotClock']['running'] = true;
                            }
                        }
                    }
                }
        } catch (Throwable $_) { /* non-fatal guard failure - continue */ }

        // Defensive merge: avoid overwriting roster/player lists when the
        // incoming request is an unload/timer-only update or otherwise
        // does not explicitly intend to modify the roster. Only allow
        // roster replacement when the client includes explicit meta
        // action 'roster_update'. This prevents a reloading/stale client
        // from wiping canonical player data.
        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
        // Treat explicit 'save_roster', legacy 'roster_update', or
        // an explicit 'reset_match' action as intent to replace the
        // canonical roster. All other writes should preserve existing
        // players to avoid accidental overwrites.
        $isRosterUpdate = isset($meta['action']) && in_array($meta['action'], ['roster_update','save_roster','reset_match'], true);
        // If not a roster update, attempt to preserve existing players
        if (!$isRosterUpdate) {
            try {
                $sel2 = $pdo->prepare("SELECT payload FROM {$stateTable} WHERE match_id = :id LIMIT 1");
                $sel2->execute([':id' => $mid]);
                $row2 = $sel2->fetch(PDO::FETCH_ASSOC);
                if ($row2 && !empty($row2['payload'])) {
                    $existing = json_decode($row2['payload'], true);
                    if (is_array($existing)) {
                        if ((!isset($data['payload']['teamA']['players']) || !is_array($data['payload']['teamA']['players']) || count($data['payload']['teamA']['players']) === 0)
                            && isset($existing['teamA']['players']) && is_array($existing['teamA']['players']) && count($existing['teamA']['players']) > 0) {
                            $data['payload']['teamA']['players'] = $existing['teamA']['players'];
                        }
                        if ((!isset($data['payload']['teamB']['players']) || !is_array($data['payload']['teamB']['players']) || count($data['payload']['teamB']['players']) === 0)
                            && isset($existing['teamB']['players']) && is_array($existing['teamB']['players']) && count($existing['teamB']['players']) > 0) {
                            $data['payload']['teamB']['players'] = $existing['teamB']['players'];
                        }
                    }
                }
            } catch (Throwable $_) { /* non-fatal - continue */ }
        }

        // Normalize timer fields when this POST is an explicit control
        // (start/pause/reset). This ensures the canonical `match_state`
        // row contains timestamped timer metadata that reloaders can
        // use to reconstruct a live-running timer even if timer.php
        // updates are missing.
        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
        $control = isset($meta['control']) ? strtolower(trim((string)$meta['control'])) : null;
        $nowMs = (int) round(microtime(true) * 1000);

        if ($control) {
            // Ensure sub-objects exist
            if (!isset($data['payload']['gameTimer']) || !is_array($data['payload']['gameTimer'])) $data['payload']['gameTimer'] = [];
            if (!isset($data['payload']['shotClock']) || !is_array($data['payload']['shotClock'])) $data['payload']['shotClock'] = [];

            // Helper to ensure remaining/total types
            $ensureRemaining = function (&$t) {
                if (!isset($t['remaining']) && isset($t['total'])) $t['remaining'] = (float)$t['total'];
                if (isset($t['remaining'])) $t['remaining'] = (float)$t['remaining'];
            };

            if ($control === 'start') {
                // Mark running and attach start timestamp so reloaders can
                // compute elapsed = now - ts and subtract from remaining.
                if (!empty($data['payload']['gameTimer']['running'])) {
                    $ensureRemaining($data['payload']['gameTimer']);
                    $data['payload']['gameTimer']['running'] = true;
                    $data['payload']['gameTimer']['ts'] = $nowMs;
                }
                if (!empty($data['payload']['shotClock']['running'])) {
                    $ensureRemaining($data['payload']['shotClock']);
                    $data['payload']['shotClock']['running'] = true;
                    $data['payload']['shotClock']['ts'] = $nowMs;
                }
            } elseif ($control === 'pause') {
                // Persist paused numeric values and clear ts so reloaders
                // treat the timer as stopped.
                $ensureRemaining($data['payload']['gameTimer']);
                $data['payload']['gameTimer']['running'] = false;
                $data['payload']['gameTimer']['ts'] = null;

                $ensureRemaining($data['payload']['shotClock']);
                $data['payload']['shotClock']['running'] = false;
                $data['payload']['shotClock']['ts'] = null;
            } elseif ($control === 'reset') {
                // Reset to totals and mark stopped
                if (isset($data['payload']['gameTimer']['total'])) {
                    $data['payload']['gameTimer']['remaining'] = (float)$data['payload']['gameTimer']['total'];
                } else {
                    $data['payload']['gameTimer']['remaining'] = isset($data['payload']['gameTimer']['remaining']) ? (float)$data['payload']['gameTimer']['remaining'] : 0.0;
                }
                $data['payload']['gameTimer']['running'] = false;
                $data['payload']['gameTimer']['ts'] = null;

                if (isset($data['payload']['shotClock']['total'])) {
                    $data['payload']['shotClock']['remaining'] = (float)$data['payload']['shotClock']['total'];
                } else {
                    $data['payload']['shotClock']['remaining'] = isset($data['payload']['shotClock']['remaining']) ? (float)$data['payload']['shotClock']['remaining'] : 0.0;
                }
                $data['payload']['shotClock']['running'] = false;
                $data['payload']['shotClock']['ts'] = null;
            }
        } else {
            // NOT an explicit control: preserve existing timer state from DB
            // so roster-only updates don't clobber running timers
            try {
                $sel3 = $pdo->prepare("SELECT payload FROM {$stateTable} WHERE match_id = :id LIMIT 1");
                $sel3->execute([':id' => $mid]);
                $row3 = $sel3->fetch(PDO::FETCH_ASSOC);
                if ($row3 && !empty($row3['payload'])) {
                    $existing = json_decode($row3['payload'], true);
                    if (is_array($existing)) {
                        // Preserve existing gameTimer/game_timer if not provided in incoming
                        $existingGameTimer = null;
                        if (isset($existing['gameTimer']) && is_array($existing['gameTimer'])) {
                            $existingGameTimer = $existing['gameTimer'];
                        } elseif (isset($existing['game_timer']) && is_array($existing['game_timer'])) {
                            $existingGameTimer = $existing['game_timer'];
                        }
                        if ((!isset($data['payload']['gameTimer']) || !is_array($data['payload']['gameTimer']) || count($data['payload']['gameTimer']) === 0)
                            && $existingGameTimer !== null) {
                            $data['payload']['gameTimer'] = $existingGameTimer;
                        }

                        // Preserve existing shotClock/shot_clock if not provided in incoming
                        $existingShotClock = null;
                        if (isset($existing['shotClock']) && is_array($existing['shotClock'])) {
                            $existingShotClock = $existing['shotClock'];
                        } elseif (isset($existing['shot_clock']) && is_array($existing['shot_clock'])) {
                            $existingShotClock = $existing['shot_clock'];
                        }
                        if ((!isset($data['payload']['shotClock']) || !is_array($data['payload']['shotClock']) || count($data['payload']['shotClock']) === 0)
                            && $existingShotClock !== null) {
                            $data['payload']['shotClock'] = $existingShotClock;
                        }
                    }
                }
            } catch (Throwable $_) { /* non-fatal */ }
        }

        // Build a canonical payload that includes snake_case timer objects
        // while preserving any legacy camelCase properties for compatibility.
        $payloadToPersist = is_array($data['payload']) ? $data['payload'] : [];

        // Helper: normalize incoming timer object into canonical structure
        $normTimer = function ($src, $defaultTotalSec = null) {
            $s = is_array($src) ? $src : [];
            $total_ms = null;
            if (isset($s['total_ms']) && is_numeric($s['total_ms'])) $total_ms = (int)$s['total_ms'];
            elseif (isset($s['total']) && is_numeric($s['total'])) $total_ms = (int)round(floatval($s['total']) * 1000.0);
            elseif (isset($s['duration']) && is_numeric($s['duration'])) $total_ms = (int)round(floatval($s['duration']) * 1000.0);
            elseif ($defaultTotalSec !== null) $total_ms = (int)round(floatval($defaultTotalSec) * 1000.0);

            $remaining_ms = null;
            if (isset($s['remaining_ms']) && is_numeric($s['remaining_ms'])) $remaining_ms = (int)round(floatval($s['remaining_ms']));
            elseif (isset($s['remaining']) && is_numeric($s['remaining'])) $remaining_ms = (int)round(floatval($s['remaining']) * 1000.0);
            elseif (isset($s['paused_remaining_ms']) && is_numeric($s['paused_remaining_ms'])) $remaining_ms = (int)round(floatval($s['paused_remaining_ms']));
            elseif (isset($s['paused_remaining']) && is_numeric($s['paused_remaining'])) $remaining_ms = (int)round(floatval($s['paused_remaining']) * 1000.0);
            else $remaining_ms = $total_ms !== null ? $total_ms : 0;

            $running = null;
            if (isset($s['is_running'])) $running = (bool)$s['is_running'];
            elseif (isset($s['running'])) $running = (bool)$s['running'];
            else $running = false;

            $start_ts = null;
            if (isset($s['start_timestamp']) && is_numeric($s['start_timestamp'])) $start_ts = (int)$s['start_timestamp'];
            elseif (isset($s['ts']) && is_numeric($s['ts'])) $start_ts = (int)$s['ts'];
            elseif (isset($s['last_started_at']) && is_numeric($s['last_started_at'])) $start_ts = (int)$s['last_started_at'];

            $out = [
                'total_ms' => $total_ms,
                'remaining_ms' => $remaining_ms,
                'running' => (bool)$running,
            ];
            if ($start_ts !== null) {
                $out['start_timestamp'] = (int)$start_ts;
                $out['last_started_at'] = (int)$start_ts;
            }
            // also include human-friendly seconds-based fields for legacy callers
            $out['total'] = $total_ms !== null ? ($total_ms / 1000.0) : null;
            $out['remaining'] = $remaining_ms !== null ? ($remaining_ms / 1000.0) : null;
            $out['is_running'] = (bool)$running;
            return $out;
        };

        // Normalize game timer and shot clock into canonical snake_case keys
        $gtSrc = isset($data['payload']['gameTimer']) ? $data['payload']['gameTimer'] : (isset($data['payload']['game_timer']) ? $data['payload']['game_timer'] : []);
        $scSrc = isset($data['payload']['shotClock']) ? $data['payload']['shotClock'] : (isset($data['payload']['shot_clock']) ? $data['payload']['shot_clock'] : []);
        $payloadToPersist['game_timer'] = $normTimer($gtSrc);
        $payloadToPersist['shot_clock'] = $normTimer($scSrc);

        // Preserve legacy camelCase timers for clients that expect them
        $payloadToPersist['gameTimer'] = isset($data['payload']['gameTimer']) ? $data['payload']['gameTimer'] : (isset($payloadToPersist['gameTimer']) ? $payloadToPersist['gameTimer'] : null);
        $payloadToPersist['shotClock'] = isset($data['payload']['shotClock']) ? $data['payload']['shotClock'] : (isset($payloadToPersist['shotClock']) ? $payloadToPersist['shotClock'] : null);

        $json = json_encode($payloadToPersist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $last_user = isset($poster['id']) ? (int)$poster['id'] : null;
        $last_role = isset($poster['role']) ? $poster['role'] : null;

        // Require confirmation for reset_match action
        if (isset($meta['action']) && $meta['action'] === 'reset_match') {
            if (!isset($data['confirmed']) || $data['confirmed'] !== true) {
                bad('Confirmation required for reset');
            }
        }

        $st = $pdo->prepare('INSERT INTO match_states (match_id,payload,last_user_id,last_role,created_at,updated_at) VALUES (:id,:payload,:last_user,:last_role,NOW(),NOW()) ON DUPLICATE KEY UPDATE payload = :payload_upd, last_user_id = :last_user_upd, last_role = :last_role_upd, updated_at = NOW()');
        $st->execute([':id'=>$mid, ':payload'=>$json, ':last_user' => $last_user, ':last_role' => $last_role, ':payload_upd' => $json, ':last_user_upd' => $last_user, ':last_role_upd' => $last_role]);
        // Notify ws-server relay (best-effort) so remote viewers/admins receive canonical state
        try {
            @require_once __DIR__ . '/../ws-server/ws_relay.php';
                if (function_exists('ss_ws_relay_notify_state')) {
                    $payloadArr = json_decode($json, true);
                    // attach poster metadata so ws relay can distribute actor info
                    $payloadArr['_meta'] = ['last_user_id' => $last_user, 'last_role' => $last_role];
                    // pass epoch milliseconds timestamp so ws-server computes elapsed correctly
                    ss_ws_relay_notify_state($mid, $payloadArr, (int)round(microtime(true) * 1000));
                }
        } catch (Throwable $_) { /* non-fatal */ }

        echo json_encode(['success'=>true]);
        exit;
    } catch (Exception $e) {
        // Log DB errors for server-side debugging but do not return 500
        // This prevents the client from entering an inconsistent state
        // when a transient DB error occurs (e.g. lock/packet/timeout).
        error_log('[state.php] DB upsert error: ' . $e->getMessage());
        // Return a safe 200-level response so the admin client does not
        // treat this as an internal server error and break its state.
        echo json_encode(['success' => false, 'error' => 'db error: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']);
