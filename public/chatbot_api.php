<?php
/**
 * SportsSync Chatbot API — chatbot_api.php
 * Place at: public/chatbot_api.php  (or your Laravel public folder)
 *
 * Handles AJAX requests from chatbot.js
 * Returns JSON responses for player, team, standings, latest match queries.
 *
 * NO framework dependencies — pure PHP + MySQLi
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ── CORS: allow same origin only ── */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    $host = parse_url($origin, PHP_URL_HOST);
    $self = $_SERVER['HTTP_HOST'];
    if ($host === $self) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
}

/* ── DB Connection ── */
// Adjust credentials to match your .env / config
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'sportssync';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

/* ── Input sanitization ── */
function clean(string $val): string {
    return trim(strip_tags($val));
}

$action = clean($_GET['action'] ?? '');
$name   = clean($_GET['name']   ?? '');
$sport  = clean($_GET['sport']  ?? '');

/* ────────────────────────────────────────────────────────
   ACTIONS
──────────────────────────────────────────────────────── */

switch ($action) {

    /* ── PLAYER SEARCH ── */
    case 'player':
        if ($name === '') {
            echo json_encode(['error' => 'No name provided']);
            break;
        }

        // 1. Find player in universal_players
        $stmt = $conn->prepare(
            "SELECT id, full_name, team_name
             FROM universal_players
             WHERE full_name LIKE ?
             ORDER BY updated_at DESC
             LIMIT 1"
        );
        $like = '%' . $name . '%';
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $player = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$player) {
            echo json_encode(['error' => 'not found']);
            break;
        }

        // 2. Get their sport + games from player_team_history
        $stmt2 = $conn->prepare(
            "SELECT sport, actual_team_name, games_played, is_current
             FROM player_team_history
             WHERE player_name LIKE ?
             ORDER BY is_current DESC, last_game DESC
             LIMIT 1"
        );
        $stmt2->bind_param('s', $like);
        $stmt2->execute();
        $history = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        if ($history) {
            $player['sport']        = ucfirst(str_replace('_', ' ', $history['sport']));
            $player['games_played'] = $history['games_played'];
            if (!$player['team_name']) {
                $player['team_name'] = $history['actual_team_name'];
            }
        }

        // 3. Basketball stats (match_players)
        $stats = null;
        $stmt3 = $conn->prepare(
            "SELECT
               SUM(pts) AS pts, SUM(reb) AS reb, SUM(ast) AS ast,
               SUM(blk) AS blk, SUM(stl) AS stl, COUNT(*) AS games
             FROM match_players
             WHERE player_name LIKE ?"
        );
        $stmt3->bind_param('s', $like);
        $stmt3->execute();
        $row3 = $stmt3->get_result()->fetch_assoc();
        $stmt3->close();

        if ($row3 && $row3['games'] > 0) {
            $stats = [
                'pts'   => (int) $row3['pts'],
                'reb'   => (int) $row3['reb'],
                'ast'   => (int) $row3['ast'],
                'blk'   => (int) $row3['blk'],
                'stl'   => (int) $row3['stl'],
                'games' => (int) $row3['games'],
            ];
        }

        echo json_encode(['player' => $player, 'stats' => $stats]);
        break;

    /* ── TEAM SEARCH ── */
    case 'team':
        if ($name === '') {
            echo json_encode(['error' => 'No team name provided']);
            break;
        }

        $like = '%' . $name . '%';

        // Players registered under this team in universal_players
        $stmt = $conn->prepare(
            "SELECT id, full_name, team_name
             FROM universal_players
             WHERE team_name LIKE ?
             ORDER BY full_name
             LIMIT 20"
        );
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res     = $stmt->get_result();
        $players = [];
        while ($r = $res->fetch_assoc()) $players[] = $r;
        $stmt->close();

        // Also check player_team_history for team name matches
        if (empty($players)) {
            $stmt2 = $conn->prepare(
                "SELECT DISTINCT player_name AS full_name, actual_team_name AS team_name
                 FROM player_team_history
                 WHERE actual_team_name LIKE ?
                 ORDER BY player_name
                 LIMIT 20"
            );
            $stmt2->bind_param('s', $like);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($r = $res2->fetch_assoc()) $players[] = $r;
            $stmt2->close();
        }

        if (empty($players)) {
            echo json_encode(['error' => 'team not found']);
            break;
        }

        $team_name = $players[0]['team_name'] ?: $name;
        echo json_encode(['team_name' => $team_name, 'players' => $players]);
        break;

    /* ── STANDINGS (Basketball wins/losses) ── */
    case 'standings':
        $result = $conn->query(
            "SELECT
               team_a_name AS team_name,
               SUM(CASE WHEN match_result = 'Team A Wins' THEN 1 ELSE 0 END) AS wins,
               SUM(CASE WHEN match_result = 'Team B Wins' THEN 1 ELSE 0 END) AS losses
             FROM matches
             WHERE status = 'completed' OR match_result != ''
             GROUP BY team_a_name
             UNION ALL
             SELECT
               team_b_name AS team_name,
               SUM(CASE WHEN match_result = 'Team B Wins' THEN 1 ELSE 0 END) AS wins,
               SUM(CASE WHEN match_result = 'Team A Wins' THEN 1 ELSE 0 END) AS losses
             FROM matches
             WHERE status = 'completed' OR match_result != ''
             GROUP BY team_b_name"
        );

        $raw = [];
        while ($r = $result->fetch_assoc()) {
            $tn = $r['team_name'];
            if (!$tn) continue;
            if (!isset($raw[$tn])) $raw[$tn] = ['wins' => 0, 'losses' => 0];
            $raw[$tn]['wins']   += (int) $r['wins'];
            $raw[$tn]['losses'] += (int) $r['losses'];
        }

        $standings = [];
        foreach ($raw as $team => $rec) {
            $standings[] = ['team_name' => $team, 'wins' => $rec['wins'], 'losses' => $rec['losses']];
        }

        // Sort by wins desc
        usort($standings, function ($a, $b) { return $b['wins'] - $a['wins']; });

        echo json_encode(['standings' => $standings]);
        break;

    /* ── LATEST MATCHES ── */
    case 'latest':
        $matches = [];
        $sport_filter = strtolower($sport);

        /* Basketball */
        if (!$sport_filter || $sport_filter === 'basketball') {
            $r = $conn->query(
                "SELECT team_a_name, team_b_name, team_a_score, team_b_score,
                        match_result, saved_at,
                        CASE
                          WHEN match_result = 'Team A Wins' THEN team_a_name
                          WHEN match_result = 'Team B Wins' THEN team_b_name
                          ELSE NULL
                        END AS winner,
                        'Basketball' AS sport
                 FROM matches
                 ORDER BY saved_at DESC
                 LIMIT 5"
            );
            while ($row = $r->fetch_assoc()) $matches[] = $row;
        }

        /* Volleyball */
        if (!$sport_filter || $sport_filter === 'volleyball') {
            $r = $conn->query(
                "SELECT team_a_name, team_b_name,
                        NULL AS team_a_score, NULL AS team_b_score,
                        status AS match_result, created_at AS saved_at,
                        winner_team AS winner,
                        'Volleyball' AS sport
                 FROM volleyball_matches
                 WHERE status = 'completed'
                 ORDER BY created_at DESC
                 LIMIT 5"
            );
            if ($r) while ($row = $r->fetch_assoc()) $matches[] = $row;
        }

        /* Badminton */
        if (!$sport_filter || $sport_filter === 'badminton') {
            $r = $conn->query(
                "SELECT bm.team_a_name, bm.team_b_name,
                        bms.team_a_sets_won AS team_a_score,
                        bms.team_b_sets_won AS team_b_score,
                        bm.status AS match_result,
                        bm.created_at AS saved_at,
                        CASE bms.winner_team
                          WHEN 'A' THEN bm.team_a_name
                          WHEN 'B' THEN bm.team_b_name
                          ELSE NULL
                        END AS winner,
                        'Badminton' AS sport
                 FROM badminton_matches bm
                 LEFT JOIN badminton_match_summary bms ON bms.match_id = bm.id
                 WHERE bm.status = 'completed'
                 ORDER BY bm.created_at DESC
                 LIMIT 5"
            );
            if ($r) while ($row = $r->fetch_assoc()) $matches[] = $row;
        }

        /* Table Tennis */
        if (!$sport_filter || $sport_filter === 'table_tennis') {
            $r = $conn->query(
                "SELECT ttm.team_a_name, ttm.team_b_name,
                        tts.team_a_sets_won AS team_a_score,
                        tts.team_b_sets_won AS team_b_score,
                        ttm.status AS match_result,
                        ttm.created_at AS saved_at,
                        CASE tts.winner_team
                          WHEN 'A' THEN ttm.team_a_name
                          WHEN 'B' THEN ttm.team_b_name
                          ELSE NULL
                        END AS winner,
                        'Table Tennis' AS sport
                 FROM table_tennis_matches ttm
                 LEFT JOIN table_tennis_match_summary tts ON tts.match_id = ttm.id
                 WHERE ttm.status = 'completed'
                 ORDER BY ttm.created_at DESC
                 LIMIT 5"
            );
            if ($r) while ($row = $r->fetch_assoc()) $matches[] = $row;
        }

        /* Darts */
        if (!$sport_filter || $sport_filter === 'darts') {
            $r = $conn->query(
                "SELECT dm.player1_name AS team_a_name, dm.player2_name AS team_b_name,
                        dms.player1_legs AS team_a_score, dms.player2_legs AS team_b_score,
                        dm.status AS match_result, dm.created_at AS saved_at,
                        dms.winner_name AS winner,
                        'Darts' AS sport
                 FROM darts_matches dm
                 LEFT JOIN darts_match_summary dms ON dms.match_id = dm.id
                 WHERE dm.status = 'completed'
                 ORDER BY dm.created_at DESC
                 LIMIT 5"
            );
            if ($r) while ($row = $r->fetch_assoc()) $matches[] = $row;
        }

        // Sort all by date descending
        usort($matches, function ($a, $b) {
            return strtotime($b['saved_at'] ?? '0') - strtotime($a['saved_at'] ?? '0');
        });

        echo json_encode(['matches' => array_slice($matches, 0, 10)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}

$conn->close();