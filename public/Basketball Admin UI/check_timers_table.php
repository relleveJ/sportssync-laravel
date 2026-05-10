<?php
// Diagnostic script to check match_timers table
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

try {
    // Check if table exists
    $st = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'sportssync' AND TABLE_NAME = 'match_timers'");
    $st->execute();
    $exists = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($exists) {
        // Get table structure
        $st = $pdo->prepare("DESCRIBE match_timers");
        $st->execute();
        $columns = $st->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'table_exists' => true,
            'columns' => $columns
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'success' => true,
            'table_exists' => false,
            'message' => 'match_timers table does not exist. Will be created on first POST.'
        ], JSON_PRETTY_PRINT);
    }
    
    // Try to create table
    echo "\n\n--- Attempting CREATE TABLE ---\n";
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS match_timers (
                match_id INT PRIMARY KEY,
                game_total INT DEFAULT 0,
                game_remaining DOUBLE DEFAULT 0,
                game_running TINYINT(1) DEFAULT 0,
                game_ts BIGINT DEFAULT NULL,
                shot_total INT DEFAULT 0,
                shot_remaining DOUBLE DEFAULT 0,
                shot_running TINYINT(1) DEFAULT 0,
                shot_ts BIGINT DEFAULT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "✓ Table created or already exists\n";
    } catch (Throwable $e) {
        echo "✗ CREATE TABLE failed: " . $e->getMessage() . "\n";
    }
    
    // Verify table now exists
    $st = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'sportssync' AND TABLE_NAME = 'match_timers'");
    $st->execute();
    $exists = $st->fetch(PDO::FETCH_ASSOC);
    echo "Table exists after creation: " . ($exists ? "YES" : "NO") . "\n";
    
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>
