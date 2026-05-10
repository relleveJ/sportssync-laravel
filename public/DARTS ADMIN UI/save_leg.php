<?php
header('Content-Type: application/json');
require_once 'db_config.php';
// Auth: require admin for write operations (legacy 'scorekeeper' mapped to 'admin')
require_once __DIR__ . '/../auth.php';
// suppress direct PHP warnings to keep JSON output clean; log errors instead
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
session_start();
// capture any accidental output and ensure we always return valid JSON
ob_start();

function json_exit($arr) {
    if (ob_get_length()) ob_clean();
    echo json_encode($arr);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Debug: append incoming raw payload and parsed result to a debug log (safe, reversible)
$logPath = __DIR__ . '/../../storage/logs/darts_save_leg_debug.log';
$logEntry = '[' . date('c') . '] IP=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' REQ=' . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
$logEntry .= "RAW:\n" . ($raw ?? '') . "\n";
$logEntry .= "PARSED:\n" . (@json_encode($data, JSON_UNESCAPED_SLASHES) ?: '(json encode failed)') . "\n\n";
@file_put_contents($logPath, $logEntry, FILE_APPEND);

if (!$data) {
    json_exit(['success' => false, 'message' => 'Invalid JSON']);
}

$allowedRoles = ['admin'];
// require authenticated admin
$poster = null;
try { $poster = currentUser(); } catch (Throwable $_) { $poster = null; }
$allowedRoles = ['admin'];
if (!$poster || !in_array($poster['role'] ?? '', $allowedRoles, true)) {
    json_exit(['success' => false, 'message' => 'Authentication required', 'error' => 'permission denied']);
}

$match_id    = isset($data['match_id']) ? intval($data['match_id']) : null;
$leg_number  = intval($data['leg_number'] ?? 1);
$is_completed = !empty($data['is_completed']) ? 1 : 0;
$players     = $data['players'] ?? [];
$game_type   = $data['game_type'] ?? '301';
$legs_to_win = intval($data['legs_to_win'] ?? 3);
$mode        = $data['mode'] ?? 'one-sided';
$session_id  = session_id();

// Detect whether the DB tables use a 'darts_' prefix (some installs) and set table names accordingly
$prefix = '';
$r = $conn->query("SHOW TABLES LIKE 'darts_matches'");
if ($r && $r->num_rows) $prefix = 'darts_';
$matchesTable = $prefix . 'matches';
$playersTable = $prefix . 'players';
$legsTable = $prefix . 'legs';
$throwsTable = $prefix . 'throws';
$summaryTable = $prefix . 'match_summary';
// Detect whether players table has save_enabled column
$colCheckPlayers = $conn->query("SHOW COLUMNS FROM `{$playersTable}` LIKE 'save_enabled'");
$hasSaveCol = ($colCheckPlayers && $colCheckPlayers->num_rows);


// Use transaction to avoid partial writes
$conn->begin_transaction();
try {
    // Create match if new — build INSERT using only columns present in the target table
    if (!$match_id) {
        $availableCols = [];
        $cres = $conn->query("SHOW COLUMNS FROM `{$matchesTable}`");
        while ($r = $cres->fetch_assoc()) {
            $availableCols[] = $r['Field'];
        }

        $insertCols = [];
        $placeholders = [];
        $bindVals = [];
        $bindTypes = '';

        if (in_array('game_type', $availableCols)) { $insertCols[] = 'game_type'; $placeholders[] = '?'; $bindVals[] = $game_type; $bindTypes .= 's'; }
        if (in_array('legs_to_win', $availableCols)) { $insertCols[] = 'legs_to_win'; $placeholders[] = '?'; $bindVals[] = $legs_to_win; $bindTypes .= 'i'; }
        if (in_array('mode', $availableCols)) { $insertCols[] = 'mode'; $placeholders[] = '?'; $bindVals[] = $mode; $bindTypes .= 's'; }
        if (in_array('status', $availableCols)) { $insertCols[] = 'status'; $placeholders[] = '?'; $bindVals[] = 'ongoing'; $bindTypes .= 's'; }
        if (in_array('owner_session', $availableCols)) { $insertCols[] = 'owner_session'; $placeholders[] = '?'; $bindVals[] = $session_id; $bindTypes .= 's'; }

        // use NOW() for timestamps if present
        if (in_array('created_at', $availableCols)) { $insertCols[] = 'created_at'; $placeholders[] = 'NOW()'; }
        if (in_array('updated_at', $availableCols)) { $insertCols[] = 'updated_at'; $placeholders[] = 'NOW()'; }

        if (count($insertCols) === 0) {
            // fallback: insert minimal row so we have a match record
            $sqlIns = "INSERT INTO `{$matchesTable}` (created_at, updated_at) VALUES (NOW(), NOW())";
            $conn->query($sqlIns);
            $match_id = $conn->insert_id;
        } else {
            $colsSql = implode(',', $insertCols);
            $valsSql = implode(',', $placeholders);
            $sqlIns = "INSERT INTO `{$matchesTable}` ($colsSql) VALUES ($valsSql)";
            $stmt = $conn->prepare($sqlIns);
            if ($stmt === false) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            if (strlen($bindTypes) > 0) {
                // bind_param requires references
                $refs = [];
                foreach ($bindVals as $k => $v) { $refs[$k] = &$bindVals[$k]; }
                array_unshift($refs, $bindTypes);
                call_user_func_array([$stmt, 'bind_param'], $refs);
            }
            $stmt->execute();
            $match_id = $conn->insert_id;
            $stmt->close();
        }

        // Set current match ID for all admins
        $currentMatchPath = __DIR__ . '/current_match_id.json';
        @file_put_contents($currentMatchPath, json_encode(['match_id' => $match_id]), LOCK_EX);

        // Insert players
        $player_ids = [];
        foreach ($players as $p) {
            $pnum  = intval($p['player_number'] ?? 1);
            $pname = $p['player_name'] ?? 'Player';
            $tname = $p['team_name'] ?? null;
            $save  = isset($p['save_enabled']) ? intval($p['save_enabled']) : 1;
            $sqlP = "INSERT INTO `{$playersTable}` (match_id, player_number, player_name, team_name, save_enabled) VALUES (?,?,?,?,?)";
            $stmt2 = $conn->prepare($sqlP);
            $stmt2->bind_param('iissi', $match_id, $pnum, $pname, $tname, $save);
            $stmt2->execute();
            $player_ids[$pnum] = $conn->insert_id;
            $stmt2->close();
        }
    } else {
        // Fetch existing player IDs for this match
        $player_ids = [];
        $res = $conn->query("SELECT id, player_number FROM `{$playersTable}` WHERE match_id=" . intval($match_id));
        while ($row = $res->fetch_assoc()) {
            $player_ids[$row['player_number']] = $row['id'];
        }
    }

    // Insert/get leg
    $leg_id = null;
        $check = $conn->prepare("SELECT id FROM `{$legsTable}` WHERE match_id=? AND leg_number=?");
    $check->bind_param('ii', $match_id, $leg_number);
    $check->execute();
    $cres = $check->get_result()->fetch_assoc();
    $check->close();

    if ($cres) {
        $leg_id = $cres['id'];
    } else {
            $stmt3 = $conn->prepare("INSERT INTO `{$legsTable}` (match_id, leg_number) VALUES (?,?)");
        $stmt3->bind_param('ii', $match_id, $leg_number);
        $stmt3->execute();
        $leg_id = $conn->insert_id;
        $stmt3->close();
    }

    // Insert/replace throws for each player present in the payload.
    foreach ($players as $p) {
        $pnum = intval($p['player_number'] ?? 1);
        $pid  = $player_ids[$pnum] ?? null;
        if (!$pid) continue;

        // Update player name/team and optionally save_enabled if the column exists
        $pname = $p['player_name'] ?? 'Player';
        $tname = $p['team_name'] ?? null;
        if ($hasSaveCol) {
            $saveFlag = isset($p['save_enabled']) ? intval($p['save_enabled']) : 1;
            $upd = $conn->prepare("UPDATE `{$playersTable}` SET player_name=?, team_name=?, save_enabled=? WHERE id=?");
            $upd->bind_param('ssii', $pname, $tname, $saveFlag, $pid);
        } else {
            $upd = $conn->prepare("UPDATE `{$playersTable}` SET player_name=?, team_name=? WHERE id=?");
            $upd->bind_param('ssi', $pname, $tname, $pid);
        }
        $upd->execute();
        $upd->close();

        $throwsPresent = array_key_exists('throws', $p);
        $throws = $throwsPresent ? $p['throws'] : null;
        // If the client explicitly included the `throws` key (even as an empty array),
        // treat that as an instruction to replace existing throws for that leg+player.
        // If the key is omitted entirely, preserve existing throws (no-op).
        if ($throwsPresent) {
            // Delete old throws for this leg+player before re-inserting (clears when empty)
            $del = $conn->prepare("DELETE FROM `{$throwsTable}` WHERE leg_id=? AND player_id=?");
            $del->bind_param('ii', $leg_id, $pid);
            $del->execute();
            $del->close();

            if (is_array($throws) && count($throws) > 0) {
                foreach ($throws as $i => $t) {
                    $throw_num   = $i + 1;
                    $throw_val   = intval($t['throw_value']);
                    $score_bef   = intval($t['score_before']);
                    $score_aft   = intval($t['score_after']);
                    $is_bust     = !empty($t['is_bust']) ? 1 : 0;
                    $stmt4 = $conn->prepare(
                        "INSERT INTO `{$throwsTable}` (leg_id, player_id, throw_number, throw_value, score_before, score_after, is_bust)
                         VALUES (?,?,?,?,?,?,?)"
                    );
                    $stmt4->bind_param('iiiiiii', $leg_id, $pid, $throw_num, $throw_val, $score_bef, $score_aft, $is_bust);
                    $stmt4->execute();
                    $stmt4->close();
                }
            }
        }
    }

    // If legs_history provided, apply winners for each recorded leg number
    $legs_history = $data['legs_history'] ?? null;
    if (is_array($legs_history) && count($legs_history) > 0) {
        // legs_history is expected to be an array of player_number values (1-based)
        foreach ($legs_history as $idx => $pnum) {
            $ln = intval($idx) + 1; // leg number
            $pnum = intval($pnum);
            if ($pnum <= 0) continue;
            $pid = $player_ids[$pnum] ?? null;
            if (!$pid) continue;
            // ensure leg exists for this match+leg_number
            $chk = $conn->prepare("SELECT id FROM `{$legsTable}` WHERE match_id=? AND leg_number=? LIMIT 1");
            $chk->bind_param('ii', $match_id, $ln);
            $chk->execute();
            $cres = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($cres) {
                $legid = intval($cres['id']);
                $up = $conn->prepare("UPDATE `{$legsTable}` SET winner_player_id=? WHERE id=?");
                $up->bind_param('ii', $pid, $legid);
                $up->execute();
                $up->close();
            } else {
                // create leg row then update
                $ins = $conn->prepare("INSERT INTO `{$legsTable}` (match_id, leg_number, winner_player_id) VALUES (?,?,?)");
                $ins->bind_param('iii', $match_id, $ln, $pid);
                $ins->execute();
                $ins->close();
            }
        }
    }

    // If leg completed, set winner
    if ($is_completed) {
        $winner_pid = null;
        foreach ($players as $p) {
            if (!empty($p['is_winner'])) {
                $pnum = intval($p['player_number'] ?? 1);
                $winner_pid = $player_ids[$pnum] ?? null;
                break;
            }
        }
        if ($winner_pid) {
                $wstmt = $conn->prepare("UPDATE `{$legsTable}` SET winner_player_id=? WHERE id=?");
            $wstmt->bind_param('ii', $winner_pid, $leg_id);
            $wstmt->execute();
            $wstmt->close();
        }
    }

    // Update match updated_at and live_state minimal payload
    // ✅ SSOT SAFE ADD START — preserve canonical live_state written by state.php
    // Read the existing live_state; if it is a full canonical payload (has gameType),
    // merge only the leg-level fields into it rather than overwriting with a stripped object.
    // This prevents save_leg.php from destroying the canonical state that the admin's
    // index.php already published via state.php (POST), which is the Single Source of Truth.
    $_existing_live_state = null;
    $_els = $conn->prepare("SELECT live_state FROM `{$matchesTable}` WHERE id=? LIMIT 1");
    $_els->bind_param('i', $match_id);
    $_els->execute();
    $_els_row = $_els->get_result()->fetch_assoc();
    $_els->close();
    if ($_els_row && !empty($_els_row['live_state'])) {
        $_existing = json_decode($_els_row['live_state'], true);
        if ($_existing && isset($_existing['gameType'])) {
            $_existing_live_state = $_existing;
        }
    }
    if ($_existing_live_state !== null) {
        // Merge only leg-specific fields; keep all canonical fields intact
        $_existing_live_state['currentLeg']    = $leg_number;
        $_existing_live_state['legs_history']  = $data['legs_history'] ?? ($_existing_live_state['legs_history'] ?? []);
        $_existing_live_state['updated_at']    = date('c');
        $live = json_encode($_existing_live_state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $live = json_encode(['leg_number'=>$leg_number, 'players'=>$players, 'last_leg_id'=>$leg_id, 'updated_by'=>$session_id, 'legs_history'=>($data['legs_history'] ?? [])]);
    }
    // ✅ SSOT SAFE ADD END
        $ust = $conn->prepare("UPDATE `{$matchesTable}` SET updated_at=NOW(), live_state = ? WHERE id = ?");
    $ust->bind_param('si', $live, $match_id);
    $ust->execute();
    $ust->close();

    // Update/insert match summary (if summary table exists)
    $haveSummary = false;
    $r = $conn->query("SHOW TABLES LIKE '{$summaryTable}'");
    if ($r && $r->num_rows) $haveSummary = true;

    if ($haveSummary) {
        // Compute totals
        $res = $conn->query("SELECT COUNT(*) AS c FROM `{$legsTable}` WHERE match_id=" . intval($match_id));
        $total_legs = ($res->fetch_assoc()['c']) ?? 0;

        // Compute winner (player with most legs)
        $lw = [];
        $lres = $conn->query("SELECT winner_player_id, COUNT(*) AS cnt FROM `{$legsTable}` WHERE match_id=" . intval($match_id) . " AND winner_player_id IS NOT NULL GROUP BY winner_player_id");
        while ($rr = $lres->fetch_assoc()) {
            $lw[intval($rr['winner_player_id'])] = intval($rr['cnt']);
        }
        // choose max
        $winner_player_id = null;
        if (count($lw)) {
            arsort($lw);
            $winner_player_id = intval(array_key_first($lw));
        }

        // Determine which columns exist in summary table
        $cols = [];
        $cres = $conn->query("SHOW COLUMNS FROM `{$summaryTable}`");
        while ($r = $cres->fetch_assoc()) $cols[] = $r['Field'];

        // Upsert minimal fields: total_legs, winner_player_id, match_id
        $sstmt = $conn->prepare("SELECT id FROM `{$summaryTable}` WHERE match_id=?");
        $sstmt->bind_param('i', $match_id);
        $sstmt->execute();
        $srow = $sstmt->get_result()->fetch_assoc();
        $sstmt->close();

        if ($srow) {
            $updates = [];
            $bind = [];
            $types = '';
            if (in_array('total_legs', $cols)) { $updates[] = 'total_legs=?'; $bind[] = $total_legs; $types .= 'i'; }
            if (in_array('winner_player_id', $cols)) { $updates[] = 'winner_player_id=?'; $bind[] = $winner_player_id; $types .= 'i'; }
            if (count($updates)) {
                $sql = "UPDATE `{$summaryTable}` SET " . implode(',', $updates) . " WHERE match_id=?";
                $stmtu = $conn->prepare($sql);
                if ($types !== '') {
                    $refs = [];
                    foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
                    $refs[] = &$match_id;
                    $types_full = $types . 'i';
                    array_unshift($refs, $types_full);
                    call_user_func_array([$stmtu, 'bind_param'], $refs);
                } else {
                    $stmtu->bind_param('i', $match_id);
                }
                $stmtu->execute();
                $stmtu->close();
            }
        } else {
            $insCols = ['match_id'];
            $insVals = ['?'];
            $bind = [$match_id];
            $types = 'i';
            if (in_array('total_legs', $cols)) { $insCols[] = 'total_legs'; $insVals[] = '?'; $bind[] = $total_legs; $types .= 'i'; }
            if (in_array('winner_player_id', $cols)) { $insCols[] = 'winner_player_id'; $insVals[] = '?'; $bind[] = $winner_player_id; $types .= 'i'; }
            $sql = "INSERT INTO `{$summaryTable}` (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")";
            $stins = $conn->prepare($sql);
            $refs = [];
            foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
            array_unshift($refs, $types);
            call_user_func_array([$stins, 'bind_param'], $refs);
            $stins->execute();
            $stins->close();
        }
    }

    $conn->commit();

    // Write a lightweight notify file so viewers on other devices (without ws-server)
    // can detect updates quickly and refetch state.php. This is a small HTTP fallback.
    try {
        $notifyPath = __DIR__ . '/darts_notify.json';
        @file_put_contents($notifyPath, json_encode(['match_id' => $match_id, 'ts' => time()]), LOCK_EX);
    } catch (Exception $e) { /* ignore */ }

    // Server-side emit to WS relay so viewers update immediately. Non-blocking-ish (short timeout).
    try {
        $wsRelay = getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000/emit';
        $wsToken = getenv('WS_TOKEN') ?: null;
        $payload = json_encode([
            'type' => 'new_match',
            'match_id' => $match_id,
            'sport' => 'darts',
            'payload' => ['match_id' => $match_id, 'leg_number' => $leg_number]
        ]);
        $ch = curl_init($wsRelay);
        $headers = ['Content-Type: application/json'];
        if ($wsToken) $headers[] = 'X-WS-Token: ' . $wsToken;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
        @curl_exec($ch);
        @curl_close($ch);
    } catch (Exception $_) {}

    json_exit([
        'success'    => true,
        'match_id'   => $match_id,
        'leg_id'     => $leg_id,
        'player_ids' => $player_ids,
        'message'    => 'Leg saved.'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    json_exit(['success'=>false,'message'=>'Save failed','error'=>$e->getMessage()]);
}
// fallback (shouldn't be reached)
json_exit(['success'=>false,'message'=>'Save failed']);