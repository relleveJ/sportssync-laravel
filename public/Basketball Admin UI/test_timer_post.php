<?php
// Test timer.php POST
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../auth.php';

echo "=== Testing timer.php POST logic ===\n\n";

// Simulate authenticated user
if (!function_exists('currentUser')) {
    function currentUser() {
        return (object)['id' => 1, 'role' => 'admin'];
    }
}

$test_payload = [
    'match_id' => 1,
    'gameTimer' => [
        'total' => 600,
        'remaining' => 540,
        'running' => true,
        'ts' => (int)round(microtime(true) * 1000)
    ],
    'shotClock' => [
        'total' => 24,
        'remaining' => 20,
        'running' => true,
        'ts' => (int)round(microtime(true) * 1000)
    ],
    'meta' => [
        'control' => 'start',
        'clientId' => 'test-client'
    ]
];

echo "Test Payload:\n";
echo json_encode($test_payload, JSON_PRETTY_PRINT) . "\n\n";

try {
    // Try direct execution
    $mid = (int)$test_payload['match_id'];
    $gt = $test_payload['gameTimer'];
    $sc = $test_payload['shotClock'];
    
    $gt_total = (int)$gt['total'];
    $gt_remaining = (int)round((float)$gt['remaining']);
    $gt_running = $gt['running'] ? 1 : 0;
    $gt_ts = isset($gt['ts']) && is_numeric($gt['ts']) ? (int)$gt['ts'] : null;
    
    $sc_total = (int)$sc['total'];
    $sc_remaining = (int)round((float)$sc['remaining']);
    $sc_running = $sc['running'] ? 1 : 0;
    $sc_ts = isset($sc['ts']) && is_numeric($sc['ts']) ? (int)$sc['ts'] : null;
    
    echo "Parsed Values:\n";
    echo "  game_total: $gt_total\n";
    echo "  game_remaining: $gt_remaining\n";
    echo "  game_running: $gt_running\n";
    echo "  game_ts: $gt_ts\n";
    echo "  shot_total: $sc_total\n";
    echo "  shot_remaining: $sc_remaining\n";
    echo "  shot_running: $sc_running\n";
    echo "  shot_ts: $sc_ts\n\n";
    
    // Test the SQL
    $sql = 'INSERT INTO match_timers 
            (match_id, game_total, game_remaining, game_running, game_ts, shot_total, shot_remaining, shot_running, shot_ts, updated_at) 
            VALUES (:mid, :game_total, :game_remaining, :game_running, :game_ts, :shot_total, :shot_remaining, :shot_running, :shot_ts, NOW()) 
            ON DUPLICATE KEY UPDATE 
            game_total = VALUES(game_total),
            game_remaining = VALUES(game_remaining),
            game_running = VALUES(game_running),
            game_ts = VALUES(game_ts),
            shot_total = VALUES(shot_total),
            shot_remaining = VALUES(shot_remaining),
            shot_running = VALUES(shot_running),
            shot_ts = VALUES(shot_ts),
            updated_at = NOW()';
    
    echo "Executing SQL...\n";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':mid' => $mid,
        ':game_total' => $gt_total,
        ':game_remaining' => $gt_remaining,
        ':game_running' => $gt_running,
        ':game_ts' => $gt_ts,
        ':shot_total' => $sc_total,
        ':shot_remaining' => $sc_remaining,
        ':shot_running' => $sc_running,
        ':shot_ts' => $sc_ts,
    ]);
    
    echo "✓ INSERT successful\n\n";
    
    // Now test the GET
    echo "Testing GET...\n";
    $st = $pdo->prepare('SELECT * FROM match_timers WHERE match_id = :id LIMIT 1');
    $st->execute([':id' => $mid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($r) {
        $payload = [
            'gameTimer' => [
                'total'     => (int)$r['game_total'],
                'remaining' => (float)$r['game_remaining'],
                'running'   => (bool)$r['game_running'],
                'ts'        => $r['game_ts'] ? (int)$r['game_ts'] : null,
            ],
            'shotClock' => [
                'total'     => (int)$r['shot_total'],
                'remaining' => (float)$r['shot_remaining'],
                'running'   => (bool)$r['shot_running'],
                'ts'        => $r['shot_ts'] ? (int)$r['shot_ts'] : null,
            ]
        ];
        echo "✓ GET successful\n";
        echo "Returned Payload:\n";
        echo json_encode(['success'=>true,'payload'=>$payload], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "✗ No row found\n";
    }
    
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
}
?>
