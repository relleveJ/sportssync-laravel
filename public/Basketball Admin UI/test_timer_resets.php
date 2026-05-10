<?php
// Test script to verify timer reset functionality
require_once 'db.php';
require_once 'timer.php';

$match_id = 1; // Test with match ID 1

// Test game timer reset
echo "Testing Game Timer Reset...\n";
try {
    $result = timer_control($match_id, 'gt_reset', []);
    echo "Game Timer Reset Result: Success\n";
    echo "Response: " . json_encode($result) . "\n";
} catch (Exception $e) {
    echo "Game Timer Reset Result: Failed - " . $e->getMessage() . "\n";
}

// Test shotclock reset
echo "Testing Shotclock Reset...\n";
try {
    $result = timer_control($match_id, 'sc_reset', []);
    echo "Shotclock Reset Result: Success\n";
    echo "Response: " . json_encode($result) . "\n";
} catch (Exception $e) {
    echo "Shotclock Reset Result: Failed - " . $e->getMessage() . "\n";
}

// Check current timer state
echo "Checking current timer state...\n";
try {
    $result = timer_get($match_id);
    echo "Timer State Result: Success\n";
    echo "Game Timer: " . ($result['gt_remaining'] ?? 'N/A') . "s\n";
    echo "Shotclock: " . ($result['sc_remaining'] ?? 'N/A') . "s\n";
} catch (Exception $e) {
    echo "Timer State Result: Failed - " . $e->getMessage() . "\n";
}
?>