<?php
// ============================================================
// save_system_setting.php  — AJAX endpoint
// POST body (JSON or form): { key: 'maintenance_mode', value: '1' }
// Requires admin or superadmin role.
// ============================================================

// Support both standalone and Laravel-wrapped contexts
$_base = __DIR__;
if (!file_exists($_base . '/auth.php') && file_exists($_base . '/../auth.php')) {
    $_base = realpath(__DIR__ . '/..');
}
require_once $_base . '/auth.php';
require_once $_base . '/db.php'; // provides $pdo

header('Content-Type: application/json');

// Auth check
$user = currentUser();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Parse input (JSON or POST)
$raw = file_get_contents('php://input');
$body = $raw ? json_decode($raw, true) : [];
if (!$body) $body = $_POST;

$allowedKeys = ['maintenance_mode']; // extend as needed
$key   = trim($body['key']   ?? '');
$value = trim($body['value'] ?? '');

if (!in_array($key, $allowedKeys, true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown setting key']);
    exit;
}

try {
    $s = $pdo->prepare(
        'INSERT INTO system_settings (`key`, `value`) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()'
    );
    $s->execute([':k' => $key, ':v' => $value]);

    // Log the action
    try {
        $pdo->prepare(
            'INSERT INTO activity_log (user_id, username, action, timestamp)
             VALUES (:uid, :un, :ac, NOW())'
        )->execute([
            ':uid' => (int)$user['id'],
            ':un'  => $user['username'],
            ':ac'  => "Setting changed: {$key} = {$value}",
        ]);
    } catch (Throwable $e) { /* non-fatal */ }

    echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
exit;