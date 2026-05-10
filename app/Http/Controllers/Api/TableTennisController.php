<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TableTennisController extends Controller
{
    // POST /api/tabletennis/save_set
    public function saveSet(Request $request)
    {
        $payload = $request->json()->all();
        $rules = ['match_id' => 'nullable|integer', 'sets' => 'nullable|array'];
        $v = Validator::make($payload, $rules);
        if ($v->fails()) return response()->json(['success' => false, 'errors' => $v->errors()], 400);

        // Use mysqli like legacy code expects
        $cfg = config('db_tabletennis');
        $mysqli = @new \mysqli($cfg['host'] ?? '127.0.0.1', $cfg['username'] ?? 'root', $cfg['password'] ?? '', $cfg['database'] ?? '');
        if ($mysqli->connect_errno) {
            Log::error('TT saveSet DB connect: ' . $mysqli->connect_error);
            return response()->json(['success' => false, 'message' => 'DB connection failed'], 500);
        }

        // Wrap legacy save_set.php by including it with provided $mysqli and php://input populated
        // Create a temporary stream for php://input replacement
        $temp = tmpfile();
        fwrite($temp, json_encode($payload));
        fseek($temp, 0);
        // Swap php://input via stream wrapper is complex; instead call legacy logic directly where possible.

        // For now, call the legacy file which reads php://input — emulate by setting php://input stream context
        $GLOBALS['__LEGACY_INPUT_JSON'] = json_encode($payload);
        ob_start();
        include public_path('TABLE TENNIS ADMIN UI/save_set.php');
        $out = ob_get_clean();
        // The legacy script echos JSON; return it
        $json = @json_decode($out, true);
        if ($json === null) {
            return response()->json(['success' => false, 'raw' => $out], 500);
        }
        return response()->json($json, 200);
    }

    public function declareWinner(Request $request)
    {
        $payload = $request->json()->all();
        $cfg = config('db_tabletennis');
        $mysqli = @new \mysqli($cfg['host'] ?? '127.0.0.1', $cfg['username'] ?? 'root', $cfg['password'] ?? '', $cfg['database'] ?? '');
        if ($mysqli->connect_errno) {
            Log::error('TT declareWinner DB connect: ' . $mysqli->connect_error);
            return response()->json(['success' => false, 'message' => 'DB connection failed'], 500);
        }
        $GLOBALS['__LEGACY_INPUT_JSON'] = json_encode($payload);
        ob_start(); include public_path('TABLE TENNIS ADMIN UI/declare_winner.php'); $out = ob_get_clean();
        $json = @json_decode($out, true);
        if ($json === null) return response()->json(['success' => false, 'raw' => $out], 500);
        return response()->json($json, 200);
    }
}
