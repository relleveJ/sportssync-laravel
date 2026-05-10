<?php
// volleyball/state.php
// GET  ?match_id=N -> returns { success:true, payload: { ... } }
// POST { match_id:N, payload:{...} } -> saves payload to draft_match_states and pending file
// This table is live draft storage, not the finalized match archive.

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
// Use request origin instead of wildcard so credentials (session cookies) work correctly.
// Wildcard + credentials is rejected by browsers and causes 403 on credentialed fetches.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
// Allow custom headers for identity fallback (X-SS-UID / X-SS-ROLE)
header('Access-Control-Allow-Headers: Content-Type, X-SS-UID, X-SS-ROLE');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

$pendingFile = __DIR__ . '/volleyball_pending_state.json';

// Bring in auth helpers. `auth.php` will safely attempt to include DB
// so we avoid requiring `db.php` directly (which would throw on connect).
require_once __DIR__ . '/../auth.php';

// Simple debug logger to help diagnose missing cookies/headers during auth.
function _vb_debug_log($tag, $data = null) {
    $path = __DIR__ . '/../../storage/logs/volleyball_state_debug.log';
    $entry = '[' . date('c') . '] ' . ($tag ?? '') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '-') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-') . ' ';
    try {
        $entry .= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $_) {
        $entry .= var_export($data, true);
    }
    $entry .= PHP_EOL;
    @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
}

// optional current user — allow unauthenticated reads; require auth for writes
$user = null;
try { $user = currentUser(); } catch (Throwable $_) { $user = null; }
_vb_debug_log('pre-auth', ['user' => $user, 'cookies' => $_COOKIE, 'headers' => ['X-SS-UID' => $_SERVER['HTTP_X_SS_UID'] ?? null, 'X-SS-ROLE' => $_SERVER['HTTP_X_SS_ROLE'] ?? null, 'ORIGIN' => $_SERVER['HTTP_ORIGIN'] ?? null]]);

// Ensure database connection is available for persisted server state.
try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $_) {
    // DB may be unavailable; continue with pending-file fallback only.
}

// Ensure database connection is available for persisted server state.
try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $_) {
    // DB may be unavailable; continue with pending-file fallback only.
}

// Ensure database connection is available for persisted server state.
try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $_) {
    // DB may be unavailable; continue with pending-file fallback only.
}

// Fallback: accept identity headers from proxied/admin page when cookies/session
// are not available (helps clients that were served outside the Laravel proxy).
// Primary: if DB available, validate the header ID against the users table.
// Secondary (development-friendly): if DB is not available but headers are
// present, accept a header-provided role as a temporary fallback so admin
// POSTs from proxied pages can succeed. This fallback is intentionally
// permissive and should only be used in local/dev environments.
try {
    if (!$user && !empty($_SERVER['HTTP_X_SS_UID'])) {
        $hid = intval($_SERVER['HTTP_X_SS_UID']);
        if ($hid > 0) {
            $found = false;

            // If we have a PDO instance, try validating the user against the DB.
            if (isset($pdo)) {
                try {
                    $st = $pdo->prepare('SELECT id, username, email, role, display_name, is_active FROM users WHERE id = ? LIMIT 1');
                    $st->execute([$hid]);
                    $u = $st->fetch(PDO::FETCH_ASSOC);
                    if ($u && ($u['is_active'] ?? 0)) {
                        $user = $u;
                        $found = true;
                    }
                } catch (Throwable $_) { /* ignore DB lookup errors */ }
            }

            // If DB lookup didn't produce a user, and a role header is present,
            // accept the header-provided identity as a fallback (dev-only).
            if (!$found) {
                $roleHeader = $_SERVER['HTTP_X_SS_ROLE'] ?? null;
                if ($roleHeader) {
                    $roleHeader = strtolower(trim((string)$roleHeader));
                    $allowedRoles = ['viewer','scorekeeper','admin','superadmin'];
                    if (in_array($roleHeader, $allowedRoles, true)) {
                        $user = [
                            'id' => $hid,
                            'username' => 'header-fallback',
                            'email' => '',
                            'role' => $roleHeader,
                            'display_name' => 'Header Fallback',
                            'is_active' => 1,
                        ];
                        _vb_debug_log('header-fallback-used', ['hid' => $hid, 'role' => $roleHeader]);
                    }
                }
            }
        }
    }
} catch (Throwable $_) { }
_vb_debug_log('post-fallback-auth', ['user' => $user, 'cookies' => $_COOKIE, 'http_x_ss_uid' => $_SERVER['HTTP_X_SS_UID'] ?? null, 'http_x_ss_role' => $_SERVER['HTTP_X_SS_ROLE'] ?? null, 'origin' => $_SERVER['HTTP_ORIGIN'] ?? null]);

