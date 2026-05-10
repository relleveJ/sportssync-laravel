<?php
// ============================================================
// analytics_api.php — SportSync Analytics API (v3 fixed)
// Fixes applied:
//  1. Basketball: saved_at aliased to created_at everywhere
//  2. Badminton/TT: ALL participants shown (not just winners)
//  3. Team Analytics roster: scoped by actual team name + side
//  4. Darts: total_throws, throw_avg, leg_wins from summary/throws
//  5. Team History: shows side + actual_team_name, is_current flag
//  6. universal_players synced with real team names (not A/B letter)
//  7. all_players includes badminton + TT participants
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../db.php';

// Guard: ensure universal_players exists
try {
    if (!$pdo->query("SHOW TABLES LIKE 'universal_players'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS universal_players (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            team_name VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_player_identity (full_name, team_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Guard: ensure player_team_history exists
    if (!$pdo->query("SHOW TABLES LIKE 'player_team_history'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_team_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_universal_id INT UNSIGNED NOT NULL DEFAULT 0,
            player_name VARCHAR(120) NOT NULL,
            sport VARCHAR(30) NOT NULL,
            side CHAR(1) NOT NULL DEFAULT '',
            actual_team_name VARCHAR(120) NOT NULL DEFAULT '',
            games_played INT UNSIGNED NOT NULL DEFAULT 0,
            first_game DATETIME DEFAULT NULL,
            last_game DATETIME DEFAULT NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_player_sport_team (player_universal_id, sport, actual_team_name(100)),
            KEY idx_player_sport (player_universal_id, sport)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // Migration: add player_universal_id column if it doesn't exist yet (upgrading from old schema)
        try {
            $pdo->exec("ALTER TABLE player_team_history
                ADD COLUMN IF NOT EXISTS player_universal_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id,
                DROP KEY IF EXISTS uq_player_sport_team,
                DROP KEY IF EXISTS idx_player_sport,
                ADD UNIQUE KEY uq_player_sport_team (player_universal_id, sport, actual_team_name(100)),
                ADD KEY idx_player_sport (player_universal_id, sport)");
        } catch (PDOException $e) { /* column may already exist on newer installs */ }
    }
} catch (PDOException $e) {}

$action = $_GET['action'] ?? '';
$sport  = $_GET['sport']  ?? 'basketball';
$pid    = (int)($_GET['player_id'] ?? 0);
$player = trim($_GET['player'] ?? '');
$team   = trim($_GET['team_name'] ?? '');

function normalizeName(string $name): string {
    return mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');
}

function getOrCreatePlayer(PDO $pdo, string $fullName, string $teamName): int {
    $fullName = normalizeName($fullName);
    $teamName = normalizeName($teamName);
    $s = $pdo->prepare("SELECT id FROM universal_players WHERE full_name=:n AND team_name=:t LIMIT 1");
    $s->execute([':n'=>$fullName,':t'=>$teamName]);
    $row = $s->fetch();
    if ($row) return (int)$row['id'];
    $ins = $pdo->prepare("INSERT INTO universal_players (full_name,team_name) VALUES(:n,:t) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $ins->execute([':n'=>$fullName,':t'=>$teamName]);
    return (int)$pdo->lastInsertId();
}

// Upsert one row into player_team_history.
// FIX: keyed on player_universal_id (not name alone) so same-name / different-team players never share rows.
function upsertTeamHistory(PDO $pdo, int $universalId, string $playerName, string $sport, string $side,
                            string $actualTeam, int $games, string $firstGame, string $lastGame): void {
    $pdo->prepare("INSERT INTO player_team_history
        (player_universal_id, player_name, sport, side, actual_team_name, games_played, first_game, last_game)
        VALUES (:uid,:pn,:sp,:si,:at,:gp,:fg,:lg)
        ON DUPLICATE KEY UPDATE
            games_played=VALUES(games_played),
            first_game=VALUES(first_game),
            last_game=VALUES(last_game),
            updated_at=CURRENT_TIMESTAMP"
    )->execute([':uid'=>$universalId,':pn'=>$playerName,':sp'=>$sport,':si'=>$side,
                ':at'=>$actualTeam,':gp'=>$games,':fg'=>$firstGame,':lg'=>$lastGame]);
}

// Rebuild is_current flags for one player — scoped by universal ID, not just name.
function refreshIsCurrent(PDO $pdo, int $universalId): void {
    $pdo->prepare("UPDATE player_team_history SET is_current=0 WHERE player_universal_id=:uid")->execute([':uid'=>$universalId]);
    $pdo->prepare("UPDATE player_team_history pth
        JOIN (SELECT sport, MAX(last_game) AS mx FROM player_team_history WHERE player_universal_id=:uid GROUP BY sport) latest
          ON latest.sport=pth.sport AND latest.mx=pth.last_game
        SET pth.is_current=1 WHERE pth.player_universal_id=:uid2"
    )->execute([':uid'=>$universalId,':uid2'=>$universalId]);
}

try {
    switch ($action) {

        // ── SPORTS OVERVIEW ─────────────────────────────────────
        case 'sports_overview':
            $result = [];

            $r = $pdo->query("SELECT COUNT(*) AS c FROM matches WHERE match_result!='ONGOING'");
            $result['basketball'] = ['total_matches'=>(int)($r->fetch()['c']??0)];
            $r = $pdo->query("SELECT mp.player_name,
                IF(mp.team='A',m.team_a_name,m.team_b_name) AS team,
                SUM(mp.pts) AS total_pts, COUNT(DISTINCT mp.match_id) AS games
                FROM match_players mp JOIN matches m ON mp.match_id=m.match_id
                WHERE mp.player_name!='' GROUP BY mp.player_name,IF(mp.team='A',m.team_a_name,m.team_b_name)
                ORDER BY total_pts DESC LIMIT 5");
            $result['basketball']['top_players'] = $r->fetchAll();

            $r = $pdo->query("SELECT COUNT(*) AS c FROM volleyball_matches WHERE match_result!='ONGOING'");
            $result['volleyball'] = ['total_matches'=>(int)($r->fetch()['c']??0)];
            $r = $pdo->query("SELECT vp.player_name, IF(vp.team='A',vm.team_a_name,vm.team_b_name) AS team,
                SUM(vp.pts) AS total_pts, COUNT(DISTINCT vp.match_id) AS games
                FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id
                WHERE vp.player_name!='' GROUP BY vp.player_name,IF(vp.team='A',vm.team_a_name,vm.team_b_name)
                ORDER BY total_pts DESC LIMIT 5");
            $result['volleyball']['top_players'] = $r->fetchAll();

            $r = $pdo->query("SELECT COUNT(*) AS c FROM badminton_matches WHERE status='completed'");
            $result['badminton'] = ['total_matches'=>(int)($r->fetch()['c']??0)];
            $r = $pdo->query("SELECT winner_name AS player_name, COUNT(*) AS wins
                FROM badminton_matches WHERE status='completed' AND winner_name IS NOT NULL
                GROUP BY winner_name ORDER BY wins DESC LIMIT 5");
            $result['badminton']['top_players'] = $r->fetchAll();

            $r = $pdo->query("SELECT COUNT(*) AS c FROM table_tennis_matches WHERE status='completed'");
            $result['table_tennis'] = ['total_matches'=>(int)($r->fetch()['c']??0)];
            $r = $pdo->query("SELECT winner_name AS player_name, COUNT(*) AS wins
                FROM table_tennis_matches WHERE status='completed' AND winner_name IS NOT NULL
                GROUP BY winner_name ORDER BY wins DESC LIMIT 5");
            $result['table_tennis']['top_players'] = $r->fetchAll();

            $r = $pdo->query("SELECT COUNT(*) AS c FROM darts_matches WHERE status='completed'");
            $result['darts'] = ['total_matches'=>(int)($r->fetch()['c']??0)];
            $r = $pdo->query("SELECT winner_name AS player_name, COUNT(*) AS wins
                FROM darts_matches WHERE status='completed' AND winner_name IS NOT NULL
                GROUP BY winner_name ORDER BY wins DESC LIMIT 5");
            $result['darts']['top_players'] = $r->fetchAll();

            echo json_encode(['success'=>true,'data'=>$result]);
            break;

        // ── PLAYERS PER SPORT ────────────────────────────────────
        case 'players':
            switch ($sport) {
                case 'basketball':
                    // FIX: resolve actual team name from side letter
                    $stmt = $pdo->query("SELECT mp.player_name,
                        IF(mp.team='A',m.team_a_name,m.team_b_name) AS team,
                        mp.team AS side,
                        SUM(mp.pts) AS total_pts, SUM(mp.reb) AS total_reb,
                        SUM(mp.ast) AS total_ast, SUM(mp.blk) AS total_blk, SUM(mp.stl) AS total_stl,
                        COUNT(DISTINCT mp.match_id) AS games_played
                        FROM match_players mp JOIN matches m ON mp.match_id=m.match_id
                        WHERE mp.player_name!=''
                        GROUP BY mp.player_name,mp.team,IF(mp.team='A',m.team_a_name,m.team_b_name)
                        ORDER BY total_pts DESC");
                    break;
                case 'volleyball':
                    $stmt = $pdo->query("SELECT vp.player_name,
                        IF(vp.team='A',vm.team_a_name,vm.team_b_name) AS team,
                        vp.team AS side,
                        SUM(vp.pts) AS total_pts, SUM(vp.spike) AS total_spike,
                        SUM(vp.ace) AS total_ace, SUM(vp.ex_set) AS total_set, SUM(vp.ex_dig) AS total_dig,
                        COUNT(DISTINCT vp.match_id) AS games_played
                        FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id
                        WHERE vp.player_name!=''
                        GROUP BY vp.player_name,vp.team,IF(vp.team='A',vm.team_a_name,vm.team_b_name)
                        ORDER BY total_pts DESC");
                    break;
                case 'badminton':
                    // FIX: ALL participants — singles, doubles, mixed — not just winners
                    $stmt = $pdo->query("SELECT p.player_name, p.team_name AS team, p.side,
                        COUNT(DISTINCT p.match_id) AS games_played,
                        SUM(CASE WHEN bm.winner_name=p.player_name OR bm.winner_name=p.team_name THEN 1 ELSE 0 END) AS wins
                        FROM (
                          SELECT team_a_player1 AS player_name, team_a_name AS team_name, 'A' AS side, id AS match_id FROM badminton_matches WHERE status='completed' AND team_a_player1 IS NOT NULL AND team_a_player1!=''
                          UNION ALL
                          SELECT team_a_player2, team_a_name, 'A', id FROM badminton_matches WHERE status='completed' AND team_a_player2 IS NOT NULL AND team_a_player2!=''
                          UNION ALL
                          SELECT team_b_player1, team_b_name, 'B', id FROM badminton_matches WHERE status='completed' AND team_b_player1 IS NOT NULL AND team_b_player1!=''
                          UNION ALL
                          SELECT team_b_player2, team_b_name, 'B', id FROM badminton_matches WHERE status='completed' AND team_b_player2 IS NOT NULL AND team_b_player2!=''
                        ) p JOIN badminton_matches bm ON p.match_id=bm.id
                        GROUP BY p.player_name,p.team_name,p.side ORDER BY wins DESC, games_played DESC");
                    break;
                case 'table_tennis':
                    // FIX: ALL participants — not just winners
                    $stmt = $pdo->query("SELECT p.player_name, p.team_name AS team, p.side,
                        COUNT(DISTINCT p.match_id) AS games_played,
                        SUM(CASE WHEN ttm.winner_name=p.player_name OR ttm.winner_name=p.team_name THEN 1 ELSE 0 END) AS wins
                        FROM (
                          SELECT team_a_player1 AS player_name, team_a_name AS team_name, 'A' AS side, id AS match_id FROM table_tennis_matches WHERE status='completed' AND team_a_player1 IS NOT NULL AND team_a_player1!=''
                          UNION ALL
                          SELECT team_a_player2, team_a_name, 'A', id FROM table_tennis_matches WHERE status='completed' AND team_a_player2 IS NOT NULL AND team_a_player2!=''
                          UNION ALL
                          SELECT team_b_player1, team_b_name, 'B', id FROM table_tennis_matches WHERE status='completed' AND team_b_player1 IS NOT NULL AND team_b_player1!=''
                          UNION ALL
                          SELECT team_b_player2, team_b_name, 'B', id FROM table_tennis_matches WHERE status='completed' AND team_b_player2 IS NOT NULL AND team_b_player2!=''
                        ) p JOIN table_tennis_matches ttm ON p.match_id=ttm.id
                        GROUP BY p.player_name,p.team_name,p.side ORDER BY wins DESC, games_played DESC");
                    break;
                case 'darts':
                    $stmt = $pdo->query("SELECT dp.player_name, COALESCE(dp.team_name,'') AS team, '' AS side,
                        COUNT(DISTINCT dp.match_id) AS games_played,
                        COUNT(DISTINCT CASE WHEN dm.winner_name=dp.player_name THEN dm.id END) AS wins
                        FROM darts_players dp JOIN darts_matches dm ON dp.match_id=dm.id
                        WHERE dp.player_name!=''
                        GROUP BY dp.player_name,dp.team_name ORDER BY wins DESC");
                    break;
                default: $stmt = null;
            }
            echo json_encode(['success'=>true,'data'=>$stmt?$stmt->fetchAll():[]]); break;

        // ── PLAYER DETAIL PER SPORT ─────────────────────────────
        case 'player_detail':
            if (!$player) { echo json_encode(['success'=>false,'error'=>'player required']); break; }
            $teamParam = trim($_GET['team']??'');
            $detail = [];
            switch ($sport) {
                case 'basketball':
                    // FIX: saved_at aliased to created_at, actual_team_name resolved
                    $stmt = $pdo->prepare("SELECT mp.*,
                        IF(mp.team='A',m.team_a_name,m.team_b_name) AS actual_team_name,
                        m.team_a_name, m.team_b_name, m.team_a_score, m.team_b_score,
                        m.match_result, m.saved_at AS created_at
                        FROM match_players mp JOIN matches m ON mp.match_id=m.match_id
                        WHERE mp.player_name=:n
                          AND (:t='' OR IF(mp.team='A',m.team_a_name,m.team_b_name)=:t2)
                        ORDER BY m.saved_at ASC");
                    $stmt->execute([':n'=>$player,':t'=>$teamParam,':t2'=>$teamParam]);
                    $detail = $stmt->fetchAll(); break;
                case 'volleyball':
                    $stmt = $pdo->prepare("SELECT vp.*,
                        IF(vp.team='A',vm.team_a_name,vm.team_b_name) AS actual_team_name,
                        vm.team_a_name, vm.team_b_name, vm.team_a_score, vm.team_b_score,
                        vm.match_result, vm.created_at
                        FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id
                        WHERE vp.player_name=:n
                          AND (:t='' OR IF(vp.team='A',vm.team_a_name,vm.team_b_name)=:t2)
                        ORDER BY vm.created_at ASC");
                    $stmt->execute([':n'=>$player,':t'=>$teamParam,':t2'=>$teamParam]);
                    $detail = $stmt->fetchAll(); break;
                case 'badminton':
                    // FIX: all matches this player participated in, plus computed is_win flag
                    $stmt = $pdo->prepare("SELECT bm.id AS match_id, bm.match_type,
                        bm.team_a_name, bm.team_b_name, bm.winner_name, bm.created_at,
                        CASE WHEN bm.team_a_player1=:n OR bm.team_a_player2=:n2 THEN bm.team_a_name
                             ELSE bm.team_b_name END AS player_team,
                        CASE WHEN bm.team_a_player1=:n3 OR bm.team_a_player2=:n4 THEN 'A' ELSE 'B' END AS side
                        FROM badminton_matches bm
                        WHERE bm.status='completed'
                          AND (bm.team_a_player1=:n5 OR bm.team_a_player2=:n6
                               OR bm.team_b_player1=:n7 OR bm.team_b_player2=:n8)
                        ORDER BY bm.created_at ASC");
                    $stmt->execute([':n'=>$player,':n2'=>$player,':n3'=>$player,':n4'=>$player,
                                    ':n5'=>$player,':n6'=>$player,':n7'=>$player,':n8'=>$player]);
                    $detail = $stmt->fetchAll(); break;
                case 'table_tennis':
                    $stmt = $pdo->prepare("SELECT ttm.id AS match_id, ttm.match_type,
                        ttm.team_a_name, ttm.team_b_name, ttm.winner_name, ttm.created_at,
                        CASE WHEN ttm.team_a_player1=:n OR ttm.team_a_player2=:n2 THEN ttm.team_a_name
                             ELSE ttm.team_b_name END AS player_team,
                        CASE WHEN ttm.team_a_player1=:n3 OR ttm.team_a_player2=:n4 THEN 'A' ELSE 'B' END AS side
                        FROM table_tennis_matches ttm
                        WHERE ttm.status='completed'
                          AND (ttm.team_a_player1=:n5 OR ttm.team_a_player2=:n6
                               OR ttm.team_b_player1=:n7 OR ttm.team_b_player2=:n8)
                        ORDER BY ttm.created_at ASC");
                    $stmt->execute([':n'=>$player,':n2'=>$player,':n3'=>$player,':n4'=>$player,
                                    ':n5'=>$player,':n6'=>$player,':n7'=>$player,':n8'=>$player]);
                    $detail = $stmt->fetchAll(); break;
                case 'darts':
                    // FIX: total_throws, throw_avg, leg_wins per match
                    $stmt = $pdo->prepare("SELECT dm.id AS match_id, dp.player_number, dp.id AS dp_id,
                        dp.player_name, dm.game_type, dm.winner_name, dm.created_at,
                        (dm.winner_name=dp.player_name) AS is_winner,
                        COALESCE(CASE dp.player_number
                            WHEN 1 THEN dms.player1_legs_won WHEN 2 THEN dms.player2_legs_won
                            WHEN 3 THEN dms.player3_legs_won WHEN 4 THEN dms.player4_legs_won
                            ELSE 0 END, 0) AS leg_wins,
                        COALESCE(dms.total_legs,0) AS total_legs,
                        COUNT(dt.id) AS total_throws,
                        COALESCE(SUM(dt.throw_value),0) AS total_score,
                        ROUND(COALESCE(AVG(dt.throw_value),0),2) AS throw_avg
                        FROM darts_players dp
                        JOIN darts_matches dm ON dp.match_id=dm.id
                        LEFT JOIN darts_match_summary dms ON dms.match_id=dm.id
                        LEFT JOIN darts_legs dl ON dl.match_id=dm.id
                        LEFT JOIN darts_throws dt ON dt.leg_id=dl.id AND dt.player_id=dp.id
                        WHERE dp.player_name=:n
                        GROUP BY dm.id,dp.id,dms.id ORDER BY dm.created_at ASC");
                    $stmt->execute([':n'=>$player]);
                    $detail = $stmt->fetchAll(); break;
            }
            echo json_encode(['success'=>true,'data'=>$detail,'player'=>$player,'sport'=>$sport]); break;

        // ── SPORT TRENDS ────────────────────────────────────────
        case 'sport_trends':
            switch ($sport) {
                case 'basketball': $stmt=$pdo->query("SELECT DATE(saved_at) AS game_date,ROUND(AVG(team_a_score),1) AS avg_a,ROUND(AVG(team_b_score),1) AS avg_b,COUNT(*) AS games FROM matches WHERE match_result!='ONGOING' GROUP BY DATE(saved_at) ORDER BY game_date DESC LIMIT 10"); break;
                case 'volleyball': $stmt=$pdo->query("SELECT DATE(created_at) AS game_date,ROUND(AVG(team_a_score),1) AS avg_a,ROUND(AVG(team_b_score),1) AS avg_b,COUNT(*) AS games FROM volleyball_matches WHERE match_result!='ONGOING' GROUP BY DATE(created_at) ORDER BY game_date DESC LIMIT 10"); break;
                case 'badminton':  $stmt=$pdo->query("SELECT DATE(created_at) AS game_date,match_type,COUNT(*) AS games FROM badminton_matches WHERE status='completed' GROUP BY DATE(created_at),match_type ORDER BY game_date DESC LIMIT 10"); break;
                case 'table_tennis': $stmt=$pdo->query("SELECT DATE(created_at) AS game_date,match_type,COUNT(*) AS games FROM table_tennis_matches WHERE status='completed' GROUP BY DATE(created_at),match_type ORDER BY game_date DESC LIMIT 10"); break;
                case 'darts': $stmt=$pdo->query("SELECT DATE(created_at) AS game_date,game_type,COUNT(*) AS games FROM darts_matches WHERE status='completed' GROUP BY DATE(created_at),game_type ORDER BY game_date DESC LIMIT 10"); break;
                default: $stmt=null;
            }
            echo json_encode(['success'=>true,'data'=>$stmt?$stmt->fetchAll():[]]); break;

        // ── ALL PLAYERS for players.php ──────────────────────────
        case 'all_players':
            // FIX: sync actual team names (resolve from match record using side letter)
            // Basketball
            $rows=$pdo->query("SELECT DISTINCT mp.player_name, mp.team AS side, IF(mp.team='A',m.team_a_name,m.team_b_name) AS team_name FROM match_players mp JOIN matches m ON mp.match_id=m.match_id WHERE mp.player_name!=''")->fetchAll();
            foreach ($rows as $r) { $uid=getOrCreatePlayer($pdo,$r['player_name'],$r['team_name']??''); }

            // Volleyball
            $rows=$pdo->query("SELECT DISTINCT vp.player_name, vp.team AS side, IF(vp.team='A',vm.team_a_name,vm.team_b_name) AS team_name FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id WHERE vp.player_name!=''")->fetchAll();
            foreach ($rows as $r) { getOrCreatePlayer($pdo,$r['player_name'],$r['team_name']??''); }

            // FIX: Badminton all 4 player columns with actual team names
            foreach([['team_a_player1','team_a_name','A'],['team_a_player2','team_a_name','A'],['team_b_player1','team_b_name','B'],['team_b_player2','team_b_name','B']] as [$pc,$tc,$si]) {
                $rows=$pdo->query("SELECT DISTINCT `$pc` AS pn, `$tc` AS tn FROM badminton_matches WHERE `$pc` IS NOT NULL AND `$pc`!='' AND status='completed'")->fetchAll();
                foreach($rows as $r) getOrCreatePlayer($pdo,$r['pn'],$r['tn']??'');
            }

            // FIX: Table Tennis all 4 player columns
            foreach([['team_a_player1','team_a_name','A'],['team_a_player2','team_a_name','A'],['team_b_player1','team_b_name','B'],['team_b_player2','team_b_name','B']] as [$pc,$tc,$si]) {
                $rows=$pdo->query("SELECT DISTINCT `$pc` AS pn, `$tc` AS tn FROM table_tennis_matches WHERE `$pc` IS NOT NULL AND `$pc`!='' AND status='completed'")->fetchAll();
                foreach($rows as $r) getOrCreatePlayer($pdo,$r['pn'],$r['tn']??'');
            }

            // Darts
            $rows=$pdo->query("SELECT DISTINCT player_name, COALESCE(team_name,'') AS team_name FROM darts_players WHERE player_name!=''")->fetchAll();
            foreach($rows as $r) getOrCreatePlayer($pdo,$r['player_name'],$r['team_name']);

            // Trigger team history sync via procedure (if it exists)
            try { $pdo->exec("CALL sync_player_team_history()"); } catch(PDOException $e) {}

            // FIX: Return registry with per-player stats correctly scoped by (full_name + team_name).
            // Badminton and TT: match only rows where the player is on up.team_name's side.
            // Darts: match only rows where dp.team_name = up.team_name.
            $stmt = $pdo->query("SELECT up.id, up.full_name, up.team_name,
                (SELECT COUNT(DISTINCT mp.match_id) FROM match_players mp JOIN matches m ON mp.match_id=m.match_id WHERE mp.player_name=up.full_name AND IF(mp.team='A',m.team_a_name,m.team_b_name)=up.team_name) AS bball_games,
                (SELECT COALESCE(SUM(mp.pts),0) FROM match_players mp JOIN matches m ON mp.match_id=m.match_id WHERE mp.player_name=up.full_name AND IF(mp.team='A',m.team_a_name,m.team_b_name)=up.team_name) AS bball_pts,
                (SELECT COUNT(DISTINCT vp.match_id) FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id WHERE vp.player_name=up.full_name AND IF(vp.team='A',vm.team_a_name,vm.team_b_name)=up.team_name) AS vball_games,
                (SELECT COALESCE(SUM(vp.pts),0) FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id WHERE vp.player_name=up.full_name AND IF(vp.team='A',vm.team_a_name,vm.team_b_name)=up.team_name) AS vball_pts,
                (SELECT COUNT(DISTINCT bm.id) FROM badminton_matches bm WHERE bm.status='completed' AND (
                    (bm.team_a_name=up.team_name AND (bm.team_a_player1=up.full_name OR bm.team_a_player2=up.full_name)) OR
                    (bm.team_b_name=up.team_name AND (bm.team_b_player1=up.full_name OR bm.team_b_player2=up.full_name))
                )) AS badminton_games,
                (SELECT COUNT(*) FROM badminton_matches bm WHERE bm.status='completed' AND bm.winner_name=up.full_name AND (
                    (bm.team_a_name=up.team_name AND (bm.team_a_player1=up.full_name OR bm.team_a_player2=up.full_name)) OR
                    (bm.team_b_name=up.team_name AND (bm.team_b_player1=up.full_name OR bm.team_b_player2=up.full_name))
                )) AS badminton_wins,
                (SELECT COUNT(DISTINCT ttm.id) FROM table_tennis_matches ttm WHERE ttm.status='completed' AND (
                    (ttm.team_a_name=up.team_name AND (ttm.team_a_player1=up.full_name OR ttm.team_a_player2=up.full_name)) OR
                    (ttm.team_b_name=up.team_name AND (ttm.team_b_player1=up.full_name OR ttm.team_b_player2=up.full_name))
                )) AS tt_games,
                (SELECT COUNT(*) FROM table_tennis_matches ttm WHERE ttm.status='completed' AND ttm.winner_name=up.full_name AND (
                    (ttm.team_a_name=up.team_name AND (ttm.team_a_player1=up.full_name OR ttm.team_a_player2=up.full_name)) OR
                    (ttm.team_b_name=up.team_name AND (ttm.team_b_player1=up.full_name OR ttm.team_b_player2=up.full_name))
                )) AS tt_wins,
                (SELECT COUNT(DISTINCT dp.match_id) FROM darts_players dp WHERE dp.player_name=up.full_name AND COALESCE(dp.team_name,'')=up.team_name) AS darts_games,
                (SELECT COUNT(DISTINCT CASE WHEN dm.winner_name=up.full_name THEN dm.id END) FROM darts_players dp JOIN darts_matches dm ON dp.match_id=dm.id WHERE dp.player_name=up.full_name AND COALESCE(dp.team_name,'')=up.team_name) AS darts_wins
                FROM universal_players up ORDER BY up.full_name ASC");
            echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]); break;

        // ── FULL CROSS-SPORT PROFILE ─────────────────────────────
        case 'player_profile':
            if (!$pid && !$player) { echo json_encode(['success'=>false,'error'=>'player_id or player required']); break; }
            if ($pid) {
                $uStmt=$pdo->prepare("SELECT * FROM universal_players WHERE id=:id");
                $uStmt->execute([':id'=>$pid]);
            } else {
                $tParam=normalizeName(trim($_GET['team']??''));
                $uStmt=$pdo->prepare("SELECT * FROM universal_players WHERE full_name=:n AND team_name=:t LIMIT 1");
                $uStmt->execute([':n'=>normalizeName($player),':t'=>$tParam]);
            }
            $uPlayer=$uStmt->fetch();
            if (!$uPlayer) { echo json_encode(['success'=>false,'error'=>'Player not found']); break; }

            $n=$uPlayer['full_name']; $t=$uPlayer['team_name'];

            // Resolve the player's current assigned team.
            // Priority: player_profiles.team_override (admin-set) → universal_players.team_name (registry).
            // This becomes the single authoritative 'current_team' field consumed by the Players page
            // so the "Current Team" banner never has to guess from per-sport history rows.
            $resolvedCurrentTeam = $t; // default: registry team
            try {
                $ctStmt = $pdo->prepare("SELECT pp.team_override
                    FROM player_profiles pp
                    WHERE pp.universal_id = :uid AND pp.team_override != ''
                    LIMIT 1");
                $ctStmt->execute([':uid' => $uPlayer['id']]);
                $adminTeam = $ctStmt->fetchColumn();
                if ($adminTeam) $resolvedCurrentTeam = $adminTeam;
            } catch (PDOException $e) {
                // player_profiles may not exist on older installs — safe to skip
            }
            $uPlayer['current_team'] = $resolvedCurrentTeam;

            $profile=['player'=>$uPlayer,'sports'=>[]];

            // Basketball — match team by actual name resolved from side
            $s=$pdo->prepare("SELECT mp.*, IF(mp.team='A',m.team_a_name,m.team_b_name) AS actual_team_name,
                m.team_a_name, m.team_b_name, m.match_result, m.saved_at AS created_at
                FROM match_players mp JOIN matches m ON mp.match_id=m.match_id
                WHERE mp.player_name=:n AND IF(mp.team='A',m.team_a_name,m.team_b_name)=:t
                ORDER BY m.saved_at ASC");
            $s->execute([':n'=>$n,':t'=>$t]); $rows=$s->fetchAll();
            if ($rows) $profile['sports']['basketball']=['games'=>count($rows),'total_pts'=>array_sum(array_column($rows,'pts')),'total_reb'=>array_sum(array_column($rows,'reb')),'total_ast'=>array_sum(array_column($rows,'ast')),'total_blk'=>array_sum(array_column($rows,'blk')),'total_stl'=>array_sum(array_column($rows,'stl')),'history'=>$rows];

            // Volleyball
            $s=$pdo->prepare("SELECT vp.*, IF(vp.team='A',vm.team_a_name,vm.team_b_name) AS actual_team_name,
                vm.team_a_name, vm.team_b_name, vm.match_result, vm.created_at
                FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id
                WHERE vp.player_name=:n AND IF(vp.team='A',vm.team_a_name,vm.team_b_name)=:t
                ORDER BY vm.created_at ASC");
            $s->execute([':n'=>$n,':t'=>$t]); $rows=$s->fetchAll();
            if ($rows) $profile['sports']['volleyball']=['games'=>count($rows),'total_pts'=>array_sum(array_column($rows,'pts')),'total_spike'=>array_sum(array_column($rows,'spike')),'total_ace'=>array_sum(array_column($rows,'ace')),'total_set'=>array_sum(array_column($rows,'ex_set')),'total_dig'=>array_sum(array_column($rows,'ex_dig')),'history'=>$rows];

            // Badminton — only matches where player appeared on their registered team's side
            // FIX: added team_name filter (bm.team_a_name=:t / bm.team_b_name=:t) so "JP (IT)"
            // and "JP (DIT)" each only see their own team's matches.
            $s=$pdo->prepare("SELECT bm.id AS match_id, bm.match_type, bm.team_a_name, bm.team_b_name,
                bm.winner_name, bm.created_at,
                CASE WHEN bm.team_a_player1=:n OR bm.team_a_player2=:n2 THEN bm.team_a_name ELSE bm.team_b_name END AS player_team,
                CASE WHEN bm.team_a_player1=:n3 OR bm.team_a_player2=:n4 THEN 'A' ELSE 'B' END AS side
                FROM badminton_matches bm WHERE bm.status='completed'
                AND (
                    (bm.team_a_name=:t  AND (bm.team_a_player1=:n5 OR bm.team_a_player2=:n6)) OR
                    (bm.team_b_name=:t2 AND (bm.team_b_player1=:n7 OR bm.team_b_player2=:n8))
                )
                ORDER BY bm.created_at ASC");
            $s->execute([':n'=>$n,':n2'=>$n,':n3'=>$n,':n4'=>$n,':n5'=>$n,':n6'=>$n,':n7'=>$n,':n8'=>$n,':t'=>$t,':t2'=>$t]);
            $rows=$s->fetchAll();
            if ($rows) $profile['sports']['badminton']=['wins'=>count(array_filter($rows,fn($r)=>strcasecmp($r['winner_name']??'',$n)===0||strcasecmp($r['winner_name']??'',$r['player_team']??'')===0)),'games'=>count($rows),'history'=>$rows];

            // Table Tennis — only matches on the player's registered team's side
            // FIX: same team_name filter applied as badminton above.
            $s=$pdo->prepare("SELECT ttm.id AS match_id, ttm.match_type, ttm.team_a_name, ttm.team_b_name,
                ttm.winner_name, ttm.created_at,
                CASE WHEN ttm.team_a_player1=:n OR ttm.team_a_player2=:n2 THEN ttm.team_a_name ELSE ttm.team_b_name END AS player_team,
                CASE WHEN ttm.team_a_player1=:n3 OR ttm.team_a_player2=:n4 THEN 'A' ELSE 'B' END AS side
                FROM table_tennis_matches ttm WHERE ttm.status='completed'
                AND (
                    (ttm.team_a_name=:t  AND (ttm.team_a_player1=:n5 OR ttm.team_a_player2=:n6)) OR
                    (ttm.team_b_name=:t2 AND (ttm.team_b_player1=:n7 OR ttm.team_b_player2=:n8))
                )
                ORDER BY ttm.created_at ASC");
            $s->execute([':n'=>$n,':n2'=>$n,':n3'=>$n,':n4'=>$n,':n5'=>$n,':n6'=>$n,':n7'=>$n,':n8'=>$n,':t'=>$t,':t2'=>$t]);
            $rows=$s->fetchAll();
            if ($rows) $profile['sports']['table_tennis']=['wins'=>count(array_filter($rows,fn($r)=>strcasecmp($r['winner_name']??'',$n)===0||strcasecmp($r['winner_name']??'',$r['player_team']??'')===0)),'games'=>count($rows),'history'=>$rows];

            // Darts — only rows where dp.team_name matches the registered team
            // FIX: added COALESCE(dp.team_name,'')=:t filter so same-name players on different teams stay separate.
            $s=$pdo->prepare("SELECT dm.id AS match_id,dp.player_number,dp.id AS dp_id,
                dp.player_name,dm.game_type,dm.winner_name,dm.created_at,
                (dm.winner_name=dp.player_name) AS is_winner,
                COALESCE(CASE dp.player_number WHEN 1 THEN dms.player1_legs_won WHEN 2 THEN dms.player2_legs_won WHEN 3 THEN dms.player3_legs_won WHEN 4 THEN dms.player4_legs_won ELSE 0 END,0) AS leg_wins,
                COALESCE(dms.total_legs,0) AS total_legs,
                COUNT(dt.id) AS total_throws,
                COALESCE(SUM(dt.throw_value),0) AS total_score,
                ROUND(COALESCE(AVG(dt.throw_value),0),2) AS throw_avg
                FROM darts_players dp JOIN darts_matches dm ON dp.match_id=dm.id
                LEFT JOIN darts_match_summary dms ON dms.match_id=dm.id
                LEFT JOIN darts_legs dl ON dl.match_id=dm.id
                LEFT JOIN darts_throws dt ON dt.leg_id=dl.id AND dt.player_id=dp.id
                WHERE dp.player_name=:n AND COALESCE(dp.team_name,'')=:t GROUP BY dm.id,dp.id,dms.id ORDER BY dm.created_at ASC");
            $s->execute([':n'=>$n,':t'=>$t]); $rows=$s->fetchAll();
            if ($rows) {
                $tt=array_sum(array_column($rows,'total_throws'));
                $ts=array_sum(array_column($rows,'total_score'));
                $profile['sports']['darts']=['games'=>count($rows),'wins'=>array_sum(array_column($rows,'is_winner')),'total_throws'=>$tt,'total_score'=>$ts,'throw_avg'=>$tt>0?round($ts/$tt,2):0,'leg_wins'=>array_sum(array_column($rows,'leg_wins')),'history'=>$rows];
            }

            // Team History — keyed by player_universal_id so same-name/different-team players stay separate.
            // FIX: query by universal ID, not by name.
            $thS=$pdo->prepare("SELECT sport, side, actual_team_name AS team_name, games_played, first_game, last_game, is_current FROM player_team_history WHERE player_universal_id=:uid ORDER BY sport, last_game ASC");
            $thS->execute([':uid'=>$uPlayer['id']]);
            $teamHistory=$thS->fetchAll();

            // Live fallback if table is empty (before migration runs).
            // FIX: all fallback queries now filter by both name ($n) AND team ($t) so
            // same-name players on different teams never bleed into each other's history.
            if (!$teamHistory) {
                $teamHistory=[];
                // Basketball — already correct (uses team side which resolves to team name)
                $th=$pdo->prepare("SELECT 'basketball' AS sport, mp.team AS side, IF(mp.team='A',m.team_a_name,m.team_b_name) AS team_name, COUNT(DISTINCT mp.match_id) AS games_played, MIN(m.saved_at) AS first_game, MAX(m.saved_at) AS last_game, 0 AS is_current FROM match_players mp JOIN matches m ON mp.match_id=m.match_id WHERE mp.player_name=:n AND IF(mp.team='A',m.team_a_name,m.team_b_name)=:t GROUP BY mp.team,team_name ORDER BY last_game ASC");
                $th->execute([':n'=>$n,':t'=>$t]); foreach($th->fetchAll() as $r) $teamHistory[]=$r;
                // Volleyball
                $th=$pdo->prepare("SELECT 'volleyball' AS sport, vp.team AS side, IF(vp.team='A',vm.team_a_name,vm.team_b_name) AS team_name, COUNT(DISTINCT vp.match_id) AS games_played, MIN(vm.created_at) AS first_game, MAX(vm.created_at) AS last_game, 0 AS is_current FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id WHERE vp.player_name=:n AND IF(vp.team='A',vm.team_a_name,vm.team_b_name)=:t GROUP BY vp.team,team_name ORDER BY last_game ASC");
                $th->execute([':n'=>$n,':t'=>$t]); foreach($th->fetchAll() as $r) $teamHistory[]=$r;
                // Badminton — FIX: restrict to matches where the player is on their registered team's side
                $th=$pdo->prepare("SELECT 'badminton' AS sport,
                    CASE WHEN bm.team_a_name=:t AND (bm.team_a_player1=:n OR bm.team_a_player2=:n2) THEN 'A' ELSE 'B' END AS side,
                    :t3 AS team_name,
                    COUNT(DISTINCT bm.id) AS games_played, MIN(bm.created_at) AS first_game, MAX(bm.created_at) AS last_game, 0 AS is_current
                    FROM badminton_matches bm WHERE bm.status='completed' AND (
                        (bm.team_a_name=:t4 AND (bm.team_a_player1=:n3 OR bm.team_a_player2=:n4)) OR
                        (bm.team_b_name=:t5 AND (bm.team_b_player1=:n5 OR bm.team_b_player2=:n6))
                    ) GROUP BY side,team_name ORDER BY last_game ASC");
                $th->execute([':n'=>$n,':n2'=>$n,':n3'=>$n,':n4'=>$n,':n5'=>$n,':n6'=>$n,':t'=>$t,':t2'=>$t,':t3'=>$t,':t4'=>$t,':t5'=>$t]); foreach($th->fetchAll() as $r) $teamHistory[]=$r;
                // Table Tennis — FIX: same team-scoped filter
                $th=$pdo->prepare("SELECT 'table_tennis' AS sport,
                    CASE WHEN ttm.team_a_name=:t AND (ttm.team_a_player1=:n OR ttm.team_a_player2=:n2) THEN 'A' ELSE 'B' END AS side,
                    :t3 AS team_name,
                    COUNT(DISTINCT ttm.id) AS games_played, MIN(ttm.created_at) AS first_game, MAX(ttm.created_at) AS last_game, 0 AS is_current
                    FROM table_tennis_matches ttm WHERE ttm.status='completed' AND (
                        (ttm.team_a_name=:t4 AND (ttm.team_a_player1=:n3 OR ttm.team_a_player2=:n4)) OR
                        (ttm.team_b_name=:t5 AND (ttm.team_b_player1=:n5 OR ttm.team_b_player2=:n6))
                    ) GROUP BY side,team_name ORDER BY last_game ASC");
                $th->execute([':n'=>$n,':n2'=>$n,':n3'=>$n,':n4'=>$n,':n5'=>$n,':n6'=>$n,':t'=>$t,':t2'=>$t,':t3'=>$t,':t4'=>$t,':t5'=>$t]); foreach($th->fetchAll() as $r) $teamHistory[]=$r;
                // Darts — FIX: filter by team_name so same-name players on different teams are separated
                $th=$pdo->prepare("SELECT 'darts' AS sport, '' AS side, COALESCE(dp.team_name,'') AS team_name, COUNT(DISTINCT dp.match_id) AS games_played, MIN(dm.created_at) AS first_game, MAX(dm.created_at) AS last_game, 0 AS is_current FROM darts_players dp JOIN darts_matches dm ON dp.match_id=dm.id WHERE dp.player_name=:n AND COALESCE(dp.team_name,'')=:t GROUP BY dp.team_name ORDER BY last_game ASC");
                $th->execute([':n'=>$n,':t'=>$t]); foreach($th->fetchAll() as $r) $teamHistory[]=$r;
                // Mark is_current
                $latestBySport=[];
                foreach($teamHistory as $h) { if (!isset($latestBySport[$h['sport']])||$h['last_game']>$latestBySport[$h['sport']]) $latestBySport[$h['sport']]=$h['last_game']; }
                foreach($teamHistory as &$h) $h['is_current']=($h['last_game']===($latestBySport[$h['sport']]??null))?1:0;
                unset($h);
            }
            $profile['team_history']=$teamHistory;
            echo json_encode(['success'=>true,'data'=>$profile]); break;

        // ── TEAM ANALYTICS ───────────────────────────────────────
        case 'team_analytics':
            $teams=[];
            switch ($sport) {
                case 'basketball':
                    $stmt=$pdo->query("SELECT tname AS team_name, SUM(m) AS matches_played, SUM(w) AS wins, SUM(l) AS losses, SUM(pf) AS total_pts_for, SUM(pa) AS total_pts_against, ROUND(SUM(pf)/GREATEST(SUM(m),1),1) AS avg_pts_for, ROUND(SUM(pa)/GREATEST(SUM(m),1),1) AS avg_pts_against FROM (SELECT team_a_name AS tname,COUNT(*) AS m,SUM(CASE WHEN match_result LIKE 'A%' THEN 1 ELSE 0 END) AS w,SUM(CASE WHEN match_result NOT LIKE 'A%' THEN 1 ELSE 0 END) AS l,SUM(team_a_score) AS pf,SUM(team_b_score) AS pa FROM matches WHERE match_result!='ONGOING' AND team_a_name!='' GROUP BY team_a_name UNION ALL SELECT team_b_name,COUNT(*),SUM(CASE WHEN match_result LIKE 'B%' THEN 1 ELSE 0 END),SUM(CASE WHEN match_result NOT LIKE 'B%' THEN 1 ELSE 0 END),SUM(team_b_score),SUM(team_a_score) FROM matches WHERE match_result!='ONGOING' AND team_b_name!='' GROUP BY team_b_name) x GROUP BY tname ORDER BY wins DESC,total_pts_for DESC");
                    $teams=$stmt->fetchAll(); break;
                case 'volleyball':
                    $stmt=$pdo->query("SELECT tname AS team_name, SUM(m) AS matches_played, SUM(w) AS wins, SUM(l) AS losses, SUM(pf) AS total_pts_for, SUM(pa) AS total_pts_against, ROUND(SUM(pf)/GREATEST(SUM(m),1),1) AS avg_pts_for, ROUND(SUM(pa)/GREATEST(SUM(m),1),1) AS avg_pts_against FROM (SELECT team_a_name AS tname,COUNT(*) AS m,SUM(CASE WHEN match_result LIKE 'A%' THEN 1 ELSE 0 END) AS w,SUM(CASE WHEN match_result NOT LIKE 'A%' THEN 1 ELSE 0 END) AS l,SUM(team_a_score) AS pf,SUM(team_b_score) AS pa FROM volleyball_matches WHERE match_result!='ONGOING' AND team_a_name!='' GROUP BY team_a_name UNION ALL SELECT team_b_name,COUNT(*),SUM(CASE WHEN match_result LIKE 'B%' THEN 1 ELSE 0 END),SUM(CASE WHEN match_result NOT LIKE 'B%' THEN 1 ELSE 0 END),SUM(team_b_score),SUM(team_a_score) FROM volleyball_matches WHERE match_result!='ONGOING' AND team_b_name!='' GROUP BY team_b_name) x GROUP BY tname ORDER BY wins DESC,total_pts_for DESC");
                    $teams=$stmt->fetchAll(); break;
                case 'badminton':
                    $stmt=$pdo->query("SELECT tname AS team_name,SUM(m) AS matches_played,SUM(w) AS wins,SUM(l) AS losses FROM (SELECT team_a_name AS tname,COUNT(*) AS m,SUM(CASE WHEN winner_name IN(team_a_player1,team_a_player2) OR winner_name=team_a_name THEN 1 ELSE 0 END) AS w,SUM(CASE WHEN winner_name IN(team_b_player1,team_b_player2) OR winner_name=team_b_name THEN 1 ELSE 0 END) AS l FROM badminton_matches WHERE status='completed' AND team_a_name!='' GROUP BY team_a_name UNION ALL SELECT team_b_name,COUNT(*),SUM(CASE WHEN winner_name IN(team_b_player1,team_b_player2) OR winner_name=team_b_name THEN 1 ELSE 0 END),SUM(CASE WHEN winner_name IN(team_a_player1,team_a_player2) OR winner_name=team_a_name THEN 1 ELSE 0 END) FROM badminton_matches WHERE status='completed' AND team_b_name!='' GROUP BY team_b_name) x GROUP BY tname ORDER BY wins DESC");
                    $teams=$stmt->fetchAll(); break;
                case 'table_tennis':
                    $stmt=$pdo->query("SELECT tname AS team_name,SUM(m) AS matches_played,SUM(w) AS wins,SUM(l) AS losses FROM (SELECT team_a_name AS tname,COUNT(*) AS m,SUM(CASE WHEN winner_name IN(team_a_player1,team_a_player2) OR winner_name=team_a_name THEN 1 ELSE 0 END) AS w,SUM(CASE WHEN winner_name IN(team_b_player1,team_b_player2) OR winner_name=team_b_name THEN 1 ELSE 0 END) AS l FROM table_tennis_matches WHERE status='completed' AND team_a_name!='' GROUP BY team_a_name UNION ALL SELECT team_b_name,COUNT(*),SUM(CASE WHEN winner_name IN(team_b_player1,team_b_player2) OR winner_name=team_b_name THEN 1 ELSE 0 END),SUM(CASE WHEN winner_name IN(team_a_player1,team_a_player2) OR winner_name=team_a_name THEN 1 ELSE 0 END) FROM table_tennis_matches WHERE status='completed' AND team_b_name!='' GROUP BY team_b_name) x GROUP BY tname ORDER BY wins DESC");
                    $teams=$stmt->fetchAll(); break;
                case 'darts':
                    // Aggregate per team: count matches and wins from darts_players + darts_matches
                    $stmt=$pdo->query("SELECT dp.team_name AS team_name,
                        COUNT(DISTINCT dp.match_id) AS matches_played,
                        COUNT(DISTINCT CASE WHEN dm.winner_name=dp.player_name THEN dm.id END) AS wins,
                        COUNT(DISTINCT CASE WHEN dm.winner_name!=dp.player_name AND dm.winner_name IS NOT NULL THEN dm.id END) AS losses
                        FROM darts_players dp
                        JOIN darts_matches dm ON dp.match_id=dm.id
                        WHERE dp.team_name IS NOT NULL AND dp.team_name!='' AND dm.status='completed'
                        GROUP BY dp.team_name
                        ORDER BY wins DESC, matches_played DESC");
                    $teams=$stmt->fetchAll(); break;
                default: $teams=[];
            }
            echo json_encode(['success'=>true,'data'=>$teams,'sport'=>$sport]); break;

        // ── TEAM INFO ────────────────────────────────────────────
        case 'team_info':
            if (!$team) { echo json_encode(['success'=>false,'error'=>'team_name required']); break; }
            $result=['team_name'=>$team,'sport'=>$sport,'roster'=>[],'matches'=>[]];
            switch ($sport) {
                case 'basketball':
                    // FIX: scope roster by actual team name + side match
                    $s=$pdo->prepare("SELECT mp.player_name, COUNT(DISTINCT mp.match_id) AS games, SUM(mp.pts) AS total_pts, SUM(mp.reb) AS total_reb, SUM(mp.ast) AS total_ast, SUM(mp.blk) AS total_blk, SUM(mp.stl) AS total_stl FROM match_players mp JOIN matches m ON mp.match_id=m.match_id WHERE mp.player_name!='' AND ((mp.team='A' AND m.team_a_name=:tn) OR (mp.team='B' AND m.team_b_name=:tn2)) GROUP BY mp.player_name ORDER BY total_pts DESC");
                    $s->execute([':tn'=>$team,':tn2'=>$team]); $result['roster']=$s->fetchAll();
                    $s=$pdo->prepare("SELECT match_id,team_a_name,team_b_name,team_a_score,team_b_score,match_result,saved_at AS created_at FROM matches WHERE (team_a_name=:tn OR team_b_name=:tn2) AND match_result!='ONGOING' ORDER BY saved_at DESC LIMIT 20");
                    $s->execute([':tn'=>$team,':tn2'=>$team]); $result['matches']=$s->fetchAll(); break;
                case 'volleyball':
                    // FIX: scope roster by actual team name + side match
                    $s=$pdo->prepare("SELECT vp.player_name, COUNT(DISTINCT vp.match_id) AS games, SUM(vp.pts) AS total_pts, SUM(vp.spike) AS total_spike, SUM(vp.ace) AS total_ace, SUM(vp.ex_set) AS total_set, SUM(vp.ex_dig) AS total_dig FROM volleyball_players vp JOIN volleyball_matches vm ON vp.match_id=vm.match_id WHERE vp.player_name!='' AND ((vp.team='A' AND vm.team_a_name=:tn) OR (vp.team='B' AND vm.team_b_name=:tn2)) GROUP BY vp.player_name ORDER BY total_pts DESC");
                    $s->execute([':tn'=>$team,':tn2'=>$team]); $result['roster']=$s->fetchAll();
                    $s=$pdo->prepare("SELECT match_id,team_a_name,team_b_name,team_a_score,team_b_score,match_result,created_at FROM volleyball_matches WHERE (team_a_name=:tn OR team_b_name=:tn2) AND match_result!='ONGOING' ORDER BY created_at DESC LIMIT 20");
                    $s->execute([':tn'=>$team,':tn2'=>$team]); $result['matches']=$s->fetchAll(); break;
                case 'badminton':
                    $s=$pdo->prepare("SELECT id AS match_id,match_type,team_a_name,team_b_name,team_a_player1,team_a_player2,team_b_player1,team_b_player2,winner_name,status,created_at FROM badminton_matches WHERE (team_a_name=:tn OR team_b_name=:tn2) AND status='completed' ORDER BY created_at DESC LIMIT 20");
                    $s->execute([':tn'=>$team,':tn2'=>$team]); $result['matches']=$s->fetchAll();
                    $roster=[];
                    foreach($result['matches'] as $m){$side=($m['team_a_name']===$team)?'a':'b';foreach([$m["team_{$side}_player1"],$m["team_{$side}_player2"]] as $pn){if(!$pn)continue;if(!isset($roster[$pn]))$roster[$pn]=['player_name'=>$pn,'games'=>0,'wins'=>0];$roster[$pn]['games']++;if($m['winner_name']===$pn||$m['winner_name']===$team)$roster[$pn]['wins']++;}}
                    $result['roster']=array_values($roster); break;
                case 'table_tennis':
                    $s=$pdo->prepare("SELECT id AS match_id,match_type,team_a_name,team_b_name,team_a_player1,team_a_player2,team_b_player1,team_b_player2,winner_name,status,created_at FROM table_tennis_matches WHERE (team_a_name=:tn OR team_b_name=:tn2) AND status='completed' ORDER BY created_at DESC LIMIT 20");
                    $s->execute([':tn'=>$team,':tn2'=>$team]); $result['matches']=$s->fetchAll();
                    $roster=[];
                    foreach($result['matches'] as $m){$side=($m['team_a_name']===$team)?'a':'b';foreach([$m["team_{$side}_player1"],$m["team_{$side}_player2"]] as $pn){if(!$pn)continue;if(!isset($roster[$pn]))$roster[$pn]=['player_name'=>$pn,'games'=>0,'wins'=>0];$roster[$pn]['games']++;if($m['winner_name']===$pn||$m['winner_name']===$team)$roster[$pn]['wins']++;}}
                    $result['roster']=array_values($roster); break;
                case 'darts':
                    // Roster: players on this team + their match wins
                    $s=$pdo->prepare("SELECT dp.player_name,
                        COUNT(DISTINCT dp.match_id) AS games,
                        COUNT(DISTINCT CASE WHEN dm.winner_name=dp.player_name THEN dm.id END) AS wins
                        FROM darts_players dp
                        JOIN darts_matches dm ON dp.match_id=dm.id
                        WHERE dp.team_name=:tn AND dm.status='completed'
                        GROUP BY dp.player_name ORDER BY wins DESC, games DESC");
                    $s->execute([':tn'=>$team]); $result['roster']=$s->fetchAll();
                    // Match history (one row per match the team appeared in)
                    $s=$pdo->prepare("SELECT dm.id AS match_id, dm.game_type, dm.winner_name,
                        dm.created_at, dm.status,
                        GROUP_CONCAT(DISTINCT dp.player_name ORDER BY dp.player_number SEPARATOR ', ') AS players
                        FROM darts_players dp
                        JOIN darts_matches dm ON dp.match_id=dm.id
                        WHERE dp.team_name=:tn AND dm.status='completed'
                        GROUP BY dm.id ORDER BY dm.created_at DESC LIMIT 20");
                    $s->execute([':tn'=>$team]); $result['matches']=$s->fetchAll(); break;
                default: break;
            }
            // ── W/L counter — sport-aware ──
            // Basketball/Volleyball: match_result contains 'A' or 'B' prefix for winner.
            // Badminton/TT: use winner_name vs team roster (winner_name = player or team name).
            // Darts: winner_name = winning player name; check if that player is on this team.
            $wins=0; $losses=0;
            foreach($result['matches'] as $m) {
                $res    = $m['match_result'] ?? '';
                $status = $m['status']       ?? '';
                $winnerName = $m['winner_name'] ?? '';
                $isA    = isset($m['team_a_name']) && $m['team_a_name'] === $team;
                if ($sport === 'basketball' || $sport === 'volleyball') {
                    // match_result like 'A-XX' or 'B-XX'
                    if (($isA && strpos($res,'A') === 0) || (!$isA && strpos($res,'B') === 0)) $wins++;
                    else $losses++;
                } elseif ($sport === 'badminton' || $sport === 'table_tennis') {
                    // winner_name is the player name or team name of the winner
                    $side = $isA ? 'a' : 'b';
                    $p1 = $m["team_{$side}_player1"] ?? '';
                    $p2 = $m["team_{$side}_player2"] ?? '';
                    $teamWon = ($winnerName === $team) || ($p1 && $winnerName === $p1) || ($p2 && $winnerName === $p2);
                    if ($teamWon) $wins++; else $losses++;
                } elseif ($sport === 'darts') {
                    // Check if winner_name belongs to any player on this team's roster
                    $rosterNames = array_column($result['roster'], 'player_name');
                    if ($winnerName && in_array($winnerName, $rosterNames, true)) $wins++;
                    else $losses++;
                }
            }
            $result['wins']=$wins; $result['losses']=$losses;
            echo json_encode(['success'=>true,'data'=>$result]); break;

        default:
            echo json_encode(['success'=>false,'error'=>'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'DB: '.$e->getMessage()]);
}