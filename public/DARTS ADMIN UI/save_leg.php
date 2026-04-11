<?php
header('Content-Type: application/json');
require_once 'db_config.php';
session_start();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$match_id    = isset($data['match_id']) ? intval($data['match_id']) : null;
$leg_number  = intval($data['leg_number'] ?? 1);
$is_completed = !empty($data['is_completed']) ? 1 : 0;
$players     = $data['players'] ?? [];
$game_type   = $data['game_type'] ?? '301';
$legs_to_win = intval($data['legs_to_win'] ?? 3);
$mode        = $data['mode'] ?? 'one-sided';
$session_id  = session_id();

// Ensure schema has helpful columns (best-effort, non-fatal)
try {
    $conn->query("ALTER TABLE matches ADD COLUMN IF NOT EXISTS owner_session VARCHAR(128) NULL");
    $conn->query("ALTER TABLE matches ADD COLUMN IF NOT EXISTS live_state LONGTEXT NULL");
    $conn->query("ALTER TABLE matches ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL");
} catch (Exception $e) {
    // ignore
}

// Use transaction to avoid partial writes
$conn->begin_transaction();
try {
    // Create match if new
    if (!$match_id) {
        $stmt = $conn->prepare(
            "INSERT INTO matches (game_type, legs_to_win, mode, status, owner_session, created_at, updated_at) VALUES (?, ?, ?, 'ongoing', ?, NOW(), NOW())"
        );
        $stmt->bind_param('siss', $game_type, $legs_to_win, $mode, $session_id);
        $stmt->execute();
        $match_id = $conn->insert_id;
        $stmt->close();

        // Insert players
        $player_ids = [];
        foreach ($players as $p) {
            $pnum  = intval($p['player_number'] ?? 1);
            $pname = $p['player_name'] ?? 'Player';
            $tname = $p['team_name'] ?? null;
            $save  = isset($p['save_enabled']) ? intval($p['save_enabled']) : 1;
            $stmt2 = $conn->prepare(
                "INSERT INTO players (match_id, player_number, player_name, team_name, save_enabled) VALUES (?,?,?,?,?)"
            );
            $stmt2->bind_param('iissi', $match_id, $pnum, $pname, $tname, $save);
            $stmt2->execute();
            $player_ids[$pnum] = $conn->insert_id;
            $stmt2->close();
        }
    } else {
        // Fetch existing player IDs for this match
        $player_ids = [];
        $res = $conn->query("SELECT id, player_number FROM players WHERE match_id=" . intval($match_id));
        while ($row = $res->fetch_assoc()) {
            $player_ids[$row['player_number']] = $row['id'];
        }
    }

    // Insert/get leg
    $leg_id = null;
    $check = $conn->prepare("SELECT id FROM legs WHERE match_id=? AND leg_number=?");
    $check->bind_param('ii', $match_id, $leg_number);
    $check->execute();
    $cres = $check->get_result()->fetch_assoc();
    $check->close();

    if ($cres) {
        $leg_id = $cres['id'];
    } else {
        $stmt3 = $conn->prepare("INSERT INTO legs (match_id, leg_number) VALUES (?,?)");
        $stmt3->bind_param('ii', $match_id, $leg_number);
        $stmt3->execute();
        $leg_id = $conn->insert_id;
        $stmt3->close();
    }

    // Insert throws
    foreach ($players as $p) {
        if (empty($p['save_enabled'])) continue;
        $pnum = intval($p['player_number'] ?? 1);
        $pid  = $player_ids[$pnum] ?? null;
        if (!$pid) continue;

        // Update player name/team if changed
        $pname = $p['player_name'] ?? 'Player';
        $tname = $p['team_name'] ?? null;
        $upd = $conn->prepare("UPDATE players SET player_name=?, team_name=? WHERE id=?");
        $upd->bind_param('ssi', $pname, $tname, $pid);
        $upd->execute();
        $upd->close();

        // Delete old throws for this leg+player before re-inserting
        $del = $conn->prepare("DELETE FROM throws WHERE leg_id=? AND player_id=?");
        $del->bind_param('ii', $leg_id, $pid);
        $del->execute();
        $del->close();

        $throws = $p['throws'] ?? [];
        foreach ($throws as $i => $t) {
            $throw_num   = $i + 1;
            $throw_val   = intval($t['throw_value']);
            $score_bef   = intval($t['score_before']);
            $score_aft   = intval($t['score_after']);
            $is_bust     = !empty($t['is_bust']) ? 1 : 0;
            $stmt4 = $conn->prepare(
                "INSERT INTO throws (leg_id, player_id, throw_number, throw_value, score_before, score_after, is_bust)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $stmt4->bind_param('iiiiiii', $leg_id, $pid, $throw_num, $throw_val, $score_bef, $score_aft, $is_bust);
            $stmt4->execute();
            $stmt4->close();
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
            $wstmt = $conn->prepare("UPDATE legs SET winner_player_id=? WHERE id=?");
            $wstmt->bind_param('ii', $winner_pid, $leg_id);
            $wstmt->execute();
            $wstmt->close();
        }
    }

    // Update match updated_at and live_state minimal payload
    $live = json_encode(['leg_number'=>$leg_number, 'players'=>$players, 'last_leg_id'=>$leg_id, 'updated_by'=>$session_id]);
    $ust = $conn->prepare("UPDATE matches SET updated_at=NOW(), live_state = ? WHERE id = ?");
    $ust->bind_param('si', $live, $match_id);
    $ust->execute();
    $ust->close();

    $conn->commit();

    echo json_encode([
        'success'    => true,
        'match_id'   => $match_id,
        'leg_id'     => $leg_id,
        'player_ids' => $player_ids,
        'message'    => 'Leg saved.'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success'=>false,'message'=>'Save failed','error'=>$e->getMessage()]);
}