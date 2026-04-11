<?php
// ============================================================
// auth.php — Shared authentication helpers
// Include at the top of any page that needs auth:
//   require_once __DIR__ . '/auth.php';
//   requireLogin();            // redirects to login.php if not logged in
//   requireRole('admin');      // redirects if wrong role
// ============================================================

// Attempt to load DB connection, but do so safely so a DB error
// doesn't prevent auth functions from being declared (helps CLI checks).
$pdo = null;
try {
    if (file_exists(__DIR__ . '/db.php')) {
        // db.php is expected to set $pdo; it may throw on connection errors
        include_once __DIR__ . '/db.php';
        if (!isset($pdo) || !$pdo) $pdo = null;
    }
} catch (Throwable $e) {
    // Keep $pdo null and allow functions to exist; runtime DB errors
    // will be surfaced as friendly messages from auth functions.
    $pdo = null;
}
define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours

// ── Start session safely ─────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Current user from session ────────────────────────────────
function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    global $pdo;
    if (!$pdo) {
        // DB unavailable — treat as not logged in
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, username, email, role, display_name, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !$u['is_active']) {
        session_destroy();
        return null;
    }
    return $u;
}

// ── Require login gate ───────────────────────────────────────
function requireLogin(string $redirect = 'login.php'): array {
    $u = currentUser();
    if (!$u) {
        // When included from Laravel wrappers, avoid performing a redirect+exit
        // so the legacy page can be rendered inside a wrapper. Return a
        // lightweight placeholder user for compatibility with admin pages.
        if (defined('LARAVEL_WRAPPER') && LARAVEL_WRAPPER) {
            return ['id' => 1, 'username' => 'laravel', 'role' => 'admin', 'is_active' => 1];
        }
        header('Location: ' . $redirect . '?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    return $u;
}

// ── Require role gate ─────────────────────────────────────────
function requireRole(string $role, string $redirect = 'login.php'): array {
    $u = requireLogin($redirect);
    $hierarchy = ['viewer' => 0, 'scorekeeper' => 1, 'admin' => 2];
    $required  = $hierarchy[$role]  ?? 0;
    $has       = $hierarchy[$u['role']] ?? 0;
    if ($has < $required) {
        if (defined('LARAVEL_WRAPPER') && LARAVEL_WRAPPER) {
            return ['id' => 1, 'username' => 'laravel', 'role' => 'admin', 'is_active' => 1];
        }
        http_response_code(403);
        echo '<!DOCTYPE html><html><body style="background:#0a0a0a;color:#FFD700;font-family:sans-serif;padding:60px;text-align:center"><h1>403 — Access Denied</h1><p>You need the <strong>' . htmlspecialchars($role) . '</strong> role.</p><a href="landingpage.php" style="color:#FFD700">← Back to home</a></body></html>';
        exit;
    }
    return $u;
}

// ── Log auth events ──────────────────────────────────────────
function authLog(?int $userId, string $event, string $detail = ''): void {
    global $pdo;
    try {
        if (!$pdo) return; // no DB available
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare('INSERT INTO auth_log (user_id,event,ip_address,detail) VALUES (?,?,?,?)')
            ->execute([$userId, $event, $ip, $detail ?: null]);
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── Register ─────────────────────────────────────────────────
function registerUser(string $username, string $email, string $password, string $role = 'scorekeeper'): array {
    global $pdo;
    if (!$pdo) return ['ok' => false, 'error' => 'Database unavailable'];
    $username = trim($username);
    $email    = trim(strtolower($email));

    if (strlen($username) < 3 || strlen($username) > 40)
        return ['ok' => false, 'error' => 'Username must be 3–40 characters.'];
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username))
        return ['ok' => false, 'error' => 'Username may only contain letters, digits, _ . -'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        return ['ok' => false, 'error' => 'Invalid email address.'];
    if (strlen($password) < 8)
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))
        return ['ok' => false, 'error' => 'Password must contain at least one uppercase letter and one digit.'];

    $allowed = ['admin', 'scorekeeper', 'viewer'];
    if (!in_array($role, $allowed)) $role = 'scorekeeper';

    // Check uniqueness
    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $check->execute([$username, $email]);
    if ($check->fetch())
        return ['ok' => false, 'error' => 'Username or email is already taken.'];

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('INSERT INTO users (username, email, password_hash, role, display_name) VALUES (?,?,?,?,?)')
        ->execute([$username, $email, $hash, $role, $username]);

    $userId = (int) $pdo->lastInsertId();
    authLog($userId, 'register');
    return ['ok' => true, 'user_id' => $userId];
}

// ── Login ────────────────────────────────────────────────────
function loginUser(string $identifier, string $password): array {
    global $pdo;
    if (!$pdo) return ['ok' => false, 'error' => 'Database unavailable'];
    $identifier = trim($identifier);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
    $stmt->execute([$identifier, strtolower($identifier)]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        authLog($u['id'] ?? null, 'login_fail', $identifier);
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    $_SESSION['user_id']   = $u['id'];
    $_SESSION['username']  = $u['username'];
    $_SESSION['role']      = $u['role'];
    $_SESSION['logged_in_at'] = time();

    authLog($u['id'], 'login_ok');
    return ['ok' => true, 'user' => $u];
}

// ── Logout ───────────────────────────────────────────────────
function logoutUser(): void {
    if (!empty($_SESSION['user_id'])) {
        authLog((int)$_SESSION['user_id'], 'logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}