// Helper to safe-echo JSON
function ok($payload, $extra = []) {
    $out = array_merge(['success' => true, 'payload' => $payload], $extra);
    echo json_encode($out);
    exit;
}

function _readPendingFile($pendingFile, $matchIdFilter = null) {
    if (!file_exists($pendingFile)) return null;
    $raw = @file_get_contents($pendingFile);
    if (!$raw) return null;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return null;
    if (isset($decoded['payload']) && isset($decoded['match_id'])) {
        $pmid = (int)$decoded['match_id'];
        if ($matchIdFilter !== null && $matchIdFilter > 0 && $pmid !== 0 && $pmid !== $matchIdFilter) return null;
        return ['payload' => $decoded['payload'], 'updated_at' => $decoded['updated_at'] ?? null, 'match_id' => $pmid];
    }
    // Raw legacy format — no match_id filter possible, return for any match
    return ['payload' => $decoded, 'updated_at' => null, 'match_id' => 0];
}

function _mergeRosterPlayers($incoming, $dbPlayers) {
    $combined = [];
    $seen = [];
    if (is_array($incoming)) {
        foreach ($incoming as $item) {
            if (!is_array($item)) continue;
            $key = null;
            if (!empty($item['id'])) {
                $key = 'id:' . (string)$item['id'];
            } elseif (isset($item['no']) && isset($item['name'])) {
                $key = 'key:' . trim((string)$item['no']) . '|' . trim((string)$item['name']);
            }
            if ($key !== null) {
                $seen[$key] = true;
            }
            $combined[] = $item;
        }
    }
    foreach ($dbPlayers as $item) {
        if (!is_array($item)) continue;
        $key = null;
        if (!empty($item['id'])) {
            $key = 'id:' . (string)$item['id'];
        } elseif (isset($item['no']) && isset($item['name'])) {
            $key = 'key:' . trim((string)$item['no']) . '|' . trim((string)$item['name']);
        }
        if ($key !== null && isset($seen[$key])) {
            continue;
        }
        $combined[] = $item;
    }
    return $combined;
}

