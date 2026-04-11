<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BadmintonReportController extends Controller
{
    public function show(Request $request)
    {
        $matchId = (int) $request->query('match_id', 0);
        if ($matchId <= 0) {
            return response()->view('errors.generic', ['status' => 400, 'message' => 'Invalid or missing match_id parameter.'], 400);
        }

        // Open DB connection using new config (no side-effects during bootstrap)
        $cfg = config('db_badminton');
        if (empty($cfg) || !is_array($cfg)) {
            return response('Database configuration missing', 500);
        }
        $host = $cfg['host'] ?? '127.0.0.1';
        $db   = $cfg['database'] ?? ($cfg['name'] ?? 'sportssync');
        $user = $cfg['username'] ?? ($cfg['user'] ?? 'root');
        $pass = $cfg['password'] ?? ($cfg['pass'] ?? '');
        $mysqli = @new \mysqli($host, $user, $pass, $db);
        if ($mysqli->connect_errno) {
            @file_put_contents(base_path('storage/logs/badminton_db_error.log'), date('[Y-m-d H:i:s] ') . "DB connect error: " . $mysqli->connect_error . "\n", FILE_APPEND);
            return response('Database connection failed', 500);
        }
        $mysqli->set_charset('utf8mb4');

        // Fetch match
        $stmt = $mysqli->prepare('SELECT * FROM badminton_matches WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $match = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$match) {
            return response()->view('errors.generic', ['status' => 404, 'message' => "Match ID {$matchId} not found."], 404);
        }

        // Fetch sets
        $stmt = $mysqli->prepare('SELECT * FROM badminton_sets WHERE match_id = ? ORDER BY set_number ASC');
        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $sets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Normalize sets
        if (!empty($sets) && is_array($sets)) {
            $maxNum = 0;
            foreach ($sets as $s) {
                $n = isset($s['set_number']) ? (int)$s['set_number'] : 0;
                if ($n > $maxNum) $maxNum = $n;
            }
            $auto = $maxNum;
            $byNum = [];
            foreach ($sets as $s) {
                $sn = isset($s['set_number']) ? (int)$s['set_number'] : 0;
                if ($sn <= 0) { $auto++; $sn = $auto; }
                $s['set_number'] = $sn;
                $s['team_a_score'] = isset($s['team_a_score']) ? (int)$s['team_a_score'] : 0;
                $s['team_b_score'] = isset($s['team_b_score']) ? (int)$s['team_b_score'] : 0;
                $s['team_a_timeout_used'] = !empty($s['team_a_timeout_used']) ? 1 : 0;
                $s['team_b_timeout_used'] = !empty($s['team_b_timeout_used']) ? 1 : 0;
                $s['serving_team'] = ($s['serving_team'] ?? 'A') === 'B' ? 'B' : 'A';
                $s['set_winner'] = in_array($s['set_winner'] ?? null, ['A','B']) ? $s['set_winner'] : null;
                $byNum[$sn] = $s;
            }
            ksort($byNum, SORT_NUMERIC);
            $sets = array_values($byNum);
            @file_put_contents(base_path('public/Badminton Admin UI/badminton_debug.log'), date('[Y-m-d H:i:s] ') . "normalized sets for match {$matchId}: " . print_r($sets, true) . "\n", FILE_APPEND);
        }

        // Fetch summary
        $stmt = $mysqli->prepare('SELECT * FROM badminton_match_summary WHERE match_id = ? LIMIT 1');
        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Compute set wins
        $teamASetWins = 0;
        $teamBSetWins = 0;
        foreach ($sets as $s) {
            if (($s['set_winner'] ?? null) === 'A') $teamASetWins++;
            elseif (($s['set_winner'] ?? null) === 'B') $teamBSetWins++;
        }
        if ($summary) {
            $teamASetWins = (int)$summary['team_a_sets_won'];
            $teamBSetWins = (int)$summary['team_b_sets_won'];
        }

        // Determine overall winner
        $overallWinner = '';
        $matchStatus   = $match['status'] ?? 'ongoing';
        if ($summary && !empty($summary['winner_name'])) {
            $overallWinner = $summary['winner_name'];
        } elseif (!empty($match['winner_name'])) {
            $overallWinner = $match['winner_name'];
        } elseif ($teamASetWins !== $teamBSetWins) {
            $overallWinner = $teamASetWins > $teamBSetWins ? $match['team_a_name'] : $match['team_b_name'];
        }

        $totalSetsPlayed = count($sets);

        // Upsert summary (attempt to preserve original behavior)
        if (isset($mysqli) && $mysqli) {
            try {
                $winnerTeam = null;
                if ($overallWinner) { /* preserve legacy behaviour - no-op placeholder */ }

                $up = $mysqli->prepare("INSERT INTO badminton_match_summary (match_id, total_sets_played, team_a_sets_won, team_b_sets_won, winner_team, winner_name) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_sets_played=VALUES(total_sets_played), team_a_sets_won=VALUES(team_a_sets_won), team_b_sets_won=VALUES(team_b_sets_won), winner_team=VALUES(winner_team), winner_name=VALUES(winner_name), declared_at=CURRENT_TIMESTAMP");
                if ($up) {
                    $wt = $winnerTeam;
                    $up->bind_param('iiisss', $matchId, $totalSetsPlayed, $teamASetWins, $teamBSetWins, $wt, $overallWinner);
                    $up->execute();
                    $up->close();
                }

                if ($overallWinner) {
                    $mup = $mysqli->prepare('UPDATE badminton_matches SET winner_name = ?, status = ? WHERE id = ?');
                    if ($mup) {
                        $mup->bind_param('ssi', $overallWinner, $matchStatus, $matchId);
                        $mup->execute();
                        $mup->close();
                    }
                }
            } catch (\Throwable $e) {
                @file_put_contents(base_path('public/Badminton Admin UI/badminton_debug.log'), date('[Y-m-d H:i:s] ') . "summary upsert error for match {$matchId}: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }

        // Helpers
        $h = function(string $s) {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };

        $exportedAt  = date('F j, Y  •  g:i A', strtotime($match['created_at']));
        $committee   = ($match['committee_official'] ?? '') !== '' ? ($h)($match['committee_official']) : '';
        $teamAName   = ($h)($match['team_a_name'] ?? '');
        $teamBName   = ($h)($match['team_b_name'] ?? '');
        $matchType   = ($h)($match['match_type'] ?? '');
        $bestOf      = (int)($match['best_of'] ?? 0);

        // Players
        $playersA = [];
        $playersB = [];
        $type = strtolower($match['match_type'] ?? '');
        if ($type === 'singles') {
            $playersA[] = ['no' => 1, 'role' => 'Singles', 'name' => $match['team_a_player1'] ?? ''];
            $playersB[] = ['no' => 1, 'role' => 'Singles', 'name' => $match['team_b_player1'] ?? ''];
        } elseif ($type === 'doubles') {
            $playersA[] = ['no' => 1, 'role' => 'Player 1', 'name' => $match['team_a_player1'] ?? ''];
            $playersA[] = ['no' => 2, 'role' => 'Player 2', 'name' => $match['team_a_player2'] ?? ''];
            $playersB[] = ['no' => 1, 'role' => 'Player 1', 'name' => $match['team_b_player1'] ?? ''];
            $playersB[] = ['no' => 2, 'role' => 'Player 2', 'name' => $match['team_b_player2'] ?? ''];
        } else {
            $playersA[] = ['no' => 1, 'role' => 'Male Player',   'name' => $match['team_a_player1'] ?? ''];
            $playersA[] = ['no' => 2, 'role' => 'Female Player', 'name' => $match['team_a_player2'] ?? ''];
            $playersB[] = ['no' => 1, 'role' => 'Male Player',   'name' => $match['team_b_player1'] ?? ''];
            $playersB[] = ['no' => 2, 'role' => 'Female Player', 'name' => $match['team_b_player2'] ?? ''];
        }

        // Per-team aggregates
        $teamASetStr = '';
        $teamBSetStr = '';
        $teamATimeoutStr = '';
        $teamBTimeoutStr = '';
        $teamATotalPts = 0;
        $teamBTotalPts = 0;
        $teamATotalTO = 0;
        $teamBTotalTO = 0;
        $lastSetAScore = 0;
        $lastSetBScore = 0;
        foreach ($sets as $i => $s) {
            $sep = ($i < count($sets) - 1) ? ' | ' : '';
            $teamASetStr     .= 'Set' . ((int)$s['set_number']) . ': ' . ((int)$s['team_a_score']) . $sep;
            $teamBSetStr     .= 'Set' . ((int)$s['set_number']) . ': ' . ((int)$s['team_b_score']) . $sep;
            $teamATimeoutStr .= 'Set' . ((int)$s['set_number']) . ': ' . ((int)$s['team_a_timeout_used']) . $sep;
            $teamBTimeoutStr .= 'Set' . ((int)$s['set_number']) . ': ' . ((int)$s['team_b_timeout_used']) . $sep;
            $teamATotalPts   += (int)$s['team_a_score'];
            $teamBTotalPts   += (int)$s['team_b_score'];
            $teamATotalTO    += (int)$s['team_a_timeout_used'];
            $teamBTotalTO    += (int)$s['team_b_timeout_used'];
            $lastSetAScore    = (int)$s['team_a_score'];
            $lastSetBScore    = (int)$s['team_b_score'];
        }

        $currentSetNum  = count($sets) > 0 ? (int)$sets[count($sets)-1]['set_number'] : 1;
        $servingTeam    = count($sets) > 0 ? ($sets[count($sets)-1]['serving_team'] ?? 'A') : 'A';
        $servingName    = $servingTeam === 'A' ? $match['team_a_name'] : $match['team_b_name'];

        $matchData = [
            'match_id'      => $matchId,
            'saved_at'      => $match['created_at'],
            'committee'     => $match['committee_official'] ?? '',
            'match_type'    => $match['match_type'],
            'best_of'       => $bestOf,
            'team_a_name'   => $match['team_a_name'],
            'team_b_name'   => $match['team_b_name'],
            'team_a_sets_won' => $teamASetWins,
            'team_b_sets_won' => $teamBSetWins,
            'overall_winner'  => $overallWinner,
            'match_status'    => $matchStatus,
            'players_a'     => $playersA,
            'players_b'     => $playersB,
            'sets'          => $sets,
        ];

        // Pass data to the Blade view; use a PHP array and let Blade escape/encode safely
        return view('badminton.report', compact(
            'matchId','match','sets','summary','teamASetWins','teamBSetWins','overallWinner','matchStatus',
            'totalSetsPlayed','teamATotalPts','teamBTotalPts','exportedAt','committee','teamAName','teamBName',
            'matchType','bestOf','playersA','playersB','teamASetStr','teamBSetStr','teamATimeoutStr','teamBTimeoutStr',
            'teamATotalPts','teamBTotalPts','teamATotalTO','teamBTotalTO','lastSetAScore','lastSetBScore','currentSetNum',
            'servingTeam','servingName','matchData'
        ));
    }
}
