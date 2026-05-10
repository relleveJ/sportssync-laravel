<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TableTennisWriteController extends Controller
{
    public function saveSet(Request $request)
    {
        $data = $request->json()->all();

        try {
            $result = DB::transaction(function () use ($data) {
                $matchId = isset($data['match_id']) && $data['match_id'] !== '' ? intval($data['match_id']) : null;
                $match_type = $this->normType($data['match_type'] ?? 'singles');
                $best_of = isset($data['best_of']) ? intval($data['best_of']) : 3;
                $team_a_name = $data['team_a_name'] ?? 'Team A';
                $team_b_name = $data['team_b_name'] ?? 'Team B';
                $ta_p1 = $data['team_a_player1'] ?? null;
                $ta_p2 = $data['team_a_player2'] ?? null;
                $tb_p1 = $data['team_b_player1'] ?? null;
                $tb_p2 = $data['team_b_player2'] ?? null;

                $committee = null;
                if (!empty($data['committee_official'])) $committee = trim($data['committee_official']);
                elseif (!empty($data['committee'])) $committee = trim($data['committee']);

                // Insert or update match
                if (empty($matchId)) {
                    $insert = [
                        'match_type' => $match_type,
                        'best_of' => $best_of,
                        'team_a_name' => $team_a_name,
                        'team_b_name' => $team_b_name,
                        'team_a_player1' => $ta_p1,
                        'team_a_player2' => $ta_p2,
                        'team_b_player1' => $tb_p1,
                        'team_b_player2' => $tb_p2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if ($committee !== null && Schema::hasColumn('table_tennis_matches', 'committee')) {
                        $insert['committee'] = $committee;
                    }
                    $matchId = DB::table('table_tennis_matches')->insertGetId($insert);
                } else {
                    $update = [
                        'match_type' => $match_type,
                        'best_of' => $best_of,
                        'team_a_name' => $team_a_name,
                        'team_b_name' => $team_b_name,
                        'team_a_player1' => $ta_p1,
                        'team_a_player2' => $ta_p2,
                        'team_b_player1' => $tb_p1,
                        'team_b_player2' => $tb_p2,
                        'updated_at' => now(),
                    ];
                    if ($committee !== null && Schema::hasColumn('table_tennis_matches', 'committee')) {
                        $update['committee'] = $committee;
                    }
                    DB::table('table_tennis_matches')->where('id', $matchId)->update($update);
                }

                $count = 0;
                // Replace sets if provided
                if (!empty($data['sets']) && is_array($data['sets'])) {
                    DB::table('table_tennis_sets')->where('match_id', $matchId)->delete();
                    $rows = [];
                    $useCommittee = Schema::hasColumn('table_tennis_sets', 'committee');
                    foreach ($data['sets'] as $s) {
                        $sn = isset($s['set_number']) ? intval($s['set_number']) : 1;
                        $ta = isset($s['team_a_score']) ? intval($s['team_a_score']) : 0;
                        $tb = isset($s['team_b_score']) ? intval($s['team_b_score']) : 0;
                        $ta_to = !empty($s['team_a_timeout_used']) ? 1 : 0;
                        $tb_to = !empty($s['team_b_timeout_used']) ? 1 : 0;
                        $serve = (isset($s['serving_team']) && $s['serving_team'] === 'B') ? 'B' : 'A';
                        $sw = isset($s['set_winner']) && in_array($s['set_winner'], ['A','B']) ? $s['set_winner'] : null;
                        $row = [
                            'match_id' => $matchId,
                            'set_number' => $sn,
                            'team_a_score' => $ta,
                            'team_b_score' => $tb,
                            'team_a_timeout_used' => $ta_to,
                            'team_b_timeout_used' => $tb_to,
                            'serving_team' => $serve,
                            'set_winner' => $sw,
                            'created_at' => now(),
                        ];
                        if ($useCommittee) $row['committee'] = $committee;
                        $rows[] = $row;
                        $count++;
                    }
                    if (!empty($rows)) DB::table('table_tennis_sets')->insert($rows);
                    return ['success' => true, 'match_id' => $matchId, 'message' => "{$count} sets saved."];
                }

                // Fallback: single set insert
                $set_number = isset($data['set_number']) ? intval($data['set_number']) : 1;
                $team_a_score = isset($data['team_a_score']) ? intval($data['team_a_score']) : 0;
                $team_b_score = isset($data['team_b_score']) ? intval($data['team_b_score']) : 0;
                $team_a_timeout_used = !empty($data['team_a_timeout_used']) ? 1 : 0;
                $team_b_timeout_used = !empty($data['team_b_timeout_used']) ? 1 : 0;
                $serving_team = (isset($data['serving_team']) && $data['serving_team'] === 'B') ? 'B' : 'A';
                $set_winner = isset($data['set_winner']) && in_array($data['set_winner'], ['A','B']) ? $data['set_winner'] : null;

                $row = [
                    'match_id' => $matchId,
                    'set_number' => $set_number,
                    'team_a_score' => $team_a_score,
                    'team_b_score' => $team_b_score,
                    'team_a_timeout_used' => $team_a_timeout_used,
                    'team_b_timeout_used' => $team_b_timeout_used,
                    'serving_team' => $serving_team,
                    'set_winner' => $set_winner,
                    'created_at' => now(),
                ];
                if (Schema::hasColumn('table_tennis_sets', 'committee')) $row['committee'] = $committee;
                DB::table('table_tennis_sets')->insert($row);
                return ['success' => true, 'match_id' => $matchId, 'message' => "Set {$set_number} saved."];
            });

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('TT saveSet error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function declareWinner(Request $request)
    {
        $data = $request->json()->all();
        $match_id = isset($data['match_id']) ? intval($data['match_id']) : 0;
        if (!$match_id) return response()->json(['success' => false, 'message' => 'match_id required'], 400);

        try {
            DB::transaction(function () use ($data, $match_id) {
                $total_sets_played = isset($data['total_sets_played']) ? intval($data['total_sets_played']) : 0;
                $team_a_sets_won = isset($data['team_a_sets_won']) ? intval($data['team_a_sets_won']) : 0;
                $team_b_sets_won = isset($data['team_b_sets_won']) ? intval($data['team_b_sets_won']) : 0;
                $winner_team = isset($data['winner_team']) && in_array($data['winner_team'], ['A','B']) ? $data['winner_team'] : null;
                $winner_name = $data['winner_name'] ?? null;

                DB::table('table_tennis_matches')->where('id', $match_id)->update([
                    'status' => 'completed',
                    'winner_name' => $winner_name,
                    'updated_at' => now(),
                ]);

                $summary = [
                    'match_id' => $match_id,
                    'total_sets_played' => $total_sets_played,
                    'team_a_sets_won' => $team_a_sets_won,
                    'team_b_sets_won' => $team_b_sets_won,
                    'winner_team' => $winner_team,
                    'winner_name' => $winner_name,
                    'declared_at' => now(),
                ];

                $exists = DB::table('table_tennis_match_summary')->where('match_id', $match_id)->exists();
                if ($exists) {
                    DB::table('table_tennis_match_summary')->where('match_id', $match_id)->update($summary);
                } else {
                    DB::table('table_tennis_match_summary')->insert($summary);
                }
            });
            return response()->json(['success' => true, 'message' => ($data['winner_name'] ?? 'Winner declared') . ' declared as winner.']);
        } catch (\Throwable $e) {
            Log::error('TT declareWinner error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function normType($t)
    {
        if (!$t) return 'Singles';
        $s = strtolower($t);
        if (strpos($s,'double') !== false && strpos($s,'mixed') === false) return 'Doubles';
        if (strpos($s,'mixed') !== false) return 'Mixed Doubles';
        return 'Singles';
    }
}