function _ensureRosterPayload($payload, $matchId) {
    if (!is_array($payload)) return $payload;
    $needsA = !isset($payload['teamA']['players']) || !is_array($payload['teamA']['players']) || count($payload['teamA']['players']) < 6;
    $needsB = !isset($payload['teamB']['players']) || !is_array($payload['teamB']['players']) || count($payload['teamB']['players']) < 6;
    if (!$needsA && !$needsB) return $payload;
    if (!isset($pdo) || !$pdo || $matchId <= 0) return $payload;
    try {
        $st = $pdo->prepare('SELECT team, jersey_no, player_name, pts, spike, ace, ex_set, ex_dig, blk FROM volleyball_players WHERE match_id = :id ORDER BY team ASC, id ASC');
        $st->execute([':id' => $matchId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $teamA = [];
        $teamB = [];
        foreach ($rows as $p) {
            $team = ($p['team'] ?? '') === 'A' ? 'A' : 'B';
            $obj = [
                'id'    => ($team === 'A' ? 'A' : 'B') . '_db_' . (count($teamA) + count($teamB) + 1),
                'no'    => $p['jersey_no'] ?? '',
                'name'  => $p['player_name'] ?? '',
                'pts'   => isset($p['pts']) ? (int)$p['pts'] : 0,
                'spike' => isset($p['spike']) ? (int)$p['spike'] : 0,
                'ace'   => isset($p['ace']) ? (int)$p['ace'] : 0,
                'exSet' => isset($p['ex_set']) ? (int)$p['ex_set'] : 0,
                'exDig' => isset($p['ex_dig']) ? (int)$p['ex_dig'] : 0,
                'blk'   => isset($p['blk']) ? (int)$p['blk'] : 0,
            ];
            if ($team === 'A') $teamA[] = $obj;
            else $teamB[] = $obj;
        }
        if (!isset($payload['teamA']) || !is_array($payload['teamA'])) $payload['teamA'] = [];
        if (!isset($payload['teamB']) || !is_array($payload['teamB'])) $payload['teamB'] = [];
        if (!is_array($payload['teamA']['players'])) $payload['teamA']['players'] = [];
        if (!is_array($payload['teamB']['players'])) $payload['teamB']['players'] = [];
        $payload['teamA']['players'] = _mergeRosterPlayers($payload['teamA']['players'], $teamA);
        $payload['teamB']['players'] = _mergeRosterPlayers($payload['teamB']['players'], $teamB);
        if ($needsA && !is_array($payload['teamA']['players'])) $payload['teamA']['players'] = $teamA;
        if ($needsB && !is_array($payload['teamB']['players'])) $payload['teamB']['players'] = $teamB;
    } catch (Throwable $_) {
        // ignore roster fallback failures
    }
    return $payload;
}

function _broadcastVolleyballState($matchId, $payload) {
    try {
        if (!is_array($payload)) return;
        $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
        $wsToken = getenv('WS_TOKEN') ?: null;
        $emit = json_encode([
            'type' => 'room_state',
            'match_id' => $matchId,
            'payload' => ['volleyball' => $payload]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    } catch (Throwable $_) {
        // non-fatal
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mid = isset($_GET['match_id']) ? (int) $_GET['match_id'] : 0;

    // Helper: read and parse the pending file, return [payload, updated_at] or null
    if ($mid <= 0) {
        $dbPayload = null; $dbUpdatedAt = null; $dbMatchId = 0;
        if (isset($pdo) && $pdo) {
            try {
                // include match_id so clients can learn the authoritative match identifier
                $st = $pdo->query("SELECT match_id, payload, updated_at FROM draft_match_states ORDER BY updated_at DESC LIMIT 1");
            if ($st) {
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty($r['payload'])) {
                    $dbPayload = json_decode($r['payload'], true);
                    $dbUpdatedAt = $r['updated_at'] ?? null;
                    $dbMatchId = isset($r['match_id']) ? (int)$r['match_id'] : 0;
                }
            }
        } catch (Throwable $_) {}
        }

        $pendingData = _readPendingFile($pendingFile);
        $pendingPayload = $pendingData ? $pendingData['payload'] : null;
        $pendingUpdatedAt = $pendingData ? $pendingData['updated_at'] : null;

        // Return the newer of the two sources
            if ($dbPayload && $pendingPayload) {
            $dbTs = $dbUpdatedAt ? strtotime($dbUpdatedAt) : 0;
            $pendingTs = $pendingUpdatedAt ? strtotime($pendingUpdatedAt) : 0;
            // If pending file has _ssot_ts, use it for comparison (ms precision)
            $pendingSsotTs = isset($pendingPayload['_ssot_ts']) ? (int)($pendingPayload['_ssot_ts'] / 1000) : $pendingTs;
            $dbSsotTs = isset($dbPayload['_ssot_ts']) ? (int)($dbPayload['_ssot_ts'] / 1000) : $dbTs;
            if ($pendingSsotTs >= $dbSsotTs) {
                ok(_ensureRosterPayload($pendingPayload, $pendingData['match_id'] ?? 0), ['updated_at' => $pendingUpdatedAt, 'match_id' => ($pendingData['match_id'] ?? 0)]);
            } else {
                ok(_ensureRosterPayload($dbPayload, $dbMatchId), ['updated_at' => $dbUpdatedAt, 'match_id' => ($dbMatchId ?? 0)]);
            }
        } elseif ($pendingPayload) {
            ok(_ensureRosterPayload($pendingPayload, $pendingData['match_id'] ?? 0), ['updated_at' => $pendingUpdatedAt, 'match_id' => ($pendingData['match_id'] ?? 0)]);
        } elseif ($dbPayload) {
            ok(_ensureRosterPayload($dbPayload, $dbMatchId), ['updated_at' => $dbUpdatedAt, 'match_id' => ($dbMatchId ?? 0)]);
        }

        ok(null);
    }

    // (no ownership check for reads) allow public viewers to fetch match state

    // Fetch both draft_match_states DB row and pending file, return the newer one
    $dbPayload = null; $dbUpdatedAt = null;
    if (isset($pdo) && $pdo) {
        try {
            $st = $pdo->prepare('SELECT payload, updated_at FROM draft_match_states WHERE match_id = :id LIMIT 1');
            $st->execute([':id' => $mid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && !empty($r['payload'])) {
                $dbPayload = json_decode($r['payload'], true);
                $dbUpdatedAt = $r['updated_at'] ?? null;
            }
        } catch (Throwable $_) {}
    }

    $pendingData = _readPendingFile($pendingFile, $mid);
    $pendingPayload = $pendingData ? $pendingData['payload'] : null;
    $pendingUpdatedAt = $pendingData ? $pendingData['updated_at'] : null;

    // Compare and return the newer source
    if ($dbPayload && $pendingPayload) {
        $dbTs = $dbUpdatedAt ? strtotime($dbUpdatedAt) : 0;
        $pendingTs = $pendingUpdatedAt ? strtotime($pendingUpdatedAt) : 0;
        $pendingSsotTs = isset($pendingPayload['_ssot_ts']) ? (int)($pendingPayload['_ssot_ts'] / 1000) : $pendingTs;
        $dbSsotTs = isset($dbPayload['_ssot_ts']) ? (int)($dbPayload['_ssot_ts'] / 1000) : $dbTs;
        if ($pendingSsotTs >= $dbSsotTs) {
            ok(_ensureRosterPayload($pendingPayload, $pendingData['match_id'] ?? $mid), ['updated_at' => $pendingUpdatedAt, 'match_id' => ($pendingData['match_id'] ?? $mid)]);
        } else {
            ok(_ensureRosterPayload($dbPayload, $dbMatchId), ['updated_at' => $dbUpdatedAt, 'match_id' => $mid]);
        }
    } elseif ($pendingPayload) {
        ok(_ensureRosterPayload($pendingPayload, $pendingData['match_id'] ?? $mid), ['updated_at' => $pendingUpdatedAt, 'match_id' => ($pendingData['match_id'] ?? $mid)]);
    } elseif ($dbPayload) {
        ok(_ensureRosterPayload($dbPayload, $dbMatchId), ['updated_at' => $dbUpdatedAt, 'match_id' => $mid]);
    }

    // Fallback 2: assemble payload from volleyball_matches + volleyball_players
    if (!isset($pdo) || !$pdo) { ok(null); }
    try {
        $st = $pdo->prepare('SELECT team_a_name, team_b_name, team_a_score, team_b_score, team_a_timeout, team_b_timeout, current_set, committee FROM volleyball_matches WHERE match_id = :id LIMIT 1');
        $st->execute([':id' => $mid]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) { ok(null); }

        $st2 = $pdo->prepare('SELECT team, jersey_no, player_name, pts, spike, ace, ex_set, ex_dig FROM volleyball_players WHERE match_id = :id ORDER BY id ASC');
        $st2->execute([':id' => $mid]);
        $players = $st2->fetchAll(PDO::FETCH_ASSOC);

        $teamA = [];
        $teamB = [];
        $playerSeq = 0;
        foreach ($players as $p) {
            $playerSeq++;
            $team = ($p['team'] ?? '') === 'A' ? 'A' : 'B';
            $obj = [
                'id'    => $team . '_db_' . $playerSeq,   // stable id for viewer row-cache
                'no'    => $p['jersey_no'] ?? '',
                'name'  => $p['player_name'] ?? '',
                'pts'   => isset($p['pts']) ? (int)$p['pts'] : 0,
                'spike' => isset($p['spike']) ? (int)$p['spike'] : 0,
                'ace'   => isset($p['ace']) ? (int)$p['ace'] : 0,
                'exSet' => isset($p['ex_set']) ? (int)$p['ex_set'] : 0,
                'exDig' => isset($p['ex_dig']) ? (int)$p['ex_dig'] : 0,
            ];
            if ($team === 'A') $teamA[] = $obj; else $teamB[] = $obj;
        }

        $payload = [
            'teamA' => [
                'name'    => $m['team_a_name'] ?? 'TEAM A',
                'score'   => (int)($m['team_a_score'] ?? 0),
                'timeout' => (int)($m['team_a_timeout'] ?? 0),
                'set'     => (int)($m['current_set'] ?? 1),
                'lineup'  => array_fill(0, 6, null),
                'players' => $teamA,
            ],
            'teamB' => [
                'name'    => $m['team_b_name'] ?? 'TEAM B',
                'score'   => (int)($m['team_b_score'] ?? 0),
                'timeout' => (int)($m['team_b_timeout'] ?? 0),
                'set'     => (int)($m['current_set'] ?? 1),
                'lineup'  => array_fill(0, 6, null),
                'players' => $teamB,
            ],
            'shared' => ['set' => (int)($m['current_set'] ?? 1)],
            'committee' => $m['committee'] ?? ''
        ];

        ok($payload, ['match_id' => $mid]);
    } catch (Throwable $_) {
        ok(null);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || !array_key_exists('payload', $data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid body']);
        exit;
    }

    $mid = isset($data['match_id']) ? (int)$data['match_id'] : 0;
    $payload = $data['payload'];
    $broadcastPayload = $payload;
    if (isset($data['action']) && $data['action'] === 'lock_in_players') {
        $hasActiveLineup = function ($team) {
            if (!is_array($team)) return false;
            if (isset($team['lineupPlayers']) && is_array($team['lineupPlayers'])) {
                foreach ($team['lineupPlayers'] as $slot) {
                    if (is_array($slot) && (!empty($slot['id']) || (isset($slot['name']) && trim((string)$slot['name']) !== ''))) {
                        return true;
                    }
                }
            }
            if (isset($team['lineup']) && is_array($team['lineup'])) {
                foreach ($team['lineup'] as $slot) {
                    if ($slot !== null && $slot !== '') {
                        return true;
                    }
                }
            }
            return false;
        };
        if (!$hasActiveLineup($payload['teamA'] ?? []) || !$hasActiveLineup($payload['teamB'] ?? [])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please add players to Team A and Team B Active Lineup before locking players.']);
            exit;
        }
        if ($mid > 0) {
            $broadcastPayload = _ensureRosterPayload($payload, $mid);
            $payload = $broadcastPayload;
        }
    }
    // Only authenticated admins may publish live state (pending snapshot + optional DB persist)
    $poster = $user;
    $allowed = ['admin','scorekeeper','superadmin'];
    if (!$poster || !in_array($poster['role'] ?? '', $allowed, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
    try {
        $pendingWrapper = json_encode(
            ['match_id' => $mid, 'payload' => $payload, 'updated_at' => date('c')],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        @file_put_contents($pendingFile, $pendingWrapper, LOCK_EX);
        // Respond immediately to the client so UI actions are not blocked by
        // background persistence work (DB writes / WS relay). Continue to
        // persist asynchronously in this request after the response is sent.
        $early_response_sent = false;
        try {
            echo json_encode(['success' => true]);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                @ob_end_flush(); @flush();
            }
            $early_response_sent = true;
        } catch (Throwable $_) { /* ignore flush errors */ }
    } catch (Throwable $_) {}

    // Persist to DB only when an authenticated admin posts a match-specific state.
    // This keeps long-term storage restricted while still allowing live viewer updates.
    $poster = $user;
    $allowed = ['admin','scorekeeper','superadmin'];
    if ($mid > 0 && $poster && in_array($poster['role'] ?? '', $allowed, true)) {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS draft_match_states (
                    match_id INT PRIMARY KEY,
                    payload LONGTEXT NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $st = $pdo->prepare('INSERT INTO draft_match_states (match_id,payload,updated_at) VALUES (:id,:payload,NOW()) ON DUPLICATE KEY UPDATE payload = :payload, updated_at = NOW()');
            $st->execute([':id' => $mid, ':payload' => $json]);
        } catch (Throwable $_) { /* DB persist non-fatal — pending file remains */ }
    }

    // Try to notify a WS relay so connected viewers receive this update immediately.
    try {
        _broadcastVolleyballState($mid, $broadcastPayload);
    } catch (Throwable $_) { /* non-fatal */ }

    // If we already replied early, finish; otherwise return success now.
    if (!empty($early_response_sent)) {
        // Early response already sent; allow the script to finish background work silently.
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);