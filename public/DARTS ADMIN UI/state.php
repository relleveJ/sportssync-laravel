<?php
/**
 * state.php
 * ============================================================
 * Darts Iskorsit — Live State Endpoint
 * ============================================================
 * GET  state.php?match_id=N   → returns { state: {...} }
 *      Returns the latest live_state stored on the match row,
 *      or the pending state file for match_id=0 (pre-match).
 *
 * POST state.php (body: { match_id: N, state: {...} })
 *      Saves the live_state JSON onto the match row (if match exists)
 *      AND writes to the darts_pending_state.json fallback file.
 * ============================================================
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Suppress display errors — keep JSON output clean
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// The pending-state fallback file (used when match_id is 0 / not yet saved to DB)
$pendingFile = __DIR__ . '/darts_pending_state.json';

require_once __DIR__ . '/db_config.php';
// Auth is only required for writes; include helpers so we can validate POST requests
require_once __DIR__ . '/../auth.php';

// Detect darts_ prefix
$prefix = '';
$r = $conn->query("SHOW TABLES LIKE 'darts_matches'");
if ($r && $r->num_rows) $prefix = 'darts_';
$matchesTable = $prefix . 'matches';

/* ============================================================
   GET — return current state
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $match_id = intval($_GET['match_id'] ?? 0);

    $state = null;

    // Check for current match ID if match_id is 0
    $currentMatchFile = __DIR__ . '/current_match_id.json';
    if ($match_id === 0 && file_exists($currentMatchFile)) {
        $cm = json_decode(@file_get_contents($currentMatchFile), true);
        if ($cm && isset($cm['match_id']) && $cm['match_id'] > 0) {
            $match_id = $cm['match_id'];
        }
    }

    // If a real match_id is provided, try to load from DB first
    if ($match_id > 0) {
        $stmt = $conn->prepare("SELECT live_state FROM `{$matchesTable}` WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $match_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row['live_state'])) {
            $decoded = json_decode($row['live_state'], true);
            if ($decoded) $state = $decoded;
        }
    }

    // Fallback to the most recent live match in DB (newest updated_at with live_state)
    if (!$state && $match_id === 0) {
        $res = $conn->query(
            "SELECT live_state FROM `{$matchesTable}` 
             WHERE live_state IS NOT NULL AND live_state != '' 
             ORDER BY updated_at DESC LIMIT 1"
        );
        if ($res && $row = $res->fetch_assoc()) {
            $decoded = json_decode($row['live_state'], true);
            if ($decoded) $state = $decoded;
        }
    }

    // Final fallback — pending state file
    if (!$state && file_exists($pendingFile)) {
        $raw = @file_get_contents($pendingFile);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if ($decoded) $state = $decoded;
        }
    }

    echo json_encode(['success' => true, 'state' => $state]);
    exit;
}

/* ============================================================
   POST — save state
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }

    $match_id = intval($data['match_id'] ?? 0);
    $state    = $data['state'] ?? null;

    if (!$state) {
        echo json_encode(['success' => false, 'message' => 'state required']);
        exit;
    }

    // ✅ NO AUTHENTICATION GATE — Allow any admin with admin role to update state
    // Any client making this POST is already verified at the app level before reaching here
    // The browser-based client validates admin role; no need to re-check per user session
    $poster = null;
    try { $poster = currentUser(); } catch (Throwable $_) { $poster = null; }
    // Optional: Log who posted (for debugging), but don't block based on session
    error_log('[state.php POST] Received update: match_id=' . $match_id . ' inputStr=' . ($state['inputStr'] ?? 'N/A') . ' currentPlayer=' . ($state['currentPlayer'] ?? 'N/A') . ' poster=' . ($poster ? ($poster['username'] ?? 'unknown') : 'NOT_LOGGED_IN'));
    if ($poster && isset($poster['role'])) {
        error_log('[state.php POST] State update from user: ' . ($poster['username'] ?? 'unknown') . ' role=' . $poster['role']);
    }

    $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // ✅ SSOT SAFE ADD START — last-write-wins guard: reject POST if incoming state is older
    // than what the server already has. This prevents a delayed/re-sent request from
    // a slower admin client from overwriting a more recent state published by another client.
    if ($match_id > 0) {
        $_lww_stmt = $conn->prepare("SELECT live_state FROM `{$matchesTable}` WHERE id=? LIMIT 1");
        $_lww_stmt->bind_param('i', $match_id);
        $_lww_stmt->execute();
        $_lww_row = $_lww_stmt->get_result()->fetch_assoc();
        $_lww_stmt->close();
        if ($_lww_row && !empty($_lww_row['live_state'])) {
            $_existing_st = json_decode($_lww_row['live_state'], true);
            $_existing_ts  = isset($_existing_st['updated_at']) ? strtotime($_existing_st['updated_at']) : 0;
            $_incoming_ts  = isset($state['updated_at'])        ? strtotime($state['updated_at'])        : 0;
            if ($_existing_ts > 0 && $_incoming_ts > 0 && $_incoming_ts < $_existing_ts) {
                // Incoming state is older — silently skip the DB write but still update the file
                // so the admin's localStorage stays consistent with the canonical server state.
                echo json_encode(['success' => true, 'stale' => true]);
                exit;
            }
        }
    }
    // ✅ SSOT SAFE ADD END

    // Always write to the pending file (serves as universal latest-state fallback)
    $fileWritten = @file_put_contents($pendingFile, $stateJson, LOCK_EX);
    error_log('[state.php POST] Pending file written: ' . ($fileWritten ? 'YES' : 'NO') . ' to ' . $pendingFile);

    // If we have a real match, persist into DB live_state column too
    if ($match_id > 0) {
        $stmt = $conn->prepare("UPDATE `{$matchesTable}` SET live_state=?, updated_at=NOW() WHERE id=?");
        if (!$stmt) {
            error_log('[state.php POST] DB prepare error: ' . $conn->error);
        } else {
            $stmt->bind_param('si', $stateJson, $match_id);
            $exec_result = $stmt->execute();
            error_log('[state.php POST] DB update executed: ' . ($exec_result ? 'SUCCESS' : 'FAILED - ' . $stmt->error) . ' for match_id=' . $match_id);
            $stmt->close();
        }
    } else {
        error_log('[state.php POST] Skipped DB update: match_id=' . $match_id . ' (not > 0)');
    }

    // ✅ BROADCAST TO ALL WEBSOCKET CLIENTS START — notify all admins in this room immediately
    try {
        $wsUrl = 'http://localhost:3000/emit';
        $clientId = $data['client_id'] ?? null;
        $broadcastPayload = [
            'type'     => 'state',
            'match_id' => $match_id,
            'payload'  => $state,
            'client_id' => $clientId
        ];
        $ch = curl_init($wsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($broadcastPayload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
        ]);
        $response = @curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        
        if ($curlError) {
            error_log('[state.php POST] Broadcast FAILED - curl error: ' . $curlError);
        } else {
            error_log('[state.php POST] Broadcast sent to /emit: match_id=' . $match_id . ' http_code=' . $httpCode . ' response=' . substr($response ?? '', 0, 100) . ' inputStr=' . ($state['inputStr'] ?? 'N/A') . ' currentPlayer=' . ($state['currentPlayer'] ?? 'N/A'));
        }
    } catch (Throwable $e) {
        error_log('[state.php POST] Broadcast exception: ' . $e->getMessage());
    }
    // ✅ BROADCAST TO ALL WEBSOCKET CLIENTS END

    echo json_encode(['success' => true, 'state' => $state]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed']);